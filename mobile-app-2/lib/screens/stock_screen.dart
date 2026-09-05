import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/pdf_service.dart';
import '../services/sync_service.dart';
import '../utils/clipboard.dart';
import '../widgets/common.dart';
import '../widgets/crud.dart';

/// Stock con filtros por ubicación, pestañas Productos/Combos,
/// acciones rápidas (entrada, salida, baja, transferencia) y
/// movimiento en lote.
class StockScreen extends StatefulWidget {
  const StockScreen({super.key});
  @override
  State<StockScreen> createState() => _StockScreenState();
}

class _StockScreenState extends State<StockScreen> {
  List<Map<String, dynamic>> _locations = [];
  List<Map<String, dynamic>> _rows = [];
  List<Map<String, dynamic>> _combos = [];
  String _locFilter = '';
  String _search = '';
  String _tab = 'products'; // products | combos
  bool _loading = true;
  // Densidad de la grilla (pellizco tipo galería). 0 = automático por ancho.
  int _cols = 0;
  bool _exportingCatalog = false;
  final GlobalKey<ScaffoldState> _scaffoldKey = GlobalKey<ScaffoldState>();

  /// Catálogo PDF de la ubicación seleccionada — misma lógica que la web:
  /// exige ubicación y pide ws_stock_catalog_pdf al servidor, luego se
  /// comparte el archivo con cualquier app del teléfono.
  Future<void> _exportCatalog(BuildContext context) async {
    if (_locFilter.isEmpty) {
      U.toast(context, 'Selecciona una ubicación para exportar el catálogo',
          kind: 'err');
      return;
    }
    setState(() => _exportingCatalog = true);
    try {
      await PdfService.exportStockCatalog(int.parse(_locFilter));
      if (mounted) U.toast(context, 'Catálogo listo para compartir', kind: 'ok');
    } on ApiException catch (e) {
      if (mounted) U.toast(context, e.message, kind: 'err');
    } catch (_) {
      if (mounted) U.toast(context, 'No se pudo exportar el catálogo.', kind: 'err');
    } finally {
      if (mounted) setState(() => _exportingCatalog = false);
    }
  }

  /// Copia el catálogo de stock al portapapeles según la configuración del usuario.
  Future<void> _copyToClipboard(BuildContext context) async {
    final items = _filtered;
    if (items.isEmpty) {
      U.toast(context, 'No hay productos para copiar', kind: 'err');
      return;
    }
    final config = await _loadClipboardConfig();
    final cur = AuthService.I.currency;
    final locName = _locations
        .where((l) => '${l['id']}' == _locFilter)
        .map((l) => '${l['name'] ?? ''}')
        .firstOrNull ?? '';
    final text = buildClipboardText(items, config,
        currency: cur, locationName: locName);
    await Clipboard.setData(ClipboardData(text: text));
    if (mounted) U.toast(context, 'Copiado al portapapeles (${items.length} productos)', kind: 'ok');
  }

  static Future<Map<String, dynamic>> _loadClipboardConfig() async {
    final sp = await SharedPreferences.getInstance();
    final raw = sp.getString('wsm_clipboard_config');
    if (raw != null) return clipboardConfigFromJson(raw);
    return defaultClipboardConfig();
  }

  @override
  void initState() {
    super.initState();
    SharedPreferences.getInstance().then((sp) {
      if (!mounted) return;
      setState(() => _cols = sp.getInt('wsm_density_stock') ?? 0);
    });
    _loadAll();
    SyncService.I.onChange(_onSync);
  }

  Future<void> _setCols(int v) async {
    if (v == _cols) return;
    setState(() => _cols = v);
    final sp = await SharedPreferences.getInstance();
    await sp.setInt('wsm_density_stock', v);
  }

  void _onSync() {
    if (mounted) _loadAll();
  }

