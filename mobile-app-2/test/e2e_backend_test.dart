/// Tests de integración REALES contra el backend WordPress local (XAMPP).
///
/// Validan los flujos cambiados en el panel: login móvil, categorías CRUD,
/// asignación de turnos por mes (bulk `dates`), reportes ampliados, pedidos
/// y movimientos. Se ejecuta `flutter test test/e2e_backend_test.dart`.
///
/// Requisitos:
///  - Apache + MySQL arriba con el tema workshop activo en
///    http://localhost/workshop (XAMPP).
///  - Usuarios E2E sembrados una vez:
///      C:\xampp\php\php.exe ws-test-seed.php seed
///
/// Configuración (opcional) por variables de entorno:
///  WS_E2E_BASE  -> http://localhost/workshop
///  WS_E2E_USER  -> ws_e2e_owner   (dueño: gestiona categorías + turnos)
///  WS_E2E_PASS  -> E2eOwner!2026
///  WS_E2E_SELLER_USER / WS_E2E_SELLER_PASS -> vendedor de control.
///
/// NOTA: usa `test()` puro (sin binding de widgets de flutter_test), así las
/// peticiones HTTP son reales.
/// Los tests E2E hacen varias peticiones reales seguidas; al correr la suite
/// completa la máquina/localhost van más lentos, así que subimos el límite.
@Timeout(Duration(minutes: 5))
library;

import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:test/test.dart';

const _timeout = Duration(seconds: 45);

String get _base => (Platform.environment['WS_E2E_BASE'] ?? 'http://localhost/workshop')
    .replaceAll(RegExp(r'/+$'), '');
String get _user => Platform.environment['WS_E2E_USER'] ?? 'ws_e2e_owner';
String get _pass => Platform.environment['WS_E2E_PASS'] ?? 'E2eOwner!2026';
String get _sellerUser => Platform.environment['WS_E2E_SELLER_USER'] ?? 'ws_e2e_seller';
String get _sellerPass => Platform.environment['WS_E2E_SELLER_PASS'] ?? 'E2eSeller!2026';

Uri get _endpoint => Uri.parse('$_base/wp-admin/admin-ajax.php');

/// Petición admin-ajax igual que ApiService.req: List/Map como JSON, auth por
/// header X-WS-Token. Devuelve el JSON completo de WordPress.
Future<Map<String, dynamic>> _postRaw(String action, Map<String, dynamic> data,
    {String? token}) async {
  final body = <String, String>{'action': action};
  data.forEach((k, v) {
    if (v == null) return;
    body[k] = (v is List || v is Map) ? jsonEncode(v) : '$v';
  });
  final res = await http
      .post(_endpoint,
          headers: {
            'X-WS-Token': token ?? '',
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
          },
          body: body)
      .timeout(_timeout);
  expect(res.statusCode, 200, reason: '$action HTTP ${res.statusCode}: ${res.body}');
  final decoded = _tryJson(res.bodyBytes);
  expect(decoded, isNotNull, reason: '$action devolvió JSON inválido');
  return decoded!.cast<String, dynamic>();
}

/// Como [_postRaw] pero sin asumir HTTP 200/JSON: útil para comprobar rechazos
/// sin token (WordPress responde `0` con HTTP 400 si no hay wp_ajax_*_nopriv).
Future<({int status, Map<String, dynamic>? json})> _postLenient(
    String action, Map<String, dynamic> data,
    {String? token}) async {
  final body = <String, String>{'action': action};
  data.forEach((k, v) {
    if (v == null) return;
    body[k] = (v is List || v is Map) ? jsonEncode(v) : '$v';
  });
  final res = await http
      .post(_endpoint,
          headers: {
            'X-WS-Token': token ?? '',
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
          },
          body: body)
      .timeout(_timeout);
  return (status: res.statusCode, json: _tryJson(res.bodyBytes));
}

Map<String, dynamic>? _tryJson(List<int> bytes) {
  try {
    final d = jsonDecode(utf8.decode(bytes, allowMalformed: true));
    return d is Map<String, dynamic> ? d : null;
  } catch (_) {
    return null;
  }
}

/// Petición que DEBE devolver `{success: true, data: {...}}`.
Future<Map<String, dynamic>> _postOk(String action, Map<String, dynamic> data,
    {String? token}) async {
  final r = await _postRaw(action, data, token: token);
  final ok = r['success'] == true || r['success'] == 1;
  if (!ok) {
    fail('$action: success=false: ${r['data']}');
  }
  final d = r['data'];
  expect(d, isA<Map<String, dynamic>>(), reason: '$action: data debe ser Map');
  return d!.cast<String, dynamic>();
}

