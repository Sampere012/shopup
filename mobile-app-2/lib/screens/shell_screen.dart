import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/auth_service.dart';
import '../services/sync_service.dart';
import '../services/db_service.dart';
import '../services/theme_service.dart';
import '../services/update_service.dart';
import '../widgets/common.dart' show U;
import 'package:shared_preferences/shared_preferences.dart';
import 'routes.dart';
import '../widgets/route_nav.dart';

class ShellScreen extends StatefulWidget {
  const ShellScreen({super.key});
  @override
  State<ShellScreen> createState() => _ShellScreenState();
}

class _ShellScreenState extends State<ShellScreen> with WidgetsBindingObserver {
  String _route = 'dashboard';
  int _unreadCount = 0;
  Timer? _sessionRefreshTimer;
  bool _planLocked = false;
  bool _hasUpdate = false;

  static const _bottomNavKeys = ['dashboard', 'products', 'stock', 'pos', 'pos-sales'];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    SyncService.I.start();
    _sessionRefreshTimer = Timer.periodic(const Duration(minutes: 5), (_) => _refreshSession());
    _updateNotifBadge();
    _checkPlan();
    _checkUpdate();
    SyncService.I.onChange(_updateNotifBadge);
    SyncService.I.onChange(_checkPlan);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _sessionRefreshTimer?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      AuthService.I.refresh();
      SyncService.I.checkConnectivity();
      _updateNotifBadge();
      _checkUpdate();
    }
  }

  Future<void> _refreshSession() async {
    if (!await AuthService.online()) return;
    final me = await AuthService.I.refresh();
    if (me != null && mounted) setState(() {});
  }

  Future<void> _updateNotifBadge() async {
    try {
      final count = await DbService.I.getMeta('notif_unread_count');
      if (mounted) setState(() => _unreadCount = (count as num?)?.toInt() ?? 0);
    } catch (_) {}
  }

  Future<void> _checkPlan() async {
    try {
      final data = await DbService.I.cacheGet('ws_plan_info');
      if (data is Map && mounted) {
        final locked = data['locked'] == true;
        if (locked != _planLocked) setState(() => _planLocked = locked);
      }
    } catch (_) {}
  }

  Future<void> _checkUpdate() async {
    final has = await UpdateService.I.check(silent: true);
    if (mounted) {
      setState(() => _hasUpdate = has);
      // Show changelog dialog if there's an update and we haven't shown it yet
      if (has && UpdateService.I.updateInfo != null && mounted) {
        final sp = await SharedPreferences.getInstance();
        final lastShown = sp.getString('_last_update_dialog');
        final newVersion = '${UpdateService.I.updateInfo?['version'] ?? ''}';
        if (lastShown != newVersion) {
          await sp.setString('_last_update_dialog', newVersion);
          // Delay to avoid showing during startup animation
          Future.delayed(const Duration(seconds: 3), () {
            if (mounted) UpdateService.showUpdateDialog(context, UpdateService.I.updateInfo!);
          });
        }
      }
    }
  }

  void _go(String key) {
    NavBus.clear();
    setState(() => _route = key);
    Navigator.of(context).pop();
  }

  Future<void> _manualSync() async {
    final sync = SyncService.I;
    if (sync.isBusy) return;
    final res = await sync.syncNow().catchError((_) => 'offline' as Object);
    if (!mounted) return;
    if (res == 'offline') U.toast(context, 'Sin conexión: cambios guardados en el dispositivo', kind: 'warn');
    else if (res == false) U.toast(context, 'Quedaron cambios pendientes de enviar', kind: 'err');
    else U.toast(context, 'Sincronizado');
    _updateNotifBadge();
  }

  IconData _iconFor(Object? raw, [String? routeKey]) {
    // Iconos que envía el backend (FontAwesome) — inc/ajax.php ws_mobile_me_payload.
    const map = {
      'fa-gauge-high': Icons.speed, 'fa-boxes-stacked': Icons.inventory_2_outlined,
      'fa-location-dot': Icons.location_on_outlined, 'fa-warehouse': Icons.warehouse_outlined,
      'fa-list-check': Icons.fact_check_outlined, 'fa-clock-rotate-left': Icons.history,
      'fa-receipt': Icons.receipt_long_outlined, 'fa-calendar-days': Icons.calendar_month_outlined,
      'fa-user-gear': Icons.manage_accounts_outlined, 'fa-users': Icons.groups_2_outlined,
      'fa-cash-register': Icons.point_of_sale, 'fa-chart-line': Icons.trending_up,
      'fa-star': Icons.star_outline, 'fa-gift': Icons.card_giftcard,
      'fa-money-bill-wave': Icons.payments_outlined, 'fa-bullhorn': Icons.campaign_outlined,
      'fa-crown': Icons.workspace_premium_outlined, 'fa-shield-halved': Icons.admin_panel_settings_outlined,
      'fa-chart-pie': Icons.pie_chart_outline, 'fa-palette': Icons.palette_outlined,
      'fa-gear': Icons.settings_outlined, 'fa-user': Icons.person_outline,
      // Nombres legacy por compatibilidad.
      'tachometer': Icons.speed, 'box': Icons.inventory_2_outlined,
      'warehouse': Icons.warehouse_outlined, 'clipboard-list': Icons.fact_check_outlined,
      'history': Icons.history, 'cash-register': Icons.point_of_sale,
      'shopping-cart': Icons.shopping_cart_outlined, 'users': Icons.groups_2_outlined,
      'map-marker': Icons.location_on_outlined, 'user-clock': Icons.schedule,
      'user-cog': Icons.manage_accounts_outlined, 'star': Icons.star_outline,
      'gem': Icons.diamond_outlined, 'money-bill': Icons.payments_outlined,
      'crown': Icons.workspace_premium_outlined, 'shield': Icons.admin_panel_settings_outlined,
      'chart': Icons.bar_chart_outlined, 'paint': Icons.palette_outlined,
      'gear': Icons.settings_outlined,
    };
    final byIcon = map['$raw'];
    if (byIcon != null) return byIcon;
    // Respaldo por clave de ruta: nunca falta un icono significativo.
    const byKey = {
      'dashboard': Icons.speed, 'products': Icons.inventory_2_outlined,
      'locations': Icons.location_on_outlined, 'stock': Icons.warehouse_outlined,
      'counts': Icons.fact_check_outlined, 'movements': Icons.history,
      'orders': Icons.receipt_long_outlined, 'shifts': Icons.calendar_month_outlined,
      'workers': Icons.manage_accounts_outlined, 'customers': Icons.groups_2_outlined,
      'pos': Icons.point_of_sale, 'pos-sales': Icons.trending_up,
      'reviews': Icons.star_outline, 'loyalty': Icons.card_giftcard,
      'expenses': Icons.payments_outlined, 'anuncios': Icons.campaign_outlined,
      'plan': Icons.workspace_premium_outlined, 'permissions': Icons.admin_panel_settings_outlined,
      'reports': Icons.pie_chart_outline, 'appearance': Icons.palette_outlined,
      'settings': Icons.settings_outlined, 'account': Icons.person_outline,
      'notificaciones': Icons.notifications_none, 'more': Icons.menu,
    };
    return byKey[routeKey ?? ''] ?? Icons.widgets_outlined;
  }

  String _titleFor(String route) {
    final me = AuthService.I.me ?? {};
    for (final m in ((me['menu'] as List?) ?? [])) {
      if (m is Map && '${m['key']}' == route) return '${m['label']}';
    }
    return 'ShopUp Panel';
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthService>();
    final sync = context.watch<SyncNotifier>().sync;
    final menu = auth.menu.whereType<Map>().map((m) => Map<String, dynamic>.from(m)).toList();
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final isTablet = MediaQuery.of(context).size.width > 600;

    final bottomItems = <_NavEntry>[];
    for (final key in _bottomNavKeys) {
      final m = menu.firstWhere((m) => '${m['key']}' == key, orElse: () => {});
      if (m.isNotEmpty) {
        bottomItems.add(_NavEntry(
            key: key, label: '${m['label']}', icon: _iconFor(m['icon'], key)));
      }
    }
    if (bottomItems.length < 3) bottomItems.add(const _NavEntry(key: 'more', label: 'Más', icon: Icons.menu));

    if (_planLocked && _route != 'plan') {
      return Scaffold(
        appBar: AppBar(title: const Text('ShopUp Panel')),
        body: _buildPlanLocked(),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(_titleFor(_route), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
          if (auth.businessName.isNotEmpty)
            Text(auth.businessName, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w400)),
        ]),
        actions: [
          if (_hasUpdate)
            Stack(clipBehavior: Clip.none, children: [
              IconButton(
                tooltip: 'Actualización disponible',
                onPressed: () => UpdateService.I.check(silent: false),
                icon: const Icon(Icons.system_update_outlined),
              ),
              Positioned(right: 4, top: 4, child: Container(
                width: 8, height: 8,
                decoration: const BoxDecoration(color: AppTheme.success, shape: BoxShape.circle),
              )),
            ]),
          IconButton(
            tooltip: isDark ? 'Modo claro' : 'Modo oscuro',
            onPressed: () => ThemeService.I.toggleDark(),
            icon: Icon(isDark ? Icons.light_mode_outlined : Icons.dark_mode_outlined, color: Colors.white70),
          ),
          IconButton(
            tooltip: 'Sincronizar',
            onPressed: sync.isBusy ? null : _manualSync,
            icon: sync.isBusy
                ? const Padding(padding: EdgeInsets.all(10), child: SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)))
                : const Icon(Icons.sync),
          ),
          Stack(clipBehavior: Clip.none, children: [
            IconButton(
              tooltip: 'Notificaciones',
              onPressed: () => setState(() => _route = 'notificaciones'),
              icon: const Icon(Icons.notifications_none),
            ),
            if (_unreadCount > 0)
              Positioned(right: 6, top: 6, child: Container(
                padding: const EdgeInsets.all(4),
                constraints: const BoxConstraints(minWidth: 18, minHeight: 18),
                decoration: const BoxDecoration(color: AppTheme.danger, shape: BoxShape.circle,
                    boxShadow: [BoxShadow(color: Color(0x44DC2626), blurRadius: 6, offset: Offset(0, 2))]),
                child: Text(_unreadCount > 99 ? '99+' : '$_unreadCount',
                    style: const TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.w800), textAlign: TextAlign.center),
              )),
          ]),
        ],
        bottom: !sync.isOnline
            ? PreferredSize(preferredSize: const Size.fromHeight(22), child: Container(
                width: double.infinity, color: const Color(0xFFD97706), padding: const EdgeInsets.symmetric(vertical: 4),
                child: const Text('Sin conexión — los cambios se guardan en el dispositivo', textAlign: TextAlign.center,
                    style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w500))))
            : null,
      ),
      drawer: _buildDrawer(context, auth, menu, isDark),
      // Sin pull-to-refresh: la sync manual vive SOLO en el botón de la
      // barra superior (y el cronómetro automático).
      body: NavCallback(
        navigate: (r) {
          NavBus.clear();
          setState(() => _route = r);
        },
        child: AnimatedSwitcher(
          duration: const Duration(milliseconds: 280),
          switchInCurve: Curves.easeOutCubic,
          switchOutCurve: Curves.easeInCubic,
          transitionBuilder: (child, anim) => FadeTransition(
            opacity: anim,
            child: SlideTransition(
              position: Tween<Offset>(begin: const Offset(0.04, 0.0), end: Offset.zero)
                  .animate(CurvedAnimation(parent: anim, curve: Curves.easeOutCubic)),
              child: child,
            ),
          ),
          child: KeyedSubtree(key: ValueKey(_route), child: RouteView(route: _route)),
        ),
      ),
      bottomNavigationBar: isTablet ? null : NavigationBar(
        selectedIndex: (() {
          final idx = _bottomNavKeys.indexOf(_route);
          return idx >= 0 ? idx : bottomItems.length - 1;
        })(),
        backgroundColor: isDark ? const Color(0xFF0F172A) : Colors.white,
        indicatorColor: AppTheme.primary.withAlpha(40),
        surfaceTintColor: Colors.transparent,
        onDestinationSelected: (i) {
          final key = bottomItems[i].key;
          if (key == 'more') Scaffold.of(context).openDrawer();
          else setState(() => _route = key);
        },
        destinations: bottomItems
            .map((e) => NavigationDestination(
                  icon: Icon(e.icon, color: isDark ? Colors.white70 : const Color(0xFF475569)),
                  selectedIcon: Icon(e.icon, color: AppTheme.primary),
                  label: e.label,
                ))
            .toList(),
      ),
    );
  }

  Widget _buildPlanLocked() {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Center(child: Padding(padding: const EdgeInsets.all(32), child: Column(mainAxisSize: MainAxisSize.min, children: [
      Container(width: 80, height: 80, decoration: BoxDecoration(
        gradient: LinearGradient(colors: [AppTheme.amber, AppTheme.amber.withAlpha(180)]),
        shape: BoxShape.circle, boxShadow: [BoxShadow(color: AppTheme.amber.withAlpha(60), blurRadius: 24, offset: const Offset(0, 8))],
      ), child: const Icon(Icons.lock_outline, size: 40, color: Colors.white)),
      const SizedBox(height: 24),
      Text('Negocio en pausa', style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
      const SizedBox(height: 8),
      Text('Tu plan venció o fue suspendido.\nEl negocio está en pausa temporal.',
          textAlign: TextAlign.center, style: TextStyle(color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted, fontSize: 14, height: 1.5)),
      const SizedBox(height: 24),
      FilledButton.icon(
        onPressed: () => setState(() => _route = 'plan'),
        icon: const Icon(Icons.workspace_premium_outlined, size: 18),
        label: const Text('Ver mi plan'),
        style: FilledButton.styleFrom(backgroundColor: AppTheme.amber),
      ),
    ])));
  }

  Widget _buildDrawer(BuildContext context, AuthService auth, List<Map<String, dynamic>> menu, bool isDark) {
    return Drawer(
      backgroundColor: isDark ? const Color(0xFF0B1220) : const Color(0xFF111827),
      child: SafeArea(child: Column(children: [
        Container(
          padding: const EdgeInsets.all(16), width: double.infinity,
          decoration: BoxDecoration(gradient: LinearGradient(colors: isDark
              ? [const Color(0xFF1E293B), const Color(0xFF0F172A)]
              : [const Color(0xFF1E2450), const Color(0xFF161B3C)])),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(24),
              child: Image.asset(
                'assets/images/logo.png',
                width: 48,
                height: 48,
                fit: BoxFit.cover,
                errorBuilder: (_, __, ___) => CircleAvatar(
                  radius: 24,
                  backgroundColor: AppTheme.primary,
                  child: Text(
                    '${auth.businessName.isEmpty ? '?' : auth.businessName[0]}',
                    style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w700),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 10),
            Text('${auth.me?['name'] ?? ''}', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 15)),
            Text('${auth.me?['roleLabel'] ?? auth.me?['role'] ?? ''}', style: const TextStyle(color: Colors.white70, fontSize: 12)),
            if (auth.businessName.isNotEmpty) Text(auth.businessName, style: const TextStyle(color: Colors.white54, fontSize: 11)),
          ]),
        ),
        Expanded(child: ListView(padding: const EdgeInsets.symmetric(vertical: 4), children: [
          for (final m in menu)
            ListTile(
              dense: true,
              selected: _route == '${m['key']}',
              selectedColor: AppTheme.primaryLight,
              iconColor: Colors.white70,
              textColor: Colors.white,
              leading: Icon(_iconFor(m['icon'], '${m['key']}'),
                  color: _route == '${m['key']}' ? AppTheme.primaryLight : Colors.white70),
              title: Text('${m['label']}',
                  style: TextStyle(
                      fontSize: 14,
                      fontWeight: _route == '${m['key']}' ? FontWeight.w700 : FontWeight.w400,
                      color: _route == '${m['key']}' ? AppTheme.primaryLight : Colors.white)),
              onTap: () => _go('${m['key']}'),
            ),
        ])),
        ListTile(
          leading: const Icon(Icons.logout, color: Colors.redAccent),
          title: const Text('Cerrar sesión', style: TextStyle(color: Colors.redAccent, fontSize: 14)),
          onTap: () async {
            Navigator.pop(context);
            if (await U.confirm(context, '¿Cerrar sesión?', action: 'Cerrar sesión')) await AuthService.I.logout();
          },
        ),
        const SizedBox(height: 8),
      ])),
    );
  }
}

class _NavEntry {
  final String key;
  final String label;
  final IconData icon;
  const _NavEntry({required this.key, required this.label, required this.icon});
}

class RouteView extends StatelessWidget {
  const RouteView({super.key, required this.route});
  final String route;
  @override
  Widget build(BuildContext context) => routeScreens(route);
}
