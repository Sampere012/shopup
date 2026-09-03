import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';

/// Ventas POS con filtros (ubicación, pago, estado), stats, búsqueda
/// y detalle inline usando acordeones (ExpansionTile).
class PosSalesScreen extends StatefulWidget {
  const PosSalesScreen({super.key});
  @override
  State<PosSalesScreen> createState() => _PosSalesScreenState();
}

class _PosSalesScreenState extends State<PosSalesScreen> {
  List<Map<String, dynamic>> _allSales = [];
  List<Map<String, dynamic>> _locations = [];
  Map<String, dynamic> _stats = {};
  int _locId = 0;
  String _payFilter = '';
  String _statusFilter = '';
  String _search = '';
  bool _loading = true;
  DateTime? _dateFrom;
  DateTime? _dateTo;

  /// Expand/collapse all — persisted in static so it survives screen changes.
  static bool _allExpanded = false;

  static const _payLabels = {
    'cash': 'Efectivo',
    'transfer': 'Transferencia',
    'card': 'Tarjeta',
    'mixed': 'Mixto',
  };
  static const _statusLabels = {
    'completed': 'Completada',
    'pending': 'Pendiente',
    'cancelled': 'Cancelada',
  };

  @override
  void initState() {
    super.initState();
    _loadAll();
    SyncService.I.onChange(_onSync);
  }

  void _onSync() {
    if (mounted) _loadAll();
  }

  Future<void> _loadAll() async {
    final salesCache = await DbService.I.cacheGet('ws_pos_sales_get_all');
    if (salesCache is List) {
      _allSales = salesCache.whereType<Map>().toList().cast<Map<String, dynamic>>();
    }
    final statsCache = await DbService.I.cacheGet('ws_pos_stats');
    if (statsCache is Map) _stats = Map<String, dynamic>.from(statsCache);
    final locs = await DbService.I.all('locations');
    _locations = locs;
    setState(() => _loading = false);
    _refreshFromServer();
  }

  Future<void> _refreshFromServer() async {
    if (SyncService.I.isPulling) return;
    try {
      final results = await Future.wait([
        ApiService.I.req('ws_pos_sales_get', {
          'location_id': 0,
          'status': '',
          'search': '',
          'limit': 500,
          'offset': 0,
        }),
        ApiService.I.req('ws_pos_stats', {}),
      ], eagerError: true);
      final salesData = results[0];
      final statsData = results[1];
      final rows =
          List<Map<String, dynamic>>.from((salesData['data'] as List?) ?? []);
      if (rows.isNotEmpty) {
        _allSales = rows;
        await DbService.I.cacheSet('ws_pos_sales_get', rows);
        await DbService.I.cacheSet('ws_pos_sales_get_all', rows);
        await DbService.I.putAll('pos_sales', rows);
      }
      // Re-inyectar ventas encoladas que el servidor aún no tiene
      final pending = await DbService.I.pending();
      final queuedSales = pending.where((op) => op['action'] == 'ws_pos_sale_save').toList();
      if (queuedSales.isNotEmpty) {
        final sales = _allSales.toList();
        for (final op in queuedSales) {
          final data = (op['data'] is Map) ? Map<String, dynamic>.from(op['data'] as Map) : <String, dynamic>{};
          final localSale = <String, dynamic>{
            'id': op['id'],
            'location_id': data['location_id'],
            'currency': data['currency'],
            'subtotal': data['subtotal'],
            'discount': data['discount'],
            'total': data['total'],
            'payment_method': data['payment_method'],
            'customer_name': data['customer_name'],
            'status': 'pending',
            'created_at': data['created_at'] ?? DateTime.now().toIso8601String(),
            'items': data['items'],
          };
          sales.insert(0, localSale);
        }
        _allSales = sales;
        await DbService.I.cacheSet('ws_pos_sales_get', sales);
        await DbService.I.cacheSet('ws_pos_sales_get_all', sales);
      }
      _stats = Map<String, dynamic>.from((statsData['data'] as Map?) ?? {});
      await DbService.I.cacheSet('ws_pos_stats', _stats);
      if (mounted) setState(() {});
    } catch (_) {}
  }

