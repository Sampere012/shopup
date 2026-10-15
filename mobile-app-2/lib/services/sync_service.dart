import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api_service.dart';
import 'auth_service.dart';
import 'db_service.dart';
import 'plan_guard_service.dart';
import '../config.dart';

/// Motor offline-first (equivalente a js/sync.js):
/// - PULL por pasos independientes: un endpoint que falla no aborta el resto.
/// - PUSH: online → envía ya; rechazo definitivo del servidor → NO encola y
///   propaga el error; red caída → encola y resuelve {queued:true}.
/// - FLUSH: envía pendientes EN ORDEN; error de red detiene, rechazo
///   definitivo deja la op en cola y continúa.
/// - adjustLocalStock: descuenta/añade stock local + notifica (tiempo real).
class SyncService extends ChangeNotifier {
  SyncService._();
  static final SyncService I = SyncService._();

  bool _online = true;
  bool _pulling = false;
  bool _flushing = false;
  int _lastSync = 0;
  Timer? _timer;
  // Escucha en vivo de la conectividad del dispositivo: al caer la red
  // marcamos offline AL INSTANTE (sin sondeo HTTP), así los guardados se
  // encolan sin esperar el timeout del servidor.
  StreamSubscription<List<ConnectivityResult>>? _connSub;
  // Intervalo de autosync: configuración LOCAL del dispositivo (no del
  // servidor) — cambiarlo no requiere conexión a internet.
  static const _minsKey = 'wsm_autosync_minutes';
  int _autoSyncMinutes = AppConfig.autoSyncMinutes;
  final List<void Function()> _changeListeners = [];
  final List<void Function()> _bgListeners = [];
  // Guardado online que superó los 5 s: expone la opción de "guardarlo en
  // el dispositivo" para que el usuario decida en vez de esperar sin info.
  Map<String, dynamic>? _slowOp;

  bool get isOnline => _online;
  /// true mientras haya un guardado lento esperando la decisión del usuario.
  bool get hasSlowPrompt => _slowOp != null;
  bool get isPulling => _pulling;
  bool get isBusy => _pulling || _flushing;
  int get autoSyncMinutes => _autoSyncMinutes;
  int get lastSyncMs => _lastSync;

  void onChange(void Function() fn) => _changeListeners.add(fn);
  void removeOnChange(void Function() fn) => _changeListeners.remove(fn);
  void onBackgroundSync(void Function() fn) => _bgListeners.add(fn);

  /// Fuerza una notificación a las pantallas suscritas (y a los `watch`).
  /// Útil tras guardar datos locales (p.ej. un movimiento offline) para que
  /// la UI los refleje al instante, sin esperar a un ciclo de sync.
  void emitChange() => _emit();

  void _emit() {
    for (final fn in List.of(_changeListeners)) {
      try { fn(); } catch (_) {}
    }
    notifyListeners();
  }

  void setOnline(bool v) {
    if (_online == v) return;
    _online = v;
    _emit();
    // Cuando recuperamos conexión, auto-flush + pull para enviar pendientes.
    if (v) backgroundSync();
  }

  Future<void> start({bool autoSync = true}) async {
    _connSub ??= Connectivity().onConnectivityChanged.listen((results) {
      setOnline(results.any((r) => r != ConnectivityResult.none));
    });
    await checkConnectivity();
    // Intervalo local persistido (si el usuario lo cambió en Ajustes).
    final sp = await SharedPreferences.getInstance();
    _autoSyncMinutes = sp.getInt(_minsKey) ?? AppConfig.autoSyncMinutes;
    // Cronómetro de autosync: intervalos periódicos.
    // Además, al abrir la app se lanza un pull con throttle (syncSoon): antes
    // el único disparo automático era este cronómetro, así que un día entero
    // de uso podía pasar sin bajar nada si el usuario no visitaba una
    // pantalla que sincronizara por su cuenta.
    if (autoSync) startTimer();
    unawaited(syncSoon());
  }

  /// Guarda el intervalo de autosync localmente y reinicia el cronómetro.
  /// No toca la red: funciona totalmente offline.
  Future<void> setAutoSyncMinutes(int minutes) async {
    final v = minutes.clamp(1, 720);
    if (v == _autoSyncMinutes) return;
    _autoSyncMinutes = v;
    final sp = await SharedPreferences.getInstance();
    await sp.setInt(_minsKey, v);
    _restartTimer();
    _emit();
  }

  Future<void> checkConnectivity() async {
    setOnline(await AuthService.online());
  }

  void startTimer() {
    _timer ??= Timer.periodic(
        Duration(minutes: _autoSyncMinutes), (_) => backgroundSync());
  }

  void _restartTimer() {
    _timer?.cancel();
    _timer = null;
    startTimer();
  }

  /// Sube pendientes y baja cambios. Devuelve
  /// 'blocked' | 'offline' | true | false.
  ///
  /// El pull se ejecuta SIEMPRE (si hay red y el plan no está bloqueado),
  /// aunque la cola aún tenga operaciones pendientes: si una op se queda
  /// atascada, antes se abortaba aquí y la app dejaba de descargar datos para
  /// siempre — sólo sobrevivían las pantallas que volvían a llamar a la API
  /// por su cuenta. Devuelve `false` si algo falló, pero ya no decide
  /// si se baja o no el estado del servidor.
  Future<Object> syncNow() async {
    // Plan bloqueado: ni subir ni bajar. La UI muestra la pantalla de bloqueo.
    if (PlanGuardService.I.isBlocked) return 'blocked';
    await checkConnectivity();
    if (!_online) return 'offline';
    var ok = true;
    try {
      final flushRes = await flush();
      if ((flushRes['remaining'] ?? 0) > 0) ok = false;
    } catch (_) {
      ok = false;
    }
    try {
      // pull() es independiente de la cola: descarga el estado del servidor
      // y reaplica los deltas pendientes encima.
      final okPull = await pull();
      if (!okPull) ok = false;
    } catch (_) {
      ok = false;
    }
    _lastSync = DateTime.now().millisecondsSinceEpoch;
    for (final fn in List.of(_bgListeners)) {
      try {
        fn();
      } catch (_) {}
    }
    _emit();
    return ok;
  }

