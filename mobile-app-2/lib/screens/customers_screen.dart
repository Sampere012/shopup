import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';
import '../widgets/crud.dart';

/// Clientes con crear/editar.
class CustomersScreen extends StatefulWidget {
  const CustomersScreen({super.key});

  @override
  State<CustomersScreen> createState() => _CustomersScreenState();
}

class _CustomersScreenState extends State<CustomersScreen> {
  String _q = '';
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
    _future = DbService.I.all('customers');
  }

  Future<void> _edit(Map<String, dynamic>? c) async {
    final name = TextEditingController(text: '${c?['name'] ?? ''}');
    final phone = TextEditingController(text: '${c?['phone'] ?? ''}');
    final doc = TextEditingController(text: '${c?['document'] ?? c?['doc'] ?? ''}');
    final address = TextEditingController(text: '${c?['address'] ?? ''}');

    final ok = await showFormSheet(
      context,
      title: c == null ? 'Nuevo cliente' : 'Editar cliente',
      fields: [
        fField('Nombre *', name),
        fField('Teléfono', phone),
        fField('Carnet / Cédula', doc),
        fField('Dirección', address),
      ],
      onSave: () async {
        if (name.text.trim().isEmpty) {
          U.toast(context, 'El nombre es obligatorio', kind: 'err');
          return false;
        }
        return U.handlePush(
          context,
          SyncService.I.push('ws_customers_save', {
            'id': c != null ? (num.tryParse('${c['id']}') ?? 0) : 0,
            'name': name.text.trim(),
            'phone': phone.text.trim(),
            'doc': doc.text.trim(),
            'address': address.text.trim(),
          }),
          'Guardado',
          onOk: () => SyncService.I.pullStore(
              'ws_cache_customers', {}, 'customers',
              cacheKey: 'ws_customers_get'),
          onQueued: (payload) async {
            // Actualización optimista: agregar/editar en SQLite local
            final rows = await DbService.I.all('customers');
            final id = payload['id'] ?? 0;
            if (id == 0) {
              // Crear: agregar con ID temporal negativo
              rows.add({
                'id': -DateTime.now().millisecondsSinceEpoch,
                'name': payload['name'], 'phone': payload['phone'],
                'doc': payload['doc'], 'address': payload['address'],
              });
            } else {
              // Editar: actualizar el registro existente
              for (final r in rows) {
                if ('${r['id']}' == '$id') {
                  r['name'] = payload['name']; r['phone'] = payload['phone'];
                  r['doc'] = payload['doc']; r['address'] = payload['address'];
                  break;
                }
              }
            }
            await DbService.I.replaceAll('customers', rows);
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
    final canCreate = AuthService.I.has('customers_create');
    final canEdit = AuthService.I.has('customers_edit');

    return Scaffold(
      backgroundColor: Colors.transparent,
      floatingActionButton: canCreate
          ? FloatingActionButton.extended(
              heroTag: 'addCustomer',
              onPressed: () => _edit(null),
              icon: const Icon(Icons.add),
              label: const Text('Cliente'),
            )
          : null,
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
          child: TextField(
            decoration: InputDecoration(
              hintText: 'Buscar cliente…',
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
                rows = rows.where((r) =>
                    '${r['name'] ?? ''}'.toLowerCase().contains(_q) ||
                    '${r['phone'] ?? ''}'.contains(_q) ||
                    '${r['doc'] ?? r['document'] ?? ''}'.toLowerCase().contains(_q)).toList();
              }
              rows.sort((a, b) => '${a['name'] ?? ''}'.toLowerCase().compareTo('${b['name'] ?? ''}'.toLowerCase()));
              if (rows.isEmpty) {
                return Center(child: Text('Sin clientes.',
                    style: TextStyle(color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted)));
              }
              return RefreshIndicator(
                onRefresh: () async { _reload(); await _future; setState(() {}); },
                child: ListView.separated(
                  padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
                  itemCount: rows.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    final c = rows[i];
                    return Card(
                      child: ListTile(
                        leading: CircleAvatar(
                          backgroundColor: AppTheme.primary.withAlpha(25),
                          child: Text(
                            '${c['name'] ?? '?'}'.isNotEmpty ? '${c['name']}'[0].toUpperCase() : '?',
                            style: const TextStyle(color: AppTheme.primary, fontWeight: FontWeight.w700),
                          ),
                        ),
                        title: Text('${c['name'] ?? ''}',
                            style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                        subtitle: Text(
                            '${c['phone'] ?? ''}${'${c['doc'] ?? c['document'] ?? ''}'.isNotEmpty ? ' · ${c['doc'] ?? c['document']}' : ''}',
                            style: TextStyle(color: Colors.grey[600], fontSize: 12)),
                        trailing: canEdit
                            ? IconButton(
                                icon: const Icon(Icons.edit_outlined, size: 20),
                                onPressed: () => _edit(c),
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
