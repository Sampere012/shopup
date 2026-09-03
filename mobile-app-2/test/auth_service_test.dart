import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:shopup_panel/services/auth_service.dart';

void main() {
  setUp(() async {
    SharedPreferences.setMockInitialValues({});
  });

  group('AuthService.store', () {
    test('guarda me y expiresAt', () async {
      final me = {'id': 5, 'currency': 'CUP'};
      await AuthService.I.store(me, 30);
      expect(AuthService.I.me, me);
      expect(AuthService.I.userId, 5);
      expect(AuthService.I.currency, 'CUP');
    });

    test('persiste en SharedPreferences', () async {
      final me = {'id': 10};
      await AuthService.I.store(me, 7);
      final sp = await SharedPreferences.getInstance();
      final raw = sp.getString('wsm_session');
      expect(raw, isNotNull);
      final d = jsonDecode(raw!) as Map;
      expect((d['me'] as Map)['id'], 10);
      expect(d['expiresAt'], isA<int>());
    });
  });

  group('AuthService.getters', () {
    test('userId extrae de id, user_id, o userId', () async {
      await AuthService.I.store({'id': 1}, 1);
      expect(AuthService.I.userId, 1);

      await AuthService.I.store({'user_id': 2}, 1);
      expect(AuthService.I.userId, 2);

      await AuthService.I.store({'userId': 3}, 1);
      expect(AuthService.I.userId, 3);

      await AuthService.I.store({'other': 'x'}, 1);
      expect(AuthService.I.userId, 0);
    });

    test('currency default es €', () async {
      await AuthService.I.store({'id': 1}, 1);
      expect(AuthService.I.currency, '€');
    });

    test('businessName extrae de businessName o business_name', () async {
      await AuthService.I.store(
          {'id': 1, 'businessName': 'Mi Tienda'}, 1);
      expect(AuthService.I.businessName, 'Mi Tienda');

      await AuthService.I.store(
          {'id': 1, 'business_name': 'Otra'}, 1);
      expect(AuthService.I.businessName, 'Otra');
    });
  });

  group('AuthService.has / caps', () {
    test('has() mira caps exactos', () async {
      await AuthService.I.store({
        'id': 1,
        'caps': {'stock_count_view': true, 'products_edit': false},
      }, 1);

      expect(AuthService.I.has('stock_count_view'), isTrue);
      expect(AuthService.I.has('products_edit'), isFalse);
      expect(AuthService.I.has('nonexistent'), isFalse);
    });
  });

  group('AuthService.canSeeMenu', () {
    test('retorna true si la key existe en menu', () async {
      await AuthService.I.store({
        'id': 1,
        'menu': [
          {'key': 'pos', 'label': 'POS'},
          {'key': 'reports', 'label': 'Reportes'},
        ],
      }, 1);

      expect(AuthService.I.canSeeMenu('pos'), isTrue);
      expect(AuthService.I.canSeeMenu('reports'), isTrue);
      expect(AuthService.I.canSeeMenu('nonexistent'), isFalse);
    });
  });

  group('AuthService.locationIds', () {
    test('parsea ids de ubicaciones', () async {
      await AuthService.I.store({
        'id': 1,
        'locations': [
          {'id': 3},
          {'id': 7},
        ],
      }, 1);

      expect(AuthService.I.locationIds, [3, 7]);
    });

    test('sin ubicaciones devuelve vacío', () async {
      await AuthService.I.store({'id': 1}, 1);
      expect(AuthService.I.locationIds, isEmpty);
    });
  });

  group('AuthService.hasValidCachedSession', () {
    test('true después de store()', () async {
      await AuthService.I.store({'id': 1}, 30);
      expect(AuthService.I.hasValidCachedSession, isTrue);
    });
  });
}
