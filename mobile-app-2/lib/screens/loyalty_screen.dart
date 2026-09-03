import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';

/// Fidelización con dark mode y mejor UI.
class LoyaltyScreen extends StatelessWidget {
  const LoyaltyScreen({super.key});

  Future<void> _adjust(BuildContext context, Map<String, dynamic> c) async {
    final cPoints = TextEditingController();
    final ok = await showAdjustSheet(context, c, cPoints);
    if (!ok) return;
    final pts = num.tryParse(cPoints.text.replaceAll(',', '.'));
    if (pts == null || pts == 0) return;
    await U.handlePush(
        context,
        SyncService.I.push('ws_loyalty_adjust_points', {
          'customer_id': c['id'] ?? c['customer_id'],
          'points': pts.toInt(),
        }),
        'Puntos actualizados',
        onQueued: (qp) async {
          final raw = await DbService.I.cacheGet('ws_loyalty_customers');
          if (raw is List) {
            final cid = '${c['id'] ?? c['customer_id']}';
            for (var i = 0; i < raw.length; i++) {
              final row = raw[i];
              if (row is Map && '${row['id']}' == cid) {
                raw[i] = Map<String, dynamic>.from(row)
                  ..['points'] =
                      ((num.tryParse('${row['points'] ?? 0}') ?? 0) +
                              pts.toInt())
                          .toString();
                break;
              }
            }
            await DbService.I.cacheSet('ws_loyalty_customers', raw);
          }
        });
  }

  Future<bool> showAdjustSheet(BuildContext context,
      Map<String, dynamic> c, TextEditingController cPoints) async {
    var add = true;
    final res = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
          builder: (ctx, setS) => Padding(
                padding: EdgeInsets.only(
                    left: 18,
                    right: 18,
                    top: 16,
                    bottom: MediaQuery.of(ctx).viewInsets.bottom + 18),
                child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text('Puntos · ${c['name'] ?? ''}',
                          style: const TextStyle(
                              fontWeight: FontWeight.w700, fontSize: 15)),
                      const SizedBox(height: 12),
                      SwitchListTile.adaptive(
                        title: Text(
                            add ? 'Sumar puntos' : 'Restar puntos'),
                        value: add,
                        onChanged: (v) => setS(() => add = v),
                      ),
                      TextField(
                        controller: cPoints,
                        keyboardType: TextInputType.number,
                        decoration: const InputDecoration(
                            labelText: 'Cantidad de puntos'),
                      ),
                      const SizedBox(height: 14),
                      FilledButton(
                        onPressed: () => Navigator.pop(ctx, true),
                        child: const Text('Aplicar'),
                      ),
                    ]),
              )),
    );
    if (add == false &&
        cPoints.text.isNotEmpty &&
        !cPoints.text.startsWith('-')) {
      cPoints.text = '-${cPoints.text}';
    }
    return res == true;
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return FutureBuilder<Object?>(
      future: DbService.I.cacheGet('ws_loyalty_customers'),
      builder: (context, snap) {
        final raw = snap.data;
        final rows = (raw is List)
            ? raw
                .whereType<Map>()
                .map((e) => Map<String, dynamic>.from(e))
                .toList()
            : <Map<String, dynamic>>[];
        if (rows.isEmpty) {
          return Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(Icons.card_giftcard_outlined,
                    size: 48,
                    color: isDark
                        ? AppTheme.darkMuted
                        : AppTheme.lightMuted),
                const SizedBox(height: 8),
                Text('Sin clientes en fidelización.',
                    style: TextStyle(
                        color: isDark
                            ? AppTheme.darkMuted
                            : AppTheme.lightMuted)),
              ],
            ),
          );
        }
        return ListView.separated(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 90),
          itemCount: rows.length > 200 ? 200 : rows.length,
          separatorBuilder: (_, __) => const SizedBox(height: 8),
          itemBuilder: (context, i) {
            final c = rows[i];
            final points =
                (num.tryParse('${c['points']}') ?? 0).toInt();
            return Card(
              child: ListTile(
                leading: Container(
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: AppTheme.primary.withAlpha(20),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(Icons.card_giftcard_outlined,
                      color: AppTheme.primary, size: 20),
                ),
                title: Text(
                    '${c['name'] ?? c['customer_name'] ?? ''}',
                    style: const TextStyle(
                        fontWeight: FontWeight.w600, fontSize: 14)),
                subtitle: Text('${c['phone'] ?? ''}',
                    style: TextStyle(
                        color: Colors.grey[600], fontSize: 12)),
                trailing: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text('$points pts',
                          style: const TextStyle(
                              fontWeight: FontWeight.w800)),
                      IconButton(
                          icon: const Icon(Icons.edit_outlined,
                              size: 20),
                          onPressed: () => _adjust(context, c)),
                    ]),
              ),
            );
          },
        );
      },
    );
  }
}