  /// Sincroniza con throttle: se dispara al abrir la app y al volver de
  /// segundo plano. Sin este paso, un día entero de uso podía pasar sin
  /// ningún pull si el usuario no visitaba una pantalla que sincronizara
  /// por su cuenta (el único disparo automático era el timer de 25 min).
  ///
  /// [minInterval] evita martillear la red cuando el usuario alterna entre
  /// la app y otras apps.
  Future<void> syncSoon({Duration? minInterval}) async {
    if (_pulling || _flushing || PlanGuardService.I.isBlocked) return;
    // En el arranque `me` puede no estar cargado todavía: sin él, pull()
    // no sabe qué menús/caps tiene el usuario y se salta los endpoints
    // acotados. `load()` es idempotente y sólo lee SharedPreferences.
    if (AuthService.I.me == null) {
      try {
        await AuthService.I.load();
      } catch (_) {}
    }
    final interval = minInterval ?? const Duration(minutes: 3);
    final last = DateTime.fromMillisecondsSinceEpoch(_lastSync);
    if (DateTime.now().difference(last) < interval) return;
    try {
      await backgroundSync();
    } catch (_) {}
  }

  Future<void> backgroundSync() async {
    await checkConnectivity();
    if (_online && !_pulling && !_flushing && !PlanGuardService.I.isBlocked) {
      try { await syncNow(); } catch (_) {}
    }
  }

  // ---------------- PULL ----------------

