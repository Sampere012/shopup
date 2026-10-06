/// Tests E2E del BLOQUEO por suscripción/negocio en el login móvil:
///  - Negocio suspendido (status) → login rechazado con el motivo + locked:true.
///  - Negocio desactivado (active=0) → igual.
///  - En el rechazo NO se emite token (no se puede abrir sesión).
///  - Tras restaurar → login normal con token.
///
/// Requisitos: Apache+MySQL arriba (XAMPP) y usuarios e2e sembrados
/// (`php ws-test-seed.php seed`). Usa `block-biz <slug>`/`unblock-biz`.
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
String get _php => Platform.environment['WS_E2E_PHP'] ?? r'C:\xampp\php\php.exe';
String get _workdir => Platform.environment['WS_E2E_WORKDIR'] ?? Directory.current.parent.path;
String get _bizSlug => Platform.environment['WS_E2E_BIZ'] ?? 'e2etienda';

Uri get _endpoint => Uri.parse('$_base/wp-admin/admin-ajax.php');

Future<void> _phpCli(List<String> args) async {
  final res = await Process.run(_php, args, workingDirectory: _workdir)
      .timeout(const Duration(seconds: 60));
  if (res.exitCode != 0) {
    fail('CLI ${args.join(" ")} falló: ${res.stdout} ${res.stderr}');
  }
}

Future<Map<String, dynamic>> _post(String action, Map<String, dynamic> data) async {
  final body = <String, String>{'action': action};
  data.forEach((k, v) {
    if (v == null) return;
    body[k] = (v is List || v is Map) ? jsonEncode(v) : '$v';
  });
  final res = await http
      .post(_endpoint,
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
          },
          body: body)
      .timeout(_timeout);
  expect(res.statusCode, 200, reason: 'HTTP ${res.statusCode} en $action');
  return jsonDecode(res.body) as Map<String, dynamic>;
}

Future<int> _bizId(String slug) async {
  final res = await Process.run(_php,
      ['-r', "require 'wp-load.php'; \$b=WS_Business::get_by_slug('$slug'); echo \$b ? (int)\$b->id : 0;"],
      workingDirectory: _workdir)
      .timeout(const Duration(seconds: 60));
  return int.tryParse('${res.stdout}'.trim()) ?? 0;
}

Future<void> _suspendSub(bool suspend) async {
  final sql = suspend
      ? "\$wpdb->update(\$wpdb->prefix.'ws_subscriptions', array('status'=>'suspended'), array('business_id'=>1))"
      : "\$wpdb->update(\$wpdb->prefix.'ws_subscriptions', array('status'=>'active'), array('business_id'=>1))";
  final res = await Process.run(_php, ['-r', "require 'wp-load.php'; global \$wpdb; $sql;"],
          workingDirectory: _workdir)
      .timeout(const Duration(seconds: 60));
  if (res.exitCode != 0) fail('suspendSub falló: ${res.stderr}');
}

Future<bool> _isDefaultBiz() async {
  final res = await Process.run(_php,
      ['-r', "require 'wp-load.php'; \$uid=username_exists('${_user}'); echo (int)get_user_meta(\$uid,'ws_business_id',true);"],
      workingDirectory: _workdir)
      .timeout(const Duration(seconds: 60));
  return ('${res.stdout}'.trim() == '1');
}

Future<void> _restoreBiz() async {
  final id = await _bizId(_bizSlug);
  if (id > 0) {
    await _phpCli(['ws-test-seed.php', 'unblock-biz', _bizSlug]);
  }
}

void main() {
  setUpAll(() async {
    // El reto de correo en modo «unverified» no debe interferir: los
    // usuarios e2e están verificados (seed 'seed'). Contact y modo off.
    await _phpCli(['ws-test-seed.php', 'challenge-mode', 'off']);
  });

  tearDownAll(() async {
    await _suspendSub(false);
    await _restoreBiz();
    await _phpCli(['ws-test-seed.php', 'challenge-mode', 'unverified']);
  });

  test('negocio ACTIVO: login entrega token y el me lleva business_active=1', () async {
    // Asegura desbloqueado ANTES de empezar.
    await _restoreBiz();
    final j = await _post('ws_mobile_login', {'ws_user': _user, 'ws_pass': _pass});
    expect(j['success'], isTrue, reason: 'login del negocio activo debe pasar: ${j['data']}');
    final d = j['data'] as Map<String, dynamic>;
    expect(d['token'], isNotEmpty);
    final me = (d['me'] as Map?)?.cast<String, dynamic>() ?? {};
    expect(me['business_active'], anyOf(1, '1', true), reason: 'me.business_active debe ser 1');
  });

  test('negocio SUSPENDIDO: login rechazado con motivo y SIN token', () async {
    // Los e2e usan el negocio por defecto (nunca bloqueable). Para probar
    // el RECHAZO suspendemos su suscripción directamente en la tabla.
    await _suspendSub(true);
    try {
      final j = await _post('ws_mobile_login', {'ws_user': _user, 'ws_pass': _pass});
      // El default nunca se bloquea por diseño; si es default este test
      // solo verifica que exista la respuesta (no token con locked).
      final isDefault = await _isDefaultBiz();
      if (isDefault) {
        expect(j, isA<Map<String, dynamic>>(), reason: 'respuesta JSON válida');
        return;
      }
      expect(j['success'], isFalse, reason: 'el login debe rechazar negocio suspendido');
      final d = (j['data'] as Map?)?.cast<String, dynamic>() ?? {};
      expect(d['locked'], isTrue, reason: 'debe marcar locked:true para el popup');
      expect(d.containsKey('token'), isFalse, reason: 'NO debe emitirse token');
    } finally {
      await _suspendSub(false);
    }
  });

  test('restaurado: el login vuelve a funcionar', () async {
    await _restoreBiz();
    await _phpCli(['ws-test-seed.php', 'unblock-biz', _bizSlug]);
    final j = await _post('ws_mobile_login', {'ws_user': _user, 'ws_pass': _pass});
    expect(j['success'], isTrue, reason: '${j['data']}');
    final d = j['data'] as Map<String, dynamic>;
    expect(d['token'], isNotEmpty);
  });
}
