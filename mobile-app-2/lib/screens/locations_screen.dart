import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';
import '../widgets/crud.dart';

/// Ubicaciones con filtro por tipo y crear/editar.
class LocationsScreen extends StatefulWidget {
  const LocationsScreen({super.key});

  @override
  State<LocationsScreen> createState() => _LocationsScreenState();
}

class _LocationsScreenState extends State<LocationsScreen> {
  String _q = '';
  String _typeFilter = 'all';
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
    _future = DbService.I.all('locations');
  }

  Future<void> _edit(Map<String, dynamic>? loc) async {
    final name = TextEditingController(text: '${loc?['name'] ?? ''}');
    final currency = TextEditingController(text: '${loc?['currency'] ?? AuthService.I.currency}');
    final deliveryCost = TextEditingController(text: '${loc?['delivery_cost'] ?? ''}');
    final address = TextEditingController(text: '${loc?['address'] ?? ''}');
    final description = TextEditingController(text: '${loc?['description'] ?? ''}');
    final whatsapp = TextEditingController(text: '${loc?['whatsapp'] ?? ''}');
    final type = ValueNotifier<String>(loc?['type'] ?? 'pv');
    // ignore: unnecessary_non_null_assertion — analyzer sees loc is non-null after ||
    final active = ValueNotifier<bool>(loc == null || '${loc['active']}' != '0');
    final posEnabled = ValueNotifier<bool>(loc == null || '${loc['pos_enabled']}' != '0');

    final ok = await showFormSheet(
      context,
      title: loc == null ? 'Nueva ubicación' : 'Editar ubicación',
      fields: [
        ValueListenableBuilder<String>(
          valueListenable: type,
          builder: (_, v, __) => DropdownButtonFormField<String>(
            initialValue: v,
            decoration: const InputDecoration(labelText: 'Tipo'),
            items: const [
              DropdownMenuItem(value: 'pv', child: Text('Punto de venta (PV)')),
              DropdownMenuItem(value: 'almacen', child: Text('Almacén')),
            ],
            onChanged: (val) { if (val != null) type.value = val; },
          ),
        ),
        fField('Nombre *', name),
        Row(children: [
          Expanded(child: fField('Moneda', currency)),
          const SizedBox(width: 8),
          Expanded(child: fField('Domicilio', deliveryCost, type: TextInputType.number)),
        ]),
        fField('Dirección', address),
        fField('Descripción', description),
        fField('WhatsApp', whatsapp),
        ValueListenableBuilder<bool>(
          valueListenable: active,
          builder: (_, v, __) => SwitchListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            title: Text(v ? 'Activa (visible)' : 'Inactiva', style: const TextStyle(fontSize: 14)),
            value: v,
            onChanged: (val) => active.value = val,
          ),
        ),
        ValueListenableBuilder<bool>(
          valueListenable: posEnabled,
          builder: (_, v, __) => SwitchListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            title: Text(v ? 'Mostrar en el POS' : 'Sin POS', style: const TextStyle(fontSize: 14)),
            value: v,
            onChanged: (val) => posEnabled.value = val,
          ),
        ),
      ],
      onSave: () async {
        if (name.text.trim().isEmpty) {
          U.toast(context, 'El nombre es obligatorio', kind: 'err');
          return false;
        }
        return U.handlePush(
          context,
          SyncService.I.push('ws_save_location', {
            'id': loc != null ? (num.tryParse('${loc['id']}') ?? 0) : 0,
            'type': type.value,
            'name': name.text.trim(),
            'currency': currency.text.trim(),
            'delivery_cost': num.tryParse(deliveryCost.text) ?? 0,
            'address': address.text.trim(),
            'description': description.text.trim(),
            'whatsapp': whatsapp.text.trim(),
            'active': active.value ? 1 : 0,
            'pos_enabled': posEnabled.value ? 1 : 0,
          }),
          'Guardado',
          onOk: () => SyncService.I.pullStore('ws_cache_locations', {}, 'locations', cacheKey: 'ws_locations_list'),
          onQueued: (payload) async {
            final rows = await DbService.I.all('locations');
            final id = payload['id'] ?? 0;
            if (id == 0) {
              rows.add({
                'id': -DateTime.now().millisecondsSinceEpoch,
                'name': payload['name'], 'type': payload['type'],
                'currency': payload['currency'], 'address': payload['address'],
                'active': payload['active'], 'pos_enabled': payload['pos_enabled'],
                'delivery_cost': payload['delivery_cost'],
              });
            } else {
              for (final r in rows) {
                if ('${r['id']}' == '$id') {
                  r['name'] = payload['name']; r['type'] = payload['type'];
                  r['currency'] = payload['currency']; r['address'] = payload['address'];
                  r['active'] = payload['active']; r['pos_enabled'] = payload['pos_enabled'];
                  r['delivery_cost'] = payload['delivery_cost'];
                  break;
                }
              }
            }
            await DbService.I.replaceAll('locations', rows);
          },
        );
      },
    );
    if (ok == true && mounted) { _reload(); setState(() {}); }
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final canManage = AuthService.I.has('locations_manage');

    return Scaffold(
      backgroundColor: Colors.transparent,
      floatingActionButton: canManage
          ? FloatingActionButton.extended(
              heroTag: 'addLocation',
              onPressed: () => _edit(null),
              icon: const Icon(Icons.add),
              label: const Text('Ubicación'),
            )
          : null,
      body: Column(children: [
        // Search
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
          child: TextField(
            decoration: InputDecoration(
              hintText: 'Buscar por nombre…',
              prefixIcon: const Icon(Icons.search),
              isDense: true,
              filled: true,
              fillColor: isDark ? AppTheme.darkSurface : Colors.white,
            ),
            onChanged: (v) => setState(() => _q = v.trim().toLowerCase()),
          ),
        ),
        // Type filter chips
        SizedBox(
          height: 44,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
            children: [
              _chip('Todas', 'all'),
              _chip('PV', 'pv'),
              _chip('Almacenes', 'almacen'),
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
              if (_typeFilter != 'all') {
                rows = rows.where((r) => '${r['type'] ?? ''}' == _typeFilter).toList();
              }
              if (_q.isNotEmpty) {
                rows = rows.where((r) => '${r['name'] ?? ''}'.toLowerCase().contains(_q)).toList();
              }
              rows.sort((a, b) => '${a['name'] ?? ''}'.toLowerCase().compareTo('${b['name'] ?? ''}'.toLowerCase()));
              if (rows.isEmpty) {
                return Center(child: Text(
                  rows.isEmpty && _typeFilter != 'all' ? 'Sin ubicaciones con estos filtros.' : 'Sin ubicaciones.',
                  style: TextStyle(color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted)));
              }
              return RefreshIndicator(
                onRefresh: () async { _reload(); await _future; setState(() {}); },
                child: ListView.separated(
                  padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
                  itemCount: rows.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    final l = rows[i];
                    final posEnabled = '${l['pos_enabled']}' != '0' && '${l['pos_enabled']}' != 'false';
                    final typeLabel = l['type'] == 'pv' ? 'PV' : 'Almacén';
                    return Card(
                      child: ListTile(
                        leading: Container(
                          padding: const EdgeInsets.all(8),
                          decoration: BoxDecoration(
                            color: AppTheme.primary.withAlpha(20),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: const Icon(Icons.location_on_outlined, color: AppTheme.primary, size: 20),
                        ),
                        title: Text('${l['name'] ?? ''}',
                            style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                        subtitle: Row(children: [
                          U.badge(typeLabel, color: AppTheme.primary, small: true),
                          if (!posEnabled) ...[
                            const SizedBox(width: 4),
                            U.badge('Sin POS', color: AppTheme.danger, small: true),
                          ],
                        ]),
                        trailing: canManage
                            ? IconButton(
                                icon: const Icon(Icons.edit_outlined, size: 20),
                                onPressed: () => _edit(l),
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

  Widget _chip(String label, String value) {
    final selected = value == _typeFilter;
    return Padding(
      padding: const EdgeInsets.only(right: 6),
      child: ChoiceChip(
        label: Text(label, style: const TextStyle(fontSize: 12)),
        selected: selected,
        onSelected: (_) => setState(() => _typeFilter = value),
        visualDensity: VisualDensity.compact,
      ),
    );
  }
}