  Future<bool> pull() async {
    if (_pulling) return false;
    // Plan bloqueado: no se baja nada a la caché local.
    if (PlanGuardService.I.isBlocked) return false;
    _pulling = true;
    _emit();
    var anySuccess = false;
    final me = AuthService.I.me ?? {};
    final menuKeys = (me['menu'] as List?)?.map((m) => '${(m as Map)['key']}').toSet() ?? <String>{};
    bool hasCap(String c) => AuthService.I.has(c);

    Future<void> step(Future<void> Function() fn) async {
      try { await fn(); anySuccess = true; } catch (_) {}
    }

    try {
      // Ubicaciones (defensa extra: recortar a las permitidas del usuario).
      await step(() => ApiService.I.req('ws_cache_locations', {}).then((d) async {
            var rows = List<Map<String, dynamic>>.from(((d as Map)['data'] as List?) ?? []);
            final mine = (me['locations'] as List?) ?? [];
            if (mine.isNotEmpty) {
              final ids = mine.map((l) => '${(l as Map)['id']}').toSet();
              rows = rows.where((l) => ids.contains('${l['id']}')).toList();
            }
            await DbService.I.cacheSet('ws_locations_list', rows);
            await DbService.I.replaceAll('locations', rows);
          }));

      // Productos maestros.
      await step(() => ApiService.I.req('ws_cache_products', {'location_id': 0}).then((d) async {
            await DbService.I.replaceAll('products',
                List<Map<String, dynamic>>.from(((d as Map)['data'] as List?) ?? []));
          }));

      // Categorías de productos (árbol: parent_id/name/active/products).
      // Sin gate de caps: el POS y el catálogo de Stock las usan todos, y el
      // que no puede gestionarlas simplemente no las edita (lo decide el
      // servidor). Antes sólo se bajaban con `categories_manage` y el POS
      // trabajaba sin ellas.
      await step(() => ApiService.I.req('ws_categories_list', {}).then((d) async {
            final dm = d as Map;
            final rows = List<Map<String, dynamic>>.from(((dm['categories']) as List?) ?? []);
            await DbService.I.cacheSet('ws_categories_list', rows);
            await DbService.I.cacheSet('ws_categories_payload', dm['payload'] ?? {});
            await DbService.I.replaceAll('categories', rows);
          }));

      // Stock + combos (merge/upsert: no borrar filas locales que el
      // servidor no devolvió, p.ej. productos recién creados offline).
      await step(() => ApiService.I.req('ws_stock_list', {'limit': 1000, 'pageSize': 1000}).then((d) async {
            final m = d as Map;
            final rows = List<Map<String, dynamic>>.from((m['rows'] as List?) ?? []);
            final combos = List<Map<String, dynamic>>.from((m['combos'] as List?) ?? []);
            for (final r in rows) { r['id'] = 'p${r['product_id']}:${r['location_id']}'; }
            await DbService.I.cacheSet('ws_stock_list', rows);
            await DbService.I.cacheSet('ws_stock_list_combos', combos);
            await DbService.I.putAll('stock', rows);
            await DbService.I.putAll('combos', combos);
            // El pull pisa el stock con el estado del servidor: si aún hay
            // movimientos EN COLA (no subidos), sus deltas se pierden y la
            // pantalla "revierte" lo que el usuario hizo offline. Se
            // reaplican encima para que la interfaz siga siendo coherente.
            await reapplyPendingStockDeltas();
          }));

      // Movimientos (merge/upsert: preservar movimientos creados offline).
      await step(() => ApiService.I.req('ws_movements_list',
          {'scope': '', 'pageSize': 500, 'page': 1}).then((d) async {
            final rows = List<Map<String, dynamic>>.from((((d as Map)['movements']) as List?) ?? []);
            // Los movimientos LOCALES (`local_…`) solo se conservan mientras
            // su op siga EN COLA: en cuanto el servidor ya los tiene, la fila
            // local se retira para no mostrarlos DUPLICADOS con la que llega
            // del pull. Se usa replaceAll para que el retiro sea efectivo.
            final keep = <String>{};
            for (final op in await DbService.I.pending()) {
              final lid = '${op['local_id'] ?? ''}';
              if (lid.isNotEmpty) keep.add(lid);
            }
            final local = await DbService.I.all('movements');
            local.removeWhere((m) =>
                '${m['id']}'.startsWith('local_') && !keep.contains('${m['id']}'));
            rows.addAll(local);
            await DbService.I.cacheSet('ws_movements_list', rows);
            await DbService.I.replaceAll('movements', rows);
          }));

      // Pedidos web.
      await step(() => ApiService.I.req('ws_order_list', {'pageSize': 200, 'page': 1}).then((d) async {
            final rows = List<Map<String, dynamic>>.from((((d as Map)['orders']) as List?) ?? []);
            await DbService.I.cacheSet('ws_order_list', rows);
            await DbService.I.replaceAll('orders', rows);
          }));

      // Clientes. Sin gate de caps: el autocompletado del POS los necesita
      // también (el servidor rechaza a quien no tenga el permiso).
      await step(() => ApiService.I.req('ws_cache_customers', {}).then((d) async {
            final rows = List<Map<String, dynamic>>.from(((d as Map)['data'] as List?) ?? []);
            await DbService.I.cacheSet('ws_customers_get', rows);
            await DbService.I.replaceAll('customers', rows);
          }));

      // Anuncios fijados (banners): para TODOS los usuarios, igual que en el
      // panel web, no solo para quien gestiona anuncios.
      await step(() => ApiService.I.req('ws_announcements_pinned_list', {})
          .then((d) async {
        final rows =
            List<Map<String, dynamic>>.from((((d as Map)['list']) as List?) ?? []);
        await DbService.I.cacheSet('ws_announcements_pinned', rows);
      }));

      // Anuncios.
      if (menuKeys.contains('anuncios')) {
        await step(() => ApiService.I.req('ws_announcements_list', {}).then((d) async {
              final rows = List<Map<String, dynamic>>.from((((d as Map)['list']) as List?) ?? []);
              await DbService.I.cacheSet('ws_announcements_list', rows);
              // Conservar lo que todavía no se subió (creaciones locales y
              // ediciones en cola): el replaceAll no debe pisarlo
              // (mismo criterio que cuadres/ventas POS).
              final merged = await mergeAnnouncementRows(rows);
              await DbService.I.replaceAll('announcements', merged);
            }));
      }

      // Turnos (mes actual).
      if (hasCap('shifts_view')) {
        await step(() {
          final now = DateTime.now();
          final from = '${now.year}-${now.month.toString().padLeft(2, '0')}-01 00:00:00';
          return ApiService.I.req('ws_shifts_list',
              {'start': from, 'end': '${now.year}-12-31 23:59:59'}).then((d) async {
            final rows = List<Map<String, dynamic>>.from((((d as Map)['shifts']) as List?) ?? []);
            await DbService.I.cacheSet('ws_shifts_list', rows);
            await DbService.I.replaceAll('shifts', rows);
          });
        });
      }

      // Ventas POS (merge/upsert: preservar ventas offline encoladas).
      if (hasCap('pos_view')) {
        await step(() => ApiService.I.req('ws_pos_sales_get',
            {'location_id': 0, 'status': '', 'search': '', 'limit': 200, 'offset': 0}).then((d) async {
              final rows = List<Map<String, dynamic>>.from((((d as Map)['data']) as List?) ?? []);
              await DbService.I.cacheSet('ws_pos_sales_get', rows);
              await DbService.I.cacheSet('ws_pos_sales_get_all', rows);
              await DbService.I.putAll('pos_sales', rows);
              // Re-inyectar ventas encoladas que el servidor aún no tiene
              final pending = await DbService.I.pending();
              final queuedSales = pending.where((op) => op['action'] == 'ws_pos_sale_save').toList();
              if (queuedSales.isNotEmpty) {
                final sales = List<Map<String, dynamic>>.from(rows);
                for (final op in queuedSales) {
                  final data = (op['data'] is Map) ? Map<String, dynamic>.from(op['data'] as Map) : <String, dynamic>{};
                  final localSale = <String, dynamic>{
                    'id': op['id'],
                    'location_id': data['location_id'],
                    'currency': data['currency'],
                    'subtotal': data['subtotal'],
                    'discount': data['discount'],
                    'total': data['total'],
                    'payment_method': data['payment_method'],
                    'customer_name': data['customer_name'],
                    'status': 'pending',
                    'created_at': data['created_at'] ?? DateTime.now().toIso8601String(),
                    'items': data['items'],
                  };
                  sales.insert(0, localSale);
                }
                await DbService.I.cacheSet('ws_pos_sales_get', sales);
                await DbService.I.cacheSet('ws_pos_sales_get_all', sales);
              }
            }));
      }

      // Estado de caja por ubicación con POS.
      if (hasCap('pos_sell')) {
        await step(() async {
          final locs = await DbService.I.all('locations');
          for (final row in locs.where((r) => '${r['pos_enabled']}' != '0' && '${r['pos_enabled']}' != 'false')) {
            await step(() => ApiService.I.req('ws_pos_cash_status', {'location_id': row['id']})
                .then((d) => DbService.I.cacheSet('ws_pos_cash_status:${row['id']}', (d as Map)['data'] ?? {})));
          }
        });
      }

      // Cuadres de stock.
      if (hasCap('stock_count_view')) {
        await step(() => ApiService.I.req('ws_stock_counts_list', {'limit': 50, 'offset': 0}).then((d) async {
              final rows = List<Map<String, dynamic>>.from((((d as Map)['data']) as List?) ?? []);
              await DbService.I.cacheSet('ws_stock_counts_list:all', rows);
              await DbService.I.replaceAll('stock_counts', rows);
            }));
        // Cuadre virtual + histórico POR UBICACIÓN: es lo que abre la pantalla
        // de Cuadre. Antes sólo se descargaban al entrar en ella, así que
        // sin visitar la vista el usuario veía "sin cuadrar" aunque hubiera
        // datos en el servidor.
        await step(() async {
          final locs = await DbService.I.all('locations');
          for (final row in locs) {
            final id = '${row['id']}';
            if (id.isEmpty) continue;
            await step(() => ApiService.I.req('ws_stock_count_virtual', {'location_id': id}).then((d) {
              // Igual que en counts_screen: el endpoint devuelve la lista
              // directamente o envuelta en `data`.
              final rows = (d is List)
                  ? d
                  : (d is Map && d['data'] is List ? d['data'] : const []);
              DbService.I.cacheSet('ws_stock_count_virtual:$id', rows);
            }));
            await step(() => ApiService.I.req('ws_stock_counts_list',
                    {'location_id': id, 'limit': 20, 'offset': 0})
                .then((d) async {
              final rows =
                  List<Map<String, dynamic>>.from((((d as Map)['data']) as List?) ?? []);
              await DbService.I.cacheSet('ws_stock_counts_list:$id', rows);
            }));
          }
        });
      }

      // Estadísticas POS.
      if (hasCap('pos_view')) {
        await step(() => ApiService.I.req('ws_pos_stats', {})
            .then((d) => DbService.I.cacheSet('ws_pos_stats', (d as Map)['data'] ?? {})));
      }

      // Gastos.
      if (hasCap('expenses_manage')) {
        await step(() => ApiService.I.req('ws_expenses_list', {'year': 0, 'month': 0}).then((d) async {
              final rows = List<Map<String, dynamic>>.from((((d as Map)['expenses']) as List?) ?? []);
              await DbService.I.cacheSet('ws_expenses_list', rows);
              await DbService.I.replaceAll('expenses', rows);
            }));
      }

      // Trabajadores.
      if (hasCap('workers_view') || hasCap('shifts_view')) {
        await step(() => ApiService.I.req('ws_workers_list',
            {'pageSize': 300, 'page': 1, 'search': ''}).then((d) async {
              final rows = List<Map<String, dynamic>>.from((((d as Map)['workers']) as List?) ?? []);
              await DbService.I.cacheSet('ws_workers_list', rows);
              await DbService.I.replaceAll('workers', rows);
            }));
      }

      // Reseñas / lealtad / plan / ajustes / apariencia / permisos.
      if (hasCap('reviews_view')) {
        await step(() => ApiService.I.req('ws_reviews_get', {'status': '', 'limit': 200, 'offset': 0})
            .then((d) async {
          final wrap = (d is Map) ? (d['data'] ?? d) : d;
          var rows = <Map<String, dynamic>>[];
          if (wrap is List) {
            rows = wrap.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
          } else if (wrap is Map) {
            final inner = wrap['data'] ?? wrap['reviews'];
            if (inner is List) rows = inner.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
          }
          await DbService.I.cacheSet('ws_reviews_get', rows);
        }));
      }
      if (menuKeys.contains('loyalty')) {
        await step(() => ApiService.I.req('ws_loyalty_customers', {'search': '', 'limit': 200, 'offset': 0})
            .then((d) => DbService.I.cacheSet('ws_loyalty_customers', (d as Map)['data'] ?? [])));
        await step(() => ApiService.I.req('ws_loyalty_stats', {})
            .then((d) => DbService.I.cacheSet('ws_loyalty_stats', (d as Map)['data'] ?? {})));
      }
      if (menuKeys.contains('plan')) {
        await step(() => ApiService.I.req('ws_plan_info', {})
            .then((d) => DbService.I.cacheSet('ws_plan_info', (d as Map)['data'] ?? {})));
      }
      // Ajustes. Sin gate de menú: `main.dart` lee `ws_settings_get` para la
      // URL del WebSocket y el catálogo de Stock para Cloudinary, y ambos
      // funcionan sin importar si el menú incluye "Ajustes".
      await step(() => ApiService.I.req('ws_settings_get', {})
          .then((d) => DbService.I.cacheSet('ws_settings_get', (d as Map)['data'] ?? {})));
      if (menuKeys.contains('appearance')) {
        await step(() => ApiService.I.req('ws_appearance_get', {})
            .then((d) => DbService.I.cacheSet('ws_appearance_get', (d as Map)['data'] ?? {})));
      }
      if (menuKeys.contains('permissions')) {
        await step(() => ApiService.I.req('ws_permissions_get', {})
            .then((d) => DbService.I.cacheSet('ws_permissions_get', (d as Map)['data'] ?? {})));
      }

      // Notificaciones (+ contador de no leídas).
      await step(() => ApiService.I.req('ws_notifications_list', {}).then((d) async {
            final m = d as Map;
            await DbService.I.cacheSet('ws_notifications_list', m['items'] ?? []);
            await DbService.I.setMeta('notif_unread_count', m['unread'] ?? 0);
          }));

      // Reportes (14 días, todas las ubicaciones).
      if (menuKeys.contains('reports')) {
        await step(() => ApiService.I.req('ws_reports_summary', {'ws_period': 14, 'ws_loc': 0},
                timeoutSec: 30)
            .then((d) {
          final outer = (d as Map)['data'];
          final leaf = (outer is Map) ? (outer['data'] ?? outer) : (outer ?? {});
          return DbService.I.cacheSet('ws_reports_summary:14:0', leaf is Map ? Map<String, dynamic>.from(leaf) : <String, dynamic>{});
        }));
      }
    } finally {
      _pulling = false;
      _emit();
    }
    return anySuccess;
  }

