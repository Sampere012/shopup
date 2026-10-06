import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/db_service.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart' show U;

/// Plan con features incluidas, consumo/uso y planes disponibles.
/// Cambio de plan IGUAL QUE LA WEB: elegir plan → solicitar upgrade
/// (ws_plan_request) → queda pendiente hasta que el admin lo apruebe;
/// se puede cancelar (ws_plan_cancel_request).
class PlanScreen extends StatefulWidget {
  const PlanScreen({super.key});

  @override
  State<PlanScreen> createState() => _PlanScreenState();
}

class _PlanScreenState extends State<PlanScreen> {
  Map<String, dynamic> _data = {};
  bool _loading = true;
  bool _busyRequest = false;

  @override
  void initState() {
    super.initState();
    _load();
    // La caché local puede ser anterior al servidor: refresca el plan y los
    // planes disponibles (features/límites) apenas se abre la pantalla.
    _pull();
  }

  Future<void> _load() async {
    final raw = await DbService.I.cacheGet('ws_plan_info');
    if (raw is Map && mounted) {
      setState(() {
        _data = Map<String, dynamic>.from(raw);
        _loading = false;
      });
    } else if (mounted) {
      setState(() => _loading = false);
    }
  }

  /// Refresca el estado del plan desde la nube y repinta (pull-to-refresh).
  Future<void> _pull() async {
    await SyncService.I.pullCache('ws_plan_info', {}, 'ws_plan_info');
    await _load();
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_data.isEmpty) {
      return Center(
        child: Text('Sin información de plan.',
            style: TextStyle(color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted)),
      );
    }

    final locked = _data['locked'] == true;
    final planName = '${_data['plan_name'] ?? _data['plan'] ?? 'Gratuito'}';
    final statusLabel = '${_data['status_label'] ?? ''}';
    final isActive = _data['is_active'] == true;
    final isTrial = _data['is_trial'] == true;
    final trialDaysLeft = (_data['trial_days_left'] as num?)?.toInt() ?? 0;
    final planDaysLeft = (_data['plan_days_left'] as num?)?.toInt() ?? 0;
    final usage = _data['usage'] is Map ? Map<String, dynamic>.from(_data['usage'] as Map) : <String, dynamic>{};
    final limits = _data['limits'] is Map ? Map<String, dynamic>.from(_data['limits'] as Map) : <String, dynamic>{};
    // Features del plan actual (límites + chatbot) desde el servidor.
    final planFeatures = ((_data['plan_features'] as List?)?.whereType<Map>()
        .map<Map<String, dynamic>>((e) => Map<String, dynamic>.from(e))
        .toList() ?? <Map<String, dynamic>>[]);
    // IGUAL QUE LA WEB: sin el plan legacy interno. Filtrado defensivo extra
    // por si el servidor aún lo mandara (is_active=0 o slug legacy).
    final plans = ((_data['plans'] as List?)?.whereType<Map>()
        .map<Map<String, dynamic>>((e) => Map<String, dynamic>.from(e))
        .where((p) => '${p['slug'] ?? ''}' != 'legacy')
        .toList() ?? <Map<String, dynamic>>[]);
    final upgradePending = _data['upgrade_pending'] == true;
    final rejected = '${_data['upgrade_status'] ?? ''}' == 'rejected';
    // Funciones del plan ACTUAL (límites + chatbot), igual que la web.
    final currentFeatures = _featuresFor(planFeatures, limits);

