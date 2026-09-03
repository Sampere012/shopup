/// Matriz de integración REAL de las acciones `ws_*` que llama la app móvil,
/// contra el backend WordPress local (XAMPP): http://localhost/workshop.
///
/// Cubre "cada función/botón" a nivel API: lectura + shape de cada endpoint,
/// escrituras reales con datos temporales y limpieza, flujos idempotentes
/// (settings/permissions/apariencia), ciclo completo de caja POS, movimiento
/// de stock real + revert, y rutas peligrosas con error controlado (nunca 500).
///
/// Requisitos:
///  - Apache + MySQL arriba; tema workshop activo en http://localhost/workshop.
///  - Usuarios E2E sembrados: C:\xampp\php\php.exe ws-test-seed.php seed
///  - Owner con caps completas en el negocio por defecto.
///
/// Configuración opcional (env):
///  WS_E2E_BASE / WS_E2E_USER / WS_E2E_PASS
///  WS_E2E_PHP (ruta php.exe) / WS_E2E_WORKDIR (raíz del repo workshop)
///
/// Limpieza por SQL (pedidos/ventas/cuadres no tienen borrado AJAX):
///  ws-e2e-helper.php cleanup-order|cleanup-pos-sale|cleanup-count &lt;id&gt;
@Timeout(Duration(minutes: 5))
library;

import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:test/test.dart';

const _timeout = Duration(seconds: 45);

String _sessionToken = '';

String get _base => (Platform.environment['WS_E2E_BASE'] ?? 'http://localhost/workshop')
    .replaceAll(RegExp(r'/+$'), '');
String get _user => Platform.environment['WS_E2E_USER'] ?? 'ws_e2e_owner';
String get _pass => Platform.environment['WS_E2E_PASS'] ?? 'E2eOwner!2026';
String get _php => Platform.environment['WS_E2E_PHP'] ?? r'C:\xampp\php\php.exe';
String get _workdir => Platform.environment['WS_E2E_WORKDIR'] ?? r'C:\xampp\htdocs\workshop';

Uri get _endpoint => Uri.parse('$_base/wp-admin/admin-ajax.php');

Map<String, String> _body(String action, Map<String, dynamic> data) {
  final b = <String, String>{'action': action};
  data.forEach((k, v) {
    if (v == null) return;
    b[k] = (v is List || v is Map) ? jsonEncode(v) : '$v';
  });
  return b;
}

Future<http.Response> _http(String action, Map<String, dynamic> data,
    {String? token, bool raw = false, Duration? timeout}) {
  final headers = {
    'X-WS-Token': token ?? _sessionToken,
    'X-Requested-With': 'XMLHttpRequest',
    'Accept': raw ? 'application/pdf' : 'application/json',
  };
  return http.post(_endpoint, headers: headers, body: _body(action, data))
      .timeout(timeout ?? _timeout);
}

Map<String, dynamic>? _tryJson(List<int> bytes) {
  try {
    final d = jsonDecode(utf8.decode(bytes, allowMalformed: true));
    return d is Map<String, dynamic> ? d : null;
  } catch (_) {
    return null;
  }
}

/// Respuesta completa de WordPress ya decodificada.
Future<Map<String, dynamic>> _postRaw(String action, Map<String, dynamic> data,
    {String? token}) async {
  final res = await _http(action, data, token: token);
  expect(res.statusCode, 200,
      reason: '$action HTTP ${res.statusCode}: ${res.body}');
  final d = _tryJson(res.bodyBytes);
  expect(d, isNotNull, reason: '$action devolvió JSON inválido: ${res.body}');
  return d!.cast<String, dynamic>();
}

/// `success: true` con `data` obligatoriamente Map (shape de la mayoría).
/// Algunos handlers devuelven data => { data => {...} } (doble wrap): se
/// deshace si el Map exterior contiene una clave `data` que también es Map.
Future<Map<String, dynamic>> _postOk(String action, Map<String, dynamic> data,
    {String? token}) async {
  final r = await _postOkAny(action, data, token: token);
  final d = r['data'];
  expect(d, isA<Map<String, dynamic>>(), reason: '$action: data debe ser Map');
  final m = _asMap(d);
  if (m['data'] is Map) {
    return _asMap(m['data']);
  }
  return m;
}

/// `success: true`, devuelve el JSON completo (data puede ser cualquier cosa).
Future<Map<String, dynamic>> _postOkAny(String action, Map<String, dynamic> data,
    {String? token}) async {
  final r = await _postRaw(action, data, token: token);
  final ok = r['success'] == true || r['success'] == 1;
  if (!ok) {
    fail('$action: success=false: ${r['data']}');
  }
  return r;
}

/// `success: false` con mensaje; devuelve el mensaje exacto.
Future<String> _postErrMsg(String action, Map<String, dynamic> data,
    {String? token}) async {
  final r = await _postRaw(action, data, token: token);
  final ok = r['success'] == true || r['success'] == 1;
  if (ok) {
    fail('$action debió fallar, pero success=true: ${r['data']}');
  }
  final msg = r['data'];
  expect(msg, isA<Map<String, dynamic>>(), reason: '$action: data de error debe ser Map');
  final m = (msg as Map)['msg'];
  expect(m, isA<String>(), reason: '$action: error sin msg: $msg');
  return m as String;
}

