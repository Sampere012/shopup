import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';
import '../widgets/crud.dart';

/// Gestión de anuncios, igual que en el panel web (Anuncios): crear/editar
/// con tipo, mensaje, fijado como banner y permiso de cierre; activar,
/// desfijar y eliminar.
class AnnouncementsScreen extends StatefulWidget {
  const AnnouncementsScreen({super.key});

  @override
  State<AnnouncementsScreen> createState() => _AnnouncementsScreenState();
}

class _AnnouncementsScreenState extends State<AnnouncementsScreen> {
  static const _types = ['info', 'success', 'warning', 'danger'];
  static const _typeLabels = {
    'info': 'Información',
    'success': 'Éxito',
    'warning': 'Aviso',
    'danger': 'Urgente',
  };

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

  /// Tras guardar/activar en el servidor: refresca la lista de gestión y los
  /// banners fijados (que ve el resto de la app). Conserva las creaciones
  /// que aún están SOLO en el dispositivo (encoladas).
  Future<void> _refreshAfterChange() async {
    await SyncService.I.pullCache(
        'ws_announcements_list', {}, 'ws_announcements_list',
        dataKey: 'list');
    final c = await DbService.I.cacheGet('ws_announcements_list');
    if (c is List) {
      final rows = List<Map<String, dynamic>>.from(c.whereType<Map>());
      await DbService.I.replaceAll(
          'announcements',
          await SyncService.I.mergeAnnouncementRows(rows));
    }
    await SyncService.I.pullCache(
        'ws_announcements_pinned_list', {}, 'ws_announcements_pinned',
        dataKey: 'list');
    _reload();
  }

  /// true si la fila todavía NO existe en el servidor (se creó offline y su
  /// guardado sigue en la cola). Esas filas usan id 'local-…'.
  static bool _isLocal(Map<String, dynamic> a) =>
      '${a['id']}'.startsWith('local-');

  /// Escribe/actualiza la fila local de un anuncio aún no subido.
  Future<void> _putLocalRow(String localId, Map<String, dynamic> row) async {
    final all = await DbService.I.all('announcements');
    final stored = {...row, 'id': localId, 'queued': true};
    final idx = all.indexWhere((r) => '${r['id']}' == localId);
    if (idx >= 0) {
      all[idx] = stored;
    } else {
      all.insert(0, stored);
    }
    await DbService.I.replaceAll('announcements', all);
    _reload();
  }

  /// Muestra el aviso de que la fila local ya no está en la cola (su creación
  /// llegó al servidor): se refresca desde el servidor para obtener el id
  /// real y el usuario repite la acción sobre la fila sincronizada.
  Future<void> _localRowAlreadySynced(String msg) async {
    await _refreshAfterChange();
    if (mounted) U.toast(context, msg, kind: 'warn');
  }

