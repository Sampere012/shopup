import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/db_service.dart';

/// Plan con features incluidas, consumo/uso y planes disponibles.
class PlanScreen extends StatefulWidget {
  const PlanScreen({super.key});

  @override
  State<PlanScreen> createState() => _PlanScreenState();
}

class _PlanScreenState extends State<PlanScreen> {
  Map<String, dynamic> _data = {};
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
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
    final plans = ((_data['plans'] as List?)?.whereType<Map>()
        .map<Map<String, dynamic>>((e) => Map<String, dynamic>.from(e))
        .toList() ?? <Map<String, dynamic>>[]);

    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: const EdgeInsets.all(14),
        children: [
          // Plan status card
          _buildStatusCard(planName, statusLabel, locked, isActive, isTrial, trialDaysLeft, planDaysLeft, isDark),
          const SizedBox(height: 16),

          // Usage / consumption
          if (usage.isNotEmpty || limits.isNotEmpty) ...[
            Text('Uso del plan',
                style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            _buildUsageSection(usage, limits, isDark),
            const SizedBox(height: 16),
          ],

          // Features incluidas según el plan
          Text('Funciones incluidas',
              style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          ..._planFeaturesFor(planName).map((f) => _featureTile(f, isDark)),
          const SizedBox(height: 16),

          // Planes disponibles para upgrade
          if (plans.isNotEmpty) ...[
            Text('Planes disponibles',
                style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
            const SizedBox(height: 8),
            ...plans.map((p) => _planCard(p, isDark)),
          ],
        ],
      ),
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

  List<_Feature> _planFeaturesFor(String plan) {
    const features = {
      'Gratuito': [
        _Feature('Inventario básico', Icons.inventory_2_outlined),
        _Feature('Punto de venta', Icons.point_of_sale_outlined),
        _Feature('Pedidos web', Icons.receipt_long_outlined),
        _Feature('Clientes', Icons.groups_2_outlined),
        _Feature('Reportes básicos', Icons.analytics_outlined),
      ],
      'Básico': [
        _Feature('Todo lo del plan Gratuito', Icons.check_circle_outline),
        _Feature('Movimientos de stock', Icons.swap_horiz),
        _Feature('Historial de movimientos', Icons.history),
        _Feature('Turnos de trabajo', Icons.schedule),
        _Feature('Gastos', Icons.payments_outlined),
        _Feature('Pedidos congelados', Icons.ac_unit),
      ],
      'Profesional': [
        _Feature('Todo lo del plan Básico', Icons.check_circle_outline),
        _Feature('Transferencias entre ubicaciones', Icons.swap_horizontal_circle_outlined),
        _Feature('Cuadre de inventario', Icons.fact_check_outlined),
        _Feature('Trabajadores y permisos', Icons.manage_accounts_outlined),
        _Feature('Valoraciones', Icons.star_outline),
        _Feature('Fidelización', Icons.card_giftcard_outlined),
        _Feature('Anuncios', Icons.campaign_outlined),
        _Feature('Reportes avanzados', Icons.leaderboard_outlined),
      ],
      'Empresarial': [
        _Feature('Todo lo del plan Profesional', Icons.check_circle_outline),
        _Feature('Múltiples ubicaciones', Icons.store_outlined),
        _Feature('Catálogo PDF', Icons.picture_as_pdf),
        _Feature('API y webhooks', Icons.code_outlined),
        _Feature('Soporte prioritario', Icons.support_agent_outlined),
        _Feature('Configuración avanzada', Icons.tune),
      ],
    };
    return features[plan] ?? features['Gratuito']!;
  }

  Widget _featureTile(_Feature f, bool isDark) {
    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: isDark ? AppTheme.darkCard : Colors.white,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: isDark ? AppTheme.darkBorder : AppTheme.lightBorder),
      ),
      child: Row(children: [
        Icon(f.icon, size: 20, color: AppTheme.success),
        const SizedBox(width: 12),
        Expanded(child: Text(f.label, style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500))),
        Icon(Icons.check_circle, size: 18, color: AppTheme.success.withAlpha(180)),
      ]),
    );
  }

  Widget _planCard(Map<String, dynamic> p, bool isDark) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        leading: Container(
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(
            color: AppTheme.primary.withAlpha(20),
            borderRadius: BorderRadius.circular(10),
          ),
          child: Icon(Icons.workspace_premium_outlined, color: AppTheme.primary, size: 20),
        ),
        title: Text('${p['name'] ?? ''}',
            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
        subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if ('${p['price_text'] ?? ''}'.isNotEmpty)
            Text('${p['price_text']}', style: TextStyle(color: AppTheme.success, fontWeight: FontWeight.w600, fontSize: 13)),
          if ('${p['description'] ?? ''}'.isNotEmpty)
            Text('${p['description']}', style: TextStyle(color: Colors.grey[600], fontSize: 12), maxLines: 2, overflow: TextOverflow.ellipsis),
        ]),
        isThreeLine: true,
      ),
    );
  }
}

class _Feature {
  final String label;
  final IconData icon;
  const _Feature(this.label, this.icon);
}

class _UsageItem {
  final String label;
  final IconData icon;
  final int used;
  final int limit;
  const _UsageItem({required this.label, required this.icon, required this.used, required this.limit});
}