  // ---------------- PUSH / FLUSH ----------------

  /// Encola [action]/[data] evitando duplicados: si YA existe la MISMA
  /// acción con los MISMOS datos encolada en los últimos 30 s (doble toque
  /// del botón mientras no hay conexión), se reutiliza ese registro.
  /// Devuelve el id de la op en cola ('op_…') o null si se dedupeó.
  Future<String?> _enqueueDedup(String action, Map<String, dynamic> data,
      {String? localId}) async {
    try {
      final key = jsonEncode(data);
      final nowUs = DateTime.now().microsecondsSinceEpoch;
      final ops = await DbService.I.pending();
      for (final op in ops) {
        if (op['action'] != action) continue;
        String opKey;
        try {
          opKey = jsonEncode(op['data']);
        } catch (_) {
          continue;
        }
        if (opKey != key) continue;
        final created = (op['created_at'] is num)
            ? (op['created_at'] as num).toInt()
            : 0;
        if (created > 0 && nowUs - created < 30 * 1000 * 1000) {
          return '${op['id']}'; // ya está en cola: no duplicar
        }
      }
    } catch (_) {
      // Si la dedupe falla, se encola igual (nunca perder un guardado).
    }
    final n = await DbService.I.enqueue(action, data, localId: localId);
    return 'op_$n';
  }

