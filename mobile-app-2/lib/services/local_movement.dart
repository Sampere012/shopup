import 'api_service.dart';
import 'auth_service.dart';
import 'db_service.dart';
import 'sync_service.dart';

/// Escritura de movimientos en SQLite local (tabla `movements` + caché) para
/// que el Historial los muestre SIN conexión.
///
/// Es el ÚNICO punto de escritura de movimientos locales: lo usan la acción
/// rápida y el asistente por lotes. Persiste SIEMPRE y avisa a las pantallas
/// en un `finally` — antes un `catch (_) {}` silencioso dejaba el movimiento
/// solo en la cola y la interfaz con datos viejos ("tengo que actualizar").
///
/// La fila local se construye con el MISMO esquema que `ws_movements_list`
/// (incluida la `image` del producto) para que Historial pinte foto, nombre,
/// destino y responsable igual que con datos del servidor.
Future<void> saveLocalMovement(String type, List<Map<String, dynamic>> items,
    String fromLoc, String toLoc, String note,
    {String? id}) async {
  try {
    final now = DateTime.now().toIso8601String();
    final workerName = AuthService.I.me?['name'] ?? '';
    // `qty` llega como int o double según el campo de origen.
    final totalQty =
        items.fold<num>(0, (t, i) => t + (((i['qty'] as num?) ?? 1)));
    // Salida/baja/transferencia se registran con qty negativo (como en la web).
    final signedQty =
        (type == 'salida' || type == 'baja' || type == 'transferencia')
            ? -totalQty
            : totalQty;
    final productId = items.isNotEmpty ? items.first['product_id'] : null;

    // Ficha del producto (nombre/foto/precios) desde la caché local: es lo
    // que permite que el movimiento offline se vea igual que uno del servidor
    // sin volver a descargar nada.
    final info = await SyncService.productInfoFor(
        productId, await DbService.I.all('stock'));

    final movement = <String, dynamic>{
      // `id` es el mismo que se pasa como `localId` al encolar: es el enlace
      // que permite retirar esta fila cuando el servidor ya la tiene (evita
      // ver el movimiento duplicado tras subir la cola).
      'id': id ?? 'local_${DateTime.now().microsecondsSinceEpoch}',
      'type': type,
      'product_id': productId,
      'product_name': items
          .map((i) => '${i['name'] ?? i['product_id'] ?? ''}')
          .join(', '),
      'qty': signedQty,
      'location_id': fromLoc,
      'location_name': '',
      'to_location_id': toLoc,
      // El Historial pide `dest_name` o `to_name` para mostrar el destino:
      // sin ellas una transferencia offline aparecía sin destino.
      'to_name': '',
      'dest_name': '',
      'note': note,
      'user_name': workerName,
      'worker_name': workerName,
      'reference': '',
      'revertable': false,
      'reverted': false,
      'created_at': now,
      'items': items,
    };
    // Foto del producto y presentación: se pisa con la ficha local si existe.
    if (info['image'] != null && '${info['image']}'.isNotEmpty) {
      movement['image'] = info['image'];
    }
    if (info['gallery'] != null) movement['gallery'] = info['gallery'];
    if (info['barcode'] != null) movement['barcode'] = info['barcode'];
    if (info['currency'] != null) movement['currency'] = info['currency'];
    // Los movimientos locales nunca son combos (el asistente sólo mueve
    // productos), pero se dejan las claves para unificar el esquema.
    movement['combo_id'] = null;
    movement['combo_name'] = null;

    final locs = await DbService.I.all('locations');
    String nameOf(String locId) {
      if (locId.isEmpty) return '';
      for (final l in locs) {
        if ('${l['id']}' == locId) return '${l['name'] ?? ''}';
      }
      return '';
    }

    movement['location_name'] = nameOf(fromLoc);
    if (toLoc.isNotEmpty) {
      movement['to_name'] = nameOf(toLoc);
      movement['dest_name'] = nameOf(toLoc);
    }
    await DbService.I.putAll('movements', [movement]);
    final cached = await DbService.I.cacheGet('ws_movements_list');
    final list = (cached is List)
        ? List<Map<String, dynamic>>.from(cached)
        : <Map<String, dynamic>>[];
    list.insert(0, movement);
    await DbService.I.cacheSet('ws_movements_list', list);
  } catch (e) {
    wsLog('! saveLocalMovement: $e');
  } finally {
    // Refleja el movimiento en Historial (y stock) al instante, sin esperar sync.
    SyncService.I.emitChange();
  }
}
