import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';

/// Reportes idénticos a la web: filtros (ubicación + rango de fechas),
/// KPI, ventas por moneda, utilidades mensuales, utilidad por PV,
/// ventas por día, movimientos por tipo y top productos.
class ReportsScreen extends StatefulWidget {
  const ReportsScreen({super.key});
  @override
  State<ReportsScreen> createState() => _ReportsScreenState();
}

class _ReportsScreenState extends State<ReportsScreen> {
  int _period = 14;
  int _locId = 0;
  String _dateFrom = '';
  String _dateTo = '';
  List<Map<String, dynamic>> _locations = [];
  Map<String, dynamic> _data = {};
  bool _loading = true;
  int _pendingPosCount = 0;
  num _pendingPosTotal = 0;

  static const _periods = <int, String>{
    0: 'Todo',
    7: '7 días',
    14: '14 días',
    30: '30 días',
    90: '90 días',
  };

  @override
  void initState() {
    super.initState();
    _loadLocations();
    _load();
    SyncService.I.onChange(_onSync);
  }

  @override
  void dispose() {
    SyncService.I.removeOnChange(_onSync);
    super.dispose();
  }

  void _onSync() {
    if (mounted) _load();
  }

  Future<void> _loadLocations() async {
    final locs = await DbService.I.all('locations');
    if (mounted) {
      final workerLocs = AuthService.I.locationIds;
      setState(() {
        _locations = workerLocs.isNotEmpty
            ? locs.where((l) => workerLocs.contains(int.tryParse('${l['id']}') ?? 0)).toList()
            : locs;
      });
    }
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    try {
      final params = <String, dynamic>{
        'ws_loc': _locId,
      };
      // Date range takes priority over period
      if (_dateFrom.isNotEmpty && _dateTo.isNotEmpty) {
        params['ws_from'] = _dateFrom;
        params['ws_to'] = _dateTo;
      } else {
        params['ws_period'] = _period;
      }
      final d = await ApiService.I.req('ws_reports_summary', params, timeoutSec: 30);
      // API returns {success:true, data:{data:{...}}}
      final outer = (d['data'] as Map?) ?? {};
      final data = Map<String, dynamic>.from((outer['data'] as Map?) ?? outer);
      await DbService.I.cacheSet('ws_reports_summary:$_period:$_locId', data);
      await _mergePendingPosSales(data);
      if (mounted) setState(() { _data = data; _loading = false; });
    } catch (_) {
      final cached = await DbService.I.cacheGet('ws_reports_summary:$_period:$_locId');
      if (cached is Map) {
        final c = Map<String, dynamic>.from(cached);
        await _mergePendingPosSales(c);
        if (mounted) setState(() { _data = c; _loading = false; });
      } else if (mounted) {
        setState(() => _loading = false);
      }
    }
  }

