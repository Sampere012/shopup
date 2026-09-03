import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../theme/app_animations.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/route_nav.dart';

/// Dashboard: saludo, estado de sync, conteos rápidos, cola de sync y accesos directos.
class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});
  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  late Future<List<dynamic>> _future;

  @override
  void initState() {
    super.initState();
    _reload();
    SyncService.I.onChange(_onSync);
  }

  void _onSync() {
    if (mounted) { _reload(); setState(() {}); }
  }

  void _reload() {
    _future = Future.wait([
      DbService.I.all('locations'),
      DbService.I.all('products'),
      DbService.I.all('orders'),
      DbService.I.pendingCount(),
      DbService.I.all('stock'),
      DbService.I.all('pos_sales'),
    ]);
  }

  String _greeting() {
    final h = DateTime.now().hour;
    if (h < 12) return 'Buenos días';
    if (h < 20) return 'Buenas tardes';
    return 'Buenas noches';
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final auth = AuthService.I;
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final screenWidth = MediaQuery.of(context).size.width;
    final crossCount = screenWidth > 600 ? 3 : 2;
    final lastSync = SyncService.I.lastSyncMs;
    final isOnline = SyncService.I.isOnline;

    return ListView(
      padding: const EdgeInsets.all(14),
      children: [
        // Greeting card
        TweenAnimationBuilder<double>(
          tween: Tween(begin: 0.0, end: 1.0),
          duration: const Duration(milliseconds: 500),
          curve: Curves.easeOutCubic,
          builder: (_, v, child) => Opacity(
            opacity: v,
            child: Transform.translate(
              offset: Offset(0, 20 * (1 - v)),
              child: child,
            ),
          ),
          child: Container(
            padding: const EdgeInsets.all(18),
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: isDark
                    ? [const Color(0xFF1E293B), const Color(0xFF334155)]
                    : [AppTheme.primary, AppTheme.primaryDark],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
              borderRadius: BorderRadius.circular(14),
              boxShadow: [
                BoxShadow(
                  color: AppTheme.primary.withAlpha(60),
                  blurRadius: 16,
                  offset: const Offset(0, 6),
                ),
              ],
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(children: [
                  CircleAvatar(
                    radius: 22,
                    backgroundColor: Colors.white.withAlpha(40),
                    child: Text(
                      '${auth.me?['name'] ?? '?'}'.isNotEmpty
                          ? '${auth.me?['name']}'[0].toUpperCase()
                          : '?',
                      style: const TextStyle(
                          color: Colors.white,
                          fontSize: 18,
                          fontWeight: FontWeight.w700),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('${_greeting()}, ${auth.me?['name'] ?? ''}',
                            style: const TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.w700,
                                fontSize: 16)),
                        Text(
                            '${auth.me?['roleLabel'] ?? ''} · ${auth.me?['businessName'] ?? ''}',
                            style: TextStyle(
                                color: Colors.white.withAlpha(180),
                                fontSize: 12)),
                      ],
                    ),
                  ),
                ]),
              ],
            ),
          ),
        ),
        const SizedBox(height: 14),

        // Stats grid
        FutureBuilder<List<dynamic>>(
          future: _future,
          builder: (context, snap) {
            final prods = snap.data?[1] ?? [];
            final orders = snap.data?[2] ?? [];
            final pend = snap.data?[3] ?? 0;
            final stock = snap.data?[4] ?? [];
            final lowStock = (stock as List).where((x) {
              final qty = (num.tryParse('${x['qty']}') ?? 0).toInt();
              final min = (num.tryParse('${x['min_stock']}') ?? 0).toInt();
              return qty > 0 && qty <= min;
            }).length;

            return GridView.count(
              crossAxisCount: crossCount,
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              mainAxisSpacing: 10,
              crossAxisSpacing: 10,
              childAspectRatio: 1.6,
              children: [
                _gradientStat(Icons.inventory_2_outlined, prods.length, 'Productos', [AppTheme.success, const Color(0xFF34D399)], onTap: () => NavCallback.of(context)?.navigate('products')),
                _gradientStat(Icons.receipt_long_outlined, orders.length, 'Pedidos web', [AppTheme.purple, const Color(0xFFA78BFA)], onTap: () => NavCallback.of(context)?.navigate('orders')),
                _gradientStat(Icons.warning_amber_outlined, lowStock, 'Stock bajo', lowStock > 0 ? [AppTheme.amber, const Color(0xFFFBBF24)] : [AppTheme.success, const Color(0xFF34D399)], onTap: () => NavCallback.of(context)?.navigate('stock')),
                _gradientStat(Icons.cloud_upload_outlined, pend, 'Sin subir', pend > 0 ? [AppTheme.amber, const Color(0xFFFBBF24)] : [AppTheme.success, const Color(0xFF34D399)], onTap: () => NavCallback.of(context)?.navigate('pending')),
              ],
            );
          },
        ),
        const SizedBox(height: 14),

        // Sync status card
        _syncStatusCard(context, isDark, isOnline, lastSync),

        const SizedBox(height: 14),

      ],
    );
  }

  Widget _syncStatusCard(BuildContext context, bool isDark, bool isOnline, int lastSync) {
    return FutureBuilder<int>(
      future: DbService.I.pendingCount(),
      builder: (context, snap) {
        final pend = snap.data ?? 0;
        return Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            color: isDark ? AppTheme.darkCard : Colors.white,
            borderRadius: BorderRadius.circular(14),
            border: Border.all(color: isDark ? AppTheme.darkBorder : AppTheme.lightBorder),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withAlpha(8),
                blurRadius: 8,
                offset: const Offset(0, 2),
              ),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(children: [
                Icon(Icons.sync, size: 18, color: isOnline ? AppTheme.success : AppTheme.danger),
                const SizedBox(width: 8),
                Text('Sincronización',
                    style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
                const Spacer(),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                  decoration: BoxDecoration(
                    color: isOnline ? AppTheme.success.withAlpha(20) : AppTheme.danger.withAlpha(20),
                    borderRadius: BorderRadius.circular(999),
                  ),
                  child: Text(isOnline ? 'En línea' : 'Sin conexión',
                      style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w600,
                          color: isOnline ? AppTheme.success : AppTheme.danger)),
                ),
              ]),
              const SizedBox(height: 10),
              if (pend > 0)
                GestureDetector(
                  onTap: () => NavCallback.of(context)?.navigate('pending'),
                  child: Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      gradient: LinearGradient(colors: [AppTheme.amber.withAlpha(20), AppTheme.amber.withAlpha(10)]),
                      borderRadius: BorderRadius.circular(10),
                      border: Border.all(color: AppTheme.amber.withAlpha(50)),
                    ),
                    child: Row(children: [
                      const Icon(Icons.cloud_upload_outlined, color: AppTheme.amber, size: 18),
                      const SizedBox(width: 8),
                      Text('$pend cambio${pend == 1 ? '' : 's'} pendiente${pend == 1 ? '' : 's'} por subir',
                          style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 12, color: AppTheme.amber)),
                      const Spacer(),
                      const Icon(Icons.chevron_right, color: AppTheme.amber, size: 18),
                    ]),
                  ),
                ),
              if (pend > 0) const SizedBox(height: 8),
              Row(children: [
                Text(
                  lastSync > 0
                      ? 'Última sync: ${_fmtLastSync(lastSync)}'
                      : 'Nunca sincronizado',
                  style: TextStyle(color: Colors.grey[600], fontSize: 12),
                ),
                const Spacer(),
                GestureDetector(
                  onTap: () => NavCallback.of(context)?.navigate('pending'),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Text('Ver cola', style: TextStyle(color: AppTheme.primary, fontSize: 12, fontWeight: FontWeight.w600)),
                    const SizedBox(width: 2),
                    const Icon(Icons.chevron_right, size: 16, color: AppTheme.primary),
                  ]),
                ),
              ]),
            ],
          ),
        );
      },
    );
  }

  String _fmtLastSync(int ms) {
    final d = DateTime.fromMillisecondsSinceEpoch(ms);
    final now = DateTime.now();
    final diff = now.difference(d);
    if (diff.inMinutes < 1) return 'ahora';
    if (diff.inMinutes < 60) return 'hace ${diff.inMinutes}m';
    if (diff.inHours < 24) return 'hace ${diff.inHours}h';
    return '${d.day}/${d.month}/${d.year}';
  }

  Widget _gradientStat(IconData icon, num value, String label, List<Color> colors, {VoidCallback? onTap}) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: colors,
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(14),
          boxShadow: [
            BoxShadow(
              color: colors.first.withAlpha(50),
              blurRadius: 12,
              offset: const Offset(0, 4),
            ),
          ],
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, size: 22, color: Colors.white.withAlpha(220)),
            const SizedBox(height: 6),
            AnimatedCounter(value: value.toInt(), style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800)),
            Text(label,
                style: TextStyle(
                    color: Colors.white.withAlpha(180), fontSize: 12)),
          ],
        ),
      ),
    );
  }

}
