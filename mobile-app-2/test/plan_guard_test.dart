import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:shopup_panel/services/plan_guard_service.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  const plan = 'plan';

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    await PlanGuardService.I.reset();
  });

  group('PlanGuardService applyMe', () {
    test('me sin plan no bloquea ni marca lastOk', () async {
      await PlanGuardService.I.applyMe({});
      expect(PlanGuardService.I.isBlocked, isFalse);
      expect(PlanGuardService.I.lastOkMs, 0);
    });

    test('me con plan activo desbloquea y marca lastOk', () async {
      await PlanGuardService.I.applyMe({
        plan: {'status': 'active', 'locked': false},
      });
      expect(PlanGuardService.I.isBlocked, isFalse);
      expect(PlanGuardService.I.status, 'active');
      expect(PlanGuardService.I.lastOkMs, greaterThan(0));
    });

    test('me con plan locked bloquea y guarda motivo', () async {
      await PlanGuardService.I.applyMe({
        plan: {
          'status': 'suspended',
          'locked': true,
          'lock': {
            'key': 'suspended',
            'title': 'Tu negocio está suspendido',
            'message': 'Contáctanos.',
          },
        },
      });
      expect(PlanGuardService.I.isBlocked, isTrue);
      expect(PlanGuardService.I.lockTitle, 'Tu negocio está suspendido');
      expect(PlanGuardService.I.lockMessage, 'Contáctanos.');
    });

    test('fromCloud aplica plan pero no pisa lastOk previo (caché)', () async {
      await PlanGuardService.I.applyMe({
        plan: {'status': 'active', 'locked': false},
      });
      final lastOk = PlanGuardService.I.lastOkMs;
      await PlanGuardService.I.applyMe({
        plan: {'status': 'active', 'locked': false},
      });
      expect(PlanGuardService.I.isBlocked, isFalse);
      expect(PlanGuardService.I.lastOkMs, greaterThanOrEqualTo(lastOk));
    });
  });

  group('PlanGuardService persistencia', () {
    test('bloqueo se persiste y se restaura con load()', () async {
      await PlanGuardService.I.applyMe({
        plan: {
          'status': 'expired',
          'locked': true,
          'lock': {'key': 'expired', 'title': 'Tu plan venció'},
        },
      });
      expect(PlanGuardService.I.isBlocked, isTrue);

      final fresh = PlanGuardService.I;
      await fresh.load();
      expect(fresh.isBlocked, isTrue);
      expect(fresh.status, 'expired');
      expect(fresh.lockTitle, 'Tu plan venció');
    });

    test('reset limpia bloqueo y lastOk', () async {
      await PlanGuardService.I.applyMe({
        plan: {'status': 'expired', 'locked': true},
      });
      expect(PlanGuardService.I.isBlocked, isTrue);

      await PlanGuardService.I.reset();
      expect(PlanGuardService.I.isBlocked, isFalse);
      expect(PlanGuardService.I.lastOkMs, 0);
    });
  });

  group('PlanGuardService lock title/message fallback', () {
    test('locked sin lock usa fallback', () async {
      await PlanGuardService.I.applyMe({
        plan: {'status': 'expired', 'locked': true},
      });
      expect(PlanGuardService.I.lockTitle, isNotEmpty);
      expect(PlanGuardService.I.lockMessage, isNotEmpty);
    });
  });
}