  /// Merge queued POS sales into report data for offline preview.
  Future<void> _mergePendingPosSales(Map<String, dynamic> data) async {
    final pending = await DbService.I.pending();
    final posSales = pending.where((op) => op['action'] == 'ws_pos_sale_save').toList();
    if (posSales.isEmpty) {
      _pendingPosCount = 0;
      _pendingPosTotal = 0;
      return;
    }
    // Aggregate totals from queued sales
    num totalAdd = 0;
    int ordersAdd = 0;
    final curTotals = <String, num>{};
    final curCounts = <String, int>{};
    final daySales = <String, num>{};
    final dayCounts = <String, int>{};
    for (final op in posSales) {
      final d = (op['data'] is Map) ? Map<String, dynamic>.from(op['data'] as Map) : <String, dynamic>{};
      final total = num.tryParse('${d['total']}') ?? 0;
      final currency = '${d['currency'] ?? data['currency'] ?? ''}';
      final createdAt = '${d['created_at'] ?? ''}';
      // Sales totals
      totalAdd += total;
      ordersAdd++;
      curTotals[currency] = (curTotals[currency] ?? 0) + total;
      curCounts[currency] = (curCounts[currency] ?? 0) + 1;
      // Group by day (extract date portion)
      if (createdAt.isNotEmpty) {
        final dayKey = createdAt.length >= 10 ? createdAt.substring(0, 10) : createdAt;
        daySales[dayKey] = (daySales[dayKey] ?? 0) + total;
        dayCounts[dayKey] = (dayCounts[dayKey] ?? 0) + 1;
      }
    }
    _pendingPosCount = ordersAdd;
    _pendingPosTotal = totalAdd;
    // Merge into total_sales / total_orders
    data['total_sales'] = (num.tryParse('${data['total_sales']}') ?? 0) + totalAdd;
    data['total_orders'] = (num.tryParse('${data['total_orders']}') ?? 0) + ordersAdd;
    // Merge into currency_totals
    final existingCt = (data['currency_totals'] as List?) ?? [];
    final ctMap = <String, dynamic>{};
    for (final ct in existingCt) {
      ctMap['${ct['currency']}'] = ct;
    }
    for (final e in curTotals.entries) {
      final cur = e.key;
      final existing = ctMap[cur];
      if (existing != null) {
        existing['total'] = (num.tryParse('${existing['total']}') ?? 0) + e.value;
        existing['n'] = (existing['n'] ?? 0) + (curCounts[cur] ?? 0);
      } else {
        ctMap[cur] = {'currency': cur, 'total': e.value, 'n': curCounts[cur] ?? 0};
      }
    }
    data['currency_totals'] = ctMap.values.toList();
    // Merge into sales (by day)
    final existingSales = (data['sales'] as List?) ?? [];
    final salesMap = <String, dynamic>{};
    for (final s in existingSales) {
      final key = '${s['d'] ?? s['date'] ?? ''}';
      salesMap[key] = s;
    }
    for (final e in daySales.entries) {
      final day = e.key;
      final existing = salesMap[day];
      if (existing != null) {
        existing['total'] = (num.tryParse('${existing['total']}') ?? 0) + e.value;
        existing['n'] = (existing['n'] ?? 0) + (dayCounts[day] ?? 0);
      } else {
        salesMap[day] = {'d': day, 'n': dayCounts[day] ?? 0, 'total': e.value};
      }
    }
    data['sales'] = salesMap.values.toList();
    // Also merge into pos_sales SQLite for local reads
    final localSales = posSales.map((op) {
      final d = (op['data'] is Map) ? Map<String, dynamic>.from(op['data'] as Map) : <String, dynamic>{};
      d['id'] = op['id'];
      return d;
    }).toList();
    await DbService.I.putAll('pos_sales', localSales);
  }

  void _resetFilters() {
    setState(() {
      _dateFrom = '';
      _dateTo = '';
      _period = 14;
      _locId = 0;
    });
    _load();
  }

