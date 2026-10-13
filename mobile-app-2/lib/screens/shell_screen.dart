import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../config.dart';
import '../theme/app_theme.dart';
import '../services/auth_service.dart';
import '../services/sync_service.dart';
import '../services/db_service.dart';
import '../services/theme_service.dart';
import '../services/update_service.dart';
import '../services/plan_guard_service.dart';
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
  bool _hasUpdate = false;
  bool _busyUpdate = false;
  bool _showingInstalledChangelog = false;

  // Búsqueda global de vistas (icono del navbar).
  bool _searchMode = false;
  final _searchCtrl = TextEditingController();
  List<_SearchTarget> _searchMatches = [];
  Timer? _searchDebounce;

  // Anuncios fijados (banners), igual que en el panel web: se muestran a
  // todos los usuarios, activos y anclados, salvo los descartados.
  List<Map<String, dynamic>> _announcements = [];

  static const _bottomNavKeys = ['dashboard', 'products', 'stock', 'pos', 'pos-sales'];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    SyncService.I.start();
    // Worker local de licencia: verifica el plan contra la nube cada pocos
    // minutos y bloquea el negocio si la suscripción venció/suspendió.
    PlanGuardService.I.start();
    _sessionRefreshTimer = Timer.periodic(const Duration(minutes: 5), (_) => _refreshSession());
    _updateNotifBadge();
    _checkUpdate();
    _loadAnnouncements();
    SyncService.I.onChange(_updateNotifBadge);
    SyncService.I.onChange(_loadAnnouncements);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _sessionRefreshTimer?.cancel();
    _searchDebounce?.cancel();
    _searchCtrl.dispose();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      AuthService.I.refresh();
      SyncService.I.checkConnectivity();
      // Al volver a primer plano: verificación inmediata contra la nube.
      PlanGuardService.I.checkFromCloud();
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

  /// Carga los anuncios fijados por el negocio (banners del panel web).
  Future<void> _loadAnnouncements() async {
    try {
      final raw = await DbService.I.cacheGet('ws_announcements_pinned');
      final rows = raw is List
          ? List<Map<String, dynamic>>.from(raw.whereType<Map>())
          : <Map<String, dynamic>>[];
      final sp = await SharedPreferences.getInstance();
      final dismissed = (sp.getStringList('ws_dismissed_anns') ?? []).toSet();
      final visible = rows
          .where((a) =>
              '${a['active'] ?? '1'}' != '0' &&
              !dismissed.contains('${a['id']}'))
          .toList();
      if (mounted) setState(() => _announcements = visible);
    } catch (_) {}
  }

  Future<void> _dismissAnnouncement(String id) async {
    final sp = await SharedPreferences.getInstance();
    final list = sp.getStringList('ws_dismissed_anns') ?? <String>[];
    if (!list.contains(id)) list.add(id);
    await sp.setStringList('ws_dismissed_anns', list);
    await _loadAnnouncements();
  }

  /// Banner de un anuncio fijado (colores como ws-banner-* de la web).
  Widget _announcementsBar(bool isDark) {
    if (_announcements.isEmpty) return const SizedBox.shrink();
    const colors = {
      'info': Color(0xFF2563EB),
      'success': Color(0xFF059669),
      'warning': Color(0xFFD97706),
      'danger': Color(0xFFDC2626),
    };
    const icons = {
      'info': Icons.info_outline,
      'success': Icons.check_circle_outline,
      'warning': Icons.warning_amber_outlined,
      'danger': Icons.error_outline,
    };
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
      child: Column(children: _announcements.map((a) {
        final type = colors.containsKey('${a['type']}') ? '${a['type']}' : 'info';
        final color = colors[type]!;
        final dismissible = '${a['dismissible'] ?? '1'}' != '0';
        return Container(
          margin: const EdgeInsets.only(bottom: 8),
          padding: const EdgeInsets.fromLTRB(12, 10, 6, 10),
          decoration: BoxDecoration(
            color: color.withAlpha(isDark ? 35 : 22),
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: color.withAlpha(70)),
          ),
          child: Row(children: [
            Icon(icons[type], color: color, size: 20),
            const SizedBox(width: 10),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min, children: [
                Text('${a['title'] ?? ''}',
                    style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                        color: isDark ? Colors.white : const Color(0xFF0F172A))),
                if ('${a['message'] ?? ''}'.isNotEmpty)
                  Text('${a['message']}',
                      style: TextStyle(
                          fontSize: 12,
                          height: 1.35,
                          color: isDark ? Colors.white70 : const Color(0xFF334155))),
              ]),
            ),
            if (dismissible)
              IconButton(
                tooltip: 'Ocultar',
                visualDensity: VisualDensity.compact,
                icon: const Icon(Icons.close, size: 18),
                onPressed: () => _dismissAnnouncement('${a['id']}'),
              ),
          ]),
        );
      }).toList()),
    );
  }

  Future<void> _checkUpdate() async {
    final has = await UpdateService.I.check(silent: true);
    final info = UpdateService.I.updateInfo;
    if (mounted) {
      setState(() => _hasUpdate = has);
      if (!has && info != null) {
        await _showInstalledChangelogIfNeeded(info);
      }
      // Show changelog dialog if there's an update and we haven't shown it yet
      if (has && info != null && mounted) {
        final sp = await SharedPreferences.getInstance();
        final lastShown = sp.getString('_last_update_dialog');
        final newVersion = '${info['version'] ?? ''}';
        if (lastShown != newVersion) {
          await sp.setString('_last_update_dialog', newVersion);
          // Delay to avoid showing during startup animation
          Future.delayed(const Duration(seconds: 3), () {
            if (mounted) UpdateService.showUpdateDialog(context, info);
          });
        }
      }
    }
  }

  Future<void> _showInstalledChangelogIfNeeded(
      Map<String, dynamic> info) async {
    if (_showingInstalledChangelog ||
        UpdateService.compareVersions(
                '${info['version'] ?? ''}', AppConfig.appVersion) !=
            0) {
      return;
    }
    final prefs = await SharedPreferences.getInstance();
    const key = '_last_installed_changelog_version';
    final previous = prefs.getString(key);
    if (previous == null) {
      await prefs.setString(key, AppConfig.appVersion);
      return;
    }
    if (previous == AppConfig.appVersion) return;

    _showingInstalledChangelog = true;
    Future.delayed(const Duration(seconds: 3), () async {
      if (!mounted) {
        _showingInstalledChangelog = false;
        return;
      }
      await UpdateService.showInstalledChangelog(context, info);
      await prefs.setString(key, AppConfig.appVersion);
      _showingInstalledChangelog = false;
    });
  }

  /// Descarga el APK (vía Download Manager del sistema con notificación) e
  /// intenta abrirlo inmediatamente para instalar. Si el navegador solo
  /// descarga sin instalar, la notificación de descarga permite abrirlo.
  ///
  /// Espera a que la descarga se lance de verdad antes de avisar: el botón
  /// del navbar tiene que responder siempre, así que si no hay datos en
  /// caché se vuelve a consultar la versión al servidor en lugar de no
  /// hacer nada.
  Future<void> _updateNow() async {
    if (!mounted || _busyUpdate) return;
    setState(() => _busyUpdate = true);
    try {
      var info = UpdateService.I.updateInfo;
      if (info == null) {
        await UpdateService.I.check(silent: false);
        info = UpdateService.I.updateInfo;
        if (mounted) setState(() => _hasUpdate = UpdateService.I.hasUpdate);
      }
      if (info == null) {
        if (mounted) U.toast(context, 'No se pudo comprobar la actualización', kind: 'err');
        return;
      }
      final ok = await UpdateService.launchDownload(info);
      if (!mounted) return;
      U.toast(
          context,
          ok
              ? 'Descargando la actualización… busca la notificación al terminar'
              : 'No se pudo abrir la descarga',
          kind: ok ? 'ok' : 'err');
    } finally {
      if (mounted) setState(() => _busyUpdate = false);
    }
  }

  void _go(String key) {
    NavBus.clear();
    setState(() => _route = key);
    Navigator.of(context).pop();
  }

  /// Cierra el modo búsqueda y limpia el estado.
  void _closeSearch({bool clearQuery = true}) {
    _searchDebounce?.cancel();
    if (clearQuery) _searchCtrl.clear();
    _searchMatches = [];
    if (mounted) setState(() => _searchMode = false);
  }

  /// Navega a una vista desde la búsqueda y cierra el modo búsqueda.
  void _goSearch(String route) {
    _closeSearch();
    NavBus.clear();
    setState(() => _route = route);
  }

  /// Quita acentos y pasa a minúsculas para comparar sin importar tildes.
  static String _norm(String s) => s
      .toLowerCase()
      .replaceAll(RegExp(r'[áàäâ]'), 'a')
      .replaceAll(RegExp(r'[éèëê]'), 'e')
      .replaceAll(RegExp(r'[íìïî]'), 'i')
      .replaceAll(RegExp(r'[óòöô]'), 'o')
      .replaceAll(RegExp(r'[úùüû]'), 'u')
      .replaceAll(RegExp(r'ñ'), 'n');

  /// Catálogo de vistas buscables: las claves del menú del servidor (ya
  /// filtradas por rol) + las vistas fijas de la app con sus alias en
  /// español. La búsqueda sólo navega a vistas a las que el usuario
  /// realmente puede entrar.
  List<_SearchTarget> _buildCatalog(List<Map<String, dynamic>> menu) {
    const aliases = <String, List<String>>{
      'dashboard': ['inicio', 'panel', 'resumen'],
      'products': ['productos', 'producto', 'catalogo'],
      'locations': ['ubicaciones', 'ubicacion', 'sucursales', 'almacenes'],
      'stock': ['stock', 'inventario', 'almacen'],
      'counts': ['cuadres', 'cuadre', 'conteo'],
      'movements': ['movimientos', 'transferencias', 'transferencia'],
      'orders': ['pedidos', 'pedido', 'encargos'],
      'shifts': ['turnos', 'turno', 'caja', 'arqueo'],
      'workers': ['trabajadores', 'trabajador', 'equipo', 'empleados'],
      'customers': ['clientes', 'cliente'],
      'pos': ['ventas', 'venta', 'pos', 'punto de venta'],
      'pos-sales': ['historial de ventas', 'ventas realizadas'],
      'reviews': ['resenas', 'opiniones', 'estrellas'],
      'loyalty': ['lealtad', 'fidelidad', 'puntos', 'recompensas'],
      'expenses': ['gastos', 'gasto'],
      'anuncios': ['anuncios', 'anuncio'],
      'plan': ['plan', 'suscripcion'],
      'permissions': ['permisos', 'permiso', 'modulos'],
      'reports': ['reportes', 'reporte', 'estadisticas', 'graficas'],
      'appearance': ['apariencia', 'tema', 'colores'],
      'settings': ['ajustes', 'configuracion', 'preferencias'],
    };
    final byRoute = <String, _SearchTarget>{};
    // Vistas fijas que no dependen del menú del servidor.
    void addFixed(String route, String label, List<String> extra) {
      byRoute[route] = _SearchTarget(
        route: route,
        label: label,
        aliases: [...?aliases[route], ...extra],
      );
    }

    addFixed('notificaciones', 'Notificaciones',
        const ['notificaciones', 'avisos', 'alertas']);
    addFixed('account', 'Mi cuenta', const ['cuenta', 'perfil', 'mi cuenta']);
    addFixed('pending', 'Pendientes', const ['pendientes', 'cola']);
    if ('${AuthService.I.me?['role'] ?? ''}' == 'owner') {
      addFixed('support', 'Ayuda y soporte',
          const ['soporte', 'ayuda', 'contacto', 'inquietud']);
    }
    // Menú del servidor (respeta los permisos del rol).
    for (final m in menu) {
      final route = '${m['key']}';
      if (route.isEmpty || byRoute.containsKey(route)) continue;
      byRoute[route] = _SearchTarget(
        route: route,
        label: '${m['label']}',
        aliases: aliases[route] ?? const [],
      );
    }
    return byRoute.values.toList();
  }

  /// Coincidencias del catálogo para la consulta (exacta > prefijo >
  /// contiene), normalizando acentos.
  List<_SearchTarget> _searchIn(String query, List<_SearchTarget> catalog) {
    final q = _norm(query.trim());
    if (q.isEmpty) return const [];
    final scored = <MapEntry<int, _SearchTarget>>[];
    for (final t in catalog) {
      final label = _norm(t.label);
      final aliases = t.aliases.map(_norm).toList();
      int score = -1;
      if (label == q || aliases.contains(q)) {
        score = 0;
      } else if (label.startsWith(q) || aliases.any((a) => a.startsWith(q))) {
        score = 1;
      } else if (label.contains(q) || aliases.any((a) => a.contains(q))) {
        score = 2;
      } else if (_norm(t.route).contains(q)) {
        score = 3;
      }
      if (score >= 0) scored.add(MapEntry(score, t));
    }
    scored.sort((a, b) => a.key.compareTo(b.key));
    return scored.map((e) => e.value).toList();
  }

  /// Al teclear: refresca las sugerencias (con debounce) y si queda una
  /// única coincidencia navega sola a esa vista.
  void _onSearchChanged(String raw, List<_SearchTarget> catalog) {
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 400), () {
      if (!mounted || !_searchMode) return;
      final matches = _searchIn(raw, catalog);
      setState(() => _searchMatches = matches.take(5).toList());
      if (raw.trim().isNotEmpty && matches.length == 1) {
        _goSearch(matches.first.route);
      }
    });
  }

  Future<void> _manualSync() async {
    final sync = SyncService.I;
    if (sync.isBusy) return;
    final res = await sync.syncNow().catchError((_) => 'offline' as Object);
    if (!mounted) return;
    if (res == 'blocked') {
      U.toast(context, 'Tu plan está en pausa: no se puede sincronizar', kind: 'err');
    } else if (res == 'offline') U.toast(context, 'Sin conexión: cambios guardados en el dispositivo', kind: 'warn');
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
      'notificaciones': Icons.notifications_none, 'support': Icons.support_agent_outlined,
      'more': Icons.menu,
    };
    return byKey[routeKey ?? ''] ?? Icons.widgets_outlined;
  }

  String _titleFor(String route) {
    // Títulos fijos para vistas que no vienen en el menú del servidor.
    const fixed = {'support': 'Ayuda y soporte'};
    if (fixed.containsKey(route)) return fixed[route]!;
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
    final planGuard = context.watch<PlanGuardService>();
    final planLocked = planGuard.isBlocked;
    final menu = auth.menu.whereType<Map>().map((m) => Map<String, dynamic>.from(m)).toList();
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final isTablet = MediaQuery.of(context).size.width > 600;

    final bottomItems = <_NavEntry>[];
    final searchCatalog = _buildCatalog(menu);
    for (final key in _bottomNavKeys) {
      final m = menu.firstWhere((m) => '${m['key']}' == key, orElse: () => {});
      if (m.isNotEmpty) {
        bottomItems.add(_NavEntry(
            key: key, label: '${m['label']}', icon: _iconFor(m['icon'], key)));
      }
    }
    if (bottomItems.length < 3) bottomItems.add(const _NavEntry(key: 'more', label: 'Más', icon: Icons.menu));

    if (planLocked && _route != 'plan') {
      return Scaffold(
        appBar: AppBar(
          title: const Text('ShopUp Panel'),
          actions: [
            IconButton(
              tooltip: 'Cerrar sesión',
              onPressed: () async {
                if (await U.confirm(context, '¿Cerrar sesión?', action: 'Cerrar sesión')) {
                  await AuthService.I.logout();
                }
              },
              icon: const Icon(Icons.logout),
            ),
          ],
        ),
        body: _buildPlanLocked(context, planGuard),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: _searchMode
            ? TextField(
                controller: _searchCtrl,
                autofocus: true,
                style: const TextStyle(color: Colors.white, fontSize: 16),
                decoration: InputDecoration(
                  hintText: 'Buscar vista…',
                  hintStyle: TextStyle(color: Colors.white.withAlpha(150), fontSize: 16),
                  border: InputBorder.none,
                  isDense: true,
                ),
                onChanged: (v) => _onSearchChanged(v, searchCatalog),
                onSubmitted: (v) {
                  final m = _searchIn(v, searchCatalog);
                  if (m.isNotEmpty) _goSearch(m.first.route);
                },
              )
            : Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(_titleFor(_route), style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                if (auth.businessName.isNotEmpty)
                  Text(auth.businessName, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w400)),
              ]),
        actions: [
          if (_searchMode)
            IconButton(
              tooltip: 'Cerrar búsqueda',
              padding: const EdgeInsets.symmetric(horizontal: 4),
              visualDensity: VisualDensity.compact,
              onPressed: () => _closeSearch(),
              icon: const Icon(Icons.close),
            )
          else
            IconButton(
              tooltip: 'Buscar vista',
              padding: const EdgeInsets.symmetric(horizontal: 4),
              visualDensity: VisualDensity.compact,
              onPressed: () => setState(() {
                _searchMode = true;
                _searchMatches = [];
              }),
              icon: const Icon(Icons.search),
            ),
          if (_hasUpdate)
            Stack(clipBehavior: Clip.none, children: [
              IconButton(
                tooltip: 'Nueva versión: actualizar ahora',
                padding: const EdgeInsets.symmetric(horizontal: 4),
                visualDensity: VisualDensity.compact,
                onPressed: _updateNow,
                icon: const Icon(Icons.system_update_outlined),
              ),
              Positioned(right: 4, top: 4, child: Container(
                width: 8, height: 8,
                decoration: const BoxDecoration(color: AppTheme.success, shape: BoxShape.circle),
              )),
            ]),
          IconButton(
            tooltip: isDark ? 'Modo claro' : 'Modo oscuro',
            padding: const EdgeInsets.symmetric(horizontal: 4),
            visualDensity: VisualDensity.compact,
            onPressed: () => ThemeService.I.toggleDark(),
            icon: Icon(isDark ? Icons.light_mode_outlined : Icons.dark_mode_outlined, color: Colors.white70),
          ),
          IconButton(
            tooltip: 'Sincronizar',
            padding: const EdgeInsets.symmetric(horizontal: 4),
            visualDensity: VisualDensity.compact,
            onPressed: sync.isBusy ? null : _manualSync,
            icon: sync.isBusy
                ? const Padding(padding: EdgeInsets.all(10), child: SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)))
                : const Icon(Icons.sync),
          ),
          Stack(clipBehavior: Clip.none, children: [
            IconButton(
              tooltip: 'Notificaciones',
              padding: const EdgeInsets.symmetric(horizontal: 4),
              visualDensity: VisualDensity.compact,
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
        bottom: _searchMode
            ? PreferredSize(
                preferredSize: const Size.fromHeight(46),
                child: Container(
                  width: double.infinity,
                  color: isDark ? const Color(0xFF0F172A) : Colors.white,
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
                  child: _searchMatches.isEmpty
                      ? Text(
                          _searchCtrl.text.trim().isEmpty
                              ? 'Escribe para buscar entre las vistas'
                              : 'Sin resultados para «${_searchCtrl.text.trim()}»',
                          style: TextStyle(fontSize: 12, color: isDark ? Colors.white54 : Colors.grey[600]),
                        )
                      : Wrap(
                          spacing: 8,
                          runSpacing: 4,
                          children: _searchMatches
                              .map((t) => ActionChip(
                                    avatar: Icon(_iconFor(null, t.route), size: 15, color: AppTheme.primary),
                                    label: Text(t.label, style: const TextStyle(fontSize: 12)),
                                    onPressed: () => _goSearch(t.route),
                                    visualDensity: VisualDensity.compact,
                                  ))
                              .toList(),
                        ),
                ),
              )
            : !sync.isOnline
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
        child: Column(children: [
          _announcementsBar(isDark),
          Expanded(
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
        ]),
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

  Widget _buildPlanLocked(BuildContext context, PlanGuardService planGuard) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Center(child: Padding(padding: const EdgeInsets.all(32), child: Column(mainAxisSize: MainAxisSize.min, children: [
      Container(width: 80, height: 80, decoration: BoxDecoration(
        gradient: LinearGradient(colors: [AppTheme.amber, AppTheme.amber.withAlpha(180)]),
        shape: BoxShape.circle, boxShadow: [BoxShadow(color: AppTheme.amber.withAlpha(60), blurRadius: 24, offset: const Offset(0, 8))],
      ), child: const Icon(Icons.lock_outline, size: 40, color: Colors.white)),
      const SizedBox(height: 24),
      Text(planGuard.lockTitle, style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800), textAlign: TextAlign.center),
      const SizedBox(height: 8),
      Text(planGuard.lockMessage,
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
          // Ayuda y soporte: sólo para el dueño del negocio.
          if ('${auth.me?['role'] ?? ''}' == 'owner')
            ListTile(
              dense: true,
              selected: _route == 'support',
              selectedColor: AppTheme.primaryLight,
              iconColor: Colors.white70,
              textColor: Colors.white,
              leading: Icon(Icons.support_agent_outlined,
                  color: _route == 'support' ? AppTheme.primaryLight : Colors.white70),
              title: Text('Ayuda y soporte',
                  style: TextStyle(
                      fontSize: 14,
                      fontWeight: _route == 'support' ? FontWeight.w700 : FontWeight.w400,
                      color: _route == 'support' ? AppTheme.primaryLight : Colors.white)),
              onTap: () => _go('support'),
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

/// Entrada del catálogo de búsqueda global de vistas.
class _SearchTarget {
  final String route;
  final String label;
  final List<String> aliases;
  const _SearchTarget(
      {required this.route, required this.label, required this.aliases});
}

class RouteView extends StatelessWidget {
  const RouteView({super.key, required this.route});
  final String route;
  @override
  Widget build(BuildContext context) => routeScreens(route);
}
