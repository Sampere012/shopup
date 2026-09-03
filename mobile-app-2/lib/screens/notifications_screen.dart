import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';
import '../widgets/route_nav.dart';

/// Pantalla de notificaciones completa:
/// - Pestañas: No leídas / Todas
/// - Marcar individual como leída
/// - Eliminar individual
/// - Marcar todas como leídas (AppBar)
/// - Eliminar todas (AppBar, con confirmación)
/// - Pull-to-refresh
/// - Timestamps
/// - Detalle con toda la información y navegación directa a la vista/filtro.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});
  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabCtrl;
  List<Map<String, dynamic>> _allItems = [];
  int _unreadCount = 0;
  bool _loading = true;
  bool _actionBusy = false;

  @override
  void initState() {
    super.initState();
    _tabCtrl = TabController(length: 2, vsync: this);
    _tabCtrl.addListener(() => setState(() {}));
    _load();
    SyncService.I.onChange(_onSync);
  }

  void _onSync() {
    if (mounted) _load();
  }

  @override
  void dispose() {
    _tabCtrl.dispose();
    super.dispose();
  }

  // ─── Data ───

  List<Map<String, dynamic>> get _unreadItems =>
      _allItems.where((n) => n['is_read'] == 0 || n['is_read'] == false).toList();

  List<Map<String, dynamic>> get _displayItems =>
      _tabCtrl.index == 0 ? _unreadItems : _allItems;

  Future<void> _load() async {
    final raw = await DbService.I.cacheGet('ws_notifications_list');
    final count = await DbService.I.getMeta('notif_unread_count');
    if (!mounted) return;
    setState(() {
      _allItems = (raw is List)
          ? raw
              .whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList()
          : [];
      _unreadCount = (count as num?)?.toInt() ?? 0;
      _loading = false;
    });
  }

  Future<void> _refresh() async {
    setState(() => _loading = true);
    try {
      await SyncService.I.syncNow();
    } catch (_) {}
    await _load();
  }

  // ─── Actions ───

  Future<void> _markRead(int id) async {
    if (_actionBusy) return;
    setState(() => _actionBusy = true);
    await U.handlePush(
      context,
      SyncService.I.push('ws_notifications_read', {'ids': [id]}),
      'Marcada como leída',
    );
    for (final n in _allItems) {
      if (n['id'] == id) {
        n['is_read'] = 1;
        break;
      }
    }
    _unreadCount = _unreadItems.length;
    await DbService.I.cacheSet('ws_notifications_list', _allItems);
    await DbService.I.setMeta('notif_unread_count', _unreadCount);
    if (mounted) setState(() => _actionBusy = false);
  }

  Future<void> _markAllRead() async {
    if (_actionBusy || _unreadCount == 0) return;
    setState(() => _actionBusy = true);
    await U.handlePush(
      context,
      SyncService.I.push('ws_notifications_read', {'all': '1'}),
      'Todas marcadas como leídas',
    );
    for (final n in _allItems) {
      n['is_read'] = 1;
    }
    _unreadCount = 0;
    await DbService.I.cacheSet('ws_notifications_list', _allItems);
    await DbService.I.setMeta('notif_unread_count', 0);
    if (mounted) setState(() => _actionBusy = false);
  }

  Future<void> _deleteOne(int id) async {
    if (_actionBusy) return;
    setState(() => _actionBusy = true);
    await U.handlePush(
      context,
      SyncService.I.push('ws_notifications_delete', {'ids': [id]}),
      'Notificación eliminada',
    );
    final removed = _allItems.firstWhere((n) => n['id'] == id, orElse: () => {});
    _allItems.removeWhere((n) => n['id'] == id);
    if (removed.isNotEmpty && (removed['is_read'] == 0 || removed['is_read'] == false)) {
      _unreadCount = _unreadItems.length;
    }
    await DbService.I.cacheSet('ws_notifications_list', _allItems);
    await DbService.I.setMeta('notif_unread_count', _unreadCount);
    if (mounted) setState(() => _actionBusy = false);
  }

  Future<void> _deleteAll() async {
    if (_actionBusy || _allItems.isEmpty) return;
    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Eliminar todas'),
        content: Text('¿Eliminar las ${_allItems.length} notificaciones?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancelar')),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Eliminar', style: TextStyle(color: Colors.red)),
          ),
        ],
      ),
    );
    if (confirm != true) return;

    setState(() => _actionBusy = true);
    final ids = _allItems.map((n) => n['id'] as dynamic).toList();
    await U.handlePush(
      context,
      SyncService.I.push('ws_notifications_delete', {'ids': ids}),
      'Todas eliminadas',
    );
    _allItems.clear();
    _unreadCount = 0;
    await DbService.I.cacheSet('ws_notifications_list', <dynamic>[]);
    await DbService.I.setMeta('notif_unread_count', 0);
    if (mounted) setState(() => _actionBusy = false);
  }

  // ─── Build ───

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Column(
      children: [
        // ── Badge de no leídas ──
        if (_unreadCount > 0)
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
            color: AppTheme.primary.withAlpha(15),
            child: Row(children: [
              const Icon(Icons.info_outline, size: 16, color: AppTheme.primary),
              const SizedBox(width: 8),
              Text(
                '$_unreadCount no leída${_unreadCount > 1 ? 's' : ''}',
                style: const TextStyle(
                  color: AppTheme.primary,
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                ),
              ),
            ]),
          ),

        // ── Barra de acciones ──
        if (_allItems.isNotEmpty)
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
            decoration: BoxDecoration(
              color: isDark ? Colors.grey[900] : Colors.grey[50],
              border: Border(bottom: BorderSide(color: Colors.grey.withAlpha(30))),
            ),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                if (_unreadCount > 0)
                  TextButton.icon(
                    onPressed: _actionBusy ? null : _markAllRead,
                    icon: const Icon(Icons.done_all, size: 16),
                    label: const Text('Marcar todas leídas', style: TextStyle(fontSize: 12)),
                  ),
                TextButton.icon(
                  onPressed: _actionBusy || _allItems.isEmpty ? null : _deleteAll,
                  icon: const Icon(Icons.delete_sweep_outlined, size: 16),
                  label: const Text('Eliminar todas', style: TextStyle(fontSize: 12, color: Colors.redAccent)),
                ),
              ],
            ),
          ),

        // ── Pestañas ──
        Container(
          decoration: BoxDecoration(
            color: isDark ? Colors.grey[850] : Colors.white,
            border: Border(bottom: BorderSide(color: Colors.grey.withAlpha(40))),
          ),
          child: TabBar(
            controller: _tabCtrl,
            labelColor: AppTheme.primary,
            unselectedLabelColor: isDark ? Colors.grey[400] : Colors.grey[600],
            indicatorColor: AppTheme.primary,
            indicatorWeight: 3,
            labelStyle: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
            unselectedLabelStyle: const TextStyle(fontWeight: FontWeight.w400, fontSize: 13),
            tabs: [
              Tab(text: 'No leídas${_unreadCount > 0 ? ' ($_unreadCount)' : ''}'),
              const Tab(text: 'Todas'),
            ],
          ),
        ),

        // ── Lista ──
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : RefreshIndicator(
                  onRefresh: _refresh,
                  child: _displayItems.isEmpty
                      ? ListView(
                          children: [
                            SizedBox(
                              height: MediaQuery.of(context).size.height * 0.4,
                              child: Center(
                                child: Column(
                                  mainAxisSize: MainAxisSize.min,
                                  children: [
                                    Icon(Icons.notifications_none_outlined,
                                        size: 48,
                                        color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted),
                                    const SizedBox(height: 8),
                                    Text(
                                      _tabCtrl.index == 0
                                          ? 'Sin notificaciones nuevas'
                                          : 'Sin notificaciones',
                                      style: TextStyle(
                                        color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          ],
                        )
                      : ListView.separated(
                          padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
                          itemCount: _displayItems.length,
                          separatorBuilder: (_, __) => const SizedBox(height: 6),
                          itemBuilder: (context, i) => _buildCard(_displayItems[i]),
                        ),
                ),
        ),
      ],
    );
  }

  Widget _buildCard(Map<String, dynamic> n) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final isUnread = n['is_read'] == 0 || n['is_read'] == false;
    final title = '${n['title'] ?? ''}';
    final body = '${n['message'] ?? ''}';
    final type = '${n['type'] ?? ''}';
    final createdAt = '${n['date'] ?? n['time'] ?? ''}';

    return Card(
      child: Container(
        decoration: isUnread
            ? BoxDecoration(
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: AppTheme.primary.withAlpha(40)),
              )
            : null,
        child: ListTile(
          leading: _notifIcon(type, isUnread),
          title: Text(title,
              style: TextStyle(
                fontWeight: isUnread ? FontWeight.w700 : FontWeight.w500,
                fontSize: 13,
              )),
          subtitle: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (body.isNotEmpty)
                Text(body,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: TextStyle(color: Colors.grey[600], fontSize: 12)),
              if (createdAt.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Text(createdAt,
                      style: TextStyle(
                        color: isDark ? Colors.grey[500] : Colors.grey[400],
                        fontSize: 10,
                      )),
                ),
            ],
          ),
          isThreeLine: body.isNotEmpty && createdAt.isNotEmpty,
          onTap: _actionBusy ? null : () => _openDetail(n),
          trailing: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (isUnread)
                IconButton(
                  icon: const Icon(Icons.check_circle_outline, size: 20),
                  tooltip: 'Marcar leída',
                  color: AppTheme.primary,
                  onPressed: _actionBusy ? null : () => _markRead(n['id'] as int),
                ),
              IconButton(
                icon: Icon(Icons.delete_outline,
                    size: 20,
                    color: isDark ? Colors.grey[400] : Colors.grey[600]),
                tooltip: 'Eliminar',
                onPressed: _actionBusy ? null : () => _deleteOne(n['id'] as int),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _notifIcon(String type, bool isUnread) {
    final color = isUnread ? AppTheme.primary : AppTheme.lightMuted;
    final icon = switch (type) {
      'order' || 'new_order' => Icons.receipt_long_outlined,
      'stock' || 'low_stock' || 'out_stock' => Icons.inventory_2_outlined,
      'review' => Icons.star_outline,
      'payment' => Icons.payments_outlined,
      'announcement' => Icons.campaign_outlined,
      'subscription' => Icons.card_membership_outlined,
      _ => Icons.notifications_none,
    };
    return Container(
      padding: const EdgeInsets.all(8),
      decoration: BoxDecoration(
        color: color.withAlpha(20),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Icon(icon, size: 20, color: color),
    );
  }

  // ─── Detalle + navegación ───

  /// Determina a qué ruta y filtros lleva esta notificación.
  (String, Map<String, dynamic>)? _routeFor(Map<String, dynamic> n) {
    final type = '${n['type'] ?? ''}';
    final refKey = '${n['ref_key'] ?? ''}';
    final refId = refKey.split('_').last;
    return switch (type) {
      'new_order' || 'order_accepted' || 'order_rejected' ||
      'order_completed' || 'order_cancelled' => (
        'orders',
        {'search': refId.isNotEmpty && refId != 'null' ? refId : ''}
      ),
      'recent_movements' || 'movement' || 'low_stock' || 'out_stock' => (
        'movements',
        <String, dynamic>{}
      ),
      'product_expired' || 'product_expiring' => ('products', {}),
      'review' || 'new_review' => ('reviews', {}),
      'payment' || 'new_sale' || 'pos_sale' || 'sale_completed' => (
        'pos-sales',
        <String, dynamic>{}
      ),
      'announcement' => ('anuncios', {}),
      'expense' => ('expenses', {}),
      'stock_count' || 'new_stock_count' => ('counts', {}),
      _ => null,
    };
  }

  String _typeName(String type) {
    return switch (type) {
      'new_order' || 'order' => 'Nuevo pedido',
      'order_accepted' => 'Pedido aceptado',
      'order_rejected' => 'Pedido rechazado',
      'order_completed' => 'Pedido completado',
      'order_cancelled' => 'Pedido cancelado',
      'low_stock' => 'Stock bajo',
      'out_stock' => 'Agotado',
      'recent_movements' => 'Movimientos recientes',
      'product_expired' => 'Productos vencidos',
      'product_expiring' => 'Productos por vencer',
      'review' || 'new_review' => 'Reseña',
      'payment' || 'new_sale' || 'pos_sale' || 'sale_completed' => 'Venta',
      'announcement' => 'Anuncio',
      'expense' => 'Gasto',
      'stock_count' || 'new_stock_count' => 'Conteo',
      'subscription' => 'Suscripción',
      _ => type.isEmpty ? 'Notificación' : type,
    };
  }

  void _openDetail(Map<String, dynamic> n) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final rawTitle = '${n['title'] ?? n['message'] ?? ''}';
    final message = '${n['message'] ?? ''}';
    final date = '${n['date'] ?? n['time'] ?? ''}';
    final type = '${n['type'] ?? ''}';
    final refKey = '${n['ref_key'] ?? ''}';
    final route = _routeFor(n);

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
                _notifIcon(type, true),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(_typeName(type),
                      style: const TextStyle(
                          fontWeight: FontWeight.w800, fontSize: 16)),
                ),
                IconButton(
                  icon: const Icon(Icons.delete_outline, size: 20),
                  tooltip: 'Eliminar',
                  onPressed: () {
                    Navigator.pop(ctx);
                    _deleteOne(n['id'] as int);
                  },
                ),
              ]),
              const SizedBox(height: 8),
              if (rawTitle.isNotEmpty)
                Text(
                  rawTitle == message ? rawTitle : rawTitle,
                  style: TextStyle(
                      fontSize: 14,
                      fontWeight: FontWeight.w700,
                      color: isDark ? Colors.white : Colors.black),
                ),
              if (message.isNotEmpty && message != rawTitle) ...[
                const SizedBox(height: 6),
                Text(message,
                    style: TextStyle(
                        fontSize: 13, height: 1.4, color: Colors.grey[600])),
              ],
              if (date.isNotEmpty) ...[
                const SizedBox(height: 4),
                Text(date,
                    style: TextStyle(
                        fontSize: 11,
                        color: isDark ? Colors.grey[500] : Colors.grey[400])),
              ],
              if (refKey.isNotEmpty) ...[
                const SizedBox(height: 6),
                Text('Ref: $refKey',
                    style: TextStyle(
                        fontSize: 11,
                        color: isDark ? Colors.grey[500] : Colors.grey[400])),
              ],
              const SizedBox(height: 16),
              if (route != null)
                FilledButton.icon(
                  onPressed: () => _goTo(ctx, route.$1, route.$2),
                  icon: const Icon(Icons.open_in_new, size: 18),
                  label: const Text('Ver detalles'),
                ),
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

  void _goTo(BuildContext ctx, String route, Map<String, dynamic> params) {
    final nav = NavCallback.of(context);
    if (nav == null) return;
    Navigator.pop(ctx);
    NavBus.to(route, params);
    nav.navigate(route);
  }
}
