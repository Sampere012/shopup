import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';
import '../widgets/crud.dart';

class AnnouncementsScreen extends StatefulWidget {
  const AnnouncementsScreen({super.key});

  @override
  State<AnnouncementsScreen> createState() => _AnnouncementsScreenState();
}

class _AnnouncementsScreenState extends State<AnnouncementsScreen> {
  Future<List<Map<String, dynamic>>>? _future;

  @override
  void initState() {
    super.initState();
    _reload();
    SyncService.I.onChange(_onSync);
  }

  void _onSync() {
    if (mounted) _reload();
  }

  void _reload() {
    _future = DbService.I.all('announcements');
    setState(() {});
  }

  Future<void> _edit(Map<String, dynamic>? a) async {
    final cTitle = TextEditingController(text: '${a?['title'] ?? ''}');
    final cBody = TextEditingController(text: '${a?['body'] ?? a?['content'] ?? ''}');

    await showFormSheet(
      context,
      title: a == null ? 'Nuevo anuncio' : 'Editar anuncio',
      fields: [
        fField('Título *', cTitle),
        fField('Mensaje', cBody),
      ],
      onSave: () async {
        if (cTitle.text.trim().isEmpty) return false;
        return U.handlePush(
            context,
            SyncService.I.push('ws_announcement_save', {
              'id': a == null ? 0 : (num.tryParse('${a['id']}') ?? 0),
              'title': cTitle.text.trim(),
              'body': cBody.text.trim(),
            }),
            'Guardado',
            onOk: () async { _reload(); },
            onQueued: (qp) async {
              final tmp = Map<String, dynamic>.from(qp);
              tmp['id'] = tmp['id'] ?? 'local-${DateTime.now().millisecondsSinceEpoch}';
              tmp['active'] = tmp['active'] ?? '1';
              tmp['created_at'] = tmp['created_at'] ?? DateTime.now().toIso8601String();
              final all = await DbService.I.all('announcements');
              final idx = all.indexWhere((r) => '${r['id']}' == '${tmp['id']}');
              if (idx >= 0) {
                all[idx] = tmp;
              } else {
                all.insert(0, tmp);
              }
              await DbService.I.replaceAll('announcements', all);
              _reload();
            });
      },
    );
  }

  Future<void> _toggle(Map<String, dynamic> a) async {
    final prev = '${a['active']}' != '0';
    // Optimistic flip
    final all = await DbService.I.all('announcements');
    final idx = all.indexWhere((r) => '${r['id']}' == '${a['id']}');
    if (idx >= 0) {
      all[idx] = Map<String, dynamic>.from(all[idx])..['active'] = prev ? '0' : '1';
      await DbService.I.replaceAll('announcements', all);
      _reload();
    }
    await U.handlePush(
        context,
        SyncService.I.push(
            'ws_announcement_toggle', {'id': a['id']}),
        prev ? 'Anuncio desactivado' : 'Anuncio activado',
        onQueued: (_) async {});
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      backgroundColor: Colors.transparent,
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'addAnnouncement',
        onPressed: () => _edit(null),
        icon: const Icon(Icons.add),
        label: const Text('Anuncio'),
      ),
      body: FutureBuilder<List<Map<String, dynamic>>>(
        future: _future,
        builder: (context, snap) {
          context.watch<SyncNotifier>();
          final rows = snap.data ?? [];
          if (rows.isEmpty) {
            return Center(child: Text('Sin anuncios.',
                style: TextStyle(color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted)));
          }
          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 90),
            itemCount: rows.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (context, i) {
              final a = rows[i];
              final active = '${a['active']}' != '0';
              return Card(
                child: ListTile(
                  leading: Icon(active ? Icons.campaign : Icons.campaign_outlined,
                      color: active ? AppTheme.primary : Colors.grey),
                  title: Text('${a['title'] ?? ''}',
                      style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                  subtitle: Text('${a['body'] ?? a['content'] ?? ''}',
                      maxLines: 2, overflow: TextOverflow.ellipsis,
                      style: TextStyle(color: Colors.grey[600], fontSize: 12)),
                  trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                    IconButton(
                      tooltip: active ? 'Desactivar' : 'Activar',
                      icon: Icon(active ? Icons.toggle_on : Icons.toggle_off,
                          size: 26, color: active ? AppTheme.success : Colors.grey),
                      onPressed: () => _toggle(a),
                    ),
                    IconButton(
                        icon: const Icon(Icons.edit_outlined, size: 20),
                        onPressed: () => _edit(a)),
                  ]),
                ),
              );
            },
          );
        },
      ),
    );
  }
}