  Future<void> _edit(Map<String, dynamic>? a) async {
    final cTitle = TextEditingController(text: '${a?['title'] ?? ''}');
    // El servidor guarda el texto en `message` (web y app); `body`/`content`
    // se leen por compatibilidad con filas antiguas.
    final cBody = TextEditingController(
        text: '${a?['message'] ?? a?['body'] ?? a?['content'] ?? ''}');
    final cDays = TextEditingController(text: '7');
    var type = '${a?['type'] ?? 'info'}';
    if (!_types.contains(type)) type = 'info';
    var pinned = '${a?['pinned']}' == '1';
    var dismissible = '${a?['dismissible'] ?? '1'}' != '0';
    final localId = (a != null && _isLocal(a)) ? '${a['id']}' : null;

    await showFormSheet(
      context,
      title: a == null ? 'Nuevo anuncio' : 'Editar anuncio',
      fields: [
        fField('Título *', cTitle),
        fField('Mensaje', cBody),
        StatefulBuilder(builder: (ctx, setLocal) {
          return Column(crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min, children: [
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: DropdownButtonFormField<String>(
                initialValue: type,
                decoration: const InputDecoration(labelText: 'Tipo'),
                items: _types
                    .map((t) => DropdownMenuItem(
                        value: t, child: Text(_typeLabels[t] ?? t)))
                    .toList(),
                onChanged: (v) => setLocal(() => type = v ?? 'info'),
              ),
            ),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: const Text('Fijar como banner',
                  style: TextStyle(fontSize: 14)),
              subtitle: pinned
                  ? const Text('Se muestra arriba en el panel y en la app',
                      style: TextStyle(fontSize: 11))
                  : null,
              value: pinned,
              onChanged: (v) => setLocal(() => pinned = v),
            ),
            if (pinned)
              fField('Días fijado', cDays,
                  type: TextInputType.number, hint: '7'),
            SwitchListTile(
              contentPadding: EdgeInsets.zero,
              dense: true,
              title: const Text('Se puede cerrar',
                  style: TextStyle(fontSize: 14)),
              value: dismissible,
              onChanged: (v) => setLocal(() => dismissible = v),
            ),
          ]);
        }),
      ],
      onSave: () async {
        if (cTitle.text.trim().isEmpty) return false;
        final payload = <String, dynamic>{
          'id': a == null ? 0 : (num.tryParse('${a['id']}') ?? 0),
          'title': cTitle.text.trim(),
          'message': cBody.text.trim(),
          'type': type,
          'pinned': pinned ? '1' : '0',
          'pinned_days':
              pinned ? '${int.tryParse(cDays.text.trim()) ?? 7}' : '0',
          'dismissible': dismissible ? '1' : '0',
          // Solo en filas locales: el estado activo se conserva entre
          // ediciones (los toggles se fusionan en esta misma creación).
          if (localId != null)
            'active': '${a?['active'] ?? '1'}' == '0' ? '0' : '1',
        };
        if (localId != null) {
          // Fila aún no subida: reescribe la creación ENCOLADA en vez de
          // encolar otra (antes se mandaba id=0 y el servidor creaba un
          // anuncio duplicado por cada edición).
          final updated = await SyncService.I.updateQueuedAnnouncementSave(
              localId, payload,
              title: '${a?['title'] ?? ''}');
          if (updated) {
            await _putLocalRow(localId, {
              ...payload,
              'id': localId,
              'created_at': a?['created_at'] ?? DateTime.now().toIso8601String(),
            });
            if (mounted) {
              U.toast(context, 'Guardado — se enviará al reconectar',
                  kind: 'warn');
            }
            return true;
          }
          await _localRowAlreadySynced(
              'Este anuncio ya se sincronizó. Vuelve a editarlo.');
          return false;
        }
        // Fila nueva (o ya sincronizada): id local generado ANTES del push
        // para que la op encolada quede enlazada a la fila que se muestra.
        final newLocalId =
            'local-${DateTime.now().microsecondsSinceEpoch}';
        return U.handlePush(
            context,
            SyncService.I.push('ws_announcement_save', payload,
                localId: a == null ? newLocalId : null),
            'Guardado',
            onOk: () => _refreshAfterChange(),
            onQueued: (qp) async {
              final tmp = Map<String, dynamic>.from(qp);
              // Solo las CREACIONES reciben id local: una edición encolada
              // de un anuncio ya sincronizado conserva su id real del
              // servidor (los toggles/ediciones posteriores lo necesitan).
              final isCreate =
                  tmp['id'] == null || '${tmp['id']}' == '0';
              final rowId = isCreate ? newLocalId : '${tmp['id']}';
              tmp['active'] = tmp['active'] ?? '1';
              tmp['message'] = tmp['message'] ?? '';
              tmp['type'] = tmp['type'] ?? 'info';
              tmp['pinned'] = tmp['pinned'] ?? '0';
              tmp['dismissible'] = tmp['dismissible'] ?? '1';
              tmp['created_at'] =
                  tmp['created_at'] ?? DateTime.now().toIso8601String();
              await _putLocalRow(rowId, tmp);
            });
      },
    );
  }

  Future<void> _toggle(Map<String, dynamic> a, [String field = 'active']) async {
    final isPinned = field == 'pinned';
    final prev = isPinned
        ? '${a['pinned']}' == '1'
        : '${a['active']}' != '0';
    final okMsg = isPinned
        ? (prev ? 'Anuncio desfijado' : 'Anuncio fijado como banner')
        : (prev ? 'Anuncio desactivado' : 'Anuncio activado');

    // Fila LOCAL (creación aún en cola): el toggle se fusiona en esa misma
    // creación. Antes se encolaba un toggle con id 'local-…' y el servidor
    // lo rechazaba para siempre con "Anuncio no encontrado".
    if (_isLocal(a)) {
      final localId = '${a['id']}';
      final fields = <String, String>{field: prev ? '0' : '1'};
      if (isPinned) {
        // Al fijar por primera vez se usan los 7 días por defecto.
        final days = int.tryParse('${a['pinned_days'] ?? ''}') ?? 7;
        fields['pinned_days'] = prev ? '0' : '${days > 0 ? days : 7}';
      }
      final ok = await SyncService.I.updateQueuedAnnouncementField(
          localId, fields,
          title: '${a['title'] ?? ''}');
      if (ok) {
        await _putLocalRow(localId, {
          ...a,
          ...fields,
        });
        if (mounted) {
          U.toast(context, '$okMsg — se enviará al reconectar', kind: 'warn');
        }
        return;
      }
      await _localRowAlreadySynced(
          'Este anuncio se está sincronizando. Reintenta en unos segundos.');
      return;
    }

    // Optimistic flip
    final all = await DbService.I.all('announcements');
    final idx = all.indexWhere((r) => '${r['id']}' == '${a['id']}');
    if (idx >= 0) {
      all[idx] = Map<String, dynamic>.from(all[idx])
        ..[field] = prev ? '0' : '1';
      await DbService.I.replaceAll('announcements', all);
      _reload();
    }
    await U.handlePush(
        context,
        SyncService.I.push(
            'ws_announcement_toggle', {'id': a['id'], 'field': field}),
        okMsg,
        onOk: () => _refreshAfterChange(),
        onQueued: (_) async {});
  }

  Future<void> _remove(Map<String, dynamic> a) async {
    final ok = await U.confirm(context, '¿Eliminar el anuncio?',
        action: 'Eliminar');
    if (!ok) return;

    // Fila LOCAL: nunca se manda un id 'local-…' al servidor (lo rechazaría).
    // Se descarta la creación encolada; si ya se subió, se refresca para
    // eliminar la fila real del servidor.
    if (_isLocal(a)) {
      final removed = await SyncService.I.discardQueuedAnnouncementOps(
          '${a['id']}',
          title: '${a['title'] ?? ''}');
      if (removed > 0) {
        final all = await DbService.I.all('announcements');
        all.removeWhere((r) => '${r['id']}' == '${a['id']}');
        await DbService.I.replaceAll('announcements', all);
        _reload();
        if (mounted) U.toast(context, 'Anuncio descartado');
        return;
      }
      await _localRowAlreadySynced(
          'Este anuncio ya se sincronizó. Mantén pulsado para eliminarlo.');
      return;
    }

    final all = await DbService.I.all('announcements');
    all.removeWhere((r) => '${r['id']}' == '${a['id']}');
    await DbService.I.replaceAll('announcements', all);
    _reload();
    await U.handlePush(
        context,
        SyncService.I.push('ws_announcement_delete', {'id': a['id']}),
        'Anuncio eliminado',
        onOk: () => _refreshAfterChange(),
        onQueued: (_) async {});
  }

  Color _typeColor(String type, bool isDark) {
    switch (type) {
      case 'success':
        return AppTheme.success;
      case 'warning':
        return AppTheme.amber;
      case 'danger':
        return AppTheme.danger;
      default:
        return AppTheme.primary;
    }
  }

  IconData _typeIcon(String type) {
    switch (type) {
      case 'success':
        return Icons.check_circle_outline;
      case 'warning':
        return Icons.warning_amber_outlined;
      case 'danger':
        return Icons.error_outline;
      default:
        return Icons.info_outline;
    }
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
            return Center(
                child: Text('Sin anuncios.',
                    style: TextStyle(
                        color: isDark
                            ? AppTheme.darkMuted
                            : AppTheme.lightMuted)));
          }
          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 90),
            itemCount: rows.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (context, i) {
              final a = rows[i];
              final active = '${a['active']}' != '0';
              final pinned = '${a['pinned']}' == '1';
              final type = _types.contains('${a['type']}')
                  ? '${a['type']}'
                  : 'info';
              final color = _typeColor(type, isDark);
              return Card(
                child: ListTile(
                  onLongPress: () => _remove(a),
                  leading: CircleAvatar(
                    backgroundColor: color.withAlpha(active ? 30 : 15),
                    child: Icon(_typeIcon(type),
                        size: 20,
                        color: active
                            ? color
                            : (isDark ? Colors.grey : Colors.grey[600])),
                  ),
                  title: Text('${a['title'] ?? ''}',
                      style: const TextStyle(
                          fontWeight: FontWeight.w600, fontSize: 14)),
                  subtitle: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('${a['message'] ?? a['body'] ?? a['content'] ?? ''}',
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                              color: Colors.grey[600], fontSize: 12)),
                      const SizedBox(height: 4),
                      Wrap(spacing: 6, runSpacing: 4, children: [
                        Container(
                          padding: const EdgeInsets.symmetric(
                              horizontal: 6, vertical: 1),
                          decoration: BoxDecoration(
                              color: color.withAlpha(20),
                              borderRadius: BorderRadius.circular(6),
                              border: Border.all(color: color.withAlpha(50))),
                          child: Text(_typeLabels[type] ?? type,
                              style: TextStyle(
                                  fontSize: 10,
                                  fontWeight: FontWeight.w700,
                                  color: color)),
                        ),
                        if (pinned)
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 6, vertical: 1),
                            decoration: BoxDecoration(
                                color: AppTheme.primary.withAlpha(15),
                                borderRadius: BorderRadius.circular(6),
                                border: Border.all(
                                    color: AppTheme.primary.withAlpha(40))),
                            child: const Text('Anclado',
                                style: TextStyle(
                                    fontSize: 10,
                                    fontWeight: FontWeight.w700,
                                    color: AppTheme.primary)),
                          ),
                        if (!active)
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 6, vertical: 1),
                            decoration: BoxDecoration(
                                color: Colors.grey.withAlpha(25),
                                borderRadius: BorderRadius.circular(6)),
                            child: Text('Inactivo',
                                style: TextStyle(
                                    fontSize: 10,
                                    fontWeight: FontWeight.w700,
                                    color: Colors.grey[600])),
                          ),
                        if ('${a['queued']}' == 'true')
                          Container(
                            padding: const EdgeInsets.symmetric(
                                horizontal: 6, vertical: 1),
                            decoration: BoxDecoration(
                                color: AppTheme.amber.withAlpha(20),
                                borderRadius: BorderRadius.circular(6),
                                border: Border.all(
                                    color: AppTheme.amber.withAlpha(50))),
                            child: const Text('Pendiente de subir',
                                style: TextStyle(
                                    fontSize: 10,
                                    fontWeight: FontWeight.w700,
                                    color: AppTheme.amber)),
                          ),
                      ]),
                    ],
                  ),
                  trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                    IconButton(
                      tooltip: pinned ? 'Desfijar' : 'Fijar como banner',
                      visualDensity: VisualDensity.compact,
                      icon: Icon(Icons.push_pin,
                          size: 20,
                          color: pinned ? AppTheme.primary : Colors.grey),
                      onPressed: () => _toggle(a, 'pinned'),
                    ),
                    IconButton(
                      tooltip: 'Editar',
                      visualDensity: VisualDensity.compact,
                      icon: const Icon(Icons.edit_outlined, size: 20),
                      onPressed: () => _edit(a),
                    ),
                    IconButton(
                      tooltip: active ? 'Desactivar' : 'Activar',
                      visualDensity: VisualDensity.compact,
                      icon: Icon(active
                          ? Icons.toggle_on
                          : Icons.toggle_off),
                      color: active ? AppTheme.success : Colors.grey,
                      iconSize: 26,
                      onPressed: () => _toggle(a, 'active'),
                    ),
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