  /// Resuelve el guardado lento en curso encolándolo localmente (botón
  /// "Guardar localmente" del diálogo de handlePush). Devuelve true si
  /// había un guardado lento esperando decisión.
  Future<bool> saveSlowLocalNow() async {
    final op = _slowOp;
    if (op == null) return false;
    final fn = op['saveLocal'];
    if (fn is Future<void> Function()) {
      await fn();
      return true;
    }
    return false;
  }

  /// Encola una operación. Ver contrato en el encabezado de la clase.
  /// Conexión ONLINE:
  ///  - a los 5 s sin respuesta expone saveSlowLocalNow() (el usuario
  ///    decide si guardar en el dispositivo en vez de esperar ciegas);
  ///  - si el usuario guarda localmente y la petición termina BIEN después,
  ///    la copia en cola se descarta para no duplicar el envío.
  /// [localId] enlaza la op con la fila local que la originó (p.ej. un
  /// anuncio creado offline) para poder coalescer ediciones posteriores.
  Future<dynamic> push(String action, Map<String, dynamic> data,
      {String? localId}) async {
    // Plan bloqueado: la operación se rechaza en vez de encolarse, para no
    // acumular escrituras que el servidor va a rechazar igualmente.
    if (PlanGuardService.I.isBlocked) {
      final lock = PlanGuardService.I.lock;
      throw ApiException(
          '${lock?['title'] ?? 'Tu plan venció o fue suspendido'}. '
          'El negocio está en pausa.',
          response: {'success': false, 'data': {'hasPlanLock': true}});
    }
    if (_online) {
      final done = Completer<dynamic>();
      var localQueued = false;
      var requestSucceeded = false;
      Future<String?>? enqueueFuture;

      Future<String?> enqueueNow() {
        if (enqueueFuture != null) return enqueueFuture!;
        localQueued = true;
        _slowOp = null;
        _emit();
        enqueueFuture = _enqueueDedup(action, data, localId: localId);
        return enqueueFuture!;
      }

      Future<void> removeQueuedAfterSuccess() async {
        final future = enqueueFuture;
        if (!requestSucceeded || future == null) return;
        final id = await future;
        if (id != null) await DbService.I.removePending(id);
      }

      Future<void> saveSlowOperationLocally() async {
        try {
          await enqueueNow();
          await removeQueuedAfterSuccess();
          if (!done.isCompleted) {
            done.complete({'queued': true, 'payload': data});
          }
        } catch (e, st) {
          if (!done.isCompleted) done.completeError(e, st);
        }
      }

      // A los 5 s se registra la opción de guardar localmente.
      final slowTimer = Timer(const Duration(seconds: 5), () {
        if (localQueued || requestSucceeded || done.isCompleted) return;
        _slowOp = {
          'action': action,
          'data': data,
          'saveLocal': saveSlowOperationLocally,
        };
        _emit();
      });

      // Escritura interactiva: timeout corto para no dejar al usuario
      // esperando si la red se cae a mitad del guardado.
      ApiService.I.req(action, data, timeoutSec: 8).then((res) {
        slowTimer.cancel();
        requestSucceeded = true;
        _slowOp = null;
        _emit();
        if (!done.isCompleted) {
          done.complete(res);
        }
        // Esperar a que termine la inserción local antes de borrarla. Si la
        // respuesta remota ganó la carrera contra SQLite, la copia se quita
        // igualmente en cuanto exista.
        removeQueuedAfterSuccess().catchError((_) {});
      }).catchError((Object e) {
        slowTimer.cancel();
        _slowOp = null;
        _emit();
        if (localQueued) return; // el usuario ya eligió guardar localmente
        // Solo la ApiException con respuesta del servidor es rechazo
        // definitivo (sin encolar); el resto de errores de red se encola;
        // errores inesperados se propagan como antes.
        final definitive = e is ApiException && e.response != null;
        final networkish = e is ApiException || e is SocketException || e is TimeoutException;
        if (e is! ApiException && !networkish) {
          if (!done.isCompleted) {
            done.completeError(e, StackTrace.current);
          }
          return;
        }
        if (definitive) {
          // Rechazo definitivo: avisar SIN encolar (píldora venenosa).
          if (!done.isCompleted) {
            done.completeError(e, StackTrace.current);
          }
          return;
        }
        saveSlowOperationLocally();
      });
      return done.future;
    }
    await _enqueueDedup(action, data, localId: localId);
    _emit();
    return {'queued': true, 'payload': data};
  }

  // ------- Anuncios: coalescencia de ops sobre filas LOCALES --------

  /// Op en cola de creación de UN anuncio local. Busca por [localId] y, si
  /// la op es de una versión anterior de la app (sin ese enlace), por el
  /// título del payload.
  Future<Map<String, dynamic>?> queuedAnnouncementSaveFor(
      String localId, {String? title}) async {
    final ops = await DbService.I.pending();
    Map<String, dynamic>? byTitle;
    for (final op in ops) {
      if (op['action'] != 'ws_announcement_save') continue;
      if ('${op['local_id'] ?? ''}' == localId) return op;
      if (title != null &&
          title.isNotEmpty &&
          '${(op['data'] is Map ? op['data'] : const {})['title'] ?? ''}' ==
              title) {
        byTitle ??= op;
      }
    }
    return byTitle;
  }

  /// Reescribe el payload de la creación encolada de un anuncio local
  /// (editar/toggle mientras aún no se subió) en vez de encolar otra op que
  /// el servidor terminaría rechazando con "Anuncio no encontrado".
  /// Devuelve true si había una op y se actualizó.
  Future<bool> updateQueuedAnnouncementSave(
      String localId, Map<String, dynamic> data,
      {String? title}) async {
    final op = await queuedAnnouncementSaveFor(localId, title: title);
    if (op == null) return false;
    return DbService.I.updatePendingData(op['id'], data);
  }