  Future<void> _pickDate(bool isFrom) async {
    final now = DateTime.now();
    final initial = isFrom
        ? (_dateFrom.isNotEmpty ? DateTime.tryParse(_dateFrom) ?? now : now)
        : (_dateTo.isNotEmpty ? DateTime.tryParse(_dateTo) ?? now : now);
    final picked = await showDatePicker(
      context: context,
      initialDate: initial,
      firstDate: DateTime(2020),
      lastDate: now,
    );
    if (picked != null) {
      final y = picked.year;
      final m = picked.month.toString().padLeft(2, '0');
      final d = picked.day.toString().padLeft(2, '0');
      setState(() {
        if (isFrom) {
          _dateFrom = '$y-$m-$d';
        } else {
          _dateTo = '$y-$m-$d';
        }
      });
      _load();
    }
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Column(children: [
      // ── Filters ──
      Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          // Period chips
          SizedBox(
            height: 38,
            child: ListView(
              scrollDirection: Axis.horizontal,
              children: _periods.entries.map((e) {
                final selected = e.key == _period && _dateFrom.isEmpty;
                return Padding(
                  padding: const EdgeInsets.only(right: 6),
                  child: ChoiceChip(
                    label: Text(e.value, style: const TextStyle(fontSize: 12)),
                    selected: selected,
                    onSelected: (_) {
                      setState(() { _period = e.key; _dateFrom = ''; _dateTo = ''; });
                      _load();
                    },
                    visualDensity: VisualDensity.compact,
                  ),
                );
              }).toList(),
            ),
          ),
          const SizedBox(height: 8),
          // Location dropdown
          if (_locations.isNotEmpty)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 10),
              decoration: BoxDecoration(
                color: isDark ? AppTheme.darkSurface : Colors.white,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: isDark ? Colors.white.withAlpha(20) : Colors.black.withAlpha(20)),
              ),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<int>(
                  value: _locId,
                  isExpanded: true,
                  isDense: true,
                  items: [
                    const DropdownMenuItem(value: 0, child: Text('Todas las ubicaciones', style: TextStyle(fontSize: 12))),
                    ..._locations.map((l) => DropdownMenuItem(
                      value: int.tryParse('${l['id']}') ?? 0,
                      child: Text('${l['name'] ?? ''}', style: const TextStyle(fontSize: 12)),
                    )),
                  ],
                  onChanged: (v) { setState(() { _locId = v ?? 0; }); _load(); },
                ),
              ),
            ),
          const SizedBox(height: 8),
          // Date range picker
          Row(children: [
            Expanded(
              child: _dateButton(_dateFrom.isEmpty ? 'Desde' : _dateFrom, () => _pickDate(true)),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _dateButton(_dateTo.isEmpty ? 'Hasta' : _dateTo, () => _pickDate(false)),
            ),
            const SizedBox(width: 8),
            IconButton(
              onPressed: _resetFilters,
              icon: const Icon(Icons.rotate_left, size: 20),
              tooltip: 'Últimos 14 días',
            ),
          ]),
        ]),
      ),

      // ── Content ──
      Expanded(
        child: _loading
            ? const Center(child: CircularProgressIndicator())
            : _data.isEmpty
                ? Center(child: Text('Sin datos de reportes.',
                    style: TextStyle(color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted)))
                : RefreshIndicator(onRefresh: _load, child: _buildContent(isDark)),
      ),
    ]);
  }

  Widget _dateButton(String label, VoidCallback onTap) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
        decoration: BoxDecoration(
          border: Border.all(color: AppTheme.lightBorder),
          borderRadius: BorderRadius.circular(10),
        ),
        child: Text(label, style: TextStyle(fontSize: 13, color: label.startsWith('2') ? null : Colors.grey[500])),
      ),
    );
  }

  Widget _buildContent(bool isDark) {
    final cur = _data['currency'] ?? AuthService.I.currency;
    final totalSales = num.tryParse('${_data['total_sales']}') ?? 0;
    final totalOrders = (num.tryParse('${_data['total_orders']}') ?? 0).toInt();
    final totalMoves = (num.tryParse('${_data['total_moves']}') ?? 0).toInt();
    final currencyTotals = (_data['currency_totals'] as List?) ?? [];
    final topAll = (_data['top_all'] as List?) ?? [];
    final bottom = (_data['bottom'] as List?) ?? [];
    final byType = (_data['by_type'] as List?) ?? [];
    final sales = (_data['sales'] as List?) ?? [];
    final transactions = (_data['transactions'] as List?) ?? [];
    final posProducts = (_data['pos_products'] as List?) ?? [];
    final posSales = (_data['pos_sales'] as List?) ?? [];
    final utils = Map<String, dynamic>.from(_data['utils'] ?? {});
    final periodLabel = (_data['filters'] as Map?)?['period_label'] ?? '$_period días';

    return ListView(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 90),
      children: [
        // Currency indicator
        _sectionHeader('Moneda del reporte: $cur', Icons.monetization_on_outlined, isDark),

        // ── Pending POS sales indicator ──
        if (_pendingPosCount > 0) ...[
          Container(
            margin: const EdgeInsets.only(bottom: 10),
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              gradient: LinearGradient(colors: [AppTheme.amber.withAlpha(25), AppTheme.amber.withAlpha(12)]),
              borderRadius: BorderRadius.circular(10),
              border: Border.all(color: AppTheme.amber.withAlpha(60)),
            ),
            child: Row(children: [
              const Icon(Icons.cloud_upload_outlined, size: 16, color: AppTheme.amber),
              const SizedBox(width: 8),
              Expanded(child: Text(
                'Incluye $_pendingPosCount venta(s) pendiente(s) · ${U.money(_pendingPosTotal, cur, dec: 0)}',
                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: AppTheme.amber),
              )),
            ]),
          ),
        ],

        // ── KPI Cards ──
        GridView.count(
          crossAxisCount: 3,
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          mainAxisSpacing: 8,
          crossAxisSpacing: 8,
          childAspectRatio: 1.1,
          children: [
            U.gradientStat(
              icon: Icons.trending_up,
              value: U.money(totalSales, cur, dec: 0),
              label: 'Ventas · $periodLabel',
              colors: [AppTheme.success, const Color(0xFF34D399)],
            ),
            U.gradientStat(
              icon: Icons.receipt_long_outlined,
              value: '$totalOrders',
              label: 'Pedidos',
              colors: [AppTheme.primary, AppTheme.primaryDark],
            ),
            U.gradientStat(
              icon: Icons.swap_horiz,
              value: '$totalMoves',
              label: 'Movimientos',
              colors: [AppTheme.amber, const Color(0xFFF59E0B)],
            ),
          ],
        ),
        const SizedBox(height: 16),

        // ── Resumen POS ──
        if (_data['pos_summary'] is Map) ...[
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              gradient: LinearGradient(colors: [
                AppTheme.purple.withAlpha(30),
                AppTheme.purple.withAlpha(12),
              ]),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppTheme.purple.withAlpha(60)),
            ),
            child: Row(children: [
              Container(
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: AppTheme.purple.withAlpha(25),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: const Icon(Icons.storefront_outlined,
                    color: AppTheme.purple, size: 22),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                          'Ventas POS · ${U.money(num.tryParse('${_data['pos_summary']['total'] ?? 0}') ?? 0, cur, dec: 0)}',
                          style: const TextStyle(
                              fontSize: 15, fontWeight: FontWeight.w800)),
                      const SizedBox(height: 2),
                      Text(
                          '${_data['pos_summary']['orders'] ?? 0} ventas · ticket promedio '
                          '${U.money(num.tryParse('${_data['pos_summary']['average'] ?? 0}') ?? 0, cur, dec: 0)}',
                          style: TextStyle(
                              fontSize: 12, color: Colors.grey[600])),
                    ]),
              ),
            ]),
          ),
          const SizedBox(height: 16),
        ],

        // ── Ventas por moneda ──
        if (currencyTotals.isNotEmpty) ...[
          const SizedBox(height: 16),
          _sectionTitle('Ventas por moneda', Icons.monetization_on_outlined),
          const SizedBox(height: 4),
          Text('Cada moneda con su total real (los montos no se mezclan).',
              style: TextStyle(fontSize: 11, color: Colors.grey[600])),
          const SizedBox(height: 6),
          _buildTable(isDark, [
            _tableHeader(['Moneda', 'Pedidos', 'Ventas POS', 'Total']),
            ...currencyTotals.map((ct) => _tableRow([
              '${ct['currency'] ?? ''}',
              '${ct['n'] ?? 0}',
              '', // POS count not in mobile API
              U.money(num.tryParse('${ct['total']}') ?? 0, cur, dec: 0),
            ])),
          ]),
          const SizedBox(height: 16),
        ],

        // ── Utilidades mensuales ──
        if (utils['months'] != null && (utils['months'] as List).isNotEmpty) ...[
          _sectionTitle('Utilidades mensuales', Icons.scale_outlined),
          const SizedBox(height: 6),
          _buildTable(isDark, [
            _tableHeader(['Mes', 'Ingresos', 'Ganancia', 'Gastos', 'Utilidad']),
            ...((utils['months'] as List).reversed.map((m) {
              final income = num.tryParse('${m['income'] ?? 0}') ?? 0;
              final profit = num.tryParse('${m['profit'] ?? 0}') ?? 0;
              final expenses = num.tryParse('${m['expenses'] ?? 0}') ?? 0;
              final utility = num.tryParse('${m['utility'] ?? 0}') ?? 0;
              return _tableRow([
                '${m['label'] ?? ''}',
                U.money(income, cur, dec: 0),
                U.money(profit, cur, dec: 0),
                U.money(expenses, cur, dec: 0),
                U.money(utility, cur, dec: 0),
              ], colors: [null, null, profit >= 0 ? AppTheme.success : AppTheme.danger, null, utility >= 0 ? AppTheme.success : AppTheme.danger]);
            })),
            // Totals row
            if (utils['totals'] != null) ...[
              _tableRow([
                'Total',
                U.money(num.tryParse('${utils['totals']['income'] ?? 0}') ?? 0, cur, dec: 0),
                U.money(num.tryParse('${utils['totals']['profit'] ?? 0}') ?? 0, cur, dec: 0),
                U.money(num.tryParse('${utils['totals']['expenses'] ?? 0}') ?? 0, cur, dec: 0),
                U.money(num.tryParse('${utils['totals']['utility'] ?? 0}') ?? 0, cur, dec: 0),
              ], bold: true),
            ],
          ]),
          Text('Ganancia = (precio venta − costo) × unidades. Utilidad = ingresos − gastos.',
              style: TextStyle(fontSize: 10, color: Colors.grey[500])),
          const SizedBox(height: 16),
        ],

        // ── Utilidad por punto de venta ──
        if (utils['by_loc'] != null && (utils['by_loc'] as Map).isNotEmpty) ...[
          _sectionTitle('Utilidad por punto de venta', Icons.store_outlined),
          const SizedBox(height: 6),
          _buildTable(isDark, [
            _tableHeader(['Punto de venta', 'Ingresos', 'Ganancia', 'Gastos', 'Utilidad']),
            ...(() {
              final byLoc = Map<String, dynamic>.from(utils['by_loc'] ?? {});
              final expByLoc = Map<String, dynamic>.from(utils['exp_by_loc'] ?? {});
              final profitByLoc = Map<String, dynamic>.from(utils['profit_by_loc'] ?? {});
              final locNames = Map<String, String>.fromEntries(
                (utils['locations'] as List? ?? []).map((l) => MapEntry('${l['id']}', '${l['name'] ?? ''}')),
              );
              return byLoc.entries.map((e) {
                final lid = e.key;
                final inc = num.tryParse('${e.value}') ?? 0;
                final prf = num.tryParse('${profitByLoc[lid] ?? 0}') ?? 0;
                final exp = num.tryParse('${expByLoc[lid] ?? 0}') ?? 0;
                final utl = inc - exp;
                return _tableRow([
                  locNames[lid] ?? '#$lid',
                  U.money(inc, cur, dec: 0),
                  U.money(prf, cur, dec: 0),
                  U.money(exp, cur, dec: 0),
                  U.money(utl, cur, dec: 0),
                ], colors: [null, null, prf >= 0 ? AppTheme.success : AppTheme.danger, null, utl >= 0 ? AppTheme.success : AppTheme.danger]);
              }).toList();
            }()),
          ]),
          const SizedBox(height: 16),
        ],

        // ── Ventas por día ──
        if (sales.isNotEmpty) ...[
          _sectionTitle('Ventas por día', Icons.insert_chart_outlined),
          const SizedBox(height: 6),
          _buildTable(isDark, [
            _tableHeader(['Día', 'Pedidos', 'Total']),
            ...sales.map((s) => _tableRow([
              '${s['d'] ?? s['date'] ?? ''}',
              '${s['n'] ?? 0}',
              U.money(num.tryParse('${s['total'] ?? 0}') ?? 0, cur, dec: 0),
            ])),
          ]),
          const SizedBox(height: 16),
        ],

        // ── Movimientos por tipo ──
        if (byType.isNotEmpty) ...[
          _sectionTitle('Movimientos por tipo', Icons.list_outlined),
          const SizedBox(height: 6),
          _buildTable(isDark, [
            _tableHeader(['Tipo', 'Transacciones', 'Unidades']),
            ...byType.map((t) => _tableRow([
              '${(t['type'] ?? '').toString()[0].toUpperCase()}${(t['type'] ?? '').toString().substring(1)}',
              '${t['n'] ?? 0}',
              '${t['qty'] ?? 0}',
            ])),
          ]),
          const SizedBox(height: 16),
        ],

        // ── Top productos vendidos ──
        if (topAll.isNotEmpty) ...[
          _sectionTitle('Top productos vendidos', Icons.leaderboard_outlined),
          const SizedBox(height: 6),
          _buildTable(isDark, [
            _tableHeader(['#', 'Producto', 'Unidades', 'Transacciones', 'Total']),
            ...topAll.asMap().entries.map((entry) {
              final p = entry.value;
              return _tableRow([
                '${entry.key + 1}',
                '${p['product_name'] ?? p['name'] ?? ''}',
                '${p['qty'] ?? 0}',
                '${p['orders'] ?? 0}',
                U.money(num.tryParse('${p['total'] ?? 0}') ?? 0, cur, dec: 0),
              ]);
            }),
          ]),
          const SizedBox(height: 16),
        ],

        // ── Menos vendidos ──
        if (bottom.isNotEmpty) ...[
          _sectionTitle('Menos vendidos', Icons.trending_down_outlined),
          const SizedBox(height: 6),
          _buildTable(isDark, [
            _tableHeader(['#', 'Producto', 'Unidades', 'Transacciones', 'Total']),
            ...bottom.asMap().entries.map((entry) {
              final p = entry.value;
              return _tableRow([
                '${entry.key + 1}',
                '${p['product_name'] ?? p['name'] ?? ''}',
                '${p['qty'] ?? 0}',
                '${p['orders'] ?? 0}',
                U.money(num.tryParse('${p['total'] ?? 0}') ?? 0, cur, dec: 0),
              ]);
            }),
          ]),
          const SizedBox(height: 16),
        ],

        // ── Top productos POS ──
        if (posProducts.isNotEmpty) ...[
          _sectionTitle('Top productos POS', Icons.store_mall_directory_outlined),
          const SizedBox(height: 6),
          _buildTable(isDark, [
            _tableHeader(['#', 'Producto', 'Unidades', 'Ventas', 'Total']),
            ...posProducts.take(10).toList().asMap().entries.map((entry) {
              final p = entry.value;
              return _tableRow([
                '${entry.key + 1}',
                '${p['product_name'] ?? ''}',
                '${p['qty'] ?? 0}',
                '${p['transactions'] ?? 0}',
                U.money(num.tryParse('${p['total'] ?? 0}') ?? 0, cur, dec: 0),
              ]);
            }),
          ]),
          const SizedBox(height: 16),
        ],

        // ── Pedidos recientes ──
        if (transactions.isNotEmpty) ...[
          _sectionTitle('Pedidos recientes', Icons.receipt_long_outlined),
          const SizedBox(height: 6),
          _buildTable(isDark, [
            _tableHeader(['Nº', 'Cliente', 'Fecha', 'Est.', 'Total']),
            ...transactions.take(12).map((t) {
              final createdAt = '${t['created_at'] ?? ''}';
              final day = createdAt.length >= 16
                  ? createdAt.substring(0, 16)
                  : createdAt;
              return _tableRow([
                '${t['number'] ?? t['id'] ?? ''}',
                '${t['customer_name'] ?? '—'}',
                day,
                '${t['status'] ?? ''}',
                U.money(num.tryParse('${t['total'] ?? 0}') ?? 0, cur, dec: 0),
              ]);
            }),
          ]),
          const SizedBox(height: 16),
        ],

        // ── Ventas POS recientes ──
        if (posSales.isNotEmpty) ...[
          _sectionTitle('Ventas POS recientes', Icons.point_of_sale_outlined),
          const SizedBox(height: 6),
          _buildTable(isDark, [
            _tableHeader(['Nº', 'Cliente', 'Punto de venta', 'Total']),
            ...posSales.take(12).map((p) {
              return _tableRow([
                '${p['number'] ?? p['id'] ?? ''}',
                '${p['customer_name'] ?? '—'}',
                '${p['location_name'] ?? ''}',
                U.money(num.tryParse('${p['total'] ?? 0}') ?? 0, cur, dec: 0),
              ]);
            }),
          ]),
        ],
      ],
    );
  }

  // ── Helpers ──

  Widget _sectionTitle(String text, IconData icon) {
    return Row(children: [
      Icon(icon, size: 18, color: AppTheme.primary),
      const SizedBox(width: 8),
      Text(text, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
    ]);
  }

  Widget _sectionHeader(String text, IconData icon, bool isDark) {
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: isDark ? AppTheme.darkCard : Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: isDark ? AppTheme.darkBorder : AppTheme.lightBorder),
      ),
      child: Row(children: [
        Icon(icon, size: 16, color: AppTheme.primary),
        const SizedBox(width: 8),
        Text(text, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
      ]),
    );
  }

  Widget _buildTable(bool isDark, List<Widget> children) {
    return Container(
      decoration: BoxDecoration(
        color: isDark ? AppTheme.darkCard : Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: isDark ? AppTheme.darkBorder : AppTheme.lightBorder),
      ),
      child: Column(children: children),
    );
  }

  Widget _tableHeader(List<String> cols) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.grey.withAlpha(20),
        borderRadius: const BorderRadius.vertical(top: Radius.circular(10)),
      ),
      child: Row(children: cols.map((c) => Expanded(
        child: Text(c, style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: Colors.grey)),
      )).toList()),
    );
  }

  Widget _tableRow(List<String> cols, {List<Color?>? colors, bool bold = false}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: const BoxDecoration(border: Border(bottom: BorderSide(color: AppTheme.lightBorder, width: 0.5))),
      child: Row(children: List.generate(cols.length, (i) => Expanded(
        child: Text(cols[i],
          style: TextStyle(
            fontSize: 12,
            fontWeight: bold ? FontWeight.w700 : FontWeight.w500,
            color: colors != null && i < colors.length ? colors[i] : null,
          ),
          overflow: TextOverflow.ellipsis,
        ),
      ))),
    );
  }
}
