import 'package:flutter_test/flutter_test.dart';
import 'package:shopup_panel/config.dart';

void main() {
  group('AppConfig', () {
    test('defaultServer es shopup.site.je', () {
      expect(AppConfig.defaultServer, contains('shopup.site.je'));
    });

    test('autoSyncMinutes es 25', () {
      expect(AppConfig.autoSyncMinutes, 25);
    });

    test('appVersion empieza con 0.', () {
      expect(AppConfig.appVersion, startsWith('0.'));
    });
  });
}
