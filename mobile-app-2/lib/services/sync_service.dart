import 'dart:async';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api_service.dart';
import 'auth_service.dart';
import 'db_service.dart';
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

  bool get isOnline => _online;
  bool get isPulling => _pulling;
  bool get isBusy => _pulling || _flushing;
  int get autoSyncMinutes => _autoSyncMinutes;
  int get lastSyncMs => _lastSync;

  void onChange(void Function() fn) => _changeListeners.add(fn);
  void removeOnChange(void Function() fn) => _changeListeners.remove(fn);
  void onBackgroundSync(void Function() fn) => _bgListeners.add(fn);

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
    // Cronómetro de autosync: SOLO intervalos periódicos. Sin sync automática
    // al abrir la app ni al volver a primer plano ni al recuperar conexión
    // (igual que la app anterior).
    if (autoSync) startTimer();
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

  /// Sube pendientes y baja cambios. Devuelve 'offline' | true | false.
  Future<Object> syncNow() async {
    await checkConnectivity();
    if (!_online) return 'offline';
    final flushRes = await flush();
    if ((flushRes['remaining'] ?? 0) > 0) return false;
    final okPull = await pull();
    _lastSync = DateTime.now().millisecondsSinceEpoch;
    for (final fn in List.of(_bgListeners)) { try { fn(); } catch (_) {} }
    _emit();
    return okPull;
  }

  Future<void> backgroundSync() async {
    await checkConnectivity();
    if (_online && !_pulling && !_flushing) {
      try { await syncNow(); } catch (_) {}
    }
  }

  // ---------------- PULL ----------------

  Future<bool> pull() async {
    if (_pulling) return false;
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
      if (hasCap('categories_manage')) {
        await step(() => ApiService.I.req('ws_categories_list', {}).then((d) async {
              final dm = d as Map;
              final rows = List<Map<String, dynamic>>.from(((dm['categories']) as List?) ?? []);
              await DbService.I.cacheSet('ws_categories_list', rows);
              await DbService.I.cacheSet('ws_categories_payload', dm['payload'] ?? {});
              await DbService.I.replaceAll('categories', rows);
            }));
      }

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
          }));

      // Movimientos (merge/upsert: preservar movimientos creados offline).
      await step(() => ApiService.I.req('ws_movements_list',
          {'scope': '', 'pageSize': 500, 'page': 1}).then((d) async {
            final rows = List<Map<String, dynamic>>.from((((d as Map)['movements']) as List?) ?? []);
            await DbService.I.cacheSet('ws_movements_list', rows);
            await DbService.I.putAll('movements', rows);
          }));

      // Pedidos web.
      await step(() => ApiService.I.req('ws_order_list', {'pageSize': 200, 'page': 1}).then((d) async {
            final rows = List<Map<String, dynamic>>.from((((d as Map)['orders']) as List?) ?? []);
            await DbService.I.cacheSet('ws_order_list', rows);
            await DbService.I.replaceAll('orders', rows);
          }));

      // Clientes.
      if (hasCap('customers_view')) {
        await step(() => ApiService.I.req('ws_cache_customers', {}).then((d) async {
              final rows = List<Map<String, dynamic>>.from(((d as Map)['data'] as List?) ?? []);
              await DbService.I.cacheSet('ws_customers_get', rows);
              await DbService.I.replaceAll('customers', rows);
            }));
      }

      // Anuncios.
      if (menuKeys.contains('anuncios')) {
        await step(() => ApiService.I.req('ws_announcements_list', {}).then((d) async {
              final rows = List<Map<String, dynamic>>.from((((d as Map)['list']) as List?) ?? []);
              await DbService.I.cacheSet('ws_announcements_list', rows);
              await DbService.I.replaceAll('announcements', rows);
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
      if (menuKeys.contains('settings')) {
        await step(() => ApiService.I.req('ws_settings_get', {})
            .then((d) => DbService.I.cacheSet('ws_settings_get', (d as Map)['data'] ?? {})));
      }
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

  /// Encola una operación. Ver contrato en el encabezado de la clase.
  Future<dynamic> push(String action, Map<String, dynamic> data) async {
    if (_online) {
      try {
        // Escritura interactiva: timeout corto para no dejar al usuario
        // esperando si la red se cae a mitad del guardado.
        final res = await ApiService.I.req(action, data, timeoutSec: 8);
        // NO _emit() aquí: solo la pantalla que hizo la acción recarga
        // con su propio _reload() después de handlePush.
        return res;
      } on ApiException catch (e) {
        if (e.response != null) {
          // Rechazo definitivo: avisar SIN encolar (píldora venenosa).
          _emit();
          rethrow;
        }
        await DbService.I.enqueue(action, data);
        _emit();
        return {'queued': true, 'payload': data};
      }
    }
    await DbService.I.enqueue(action, data);
    _emit();
    return {'queued': true, 'payload': data};
  }

  /// Envía pendientes en orden. Devuelve {sent, remaining}.
  Future<Map<String, int>> flush() async {
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
          await ApiService.I.req('${op['action']}',
              Map<String, dynamic>.from(op['data'] as Map),
              timeoutSec: 12);
          sent++;
          await DbService.I.removePending(op['id']);
        } on ApiException catch (e) {
          if (e.response != null) {
            failures.add(e.message.replaceAll(RegExp(r'\s+'), ' ').trim());
            continue; // definitiva: sigue en cola, continúa con la siguiente
          }
          break; // de red: parar, resto queda en cola
        } catch (_) {
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
    try {
      final d = await ApiService.I.req(action, params);
      final data = dataKey != null ? d[dataKey] : (d['data'] ?? d);
      await DbService.I.cacheSet(cacheKey, data);
      _emit();
    } catch (_) {}
  }

  /// Ajusta el stock LOCAL (SQLite) y notifica en tiempo real.
  /// Clave para vender offline tras una entrada en cola: la entrada suma
  /// localmente al momento y el POS puede seguir vendiendo.
  Future<void> adjustLocalStock(dynamic locationId, dynamic productId, num delta) async {
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
      if (!changed) return;
      await DbService.I.putAll('stock', rows);
      _emit();
    } catch (_) {}
  }
}
