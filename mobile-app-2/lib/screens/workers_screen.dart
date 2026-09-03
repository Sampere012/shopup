import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';
import '../widgets/crud.dart';

/// Trabajadores con crear/editar, rol, ubicaciones, pausar/activar, eliminar.
class WorkersScreen extends StatefulWidget {
  const WorkersScreen({super.key});

  @override
  State<WorkersScreen> createState() => _WorkersScreenState();
}

class _WorkersScreenState extends State<WorkersScreen> {
  String _q = '';
  late Future<List<Map<String, dynamic>>> _future;

  @override
  void initState() {
    super.initState();
    _reload();
    SyncService.I.onChange(_onSync);
  }

  void _onSync() {
    if (mounted) {
      _reload();
      setState(() {});
    }
  }

  void _reload() {
    _future = DbService.I.all('workers');
  }

  /// Extract location IDs from worker's locations field, handling both
  /// formats: List of Maps with 'id' keys, and List of ints (plain IDs).
  List<String> _extractLocIds(dynamic locations) {
    if (locations == null) return [];
    if (locations is List) {
      return locations.map((l) {
        if (l is Map) return '${l['id'] ?? ''}';
        return '$l';
      }).where((s) => s.isNotEmpty).toList();
    }
    return [];
  }

  Future<void> _edit(Map<String, dynamic>? w) async {
    try {
      final locations = await DbService.I.all('locations');
      final name = TextEditingController(
          text: '${w?['display_name'] ?? w?['name'] ?? ''}');
      final email = TextEditingController(
          text: '${w?['user_email'] ?? w?['email'] ?? ''}');
      final username = TextEditingController();
      final password = TextEditingController();
      final role = ValueNotifier<String>(w?['role'] ?? 'ws_seller');
      final selectedLocs =
          ValueNotifier<List<String>>(_extractLocIds(w?['locations']));

      final isEdit = w != null;

      final ok = await showFormSheet(
        context,
        title: isEdit ? 'Editar trabajador' : 'Nuevo trabajador',
        fields: [
          if (!isEdit) ...[
            fField('Nombre de usuario *', username),
            fField('Email *', email),
            fField('Contraseña *', password),
          ],
          fField('Nombre', name),
          if (isEdit) fField('Email', email),
          ValueListenableBuilder<String>(
            valueListenable: role,
            builder: (_, v, __) => DropdownButtonFormField<String>(
              initialValue: v,
              decoration: const InputDecoration(labelText: 'Rol *'),
              items: const [
                DropdownMenuItem(
                    value: 'ws_seller', child: Text('Vendedor')),
                DropdownMenuItem(
                    value: 'ws_storekeeper', child: Text('Almacenero')),
                DropdownMenuItem(
                    value: 'ws_owner', child: Text('Dueño')),
              ],
              onChanged: (val) {
                if (val != null) role.value = val;
              },
            ),
          ),
          ValueListenableBuilder<List<String>>(
            valueListenable: selectedLocs,
            builder: (_, selected, __) => Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Ubicaciones',
                    style:
                        TextStyle(fontSize: 12, fontWeight: FontWeight.w600)),
                const SizedBox(height: 4),
                ...locations.map((l) {
                  final lid = '${l['id']}';
                  final isSelected = selected.contains(lid);
                  return CheckboxListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    title: Text('${l['name'] ?? ''}',
                        style: const TextStyle(fontSize: 13)),
                    value: isSelected,
                    onChanged: (v) {
                      final newList = List<String>.from(selected);
                      if (v == true) {
                        newList.add(lid);
                      } else {
                        newList.remove(lid);
                      }
                      selectedLocs.value = newList;
                    },
                  );
                }),
              ],
            ),
          ),
        ],
        onSave: () async {
          if (!isEdit &&
              (username.text.trim().isEmpty ||
                  email.text.trim().isEmpty ||
                  password.text.isEmpty)) {
            U.toast(context,
                'Usuario, email y contraseña son obligatorios',
                kind: 'err');
            return false;
          }
          final payload = <String, dynamic>{
            'role': role.value,
            'locations': selectedLocs.value
                .map((id) => int.tryParse(id) ?? id)
                .toList(),
            'display_name': name.text.trim(),
            'email': email.text.trim(),
          };
          if (isEdit) {
            payload['user_id'] = w['id'];
          } else {
            payload['username'] = username.text.trim();
            payload['password'] = password.text;
          }
          return U.handlePush(
            context,
            SyncService.I.push(
                isEdit ? 'ws_update_worker' : 'ws_save_worker_user', payload),
            'Guardado',
            onOk: () => SyncService.I.pullStore('ws_workers_list',
                {'pageSize': 300, 'page': 1, 'search': ''}, 'workers',
                cacheKey: 'ws_workers_list', dataKey: 'workers'),
            onQueued: (queuedPayload) async {
              final rows = await DbService.I.all('workers');
              if (!isEdit) {
                rows.add({
                  'id': -DateTime.now().millisecondsSinceEpoch,
                  'display_name': payload['display_name'],
                  'user_email': payload['email'],
                  'role': payload['role'],
                  'locations': payload['locations'],
                  'is_disabled': 0,
                });
              } else {
                for (final r in rows) {
                  if ('${r['id']}' == '${payload['user_id']}') {
                    r['display_name'] = payload['display_name'];
                    r['role'] = payload['role'];
                    r['locations'] = payload['locations'];
                    break;
                  }
                }
              }
              await DbService.I.replaceAll('workers', rows);
            },
          );
        },
      );
      if (ok == true && mounted) {
        _reload();
        setState(() {});
      }
    } catch (e) {
      if (mounted) {
        U.toast(context, 'Error al abrir formulario: $e', kind: 'err');
      }
    }
  }

  Future<void> _toggleDisabled(Map<String, dynamic> w) async {
    final currentDisabled = '${w['is_disabled']}' == '1';
    await U.handlePush(
      context,
      SyncService.I.push('ws_worker_set_disabled', {
        'user_id': w['id'],
        'disabled': currentDisabled ? 0 : 1,
      }),
      currentDisabled ? 'Trabajador activado' : 'Trabajador pausado',
      onOk: () => SyncService.I.pullStore(
          'ws_workers_list',
          {'pageSize': 300, 'page': 1, 'search': ''},
          'workers',
          cacheKey: 'ws_workers_list',
          dataKey: 'workers'),
      onQueued: (qp) async {
        final rows = await DbService.I.all('workers');
        for (final r in rows) {
          if ('${r['id']}' == '${w['id']}') {
            r['is_disabled'] = currentDisabled ? 0 : 1;
            break;
          }
        }
        await DbService.I.replaceAll('workers', rows);
      },
    );
    _reload();
    setState(() {});
  }

  Future<void> _delete(Map<String, dynamic> w) async {
    if (await U.confirm(context, '¿Eliminar este trabajador del negocio?',
        action: 'Eliminar')) {
      await U.handlePush(
        context,
        SyncService.I
            .push('ws_delete_worker', {'user_id': w['id']}),
        'Eliminado',
        onOk: () => SyncService.I.pullStore(
            'ws_workers_list',
            {'pageSize': 300, 'page': 1, 'search': ''},
            'workers',
            cacheKey: 'ws_workers_list',
            dataKey: 'workers'),
        onQueued: (qp) async {
          final rows = await DbService.I.all('workers');
          rows.removeWhere((r) => '${r['id']}' == '${w['id']}');
          await DbService.I.replaceAll('workers', rows);
        },
      );
      _reload();
      setState(() {});
    }
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final canManage = AuthService.I.has('workers_manage');
    const roleLabels = {
      'ws_owner': 'Dueño',
      'ws_storekeeper': 'Almacenero',
      'ws_seller': 'Vendedor'
    };

    return Scaffold(
      backgroundColor: Colors.transparent,
      floatingActionButton: canManage
          ? FloatingActionButton.extended(
              heroTag: 'addWorker',
              onPressed: () => _edit(null),
              icon: const Icon(Icons.add),
              label: const Text('Trabajador'),
            )
          : null,
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
          child: TextField(
            decoration: InputDecoration(
              hintText: 'Buscar trabajador…',
              prefixIcon: const Icon(Icons.search),
              isDense: true,
              filled: true,
              fillColor: isDark ? AppTheme.darkSurface : Colors.white,
            ),
            onChanged: (v) => setState(() => _q = v.trim().toLowerCase()),
          ),
        ),
        Expanded(
          child: FutureBuilder<List<Map<String, dynamic>>>(
            future: _future,
            builder: (context, snap) {
              if (snap.connectionState != ConnectionState.done) {
                return const Center(child: CircularProgressIndicator());
              }
              var rows = snap.data ?? [];
              if (_q.isNotEmpty) {
                rows = rows
                    .where((r) =>
                        '${r['display_name'] ?? r['name'] ?? ''}'
                            .toLowerCase()
                            .contains(_q) ||
                        '${r['user_email'] ?? r['email'] ?? ''}'
                            .toLowerCase()
                            .contains(_q))
                    .toList();
              }
              if (rows.isEmpty) {
                return Center(
                    child: Text('Sin trabajadores.',
                        style: TextStyle(
                            color: isDark
                                ? AppTheme.darkMuted
                                : AppTheme.lightMuted)));
              }
              return RefreshIndicator(
                onRefresh: () async {
                  _reload();
                  await _future;
                  setState(() {});
                },
                child: ListView.separated(
                  padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
                  itemCount: rows.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    final w = rows[i];
                    final displayName =
                        '${w['display_name'] ?? w['name'] ?? ''}';
                    final roleKey = '${w['role'] ?? ''}';
                    final isDisabled = '${w['is_disabled']}' == '1';
                    // Handle locations as List<Map> or List<int>
                    // Simpler: just show location count
                    final locCount =
                        _extractLocIds(w['locations']).length;
                    final locText = locCount > 0
                        ? '$locCount ubicacione${locCount != 1 ? 's' : ''}'
                        : '';
                    return Card(
                      child: ListTile(
                        leading: CircleAvatar(
                          backgroundColor: isDisabled
                              ? Colors.grey.withAlpha(25)
                              : AppTheme.success.withAlpha(25),
                          child: Text(
                            displayName.isNotEmpty
                                ? displayName[0].toUpperCase()
                                : '?',
                            style: TextStyle(
                                color: isDisabled
                                    ? Colors.grey
                                    : AppTheme.success,
                                fontWeight: FontWeight.w700),
                          ),
                        ),
                        title: Row(children: [
                          Expanded(
                              child: Text(displayName,
                                  style: const TextStyle(
                                      fontWeight: FontWeight.w600,
                                      fontSize: 14),
                                  overflow: TextOverflow.ellipsis)),
                          if (isDisabled)
                            U.badge('Pausado',
                                color: AppTheme.danger, small: true),
                        ]),
                        subtitle: Text(
                            '${roleLabels[roleKey] ?? roleKey}${locText.isNotEmpty ? ' · $locText' : ''}',
                            style: TextStyle(
                                color: Colors.grey[600], fontSize: 12)),
                        trailing: canManage
                            ? Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  IconButton(
                                      icon: const Icon(
                                          Icons.edit_outlined,
                                          size: 20),
                                      onPressed: () => _edit(w)),
                                  IconButton(
                                      icon: Icon(
                                          isDisabled
                                              ? Icons.check_circle_outline
                                              : Icons
                                                  .pause_circle_outline,
                                          size: 20),
                                      onPressed: () =>
                                          _toggleDisabled(w)),
                                  IconButton(
                                      icon: const Icon(
                                          Icons.delete_outline,
                                          size: 20,
                                          color: AppTheme.danger),
                                      onPressed: () => _delete(w)),
                                ],
                              )
                            : null,
                      ),
                    );
                  },
                ),
              );
            },
          ),
        ),
      ]),
    );
  }
}
