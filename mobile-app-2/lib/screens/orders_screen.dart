import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';
import '../widgets/route_nav.dart';

/// Pedidos web con filtros por estado, ubicación, búsqueda, detalle y acciones.
class OrdersScreen extends StatefulWidget {
  const OrdersScreen({super.key});

  @override
  State<OrdersScreen> createState() => _OrdersScreenState();
}

class _OrdersScreenState extends State<OrdersScreen> {
  String _statusFilter = '';
  String _locFilter = '';
  String _q = '';
  late Future<List<Map<String, dynamic>>> _future;

  @override
  void initState() {
    super.initState();
    // Deep-link desde notificaciones: filtrar por nº de pedido.
    final p = NavBus.params;
    if (p.isNotEmpty) {
      if (p['status'] is String && '${p['status']}'.isNotEmpty) {
        _statusFilter = '${p['status']}';
      }
      if (p['search'] is String && '${p['search']}'.isNotEmpty) {
        _q = '${p['search']}';
      }
    }
    _reload();
    SyncService.I.onChange(_onSync);
  }

  void _onSync() {
    if (mounted) { _reload(); setState(() {}); }
  }

  void _reload() {
    _future = DbService.I.all('orders');
  }

  void _openDetail(Map<String, dynamic> o) {
    final cur = AuthService.I.currency;
    final canAct = AuthService.I.has('orders_accept');
    final status = '${o['status'] ?? ''}';
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => Padding(
        padding: EdgeInsets.only(
            left: 18, right: 18, top: 16,
            bottom: MediaQuery.of(ctx).viewInsets.bottom + 18),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text('#${o['id'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
            U.badge('${o['status_label'] ?? o['status'] ?? ''}',
                color: _statusColor(status), small: true),
          ]),
          const SizedBox(height: 8),
          Text('${o['customer_name'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w600)),
          if ('${o['customer_phone'] ?? ''}'.isNotEmpty)
            Text('${o['customer_phone']}', style: TextStyle(color: Colors.grey[600], fontSize: 13)),
          if ('${o['customer_address'] ?? ''}'.isNotEmpty)
            Text('${o['customer_address']}', style: TextStyle(color: Colors.grey[600], fontSize: 13)),
          const SizedBox(height: 8),
          Text('Total: ${U.money(num.tryParse('${o['total']}') ?? 0, cur)}',
              style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
          Text('${o['items_count'] ?? ''} ítems', style: TextStyle(color: Colors.grey[600], fontSize: 12)),
          const SizedBox(height: 12),
          Row(children: [
            if (status == 'pending' && canAct) ...[
              Expanded(child: OutlinedButton(
                onPressed: () async {
                  await U.handlePush(context, SyncService.I.push('ws_order_accept', {'id': o['id']}), 'Pedido aceptado',
                    onOk: () => SyncService.I.pullStore('ws_order_list', {'pageSize': 200, 'page': 1}, 'orders', cacheKey: 'ws_order_list', dataKey: 'orders'),
                    onQueued: (qp) async {
                      final rows = await DbService.I.all('orders');
                      for (final r in rows) {
                        if ('${r['id']}' == '${o['id']}') { r['status'] = 'accepted'; break; }
                      }
                      await DbService.I.replaceAll('orders', rows);
                    });
                  if (ctx.mounted) Navigator.pop(ctx);
                  _reload(); setState(() {});
                },
                child: const Text('Aceptar'),
              )),
              const SizedBox(width: 8),
              Expanded(child: OutlinedButton(
                style: OutlinedButton.styleFrom(foregroundColor: AppTheme.danger),
                onPressed: () async {
                  await U.handlePush(context, SyncService.I.push('ws_order_reject', {'id': o['id']}), 'Pedido rechazado',
                    onOk: () => SyncService.I.pullStore('ws_order_list', {'pageSize': 200, 'page': 1}, 'orders', cacheKey: 'ws_order_list', dataKey: 'orders'),
                    onQueued: (qp) async {
                      final rows = await DbService.I.all('orders');
                      for (final r in rows) {
                        if ('${r['id']}' == '${o['id']}') { r['status'] = 'rejected'; break; }
                      }
                      await DbService.I.replaceAll('orders', rows);
                    });
                  if (ctx.mounted) Navigator.pop(ctx);
                  _reload(); setState(() {});
                },
                child: const Text('Rechazar'),
              )),
            ],
            if (status == 'accepted' && canAct)
              Expanded(child: FilledButton(
                onPressed: () async {
                  await U.handlePush(context, SyncService.I.push('ws_order_complete', {'id': o['id']}), 'Pedido completado',
                    onOk: () => SyncService.I.pullStore('ws_order_list', {'pageSize': 200, 'page': 1}, 'orders', cacheKey: 'ws_order_list', dataKey: 'orders'),
                    onQueued: (qp) async {
                      final rows = await DbService.I.all('orders');
                      for (final r in rows) {
                        if ('${r['id']}' == '${o['id']}') { r['status'] = 'completed'; break; }
                      }
                      await DbService.I.replaceAll('orders', rows);
                    });
                  if (ctx.mounted) Navigator.pop(ctx);
                  _reload(); setState(() {});
                },
                child: const Text('Completar'),
              )),
            if (status != 'pending' && status != 'accepted')
              Expanded(child: TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cerrar'))),
          ]),
          const SizedBox(height: 8),
        ]),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final cur = AuthService.I.currency;

    return Column(children: [
      // Search
      Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
        child: TextField(
          decoration: InputDecoration(
            hintText: 'Buscar por nº, cliente o teléfono…',
            prefixIcon: const Icon(Icons.search),
            isDense: true,
            filled: true,
            fillColor: isDark ? AppTheme.darkSurface : Colors.white,
            suffixIcon: _q.isNotEmpty
                ? IconButton(
                    onPressed: () => setState(() => _q = ''),
                    icon: const Icon(Icons.close, size: 18))
                : null,
          ),
          onChanged: (v) => setState(() => _q = v.trim().toLowerCase()),
        ),
      ),
      // Status filter tabs
      SizedBox(
        height: 44,
        child: ListView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
          children: [
            _chip('Todos', ''),
            _chip('Pendientes', 'pending'),
            _chip('Aceptados', 'accepted'),
            _chip('Completados', 'completed'),
            _chip('Rechazados', 'rejected'),
          ],
        ),
      ),
      // List
      Expanded(
        child: FutureBuilder<List<Map<String, dynamic>>>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            var rows = snap.data ?? [];
            if (_statusFilter.isNotEmpty) {
              rows = rows.where((r) => '${r['status'] ?? ''}' == _statusFilter).toList();
            }
            if (_locFilter.isNotEmpty) {
              rows = rows.where((r) => '${r['location_name'] ?? ''}' == _locFilter).toList();
            }
            if (_q.isNotEmpty) {
              rows = rows.where((r) {
                final id = '${r['id'] ?? ''}'.toLowerCase();
                final num = '${r['number'] ?? ''}'.toLowerCase();
                final cust = '${r['customer_name'] ?? ''}'.toLowerCase();
                final tel = '${r['customer_phone'] ?? ''}'.toLowerCase();
                return id.contains(_q) || num.contains(_q) || cust.contains(_q) || tel.contains(_q);
              }).toList();
            }
            rows.sort((a, b) => '${b['created_at'] ?? b['date'] ?? ''}'.compareTo('${a['created_at'] ?? a['date'] ?? ''}'));
            if (rows.isEmpty) {
              return Center(
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  Icon(Icons.receipt_long_outlined, size: 48,
                      color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted),
                  const SizedBox(height: 8),
                  Text('Sin pedidos.', style: TextStyle(
                      color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted)),
                ]),
              );
            }
            // Location chips (derived from rows, shown only when several)
            final locs = <String>{};
            for (final r in rows) {
              final l = '${r['location_name'] ?? ''}';
              if (l.isNotEmpty) locs.add(l);
            }
            final locList = locs.toList()..sort();
            return RefreshIndicator(
              onRefresh: () async { _reload(); await _future; setState(() {}); },
              child: ListView(
                padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
                children: [
                  if (locList.length > 1)
                    SizedBox(
                      height: 40,
                      child: ListView(
                        scrollDirection: Axis.horizontal,
                        children: [
                          _chip('Todas las ubicaciones', '', true),
                          for (final l in locList)
                            _chip(l, l, true),
                        ],
                      ),
                    ),
                  const SizedBox(height: 4),
                  for (final o in rows) _orderTile(o, cur, isDark),
                ],
              ),
            );
          },
        ),
      ),
    ]);
  }

  Widget _orderTile(Map<String, dynamic> o, String cur, bool isDark) {
    final total = num.tryParse('${o['total']}') ?? 0;
    final status = '${o['status_label'] ?? o['status'] ?? ''}';
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Card(
        child: ListTile(
          leading: Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: _statusColor(status.toLowerCase()).withAlpha(20),
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(Icons.receipt_long_outlined,
                color: _statusColor(status.toLowerCase()), size: 20),
          ),
          title: Text('#${o['number'] ?? o['id']} · ${o['customer_name'] ?? ''}',
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
              overflow: TextOverflow.ellipsis),
          subtitle: Text(
              '${o['location_name'] ?? ''} · ${U.fmtDate(o['created_at'] ?? o['date'])}',
              style: TextStyle(color: Colors.grey[600], fontSize: 12)),
          trailing: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Text(U.money(total, cur),
                    style: const TextStyle(fontWeight: FontWeight.w800)),
                U.badge(status, color: _statusColor(status.toLowerCase()), small: true),
              ]),
          onTap: () => _openDetail(o),
        ),
      ),
    );
  }

  Color _statusColor(String s) {
    if (s.contains('complet') || s.contains('entreg')) return AppTheme.success;
    if (s.contains('cancel') || s.contains('rechaz')) return AppTheme.danger;
    return AppTheme.amber;
  }

  Widget _chip(String label, String value, [bool isLoc = false]) {
    final cur = isLoc ? _locFilter : _statusFilter;
    final selected = value == cur;
    return Padding(
      padding: const EdgeInsets.only(right: 6),
      child: ChoiceChip(
        label: Text(label, style: const TextStyle(fontSize: 12)),
        selected: selected,
        onSelected: (_) => setState(() {
          if (isLoc) {
            _locFilter = value;
          } else {
            _statusFilter = value;
          }
        }),
        visualDensity: VisualDensity.compact,
      ),
    );
  }
}