String _ymd(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

Map<String, dynamic> _asMap(dynamic d) => (d as Map).cast<String, dynamic>();
List<Map<String, dynamic>> _asList(dynamic d) =>
    (d as List).cast<Map<String, dynamic>>().map(_asMap).toList();

/// ID del vendedor E2E leyéndolo de ws_workers_list (el login está bloqueado
/// para él sin turnos, así que no se obtiene por la sesión móvil).
Future<int> _sellerUserId(String ownerToken) async {
  final d = await _postOk('ws_workers_list', {'search': 'e2e'}, token: ownerToken);
  for (final w in _asList(d['workers'])) {
    if ('${w['user_email']}'.contains('e2e.seller')) {
      return (w['id'] as num).toInt();
    }
  }
  fail('no se encontró al vendedor E2E (ws.e2e.seller) en ws_workers_list');
}

void main() {
  late String token;
  late Map<String, dynamic> me;
  late int ownerId;
  late List<Map<String, dynamic>> myLocations;

  setUpAll(() async {
    final r = await _postRaw('ws_mobile_login', {'ws_user': _user, 'ws_pass': _pass});
    expect(r['success'], isTrue, reason: 'login falló: ${r['data']}');
    final data = _asMap(r['data']);
    token = data['token'] as String;
    me = _asMap(data['me']);
    ownerId = (me['userId'] as num).toInt();
    final locs = me['locations'];
    expect(locs, isA<List>(), reason: 'me.locations debe ser lista');
    myLocations = _asList(locs!);
    expect(myLocations, isNotEmpty, reason: 'el dueño E2E debe tener ubicaciones');
  });

  group('Autenticación móvil', () {
    test('login devuelve token, perfil y caps del propio rol', () {
      expect(token, isNotEmpty);
      expect(ownerId, greaterThan(0));
      expect(me['role'], 'owner');
      expect(_user, contains('owner'));
      expect(me['caps'], isA<Map<String, dynamic>>());
      expect(me['caps']['categories_manage'], isTrue,
          reason: 'el dueño debe poder gestionar categorías');
      expect(me['caps']['shifts_manage'], isTrue);
      expect(me['caps']['reports_view'], isTrue);
      expect(me.containsKey('wsVersion'), isTrue);
    });

    test('sin token las acciones protegidas son rechazadas', () async {
      for (final action in ['ws_categories_list', 'ws_reports_summary', 'ws_save_shift']) {
        final r = await _postLenient(action, {});
        final denied = (r.json != null && r.json!['success'] == false) ||
            (r.json == null && r.status >= 400);
        expect(denied, isTrue,
            reason: '$action no debió funcionar sin token (${r.status}, ${r.json})');
      }
    });

    test('vendedor: bloqueado sin turnos; con turno entra pero sin gestión', () async {
      // Autolimpieza: borra turnos previos del vendedor (usuario solo de E2E).
      final sellerId = await _sellerUserId(token);
      final loc = (myLocations.first['id'] as num).toInt();
      final today = _ymd(DateTime.now());

      final before = await _postOk('ws_shifts_list', {'start': '2000-01-01', 'end': '2099-12-31', 'location_id': 0}, token: token);
      for (final s in _asList(before['shifts'])) {
        if ((s['user_id'] as num).toInt() == sellerId) {
          await _postRaw('ws_delete_shift', {'id': s['id']}, token: token);
        }
      }
      // Sin turnos → el filtro ws_block_no_shift_login bloquea el acceso móvil.
      final blocked = await _postRaw(
          'ws_mobile_login', {'ws_user': _sellerUser, 'ws_pass': _sellerPass});
      expect(blocked['success'], isFalse,
          reason: 'vendedor sin turnos no debe poder iniciar sesión');

      // Con un turno asignado sí entra, pero sin capacidades de gestión.
      final made = await _postOk('ws_save_shift',
          {'location_id': loc, 'user_id': sellerId, 'time_start': '08:00', 'time_end': '12:00', 'note': 'e2e seller', 'dates': [today]},
          token: token);
      final shiftId = (made['ids'] as List).cast<num>().first.toInt();
      try {
        final r = await _postRaw(
            'ws_mobile_login', {'ws_user': _sellerUser, 'ws_pass': _sellerPass});
        expect(r['success'], isTrue, reason: 'login vendedor con turno falló: ${r['data']}');
        final sData = _asMap(r['data']);
        final sCaps = _asMap(_asMap(sData['me'])['caps']);
        expect(sCaps['categories_manage'], isFalse);
        expect(sCaps['shifts_manage'], isFalse);
        expect(sCaps['shifts_view'], isTrue, reason: 'sí debe ver sus turnos');
        final sToken = sData['token'] as String;
        // Los guards de escritura lo bloquean (no solo el menú).
        final cat = await _postRaw('ws_categories_list', {}, token: sToken);
        expect(cat['success'], isFalse, reason: 'vendedor no debe listar categorías');
        final save = await _postRaw('ws_save_shift', {'location_id': loc, 'user_id': sellerId, 'dates': [today]}, token: sToken);
        expect(save['success'], isFalse, reason: 'vendedor no debe crear turnos');
        // Y la lectura permitida sí funciona.
        final view = await _postOk('ws_shifts_list', {'start': '2000-01-01', 'end': '2099-12-31'}, token: sToken);
        expect(view['shifts'], isA<List>());
      } finally {
        await _postRaw('ws_delete_shift', {'id': shiftId}, token: token);
      }
      // Al quitarle el único turno vuelve a quedar bloqueado.
      final blockedAgain = await _postRaw(
          'ws_mobile_login', {'ws_user': _sellerUser, 'ws_pass': _sellerPass});
      expect(blockedAgain['success'], isFalse,
          reason: 'sin turnos el vendedor debe volver a estar bloqueado');
    });
  });

  group('Categorías (CRUD real)', () {
    test('listado expone listado + payload, con ruta/árbol', () async {
      final d = await _postOk('ws_categories_list', {}, token: token);
      expect(d['categories'], isA<List>());
      final payload = _asMap(d['payload']);
      expect(payload.containsKey('tree'), isTrue);
      expect(payload.containsKey('flat'), isTrue);
      for (final c in _asList(d['categories'])) {
        expect(c['id'], isA<num>());
        expect(c.containsKey('name'), isTrue);
        expect(c.containsKey('parent_id'), isTrue);
        expect(c.containsKey('slug'), isTrue);
        expect(c.containsKey('active'), isTrue);
        expect(c.containsKey('path'), isTrue);
        expect(c.containsKey('children'), isTrue);
        expect(c.containsKey('products'), isTrue);
      }
    });

    test('crear → editar → listar → borrar (con limpieza)', () async {
      final name = 'E2E Cat ${DateTime.now().millisecondsSinceEpoch}';
      final created = await _postOk('ws_category_save', {'name': name, 'sort_order': 1, 'active': 1}, token: token);
      final id = (created['id'] as num).toInt();
      expect(id, greaterThan(0));

      try {
        final list1 = await _postOk('ws_categories_list', {}, token: token);
        final cat = _asList(list1['categories']).firstWhere((c) => (c['id'] as num).toInt() == id);
        expect(cat['name'], name);
        expect(cat['parent_id'], 0);
        expect(cat['active'], 1);

        final upd = await _postOk('ws_category_save', {'id': id, 'name': '$name 2', 'sort_order': 5, 'active': 0}, token: token);
        expect((upd['id'] as num).toInt(), id);

        final list2 = await _postOk('ws_categories_list', {}, token: token);
        final cat2 = _asList(list2['categories']).firstWhere((c) => (c['id'] as num).toInt() == id);
        expect(cat2['name'], '$name 2');
        expect(cat2['sort_order'], 5);
        expect(cat2['active'], 0);
      } finally {
        await _postRaw('ws_category_delete', {'id': id}, token: token);
      }

      final list3 = await _postOk('ws_categories_list', {}, token: token);
      final gone = _asList(list3['categories']).where((c) => (c['id'] as num).toInt() == id);
      expect(gone, isEmpty, reason: 'la categoría E2E debió borrarse');
    });
  });

  group('Turnos (asignación de mes / bulk dates)', () {
    test('listado shape y filtro por ubicación', () async {
      final loc = myLocations.first['id'] as num;
      final d = await _postOk('ws_shifts_list', {'start': _ymd(DateTime(2020, 1, 1)), 'end': _ymd(DateTime.now().add(const Duration(days: 180))), 'location_id': loc}, token: token);
      expect(d['shifts'], isA<List>());
      for (final s in _asList(d['shifts'])) {
        expect(s['location_id'] as num, loc);
        for (final k in ['id', 'title', 'start', 'end', 'user_id', 'shift_date', 'time_start', 'time_end']) {
          expect(s.containsKey(k), isTrue, reason: 'turno sin $k');
        }
      }
    });

    test('crear bulk de fechas → no duplica → borrar', () async {
      final loc = (myLocations.first['id'] as num).toInt();
      // Fechas 90 días adelante: evitan chocar con turnos reales existentes.
      final base = DateTime.now().add(const Duration(days: 90));
      final dates = [base, base.add(const Duration(days: 1)), base.add(const Duration(days: 2))].map(_ymd).toList();
      final payload = {
        'location_id': loc,
        'user_id': ownerId,
        'time_start': '09:30',
        'time_end': '13:30',
        'note': 'e2e bulk',
        'dates': dates,
      };

      final createdIds = <int>[];
      try {
        final bulk = await _postOk('ws_save_shift', payload, token: token);
        final ids = (bulk['ids'] as List).cast<num>().map((e) => e.toInt()).toList();
        final created = (bulk['created'] as num).toInt();
        final skipped = (bulk['skipped'] as num?)?.toInt() ?? 0;
        expect(ids.length, created);
        expect(created + skipped, dates.length,
            reason: 'todas las fechas deben quedar contabilizadas (creadas u omitidas)');
        expect(created, greaterThan(0), reason: 'deberían crearse turnos nuevos');
        createdIds.addAll(ids);

        // Segunda llamada: NO debe duplicar.
        final again = await _postOk('ws_save_shift', payload, token: token);
        expect((again['created'] as num).toInt(), 0);
        expect((again['skipped'] as num).toInt(), equals(dates.length));

        // Los creados aparecen en el rango.
        final list = await _postOk('ws_shifts_list', {'start': dates.first, 'end': dates.last, 'location_id': 0}, token: token);
        final inRange = _asList(list['shifts']).map((s) => (s['id'] as num).toInt()).toSet();
        expect(inRange, containsAll(createdIds));
      } finally {
        for (final id in createdIds) {
          await _postRaw('ws_delete_shift', {'id': id}, token: token);
        }
      }

      final after = await _postOk('ws_shifts_list', {'start': dates.first, 'end': dates.last, 'location_id': 0}, token: token);
      final idsAfter = _asList(after['shifts']).map((s) => (s['id'] as num).toInt()).toSet();
      for (final id in createdIds) {
        expect(idsAfter, isNot(contains(id)), reason: 'turno E2E $id debió eliminarse');
      }
    });
  });

  group('Reportes (secciones nuevas del summary)', () {
    test('summary expone bottom/transactions/pos_*/utils y filtros', () async {
      final d = await _postOk('ws_reports_summary', {}, token: token);
      // ws_reports_summary devuelve data.data (doble envoltura); la app lo
      // desenvuelve igual en reports_screen (outer['data'] ?? outer).
      final outer = _asMap(d['data']);
      final out = outer.containsKey('data') ? _asMap(outer['data']) : outer;
      for (final k in [
        'filters', 'currency', 'sales', 'by_type', 'top_all', 'bottom',
        'transactions', 'pos_summary', 'pos_sales', 'pos_products',
        'total_sales', 'total_orders', 'total_units', 'total_moves',
        'avg_sale', 'currency_totals', 'utils',
      ]) {
        expect(out.containsKey(k), isTrue, reason: 'falta la clave $k en ws_reports_summary');
      }
      expect(_asMap(out['filters'])['location_id'], 0);
      expect(out['bottom'], isA<List>());
      expect(out['transactions'], isA<List>());
      expect(out['pos_sales'], isA<List>());
      expect(out['pos_products'], isA<List>());
      expect(out['currency_totals'], isA<List>());
      final utils = _asMap(out['utils']);
      expect(utils.containsKey('months'), isTrue);
      expect(utils.containsKey('totals'), isTrue);
      expect(utils.containsKey('by_loc'), isTrue);
    });
  });

  group('Pedidos y movimientos (shape de listados)', () {
    test('order_list devuelve orders/total/page/pageSize', () async {
      final d = await _postOk('ws_order_list', {'date_from': '2000-01-01', 'date_to': '2099-12-31'}, token: token);
      expect(d['orders'], isA<List>());
      expect(d['total'], isA<num>());
      expect(d.containsKey('page'), isTrue);
      expect(d.containsKey('pageSize'), isTrue);
      for (final o in _asList(d['orders'])) {
        for (final k in ['id', 'number', 'location_name', 'customer_name', 'subtotal', 'total', 'currency', 'status', 'date']) {
          expect(o.containsKey(k), isTrue, reason: 'pedido sin $k');
        }
      }
    });

    test('movements_list devuelve movements/total con campos resueltos', () async {
      final d = await _postOk('ws_movements_list', {}, token: token);
      expect(d['movements'], isA<List>());
      expect(d['total'], isA<num>());
      for (final m in _asList(d['movements'])) {
        for (final k in ['id', 'type', 'product_name', 'location_name', 'qty', 'user_name', 'date', 'revertable']) {
          expect(m.containsKey(k), isTrue, reason: 'movimiento sin $k');
        }
      }
    });
  });
}