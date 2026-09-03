import 'dart:convert';
import 'package:sqflite/sqflite.dart';
import 'package:path/path.dart' as p;

/// Espejo SQLite de los stores de la app Cordova (js/db.js).
/// Tablas clave-valor con filas JSON: mismo modelo flexible, migración
/// directa del código JS y consultas completas en memoria (los volúmenes
/// son pequeños: cientos/miles de filas como máximo).
class DbService {
  DbService._();
  static final DbService I = DbService._();

  static const _stores = [
    'locations', 'products', 'stock', 'combos', 'movements', 'orders',
    'customers', 'workers', 'shifts', 'pos_sales', 'expenses',
    'announcements', 'stock_counts', 'pending', 'meta', 'cache',
  ];

  Database? _db;

  Future<Database> get db async {
    if (_db != null) return _db!;
    final dir = await getDatabasesPath();
    _db = await openDatabase(
      p.join(dir, 'wsm.db'),
      version: 1,
      onCreate: (d, v) async {
        for (final s in _stores) {
          await d.execute(
              'CREATE TABLE IF NOT EXISTS "$s" (id TEXT PRIMARY KEY, data TEXT NOT NULL)');
        }
      },
    );
    return _db!;
  }

  String rowId(Map<String, dynamic> row, String store) {
    final dynamic id = row['id'] ?? row['ID'] ?? row['product_id'] ?? row['user_id'];
    return id == null ? UniqueKey.next() : '$id';
  }

  Future<List<Map<String, dynamic>>> all(String store) async {
    final d = await db;
    final rows = await d.query(store);
    return rows
        .map((r) => jsonDecode(r['data'] as String) as Map<String, dynamic>)
        .toList();
  }

  Future<void> replaceAll(String store, List<Map<String, dynamic>> rows) async {
    final d = await db;
    final batch = d.batch();
    batch.delete(store);
    for (final r in rows) {
      batch.insert(store,
          {'id': rowId(r, store), 'data': jsonEncode(r)},
          conflictAlgorithm: ConflictAlgorithm.replace);
    }
    await batch.commit(noResult: true);
  }

  Future<void> putAll(String store, List<Map<String, dynamic>> rows) async {
    final d = await db;
    final batch = d.batch();
    for (final r in rows) {
      batch.insert(store,
          {'id': rowId(r, store), 'data': jsonEncode(r)},
          conflictAlgorithm: ConflictAlgorithm.replace);
    }
    await batch.commit(noResult: true);
  }

  // ---- Cola de pendientes (mismo contrato que js/db.js pending) ----

  Future<int> enqueue(String action, Map<String, dynamic> data) async {
    final d = await db;
    final n = DateTime.now().microsecondsSinceEpoch;
    await d.insert('pending', {
      'id': 'op_$n',
      'data': jsonEncode({'action': action, 'data': data, 'created_at': n}),
    });
    return n;
  }

  Future<List<Map<String, dynamic>>> pending() async {
    final d = await db;
    final rows = await d.query('pending', orderBy: 'id ASC');
    return rows.map((r) {
      final op = jsonDecode(r['data'] as String) as Map<String, dynamic>;
      op['id'] = r['id'];
      return op;
    }).toList();
  }

  Future<void> removePending(Object id) async {
    final d = await db;
    await d.delete('pending', where: 'id = ?', whereArgs: ['$id']);
  }

  Future<int> pendingCount() async {
    final d = await db;
    return Sqflite.firstIntValue(
            await d.rawQuery('SELECT COUNT(*) c FROM pending')) ??
        0;
  }

  // ---- Meta / caché de respuestas ----

  Future<void> setMeta(String key, Object value) async {
    final d = await db;
    await d.insert('meta', {'id': key, 'data': jsonEncode(value)},
        conflictAlgorithm: ConflictAlgorithm.replace);
  }

  Future<Object?> getMeta(String key) async {
    final d = await db;
    final rows = await d.query('meta', where: 'id = ?', whereArgs: [key]);
    if (rows.isEmpty) return null;
    return jsonDecode(rows.first['data'] as String);
  }

  /// cache(action, payload): respuestas de solo-lectura por clave.
  Future<void> cacheSet(String key, Object value) => setMeta('cache:$key', value);

  Future<Object?> cacheGet(String key) => getMeta('cache:$key');

  /// Borra TODOS los datos locales (cambio de usuario / logout).
  Future<void> wipe() async {
    final d = await db;
    final batch = d.batch();
    for (final s in _stores) {
      if (s == 'meta') continue;
      batch.delete(s);
    }
    await batch.commit(noResult: true);
  }
}

class UniqueKey {
  static int _n = 0;
  static String next() => 'row_${++_n}_${DateTime.now().microsecondsSinceEpoch}';
}
