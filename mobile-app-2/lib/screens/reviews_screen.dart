import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';

/// Valoraciones con filtro por estado y acciones (aprobar/rechazar/eliminar).
class ReviewsScreen extends StatefulWidget {
  const ReviewsScreen({super.key});

  @override
  State<ReviewsScreen> createState() => _ReviewsScreenState();
}

class _ReviewsScreenState extends State<ReviewsScreen> {
  String _statusFilter = '';
  late Future<List<Map<String, dynamic>>> _future;

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
    _future = DbService.I.cacheGet('ws_reviews_get').then((raw) {
      if (raw is List) return raw.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
      if (raw is Map) {
        final inner = raw['data'] ?? raw['reviews'];
        if (inner is List) return inner.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
      }
      return <Map<String, dynamic>>[];
    });
  }

  Future<void> _moderate(String id, String status) async {
    await U.handlePush(
      context,
      SyncService.I.push('ws_reviews_moderate', {'id': id, 'status': status}),
      status == 'approved' ? 'Aprobada' : 'Rechazada',
      onOk: () => SyncService.I.pullCache('ws_reviews_get', {'status': '', 'limit': 200, 'offset': 0}, 'ws_reviews_get'),
      onQueued: (qp) async {
        final raw = await DbService.I.cacheGet('ws_reviews_get');
        if (raw is List) {
          for (final r in raw) {
            if (r is Map && '${r['id']}' == id) { r['status'] = status; break; }
          }
          await DbService.I.cacheSet('ws_reviews_get', raw);
        }
      },
    );
    _reload(); setState(() {});
  }

  Future<void> _delete(String id) async {
    if (await U.confirm(context, '¿Eliminar esta valoración?', action: 'Eliminar')) {
      await U.handlePush(
        context,
        SyncService.I.push('ws_reviews_delete', {'id': id}),
        'Eliminada',
        onOk: () => SyncService.I.pullCache('ws_reviews_get', {'status': '', 'limit': 200, 'offset': 0}, 'ws_reviews_get'),
        onQueued: (qp) async {
          final raw = await DbService.I.cacheGet('ws_reviews_get');
          if (raw is List) {
            final rows = raw.whereType<Map>().where((r) => '${r['id']}' != id).toList();
            await DbService.I.cacheSet('ws_reviews_get', rows);
          }
        },
      );
      _reload(); setState(() {});
    }
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final canModerate = AuthService.I.has('reviews_moderate');

    return Column(children: [
      // Status filter
      SizedBox(
        height: 44,
        child: ListView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
          children: [
            _chip('Todas', ''),
            _chip('Pendientes', 'pending'),
            _chip('Aprobadas', 'approved'),
            _chip('Rechazadas', 'rejected'),
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
            if (rows.isEmpty) {
              return Center(
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  Icon(Icons.star_outline, size: 48,
                      color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted),
                  const SizedBox(height: 8),
                  Text('Sin valoraciones.', style: TextStyle(
                      color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted)),
                ]),
              );
            }
            return RefreshIndicator(
              onRefresh: () async { _reload(); await _future; setState(() {}); },
              child: ListView.separated(
                padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
                itemCount: rows.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) {
                  final r = rows[i];
                  final rating = (num.tryParse('${r['rating']}') ?? 0).toInt();
                  final status = '${r['status'] ?? ''}';
                  return Card(
                    child: ListTile(
                      leading: Container(
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          color: AppTheme.accent.withAlpha(25),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: const Icon(Icons.star, color: AppTheme.accent, size: 20),
                      ),
                      title: Row(children: [
                        Expanded(child: Text('${r['customer_name'] ?? ''}',
                            style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
                            overflow: TextOverflow.ellipsis)),
                        ...List.generate(5, (j) => Icon(
                            j < rating ? Icons.star : Icons.star_border,
                            size: 14, color: j < rating ? AppTheme.accent : Colors.grey[400])),
                      ]),
                      subtitle: Text('${r['comment'] ?? ''}',
                          maxLines: 2, overflow: TextOverflow.ellipsis,
                          style: TextStyle(color: Colors.grey[600], fontSize: 12)),
                      trailing: canModerate
                          ? Row(mainAxisSize: MainAxisSize.min, children: [
                              if (status != 'approved')
                                IconButton(
                                    icon: const Icon(Icons.check_circle_outline, size: 20, color: AppTheme.success),
                                    onPressed: () => _moderate('${r['id']}', 'approved')),
                              if (status != 'rejected')
                                IconButton(
                                    icon: const Icon(Icons.cancel_outlined, size: 20, color: AppTheme.amber),
                                    onPressed: () => _moderate('${r['id']}', 'rejected')),
                              IconButton(
                                  icon: const Icon(Icons.delete_outline, size: 18, color: AppTheme.danger),
                                  onPressed: () => _delete('${r['id']}')),
                            ])
                          : U.badge(status == 'approved' ? 'Aprobada' : status == 'rejected' ? 'Rechazada' : 'Pendiente',
                              color: status == 'approved' ? AppTheme.success : status == 'rejected' ? AppTheme.danger : AppTheme.amber,
                              small: true),
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

  Widget _chip(String label, String value) {
    final selected = value == _statusFilter;
    return Padding(
      padding: const EdgeInsets.only(right: 6),
      child: ChoiceChip(
        label: Text(label, style: const TextStyle(fontSize: 12)),
        selected: selected,
        onSelected: (_) => setState(() => _statusFilter = value),
        visualDensity: VisualDensity.compact,
      ),
    );
  }
}