  /// Aplica [fields] (p.ej. {active: '0'} o {pinned: '1'}) sobre el payload
  /// de la creación encolada de un anuncio local: el toggle de una fila que
  /// todavía no está en el servidor se "fusiona" en su propia creación en
  /// vez de generar una op que el servidor rechazaría.
  Future<bool> updateQueuedAnnouncementField(
      String localId, Map<String, String> fields,
      {String? title}) async {
    final op = await queuedAnnouncementSaveFor(localId, title: title);
    if (op == null) return false;
    final data = (op['data'] is Map)
        ? Map<String, dynamic>.from(op['data'] as Map)
        : <String, dynamic>{};
    data.addAll(fields);
    return DbService.I.updatePendingData(op['id'], data);
  }

  /// Descarta TODAS las ops encoladas que referencian una fila local
  /// (creación pendiente + toggles/borrados que ya no aplican).
  /// Devuelve cuántas ops se descartaron (0 = la creación ya se subió).
  Future<int> discardQueuedAnnouncementOps(String localId,
      {String? title}) async {
    var removed = 0;
    final ops = await DbService.I.pending();
    for (final op in ops) {
      final action = '${op['action'] ?? ''}';
      if (!action.startsWith('ws_announcement_')) continue;
      if ('${op['local_id'] ?? ''}' == localId) {
        await DbService.I.removePending(op['id']);
        removed++;
        continue;
      }
      // Ops antiguas (sin local_id): solo las de guardado se emparejan por
      // título; toggle/delete jamás se emparejan (su payload no tiene título).
      if (action == 'ws_announcement_save' &&
          title != null &&
          title.isNotEmpty &&
          '${(op['data'] is Map ? op['data'] : const {})['title'] ?? ''}' ==
              title) {
        await DbService.I.removePending(op['id']);
        removed++;
      }
    }
    if (removed > 0) {
      _emit();
    }
    return removed;
  }

  /// Filas de anuncios que aún están SOLO en el dispositivo (creación en
  /// cola): lo que la pantalla muestra como pendiente de subir. Se usan para
  /// no perderlas cuando el pull reemplaza la tabla con la lista del
  /// servidor.
  Future<List<Map<String, dynamic>>> queuedAnnouncementRows() async {
    final out = <Map<String, dynamic>>[];
    try {
      final ops = await DbService.I.pending();
      for (final op in ops) {
        if (op['action'] != 'ws_announcement_save') continue;
        final data = (op['data'] is Map)
            ? Map<String, dynamic>.from(op['data'] as Map)
            : <String, dynamic>{};
        if ('${data['title'] ?? ''}'.trim().isEmpty) continue;
        out.add({
          ...data,
          'id': '${op['local_id'] ?? ''}'.isNotEmpty
              ? '${op['local_id']}'
              : 'local-op-${op['id']}',
          'active': '${data['active'] ?? '1'}',
          'pinned': '${data['pinned'] ?? '0'}',
          'dismissible': '${data['dismissible'] ?? '1'}',
          'type': '${data['type'] ?? 'info'}',
          'message': '${data['message'] ?? ''}',
          'created_at':
              data['created_at'] ?? DateTime.now().toIso8601String(),
          'queued': true,
        });
      }
    } catch (_) {}
    return out;
  }

  /// Combina la lista del servidor con las operaciones de anuncios aún en
  /// cola, para que el pull no pise lo que todavía no se ha subido:
  ///  - las CREACIONES locales (id 'local-…') se añaden como filas propias;
  ///  - las EDICIONES pendientes de anuncios ya sincronizados se aplican
  ///    encima de su fila del servidor (mismo id).
  Future<List<Map<String, dynamic>>> mergeAnnouncementRows(
      List<Map<String, dynamic>> serverRows) async {
    final overrides = <String, Map<String, dynamic>>{};
    try {
      final ops = await DbService.I.pending();
      for (final op in ops) {
        if (op['action'] != 'ws_announcement_save') continue;
        final data = (op['data'] is Map)
            ? Map<String, dynamic>.from(op['data'] as Map)
            : <String, dynamic>{};
        if ('${data['title'] ?? ''}'.trim().isEmpty) continue;
        var id = '${op['local_id'] ?? ''}';
        if (id.isEmpty) id = '${data['id'] ?? ''}';
        if (id.isEmpty || id == '0') continue;
        overrides[id] = data;
      }
    } catch (_) {}
    final merged = <Map<String, dynamic>>[];
    for (final row in serverRows) {
      final override = overrides['${row['id']}'];
      if (override == null) {
        merged.add(Map<String, dynamic>.from(row));
      } else {
        merged.add({
          ...row,
          ...override,
          'id': row['id'],
          'queued': true,
        });
      }
    }
    for (final entry in overrides.entries) {
      if (!entry.key.startsWith('local-')) continue;
      merged.insert(0, {
        ...entry.value,
        'id': entry.key,
        'active': '${entry.value['active'] ?? '1'}',
        'pinned': '${entry.value['pinned'] ?? '0'}',
        'dismissible': '${entry.value['dismissible'] ?? '1'}',
        'type': '${entry.value['type'] ?? 'info'}',
        'message': '${entry.value['message'] ?? ''}',
        'created_at': entry.value['created_at'] ??
            DateTime.now().toIso8601String(),
        'queued': true,
      });
    }
    return merged;
  }

