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
  // Caps marcadas como solo lectura en wp-admin: bloqueadas en TODAS las filas.
  List<String> _readonly = [];
  String _currentRole = '';
  final Map<String, bool> _dirty = {}; // "role:cap" => true/false
  bool _saving = false;
  bool _loading = true;

  // Rol del usuario logueado (slug corto: owner/storekeeper/seller).
  String get _myRole => '${AuthService.I.me?['role'] ?? ''}';

  // El dueño NO se edita a sí mismo: solo el administrador del sitio lo hace.
  bool get _canEditOwner => _myRole != 'owner';

  @override
  void initState() {
    super.initState();
    // 1) Pintar lo que ya está en caché (funciona SIN conexión).
    _loadFromCache();
    // 2) Refrescar desde el servidor si hay red; sin red no-op y la caché
    //    anterior sigue siendo la fuente de verdad.
    SyncService.I.pullCache('ws_permissions_get', {}, 'ws_permissions_get')
        .then((_) => _loadFromCache())
        .catchError((_) => _loadFromCache());
    SyncService.I.onChange(_onSync);
  }

  @override
  void dispose() {
    SyncService.I.removeOnChange(_onSync);
    super.dispose();
  }

  void _onSync() {
    // Los cambios sin guardar (`_dirty`) NO se descartan: el usuario puede
    // estar ajustando la matriz mientras corre un pull.
    if (!mounted || _dirty.isNotEmpty) return;
    _loadFromCache();
  }

  /// Aplica a la UI el payload cacheado de `ws_permissions_get`. Vacío si no
  /// hay nada cacheado: en ese caso se muestra el estado de "sin datos" y no
  /// un spinner eterno.
  Future<void> _loadFromCache() async {
    dynamic raw;
    try {
      raw = await DbService.I.cacheGet('ws_permissions_get');
    } catch (_) {
      raw = null;
    }
    if (!mounted) return;
    final data =
        raw is Map ? Map<String, dynamic>.from(raw) : <String, dynamic>{};
    setState(() {
      _caps = Map<String, dynamic>.from(data['caps'] ?? {});
      _matrix = Map<String, dynamic>.from(data['matrix'] ?? {});
      _roles =
          (data['roles'] as Map?)?.keys.map((e) => '$e').toList() ?? <String>[];
      _readonly =
          (data['readonly'] as List?)?.map((e) => '$e').toList() ?? <String>[];
      if (_currentRole.isEmpty || !_roles.contains(_currentRole)) {
        _currentRole = _roles.isNotEmpty ? _roles.first : 'owner';
      }
      // SIEMPRE se apaga el indicador: antes solo se hacía dentro del `if`,
      // así que sin caché la pantalla se quedaba cargando para siempre.
      _loading = false;
    });
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
    // El dueño no puede modificar su propia fila (solo el admin del sitio la
    // edita) y las capacidades de solo lectura no se tocan en ninguna fila.
    final editable = _dirty.entries.where((e) {
      final parts = e.key.split(':');
      final r = parts.first;
      final cap = parts.sublist(1).join(':');
      if (_readonly.contains(cap)) return false;
      return _canEditOwner || r != 'owner';
    }).toList();
    final pending = Map<String, bool>.fromEntries(editable);
    if (pending.isEmpty) {
      if (mounted) U.toast(context, 'Sin cambios', kind: 'warn');
      return;
    }
    setState(() => _saving = true);
    // Build new matrix
    final next = <String, dynamic>{};
    for (final r in _roles) {
      next[r] = Map<String, dynamic>.from(_matrix[r] is Map ? _matrix[r] : {});
    }
    for (final entry in pending.entries) {
      final parts = entry.key.split(':');
      final r = parts.first;
      final cap = parts.sublist(1).join(':');
      if (next[r] is Map) next[r][cap] = entry.value;
    }
    final ok = await U.handlePush(
      context,
      SyncService.I.push('ws_save_permissions', {
        // La matriz se envía como Map: `ApiService._doReq` la serializa con
        // `jsonEncode` y el servidor hace `json_decode`. Antes se mandaba
        // `Uri.encodeComponent('${next}')` (el `toString()` de Dart, que no
        // es JSON) y `json_decode` devolvía NULL → el guardado no hacía nada.
        'matrix': next,
      }),
      'Permisos guardados',
      onOk: () =>
          SyncService.I.pullCache('ws_permissions_get', {}, 'ws_permissions_get')
              .then((_) => _loadFromCache()),
      onQueued: (qp) async {
        // Solo se actualiza la matriz: se conservan caps/roles/readonly. Si
        // la caché no está completa todavía (p.ej. primera ejecución sin
        // red), no se escribe nada para no dejar un payload a medias que la
        // pantalla interprete como "sin datos".
        final prev = await DbService.I.cacheGet('ws_permissions_get');
        if (prev is! Map || prev['matrix'] is! Map) return;
        final map = Map<String, dynamic>.from(prev);
        map['matrix'] = next;
        await DbService.I.cacheSet('ws_permissions_get', map);
        if (mounted) _loadFromCache();
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
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.lock_outline,
                  size: 34,
                  color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted),
              const SizedBox(height: 10),
              Text('Sin datos de permisos.',
                  style: TextStyle(
                      fontWeight: FontWeight.w600,
                      color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted)),
              const SizedBox(height: 6),
              Text(
                'Se cargarán al sincronizar. Comprueba la conexión o pulsa '
                'el botón de sincronizar.',
                textAlign: TextAlign.center,
                style: TextStyle(
                    color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted,
                    fontSize: 12),
              ),
            ],
          ),
        ),
      );
    }

    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
        child: Text('Marca qué puede hacer cada rol y pulsa Guardar.',
            style: TextStyle(color: Colors.grey[600], fontSize: 12)),
      ),
      if (!_canEditOwner)
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
          child: Row(children: [
            Icon(Icons.lock_outline, size: 14, color: Colors.grey[500]),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                'Tus permisos (Dueño) los gestiona el administrador del sitio. Aquí puedes editarlos si estás viendo otro rol.',
                style: TextStyle(color: Colors.grey[500], fontSize: 11),
              ),
            ),
          ]),
        ),

      // Caps de solo lectura: bloqueadas en todas las filas.
      if (_readonly.isNotEmpty)
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
          child: Row(children: [
            Icon(Icons.lock_outline, size: 14, color: Colors.grey[500]),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                'Solo lectura (los define el administrador del sitio): '
                '${_readonly.map((c) => '${_caps[c] ?? c}').join(' · ')}',
                style: TextStyle(color: Colors.grey[500], fontSize: 11),
              ),
            ),
          ]),
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
            // Solo lectura: fila del dueño (la edita el admin del sitio) o
            // cap marcada como solo lectura en wp-admin (en cualquier fila).
            final locked =
                (_currentRole == 'owner' && !_canEditOwner) || _readonly.contains(cap);
            return Card(
              child: SwitchListTile.adaptive(
                title: Text('$label', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                subtitle: Text(
                    locked ? '$cap · solo lectura' : cap,
                    style: TextStyle(color: Colors.grey[500], fontSize: 11)),
                value: on,
                onChanged: locked
                    ? null
                    : (v) {
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