    return RefreshIndicator(
      onRefresh: _pull,
      child: ListView(
        padding: const EdgeInsets.all(14),
        children: [
          // Plan status card
          _buildStatusCard(planName, statusLabel, locked, isActive, isTrial, trialDaysLeft, planDaysLeft, isDark),
          const SizedBox(height: 16),

          // Solicitud pendiente / rechazada (igual que la web).
          if (upgradePending) _pendingBanner(isDark, planName: '${_data['upgrade_plan'] ?? ''}'),
          if (rejected && !upgradePending)
            Container(
              margin: const EdgeInsets.only(bottom: 14),
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                color: AppTheme.amber.withAlpha(18),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppTheme.amber.withAlpha(60)),
              ),
              child: const Row(children: [
                Icon(Icons.close, color: AppTheme.amber, size: 20),
                SizedBox(width: 10),
                Expanded(
                    child: Text('Tu última solicitud de plan fue rechazada. Puedes solicitar otro plan.',
                        style: TextStyle(fontSize: 12.5))),
              ]),
            ),

          // Usage / consumption
          if (usage.isNotEmpty || limits.isNotEmpty) ...[
            Text('Uso del plan',
                style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            _buildUsageSection(usage, limits, isDark),
            const SizedBox(height: 16),
          ],

          // Features incluidas según el plan (límites + chatbot, como la web)
          if (currentFeatures.isNotEmpty) ...[
            Text('Funciones incluidas',
                style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            ...currentFeatures.map((f) => _featureTile(f, isDark)),
            const SizedBox(height: 16),
          ],

          // Planes disponibles para upgrade
          if (plans.isNotEmpty) ...[
            Text('Cambiar de plan',
                style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
            const SizedBox(height: 4),
            Text('Elige el plan que necesitas y envía la solicitud: el administrador la aprobará.',
                style: TextStyle(color: Colors.grey[600], fontSize: 12)),
            const SizedBox(height: 8),
            ...plans.map((p) => _planCard(p, isDark)),
          ],
        ],
      ),
    );
  }

  // ---------------- Cambio de plan (igual que la web) ----------------

  bool get _isOwner => '${(AuthService.I.me ?? const {})['role']}' == 'owner';

  Future<void> _requestUpgrade(Map<String, dynamic> p) async {
    final name = '${p['name'] ?? ''}';
    if (!await U.confirm(context,
        '¿Solicitar el plan $name? El administrador lo revisará y habilitará tu negocio cuando lo apruebe.',
        action: 'Solicitar')) {
      return;
    }
    setState(() => _busyRequest = true);
    try {
      await ApiService.I.req('ws_plan_request', {'plan_id': p['id'] ?? 0});
      if (!mounted) return;
      U.toast(context, 'Solicitud enviada. El administrador la revisará.');
      await SyncService.I.pullCache('ws_plan_info', {}, 'ws_plan_info');
      await _load();
      setState(() {});
    } on ApiException catch (e) {
      if (mounted) U.toast(context, e.message, kind: 'err');
    } catch (_) {
      if (mounted) U.toast(context, 'No se pudo enviar la solicitud', kind: 'err');
    } finally {
      if (mounted) setState(() => _busyRequest = false);
    }
  }

  Future<void> _cancelUpgrade() async {
    if (!await U.confirm(context, '¿Cancelar tu solicitud de upgrade?', action: 'Cancelar solicitud')) {
      return;
    }
    setState(() => _busyRequest = true);
    try {
      await ApiService.I.req('ws_plan_cancel_request', {});
      if (!mounted) return;
      U.toast(context, 'Solicitud cancelada.');
      await SyncService.I.pullCache('ws_plan_info', {}, 'ws_plan_info');
      await _load();
      setState(() {});
    } on ApiException catch (e) {
      if (mounted) U.toast(context, e.message, kind: 'err');
    } catch (_) {
      if (mounted) U.toast(context, 'No se pudo cancelar', kind: 'err');
    } finally {
      if (mounted) setState(() => _busyRequest = false);
    }
  }