  Future<void> _loadAll() async {
    // 1) Locations from SQLite
    var locs = await DbService.I.all('locations');
    // Filtrar por ubicaciones del trabajador si tiene asignadas
    final workerLocs = AuthService.I.locationIds;
    if (workerLocs.isNotEmpty) {
      locs = locs
          .where((l) => workerLocs.contains(int.tryParse('${l['id']}') ?? 0))
          .toList();
    }
    if (locs.isNotEmpty) _locations = locs;
    // 2) Stock from SQLite
    var stock = await DbService.I.all('stock');
    stock = await _repairStockDuplicates(stock);
    if (workerLocs.isNotEmpty) {
      stock = stock
          .where((r) => workerLocs.contains(int.tryParse('${r['location_id']}') ?? 0))
          .toList();
    }
    _rows = stock;
    // 3) Combos from cache
    final combosCache =
        await DbService.I.cacheGet('ws_stock_list_combos');
    _combos = (combosCache as List?)?.whereType<Map>().toList().cast<Map<String, dynamic>>() ?? [];
    setState(() => _loading = false);
    // 4) Background refresh from server
    _refreshFromServer();
  }

  Future<void> _refreshFromServer() async {
    if (SyncService.I.isPulling) return;
    try {
      final d = await ApiService.I.req('ws_stock_list', {
        'location_id': 0,
        'search': '',
        'pageSize': 500,
        'page': 1,
      });
      var serverRows = List<Map<String, dynamic>>.from((d['rows'] as List?) ?? []);
      final serverCombos = List<Map<String, dynamic>>.from((d['combos'] as List?) ?? []);
      for (final r in serverRows) {
        r['id'] = 'p${r['product_id']}:${r['location_id']}';
      }
      // Filtrar por ubicaciones del trabajador
      final workerLocs = AuthService.I.locationIds;
      if (workerLocs.isNotEmpty) {
        serverRows = serverRows
            .where((r) => workerLocs.contains(int.tryParse('${r['location_id']}') ?? 0))
            .toList();
      }
      _rows = serverRows;
      _combos = serverCombos;
      await DbService.I.cacheSet('ws_stock_list', _rows);
      await DbService.I.cacheSet('ws_stock_list_combos', _combos);
      // Persist to SQLite (merge/upsert) so data survives app restart
      await DbService.I.putAll('stock', _rows);
      await _repairStockDuplicates(await DbService.I.all('stock'));
      await DbService.I.putAll('combos', _combos);
      // Además actualizar las ubicaciones
      final locData = await ApiService.I.req('ws_locations_list', {});
      final serverLocs =
          (locData['locations'] as List?) ?? (locData['data'] as List?) ?? [];
      if (serverLocs.isNotEmpty) {
        _locations = List<Map<String, dynamic>>.from(serverLocs);
        await DbService.I.replaceAll('locations', _locations);
      }
      if (mounted) setState(() {});
    } catch (_) {}
  }

  Future<List<Map<String, dynamic>>> _repairStockDuplicates(
      List<Map<String, dynamic>> rows) async {
    final map = <String, Map<String, dynamic>>{};
    for (final r in rows) {
      final key = 'p${r['product_id']}:${r['location_id']}';
      final existing = map[key];
      if (existing == null) {
        final copy = Map<String, dynamic>.from(r);
        copy['id'] = key;
        map[key] = copy;
      }
    }
    final cleaned = map.values.toList();
    if (cleaned.length != rows.length) {
      await DbService.I.replaceAll('stock', cleaned);
    }
    return cleaned;
  }

