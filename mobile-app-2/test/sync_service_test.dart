import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:shopup_panel/services/sync_service.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
  });

  group('SyncService autoSyncMinutes', () {
    test('valor default es 25', () async {
      expect(SyncService.I.autoSyncMinutes, 25);
    });

    test('setAutoSyncMinutes guarda y lee', () async {
      await SyncService.I.setAutoSyncMinutes(10);
      expect(SyncService.I.autoSyncMinutes, 10);

      final sp = await SharedPreferences.getInstance();
      expect(sp.getInt('wsm_autosync_minutes'), 10);
    });

    test('clamp entre 1 y 720', () async {
      await SyncService.I.setAutoSyncMinutes(0);
      expect(SyncService.I.autoSyncMinutes, 1);

      await SyncService.I.setAutoSyncMinutes(9999);
      expect(SyncService.I.autoSyncMinutes, 720);
    });

    test('mismo valor no hace nada', () async {
      final before = SyncService.I.autoSyncMinutes;
      await SyncService.I.setAutoSyncMinutes(before);
      expect(SyncService.I.autoSyncMinutes, before);
    });
  });

  group('SyncService isBusy / estado', () {
    test('isBusy false por defecto (no syncing)', () {
      expect(SyncService.I.isBusy, isFalse);
      expect(SyncService.I.isPulling, isFalse);
    });

    test('lastSyncMs es 0 al inicio', () {
      expect(SyncService.I.lastSyncMs, 0);
    });
  });

  group('SyncService setOnline', () {
    test('cambia isOnline de true a false', () {
      expect(SyncService.I.isOnline, isTrue);
      SyncService.I.setOnline(false);
      expect(SyncService.I.isOnline, isFalse);
    });

    test('setOnline con mismo valor es no-op', () {
      SyncService.I.setOnline(false);
      SyncService.I.setOnline(false); // no-op
      expect(SyncService.I.isOnline, isFalse);
    });
  });
}
