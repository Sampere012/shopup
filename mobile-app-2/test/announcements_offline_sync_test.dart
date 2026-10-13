/// Flujo offline de Anuncios contra el backend local:
///  1. encolar un "crear" con la red caída y comprobar que el FLUSH lo sube;
///  2. editar/toggle/eliminar una fila LOCAL (aún no subida): las ops se
///     coalescen en la propia creación encolada, así el servidor recibe UNA
///     sola operación válida y la cola se vacía.
///
/// Requisitos: Apache + MySQL arriba y el tema workshop activo en
/// http://localhost/workshop, con el usuario E2E sembrado (ws-test-seed.php).
@Timeout(Duration(minutes: 3))
library;

import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:shopup_panel/services/api_service.dart';
import 'package:shopup_panel/services/db_service.dart';
import 'package:shopup_panel/services/sync_service.dart';

String get _base =>
    (Platform.environment['WS_E2E_BASE'] ?? 'http://localhost/workshop')
        .replaceAll(RegExp(r'/+$'), '');
String get _user => Platform.environment['WS_E2E_USER'] ?? 'ws_e2e_app';
String get _pass => Platform.environment['WS_E2E_PASS'] ?? 'E2eApp!2026';

Uri get _endpoint => Uri.parse('$_base/wp-admin/admin-ajax.php');

Map<String, String> _body(String action, Map<String, dynamic> data) {
  final b = <String, String>{'action': action};
  data.forEach((k, v) {
    if (v == null) return;
    b[k] = (v is List || v is Map) ? jsonEncode(v) : '$v';
  });
  return b;
}

