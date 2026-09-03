import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:shopup_panel/services/api_service.dart';

void main() {
  group('ApiService', () {
    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      await ApiService.I.load();
    });

    test('token y server persisten en SharedPreferences', () async {
      await ApiService.I.setToken('abc123');
      await ApiService.I.setServer('https://mi-tienda.com');

      // Verificar que se guardaron en SharedPreferences
      final sp = await SharedPreferences.getInstance();
      expect(sp.getString('wsm_token'), 'abc123');
      expect(sp.getString('wsm_server'), 'https://mi-tienda.com');
    });

    test('setToken(null) borra el token', () async {
      await ApiService.I.setToken('tmp');
      expect(ApiService.I.token, 'tmp');

      await ApiService.I.setToken(null);
      expect(ApiService.I.token, isNull);
    });

    test('endpoint se construye correctamente', () {
      expect(ApiService.I.endpoint.toString(),
          endsWith('/wp-admin/admin-ajax.php'));
    });

    test('server default es shopup.site.je', () {
      expect(ApiService.I.server, contains('shopup.site.je'));
    });

    test('setServer elimina barras finales', () async {
      await ApiService.I.setServer('https://example.com/');
      expect(ApiService.I.server, 'https://example.com');
      await ApiService.I.setServer('https://example.com///');
      expect(ApiService.I.server, 'https://example.com');
    });
  });

  group('ApiException', () {
    test('mensaje se preserva', () {
      final e = ApiException('Error de red');
      expect(e.toString(), 'Error de red');
    });

    test('response es opcional', () {
      final e1 = ApiException('sin resp');
      expect(e1.response, isNull);

      final e2 = ApiException('con resp', response: {'success': false});
      expect(e2.response, isNotNull);
    });
  });
}
