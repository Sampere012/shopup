import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';

/// Permisos: matriz roles × capacidades, edición y guardado (como Cordova).
class PermissionsScreen extends StatefulWidget {
  const PermissionsScreen({super.key});

  @override
  State<PermissionsScreen> createState() => _PermissionsScreenState();
}

class _PermissionsScreenState extends State<PermissionsScreen> {
  Map<String, dynamic> _caps = {};
  Map<String, dynamic> _matrix = {};
  List<String> _roles = [];
  String _currentRole = '';
  final Map<String, bool> _dirty = {}; // "role:cap" => true/false
  bool _saving = false;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _loadFromCache();
    _loadFromServer();
    SyncService.I.onChange(_onSync);
  }

  void _onSync() {
    if (mounted) { _loadFromCache(); _loadFromServer(); }
  }

  Future<void> _loadFromCache() async {
    final raw = await DbService.I.cacheGet('ws_permissions_get');
    if (raw is Map && mounted) {
      final data = Map<String, dynamic>.from(raw);
      setState(() {
        _caps = Map<String, dynamic>.from(data['caps'] ?? {});
        _matrix = Map<String, dynamic>.from(data['matrix'] ?? {});
        _roles = (data['roles'] as Map?)?.keys.map((e) => '$e').toList() ?? [];
        _currentRole = _roles.isNotEmpty ? _roles.first : 'owner';
        _loading = false;
      });
    }
  }

  Future<void> _loadFromServer() async {
    try {
      final d = await DbService.I.cacheGet('ws_permissions_get');
      if (d is Map && mounted) {
        final data = Map<String, dynamic>.from(d);
        setState(() {
          _caps = Map<String, dynamic>.from(data['caps'] ?? {});
          _matrix = Map<String, dynamic>.from(data['matrix'] ?? {});
          _roles = (data['roles'] as Map?)?.keys.map((e) => '$e').toList() ?? [];
          if (_currentRole.isEmpty || !_roles.contains(_currentRole)) {
            _currentRole = _roles.isNotEmpty ? _roles.first : 'owner';
          }
          _dirty.clear();
          _loading = false;
        });
      }
    } catch (_) {}
  }

  bool _valueFor(String role, String cap) {
    final k = '$role:$cap';
    if (_dirty.containsKey(k)) return _dirty[k]!;
    final roleMatrix = _matrix[role];
    if (roleMatrix is Map) return roleMatrix[cap] == true;
    return false;
  }

  int _dirtyCount(String role) {
    return _dirty.keys.where((k) => k.startsWith('$role:')).length;
  }

  Future<void> _save() async {
    if (_dirty.isEmpty) {
      if (mounted) U.toast(context, 'Sin cambios', kind: 'warn');
      return;
    }
    setState(() => _saving = true);
    // Build new matrix
    final next = <String, dynamic>{};
    for (final r in _roles) {
      next[r] = Map<String, dynamic>.from(_matrix[r] is Map ? _matrix[r] : {});
    }
    for (final entry in _dirty.entries) {
      final parts = entry.key.split(':');
      final r = parts.first;
      final cap = parts.sublist(1).join(':');
      if (next[r] is Map) next[r][cap] = entry.value;
    }
    final ok = await U.handlePush(
      context,
      SyncService.I.push('ws_save_permissions', {
        'matrix': Uri.encodeComponent('${next}'),
      }),
      'Permisos guardados',
      onOk: () => SyncService.I.pullCache('ws_permissions_get', {}, 'ws_permissions_get'),
      onQueued: (qp) async {
        await DbService.I.cacheSet('ws_permissions_get', {'matrix': next});
      },
    );
    if (ok) {
      _matrix = next;
      _dirty.clear();
      if (mounted) {
        // Refresh session so menu updates
        AuthService.I.refresh();
      }
    }
    if (mounted) setState(() => _saving = false);
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final roleLabels = {'owner': 'Dueño', 'storekeeper': 'Almacenero', 'seller': 'Vendedor'};
    final capKeys = _caps.keys.toList()..sort();

    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }

    if (_caps.isEmpty || _roles.isEmpty) {
      return Center(
        child: Text('Sin datos de permisos.',
            style: TextStyle(color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted)),
      );
    }

    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
        child: Text('Marca qué puede hacer cada rol y pulsa Guardar.',
            style: TextStyle(color: Colors.grey[600], fontSize: 12)),
      ),

      // Role tabs
      SizedBox(
        height: 44,
        child: ListView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
          children: _roles.map((r) {
            final selected = r == _currentRole;
            final count = _dirtyCount(r);
            return Padding(
              padding: const EdgeInsets.only(right: 8),
              child: ChoiceChip(
                label: Row(mainAxisSize: MainAxisSize.min, children: [
                  Text(roleLabels[r] ?? r, style: const TextStyle(fontSize: 13)),
                  if (count > 0) ...[
                    const SizedBox(width: 4),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
                      decoration: BoxDecoration(
                        color: AppTheme.amber.withAlpha(30),
                        borderRadius: BorderRadius.circular(999),
                      ),
                      child: Text('$count', style: const TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: AppTheme.amber)),
                    ),
                  ],
                ]),
                selected: selected,
                onSelected: (_) => setState(() => _currentRole = r),
              ),
            );
          }).toList(),
        ),
      ),

      // Permission toggles
      Expanded(
        child: ListView.separated(
          padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
          itemCount: capKeys.length,
          separatorBuilder: (_, __) => const SizedBox(height: 4),
          itemBuilder: (context, i) {
            final cap = capKeys[i];
            final label = _caps[cap] ?? cap;
            final on = _valueFor(_currentRole, cap);
            return Card(
              child: SwitchListTile.adaptive(
                title: Text('$label', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                subtitle: Text(cap, style: TextStyle(color: Colors.grey[500], fontSize: 11)),
                value: on,
                onChanged: (v) {
                  setState(() {
                    _dirty['$_currentRole:$cap'] = v;
                  });
                },
              ),
            );
          },
        ),
      ),

      // Save button
      Padding(
        padding: const EdgeInsets.fromLTRB(14, 0, 14, 16),
        child: SizedBox(
          width: double.infinity,
          child: FilledButton.icon(
            onPressed: _saving ? null : _save,
            icon: _saving
                ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                : const Icon(Icons.save_outlined, size: 18),
            label: Text(_saving ? 'Guardando…' : 'Guardar cambios'),
          ),
        ),
      ),
    ]);
  }
}