Map<String, dynamic> _asMap(dynamic d) => (d as Map).cast<String, dynamic>();
List<Map<String, dynamic>> _asList(dynamic d) =>
    (d as List).cast<Map<String, dynamic>>().map(_asMap).toList();
double _num(dynamic v) => (v as num).toDouble();

Future<void> _phpCli(List<String> args) async {
  final res = await Process.run(_php, args, workingDirectory: _workdir).timeout(_timeout);
  expect(res.exitCode, 0,
      reason: 'php ${args.join(' ')}: ${res.stdout} ${res.stderr}');
}

void main() {
  late String token;
  late Map<String, dynamic> me;
  late int ownerId;
  late List<Map<String, dynamic>> myLocations;
  late int locA;
  late int locB;
  late bool hasLocB;

  int? tempProductId;
  late String tempProductName;
  int? comboId;
  int? closedRegisterId;
  final ts = DateTime.now().millisecondsSinceEpoch;
  String ref(String tag) => 'E2E-$tag-$ts';

  setUpAll(() async {
    final r = await _postRaw('ws_mobile_login', {'ws_user': _user, 'ws_pass': _pass});
    expect(r['success'], isTrue, reason: 'login falló: ${r['data']}');
    final data = _asMap(r['data']);
    token = data['token'] as String;
    me = _asMap(data['me']);
    ownerId = (me['userId'] as num).toInt();
    _sessionToken = token;
    final locs = me['locations'];
    expect(locs, isA<List>());
    myLocations = _asList(locs as dynamic);
    expect(myLocations, isNotEmpty);
    locA = (myLocations.first['id'] as num).toInt();
    hasLocB = myLocations.length > 1;
    locB = hasLocB ? (myLocations[1]['id'] as num).toInt() : locA;
  });

  group('Sesión, estado y configuración', () {
    test('ws_app_version es público y expone datos de la APK/PWA', () async {
      final d = await _postOkAny('ws_app_version', {});
      expect(d['data'], isA<Map<String, dynamic>>());
      final v = _asMap(d['data']);
      for (final k in ['version', 'pwa_version']) {
        expect(v.containsKey(k), isTrue, reason: 'app_version sin $k');
      }
      expect(v.containsKey('apk_url'), isTrue);
      expect(v.containsKey('has_apk'), isTrue);
      expect(v.containsKey('changelog'), isTrue);
    });

    test('ws_mobile_me devuelve la sesión actual (token)', () async {
      final d = await _postOkAny('ws_mobile_me', {});
      final me2 = _asMap(d['data']);
      expect(me2['loggedIn'], isTrue);
      final mm = _asMap(me2['me']);
      expect((mm['userId'] as num).toInt(), ownerId);
      expect(mm['caps'], isA<Map<String, dynamic>>());
      expect(mm.containsKey('wsVersion'), isTrue);
    });

    test('ws_mobile_state expone un hash de cambios', () async {
      final d = await _postOkAny('ws_mobile_state', {});
      final s = _asMap(d['data']);
      expect(s['hash'], isA<String>());
      expect((s['hash'] as String).length, 32);
    });

    test('ws_plan_info expone estado del plan y planes', () async {
      final d = await _postOk('ws_plan_info', {});
      for (final k in ['status', 'status_label', 'is_trial', 'is_active', 'usage', 'limits', 'plans']) {
        expect(d.containsKey(k), isTrue, reason: 'plan_info sin $k');
      }
      expect(d['plans'], isA<List>());
    });

    test('settings_get → save_settings idempotente → mismos valores', () async {
      final d = await _postOk('ws_settings_get', {});
      for (final k in ['currency', 'currencies', 'rates', 'payment_methods']) {
        expect(d.containsKey(k), isTrue, reason: 'settings_get sin $k');
      }
      final rates = _asMap(d['rates']);
      final methods = d['payment_methods'] as List;
      for (final m in methods) {
        expect(m, isA<String>(), reason: 'payment_methods debe ser List<String>');
      }
      await _postOkAny('ws_save_settings', {
        'currency': d['currency'],
        'currencies': d['currencies'],
        'rates': rates,
        'payment_methods': methods,
        'whatsapp': d['whatsapp'] ?? '',
      });
      final again = await _postOk('ws_settings_get', {});
      expect(again['currency'], d['currency']);
      expect(again['currencies'], d['currencies']);
      expect((again['rates'] as Map).length, rates.length);
    });

    test('save_account idempotente (mismos datos) confirma guardado', () async {
      final d = await _postOkAny('ws_save_account',
          {'id': ownerId, 'display_name': me['name'], 'email': me['email']});
      expect(_asMap(d['data'])['msg'], isNotEmpty);
    });

    test('change_password: rutas de error nunca cambian la clave', () async {
      final base = {'id': ownerId};
      var msg = await _postErrMsg('ws_change_password',
          {...base, 'current': 'clave-incorrecta', 'new': '12345678', 'confirm': '12345678'});
      expect(msg, 'La contraseña actual no es correcta.');

      msg = await _postErrMsg('ws_change_password',
          {...base, 'current': _pass, 'new': '123', 'confirm': '123'});
      expect(msg, 'La nueva contraseña debe tener al menos 8 caracteres.');

      msg = await _postErrMsg('ws_change_password',
          {...base, 'current': _pass, 'new': '12345678', 'confirm': '87654321'});
      expect(msg, 'Las contraseñas no coinciden.');
    });

    test('permissions_get → save_permissions round-trip sin cambios', () async {
      final d = await _postOk('ws_permissions_get', {});
      for (final k in ['caps', 'matrix', 'roles']) {
        expect(d.containsKey(k), isTrue, reason: 'permissions_get sin $k');
      }
      final matrix = _asMap(d['matrix']);
      expect(matrix.containsKey('owner'), isTrue);
      final ownerCaps = _asMap(matrix['owner']);
      expect(ownerCaps.containsKey('categories_manage'), isTrue);
      // Guarda la misma matriz: no debe haber cambios (merge con existente).
      await _postOkAny('ws_save_permissions', {'matrix': matrix});
      final again = await _postOk('ws_permissions_get', {});
      expect(_asMap(again['matrix'])['owner'], ownerCaps);
    });

    test('apariencia: appearance_get → save_site_theme idempotente', () async {
      final d = await _postOk('ws_appearance_get', {});
      for (final k in ['name', 'logo', 'favicon', 'primary', 'accent']) {
        expect(d.containsKey(k), isTrue, reason: 'appearance_get sin $k');
      }
      await _postOkAny('ws_save_site_theme', {
        'primary': d['primary'],
        'accent': d['accent'],
        'name': d['name'],
        'hero_badge': d['hero_badge'] ?? '',
        'hero_title': d['hero_title'] ?? '',
        'hero_sub': d['hero_sub'] ?? '',
        'footer_text': d['footer_text'] ?? '',
      });
      final again = await _postOk('ws_appearance_get', {});
      expect(again['primary'], d['primary']);
    });

    test('notificaciones: listar, marcar todas y borrar vacío (no-op)', () async {
      final d = await _postOk('ws_notifications_list', {});
      expect(d['items'], isA<List>());
      expect(d['unread'], isA<num>());
      await _postOkAny('ws_notifications_read', {'all': '1'});
      await _postOkAny('ws_notifications_delete', {'ids': <int>[]});
    });

    test('cachés: products, customers y locations', () async {
      final p = await _postOkAny('ws_cache_products', {'location_id': locA});
      expect(_asList(_asMap(p['data'])['data']), isNotEmpty);
      final c = await _postOkAny('ws_cache_customers', {});
      expect(_asList(_asMap(c['data'])['data']), isNotEmpty);
      final l = await _postOkAny('ws_cache_locations', {});
      expect(_asList(_asMap(l['data'])['data']), isNotEmpty);
    });
  });

  group('Productos y combos (CRUD real)', () {
    test('producto: crear → listar → toggle → editar (borrado al final)', () async {
      tempProductName = 'E2E Producto $ts';
      final created = await _postOk('ws_save_product', {
        'name': tempProductName,
        'sale_price': 10,
        'cost_price': 5,
        'currency': '€',
        'active': 1,
      });
      tempProductId = (created['id'] as num).toInt();
      expect(tempProductId, greaterThan(0));

      final list = await _postOk('ws_products_list', {'search': tempProductName});
      final found = _asList(list['products']).firstWhere((p) => (p['id'] as num).toInt() == tempProductId);
      expect(found['name'], tempProductName);

      final toggled = await _postOk('ws_product_toggle', {'id': tempProductId, 'active': 0});
      expect(toggled['active'], 0);

      final upd = await _postOk('ws_save_product', {
        'id': tempProductId,
        'name': '$tempProductName 2',
        'sale_price': 12.5,
        'cost_price': 5,
        'currency': '€',
        'active': 1,
      });
      expect((upd['id'] as num).toInt(), tempProductId);

      final pget = await _postOk('ws_products_list', {'search': tempProductName});
      final row = _asList(pget['products']).firstWhere((p) => (p['id'] as num).toInt() == tempProductId);
      expect(row['name'], '$tempProductName 2');
    });

    test('producto: toggle de un id inexistente da error controlado', () async {
      final msg = await _postErrMsg('ws_product_toggle', {'id': 999999, 'active': 1});
      expect(msg, 'Producto no encontrado.');
    });

    test('combo: crear con producto temporal → listar (borrado al final)', () async {
      expect(tempProductId, isNotNull);
      final created = await _postOk('ws_combo_save', {
        'name': 'E2E Combo $ts',
        'price_mode': 'manual',
        'price': 15,
        'currency': '€',
        'active': 1,
        'items': [
          {'product_id': tempProductId, 'qty': 1},
        ],
      });
      comboId = (created['id'] as num).toInt();
      expect(comboId, greaterThan(0));

      final list = await _postOk('ws_combos_list', {'search': 'E2E Combo $ts'});
      final found = _asList(list['combos']).firstWhere((c) => (c['id'] as num).toInt() == comboId);
      expect(found['item_count'], greaterThanOrEqualTo(1));
      expect(found['items'], isA<List>());
    });
  });

  group('Stock: movimientos, transferencias y cuadre real', () {
    test('stock_list: shape normal, low_only e include_combos', () async {
      for (final opt in [<String, dynamic>{}, {'low_only': '1'}, {'include_combos': '1'}]) {
        final d = await _postOk('ws_stock_list', {...opt, 'location_id': locA});
        expect(d['rows'], isA<List>());
        expect(d['total'], isA<num>());
        expect(d.containsKey('page'), isTrue);
        expect(d.containsKey('pageSize'), isTrue);
        for (final row in _asList(d['rows'])) {
          for (final k in ['product_id', 'location_id', 'name', 'qty']) {
            expect(row.containsKey(k), isTrue, reason: 'stock row sin $k');
          }
        }
      }
    });

    test('entrada real de stock por producto', () async {
      expect(tempProductId, isNotNull);
      final moved = await _postOk('ws_stock_move',
          {'type': 'entrada', 'product_id': tempProductId, 'location_id': locA, 'qty': 5, 'reference': ref('entrada'), 'note': 'e2e'},
          token: token);
      expect(moved['qty'], 5);

      final list = await _postOk('ws_stock_list', {'location_id': locA, 'search': tempProductName});
      final row = _asList(list['rows']).firstWhere((r) => (r['product_id'] as num).toInt() == tempProductId);
      expect(_num(row['qty']), greaterThanOrEqualTo(5));

      // products_get es el catálogo del POS: SOLO productos con stock.
      // Sus filas usan 'id' como clave del producto (no 'product_id').
      final pg = await _postOkAny('ws_products_get', {'search': tempProductName});
      final prow = _asList(_asMap(pg['data'])['data'])
          .firstWhere((r) => (r['id'] as num).toInt() == tempProductId);
      expect(prow['name'], '$tempProductName 2');
    });

    test('transferencia real entre ubicaciones', () async {
      expect(tempProductId, isNotNull);
      if (!hasLocB) {
        return; // Sin segunda ubicación no hay transferencia que probar.
      }
      await _postOkAny('ws_stock_transfer',
          {'product_id': tempProductId, 'from_location': locA, 'to_location': locB, 'qty': 2, 'note': ref('transferencia')});

      final inB = await _postOk('ws_stock_list', {'location_id': locB, 'search': tempProductName});
      final rowB = _asList(inB['rows']).firstWhere((r) => (r['product_id'] as num).toInt() == tempProductId);
      expect(_num(rowB['qty']), greaterThanOrEqualTo(2));

      final inA = await _postOk('ws_stock_list', {'location_id': locA, 'search': tempProductName});
      final rowA = _asList(inA['rows']).firstWhere((r) => (r['product_id'] as num).toInt() == tempProductId);
      expect(_num(rowA['qty']), greaterThanOrEqualTo(3));
    });

    test('movement_add, batch_move y revert reales por reference', () async {
      expect(tempProductId, isNotNull);
      final addMarker = ref('manual');
      final m = await _postOk('ws_movement_add',
          {'direction': 'entrada', 'type': 'entrada', 'product_id': tempProductId, 'location_id': locA, 'qty': 1, 'reference': addMarker});
      // ws_movement_add devuelve el STOCK resultante (no el qty del movimiento).
      expect(_num(m['qty']), greaterThanOrEqualTo(1));

      final batchMarker = ref('batch');
      final b = await _postOk('ws_stock_batch_move',
          {'type': 'entrada', 'direction': 'entrada', 'location_id': locA,
           'items': [
             {'product_id': tempProductId, 'qty': 1},
           ],
           'reference': batchMarker});
      expect(b['count'], greaterThanOrEqualTo(1));

      for (final marker in [addMarker, batchMarker]) {
        final list = await _postOk('ws_movements_list', {'search': marker});
        final rows = _asList(list['movements']).where((mm) => (mm['reference'] ?? '') == marker).toList();
        expect(rows, isNotEmpty, reason: 'movimiento con $marker no aparece');
        final mid = (rows.first['id'] as num).toInt();
        final rev = await _postOk('ws_movement_revert', {'id': mid});
        expect((rev['reverted'] as num), greaterThanOrEqualTo(1));
        // Tras revertir, el movimiento sigue visible pero marcado como
        // revertido y sin posibilidad de revertir otra vez.
        final after = await _postOk('ws_movements_list', {'search': marker});
        final still = _asList(after['movements'])
            .where((mm) => (mm['id'] as num).toInt() == mid)
            .toList();
        expect(still, isNotEmpty, reason: 'movimiento $mid debió seguir visible');
        expect(still.first['reverted'], isTrue,
            reason: 'movimiento $mid debió marcarse como revertido');
        expect(still.first['revertable'], isFalse,
            reason: 'movimiento $mid ya no debe ser revertible');
      }
    });

    test('movement_revert: id inexistente → error controlado', () async {
      final msg = await _postErrMsg('ws_movement_revert', {'id': 999999});
      expect(msg, 'Movimiento no encontrado.');
    });

    test('stock_count_virtual + stock_count_save real (cuadre + limpieza)', () async {
      expect(tempProductId, isNotNull);
      final virt = await _postOkAny('ws_stock_count_virtual', {'location_id': locA});
      expect(_asList(_asMap(virt['data'])['data']), isNotEmpty);

      final saved = await _postOk('ws_stock_count_save',
          {'location_id': locA, 'note': ref('cuadre'), 'items': [
             {'product_id': tempProductId, 'physical': 4},
           ]});
      final countId = (saved['id'] as num).toInt();
      expect(countId, greaterThan(0));
      expect(saved.containsKey('summary'), isTrue,
          reason: 'el cuadre debe exponer su resumen');
      try {
        final list = await _postOkAny('ws_stock_counts_list', {'location_id': locA, 'limit': 500});
        final rows = _asList(_asMap(list['data'])['data']);
        expect(rows.any((r) => (r['id'] as num).toInt() == countId), isTrue);
      } finally {
        await _phpCli(['ws-e2e-helper.php', 'cleanup-count', '$countId']);
      }
    });

    test('stock_count_save: sin items → error controlado', () async {
      final msg = await _postErrMsg('ws_stock_count_save', {'location_id': locA, 'items': <dynamic>[]});
      expect(msg, 'No hay productos para cuadrar.');
    });

    test('stock_catalog_pdf devuelve binario PDF y rechaza ubicación inválida', () async {
      // La generación del PDF tarda ~12s en frío y más bajo carga de la matriz.
      const pdfTimeout = Duration(seconds: 120);
      final ok = await _http('ws_stock_catalog_pdf', {'location_id': locA},
          token: token, raw: true, timeout: pdfTimeout);
      expect(ok.statusCode, 200);
      expect(ok.headers['content-type'], contains('application/pdf'));
      expect(ok.bodyBytes.length, greaterThan(100), reason: 'catálogo demasiado corto');
      expect(ok.bodyBytes.take(1).first, isNot('{'.codeUnitAt(0)), reason: 'respuesta JSON en vez de PDF');

      final bad = await _http('ws_stock_catalog_pdf', {'location_id': 999999}, token: token, raw: true);
      final jsonBad = _tryJson(bad.bodyBytes);
      if (jsonBad != null) {
        expect(jsonBad['success'], isFalse);
        final data = _asMap(jsonBad['data']);
        expect((data['msg'] as String), isNotEmpty);
      } else {
        expect(bad.statusCode, 500, reason: 'ubicación inválida dio PDF: ${bad.bodyBytes}');
      }
    });
  });

  group('Pedidos (flujo real + errores)', () {
    test('order_list mantiene shape paginado', () async {
      final d = await _postOk('ws_order_list',
          {'date_from': '2000-01-01', 'date_to': '2099-12-31'});
      expect(d['orders'], isA<List>());
      expect(d['total'], isA<num>());
      expect(d.containsKey('page'), isTrue);
      expect(d.containsKey('pageSize'), isTrue);
    });

    test('crear → detalle → aceptar → completar (con limpieza)', () async {
      expect(tempProductId, isNotNull);
      final created = await _postOkAny('ws_create_order', {
        'location_id': locA,
        'items': [
          {'product_id': tempProductId, 'qty': 1},
        ],
        'customer_name': 'E2E Cliente $ts',
        'customer_phone': '+34111111111',
        'customer_address': 'Calle E2E 1',
      });
      final id = (_asMap(created['data'])['id'] as num).toInt();
      expect(id, greaterThan(0));
      int? orderId = id;
      try {
        final detail = await _postOk('ws_order_detail', {'id': id});
        final order = _asMap(detail['order']);
        expect(int.parse('${order['id']}'), id);
        expect(order['items'], isA<List>());
        expect((order['items'] as List), isNotEmpty);

        await _postOkAny('ws_order_accept', {'id': id});
        await _postOkAny('ws_order_complete', {'id': id});
      } finally {
        await _phpCli([r'ws-e2e-helper.php', 'cleanup-order', '$id']);
        orderId = null;
      }
      expect(orderId, isNull);
    });

    test('crear → rechazar (con limpieza)', () async {
      expect(tempProductId, isNotNull);
      final created = await _postOkAny('ws_create_order', {
        'location_id': locA,
        'items': [
          {'product_id': tempProductId, 'qty': 1},
        ],
        'customer_name': 'E2E Rechazo $ts',
        'customer_phone': '+34222222222',
      });
      final id = (_asMap(created['data'])['id'] as num).toInt();
      expect(id, greaterThan(0));
      try {
        await _postOkAny('ws_order_reject', {'id': id});
      } finally {
        await _phpCli([r'ws-e2e-helper.php', 'cleanup-order', '$id']);
      }
    });

    test('pedidos: validaciones controladas', () async {
      final noName = await _postErrMsg('ws_create_order', {
        'location_id': locA,
        'items': [
          {'product_id': 1, 'qty': 1},
        ],
      });
      expect(noName, 'Nombre y teléfono son obligatorios.');

      final empty = await _postErrMsg('ws_create_order', {
        'location_id': locA,
        'items': <dynamic>[],
        'customer_name': 'X',
        'customer_phone': '+34333333333',
      });
      expect(empty, 'El pedido está vacío.');

      final noDetail = await _postErrMsg('ws_order_detail', {'id': 999999});
      expect(noDetail, 'Pedido no encontrado.');
    });
  });

  group('Clientes, gastos y fidelización', () {
    test('customers_get expone clientes con shape', () async {
      final r = await _postOkAny('ws_customers_get', {});
      final payload = _asMap(r['data']);
      final rows = _asList(payload['data']);
      expect(payload['total'], isA<num>());
      for (final c in rows) {
        for (final k in ['id', 'name']) {
          expect(c.containsKey(k), isTrue, reason: 'cliente sin $k');
        }
      }
    });

    test('cliente: crear → buscar → borrar; nombre obligatorio', () async {
      final name = 'E2E Cliente CRM $ts';
      final saved = await _postOk('ws_customers_save', {'name': name, 'phone': '+34444444444', 'doc': 'E2E-DOC-$ts'});
      final id = (saved['id'] as num).toInt();
      expect(id, greaterThan(0));
      try {
        final list = await _postOkAny('ws_customers_get', {'search': name});
        final matches = _asList(_asMap(list['data'])['data']).where((c) => (c['id'] as num).toInt() == id).toList();
        expect(matches, isNotEmpty);
        await _postOkAny('ws_customers_delete', {'id': id});
        final after = await _postOkAny('ws_customers_get', {'search': name});
        expect(_asList(_asMap(after['data'])['data']).where((c) => (c['id'] as num).toInt() == id), isEmpty);
      } finally {
        await _postOkAny('ws_customers_delete', {'id': id});
      }
      final msg = await _postErrMsg('ws_customers_save', {});
      expect(msg, 'El nombre es obligatorio.');
    });

    test('gastos: lista → crear → borrar (crud real)', () async {
      final now = DateTime.now();
      final list = await _postOk('ws_expenses_list',
          {'year': now.year, 'month': now.month});
      expect(list['expenses'], isA<List>());
      expect(list['summary'], isA<Map<String, dynamic>>());

      final created = await _postOk('ws_expense_save',
          {'concept': 'E2E Gasto $ts', 'amount': 9.99, 'category': 'Test', 'note': 'e2e'});
      final id = (created['id'] as num).toInt();
      expect(id, greaterThan(0));
      try {
        final list2 = await _postOk('ws_expenses_list',
            {'year': now.year, 'month': now.month});
        expect(_asList(list2['expenses']).any((e) => (e['id'] as num).toInt() == id), isTrue);
        final msg = await _postErrMsg('ws_expense_save', {});
        expect(msg, 'El concepto del gasto es obligatorio.');
      } finally {
        await _postOkAny('ws_expense_delete', {'id': id});
      }
      final after = await _postOk('ws_expenses_list', {'year': now.year, 'month': now.month});
      expect(_asList(after['expenses']).any((e) => (e['id'] as num).toInt() == id), isFalse);
    });

    test('fidelización: rama según cap de negocio', () async {
      final loyaltyOn = me['caps'] is Map<String, dynamic>
          ? ((me['caps'] as Map)['loyalty_manage'] ?? false) as bool
          : false;
      if (loyaltyOn) {
        final cust = await _postOkAny('ws_loyalty_customers', {});
        expect(cust['data'], isA<List>());
        expect(cust['total'], isA<num>());
        final stats = await _postOkAny('ws_loyalty_stats', {});
        expect(stats['data'], isA<Map<String, dynamic>>());
        final msg = await _postErrMsg('ws_loyalty_adjust_points', {});
        expect(msg, 'Datos incompletos.');
      } else {
        for (final a in ['ws_loyalty_customers', 'ws_loyalty_stats']) {
          final r = await _postRaw(a, {});
          expect(r['success'], isFalse, reason: '$a sin cap loyalty_manage debió rechazarse');
        }
      }
    });
  });

  group('POS y caja (flujo real)', () {
    test('pos_sales_get shape y pos_sale_items_get con id inválido', () async {
      final r = await _postOkAny('ws_pos_sales_get', {'location_id': locA});
      expect(_asList(_asMap(r['data'])['data']), isNotEmpty);
      expect(_asMap(r['data'])['total'], isA<num>());

      final msg = await _postErrMsg('ws_pos_sale_items_get', {'sale_id': 0});
      expect(msg, 'ID inválido.');
    });

    test('pos_stats responde con stats', () async {
      final r = await _postOkAny('ws_pos_stats', {});
      expect(r['data'], isA<Map<String, dynamic>>());
    });

    test('caja: status → abrir → status → cerrar (cuadre físico)', () async {
      expect(tempProductId, isNotNull);
      // Si una ejecución anterior dejó la caja abierta, la reiniciamos.
      var st = await _postOk('ws_pos_cash_status', {'location_id': locA});
      var cash = st['cash'];
      if (cash is Map && cash['id'] is num) {
        final openId = (cash['id'] as num).toInt();
        await _postOkAny('ws_pos_cash_close',
            {'location_id': locA, 'closing_amount': 0, 'note': 'e2e reset'});
        cash = null;
        expect(openId, greaterThan(0));
      }

      final opened = await _postOk('ws_pos_cash_open',
          {'location_id': locA, 'opening_amount': 100, 'note': ref('caja')});
      final registerId = (opened['id'] as num).toInt();
      expect(registerId, greaterThan(0));
      expect(opened['opening_amount'], 100);

      final afterOpen = await _postOk('ws_pos_cash_status', {'location_id': locA});
      expect(afterOpen['open'], isTrue);
      expect(_asMap(afterOpen['cash'])['id'], registerId);

      final closed = await _postOk('ws_pos_cash_close', {
        'location_id': locA,
        'closing_amount': 100,
        'note': ref('cierre'),
        'cuadre': {'$tempProductId': 4},
      });
      final cdata = closed; // _postOk ya deshizo el doble wrap data.data
      expect(cdata['id'], registerId);
      expect(cdata.containsKey('expected'), isTrue);
      expect(_asMap(cdata['cuadre'])['count'], greaterThanOrEqualTo(1));
      closedRegisterId = registerId;

      final afterClose = await _postOk('ws_pos_cash_status', {'location_id': locA});
      expect(afterClose['open'], isFalse);
    });

    test('historial de caja y cuadre del cierre reciente', () async {
      expect(closedRegisterId, isNotNull);
      final hist = await _postOkAny('ws_pos_cash_history', {'location_id': locA});
      final rows = _asList(_asMap(hist['data'])['data']);
      final mine = rows.where((r) => (r['id'] as num).toInt() == closedRegisterId).toList();
      expect(mine, isNotEmpty, reason: 'el cierre E2E no aparece en el historial');
      expect(mine.first['status'], 'closed');

      final counts = await _postOk('ws_pos_cash_counts_get', {'register_id': closedRegisterId});
      expect(counts['register_id'], closedRegisterId);
      final items = counts['items'];
      expect(items, isA<List<Object?>>());
      expect(items, isNotEmpty, reason: 'cuadre sin ítems');
      final summary = counts['summary'];
      expect(summary, isA<Map<String, dynamic>>());
      expect((summary as Map)['count'], greaterThanOrEqualTo(1));
    });

    test('pos_sale_save sin caja abierta → error controlado', () async {
      // Un run anterior puede dejar la caja abierta: la cerramos para aislar
      // el caso (independiente del orden de los tests de caja).
      final st = await _postOk('ws_pos_cash_status', {'location_id': locA});
      if (st['open'] == true || st['open'] == 1) {
        await _postOkAny('ws_pos_cash_close',
            {'location_id': locA, 'closing_amount': 0, 'note': 'e2e pre-sale'});
      }
      final r = await _postRaw('ws_pos_sale_save', {
        'location_id': locA,
        'seller_id': ownerId,
        'items': <dynamic>[],
        'subtotal': 0,
        'discount': 0,
        'total': 0,
        'payment_method': 'cash',
      });
      expect(r['success'], isFalse, reason: 'venta sin caja no debe completarse');
      final data = _asMap(r['data']);
      expect((data['msg'] as String), isNotEmpty);
    });
  });

  group('Reviews', () {
    test('reviews_get (panel) y reviews_stats responden', () async {
      final r = await _postOkAny('ws_reviews_get', {});
      if (r['data'] is List) {
        for (final rev in _asList(r['data'])) {
          expect(rev.containsKey('id'), isTrue);
        }
      } else if (r['data'] is Map) {
        final d = _asMap(r['data']);
        expect(d.containsKey('data'), isTrue);
      }
      final stats = await _postOkAny('ws_reviews_stats', {});
      expect(stats['data'], isA<Map<String, dynamic>>());
    });

    test('reviews_delete con id inválido → error controlado', () async {
      final msg = await _postErrMsg('ws_reviews_delete', {'id': 0});
      expect(msg, 'ID inválido.');
    });
  });

  group('Trabajadores', () {
    test('workers_list shape y update_worker round-trip del nombre', () async {
      final d = await _postOk('ws_workers_list', {'search': 'e2e'});
      expect(d['workers'], isA<List>());
      final orig = _asList(d['workers'])
          .firstWhere((w) => '${w['user_email']}'.contains('e2e.seller'));
      final sellerId = (orig['id'] as num).toInt();
      final origName = orig['display_name'] as String;
      await _postOkAny('ws_update_worker',
          {'user_id': sellerId, 'role': 'ws_seller', 'display_name': '$origName 2'});
      try {
        final again = await _postOk('ws_workers_list', {'search': 'e2e'});
        final updated = _asList(again['workers'])
            .firstWhere((w) => (w['id'] as num).toInt() == sellerId);
        expect(updated['display_name'], '$origName 2');
      } finally {
        await _postOkAny('ws_update_worker',
            {'user_id': sellerId, 'role': 'ws_seller', 'display_name': origName});
      }
    });

    test('worker_set_disabled on/off del vendedor y session_close', () async {
      final d = await _postOk('ws_workers_list', {'search': 'e2e'});
      final seller = _asList(d['workers'])
          .firstWhere((w) => '${w['user_email']}'.contains('e2e.seller'));
      final sellerId = (seller['id'] as num).toInt();
      // La app envía 0/1 (el body NO convierte booleanos); PHP evalúa
        // !empty('0') = false y !empty('1') = true.
        await _postOkAny('ws_worker_set_disabled', {'user_id': sellerId, 'disabled': 1});
        try {
          final again = await _postOk('ws_workers_list', {'search': 'e2e'});
          final disabled = _asList(again['workers'])
              .firstWhere((w) => (w['id'] as num).toInt() == sellerId);
          final isDisabled = disabled['is_disabled'] == true || disabled['is_disabled'] == 1;
          expect(isDisabled, isTrue);
        } finally {
          await _postOkAny('ws_worker_set_disabled', {'user_id': sellerId, 'disabled': 0});
        }
      final after = await _postOk('ws_workers_list', {'search': 'e2e'});
      final enabled = _asList(after['workers'])
          .firstWhere((w) => (w['id'] as num).toInt() == sellerId);
      final isEnabled = enabled['is_disabled'] == false || enabled['is_disabled'] == 0;
      expect(isEnabled, isTrue);

      var msg = await _postErrMsg('ws_session_close', {'session_id': 0});
      expect(msg, 'Sesión inválida.');
      msg = await _postErrMsg('ws_session_close', {'session_id': 999999});
      expect(msg, 'Sesión no encontrada.');
    });

    test('delete_worker de la propia cuenta → error controlado', () async {
      final msg = await _postErrMsg('ws_delete_worker', {'user_id': ownerId});
      expect(msg, 'No puedes eliminar tu propia cuenta.');
    });

    test('crear trabajador nuevo → listar → borrar (ciclo completo)', () async {
      final uname = 'e2e$ts';
      final created = await _postOk('ws_save_worker_user', {
        'username': uname,
        'email': '$uname@example.com',
        'password': 'E2eNew!2026',
        'role': 'ws_seller',
        'display_name': 'E2E Trabajador $ts',
        'locations': [locA],
      });
      final newId = (created['id'] as num).toInt();
      expect(newId, greaterThan(0));
      final d = await _postOk('ws_workers_list', {'search': uname});
      final found = _asList(d['workers']).where((w) => (w['id'] as num).toInt() == newId).toList();
      expect(found, isNotEmpty);
      await _postOkAny('ws_delete_worker', {'user_id': newId});
      final after = await _postOk('ws_workers_list', {'search': uname});
      expect(_asList(after['workers']).where((w) => (w['id'] as num).toInt() == newId), isEmpty);
    });
  });

  group('Anuncios', () {
    test('announcements_list shape', () async {
      final d = await _postOk('ws_announcements_list', {});
      expect(d['list'], isA<List>());
    });

    test('anuncio: crear → toggle → borrar; título obligatorio', () async {
      final title = 'E2E Anuncio $ts';
      final created = await _postOk('ws_announcement_save',
          {'title': title, 'message': 'mensaje e2e', 'type': 'info', 'scope': 'business'});
      expect(created['msg'], isNotEmpty);
      final list1 = _asList(created['list']);
      final ann = list1.firstWhere((a) => (a['title'] as String) == title);
      final id = (ann['id'] as num).toInt();
      await _postOk('ws_announcement_toggle', {'id': id, 'active': 0});
      final list2 = _asList((await _postOk('ws_announcements_list', {}))['list']);
      expect(list2.firstWhere((a) => (a['id'] as num).toInt() == id)['active'], 0);
      final del = await _postOk('ws_announcement_delete', {'id': id});
      final list3 = _asList(del['list']);
      expect(list3.where((a) => (a['id'] as num).toInt() == id), isEmpty);
      final msg = await _postErrMsg('ws_announcement_save', {});
      expect(msg, 'El título es obligatorio.');
    });
  });

  group('Cierre de sesión (último)', () {
    test('ws_mobile_logout invalida el token y permite volver a entrar', () async {
      final loggedOut = await _postOkAny('ws_mobile_logout', {});
      expect(_asMap(loggedOut['data'])['loginUrl'], isNotEmpty);

      final meOld = await _postOkAny('ws_mobile_me', {}, token: token);
      expect(_asMap(meOld['data'])['loggedIn'], isFalse,
          reason: 'el token viejo no debe seguir válido');

      final relogin = await _postRaw('ws_mobile_login', {'ws_user': _user, 'ws_pass': _pass});
      expect(relogin['success'], isTrue, reason: 're-login tras logout falló: ${relogin['data']}');
      final newToken = (_asMap(relogin['data'])['token'] as String);
      _sessionToken = newToken;
      final meNew = await _postOkAny('ws_mobile_me', {}, token: newToken);
      expect(_asMap(meNew['data'])['loggedIn'], isTrue);
    });
  });

  group('Limpieza final de datos E2E', () {
    test('borrar combo y producto temporales; verificar ausencia', () async {
      if (comboId != null) {
        await _postOkAny('ws_combo_delete', {'id': comboId});
        final combos = await _postOk('ws_combos_list', {'search': 'E2E Combo $ts'});
        expect(_asList(combos['combos']).where((c) => (c['id'] as num).toInt() == comboId), isEmpty);
      }
      if (tempProductId != null) {
        // El borrado AJAX bloquea productos con stock/movimientos/historial:
        // limpio por SQL (solo productos creados por los tests e2e).
        await _phpCli([r'ws-e2e-helper.php', 'cleanup-product', '$tempProductId']);
        final ps = await _postOk('ws_products_list', {'search': tempProductName});
        expect(_asList(ps['products']).where((p) => (p['id'] as num).toInt() == tempProductId), isEmpty);
        final ss = await _postOk('ws_stock_list', {'search': tempProductName});
        expect(_asList(ss['rows']).where((r) => (r['product_id'] as num).toInt() == tempProductId), isEmpty,
            reason: 'tras borrar el producto no deben quedar filas de stock');
      }
    });
  });
}