  Widget _pendingBanner(bool isDark, {String? planName}) {
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppTheme.amber.withAlpha(18),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppTheme.amber.withAlpha(60)),
      ),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Icon(Icons.hourglass_top, color: AppTheme.amber, size: 20),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
              planName != null && planName.isNotEmpty
                  ? 'Tienes una solicitud pendiente para el plan $planName. El administrador la revisará y habilitará tu negocio cuando la apruebe.'
                  : 'Tienes una solicitud de upgrade pendiente de aprobación.',
              style: const TextStyle(fontSize: 12.5, height: 1.35)),
        ),
        if (_isOwner)
          TextButton(
            onPressed: _busyRequest ? null : _cancelUpgrade,
            child: const Text('Cancelar', style: TextStyle(fontSize: 12)),
          ),
      ]),
    );
  }

  Widget _buildStatusCard(String planName, String statusLabel, bool locked,
      bool isActive, bool isTrial, int trialDaysLeft, int planDaysLeft, bool isDark) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        gradient: LinearGradient(
          colors: locked
              ? [AppTheme.amber, AppTheme.amber.withAlpha(180)]
              : [AppTheme.success, AppTheme.success.withAlpha(180)],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        borderRadius: BorderRadius.circular(14),
        boxShadow: [
          BoxShadow(
            color: (locked ? AppTheme.amber : AppTheme.success).withAlpha(60),
            blurRadius: 16,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(
          locked ? Icons.lock_outline : Icons.workspace_premium_outlined,
          size: 32,
          color: Colors.white,
        ),
        const SizedBox(height: 12),
        Text(planName,
            style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800)),
        if (statusLabel.isNotEmpty)
          Text(statusLabel, style: TextStyle(color: Colors.white.withAlpha(200), fontSize: 13)),
        if (isTrial && trialDaysLeft > 0)
          Text('Prueba: $trialDaysLeft día${trialDaysLeft == 1 ? '' : 's'} restante${trialDaysLeft == 1 ? '' : 's'}',
              style: TextStyle(color: Colors.white.withAlpha(200), fontSize: 13)),
        if (!isTrial && planDaysLeft > 0)
          Text('Plan: $planDaysLeft día${planDaysLeft == 1 ? '' : 's'} restante${planDaysLeft == 1 ? '' : 's'}',
              style: TextStyle(color: Colors.white.withAlpha(200), fontSize: 13)),
        if (locked) ...[
          const SizedBox(height: 8),
          const Text('Tu negocio está en pausa. Actualiza tu plan para continuar.',
              style: TextStyle(color: Colors.white, fontSize: 13)),
        ],
      ]),
    );
  }

  Widget _buildUsageSection(Map<String, dynamic> usage, Map<String, dynamic> limits, bool isDark) {
    final items = <_UsageItem>[];

    // Map common usage keys
    final usageMap = {
      'products': ('Productos', Icons.inventory_2_outlined),
      'locations': ('Ubicaciones', Icons.location_on_outlined),
      'workers': ('Trabajadores', Icons.groups_2_outlined),
      'orders': ('Pedidos web', Icons.receipt_long_outlined),
      'customers': ('Clientes', Icons.people_outline),
      'pos_sales': ('Ventas POS', Icons.point_of_sale),
    };

    for (final entry in usage.entries) {
      final key = entry.key;
      final val = (entry.value is num) ? (entry.value as num).toInt() : 0;
      final limitVal = limits[key];
      final lim = (limitVal is num) ? limitVal.toInt() : -1; // -1 = unlimited
      final label = usageMap[key]?.$1 ?? key;
      final icon = usageMap[key]?.$2 ?? Icons.analytics_outlined;
      items.add(_UsageItem(label: label, icon: icon, used: val, limit: lim));
    }

    if (items.isEmpty) return const SizedBox.shrink();

    return Column(
      children: items.map((item) {
        final pct = item.limit > 0 ? (item.used / item.limit).clamp(0.0, 1.0) : 0.0;
        final isOver = item.limit > 0 && item.used >= item.limit;
        final color = isOver ? AppTheme.danger : (pct > 0.8 ? AppTheme.amber : AppTheme.success);

        return Container(
          margin: const EdgeInsets.only(bottom: 8),
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: isDark ? AppTheme.darkCard : Colors.white,
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: isDark ? AppTheme.darkBorder : AppTheme.lightBorder),
          ),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Icon(item.icon, size: 18, color: color),
              const SizedBox(width: 8),
              Text(item.label,
                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
              const Spacer(),
              Text('${item.used}${item.limit > 0 ? ' / ${item.limit}' : ''}',
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: color)),
            ]),
            if (item.limit > 0) ...[
              const SizedBox(height: 6),
              ClipRRect(
                borderRadius: BorderRadius.circular(4),
                child: LinearProgressIndicator(
                  value: pct,
                  minHeight: 6,
                  backgroundColor: isDark ? AppTheme.darkSurface : Colors.grey[200],
                  valueColor: AlwaysStoppedAnimation(color),
                ),
              ),
            ],
          ]),
        );
      }).toList(),
    );
  }

  /// Features del plan actual. Prioridad: las que manda el servidor
  /// (plan_features, mismo formato que la web). Fallback: límites conocidos.
  /// Regla: límites SIEMPRE incluidos (0 = ilimitado ∞); chatbot según value.
  List<_Feature> _featuresFor(List<Map<String, dynamic>> planFeatures, Map<String, dynamic> limits) {
    if (planFeatures.isNotEmpty) {
      return [
        for (final f in planFeatures)
          _Feature(
            '${f['key'] ?? ''}' == 'chatbot'
                ? '${f['label'] ?? ''}'
                : '${f['label'] ?? ''}: ${f['text'] ?? ''}',
            _featureIcon('${f['key'] ?? ''}'),
            included: '${f['key'] ?? ''}' == 'chatbot'
                ? ((f['value'] as num?)?.toInt() ?? 0) == 1
                : true,
          ),
      ];
    }
    // Fallback (servidor sin plan_features): límites conocidos con su
    // etiqueta en español, misma presentación "Límite: cantidad | ∞".
    const labels = {
      'products': 'Productos',
      'users': 'Usuarios',
      'pvs': 'Puntos de venta',
      'warehouses': 'Almacenes',
      'suppliers': 'Proveedores',
    };
    const icons = {
      'products': Icons.inventory_2_outlined,
      'users': Icons.groups_2_outlined,
      'pvs': Icons.store_outlined,
      'warehouses': Icons.warehouse_outlined,
      'suppliers': Icons.local_shipping_outlined,
    };
    return [
      for (final e in limits.entries)
        _Feature(
          '${labels[e.key] ?? e.key}: ${(e.value is num && (e.value as num).toInt() > 0) ? e.value : '∞'}',
          icons[e.key] ?? Icons.check_circle_outline,
        ),
    ];
  }

  IconData _featureIcon(String key) {
    const icons = {
      'products': Icons.inventory_2_outlined,
      'users': Icons.groups_2_outlined,
      'pvs': Icons.store_outlined,
      'warehouses': Icons.warehouse_outlined,
      'suppliers': Icons.local_shipping_outlined,
      'chatbot': Icons.smart_toy_outlined,
    };
    return icons[key] ?? Icons.check_circle_outline;
  }

  Widget _featureTile(_Feature f, bool isDark) {
    final ok = f.included;
    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: isDark ? AppTheme.darkCard : Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: isDark ? AppTheme.darkBorder : AppTheme.lightBorder),
      ),
      child: Row(children: [
        Icon(f.icon, size: 20, color: ok ? AppTheme.success : Colors.grey),
        const SizedBox(width: 12),
        Expanded(child: Text(f.label, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500))),
        Icon(ok ? Icons.check_circle : Icons.cancel,
            size: 18, color: (ok ? AppTheme.success : Colors.grey).withAlpha(180)),
      ]),
    );
  }

  Widget _planCard(Map<String, dynamic> p, bool isDark) {
    final name = '${p['name'] ?? ''}';
    final isCurrent = !_data.isEmpty && name == '${_data['plan_name'] ?? ''}';
    final isTrialPlan = p['is_trial'] == true;
    final price = '${p['price_text'] ?? ''}';
    final duration = '${p['duration_label'] ?? ''}';
    final popular = '${p['slug'] ?? ''}' == 'pro';
    final upgradePending = _data['upgrade_pending'] == true;
    final canRequest = _isOwner;
    // Features/límites del plan (lista "X: N" o "∞"), IGUAL QUE LA WEB.
    final features = ((p['features'] as List?)?.whereType<Map>()
        .map<Map<String, dynamic>>((e) => Map<String, dynamic>.from(e))
        .toList() ?? <Map<String, dynamic>>[]);

    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: (popular ? AppTheme.amber : AppTheme.primary).withAlpha(20),
                borderRadius: BorderRadius.circular(10),
              ),
              child: Icon(Icons.workspace_premium_outlined,
                  color: popular ? AppTheme.amber : AppTheme.primary, size: 20),
            ),
            const SizedBox(width: 10),
            Expanded(child: Text(name,
                style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))),
            if (popular)
              U.badge('Más popular', color: AppTheme.amber, small: true),
            if (isTrialPlan) ...[
              const SizedBox(width: 4),
              U.badge('Prueba gratis', color: AppTheme.primary, small: true),
            ],
          ]),
          const SizedBox(height: 8),
          if (price.isNotEmpty)
            Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(price,
                  style: const TextStyle(
                      color: AppTheme.success, fontWeight: FontWeight.w800, fontSize: 17)),
              if (duration.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(left: 4, bottom: 2),
                  child: Text(duration,
                      style: TextStyle(color: Colors.grey[600], fontSize: 11)),
                ),
            ]),
          if ('${p['description'] ?? ''}'.isNotEmpty)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text('${p['description']}',
                  style: TextStyle(color: Colors.grey[600], fontSize: 12)),
            ),
          const SizedBox(height: 10),
          // Features del plan (límites + chatbot), igual que la web.
          if (features.isNotEmpty) ...[
            for (final f in features)
              Padding(
                padding: const EdgeInsets.only(bottom: 4),
                child: Row(children: [
                  Icon(
                    '${f['key'] ?? ''}' == 'chatbot'
                        ? (((f['value'] as num?)?.toInt() ?? 0) == 1
                            ? Icons.check_circle_outline
                            : Icons.cancel_outlined)
                        : Icons.check_circle_outline,
                    size: 15,
                    color: '${f['key'] ?? ''}' == 'chatbot' && ((f['value'] as num?)?.toInt() ?? 0) == 0
                        ? Colors.grey
                        : AppTheme.success,
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                      child: Text(
                          '${f['key'] ?? ''}' == 'chatbot'
                              ? '${f['label'] ?? ''}'
                              : '${f['label'] ?? ''}: ${f['text'] ?? ''}',
                          style: const TextStyle(fontSize: 12.5))),
                ]),
              ),
            const SizedBox(height: 8),
          ],
          if (isCurrent)
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                onPressed: null,
                icon: const Icon(Icons.check, size: 16),
                label: const Text('Plan actual'),
              ),
            )
          else if (upgradePending)
            SizedBox(
              width: double.infinity,
              child: OutlinedButton.icon(
                onPressed: null,
                icon: const Icon(Icons.hourglass_top, size: 16),
                label: const Text('Solicitud pendiente'),
              ),
            )
          else if (isTrialPlan)
            SizedBox(
              width: double.infinity,
              child: OutlinedButton(
                onPressed: null,
                child: const Text('Solo para negocios nuevos'),
              ),
            )
          else if (canRequest)
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                onPressed: _busyRequest ? null : () => _requestUpgrade(p),
                icon: const Icon(Icons.arrow_upward, size: 16),
                label: const Text('Solicitar upgrade'),
              ),
            )
          else
            SizedBox(
              width: double.infinity,
              child: OutlinedButton(onPressed: null, child: const Text('Disponible')),
            ),
        ]),
      ),
    );
  }
}

class _Feature {
  final String label;
  final IconData icon;
  final bool included;
  const _Feature(this.label, this.icon, {this.included = true});
}

class _UsageItem {
  final String label;
  final IconData icon;
  final int used;
  final int limit;
  const _UsageItem({required this.label, required this.icon, required this.used, required this.limit});
}