  /// Envía pendientes en orden. Devuelve {sent, remaining}.
  Future<Map<String, int>> flush() async {
    // Plan bloqueado: no se sube nada; la cola se conserva para reactivar.
    if (PlanGuardService.I.isBlocked) {
      return {'sent': 0, 'remaining': await DbService.I.pendingCount()};
    }
    if (_flushing) return {'sent': 0, 'remaining': await DbService.I.pendingCount()};
    if (ApiService.I.token == null) return {'sent': 0, 'remaining': await DbService.I.pendingCount()};
    final ops = await DbService.I.pending();
    if (ops.isEmpty) return {'sent': 0, 'remaining': 0};
    _flushing = true;
    _emit();
    var sent = 0;
    final failures = <String>[];
    try {
      for (final op in ops) {
        try {
          await DbService.I.updatePending(
            op['id'],
            status: 'sending',
            incrementAttempts: true,
          );
          await ApiService.I.req('${op['action']}',
              Map<String, dynamic>.from(op['data'] as Map),
              timeoutSec: 12);
          // Si el usuario coalescó un cambio (p.ej. editó un anuncio local)
          // MIENTRAS se enviaba esta op, la versión nueva sigue pendiente:
          // no se borra, se reenvía en el próximo flush.
          final fresh = await DbService.I.pendingById(op['id']);
          final samePayload = fresh == null ||
              jsonEncode(fresh['data'] ?? {}) == jsonEncode(op['data'] ?? {});
          if (!samePayload) {
            await DbService.I.updatePending(op['id'], status: 'pending');
          } else {
            await DbService.I.updatePending(op['id'], status: 'sent');
            await DbService.I.removePending(op['id']);
          }
          sent++;
        } on ApiException catch (e) {
          await DbService.I.updatePending(
            op['id'],
            status: 'error',
            error: e.message,
          );
          // Rechazo del servidor (o el `0` de admin-ajax con sesión
          // inválida/acción inexistente): NO detener la cola, solo marcar y
          // seguir. Detener aquí dejaba TODOS los pendientes detrás sin
          // subirse nunca (p.ej. los anuncios encolados).
          if (e.response != null || e.reachedServer) {
            failures.add(e.message.replaceAll(RegExp(r'\s+'), ' ').trim());
            continue; // definitiva: sigue en cola, continúa con la siguiente
          }
          break; // de red: parar, resto queda en cola
        } catch (e) {
          await DbService.I.updatePending(
            op['id'],
            status: 'error',
            error: '$e',
          );
          break;
        }
      }
    } finally {
      _flushing = false;
      _emit();
    }
    final remaining = await DbService.I.pendingCount();
    return {'sent': sent, 'remaining': remaining};
  }

  /// Descarta una operación pendiente definitivamente (desde la cola UI).
  Future<void> discardPending(Object id) async {
    await DbService.I.removePending(id);
    _emit();
  }

  /// Pull ligero: refresca UN solo endpoint del servidor y actualiza SQLite.
  /// Usa putAll (merge/upsert) en vez de replaceAll para no borrar datos
  /// locales que el servidor no devolvió (offline, filtros, paginación).
  Future<List<Map<String, dynamic>>?> pullStore(
      String action, Map<String, dynamic> params, String store,
      {String? cacheKey, String? dataKey, bool mergeOnly = true}) async {
    if (!_online) return null;
    if (PlanGuardService.I.isBlocked) return null;
    try {
      final d = await ApiService.I.req(action, params);
      final raw = dataKey != null ? d[dataKey] : d['data'];
      final rows = (raw is List)
          ? raw.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList()
          : <Map<String, dynamic>>[];
      if (mergeOnly) {
        await DbService.I.putAll(store, rows);
      } else {
        await DbService.I.replaceAll(store, rows);
      }
      if (cacheKey != null) await DbService.I.cacheSet(cacheKey, rows);
      return rows;
    } catch (_) {
      return null;
    }
  }

  /// Pull ultra-ligero: solo refresca el cache key (sin tabla SQLite).
  /// Para pantallas que leen de cacheGet() en vez de all().
  Future<void> pullCache(String action, Map<String, dynamic> params,
      String cacheKey, {String? dataKey}) async {
    if (!_online) return;
    if (PlanGuardService.I.isBlocked) return;
    try {
      final d = await ApiService.I.req(action, params);
      final data = dataKey != null ? d[dataKey] : (d['data'] ?? d);
      await DbService.I.cacheSet(cacheKey, data);
      _emit();
    } catch (_) {}
  }

  /// Devuelve la ficha mínima de un producto (nombre, foto y datos de
  /// presentación) a partir de la caché local. Se usa para hidratar filas de
  /// stock creadas offline y los movimientos locales, de modo que Historial y
  /// Stock muestren el mismo nombre/foto que el servidor aunque la fila sea
  /// nueva.
  ///
  /// Primero mira la tabla `products` (catálogo maestro) y, si el producto no
  /// está ahí, cualquier otra fila de `stock` con ese producto (la foto ya
  /// cacheada en otra ubicación). Vacío si no hay nada que copiar.
  static Future<Map<String, dynamic>> productInfoFor(
      dynamic productId, List<Map<String, dynamic>> stockRows) async {
    final out = <String, dynamic>{};
    final id = '$productId';
    if (id.isEmpty || id == 'null') return out;
    try {
      for (final p in await DbService.I.all('products')) {
        if ('${p['id']}' != id) continue;
        out.addAll(_productFields(p));
        return out;
      }
      // Fallback: otra fila de stock con el mismo producto.
      for (final row in stockRows) {
        if ('${row['product_id']}' != id) continue;
        out.addAll(_productFields(row));
        // `group_*` no se copia: son agregados de la fila de origen.
        return out;
      }
    } catch (e) {
      wsLog('! productInfoFor($id): $e');
    }
    return out;
  }

  /// Campos de ficha de producto que se copian a filas derivadas.
  static Map<String, dynamic> _productFields(Map raw) {
    const keys = [
      'name',
      'image',
      'gallery',
      'barcode',
      'category',
      'category_id',
      'description',
      'min_stock',
      'sale_price',
      'cost_price',
      'currency',
      'store_visible',
      'pos_visible',
    ];
    final out = <String, dynamic>{};
    for (final k in keys) {
      if (raw.containsKey(k)) out[k] = raw[k];
    }
    return out;
  }

