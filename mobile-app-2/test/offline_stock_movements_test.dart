import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';
import 'package:shopup_panel/services/db_service.dart';
import 'package:shopup_panel/services/sync_service.dart';

/// Regresiones del bug #2 de 0.6.5: los movimientos hechos sin conexión
/// tenían que verse al instante en Stock / POS / otras ubicaciones, y no
/// podían perderse ni duplicarse al subir la cola.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUpAll(() {
    sqfliteFfiInit();
    databaseFactory = databaseFactoryFfi;
  });

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    await DbService.I.wipe();
    // wipe() no borra meta; limpiar cache y cola manualmente por si acaso.
    final d = await DbService.I.db;
    await d.delete('meta');
    await d.delete('pending');
    await d.delete('cache');
  });

  Future<num> qtyAt(Object locationId, Object productId) async {
    final rows = await DbService.I.all('stock');
    final hit = rows.where((r) =>
        '${r['location_id']}' == '$locationId' &&
        '${r['product_id']}' == '$productId');
    if (hit.isEmpty) return 0;
    return (hit.first['qty'] as num?) ?? 0;
  }

  group('adjustLocalStock (upsert)', () {
    test('crea la fila de stock si la ubicación no la tenía', () async {
      // Transferencia/entrada a una ubicación que aún no registraba ese
      // producto: antes `adjustLocalStock` no hacía nada y el producto
      // no aparecía en Stock, en el POS ni en esa ubicación.
      await SyncService.I.adjustLocalStock(2, 7, 3, notify: false);

      final rows = await DbService.I.all('stock');
      expect(rows, hasLength(1));
      expect(rows.first['id'], 'p7:2');
      expect(rows.first['qty'], 3);
      expect(await qtyAt(2, 7), 3);
    });

    test('actualiza una fila existente', () async {
      await DbService.I.replaceAll('stock', [
        {'id': 'p7:2', 'product_id': 7, 'location_id': 2, 'qty': 5},
      ]);
      await SyncService.I.adjustLocalStock(2, 7, -2, notify: false);
      expect(await qtyAt(2, 7), 3);
      expect(await DbService.I.all('stock'), hasLength(1));
    });

    test('clamp: nunca deja stock negativo', () async {
      await DbService.I.replaceAll('stock', [
        {'id': 'p1:1', 'product_id': 1, 'location_id': 1, 'qty': 2},
      ]);
      await SyncService.I.adjustLocalStock(1, 1, -4, notify: false);
      expect(await qtyAt(1, 1), 0);
    });

    test('notify:false sigue persistiendo', () async {
      await SyncService.I.adjustLocalStock(1, 9, 4, notify: false);
      expect(await qtyAt(1, 9), 4);
    });
  });

  group('reapplyPendingStockDeltas', () {
    test('cola vacía: no toca el stock', () async {
      await DbService.I.replaceAll('stock', [
        {'id': 'p1:1', 'product_id': 1, 'location_id': 1, 'qty': 5},
      ]);
      await SyncService.I.reapplyPendingStockDeltas();
      expect(await qtyAt(1, 1), 5);
    });

    test('entrada en cola se reaplica tras un pull del servidor', () async {
      await DbService.I.replaceAll('stock', [
        {'id': 'p1:1', 'product_id': 1, 'location_id': 1, 'qty': 5},
      ]);
      await DbService.I.enqueue('ws_stock_move', {
        'type': 'entrada',
        'product_id': 1,
        'location_id': 1,
        'qty': 4,
      });

      // Pull: el servidor devuelve 5 (sin el movimiento offline).
      await DbService.I.replaceAll('stock', [
        {'id': 'p1:1', 'product_id': 1, 'location_id': 1, 'qty': 5},
      ]);
      await SyncService.I.reapplyPendingStockDeltas();

      expect(await qtyAt(1, 1), 9);
    });

    test('salida/baja en cola descuenta', () async {
      await DbService.I.replaceAll('stock', [
        {'id': 'p1:1', 'product_id': 1, 'location_id': 1, 'qty': 5},
      ]);
      await DbService.I.enqueue('ws_stock_move', {
        'type': 'baja',
        'product_id': 1,
        'location_id': 1,
        'qty': 2,
      });
      await DbService.I.replaceAll('stock', [
        {'id': 'p1:1', 'product_id': 1, 'location_id': 1, 'qty': 5},
      ]);
      await SyncService.I.reapplyPendingStockDeltas();
      expect(await qtyAt(1, 1), 3);
    });

    test('transferencia por lotes mueve stock del origen al destino', () async {
      await DbService.I.replaceAll('stock', [
        {'id': 'p1:1', 'product_id': 1, 'location_id': 1, 'qty': 10},
      ]);
      await DbService.I.enqueue('ws_stock_batch_transfer', {
        'from_location': 1,
        'to_location': 2,
        'items': [
          {'product_id': 1, 'name': 'A', 'qty': 3},
        ],
        'note': 'offline',
      });

      await SyncService.I.reapplyPendingStockDeltas();

      expect(await qtyAt(1, 1), 7, reason: 'el origen no descontó');
      expect(await qtyAt(2, 1), 3,
          reason: 'el destino no lo creció: bug #2 de 0.6.5');
    });

    test('transferencia simple en cola se reaplica', () async {
      await DbService.I.replaceAll('stock', [
        {'id': 'p1:1', 'product_id': 1, 'location_id': 1, 'qty': 6},
      ]);
      await DbService.I.enqueue('ws_stock_transfer', {
        'product_id': 1,
        'from_location': 1,
        'to_location': 3,
        'qty': 2,
        'note': 'offline',
      });
      await SyncService.I.reapplyPendingStockDeltas();
      expect(await qtyAt(1, 1), 4);
      expect(await qtyAt(3, 1), 2);
    });

    test('venta POS en cola descuenta el stock de su ubicación', () async {
      await DbService.I.replaceAll('stock', [
        {'id': 'p1:1', 'product_id': 1, 'location_id': 1, 'qty': 8},
      ]);
      await DbService.I.enqueue('ws_pos_sale_save', {
        'location_id': 1,
        'items': [
          {'product_id': 1, 'qty': 2},
        ],
      });
      await SyncService.I.reapplyPendingStockDeltas();
      expect(await qtyAt(1, 1), 6);
    });

    test('acumula varias ops en una sola pasada', () async {
      await DbService.I.replaceAll('stock', [
        {'id': 'p1:1', 'product_id': 1, 'location_id': 1, 'qty': 0},
      ]);
      await DbService.I.enqueue('ws_stock_move', {
        'type': 'entrada',
        'product_id': 1,
        'location_id': 1,
        'qty': 5,
      });
      await Future<void>.delayed(const Duration(milliseconds: 2));
      await DbService.I.enqueue('ws_stock_move', {
        'type': 'salida',
        'product_id': 1,
        'location_id': 1,
        'qty': 2,
      });
      await SyncService.I.reapplyPendingStockDeltas();
      expect(await qtyAt(1, 1), 3);
      expect(await DbService.I.pendingCount(), 2,
          reason: 'reaplicar no debe consumir la cola');
    });

    test('cuadre con ajuste en cola reaplica su diff tras un pull', () async {
      await DbService.I.replaceAll('stock', [
        {'id': 'p1:1', 'product_id': 1, 'location_id': 1, 'qty': 10},
      ]);
      await DbService.I.enqueue('ws_stock_count_save', {
        'location_id': 1,
        'adjust': 1,
        'items': [
          {'product_id': 1, 'physical': 7, 'diff': -3},
        ],
      });
      // Pull: el servidor devuelve el stock SIN el cuadre offline.
      await DbService.I.replaceAll('stock', [
        {'id': 'p1:1', 'product_id': 1, 'location_id': 1, 'qty': 10},
      ]);
      await SyncService.I.reapplyPendingStockDeltas();
      expect(await qtyAt(1, 1), 7,
          reason: 'el cuadre offline se "deshacía" al refrescar');
    });

    test('cuadre SIN ajuste no toca el stock', () async {
      await DbService.I.replaceAll('stock', [
        {'id': 'p1:1', 'product_id': 1, 'location_id': 1, 'qty': 10},
      ]);
      await DbService.I.enqueue('ws_stock_count_save', {
        'location_id': 1,
        'adjust': 0,
        'items': [
          {'product_id': 1, 'physical': 7},
        ],
      });
      await SyncService.I.reapplyPendingStockDeltas();
      expect(await qtyAt(1, 1), 10);
    });
  });
}
