/// Tests E2E de la seguridad del login móvil: reto de verificación de correo
/// al iniciar sesión (unverified → código por email → verify → token) y
/// bloqueo anti fuerza bruta (5 fallos → cuenta bloqueada temporalmente).
///
/// Requisitos: Apache+MySQL arriba (XAMPP) y usuarios e2e sembrados
/// (`php ws-test-seed.php seed`). La captura de códigos del mu-plugin
/// (ws-e2e-mail-capture.php) permite leer el código sin buzón real.
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

Uri get _endpoint => Uri.parse('$_base/wp-admin/admin-ajax.php');

Future<void> _phpCli(List<String> args) async {
  final res = await Process.run(_php, args, workingDirectory: _workdir)
      .timeout(const Duration(seconds: 60));
  if (res.exitCode != 0) {
    fail('CLI ${args.join(" ")} falló: ${res.stdout} ${res.stderr}');
  }
  return;
}

/// Último código de verificación capturado para un email (mu-plugin e2e).
Future<String> _peekCode(String email) async {
  final res = await Process.run(_php, ['ws-e2e-helper.php', 'peek-code', email],
          workingDirectory: _workdir)
      .timeout(const Duration(seconds: 60));
  final out = '${res.stdout}'.trim();
  final m = RegExp(r'code:\s*(\d{6})').firstMatch(out);
  if (m == null) {
    fail('no se pudo capturar el código para $email (salida: $out)');
  }
  return m.group(1)!;
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
  expect(res.statusCode, 200, reason: '$action HTTP ${res.statusCode}: ${res.body}');
  final json = jsonDecode(res.body);
  expect(json, isA<Map>());
  return (json as Map).cast<String, dynamic>();
}

void main() {
  setUpAll(() async {
    await _phpCli(['ws-test-seed.php', 'seed']);
    await _phpCli(['ws-test-seed.php', 'challenge-mode', 'unverified']);
    // Captura de correos (mu-plugin e2e): los códigos se leen con peek-code
    // sin depender de SMTP real.
    await _phpCli(['ws-e2e-helper.php', 'capture', 'on']);
  });

  tearDownAll(() async {
    // Restaura precondiciones para el resto de la suite.
    await _phpCli(['ws-test-seed.php', 'verify', _user]);
    await _phpCli(['ws-test-seed.php', 'challenge-mode', 'unverified']);
  });

  test('login normal (cuenta verificada) devuelve token sin reto', () async {
    await _phpCli(['ws-test-seed.php', 'verify', _user]);
    final r = await _post('ws_mobile_login', {'ws_user': _user, 'ws_pass': _pass});
    expect(r['success'], isTrue);
    final d = r['data'] as Map;
    expect(d['token'], isNotNull, reason: 'sin reto debe entregar token');
    expect(d['needVerify'], isNull);
  });

  test('login sin verificar → reto needVerify + código → verify_login entrega token', () async {
    await _phpCli(['ws-test-seed.php', 'unverify', _user]);
    final r = await _post('ws_mobile_login', {'ws_user': _user, 'ws_pass': _pass});
    expect(r['success'], isTrue);
    final d = r['data'] as Map;
    expect(d['needVerify'], isTrue, reason: 'cuenta sin verificar debe pedir código');
    expect(d['email'], isNotEmpty);
    expect(d['token'], isNull, reason: 'no debe entregar token sin verificar');

    final code = await _peekCode('${d['email']}');
    expect(code, hasLength(6));

    // Código incorrecto: rechazo controlado (y el correcto aún funciona).
    final bad = await _post('ws_mobile_verify_login', {'email': d['email'], 'code': '000000' == code ? '111111' : '000000'});
    expect(bad['success'], isFalse, reason: 'código erróneo debe rechazarse');

    final ok = await _post('ws_mobile_verify_login', {'email': d['email'], 'code': code});
    expect(ok['success'], isTrue, reason: 'código correcto debe entregar token: ${ok['data']}');
    final okData = ok['data'] as Map;
    expect(okData['token'], isNotNull);
    expect((okData['me'] as Map)['userId'], greaterThan(0));
  });

  test('5 contraseñas fallidas → cuenta bloqueada temporalmente', () async {
    await _phpCli(['ws-test-seed.php', 'clear-fails', _user]);
    for (var i = 0; i < 5; i++) {
      final r = await _post(
          'ws_mobile_login', {'ws_user': _user, 'ws_pass': 'incorrecta-$i'});
      expect(r['success'], isFalse, reason: 'intento $i debe fallar');
    }
    // Con la contraseña CORRECTA igualmente queda bloqueada (5 fallos).
    final r = await _post('ws_mobile_login', {'ws_user': _user, 'ws_pass': _pass});
    expect(r['success'], isFalse);
    final msg = '${(r['data'] as Map)['msg']}';
    expect(msg, contains('bloqueada'), reason: 'debe informar del bloqueo: $msg');
    // Limpia el bloqueo para el resto de la suite.
    await _phpCli(['ws-test-seed.php', 'clear-fails', _user]);
  });
}