  List<Map<String, dynamic>> get _filtered {
    var list = _allSales.toList();
    if (_locId != 0) {
      list = list.where((s) => '${s['location_id']}' == '$_locId').toList();
    }
    if (_payFilter.isNotEmpty) {
      list = list.where((s) => s['payment_method'] == _payFilter).toList();
    }
    if (_statusFilter.isNotEmpty) {
      list = list.where((s) => s['status'] == _statusFilter).toList();
    }
    if (_dateFrom != null) {
      list = list.where((s) {
        final d = DateTime.tryParse('${s['created_at'] ?? ''}');
        return d != null && !d.isBefore(_dateFrom!);
      }).toList();
    }
    if (_dateTo != null) {
      final end = _dateTo!.add(const Duration(days: 1));
      list = list.where((s) {
        final d = DateTime.tryParse('${s['created_at'] ?? ''}');
        return d != null && d.isBefore(end);
      }).toList();
    }
    if (_search.isNotEmpty) {
      final q = _search.toLowerCase();
      list = list.where((s) {
        return '${s['number'] ?? s['id'] ?? ''}'.toLowerCase().contains(q) ||
            '${s['customer_name'] ?? ''}'.toLowerCase().contains(q) ||
            '${s['seller_name'] ?? ''}'.toLowerCase().contains(q);
      }).toList();
    }
    return list;
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final cur = AuthService.I.currency;
    final sales = _filtered;

    // Stats
    final st = _stats['sales'] ?? _stats;
    final totalCount = st['count'] ?? st['total_count'] ?? st['orders'] ?? 0;
    final totalRevenue = st['total'] ?? st['total_revenue'] ?? 0;
    final avgSale = st['average'] ?? st['average_sale'] ?? st['avg_sale'] ?? 0;

    return Column(
      children: [
        // Search
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
          child: TextField(
            decoration: InputDecoration(
              hintText: 'Buscar venta…',
              prefixIcon: const Icon(Icons.search, size: 18),
              isDense: true,
              filled: true,
              fillColor: isDark ? AppTheme.darkSurface : Colors.white,
            ),
            onChanged: (v) => setState(() => _search = v.trim()),
          ),
        ),
        // Date range filter
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
          child: Row(children: [
            Expanded(
              child: GestureDetector(
                onTap: () async {
                  final d = await showDatePicker(
                      context: context,
                      initialDate: _dateFrom ?? DateTime.now(),
                      firstDate: DateTime(2020),
                      lastDate: DateTime.now());
                  if (d != null) setState(() => _dateFrom = d);
                },
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: Colors.grey.withAlpha(60)),
                  ),
                  child: Text(
                      _dateFrom != null
                          ? 'Desde: ${_dateFrom!.day}/${_dateFrom!.month}/${_dateFrom!.year}'
                          : 'Desde',
                      style: TextStyle(
                          fontSize: 12,
                          color: _dateFrom != null
                              ? (isDark ? Colors.white : Colors.black87)
                              : Colors.grey[500])),
                ),
              ),
            ),
            const SizedBox(width: 6),
            Expanded(
              child: GestureDetector(
                onTap: () async {
                  final d = await showDatePicker(
                      context: context,
                      initialDate: _dateTo ?? DateTime.now(),
                      firstDate: DateTime(2020),
                      lastDate: DateTime.now());
                  if (d != null) setState(() => _dateTo = d);
                },
                child: Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 10),
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: Colors.grey.withAlpha(60)),
                  ),
                  child: Text(
                      _dateTo != null
                          ? 'Hasta: ${_dateTo!.day}/${_dateTo!.month}/${_dateTo!.year}'
                          : 'Hasta',
                      style: TextStyle(
                          fontSize: 12,
                          color: _dateTo != null
                              ? (isDark ? Colors.white : Colors.black87)
                              : Colors.grey[500])),
                ),
              ),
            ),
            if (_dateFrom != null || _dateTo != null)
              IconButton(
                icon: const Icon(Icons.clear, size: 18),
                onPressed: () => setState(() {
                  _dateFrom = null;
                  _dateTo = null;
                }),
              ),
          ]),
        ),
        // Filters
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
          child: Row(
            children: [
              Expanded(
                child: _filterDropdown(
                  'Ubicación',
                  _locId,
                  [
                    const DropdownMenuItem(
                        value: 0, child: Text('Todas', style: TextStyle(fontSize: 12))),
                    ..._locations.map((l) => DropdownMenuItem(
                        value: int.tryParse('${l['id']}') ?? 0,
                        child: Text('${l['name'] ?? ''}',
                            style: const TextStyle(fontSize: 12)))),
                  ],
                  (v) => setState(() => _locId = v ?? 0),
                ),
              ),
              const SizedBox(width: 6),
              Expanded(
                child: _filterDropdown(
                  'Pago',
                  _payFilter,
                  [
                    const DropdownMenuItem(
                        value: '', child: Text('Todos', style: TextStyle(fontSize: 12))),
                    ..._payLabels.entries.map((e) => DropdownMenuItem(
                        value: e.key,
                        child: Text(e.value, style: const TextStyle(fontSize: 12)))),
                  ],
                  (v) => setState(() => _payFilter = v ?? ''),
                ),
              ),
              const SizedBox(width: 6),
              Expanded(
                child: _filterDropdown(
                  'Estado',
                  _statusFilter,
                  [
                    const DropdownMenuItem(
                        value: '', child: Text('Todos', style: TextStyle(fontSize: 12))),
                    ..._statusLabels.entries.map((e) => DropdownMenuItem(
                        value: e.key,
                        child: Text(e.value, style: const TextStyle(fontSize: 12)))),
                  ],
                  (v) => setState(() => _statusFilter = v ?? ''),
                ),
              ),
            ],
          ),
        ),
        // Stats
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 10, 14, 0),
          child: Row(
            children: [
              _miniStat(Icons.receipt_long, '$totalCount', 'Ventas'),
              const SizedBox(width: 8),
              _miniStat(Icons.attach_money,
                  U.money(num.tryParse('$totalRevenue') ?? 0, cur, dec: 0), 'Total'),
              const SizedBox(width: 8),
              _miniStat(Icons.trending_up,
                  U.money(num.tryParse('$avgSale') ?? 0, cur, dec: 0), 'Ticket medio'),
            ],
          ),
        ),
        // Count + Expand/collapse toggle
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 8, 14, 0),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text('Ventas',
                  style:
                      TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: Colors.grey[600])),
              Row(children: [
                Text('${sales.length} venta${sales.length != 1 ? 's' : ''}',
                    style: TextStyle(fontSize: 12, color: Colors.grey[500])),
                const SizedBox(width: 8),
                GestureDetector(
                  onTap: () => setState(() => _allExpanded = !_allExpanded),
                  child: Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: AppTheme.primary.withAlpha(15),
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      Icon(_allExpanded ? Icons.unfold_less : Icons.unfold_more,
                          size: 14, color: AppTheme.primary),
                      const SizedBox(width: 4),
                      Text(_allExpanded ? 'Plegar' : 'Desplegar',
                          style: TextStyle(
                              fontSize: 11, fontWeight: FontWeight.w600, color: AppTheme.primary)),
                    ]),
                  ),
                ),
              ]),
            ],
          ),
        ),
        // List
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : sales.isEmpty
                  ? Center(
                      child: Text('Sin ventas con estos filtros.',
                          style: TextStyle(color: Colors.grey[500])),
                    )
                  : RefreshIndicator(
                      onRefresh: () async => _loadAll(),
                      child: ListView.builder(
                        padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
                        itemCount: sales.length,
                        itemBuilder: (context, i) => _saleAccordion(sales[i]),
                      ),
                    ),
        ),
      ],
    );
  }

  Widget _filterDropdown<T>(
      String hint, T value, List<DropdownMenuItem<T>> items, ValueChanged<T?> onChanged) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: Colors.grey.withAlpha(60)),
      ),
      child: DropdownButtonHideUnderline(
        child: DropdownButton<T>(
          value: value,
          isExpanded: true,
          isDense: true,
          style: TextStyle(
              fontSize: 12, color: isDark ? Colors.white : Colors.black87),
          dropdownColor: isDark ? AppTheme.darkCard : Colors.white,
          icon: Icon(Icons.expand_more,
              size: 18, color: isDark ? Colors.white70 : Colors.black54),
          hint: Text(hint, style: const TextStyle(fontSize: 11)),
          items: items,
          onChanged: onChanged,
        ),
      ),
    );
  }

  Widget _miniStat(IconData icon, String value, String label) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 6),
        decoration: BoxDecoration(
          color: AppTheme.primary.withAlpha(10),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Column(
          children: [
            Icon(icon, size: 16, color: AppTheme.primary),
            const SizedBox(height: 2),
            Text(value,
                style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w800)),
            Text(label, style: TextStyle(fontSize: 10, color: Colors.grey[500])),
          ],
        ),
      ),
    );
  }

  /// Sale tile as an accordion (ExpansionTile).
  Widget _saleAccordion(Map<String, dynamic> s) {
    final total = num.tryParse('${s['total']}') ?? 0;
    final cur = s['currency'] ?? AuthService.I.currency;
    final payLabel = _payLabels[s['payment_method']] ?? s['payment_method'] ?? 'Pago';
    final statusLabel = _statusLabels[s['status']] ?? s['status'] ?? '';
    final statusColor = s['status'] == 'completed'
        ? AppTheme.success
        : s['status'] == 'pending'
            ? AppTheme.amber
            : AppTheme.danger;

    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ExpansionTile(
        // La key incluye _allExpanded para que al pulsar Desplegar/Plegar los
        // tiles se RECREEN con el nuevo estado (initiallyExpanded solo aplica
        // a la creación; sin esto el botón no afecta a los ya construidos).
        key: ValueKey('sale-${s['id']}-$_allExpanded'),
        initiallyExpanded: _allExpanded,
        tilePadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
        childrenPadding: const EdgeInsets.fromLTRB(12, 0, 12, 12),
        leading: Container(
          width: 38,
          height: 38,
          decoration: BoxDecoration(
            color: AppTheme.primary.withAlpha(20),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Center(
            child: Text(payLabel[0],
                style: const TextStyle(
                    color: AppTheme.primary, fontWeight: FontWeight.w800, fontSize: 16)),
          ),
        ),
        title: Row(
          children: [
            Expanded(
              child: Text('#${s['number'] ?? s['id'] ?? ''}',
                  style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                  overflow: TextOverflow.ellipsis),
            ),
            U.badge(statusLabel, color: statusColor, small: true),
          ],
        ),
        subtitle: Text(
          '${U.fmtDate(s['created_at'])} · $payLabel'
          '${'${s['seller_name'] ?? ''}'.isNotEmpty ? ' · ${s['seller_name']}' : ''}'
          '${'${s['location_name'] ?? ''}'.isNotEmpty ? ' · ${s['location_name']}' : ''}',
          style: TextStyle(color: Colors.grey[600], fontSize: 11),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
        ),
        trailing: Text(U.money(total, cur),
            style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14)),
        children: [
          // Detail section (inline, no drawer/sheet)
          _SaleInlineDetail(sale: s),
        ],
      ),
    );
  }
}