  List<Map<String, dynamic>> get _filtered {
    var list = _tab == 'combos' ? _combos : _rows;
    if (_locFilter.isNotEmpty) {
      list = list
          .where((r) => '${r['location_id']}' == _locFilter)
          .toList();
    }
    if (_search.isNotEmpty) {
      final q = _search.toLowerCase();
      list = list
          .where((r) => '${r['name'] ?? ''}'.toLowerCase().contains(q))
          .toList();
    }
    return list;
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final canEntry = AuthService.I.has('stock_entry');
    final canExit = AuthService.I.has('stock_exit');
    final canTransfer = AuthService.I.has('stock_transfer');
    final canWriteoff = AuthService.I.has('stock_writeoff');
    final hasAnyAction = canEntry || canExit || canTransfer || canWriteoff;
    final items = _filtered;
    final screenWidth = MediaQuery.of(context).size.width;
    final crossCount =
        _cols > 0 ? _cols : (screenWidth > 900 ? 3 : screenWidth > 600 ? 2 : 1);

    return Scaffold(
      key: _scaffoldKey,
      backgroundColor: Colors.transparent,
      floatingActionButton: hasAnyAction
          ? FloatingActionButton.extended(
              heroTag: 'newStockMove',
              onPressed: () => _openBatchMove(context),
              icon: const Icon(Icons.add),
              label: const Text('Nuevo movimiento'),
            )
          : null,
      body: Column(
      children: [
        // Location filter + Search
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
          child: Row(
            children: [
              Expanded(
                flex: 2,
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10),
                  decoration: BoxDecoration(
                    color: isDark ? AppTheme.darkSurface : Colors.white,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(
                        color: isDark
                            ? Colors.white.withAlpha(20)
                            : Colors.black.withAlpha(20)),
                  ),
                  child: DropdownButtonHideUnderline(
                    child: DropdownButton<String>(
                      value: _locFilter.isEmpty ? null : _locFilter,
                      isExpanded: true,
                      hint: Text('Todas',
                          style: TextStyle(
                              fontSize: 13,
                              color: isDark
                                  ? AppTheme.darkMuted
                                  : AppTheme.lightMuted)),
                      items: _locations
                          .map((l) => DropdownMenuItem(
                              value: '${l['id']}',
                              child: Text('${l['name'] ?? ''}',
                                  style: const TextStyle(fontSize: 13))))
                          .toList(),
                      onChanged: (v) => setState(() => _locFilter = v ?? ''),
                    ),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                flex: 3,
                child: TextField(
                  decoration: InputDecoration(
                    hintText: 'Buscar…',
                    prefixIcon: const Icon(Icons.search, size: 18),
                    isDense: true,
                    filled: true,
                    fillColor: isDark ? AppTheme.darkSurface : Colors.white,
                  ),
                  onChanged: (v) => setState(() => _search = v.trim()),
                ),
              ),
              // Catálogo PDF de la ubicación seleccionada (igual que la web).
              Tooltip(
                message: 'Exporta el catálogo PDF de los productos '
                    'disponibles en la ubicación seleccionada (foto, nombre y precio)',
                child: IconButton.outlined(
                  onPressed:
                      _exportingCatalog ? null : () => _exportCatalog(context),
                  icon: _exportingCatalog
                      ? const SizedBox(
                          width: 18,
                          height: 18,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.picture_as_pdf, size: 20),
                  tooltip: 'Catálogo PDF',
                ),
              ),
              // Copiar al portapapeles
              IconButton.outlined(
                onPressed: () => _copyToClipboard(context),
                icon: const Icon(Icons.content_copy, size: 20),
                tooltip: 'Copiar catálogo al portapapeles',
              ),
            ],
          ),
        ),
        // Segmented tabs
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 10, 14, 0),
          child: Row(
            children: [
              _segBtn('Productos', 'products'),
              const SizedBox(width: 6),
              _segBtn('Combos', 'combos'),
            ],
          ),
        ),
        // List
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : items.isEmpty
                  ? Center(
                      child: Text(
                          _tab == 'combos' ? 'Sin combos.' : 'Sin stock.',
                          style: TextStyle(
                              color: isDark
                                  ? AppTheme.darkMuted
                                  : AppTheme.lightMuted)),
                    )
                  : RefreshIndicator(
                      onRefresh: () async {
                        await _loadAll();
                      },
                      child: PinchDensity(
                        cols: crossCount,
                        minCols: 1,
                        maxCols: 3,
                        onChanged: _setCols,
                        child: GridView.builder(
                          padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
                          cacheExtent: 300,
                          itemCount: items.length,
                          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                            crossAxisCount: crossCount,
                            mainAxisExtent: 76,
                            mainAxisSpacing: 8,
                            crossAxisSpacing: 8,
                          ),
                          itemBuilder: (context, i) =>
                              _stockTile(context, items[i]),
                        ),
                      ),
                    ),
        ),
      ],
    ),
    );
  }

  Widget _segBtn(String label, String value) {
    final active = _tab == value;
    return Expanded(
      child: GestureDetector(
        onTap: () => setState(() => _tab = value),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 8),
          decoration: BoxDecoration(
            color: active ? AppTheme.primary : Colors.transparent,
            borderRadius: BorderRadius.circular(10),
            border: Border.all(
                color: active
                    ? AppTheme.primary
                    : AppTheme.primary.withAlpha(80)),
          ),
          alignment: Alignment.center,
          child: Text(label,
              style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: active ? Colors.white : AppTheme.primary)),
        ),
      ),
    );
  }

  Widget _stockTile(BuildContext context, Map<String, dynamic> r) {
    final qty = (num.tryParse('${r['qty']}') ?? 0).toInt();
    final canEntry = AuthService.I.has('stock_entry');
    final canExit = AuthService.I.has('stock_exit');
    final canTransfer = AuthService.I.has('stock_transfer');
    final canWriteoff = AuthService.I.has('stock_writeoff');
    final imgUrl = '${r['image'] ?? ''}';
    final hasAnyAction = canEntry || canExit || canTransfer || canWriteoff;

    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        child: Row(children: [
          ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: imgUrl.isNotEmpty
                ? NetImage(
                    imgUrl,
                    width: 40, height: 40, fit: BoxFit.cover,
                    memCacheWidth: 100,
                    fallback: (_) => _qtyBadge(qty),
                  )
                : _qtyBadge(qty),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(r['name'] ?? '',
                    style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                    maxLines: 1, overflow: TextOverflow.ellipsis),
                const SizedBox(height: 2),
                Text('${r['location_name'] ?? '—'} · $qty uds',
                    style: TextStyle(
                      color: qty == 0 ? AppTheme.danger : Colors.grey[600],
                      fontSize: 11,
                      fontWeight: qty == 0 ? FontWeight.w700 : FontWeight.normal,
                    ),
                    maxLines: 1, overflow: TextOverflow.ellipsis),
              ],
            ),
          ),
          if (hasAnyAction)
            PopupMenuButton<String>(
              padding: EdgeInsets.zero,
              iconSize: 20,
              icon: Icon(Icons.more_vert,
                  color: AppTheme.primary.withAlpha(160), size: 20),
              onSelected: (type) => _openMove(context, type, r),
              itemBuilder: (_) => [
                if (canEntry)
                  const PopupMenuItem(
                    value: 'entrada',
                    child: Text('＋ Entrada', style: TextStyle(fontSize: 13)),
                  ),
                if (canExit)
                  const PopupMenuItem(
                    value: 'salida',
                    child: Text('− Salida', style: TextStyle(fontSize: 13)),
                  ),
                if (canTransfer)
                  const PopupMenuItem(
                    value: 'transferencia',
                    child: Text('⇄ Transferencia', style: TextStyle(fontSize: 13)),
                  ),
                if (canWriteoff)
                  const PopupMenuItem(
                    value: 'baja',
                    child: Text('✕ Baja', style: TextStyle(fontSize: 13, color: AppTheme.danger)),
                  ),
              ],
            ),
        ]),
      ),
    );
  }

  Widget _qtyBadge(int qty) {
    return Container(
      width: 42,
      height: 42,
      decoration: BoxDecoration(
        color: qty == 0 ? AppTheme.danger.withAlpha(20) : AppTheme.success.withAlpha(20),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Center(
        child: Text('$qty',
            style: TextStyle(
                fontWeight: FontWeight.w800,
                fontSize: 14,
                color: qty == 0 ? AppTheme.danger : AppTheme.success)),
      ),
    );
  }

  // ---- Individual move modal ----
  void _openMove(
      BuildContext context, String type, Map<String, dynamic> row) {
    final locations = _locations.isNotEmpty
        ? _locations
        : [
            {
              'id': row['location_id'],
              'name': row['location_name'] ?? '—'
            }
          ];
    final qtyCtrl = TextEditingController(text: '1');
    final noteCtrl = TextEditingController();
    String locId = '${row['location_id'] ?? ''}';
    String destId = locations.isNotEmpty ? '${locations[0]['id']}' : '';

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) => Padding(
          padding: EdgeInsets.only(
              left: 18,
              right: 18,
              top: 16,
              bottom: MediaQuery.of(ctx).viewInsets.bottom + 18),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('Movimiento: $type',
                  style: Theme.of(ctx)
                      .textTheme
                      .titleMedium
                      ?.copyWith(fontWeight: FontWeight.w700)),
              const SizedBox(height: 4),
              Text('${row['name'] ?? ''}',
                  style: TextStyle(color: Colors.grey[600], fontSize: 13)),
              const SizedBox(height: 14),
              DropdownButtonFormField<String>(
                value: locId,
                decoration: const InputDecoration(labelText: 'Ubicación'),
                items: locations
                    .map((l) => DropdownMenuItem(
                        value: '${l['id']}',
                        child: Text('${l['name'] ?? ''}')))
                    .toList(),
                onChanged: (v) => setSheet(() => locId = v ?? ''),
              ),
              if (type == 'transferencia')
                DropdownButtonFormField<String>(
                  value: destId,
                  decoration:
                      const InputDecoration(labelText: 'Destino'),
                  items: locations
                      .map((l) => DropdownMenuItem(
                          value: '${l['id']}',
                          child: Text('${l['name'] ?? ''}')))
                      .toList(),
                  onChanged: (v) => setSheet(() => destId = v ?? ''),
                ),
              fField('Cantidad', qtyCtrl,
                  type: TextInputType.number),
              fField('Nota', noteCtrl),
              const SizedBox(height: 12),
              Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                TextButton(
                    onPressed: () => Navigator.pop(ctx),
                    child: const Text('Cancelar')),
                const SizedBox(width: 8),
                FilledButton(
                  onPressed: () async {
                    final qty = num.tryParse(qtyCtrl.text) ?? 0;
                    if (qty <= 0) {
                      U.toast(ctx, 'Cantidad inválida', kind: 'err');
                      return;
                    }
                    Navigator.pop(ctx);
                    final payload = <String, dynamic>{
                      'product_id': row['product_id'],
                      'location_id': locId,
                      'qty': qty,
                      'note': noteCtrl.text.trim(),
                    };
                    if (type == 'transferencia') {
                      payload['from_location'] = locId;
                      payload['to_location'] = destId;
                    } else {
                      payload['type'] = type;
                    }
                    final endpoint = type == 'transferencia'
                        ? 'ws_stock_transfer'
                        : 'ws_stock_move';
                    await U.handlePush(
                        context, SyncService.I.push(endpoint, payload),
                        'Movimiento registrado',
                        onOk: () => _loadAll());
                    // SIEMPRE ajustar stock local (online o encolado)
                    final delta = type == 'entrada'
                        ? qty
                        : (type == 'salida' || type == 'baja'
                            ? -qty
                            : 0);
                    if (delta != 0) {
                      SyncService.I.adjustLocalStock(
                          locId, row['product_id'], delta);
                    }
                    if (type == 'transferencia') {
                      SyncService.I.adjustLocalStock(
                          locId, row['product_id'], -qty);
                      SyncService.I.adjustLocalStock(
                          destId, row['product_id'], qty);
                    }
                    // Guardar movimiento en historial local
                    final now = DateTime.now().toIso8601String();
                    final workerName = AuthService.I.me?['name'] ?? '';
                    final movement = <String, dynamic>{
                      'id': 'local_${DateTime.now().microsecondsSinceEpoch}',
                      'type': type,
                      'product_id': row['product_id'],
                      'product_name': row['name'] ?? '',
                      'qty': qty,
                      'location_id': locId,
                      'location_name': row['location_name'] ?? '',
                      'to_location_id': destId,
                      'note': noteCtrl.text.trim(),
                      'worker_name': workerName,
                      'created_at': now,
                    };
                    await DbService.I.putAll('movements', [movement]);
                    final cached = await DbService.I.cacheGet('ws_movements_list');
                    final list = (cached is List) ? List<Map<String, dynamic>>.from(cached) : <Map<String, dynamic>>[];
                    list.insert(0, movement);
                    await DbService.I.cacheSet('ws_movements_list', list);
                    _loadAll();
                  },
                  child: const Text('Guardar'),
                ),
              ]),
            ],
          ),
        ),
      ),
    );
  }

  // ---- Batch move modal ----
  void _openBatchMove(BuildContext context) {
    final allowed = <String>[];
    if (AuthService.I.has('stock_entry')) allowed.add('entrada');
    if (AuthService.I.has('stock_exit')) allowed.add('salida');
    if (AuthService.I.has('stock_writeoff')) allowed.add('baja');
    if (AuthService.I.has('stock_transfer')) allowed.add('transferencia');
    if (allowed.isEmpty) {
      U.toast(context, 'Sin permisos para movimientos de stock',
          kind: 'err');
      return;
    }

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (ctx) => _BatchMoveSheet(
        allowedTypes: allowed,
        locations: _locations,
        stockRows: _rows,
        initialLoc: _locFilter,
        onSaved: () {
          Navigator.pop(ctx);
          _loadAll();
        },
      ),
    );
  }
}

