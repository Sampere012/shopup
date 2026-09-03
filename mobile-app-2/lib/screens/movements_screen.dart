import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';
import '../widgets/route_nav.dart';

/// Historial de movimientos con filtros: búsqueda, tipo, ubicación y orden.
/// Soporta deep-link vía [NavBus] (type/search) y detalle completo con revert.
class MovementsScreen extends StatefulWidget {
  const MovementsScreen({super.key});

  @override
  State<MovementsScreen> createState() => _MovementsScreenState();
}

class _MovementsScreenState extends State<MovementsScreen> {
  String _q = '';
  String _typeFilter = 'all';
  String _locationFilter = 'all';
  bool _sortNewest = true;
  bool _busy = false;
  late Future<List<Map<String, dynamic>>> _future;

  @override
  void initState() {
    super.initState();
    // Deep-link: aplicar filtros que vengan de una notificación.
    final p = NavBus.params;
    if (p.isNotEmpty) {
      if (p['type'] is String && '${p['type']}'.isNotEmpty) {
        _typeFilter = '${p['type']}';
      }
      if (p['search'] is String && '${p['search']}'.isNotEmpty) {
        _q = '${p['search']}';
      }
    }
    _reload();
    SyncService.I.onChange(_onSync);
  }

  void _onSync() {
    if (mounted) _reload();
  }

  void _reload() {
    _future = DbService.I.all('movements');
    setState(() {});
  }

  Future<void> _refresh() async {
    try {
      await SyncService.I.syncNow();
    } catch (_) {}
    if (mounted) _reload();
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Column(children: [
      // ── Search bar ──
      Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
        child: TextField(
          decoration: InputDecoration(
            hintText: 'Buscar movimiento…',
            prefixIcon: const Icon(Icons.search),
            isDense: true,
            filled: true,
            fillColor: isDark ? AppTheme.darkSurface : Colors.white,
            suffixIcon: _q.isNotEmpty
                ? IconButton(
                    onPressed: () {
                      setState(() => _q = '');
                    },
                    icon: const Icon(Icons.close, size: 18))
                : null,
          ),
          onChanged: (v) => setState(() => _q = v.trim().toLowerCase()),
        ),
      ),

      // ── Filter chips ──
      FutureBuilder<List<Map<String, dynamic>>>(
        future: _future,
        builder: (context, snap) {
          final rows = snap.data ?? [];
          // Collect unique types and locations
          final types = <String>{};
          final locations = <String>{};
          for (final r in rows) {
            final t = '${r['type'] ?? ''}'.toLowerCase();
            if (t.isNotEmpty) types.add(t);
            final l = '${r['location_name'] ?? ''}';
            if (l.isNotEmpty) locations.add(l);
          }
          final sortedTypes = types.toList()..sort();
          final sortedLocations = locations.toList()..sort();

          return Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Type filter chips
              if (sortedTypes.isNotEmpty)
                SizedBox(
                  height: 44,
                  child: ListView(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
                    children: [
                      _chip('Todos', 'all', _typeFilter, (v) {
                        setState(() => _typeFilter = v);
                        _reload();
                      }),
                      for (final t in sortedTypes)
                        _chip(_typeLabel(t), t, _typeFilter, (v) {
                          setState(() => _typeFilter = v);
                          _reload();
                        }),
                    ],
                  ),
                ),

              // Location filter chips + sort toggle
              if (sortedLocations.isNotEmpty)
                SizedBox(
                  height: 44,
                  child: ListView(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.fromLTRB(14, 2, 14, 0),
                    children: [
                      _chip('Todas las ubicaciones', 'all', _locationFilter,
                          (v) {
                        setState(() => _locationFilter = v);
                        _reload();
                      }),
                      for (final l in sortedLocations)
                        _chip(l, l, _locationFilter, (v) {
                          setState(() => _locationFilter = v);
                          _reload();
                        }),
                    ],
                  ),
                ),

              // Sort toggle
              Padding(
                padding: const EdgeInsets.fromLTRB(14, 2, 14, 0),
                child: Row(children: [
                  Icon(Icons.sort, size: 16, color: Colors.grey[500]),
                  const SizedBox(width: 4),
                  GestureDetector(
                    onTap: () =>
                        setState(() => _sortNewest = !_sortNewest),
                    child: Text(
                      _sortNewest ? 'Más recientes' : 'Más antiguos',
                      style: TextStyle(
                          fontSize: 12,
                          color: Colors.grey[600],
                          fontWeight: FontWeight.w600),
                    ),
                  ),
                ]),
              ),
            ],
          );
        },
      ),

      const SizedBox(height: 4),