Future<Map<String, dynamic>> _req(
  String action,
  Map<String, dynamic> data, {
  required String token,
}) async {
  final res = await http
      .post(_endpoint,
          headers: {
            'X-WS-Token': token,
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: _body(action, data))
      .timeout(const Duration(seconds: 30));
  return jsonDecode(utf8.decode(res.bodyBytes, allowMalformed: true))
      as Map<String, dynamic>;
}

Future<String> _login() async {
  final r = await _req('ws_mobile_login', {
    'ws_user': _user,
    'ws_pass': _pass,
  }, token: '');
  expect(r['success'], isTrue,
      reason: 'login falló: ${r['data']} — ¿XAMPP + usuario E2E activos?');
  return (r['data'] as Map)['token'] as String;
}

Future<List<dynamic>> _serverList(String token) async {
  final r = await _req('ws_announcements_list', {}, token: token);
  return ((r['data'] as Map)['list'] as List?) ?? const [];
}

Future<void> _deleteServerRows(
    String token, Iterable<Map<String, dynamic>> rows) async {
  for (final row in rows) {
    await _req('ws_announcement_delete', {'id': row['id']}, token: token);
  }
}

/// Mismo payload que arma AnnouncementsScreen._edit() para una fila local.
Map<String, dynamic> _localPayload(Map<String, dynamic> a, String title,
        {String active = '1'}) =>
    {
      'id': 0, // así lo calcula num.tryParse('local-…') ?? 0
      'title': title,
      'message': '',
      'type': 'info',
      'pinned': '${a['pinned'] ?? '0'}',
      'pinned_days': '0',
      'dismissible': '1',
      'active': active,
    };

/// Fila local como la deja el onQueued de la pantalla.
Map<String, dynamic> _localRow(String localId, String title) => {
      'id': localId,
      'title': title,
      'message': '',
      'type': 'info',
      'pinned': '0',
      'dismissible': '1',
      'active': '1',
      'created_at': DateTime.now().toIso8601String(),
    };

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late String token;

  setUpAll(() async {
    // flutter_test instala un HttpClient mock que responde 400 sin red:
    // aquí se necesita el HTTP real contra el WordPress local.
    HttpOverrides.global = null;
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
    token = await _login();
    SharedPreferences.setMockInitialValues({
      'wsm_token': token,
      'wsm_server': _base,
    });
    await ApiService.I.load();
  });

  setUp(() async {
    await DbService.I.wipe();
    final d = await DbService.I.db;
    await d.delete('pending');
    SyncService.I.setOnline(false);
  });

  test('crear offline → flush sube el anuncio al servidor', () async {
    final title = 'Offline create ${DateTime.now().microsecondsSinceEpoch}';
    final res = await SyncService.I
        .push('ws_announcement_save', _localPayload(_localRow('x', title), title));
    expect((res as Map)['queued'], isTrue,
        reason: 'sin conexión el push debe encolar');
    expect(await DbService.I.pendingCount(), 1);

    final flushed = await SyncService.I.flush();
    expect(flushed['sent'], 1,
        reason: 'el flush debe subir el anuncio encolado: $flushed');
    expect(flushed['remaining'], 0);

    final list = await _serverList(token);
    final found = list.where((a) => '${a['title']}' == title).toList();
    expect(found, isNotEmpty,
        reason: 'el servidor debe tener el anuncio creado offline');
    await _deleteServerRows(token, found.cast<Map<String, dynamic>>());
  });

  test('editar una fila local reescribe la MISMA op (sin duplicar)', () async {
    final localId = 'local-${DateTime.now().microsecondsSinceEpoch}';
    final row = _localRow(localId, 'Original offline');
    await DbService.I.replaceAll('announcements', [row]);
    await SyncService.I.push(
        'ws_announcement_save', _localPayload(row, row['title'] as String),
        localId: localId);

    // El usuario corrige el título ANTES de que se suba.
    final edited = _localPayload(row, 'Editado offline');
    final updated = await SyncService.I.updateQueuedAnnouncementSave(
        localId, edited,
        title: row['title'] as String);
    expect(updated, isTrue, reason: 'debe encontrar la creación encolada');
    expect(await DbService.I.pendingCount(), 1,
        reason: 'la edición se fusiona en la op existente (una sola)');

    final flushed = await SyncService.I.flush();
    expect(flushed['remaining'], 0, reason: 'la cola debe vaciarse: $flushed');

    final list = await _serverList(token);
    final created =
        list.where((a) => '${a['title']}'.contains('offline')).toList();
    expect(created.length, 1,
        reason: 'el servidor debe recibir UN anuncio, no uno por edición: '
            '${created.map((a) => a['title']).toList()}');
    expect(created.first['title'], 'Editado offline');
    await _deleteServerRows(token, created.cast<Map<String, dynamic>>());
  });

  test('toggle de una fila local se fusiona en su creación encolada',
      () async {
    final localId = 'local-${DateTime.now().microsecondsSinceEpoch}';
    final row = _localRow(localId, 'Local toggle');
    await DbService.I.replaceAll('announcements', [row]);
    await SyncService.I.push(
        'ws_announcement_save', _localPayload(row, row['title'] as String),
        localId: localId);

    // Mismo camino que AnnouncementsScreen._toggle() para filas locales.
    final ok = await SyncService.I.updateQueuedAnnouncementField(
        localId, {'active': '0'},
        title: row['title'] as String);
    expect(ok, isTrue);
    expect(await DbService.I.pendingCount(), 1,
        reason: 'NO debe encolarse un toggle con id local');

    final flushed = await SyncService.I.flush();
    expect(flushed['sent'], 1, reason: 'debe subirse la creación: $flushed');
    expect(flushed['remaining'], 0,
        reason: 'la cola debe quedar vacía (antes quedaba pegada para siempre)');

    final list = await _serverList(token);
    final created = list
        .where((a) => '${a['title']}' == 'Local toggle')
        .toList();
    expect(created, isNotEmpty);
    expect('${created.first['active']}', '0',
        reason: 'el toggle debe llegar como parte de la creación');
    await _deleteServerRows(token, created.cast<Map<String, dynamic>>());
  });

  test('borrar una fila local descarta su creación encolada', () async {
    final localId = 'local-${DateTime.now().microsecondsSinceEpoch}';
    final row = _localRow(localId, 'Local delete');
    await DbService.I.replaceAll('announcements', [row]);
    await SyncService.I.push(
        'ws_announcement_save', _localPayload(row, row['title'] as String),
        localId: localId);
    expect(await DbService.I.pendingCount(), 1);

    final removed = await SyncService.I.discardQueuedAnnouncementOps(
        localId,
        title: row['title'] as String);
    expect(removed, 1);
    expect(await DbService.I.pendingCount(), 0,
        reason: 'la creación nunca llegó al servidor: se descarta de la cola');

    final list = await _serverList(token);
    expect(list.where((a) => '${a['title']}' == 'Local delete'), isEmpty);
  });

  test('pull conserva las creaciones locales aún no subidas', () async {
    final localId = 'local-${DateTime.now().microsecondsSinceEpoch}';
    final row = _localRow(localId, 'Pendiente de subir');
    await SyncService.I.push(
        'ws_announcement_save', _localPayload(row, row['title'] as String),
        localId: localId);

    // Mismo merge que hace SyncService.pull() tras bajar la lista.
    final serverRows = List<Map<String, dynamic>>.from(
        (await _serverList(token)).cast<Map<String, dynamic>>());
    final merged = await SyncService.I.mergeAnnouncementRows(serverRows);
    await DbService.I.replaceAll('announcements', merged);

    final local = await DbService.I.all('announcements');
    expect(
        local.any((r) => '${r['id']}' == localId),
        isTrue,
        reason: 'el replaceAll del pull no debe borrar lo que aún no se subió');
  });

  test('pull aplica encima las ediciones pendientes de filas del servidor',
      () async {
    // Fila real del servidor (la que ya existe en el seed).
    final list = await _serverList(token);
    final serverRow = list.cast<Map<String, dynamic>>().firstWhere(
        (r) => '${r['id']}' == '42',
        orElse: () => list.isNotEmpty
            ? Map<String, dynamic>.from(list.first as Map)
            : <String, dynamic>{});
    expect(serverRow, isNotEmpty, reason: 'hace falta un anuncio en el server');
    final serverTitle = '${serverRow['title']}';

    // El usuario lo edita offline: se encola una op con su id real.
    final edited = {
      ..._localPayload(serverRow, 'Titulo editado offline'),
      'id': serverRow['id'],
      'message': '${serverRow['message'] ?? ''}',
    };
    await SyncService.I.push('ws_announcement_save', edited);

    final merged = await SyncService.I
        .mergeAnnouncementRows([serverRow, ...[]]);
    final target = merged
        .firstWhere((r) => '${r['id']}' == '${serverRow['id']}');
    expect(target['title'], 'Titulo editado offline',
        reason: 'la edición en cola debe aplicarse encima de la fila server');
    expect('${target['queued']}', 'true');

    final flushed = await SyncService.I.flush();
    expect(flushed['remaining'], 0, reason: 'la cola debe vaciarse: $flushed');

    // Restaurar el anuncio original para no ensuciar el entorno.
    await _req(
        'ws_announcement_save',
        {
          ...edited,
          'title': serverTitle,
          'message': '${serverRow['message'] ?? ''}',
        },
        token: token);
  });
}