  /// Ajusta el stock LOCAL (SQLite) y notifica en tiempo real.
  /// Clave para vender offline tras una entrada en cola: la entrada suma
  /// localmente al momento y el POS puede seguir vendiendo.
  ///
  /// UPSERT: si la fila (ubicación, producto) no existía se CREA, para que
  /// una transferencia hacia una ubicación sin registro previo —o una
  /// entrada de un producto nuevo— se refleje al instante en Stock, en las
  /// demás ubicaciones y en el POS (que leen esta misma tabla).
  Future<void> adjustLocalStock(dynamic locationId, dynamic productId, num delta,
      {bool notify = true}) async {
    try {
      final rows = await DbService.I.all('stock');
      var changed = false;
      for (final row in rows) {
        if ('${row['location_id']}' != '$locationId' ||
            '${row['product_id']}' != '$productId') continue;
        final q = ((row['qty'] as num?) ?? 0) + delta;
        row['qty'] = q < 0 ? 0 : q;
        changed = true;
      }
      if (!changed) {
        final q = delta < 0 ? 0 : delta;
        final created = <String, dynamic>{
          'id': 'p$productId:$locationId',
          'product_id': int.tryParse('$productId') ?? 0,
          'location_id': int.tryParse('$locationId') ?? 0,
          'qty': q,
        };
        // Hidrata con la ficha del producto (nombre, foto, precios…): sin
        // esto una transferencia hacia una ubicación sin registro previo
        // aparecía en Stock como "solo el monto", sin nombre ni foto.
        created.addAll(await productInfoFor(productId, rows));
        rows.add(created);
      }
      await DbService.I.putAll('stock', rows);
      if (notify) _emit();
    } catch (e) {
      wsLog('! adjustLocalStock($locationId,$productId,$delta): $e');
      // Aunque falle la escritura se notifica: la pantalla debe refrescar
      // con lo que haya, no quedarse con datos viejos.
      if (notify) _emit();
    }
  }

  /// Reaplica sobre el stock local los deltas de las operaciones de stock
  /// que siguen EN COLA (todavía no enviadas).
  ///
  /// Un pull descarga el estado del servidor, que aún no incluye esos
  /// movimientos: sin este paso, refrescar —o entrar a Stock/POS— "deshacía"
  /// lo que el usuario acababa de registrar offline y obligaba a subir la
  /// cola para volver a verlo. Se ejecuta SIEMPRE después de escribir el
  /// stock que vino del servidor.
  Future<void> reapplyPendingStockDeltas() async {
    try {
      final ops = await DbService.I.pending();
      if (ops.isEmpty) return;

      // Combo -> componentes, para descontar sus productos reales.
      Map<int, List<Map<String, dynamic>>> comboItems = {};
      try {
        for (final c in await DbService.I.all('combos')) {
          final cid = int.tryParse('${c['id'] ?? 0}') ?? 0;
          if (cid <= 0) continue;
          final its = c['items'];
          if (its is List) {
            comboItems[cid] = its.whereType<Map>().toList().cast<Map<String, dynamic>>();
          }
        }
      } catch (_) {}

      final deltas = <String, num>{};
      void add(dynamic loc, dynamic pid, num d) {
        final l = int.tryParse('$loc') ?? 0;
        final p = int.tryParse('$pid') ?? 0;
        if (l <= 0 || p <= 0) return;
        final k = '$l|$p';
        deltas[k] = (deltas[k] ?? 0) + d;
      }

      for (final op in ops) {
        final action = '${op['action'] ?? ''}';
        final data = (op['data'] is Map)
            ? Map<String, dynamic>.from(op['data'] as Map)
            : <String, dynamic>{};
        final items = (data['items'] is List)
            ? (data['items'] as List).whereType<Map>().toList()
            : <Map>[];

        switch (action) {
          case 'ws_stock_move':
            add(data['location_id'], data['product_id'],
                _moveSign('${data['type']}', (data['qty'] as num?) ?? 0));
            break;
          case 'ws_stock_batch_move':
            for (final it in items) {
              add(data['location_id'], it['product_id'],
                  _moveSign('${data['type']}', (it['qty'] as num?) ?? 0));
            }
            break;
          case 'ws_stock_transfer':
            final q = (data['qty'] as num?) ?? 0;
            add(data['from_location'], data['product_id'], -q);
            add(data['to_location'], data['product_id'], q);
            break;
          case 'ws_stock_batch_transfer':
            for (final it in items) {
              final q = (it['qty'] as num?) ?? 0;
              add(data['from_location'], it['product_id'], -q);
              add(data['to_location'], it['product_id'], q);
            }
            break;
          case 'ws_pos_sale_save':
            // La venta descuenta stock en la ubicación del POS.
            for (final it in items) {
              final q = (it['qty'] as num?) ?? 0;
              final cid = int.tryParse('${it['combo_id'] ?? 0}') ?? 0;
              if (cid > 0) {
                for (final ci in comboItems[cid] ?? const <Map<String, dynamic>>[]) {
                  add(data['location_id'], ci['product_id'],
                      -q * (((ci['qty'] as num?) ?? 1)));
                }
              } else {
                add(data['location_id'], it['product_id'], -q);
              }
            }
            break;
          default:
            break;
        }
      }
      if (deltas.isEmpty) return;
      final rows = await DbService.I.all('stock');
      for (final e in deltas.entries) {
        final parts = e.key.split('|');
        final loc = parts[0], pid = parts[1];
        var found = false;
        for (final row in rows) {
          if ('${row['location_id']}' != loc || '${row['product_id']}' != pid) {
            continue;
          }
          final q = ((row['qty'] as num?) ?? 0) + e.value;
          row['qty'] = q < 0 ? 0 : q;
          found = true;
        }
        if (!found) {
          rows.add({
            'id': 'p$pid:$loc',
            'product_id': int.tryParse(pid) ?? 0,
            'location_id': int.tryParse(loc) ?? 0,
            'qty': e.value < 0 ? 0 : e.value,
          });
        }
      }
      await DbService.I.putAll('stock', rows);
      _emit();
    } catch (e) {
      wsLog('! reapplyPendingStockDeltas: $e');
    }
  }

  /// Signo de un movimiento de stock según su tipo (entrada suma, el resto
  /// resta — igual que en la web).
  num _moveSign(String type, num qty) {
    if (type == 'entrada') return qty;
    if (type == 'salida' || type == 'baja' || type == 'venta') return -qty;
    return 0;
  }
}
