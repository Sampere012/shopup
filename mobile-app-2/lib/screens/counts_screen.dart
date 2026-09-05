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
  final Map<String, TextEditingController> _phys = {};

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
    // Conserva lo que el usuario ya contó (físico): solo se rellena el físico
    // con el virtual cuando el producto es nuevo en la lista. El virtual
    // (app) nunca se modifica.
    final prev = <String, Map<String, dynamic>>{
      for (final r in _items) '${r['product_id']}': r,
    };
    List<Map<String, dynamic>> normalize(List<Map<String, dynamic>> rows) {
      return rows.map((r) {
        final pid = '${r['product_id']}';
        final qty = (num.tryParse('${r['qty'] ?? r['virtual_qty']}') ?? 0).toInt();
        final before = prev[pid];
        final keptPhysical =
            (before != null && before['physical'] is int) ? before['physical'] as int : null;
        return {
          'product_id': r['product_id'],
          'name': r['name'],
          'barcode': r['barcode'],
          'virtual_qty': qty,
          'physical': keptPhysical ?? qty,
        };
      }).toList();
    }

    // Load from cache first
    final cached = await DbService.I.cacheGet('ws_stock_count_virtual:$_locId');
    if (cached is List && cached.isNotEmpty) {
      _items = normalize(cached.whereType<Map>().toList().cast<Map<String, dynamic>>());
      _phys.clear();
      setState(() {});
    }
    // Background refresh
    try {
      final d = await ApiService.I.req(
          'ws_stock_count_virtual', {'location_id': _locId});
      final rows = (d is List)
          ? d
          : (d['data'] is List ? d['data'] : []);
      _items = normalize(List<Map<String, dynamic>>.from(rows));
      await DbService.I.cacheSet('ws_stock_count_virtual:$_locId', _items);
      _phys.clear();
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
                  _phys.clear();
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
            final pid = '${item['product_id']}';
            final ctrl = _phys.putIfAbsent(pid, () {
              final c = TextEditingController(text: '${item['physical'] ?? 0}');
              c.addListener(() {
                final v = (int.tryParse(c.text) ?? 0).clamp(0, 99999);
                if (v != item['physical']) {
                  item['physical'] = v;
                  setInner(() {});
                }
              });
              return c;
            });
            return Card(
              margin: const EdgeInsets.only(bottom: 4),
              child: Padding(
                padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('${item['name'] ?? ''}',
                      style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
                  if ('${item['barcode'] ?? ''}'.isNotEmpty)
                    Text('${item['barcode']}',
                        style: TextStyle(fontSize: 11, color: Colors.grey[500])),
                  const SizedBox(height: 8),
                  Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
                    // Virtual (app) — read-only
                    Expanded(
                      flex: 3,
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text('Virtual (app)',
                            style: TextStyle(
                                fontSize: 10, fontWeight: FontWeight.w600, color: Colors.grey[500])),
                        const SizedBox(height: 2),
                        Text('${item['virtual_qty'] ?? 0}',
                            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                      ]),
                    ),
                    const SizedBox(width: 10),
                    // Físico (contado) — lo introduce el usuario
                    Expanded(
                      flex: 3,
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text('Físico (contado)',
                            style: TextStyle(
                                fontSize: 10, fontWeight: FontWeight.w600, color: Colors.grey[500])),
                        const SizedBox(height: 2),
                        TextField(
                          controller: ctrl,
                          keyboardType: TextInputType.number,
                          textAlign: TextAlign.center,
                          style: const TextStyle(fontSize: 15, fontWeight: FontWeight.w700),
                          decoration: const InputDecoration(
                            isDense: true,
                            contentPadding:
                                EdgeInsets.symmetric(horizontal: 8, vertical: 6),
                            border: OutlineInputBorder(),
                          ),
                        ),
                      ]),
                    ),
                    const SizedBox(width: 10),
                    // Resultado: falta / cuadra / sobra
                    Expanded(
                      flex: 4,
                      child: d == 0
                          ? _countDiffBadge('✓ Cuadra', AppTheme.success)
                          : d > 0
                              ? _countDiffBadge('Sobra $d', AppTheme.amber)
                              : _countDiffBadge('Falta ${-d}', AppTheme.danger),
                    ),
                  ]),
                ]),
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

  Widget _countDiffBadge(String text, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 8),
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: color.withAlpha(15),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: color.withAlpha(50)),
      ),
      child: Text(text,
          textAlign: TextAlign.center,
          style: TextStyle(
              fontSize: 12, fontWeight: FontWeight.w700, color: color)),
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