/// Inline detail of a sale shown inside the accordion.
class _SaleInlineDetail extends StatefulWidget {
  final Map<String, dynamic> sale;
  const _SaleInlineDetail({required this.sale});
  @override
  State<_SaleInlineDetail> createState() => _SaleInlineDetailState();
}

class _SaleInlineDetailState extends State<_SaleInlineDetail> {
  List<Map<String, dynamic>> _items = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _loadItems();
  }

  Future<void> _loadItems() async {
    final saleId = int.tryParse('${widget.sale['id']}') ?? 0;
    // Los ítems vienen embebidos en la venta cuando se creó localmente
    // (offline/pending o recién guardada): usarlos de respaldo para que el
    // detalle no diga «Sin ítems» aunque el servidor aún no tenga el id.
    final localItems = (widget.sale['items'] is List)
        ? List<Map<String, dynamic>>.from(
            (widget.sale['items'] as List).whereType<Map>())
        : <Map<String, dynamic>>[];
    if (localItems.isNotEmpty) {
      _items = localItems;
      setState(() => _loading = false);
    }
    // Try cache first
    final cached =
        await DbService.I.cacheGet('ws_pos_sale_items_get:${widget.sale['id']}');
    if (cached is List && cached.isNotEmpty) {
      _items = cached.whereType<Map>().toList().cast<Map<String, dynamic>>();
      setState(() => _loading = false);
    }
    // Refresh from server (solo si el id es numérico/persistente)
    if (saleId <= 0) {
      setState(() => _loading = false);
      return;
    }
    try {
      final d = await ApiService.I.req('ws_pos_sale_items_get', {
        'sale_id': saleId,
      });
      final rows = List<Map<String, dynamic>>.from(
          (d['items'] as List?) ?? (d['data'] as List?) ?? []);
      if (rows.isNotEmpty) _items = rows;
      await DbService.I.cacheSet('ws_pos_sale_items_get:${widget.sale['id']}', rows);
      if (mounted) setState(() => _loading = false);
    } catch (_) {
      if (_loading) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = widget.sale;
    final cur = s['currency'] ?? AuthService.I.currency;
    final payLabels = {
      'cash': 'Efectivo',
      'transfer': 'Transferencia',
      'card': 'Tarjeta',
      'mixed': 'Mixto',
    };
    final payLabel = payLabels[s['payment_method']] ?? s['payment_method'] ?? 'Pago';

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Meta info
        _meta('Vendedor', '${s['seller_name'] ?? '—'}'),
        if ('${s['customer_name'] ?? ''}'.isNotEmpty)
          _meta('Cliente',
              '${s['customer_name']}${'${s['customer_doc'] ?? ''}'.isNotEmpty ? ' · ${s['customer_doc']}' : ''}'),
        _meta('Pago',
            '$payLabel${'${s['transfer_number'] ?? ''}'.isNotEmpty ? ' · TRX ${s['transfer_number']}' : ''}'),
        if ('${s['location_name'] ?? ''}'.isNotEmpty)
          _meta('Ubicación', '${s['location_name']}'),
        const SizedBox(height: 8),
        // Items
        Text('Productos',
            style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: Colors.grey[600])),
        const SizedBox(height: 4),
        if (_loading)
          const Padding(
            padding: EdgeInsets.all(12),
            child: Center(
                child: SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2))),
          )
        else if (_items.isEmpty)
          const Text('Sin ítems.', style: TextStyle(fontSize: 13))
        else
          ..._items.map((it) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(
                  children: [
                    Expanded(
                        child: Text('${it['product_name'] ?? it['name'] ?? ''}',
                            style: const TextStyle(fontSize: 13))),
                    Text('×${it['qty'] ?? 0}',
                        style: TextStyle(fontSize: 12, color: Colors.grey[500])),
                    const SizedBox(width: 10),
                    Text(
                        U.money((num.tryParse('${it['price']}') ?? 0) * (num.tryParse('${it['qty']}') ?? 1), cur),
                        style:
                            const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
                  ],
                ),
              )),
        const SizedBox(height: 8),
        // Total
        Row(
          mainAxisAlignment: MainAxisAlignment.end,
          children: [
            Text(
                'Total: ${U.money(num.tryParse('${s['total']}') ?? 0, cur)}',
                style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w800)),
          ],
        ),
      ],
    );
  }

  Widget _meta(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: [
          Text('$label: ', style: TextStyle(fontSize: 12, color: Colors.grey[500])),
          Expanded(
              child: Text(value,
                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600))),
        ],
      ),
    );
  }
}
