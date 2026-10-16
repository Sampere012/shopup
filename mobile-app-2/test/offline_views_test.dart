import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:shopup_panel/services/db_service.dart';
import 'package:shopup_panel/services/filter_prefs.dart';
import 'package:shopup_panel/services/sync_service.dart';

/// Regresiones del trabajo de 0.6.7: filtros persistidos en el dispositivo,
/// filas locales que sobreviven al pull y cuadre virtual SIN conexión.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    await DbService.I.wipe();
    final d = await DbService.I.db;
    await d.delete('meta');
    await d.delete('pending');
    await d.delete('cache');
  });

  group('FilterPrefs', () {
    test('guarda y devuelve el filtro de ubicación', () async {
      expect(await FilterPrefs.loc(FilterPrefs.stockLoc), '');
      await FilterPrefs.setLoc(FilterPrefs.stockLoc, '3');
      expect(await FilterPrefs.loc(FilterPrefs.stockLoc), '3');
    });

    test('vacío borra la clave (vuelve al default)', () async {
      await FilterPrefs.setLoc(FilterPrefs.countsLoc, '7');
      await FilterPrefs.setLoc(FilterPrefs.countsLoc, '');
      expect(await FilterPrefs.loc(FilterPrefs.countsLoc), '');
    });

    test('claves independientes por vista', () async {
      await FilterPrefs.setLoc(FilterPrefs.stockLoc, '1');
      await FilterPrefs.setLoc(FilterPrefs.movementsLoc, '2');
      expect(await FilterPrefs.loc(FilterPrefs.stockLoc), '1');
      expect(await FilterPrefs.loc(FilterPrefs.movementsLoc), '2');
    });

    test('intVal/setInt persisten el período de reportes', () async {
      expect(
          await FilterPrefs.intVal(FilterPrefs.reportsPeriod, def: 14), 14);
      await FilterPrefs.setInt(FilterPrefs.reportsPeriod, 30);
      expect(
          await FilterPrefs.intVal(FilterPrefs.reportsPeriod, def: 14), 30);
      expect(await FilterPrefs.intVal(FilterPrefs.reportsLoc, def: 0), 0);
    });

    test('keepIfKnown descarta ubicaciones ya borradas', () {
      expect(FilterPrefs.keepIfKnown('2', ['1', '2']), '2');
      expect(FilterPrefs.keepIfKnown('9', ['1', '2']), '');
      expect(FilterPrefs.keepIfKnown('', ['1']), '');
    });
  });

  group('DbService.cacheKeys', () {
    test('lista solo las claves con el prefijo pedido', () async {
      await DbService.I.cacheSet('ws_reports_summary:14:0', {'a': 1});
      await DbService.I.cacheSet('ws_reports_summary:30:0', {'b': 2});
      await DbService.I.cacheSet('ws_movements_list', []);
      final keys = await DbService.I.cacheKeys('ws_reports_summary');
      expect(keys, containsAll(['ws_reports_summary:14:0', 'ws_reports_summary:30:0']));
      expect(keys, isNot(contains('ws_movements_list')));
    });

    test('devuelve lista vacía si no hay caché', () async {
      expect(await DbService.I.cacheKeys('nada_'), isEmpty);
    });
  });

  group('isLocalRow', () {
    test('reconoce los prefijos locales de la app', () {
      expect(SyncService.isLocalRow({'id': 'local_123'}), isTrue);
      expect(SyncService.isLocalRow({'id': 'local-456'}), isTrue);
      expect(SyncService.isLocalRow({'id': 'pending_789'}), isTrue);
      expect(SyncService.isLocalRow({'id': '-1699999999999'}), isTrue);
      expect(SyncService.isLocalRow({'id': '42'}), isFalse);
      expect(SyncService.isLocalRow({'id': 'srv_1'}), isFalse);
    });
  });

  group('mergeLocalRows', () {
    test('conserva lo local que el servidor aún no devuelve', () {
      final merged = SyncService.mergeLocalRows([
        {'id': '10', 'qty': 1},
      ], [
        {'id': 'local_1', 'qty': 5},
        {'id': '10', 'qty': 99},
      ]);
      expect(merged, hasLength(2));
      expect(merged.first['id'], '10');
      expect(merged.last['id'], 'local_1');
      expect(merged.first['qty'], 1, reason: 'no se duplica la fila del servidor');
    });

    test('no agrega filas no-locales del almacen local', () {
      final merged = SyncService.mergeLocalRows(
          <Map<String, dynamic>>[], [
        {'id': '55'},
      ]);
      expect(merged, isEmpty);
    });

    test('acepta un detector isLocal propio', () {
      final merged = SyncService.mergeLocalRows(
          <Map<String, dynamic>>[],
          [
            {'id': 'x'},
            {'id': 'y'},
          ],
          isLocal: (r) => r['id'] == 'y');
      expect(merged.single['id'], 'y');
    });
  });

  group('virtualCountFromLocal', () {
    test('corrige el virtual del servidor con el stock local', () {
      final out = SyncService.virtualCountFromLocal([
        {'product_id': 7, 'name': 'Café', 'virtual_qty': 10},
      ], [
        {'product_id': 7, 'location_id': '2', 'qty': 4},
      ], '2');
      expect(out, hasLength(1));
      expect(out.first['qty'], 4, reason: 'el stock local manda offline');
      expect(out.first['virtual_qty'], 4);
      expect(out.first['name'], 'Café');
    });

    test('ignora el stock de OTRAS ubicaciones', () {
      final out = SyncService.virtualCountFromLocal([
        {'product_id': 7, 'virtual_qty': 10},
      ], [
        {'product_id': 7, 'location_id': '9', 'qty': 4},
      ], '2');
      expect(out.first['qty'], 10);
    });

    test('añade productos nuevos que entraron sin conexión', () {
      final out = SyncService.virtualCountFromLocal([], [
        {'product_id': 3, 'location_id': '2', 'qty': 6, 'name': 'Té'},
        {'product_id': 4, 'location_id': '2', 'qty': 0, 'name': 'Agua'},
      ], '2');
      expect(out, hasLength(1), reason: 'los qty<=0 no se agregan');
      expect(out.first['product_id'], 3);
      expect(out.first['qty'], 6);
      expect(out.first['name'], 'Té');
    });
  });

  group('adjustLocalStock hidrata location_name', () {
    test('la fila creada trae el nombre de la ubicación', () async {
      await DbService.I.replaceAll('locations', [
        {'id': 2, 'name': 'Bodega'},
      ]);
      await SyncService.I.adjustLocalStock(2, 7, 3, notify: false);
      final rows = await DbService.I.all('stock');
      expect(rows.first['location_name'], 'Bodega');
    });

    test('completa el nombre que faltaba en una fila existente', () async {
      await DbService.I.replaceAll('locations', [
        {'id': 2, 'name': 'Bodega'},
      ]);
      await DbService.I.replaceAll('stock', [
        {'id': 'p7:2', 'product_id': 7, 'location_id': 2, 'qty': 5},
      ]);
      await SyncService.I.adjustLocalStock(2, 7, -1, notify: false);
      final rows = await DbService.I.all('stock');
      expect(rows.first['location_name'], 'Bodega',
          reason: 'Stock pintaba «—» sin este nombre');
      expect(rows.first['qty'], 4);
    });
  });
}
