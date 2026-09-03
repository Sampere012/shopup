import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/api_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';

/// Cuadre de inventario con tabs (Cuadre / Historial).
/// El cuadre virtual se consulta desde SQLite; el guardado usa Sync.push.
class CountsScreen extends StatefulWidget {
  const CountsScreen({super.key});
  @override
  State<CountsScreen> createState() => _CountsScreenState();
}

class _CountsScreenState extends State<CountsScreen> {
  List<Map<String, dynamic>> _locations = [];
  String _locId = '';
  String _tab = 'count'; // count | history
  List<Map<String, dynamic>> _items = [];
  List<Map<String, dynamic>> _history = [];
  bool _loading = true;

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
    final locs = await DbService.I.all('locations');
    if (locs.isNotEmpty) _locations = locs;
    final counts = await DbService.I.all('stock_counts');
    _history = counts;
    if (_locations.isNotEmpty && _locId.isEmpty) {
      _locId = '${_locations.first['id']}';
    }
    setState(() => _loading = false);
    _loadVirtual();
    _loadHistory();
    _refreshLocations();
  }

  Future<void> _refreshLocations() async {
    if (SyncService.I.isPulling) return;
    try {
      final d = await ApiService.I.req('ws_locations_list', {});
      final serverLocs =
          (d['locations'] as List?) ?? (d['data'] as List?) ?? [];
      if (serverLocs.isNotEmpty) {
        _locations = List<Map<String, dynamic>>.from(serverLocs);
        await DbService.I.replaceAll('locations', _locations);
        if (_locId.isEmpty) _locId = '${_locations.first['id']}';
        if (mounted) setState(() {});
      }
    } catch (_) {}
  }

  Future<void> _loadVirtual() async {
    if (_locId.isEmpty) {
      _items = [];
      setState(() {});
      return;
    }
    // Load from cache first
    final cached = await DbService.I.cacheGet('ws_stock_count_virtual:$_locId');
    if (cached is List && cached.isNotEmpty) {
      _items = cached.whereType<Map>().toList().cast<Map<String, dynamic>>();
      _items = _items.map((r) => {
        'product_id': r['product_id'],
        'name': r['name'],
        'barcode': r['barcode'],
        'virtual_qty': (num.tryParse('${r['qty'] ?? r['virtual_qty']}') ?? 0).toInt(),
        'physical': (num.tryParse('${r['qty'] ?? r['virtual_qty']}') ?? 0).toInt(),
      }).toList();
      setState(() {});
    }
    // Background refresh
    try {
      final d = await ApiService.I.req(
          'ws_stock_count_virtual', {'location_id': _locId});
      final rows = (d is List)
          ? d
          : (d['data'] is List ? d['data'] : []);
      _items = List<Map<String, dynamic>>.from(rows).map((r) => {
        'product_id': r['product_id'],
        'name': r['name'],
        'barcode': r['barcode'],
        'virtual_qty': (num.tryParse('${r['qty']}') ?? 0).toInt(),
        'physical': (num.tryParse('${r['qty']}') ?? 0).toInt(),
      }).toList();
      await DbService.I.cacheSet('ws_stock_count_virtual:$_locId', _items);
      if (mounted) setState(() {});
    } catch (_) {}
  }

  Future<void> _loadHistory() async {
    try {
      final d = await ApiService.I.req('ws_stock_counts_list', {
        'location_id': _locId,
        'limit': 50,
        'offset': 0,
      });
      final rows =
          List<Map<String, dynamic>>.from((d['data'] as List?) ?? []);
      _history = rows;
      await DbService.I.cacheSet('ws_stock_counts_list:$_locId', rows);
      await DbService.I.replaceAll('stock_counts', rows);
      if (mounted) setState(() {});
    } catch (_) {}
  }

  int _diff(Map<String, dynamic> item) {
    return (item['physical'] as int? ?? 0) -
        (item['virtual_qty'] as int? ?? 0);
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Column(
      children: [
        // Location selector
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
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
                value: _locId.isEmpty ? null : _locId,
                isExpanded: true,
                hint: const Text('Seleccionar ubicación'),
                items: _locations
                    .map((l) => DropdownMenuItem(
                        value: '${l['id']}',
                        child: Text('${l['name'] ?? ''}',
                            style: const TextStyle(fontSize: 13))))
                    .toList(),
                onChanged: (v) {
                  setState(() => _locId = v ?? '');
                  _loadVirtual();
                  _loadHistory();
                },
              ),
            ),
          ),
        ),
        // Tabs
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 10, 14, 0),
          child: Row(
            children: [
              _segBtn('Cuadre', 'count'),
              const SizedBox(width: 6),
              _segBtn('Historial de cuadres', 'history'),
            ],
          ),
        ),
        // Content
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : _tab == 'count'
                  ? _buildCountView()
                  : _buildHistoryView(),
        ),
      ],
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
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                  color: active ? Colors.white : AppTheme.primary)),
        ),
      ),
    );
  }

  Widget _buildCountView() {
    if (_locId.isEmpty) {
      return const Center(child: Text('Selecciona una ubicación.'));
    }
    if (_items.isEmpty) {
      return const Center(child: Text('Sin productos para contar.'));
    }

    // Stats
    var cuadrado = 0, sobrante = 0, faltante = 0;
    for (final item in _items) {
      final d = _diff(item);
      if (d > 0) sobrante++;
      else if (d < 0) faltante++;
      else cuadrado++;
    }

    final noteCtrl = TextEditingController();
    bool adjust = false;

    return StatefulBuilder(
      builder: (ctx, setInner) => ListView(
        padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
        children: [
          // Stats
          Row(
            children: [
              _statCard('Cuadrados', '$cuadrado', AppTheme.success),
              const SizedBox(width: 8),
              _statCard('Sobrantes', '$sobrante', AppTheme.amber),
              const SizedBox(width: 8),
              _statCard('Faltantes', '$faltante', AppTheme.danger),
            ],
          ),
          const SizedBox(height: 12),
          Text('${_items.length} productos',
              style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: Colors.grey[600])),
          const SizedBox(height: 8),
          // Product list with editable quantities
          ...List.generate(_items.length > 200 ? 200 : _items.length, (i) {
            final item = _items[i];
            final d = _diff(item);
            final diffColor = d > 0
                ? AppTheme.success
                : d < 0
                    ? AppTheme.danger
                    : AppTheme.success;
            final diffLabel = d == 0
                ? '✓'
                : '${d > 0 ? '+' : ''}$d';
            return Card(
              margin: const EdgeInsets.only(bottom: 4),
              child: ListTile(
                dense: true,
                title: Text('${item['name'] ?? ''}',
                    style: const TextStyle(
                        fontSize: 13, fontWeight: FontWeight.w600)),
                subtitle: Text(
                    'Virtual: ${item['virtual_qty'] ?? 0} · $diffLabel',
                    style: TextStyle(
                        fontSize: 11, color: diffColor)),
                trailing: SizedBox(
                  width: 70,
                  child: TextField(
                    keyboardType: TextInputType.number,
                    controller: TextEditingController(
                        text: '${item['physical'] ?? 0}'),
                    style: const TextStyle(fontSize: 13),
                    decoration: const InputDecoration(
                        isDense: true,
                        contentPadding: EdgeInsets.symmetric(
                            horizontal: 6, vertical: 4)),
                    onChanged: (v) {
                      item['physical'] =
                          (int.tryParse(v) ?? 0).clamp(0, 99999);
                      setInner(() {});
                    },
                  ),
                ),
              ),
            );
          }),
          const SizedBox(height: 12),
          // Note
          TextField(
            controller: noteCtrl,
            decoration: const InputDecoration(
                labelText: 'Nota', hintText: 'Observaciones'),
          ),
          const SizedBox(height: 8),
          // Adjust checkbox
          SwitchListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            title: const Text('Ajustar stock al conteo físico',
                style: TextStyle(fontSize: 13)),
            value: adjust,
            onChanged: (v) => setInner(() => adjust = v),
          ),
          const SizedBox(height: 8),
          // Save button
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: _items.isEmpty
                  ? null
                  : () async {
                      final itemsPayload = _items
                          .map((it) => {
                                'product_id': it['product_id'],
                                'physical': it['physical'] ?? 0,
                              })
                          .toList();
                      final payload = <String, dynamic>{
                        'location_id': _locId,
                        'adjust': adjust ? 1 : 0,
                        'note': noteCtrl.text.trim(),
                        'items': itemsPayload,
                      };
                      final ok = await U.handlePush(
                          context,
                          SyncService.I.push(
                              'ws_stock_count_save', payload),
                          adjust
                              ? 'Cuadre guardado y stock ajustado'
                              : 'Cuadre guardado');
                      if (ok) {
                        // Add to local history
                        final local = <String, dynamic>{
                          'id': 'local-${DateTime.now().millisecondsSinceEpoch}',
                          'location_id': int.tryParse(_locId) ?? 0,
                          'location_name': _locations
                              .where((l) => '${l['id']}' == _locId)
                              .map((l) => '${l['name'] ?? ''}')
                              .firstOrNull ??
                              '',
                          'summary': adjust ? 'Ajustado' : 'Guardado',
                          'note': noteCtrl.text.trim(),
                          'created_at':
                              DateTime.now().toIso8601String(),
                        };
                        _history.insert(0, local);
                        setState(() => _tab = 'history');
                      }
                    },
              child: const Text('Guardar cuadre'),
            ),
          ),
        ],
      ),
    );
  }

  Widget _statCard(String label, String value, Color color) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 10),
        decoration: BoxDecoration(
          color: color.withAlpha(15),
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: color.withAlpha(40)),
        ),
        child: Column(
          children: [
            Text(value,
                style: TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                    color: color)),
            Text(label,
                style: TextStyle(fontSize: 11, color: Colors.grey[600])),
          ],
        ),
      ),
    );
  }

  Widget _buildHistoryView() {
    if (_history.isEmpty) {
      return const Center(child: Text('Sin cuadres registrados.'));
    }
    return ListView.separated(
      padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
      itemCount: _history.length > 50 ? 50 : _history.length,
      separatorBuilder: (_, __) => const SizedBox(height: 8),
      itemBuilder: (context, i) {
        final c = _history[i];
        return Card(
          child: ListTile(
            leading: Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: AppTheme.primary.withAlpha(20),
                borderRadius: BorderRadius.circular(10),
              ),
              child: const Icon(Icons.fact_check_outlined,
                  color: AppTheme.primary, size: 20),
            ),
            title: Text('Cuadre #${c['id'] ?? 'pendiente'}',
                style: const TextStyle(
                    fontWeight: FontWeight.w600, fontSize: 14)),
            subtitle: Text(
                '${c['location_name'] ?? ''} · ${U.fmtDate(c['created_at'])}',
                style: TextStyle(color: Colors.grey[600], fontSize: 12)),
            trailing: U.badge('${c['summary'] ?? 'Guardado'}',
                color: AppTheme.primary, small: true),
          ),
        );
      },
    );
  }
}
