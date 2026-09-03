import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../theme/app_animations.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';

/// Cola de cambios pendientes con labels descriptivos y contexto por tipo de operación.
class PendingScreen extends StatelessWidget {
  const PendingScreen({super.key});

  // Mapa de acciones → labels, iconos y descripción contextual (como Cordova ACTION_INFO).
  static const _actionInfo = <String, _ActionInfo>{
    'ws_save_product': _ActionInfo('Producto guardado', Icons.inventory_2_outlined, AppTheme.primary),
    'ws_combo_save': _ActionInfo('Combo guardado', Icons.inventory_2_outlined, AppTheme.primary),
    'ws_save_location': _ActionInfo('Ubicación guardada', Icons.location_on_outlined, AppTheme.primary),
    'ws_stock_move': _ActionInfo('Movimiento de stock', Icons.warehouse_outlined, AppTheme.amber),
    'ws_stock_transfer': _ActionInfo('Transferencia de stock', Icons.swap_horiz, AppTheme.primary),
    'ws_stock_batch_move': _ActionInfo('Movimiento masivo', Icons.warehouse_outlined, AppTheme.amber),
    'ws_stock_count_save': _ActionInfo('Cuadre de inventario', Icons.fact_check_outlined, AppTheme.success),
    'ws_undo': _ActionInfo('Deshacer movimiento', Icons.undo, AppTheme.purple),
    'ws_redo': _ActionInfo('Rehacer movimiento', Icons.redo, AppTheme.purple),
    'ws_movement_revert': _ActionInfo('Revertir movimiento', Icons.history, AppTheme.amber),
    'ws_pos_sale_save': _ActionInfo('Venta POS', Icons.point_of_sale, AppTheme.success),
    'ws_pos_cash_open': _ActionInfo('Apertura de caja', Icons.point_of_sale, AppTheme.success),
    'ws_pos_cash_close': _ActionInfo('Cierre de caja', Icons.point_of_sale, AppTheme.danger),
    'ws_order_accept': _ActionInfo('Aceptar pedido', Icons.receipt_long_outlined, AppTheme.success),
    'ws_order_reject': _ActionInfo('Rechazar pedido', Icons.receipt_long_outlined, AppTheme.danger),
    'ws_order_complete': _ActionInfo('Completar pedido', Icons.receipt_long_outlined, AppTheme.primary),
    'ws_customers_save': _ActionInfo('Cliente guardado', Icons.groups_2_outlined, AppTheme.primary),
    'ws_save_shift': _ActionInfo('Turno guardado', Icons.schedule, AppTheme.primary),
    'ws_delete_shift': _ActionInfo('Eliminar turno', Icons.schedule, AppTheme.danger),
    'ws_save_worker_user': _ActionInfo('Trabajador creado', Icons.person_add_outlined, AppTheme.success),
    'ws_update_worker': _ActionInfo('Trabajador actualizado', Icons.manage_accounts_outlined, AppTheme.primary),
    'ws_worker_set_disabled': _ActionInfo('Estado de trabajador', Icons.person_outline, AppTheme.amber),
    'ws_delete_worker': _ActionInfo('Eliminar trabajador', Icons.person_remove_outlined, AppTheme.danger),
    'ws_expense_save': _ActionInfo('Gasto guardado', Icons.payments_outlined, AppTheme.amber),
    'ws_expense_delete': _ActionInfo('Eliminar gasto', Icons.payments_outlined, AppTheme.danger),
    'ws_reviews_delete': _ActionInfo('Eliminar valoración', Icons.star_outline, AppTheme.danger),
    'ws_reviews_moderate': _ActionInfo('Moderar valoración', Icons.star_outline, AppTheme.amber),
    'ws_loyalty_adjust_points': _ActionInfo('Ajustar puntos fidelización', Icons.card_giftcard_outlined, AppTheme.purple),
    'ws_announcement_save': _ActionInfo('Anuncio guardado', Icons.campaign_outlined, AppTheme.primary),
    'ws_announcement_delete': _ActionInfo('Eliminar anuncio', Icons.campaign_outlined, AppTheme.danger),
    'ws_announcement_toggle': _ActionInfo('Estado de anuncio', Icons.campaign_outlined, AppTheme.amber),
    'ws_settings_save': _ActionInfo('Configuración guardada', Icons.settings_outlined, AppTheme.primary),
    'ws_save_site_theme': _ActionInfo('Apariencia guardada', Icons.palette_outlined, AppTheme.primary),
    'ws_save_permissions': _ActionInfo('Permisos guardados', Icons.admin_panel_settings_outlined, AppTheme.primary),
  };

