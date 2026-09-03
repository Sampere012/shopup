import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';
import '../widgets/crud.dart';

/// Gastos con navegación por mes, resumen y crear/editar/eliminar.
class ExpensesScreen extends StatefulWidget {
  const ExpensesScreen({super.key});

  @override
  State<ExpensesScreen> createState() => _ExpensesScreenState();
}

class _ExpensesScreenState extends State<ExpensesScreen> {
  int _year = DateTime.now().year;
  int _month = DateTime.now().month;
  late Future<List<Map<String, dynamic>>> _future;

  static const _months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
      'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
  static const _cats = ['Alquiler', 'Servicios', 'Sueldos', 'Compra', 'Mantenimiento',
      'Transporte', 'Marketing', 'Otros'];

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
    _future = DbService.I.cacheGet('ws_expenses_list').then((raw) {
      if (raw is Map) {
        final expenses = raw['expenses'];
        if (expenses is List) return expenses.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
      }
      if (raw is List) return raw.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
      return <Map<String, dynamic>>[];
    });
  }

  void _prevMonth() {
    setState(() {
      _month--;
      if (_month < 1) { _month = 12; _year--; }
      _reload();
    });
  }

  void _nextMonth() {
    setState(() {
      _month++;
      if (_month > 12) { _month = 1; _year++; }
      _reload();
    });
  }

  Future<void> _edit(Map<String, dynamic>? e) async {
    final locations = await DbService.I.all('locations');
    final concept = TextEditingController(text: '${e?['concept'] ?? ''}');
    final amount = TextEditingController(text: '${e?['amount'] ?? ''}');
    final note = TextEditingController(text: '${e?['note'] ?? ''}');
    final category = ValueNotifier<String>(e?['category'] ?? _cats.first);
    final dateRaw = TextEditingController(text: e?['date_raw'] ??
        '$_year-${_month.toString().padLeft(2, '0')}-${DateTime.now().day.toString().padLeft(2, '0')}');
    final locId = ValueNotifier<String>('${e?['location_id'] ?? '0'}');

    final ok = await showFormSheet(
      context,
      title: e == null ? 'Nuevo gasto' : 'Editar gasto',
      fields: [
        fField('Concepto *', concept),
        Row(children: [
          Expanded(child: fField('Monto *', amount, type: TextInputType.number)),
          const SizedBox(width: 8),
          Expanded(child: ValueListenableBuilder<String>(
            valueListenable: category,
            builder: (_, v, __) => DropdownButtonFormField<String>(
              initialValue: v,
              decoration: const InputDecoration(labelText: 'Categoría'),
              items: _cats.map((c) => DropdownMenuItem(value: c, child: Text(c))).toList(),
              onChanged: (val) { if (val != null) category.value = val; },
            ),
          )),
        ]),
        fField('Fecha', dateRaw),
        ValueListenableBuilder<String>(
          valueListenable: locId,
          builder: (_, v, __) => DropdownButtonFormField<String>(
            initialValue: v,
            decoration: const InputDecoration(labelText: 'Ubicación'),
            items: [
              const DropdownMenuItem(value: '0', child: Text('Sin ubicación')),
              ...locations.map((l) => DropdownMenuItem(value: '${l['id']}', child: Text('${l['name'] ?? ''}'))),
            ],
            onChanged: (val) { if (val != null) locId.value = val; },
          ),
        ),
        fField('Nota', note),
      ],
      onSave: () async {
        if (concept.text.trim().isEmpty || (num.tryParse(amount.text) ?? 0) == 0) {
          U.toast(context, 'Concepto y monto son obligatorios', kind: 'err');
          return false;
        }
        return U.handlePush(
          context,
          SyncService.I.push('ws_expense_save', {
            'id': e != null ? (num.tryParse('${e['id']}') ?? 0) : 0,
            'concept': concept.text.trim(),
            'amount': num.tryParse(amount.text) ?? 0,
            'category': category.value,
            'expense_date': dateRaw.text.trim(),
            'location_id': locId.value,
            'note': note.text.trim(),
          }),
          'Guardado',
          onOk: () => SyncService.I.pullCache('ws_expenses_list', {'year': 0, 'month': 0}, 'ws_expenses_list'),
          onQueued: (payload) async {
            final raw = await DbService.I.cacheGet('ws_expenses_list');
            final rows = (raw is List) ? List<Map<String, dynamic>>.from(raw) : <Map<String, dynamic>>[];
            final id = payload['id'] ?? 0;
            if (id == 0) {
              rows.add({
                'id': -DateTime.now().millisecondsSinceEpoch,
                'concept': payload['concept'], 'amount': payload['amount'],
                'category': payload['category'], 'date_raw': payload['expense_date'],
                'location_id': payload['location_id'], 'note': payload['note'],
              });
            } else {
              for (final r in rows) {
                if ('${r['id']}' == '$id') {
                  r['concept'] = payload['concept']; r['amount'] = payload['amount'];
                  r['category'] = payload['category']; r['date_raw'] = payload['expense_date'];
                  break;
                }
              }
            }
            await DbService.I.cacheSet('ws_expenses_list', rows);
          },
        );
      },
    );
    if (ok == true && mounted) { _reload(); setState(() {}); }
  }

  Future<void> _delete(Map<String, dynamic> e) async {
    if (await U.confirm(context, '¿Eliminar este gasto?', action: 'Eliminar')) {
      await U.handlePush(
        context,
        SyncService.I.push('ws_expense_delete', {'id': e['id']}),
        'Eliminado',
        onOk: () => SyncService.I.pullCache('ws_expenses_list', {'year': 0, 'month': 0}, 'ws_expenses_list'),
        onQueued: (qp) async {
          final raw = await DbService.I.cacheGet('ws_expenses_list');
          final rows = (raw is List) ? List<Map<String, dynamic>>.from(raw) : <Map<String, dynamic>>[];
          rows.removeWhere((r) => '${r['id']}' == '${e['id']}');
          await DbService.I.cacheSet('ws_expenses_list', rows);
        },
      );
      _reload(); setState(() {});
    }
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final canManage = AuthService.I.has('expenses_manage');
    final cur = AuthService.I.currency;

    return Scaffold(
      backgroundColor: Colors.transparent,
      floatingActionButton: canManage
          ? FloatingActionButton.extended(
              heroTag: 'addExpense',
              onPressed: () => _edit(null),
              icon: const Icon(Icons.add),
              label: const Text('Gasto'),
            )
          : null,
      body: Column(children: [
        // Month navigation
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
          child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            IconButton(icon: const Icon(Icons.chevron_left), onPressed: _prevMonth),
            Text('${_months[_month - 1]} $_year',
                style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
            IconButton(icon: const Icon(Icons.chevron_right), onPressed: _nextMonth),
          ]),
        ),
        // Summary stats
        FutureBuilder<List<Map<String, dynamic>>>(
          future: _future,
          builder: (context, snap) {
            final rows = snap.data ?? [];
            final total = rows.fold<num>(0, (a, e) => a + (num.tryParse('${e['amount']}') ?? 0));
            return Container(
              margin: const EdgeInsets.fromLTRB(14, 8, 14, 0),
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(
                gradient: LinearGradient(colors: [AppTheme.danger.withAlpha(20), AppTheme.danger.withAlpha(10)]),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Row(children: [
                const Icon(Icons.payments_outlined, color: AppTheme.danger, size: 20),
                const SizedBox(width: 10),
                Text('${rows.length} gasto${rows.length == 1 ? '' : 's'}', style: TextStyle(color: Colors.grey[600], fontSize: 12)),
                const Spacer(),
                Text(U.money(total, cur),
                    style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: AppTheme.danger)),
              ]),
            );
          },
        ),
        // Expense list
        Expanded(
          child: FutureBuilder<List<Map<String, dynamic>>>(
            future: _future,
            builder: (context, snap) {
              if (snap.connectionState != ConnectionState.done) {
                return const Center(child: CircularProgressIndicator());
              }
              var rows = snap.data ?? [];
              rows = rows.where((r) {
                final d = '${r['date_raw'] ?? r['date'] ?? ''}';
                return d.startsWith('$_year-${_month.toString().padLeft(2, '0')}');
              }).toList();
              rows.sort((a, b) => '${b['date_raw'] ?? b['date'] ?? ''}'.compareTo('${a['date_raw'] ?? a['date'] ?? ''}'));
              if (rows.isEmpty) {
                return Center(child: Text('Sin gastos este mes.',
                    style: TextStyle(color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted)));
              }
              return RefreshIndicator(
                onRefresh: () async { _reload(); await _future; setState(() {}); },
                child: ListView.separated(
                  padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
                  itemCount: rows.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    final e = rows[i];
                    final amount = num.tryParse('${e['amount']}') ?? 0;
                    return Card(
                      child: ListTile(
                        leading: Container(
                          padding: const EdgeInsets.all(8),
                          decoration: BoxDecoration(
                            color: AppTheme.danger.withAlpha(20),
                            borderRadius: BorderRadius.circular(10),
                          ),
                          child: const Icon(Icons.payments_outlined, color: AppTheme.danger, size: 20),
                        ),
                        title: Text('${e['concept'] ?? ''}',
                            style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
                        subtitle: Text(
                            '${e['category'] ?? ''} · ${e['date_label'] ?? e['date_raw'] ?? ''}',
                            style: TextStyle(color: Colors.grey[600], fontSize: 12)),
                        trailing: canManage
                            ? Row(mainAxisSize: MainAxisSize.min, children: [
                                Text(U.money(amount, cur),
                                    style: const TextStyle(fontWeight: FontWeight.w800, color: AppTheme.danger)),
                                IconButton(
                                    icon: const Icon(Icons.edit_outlined, size: 18),
                                    onPressed: () => _edit(e)),
                                IconButton(
                                    icon: const Icon(Icons.delete_outline, size: 18, color: AppTheme.danger),
                                    onPressed: () => _delete(e)),
                              ])
                            : Text(U.money(amount, cur),
                                style: const TextStyle(fontWeight: FontWeight.w800, color: AppTheme.danger)),
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