      // ── Movements list ──
      Expanded(
        child: FutureBuilder<List<Map<String, dynamic>>>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            var rows = snap.data ?? [];

            // Filter by type
            if (_typeFilter != 'all') {
              rows = rows
                  .where(
                      (r) => '${r['type'] ?? ''}'.toLowerCase() == _typeFilter)
                  .toList();
            }

            // Filter by location
            if (_locationFilter != 'all') {
              rows = rows
                  .where(
                      (r) => '${r['location_name'] ?? ''}' == _locationFilter)
                  .toList();
            }

            // Search
            if (_q.isNotEmpty) {
              rows = rows.where((r) {
                final name = '${r['product_name'] ?? ''}'.toLowerCase();
                final combo = '${r['combo_name'] ?? ''}'.toLowerCase();
                final loc = '${r['location_name'] ?? ''}'.toLowerCase();
                final dest = '${r['dest_name'] ?? ''}'.toLowerCase();
                final type = '${r['type'] ?? ''}'.toLowerCase();
                final ref = '${r['reference'] ?? ''}'.toLowerCase();
                final note = '${r['note'] ?? ''}'.toLowerCase();
                final user = '${r['user_name'] ?? r['worker_name'] ?? ''}'.toLowerCase();
                return name.contains(_q) || combo.contains(_q) ||
                    loc.contains(_q) || dest.contains(_q) || type.contains(_q) ||
                    ref.contains(_q) || note.contains(_q) || user.contains(_q);
              }).toList();
            }

            // Sort
            rows.sort((a, b) {
              final da = '${a['created_at'] ?? ''}';
              final db = '${b['created_at'] ?? ''}';
              return _sortNewest ? db.compareTo(da) : da.compareTo(db);
            });

            if (rows.isEmpty) {
              return Center(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.history,
                        size: 48,
                        color: isDark
                            ? AppTheme.darkMuted
                            : AppTheme.lightMuted),
                    const SizedBox(height: 8),
                    Text(
                      _q.isNotEmpty || _typeFilter != 'all' || _locationFilter != 'all'
                          ? 'Sin resultados para estos filtros'
                          : 'Sin movimientos.',
                      style: TextStyle(
                          color: isDark
                              ? AppTheme.darkMuted
                              : AppTheme.lightMuted,
                          fontSize: 14),
                    ),
                  ],
                ),
              );
            }

            return RefreshIndicator(
              onRefresh: _refresh,
              child: ListView.separated(
                padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
                itemCount: rows.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) {
                  final m = rows[i];
                  final type = '${m['type'] ?? ''}'.toLowerCase();
                  final qty = (num.tryParse('${m['qty']}') ?? 0).toInt();
                  final color = _typeColor(type);
                  final dest = '${m['dest_name'] ?? m['to_name'] ?? ''}';
                  final ref = '${m['reference'] ?? ''}';
                  final reverted = m['reverted'] == true ||
                      (m['reverted_at'] is String &&
                          (m['reverted_at'] as String).isNotEmpty);
                  final parts = <String>[
                    '${m['location_name'] ?? ''}',
                    if (dest.isNotEmpty && dest != '${m['location_name'] ?? ''}')
                      '→ $dest',
                    U.fmtDate(m['created_at']),
                  ];
                  return Card(
                    child: ListTile(
                      leading: Container(
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          color: color.withAlpha(20),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: Icon(_typeIcon(type), color: color, size: 20),
                      ),
                      title: Row(children: [
                        Expanded(
                          child: Text(
                            '${m['product_name'] ?? m['combo_name'] ?? ''}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: const TextStyle(
                                fontWeight: FontWeight.w600, fontSize: 14),
                          ),
                        ),
                        if (reverted)
                          U.badge('Revertido', color: Colors.grey, small: true),
                        if (ref.isNotEmpty)
                          Padding(
                            padding: const EdgeInsets.only(left: 4),
                            child: Text('#$ref',
                                style: TextStyle(
                                    color: Colors.grey[500], fontSize: 11)),
                          ),
                      ]),
                      subtitle: Text(
                        parts.where((p) => p.isNotEmpty && !p.startsWith('→')).join(' · '),
                        style: TextStyle(color: Colors.grey[600], fontSize: 12),
                      ),
                      trailing: U.badge(
                          '${qty > 0 ? '+' : ''}$qty',
                          color: color,
                          small: true),
                      onTap: () => _openDetail(m),
                    ),
                  );
                },
              ),
            );
          },
        ),
      ),
    ]);
  }

  // ── Helpers ──

  void _openDetail(Map<String, dynamic> m) {
    final type = '${m['type'] ?? ''}'.toLowerCase();
    final color = _typeColor(type);
    final qty = num.tryParse('${m['qty']}') ?? 0;
    final isServer = int.tryParse('${m['id']}') != null;
    final revertable = isServer &&
        m['reverted'] != true &&
        (m['reverted_at'] == null || '${m['reverted_at']}'.isEmpty) &&
        AuthService.I.has('stock_writeoff') &&
        ['entrada', 'salida', 'baja', 'venta', 'pedido', 'transferencia']
            .contains(type)
        ? true
        : false;

    final rows = <(String, String)>[
      ('Tipo', _typeLabel(type)),
      ('Producto/Combo',
          '${m['product_name'] ?? m['combo_name'] ?? '—'}${m['combo_id'] != null && (num.tryParse('${m['combo_id']}') ?? 0) > 0 ? ' (combo)' : ''}'),
      ('Cantidad', '${qty > 0 ? '+' : ''}${qty.toStringAsFixed(qty == qty.roundToDouble() ? 0 : 2)}'),
      ('Ubicación', '${m['location_name'] ?? m['to_name'] ?? '—'}'),
      if ('${m['dest_name'] ?? m['to_name'] ?? ''}'.isNotEmpty &&
          '${m['dest_name'] ?? m['to_name'] ?? ''}' !=
              '${m['location_name'] ?? ''}')
        ('Destino', '${m['dest_name'] ?? m['to_name'] ?? ''}'),
      if ('${m['reference'] ?? ''}'.isNotEmpty)
        ('Referencia', '${m['reference']}'),
      if ('${m['note'] ?? ''}'.isNotEmpty) ('Nota', '${m['note']}'),
      if ('${m['user_name'] ?? m['worker_name'] ?? ''}'.isNotEmpty)
        ('Responsable', '${m['user_name'] ?? m['worker_name']}'),
      ('Fecha', U.fmtDate(m['created_at'])),
      if (m['reverted_at'] != null && '${m['reverted_at']}'.isNotEmpty)
        ('Revertido', U.fmtDate(m['reverted_at'])),
    ];

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(children: [
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: color.withAlpha(20),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Icon(_typeIcon(type), color: color, size: 24),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(_typeLabel(type),
                      style: const TextStyle(
                          fontWeight: FontWeight.w800, fontSize: 16)),
                ),
                if (m['id'] != null)
                  Text('#${m['id']}',
                      style: TextStyle(
                          color: Colors.grey[500], fontSize: 12)),
              ]),
              const SizedBox(height: 16),
              Flexible(
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    for (final (label, value) in rows)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 5),
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            SizedBox(
                              width: 110,
                              child: Text(label,
                                  style: TextStyle(
                                      color: Colors.grey[600],
                                      fontSize: 12.5)),
                            ),
                            Expanded(
                              child: Text(value,
                                  style: const TextStyle(
                                      fontSize: 13,
                                      fontWeight: FontWeight.w600)),
                            ),
                          ],
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
              if (revertable) ...[
                FilledButton.icon(
                  style: FilledButton.styleFrom(backgroundColor: AppTheme.danger),
                  onPressed: _busy
                      ? null
                      : () => _revertMovement(ctx, m),
                  icon: const Icon(Icons.undo, size: 18),
                  label: const Text('Revertir movimiento'),
                ),
                const SizedBox(height: 8),
              ],
              TextButton.icon(
                onPressed: () => Navigator.pop(ctx),
                icon: const Icon(Icons.close, size: 18),
                label: const Text('Cerrar'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _revertMovement(BuildContext ctx, Map<String, dynamic> m) async {
    if (!await U.confirm(context,
        '¿Revertir este movimiento?\nSe restablecerá el inventario.',
        action: 'Revertir')) {
      return;
    }
    setState(() => _busy = true);
    final sent = await U.handlePush(
      context,
      SyncService.I.push('ws_movement_revert', {'id': int.tryParse('${m['id']}') ?? 0}),
      'Movimiento revertido',
    );
    setState(() => _busy = false);
    if (sent) {
      if (ctx.mounted) Navigator.pop(ctx);
      _reload();
    }
  }

  Widget _chip(String label, String value, String current,
      ValueChanged<String> onTap) {
    final selected = value == current;
    return Padding(
      padding: const EdgeInsets.only(right: 6),
      child: ChoiceChip(
        label: Text(label, style: TextStyle(fontSize: 12)),
        selected: selected,
        onSelected: (_) => onTap(value),
        visualDensity: VisualDensity.compact,
      ),
    );
  }

  String _typeLabel(String type) {
    if (type.contains('entrada') || type.contains('in')) return 'Entradas';
    if (type.contains('salida') || type.contains('out')) return 'Salidas';
    if (type.contains('venta')) return 'Ventas';
    if (type.contains('transfer')) return 'Transferencias';
    if (type.contains('baja')) return 'Bajas';
    return type[0].toUpperCase() + type.substring(1);
  }

  Color _typeColor(String type) {
    if (type.contains('entrada') || type.contains('in')) {
      return AppTheme.success;
    }
    if (type.contains('salida') ||
        type.contains('out') ||
        type.contains('venta')) {
      return AppTheme.danger;
    }
    if (type.contains('transfer')) return AppTheme.primary;
    if (type.contains('baja')) return AppTheme.purple;
    return AppTheme.lightMuted;
  }

  IconData _typeIcon(String type) {
    if (type.contains('entrada') || type.contains('in')) {
      return Icons.arrow_downward;
    }
    if (type.contains('salida') ||
        type.contains('out') ||
        type.contains('venta')) {
      return Icons.arrow_upward;
    }
    if (type.contains('transfer')) return Icons.swap_horiz;
    if (type.contains('baja')) return Icons.delete_outline;
    return Icons.receipt_long_outlined;
  }
}