  String _describe(String action, Map<String, dynamic> data) {
    switch (action) {
      case 'ws_stock_move':
      case 'ws_stock_transfer':
      case 'ws_stock_batch_move':
        final name = data['name'] ?? data['product_name'] ?? '';
        final qty = data['qty'];
        return '$name${qty != null ? ' · ${num.tryParse('$qty')! > 0 ? '+' : ''}$qty' : ''}';
      case 'ws_pos_sale_save':
        final total = data['total'] ?? '';
        final loc = data['location_name'] ?? '';
        return 'Total: $total${loc.isNotEmpty ? ' · $loc' : ''}';
      case 'ws_expense_save':
        return '${data['concept'] ?? ''}${data['amount'] != null ? ' · ${data['amount']}' : ''}';
      case 'ws_customers_save':
        return '${data['name'] ?? ''}';
      case 'ws_save_product':
      case 'ws_combo_save':
      case 'ws_save_location':
      case 'ws_announcement_save':
        return '${data['name'] ?? data['title'] ?? ''}';
      case 'ws_save_shift':
        return '${data['worker_name'] ?? data['title'] ?? data['start'] ?? ''}';
      case 'ws_save_worker_user':
      case 'ws_update_worker':
        return '${data['display_name'] ?? data['email'] ?? ''}';
      case 'ws_loyalty_adjust_points':
        return '${data['reason'] ?? ''}${data['points'] != null ? ' · ${data['points']} pts' : ''}';
      case 'ws_order_accept':
      case 'ws_order_reject':
      case 'ws_order_complete':
        return 'Pedido #${data['number'] ?? data['id'] ?? ''}';
      case 'ws_stock_count_save':
        return '${data['location_name'] ?? ''}${data['date'] != null ? ' · ${data['date']}' : ''}';
      default:
        return data.entries.take(2).map((e) => '${e.key}: ${e.value}').join(' · ');
    }
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return FutureBuilder<List<Map<String, dynamic>>>(
      future: DbService.I.pending(),
      builder: (context, snap) {
        final ops = snap.data ?? [];
        if (ops.isEmpty) {
          return Center(
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Container(
                width: 64, height: 64,
                decoration: BoxDecoration(gradient: AppTheme.successGradient, shape: BoxShape.circle,
                    boxShadow: [BoxShadow(color: AppTheme.success.withAlpha(60), blurRadius: 16, offset: const Offset(0, 6))]),
                child: const Icon(Icons.cloud_done_outlined, size: 32, color: Colors.white),
              ),
              const SizedBox(height: 12),
              Text('Todo sincronizado', style: TextStyle(color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted, fontWeight: FontWeight.w600)),
              const SizedBox(height: 4),
              Text('No hay cambios pendientes', style: TextStyle(color: isDark ? AppTheme.darkMuted.withAlpha(150) : AppTheme.lightMuted.withAlpha(150), fontSize: 12)),
            ]),
          );
        }

        // Compute POS sales summary
        final posSales = ops.where((op) => op['action'] == 'ws_pos_sale_save').toList();
        num posTotal = 0;
        for (final op in posSales) {
          final d = (op['data'] is Map) ? Map<String, dynamic>.from(op['data'] as Map) : <String, dynamic>{};
          posTotal += num.tryParse('${d['total']}') ?? 0;
        }
        final posCur = posSales.isNotEmpty
            ? '${((posSales.first['data'] is Map) ? posSales.first['data'] : {})['currency'] ?? ''}'
            : '';

        return Column(children: [
          // ── Summary banner ──
          Container(
            margin: const EdgeInsets.fromLTRB(14, 12, 14, 0),
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              gradient: LinearGradient(colors: [AppTheme.amber.withAlpha(20), AppTheme.amber.withAlpha(10)]),
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: AppTheme.amber.withAlpha(50)),
            ),
            child: Row(children: [
              const Icon(Icons.cloud_upload_outlined, color: AppTheme.amber, size: 20),
              const SizedBox(width: 10),
              Expanded(child: Row(children: [
                AnimatedCounter(value: ops.length, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: AppTheme.amber)),
                const SizedBox(width: 6),
                Text('cambio(s) pendiente(s)', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: Colors.grey[600])),
              ])),
              FilledButton.icon(
                onPressed: SyncService.I.isBusy ? null : () async {
                  await U.handlePush(context, SyncService.I.syncNow(), 'Sincronizado');
                },
                icon: const Icon(Icons.sync, size: 18),
                label: const Text('Enviar'),
                style: FilledButton.styleFrom(backgroundColor: AppTheme.amber),
              ),
            ]),
          ),

          // ── POS sales summary card ──
          if (posSales.isNotEmpty) ...[
            Container(
              margin: const EdgeInsets.fromLTRB(14, 8, 14, 0),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: isDark ? AppTheme.darkCard : Colors.white,
                borderRadius: BorderRadius.circular(10),
                border: Border.all(color: AppTheme.success.withAlpha(40)),
              ),
              child: Row(children: [
                Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(color: AppTheme.success.withAlpha(20), borderRadius: BorderRadius.circular(8)),
                  child: const Icon(Icons.point_of_sale, color: AppTheme.success, size: 18),
                ),
                const SizedBox(width: 10),
                Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('Ventas POS pendientes', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w600, color: Colors.grey[600])),
                  const SizedBox(height: 2),
                  Text(
                    '${posSales.length} venta(s) · ${U.money(posTotal, posCur.isNotEmpty ? posCur : AuthService.I.currency, dec: 0)}',
                    style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w800, color: AppTheme.success),
                  ),
                ])),
                Icon(Icons.chevron_right, size: 18, color: Colors.grey[400]),
              ]),
            ),
          ],

          // ── Operations list ──
          Expanded(
            child: ListView.separated(
              padding: const EdgeInsets.fromLTRB(14, 10, 14, 90),
              itemCount: ops.length,
              separatorBuilder: (_, _) => const SizedBox(height: 6),
              itemBuilder: (context, i) {
                final op = ops[i];
                final action = '${op['action'] ?? ''}';
                final data = (op['data'] is Map) ? Map<String, dynamic>.from(op['data'] as Map) : <String, dynamic>{};
                final info = _actionInfo[action];
                final label = info?.label ?? action.replaceFirst('ws_', '').replaceAll('_', ' ');
                final icon = info?.icon ?? Icons.sync;
                final color = info?.color ?? AppTheme.lightMuted;
                final desc = _describe(action, data);

                return Card(
                  child: ListTile(
                    leading: Container(
                      padding: const EdgeInsets.all(8),
                      decoration: BoxDecoration(color: color.withAlpha(20), borderRadius: BorderRadius.circular(10)),
                      child: Icon(icon, color: color, size: 20),
                    ),
                    title: Text(label, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                    subtitle: desc.isNotEmpty
                        ? Text(desc, maxLines: 1, overflow: TextOverflow.ellipsis,
                            style: TextStyle(color: Colors.grey[600], fontSize: 11))
                        : null,
                    trailing: IconButton(
                      icon: const Icon(Icons.delete_outline, size: 20, color: AppTheme.danger),
                      onPressed: () async {
                        if (await U.confirm(context, '¿Descartar este cambio pendiente?', action: 'Descartar')) {
                          await SyncService.I.discardPending(op['id']);
                        }
                      },
                    ),
                  ),
                );
              },
            ),
          ),
        ]);
      },
    );
  }
}

class _ActionInfo {
  final String label;
  final IconData icon;
  final Color color;
  const _ActionInfo(this.label, this.icon, this.color);
}
