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
    'announcements', 'stock_counts', 'categories', 'pending', 'meta', 'cache',
  ];

  static Future<void> _ensureTables(Database db, [int? version]) async {
    for (final s in _stores) {
      await db.execute(
          'CREATE TABLE IF NOT EXISTS "$s" (id TEXT PRIMARY KEY, data TEXT NOT NULL)');
    }
  }

  Database? _db;

  Future<Database> get db async {
    if (_db != null) return _db!;
    final dir = await getDatabasesPath();
    _db = await openDatabase(
      p.join(dir, 'wsm.db'),
      version: 1,
      onCreate: _ensureTables,
      // Las DB creadas con versiones anteriores (v1) no tenían la tabla
      // 'categories'. onOpen garantiza que exista también en instalaciones
      // existentes, sin forzar una migración de versión.
      onOpen: _ensureTables,
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

  /// Encola una operación. [localId] enlaza la op con la fila LOCAL que la
  /// generó (p.ej. un anuncio creado offline con id 'local-…'): se guarda en
  /// la op pero NUNCA se envía al servidor (flush solo transmite `data`).
  Future<int> enqueue(String action, Map<String, dynamic> data,
      {String? localId}) async {
    final d = await db;
    final n = DateTime.now().microsecondsSinceEpoch;
    await d.insert('pending', {
      'id': 'op_$n',
      'data': jsonEncode({
        'action': action,
        'data': data,
        if (localId != null && localId.isNotEmpty) 'local_id': localId,
        'created_at': n,
        'updated_at': n,
        'status': 'pending',
        'attempts': 0,
      }),
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

  /// Una sola op por id (null si ya fue enviada/borrada).
  Future<Map<String, dynamic>?> pendingById(Object id) async {
    final d = await db;
    final rows =
        await d.query('pending', where: 'id = ?', whereArgs: ['$id'], limit: 1);
    if (rows.isEmpty) return null;
    final op = jsonDecode(rows.first['data'] as String) as Map<String, dynamic>;
    op['id'] = rows.first['id'];
    return op;
  }

  Future<void> removePending(Object id) async {
    final d = await db;
    await d.delete('pending', where: 'id = ?', whereArgs: ['$id']);
  }

  Future<void> updatePending(
    Object id, {
    required String status,
    String? error,
    bool incrementAttempts = false,
  }) async {
    final d = await db;
    final rows =
        await d.query('pending', where: 'id = ?', whereArgs: ['$id'], limit: 1);
    if (rows.isEmpty) return;
    final operation =
        jsonDecode(rows.first['data'] as String) as Map<String, dynamic>;
    operation['status'] = status;
    operation['updated_at'] = DateTime.now().microsecondsSinceEpoch;
    if (incrementAttempts) {
      operation['attempts'] = ((operation['attempts'] as num?) ?? 0) + 1;
    }
    if (error == null || error.isEmpty) {
      operation.remove('error');
    } else {
      operation['error'] = error;
    }
    await d.update(
      'pending',
      {'data': jsonEncode(operation)},
      where: 'id = ?',
      whereArgs: ['$id'],
    );
  }

  /// Reescribe el payload (`data`) de una op en cola y la vuelve a dejar
  /// como 'pending'. Permite coalescer varios cambios sobre la MISMA fila
  /// local (editar/toggle de un anuncio aún no subido) en una sola op.
  Future<bool> updatePendingData(
      Object id, Map<String, dynamic> data) async {
    final d = await db;
    final rows =
        await d.query('pending', where: 'id = ?', whereArgs: ['$id'], limit: 1);
    if (rows.isEmpty) return false;
    final operation =
        jsonDecode(rows.first['data'] as String) as Map<String, dynamic>;
    operation['data'] = data;
    operation['status'] = 'pending';
    operation['updated_at'] = DateTime.now().microsecondsSinceEpoch;
    operation.remove('error');
    await d.update(
      'pending',
      {'data': jsonEncode(operation)},
      where: 'id = ?',
      whereArgs: ['$id'],
    );
    return true;
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

  /// Claves de caché guardadas con ese prefijo (sin el prefijo 'cache:').
  /// Permite, p.ej., localizar en offline EL ÚLTIMO reporte cacheado aunque
  /// el filtro exacto del usuario (período/ubicación) no tenga copia propia.
  Future<List<String>> cacheKeys(String prefix) async {
    final d = await db;
    final rows = await d.query('meta',
        columns: ['id'], where: 'id LIKE ?', whereArgs: ['cache:$prefix%']);
    return rows
        .map((r) => '${r['id']}'.substring('cache:'.length))
        .where((k) => k.length > prefix.length)
        .toList();
  }

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