/// Bottom sheet for batch stock moves.
class _BatchMoveSheet extends StatefulWidget {
  final List<String> allowedTypes;
  final List<Map<String, dynamic>> locations;
  final List<Map<String, dynamic>> stockRows;
  final String initialLoc;
  final VoidCallback onSaved;

  const _BatchMoveSheet({
    required this.allowedTypes,
    required this.locations,
    required this.stockRows,
    required this.initialLoc,
    required this.onSaved,
  });

  @override
  State<_BatchMoveSheet> createState() => _BatchMoveSheetState();
}

class _BatchMoveSheetState extends State<_BatchMoveSheet> {
  late String _type;
  late String _locId;
  late String _destId;
  final _searchCtrl = TextEditingController();
  final _noteCtrl = TextEditingController();
  List<Map<String, dynamic>> _products = [];
  final List<Map<String, dynamic>> _items = [];

  @override
  void initState() {
    super.initState();
    _type = widget.allowedTypes.first;
    _locId = widget.initialLoc.isNotEmpty
        ? widget.initialLoc
        : (widget.locations.isNotEmpty
            ? '${widget.locations.first['id']}'
            : '');
    _destId = widget.locations.length > 1
        ? '${widget.locations[1]['id']}'
        : (widget.locations.isNotEmpty ? '${widget.locations.first['id']}' : '');
    _loadProducts();
  }

