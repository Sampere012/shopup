import 'package:flutter_test/flutter_test.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:shopup_panel/services/db_service.dart';

void main() {
  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  setUp(() async {
    await DbService.I.wipe();
    // wipe() no borra meta; limpiar cache manualmente
    final d = await DbService.I.db;
    await d.delete('meta');
  });

  group('DbService replaceAll / all', () {
    test('persiste y lee filas JSON', () async {
      await DbService.I.replaceAll('stock', [
        {'id': 'p1:1', 'product_id': 1, 'qty': 5},
        {'id': 'p2:1', 'product_id': 2, 'qty': 0},
      ]);
      final rows = await DbService.I.all('stock');
      expect(rows.length, 2);
      expect(rows.firstWhere((r) => '${r['product_id']}' == '1')['qty'], 5);
    });

    test('replaceAll reemplaza datos anteriores', () async {
      await DbService.I.replaceAll('products', [
        {'id': 1, 'name': 'A'},
      ]);
      await DbService.I.replaceAll('products', [
        {'id': 2, 'name': 'B'},
      ]);
      final rows = await DbService.I.all('products');
      expect(rows.length, 1);
      expect(rows.first['name'], 'B');
    });

    test('all(store vacío) retorna lista vacía', () async {
      final rows = await DbService.I.all('customers');
      expect(rows, isEmpty);
    });
  });

  group('DbService putAll', () {
    test('agrega sin borrar existentes', () async {
      await DbService.I.putAll('products', [
        {'id': 1, 'name': 'A'},
      ]);
      await DbService.I.putAll('products', [
        {'id': 2, 'name': 'B'},
      ]);
      final rows = await DbService.I.all('products');
      expect(rows.length, 2);
    });

    test('upsert: misma id sobrescribe', () async {
      await DbService.I.putAll('products', [
        {'id': 1, 'name': 'Old'},
      ]);
      await DbService.I.putAll('products', [
        {'id': 1, 'name': 'New'},
      ]);
      final rows = await DbService.I.all('products');
      expect(rows.length, 1);
      expect(rows.first['name'], 'New');
    });
  });

  group('DbService cola de pendientes', () {
    test('enqueue + pending (FIFO)', () async {
      await DbService.I.enqueue('ws_stock_move', {'qty': 1});
      await DbService.I.enqueue('ws_stock_move', {'qty': 2});

      final ops = await DbService.I.pending();
      expect(ops.length, 2);
      expect(ops[0]['action'], 'ws_stock_move');
      expect(ops[0]['data']['qty'], 1);
      expect(ops[1]['data']['qty'], 2);
    });

    test('pendingCount retorna el conteo', () async {
      expect(await DbService.I.pendingCount(), 0);
      await DbService.I.enqueue('a', {});
      await DbService.I.enqueue('b', {});
      expect(await DbService.I.pendingCount(), 2);
    });

    test('removePending elimina una operación', () async {
      await DbService.I.enqueue('x', {'a': 1});
      await DbService.I.enqueue('x', {'a': 2});
      final ops = await DbService.I.pending();
      await DbService.I.removePending(ops.first['id']);
      final after = await DbService.I.pending();
      expect(after.length, 1);
      expect(after.any((o) => o['id'] == ops.first['id']), isFalse);
    });

    test('cola vacía retorna []', () async {
      final ops = await DbService.I.pending();
      expect(ops, isEmpty);
    });
  });

  group('DbService meta / cache', () {
    test('setMeta + getMeta', () async {
      await DbService.I.setMeta('notif_unread_count', 5);
      final v = await DbService.I.getMeta('notif_unread_count');
      expect(v, 5);
    });

    test('getMeta de key inexistente retorna null', () async {
      final v = await DbService.I.getMeta('nonexistent');
      expect(v, isNull);
    });

    test('setMeta sobrescribe', () async {
      await DbService.I.setMeta('k', 1);
      await DbService.I.setMeta('k', 2);
      expect(await DbService.I.getMeta('k'), 2);
    });

    test('cacheSet + cacheGet', () async {
      await DbService.I.cacheGet('test_key');
      // null initially
      final v0 = await DbService.I.cacheGet('test_key');
      expect(v0, isNull);

      await DbService.I.cacheSet('test_key', [
        {'id': 1, 'name': 'X'}
      ]);
      final v = await DbService.I.cacheGet('test_key');
      expect(v, isA<List>());
      expect((v as List).first['name'], 'X');
    });

    test('cacheSet sobrescribe', () async {
      await DbService.I.cacheSet('k', {'a': 1});
      await DbService.I.cacheSet('k', {'b': 2});
      final v = await DbService.I.cacheGet('k');
      expect((v as Map)['b'], 2);
    });
  });

  group('DbService wipe', () {
    test('borra datos pero no meta', () async {
      await DbService.I.replaceAll('products', [{'id': 1}]);
      await DbService.I.setMeta('important', 'value');
      await DbService.I.wipe();

      expect(await DbService.I.all('products'), isEmpty);
      expect(await DbService.I.getMeta('important'), 'value');
    });
  });

  group('DbService rowId', () {
    test('usa id, ID, product_id, o user_id', () {
      final db = DbService.I;
      expect(db.rowId({'id': 5}, 'x'), '5');
      expect(db.rowId({'ID': 10}, 'x'), '10');
      expect(db.rowId({'product_id': 42}, 'x'), '42');
      expect(db.rowId({'user_id': 99}, 'x'), '99');
      expect(db.rowId({}, 'x'), isA<String>());
    });
  });
}