  void _loadProducts() {
    if (_type == 'entrada') {
      // All products (non-combo)
      DbService.I.all('products').then((rows) {
        setState(() {
          _products = rows
              .where((p) => '${p['is_combo']}' != '1' && '${p['is_combo']}' != 'true')
              .toList();
        });
      });
    } else {
      // Salida/baja/transferencia: only products with stock in selected location
      setState(() {
        _products = widget.stockRows
            .where((r) =>
                '${r['location_id']}' == _locId &&
                (num.tryParse('${r['qty']}') ?? 0) > 0)
            .toList();
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = _searchCtrl.text.trim().toLowerCase();
    final filtered = s.isEmpty
        ? _products
        : _products.where((p) {
            return '${p['name'] ?? ''}'.toLowerCase().contains(s) ||
                '${p['barcode'] ?? ''}'.toLowerCase().contains(s);
          }).toList();
    final totalItems =
        _items.fold<int>(0, (t, i) => t + ((i['qty'] as int?) ?? 1));

    return Padding(
      padding: EdgeInsets.only(
          left: 18,
          right: 18,
          top: 16,
          bottom: MediaQuery.of(context).viewInsets.bottom + 18),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Nuevo movimiento',
              style: Theme.of(context)
                  .textTheme
                  .titleMedium
                  ?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: 12),
          // Type selector
          Row(
            children: widget.allowedTypes.map((t) {
              final active = _type == t;
              return Expanded(
                child: GestureDetector(
                  onTap: () {
                    setState(() {
                      _type = t;
                      _items.clear();
                      _loadProducts();
                    });
                  },
                  child: Container(
                    margin: const EdgeInsets.symmetric(horizontal: 2),
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    decoration: BoxDecoration(
                      color: active ? AppTheme.primary : Colors.transparent,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(
                          color: active
                              ? AppTheme.primary
                              : AppTheme.primary.withAlpha(80)),
                    ),
                    alignment: Alignment.center,
                    child: Text(
                        t[0].toUpperCase() + t.substring(1),
                        style: TextStyle(
                            fontSize: 13,
                            fontWeight: FontWeight.w600,
                            color:
                                active ? Colors.white : AppTheme.primary)),
                  ),
                ),
              );
            }).toList(),
          ),
          const SizedBox(height: 10),
          // Location
          if (widget.locations.isNotEmpty)
            DropdownButtonFormField<String>(
              value: _locId,
              decoration: const InputDecoration(labelText: 'Ubicación origen'),
              items: widget.locations
                  .map((l) => DropdownMenuItem(
                      value: '${l['id']}', child: Text('${l['name'] ?? ''}')))
                  .toList(),
              onChanged: (v) => setState(() {
                _locId = v ?? '';
                _items.clear();
                _loadProducts();
              }),
            ),
          if (_type == 'transferencia' && widget.locations.length > 1)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: DropdownButtonFormField<String>(
                value: _destId,
                decoration: const InputDecoration(labelText: 'Ubicación destino'),
                items: widget.locations
                    .where((l) => '${l['id']}' != _locId)
                    .map((l) => DropdownMenuItem(
                        value: '${l['id']}', child: Text('${l['name'] ?? ''}')))
                    .toList(),
                onChanged: (v) => setState(() => _destId = v ?? ''),
              ),
            ),
          const SizedBox(height: 8),
          // Search
          TextField(
            controller: _searchCtrl,
            decoration: const InputDecoration(
                hintText: 'Buscar producto…', prefixIcon: Icon(Icons.search, size: 18)),
            onChanged: (_) => setState(() {}),
          ),
          const SizedBox(height: 8),
          // Product list
          SizedBox(
            height: 200,
            child: filtered.isEmpty
                ? const Center(child: Text('Sin productos'))
                : ListView.builder(
                    itemCount: filtered.length > 100 ? 100 : filtered.length,
                    itemBuilder: (ctx, i) {
                      final p = filtered[i];
                      final pid = '${p['id'] ?? p['product_id'] ?? ''}';
                      final item = _items.firstWhere(
                          (x) => '${x['product_id']}' == pid,
                          orElse: () => {});
                      final checked = item.isNotEmpty;
                      final qty = (item['qty'] as int?) ?? 1;
                      return CheckboxListTile(
                        value: checked,
                        dense: true,
                        controlAffinity: ListTileControlAffinity.leading,
                        title: Row(
                          children: [
                            Expanded(
                                child: Text('${p['name'] ?? ''}',
                                    style: const TextStyle(fontSize: 13),
                                    overflow: TextOverflow.ellipsis)),
                            if (_type != 'entrada')
                              Text('stock ${p['qty'] ?? 0}',
                                  style: TextStyle(
                                      fontSize: 11,
                                      color: Colors.grey[500])),
                          ],
                        ),
                        secondary: checked
                            ? SizedBox(
                                width: 70,
                                child: TextField(
                                  keyboardType: TextInputType.number,
                                  controller:
                                      TextEditingController(text: '$qty'),
                                  style: const TextStyle(fontSize: 13),
                                  decoration: const InputDecoration(
                                      isDense: true,
                                      contentPadding: EdgeInsets.symmetric(
                                          horizontal: 6, vertical: 4)),
                                  onChanged: (v) {
                                    final nq = int.tryParse(v) ?? 1;
                                    setState(() {
                                      final idx = _items.indexWhere(
                                          (x) =>
                                              '${x['product_id']}' == pid);
                                      if (idx != -1) _items[idx]['qty'] = nq;
                                    });
                                  },
                                ),
                              )
                            : null,
                        onChanged: (v) {
                          setState(() {
                            if (v == true) {
                              _items.add({'product_id': int.tryParse(pid), 'qty': 1});
                            } else {
                              _items.removeWhere(
                                  (x) => '${x['product_id']}' == pid);
                            }
                          });
                        },
                      );
                    },
                  ),
          ),
          // Summary
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 6),
            child: Text(
              _items.isNotEmpty
                  ? '${_items.length} producto${_items.length > 1 ? 's' : ''} · $totalItems unidades'
                  : 'Selecciona productos para mover.',
              style: TextStyle(
                  fontSize: 13,
                  color: Colors.grey[600],
                  fontWeight: FontWeight.w600),
            ),
          ),
          fField('Nota', _noteCtrl, hint: 'Opcional'),
          const SizedBox(height: 8),
          Row(mainAxisAlignment: MainAxisAlignment.end, children: [
            TextButton(
                onPressed: () => Navigator.pop(context),
                child: const Text('Cancelar')),
            const SizedBox(width: 8),
            FilledButton(
              onPressed: _items.isEmpty
                  ? null
                  : () async {
                      if (_locId.isEmpty) {
                        U.toast(context, 'Selecciona una ubicación',
                            kind: 'err');
                        return;
                      }
                      if (_type == 'transferencia' && _destId.isEmpty) {
                        U.toast(context, 'Selecciona ubicación destino',
                            kind: 'err');
                        return;
                      }
                      final endpoint =
                          _type == 'transferencia' ? 'ws_stock_transfer' : 'ws_stock_batch_move';
                      final payload = <String, dynamic>{
                        'type': _type,
                        'location_id': _locId,
                        'items': _items,
                        'note': _noteCtrl.text.trim(),
                      };
                      if (_type == 'transferencia') {
                        payload['from_location'] = _locId;
                        payload['to_location'] = _destId;
                      }
                      await U.handlePush(
                          context,
                          SyncService.I.push(endpoint, payload),
                          'Movimiento registrado',
                          onOk: () => SyncService.I.pullStore('ws_stock_list', {'limit': 1000, 'pageSize': 1000}, 'stock', cacheKey: 'ws_stock_list', dataKey: 'rows'));
                      // SIEMPRE ajustar stock local (online o encolado/offline)
                      // para que POS y stock se actualicen de inmediato.
                      for (final it in _items) {
                        final qty = (it['qty'] as int?) ?? 1;
                        if (_type == 'transferencia') {
                          SyncService.I.adjustLocalStock(
                              _locId, it['product_id'], -qty);
                          SyncService.I.adjustLocalStock(
                              _destId, it['product_id'], qty);
                        } else {
                          final delta = _type == 'entrada' ? qty : -qty;
                          SyncService.I.adjustLocalStock(
                              _locId, it['product_id'], delta);
                        }
                      }
                      // Guardar movimiento en historial local
                      await _saveLocalMovement(_type, _items, _locId,
                          _type == 'transferencia' ? _destId : '', _noteCtrl.text.trim());
                      widget.onSaved();
                    },
              child: const Text('Guardar movimiento'),
            ),
           ]),
        ],
      ),
    );
  }

  /// Guarda un movimiento en SQLite local para historial offline.
  Future<void> _saveLocalMovement(String type, List<Map<String, dynamic>> items,
      String fromLoc, String toLoc, String note) async {
    try {
      final now = DateTime.now().toIso8601String();
      final workerName = AuthService.I.me?['name'] ?? '';
      final movement = <String, dynamic>{
        'id': 'local_${DateTime.now().microsecondsSinceEpoch}',
        'type': type,
        'product_id': items.isNotEmpty ? items.first['product_id'] : null,
        'product_name': items.map((i) => '${i['name'] ?? i['product_id'] ?? ''}').join(', '),
        'qty': items.fold<int>(0, (t, i) => t + ((i['qty'] as int?) ?? 1)),
        'location_id': fromLoc,
        'location_name': '',
        'to_location_id': toLoc,
        'note': note,
        'worker_name': workerName,
        'created_at': now,
        'items': items,
      };
      final locs = await DbService.I.all('locations');
      for (final l in locs) {
        if ('${l['id']}' == fromLoc) movement['location_name'] = l['name'] ?? '';
      }
      await DbService.I.putAll('movements', [movement]);
      final cached = await DbService.I.cacheGet('ws_movements_list');
      final list = (cached is List) ? List<Map<String, dynamic>>.from(cached) : <Map<String, dynamic>>[];
      list.insert(0, movement);
      await DbService.I.cacheSet('ws_movements_list', list);
    } catch (_) {}
  }
}
