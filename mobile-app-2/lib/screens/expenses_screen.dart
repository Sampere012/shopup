import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/api_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';
import '../widgets/crud.dart';

/// Gastos IGUAL QUE LA WEB:
/// - Navegación por mes; el gasto es POR MES (su fecha lo ubica en el mes).
/// - Resumen del MES (no del histórico): gastos del mes y total del mes.
/// - Alta/edición con Concepto, Monto, Ubicación (General = todas), Categoría,
///   FECHA (calendario, como el input date de la web) y Nota.
/// - Duplicar un gasto en 1/3/6/12 meses (como "Duplicar" de la web).
/// - Editar y eliminar SIEMPRE sobre la fila concreta (su id real en la nube).
class ExpensesScreen extends StatefulWidget {
  const ExpensesScreen({super.key});

  @override
  State<ExpensesScreen> createState() => _ExpensesScreenState();
}

class _ExpensesScreenState extends State<ExpensesScreen> {
  int _year = DateTime.now().year;
  int _month = DateTime.now().month;
  late Future<List<Map<String, dynamic>>> _future;

  /// Últimas filas leídas (para sugerencias de categorías, como el datalist).
  List<Map<String, dynamic>> _rows = const [];

  static const _months = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
      'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
  static const _cats = ['Alquiler', 'Servicios', 'Sueldos', 'Compra', 'Mantenimiento',
      'Transporte', 'Marketing', 'Otros'];

  String get _monthPrefix => '$_year-${_month.toString().padLeft(2, '0')}';

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
    _future = _readRows();
  }

  /// Filas completas de la caché (acepta la List del pull completo y el Map
  /// de pullCache con {expenses: [...]}): única fuente de lectura local.
  Future<List<Map<String, dynamic>>> _cacheRows() async {
    final raw = await DbService.I.cacheGet('ws_expenses_list');
    if (raw is Map) {
      final expenses = raw['expenses'];
      if (expenses is List) {
        return expenses.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
      }
      return <Map<String, dynamic>>[];
    }
    if (raw is List) {
      return raw.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
    }
    return <Map<String, dynamic>>[];
  }

  Future<void> _cacheSetRows(List<Map<String, dynamic>> rows) =>
      DbService.I.cacheSet('ws_expenses_list', rows);

  /// Lee el listado completo desde la caché y queda listo para el filtro
  /// del mes (igual que la web).
  Future<List<Map<String, dynamic>>> _readRows() async {
    final rows = await _cacheRows();
    if (mounted) _rows = rows;
    return rows;
  }

  /// Filas del MES seleccionado (filtro por la FECHA del gasto, como la web).
  List<Map<String, dynamic>> _monthRows(List<Map<String, dynamic>> rows) {
    final out = rows
        .where((r) => '${r['date_raw'] ?? r['date'] ?? ''}'.startsWith(_monthPrefix))
        .toList();
    out.sort((a, b) => '${b['date_raw'] ?? b['date'] ?? ''}'
        .compareTo('${a['date_raw'] ?? a['date'] ?? ''}'));
    return out;
  }

  /// ¿Quedan gastos en la cola offline (por enviar o por borrar)?
  Future<bool> _hasQueuedExpenses() async {
    final q = await DbService.I.pending();
    return q.any((op) =>
        '${op['action']}' == 'ws_expense_save' ||
        '${op['action']}' == 'ws_expense_delete');
  }

  /// Refresca el MES elegido desde la nube (como load() de la web) y fusiona
  /// con la caché completa para no perder los otros meses (offline-first).
  Future<void> _pullMonth() async {
    if (!SyncService.I.isOnline) return;
    try {
      final d = await ApiService.I.req('ws_expenses_list', {'year': _year, 'month': _month});
      final monthRows = (((d as Map)['expenses']) as List?)
              ?.whereType<Map>()
              .map((e) => Map<String, dynamic>.from(e))
              .toList() ??
          <Map<String, dynamic>>[];
      final queued = await _hasQueuedExpenses();
      final all = await _cacheRows();
      // Se reemplazan los del mes por los del servidor; los gastos AÚN en
      // cola (id negativo) se conservan y los obsoletos se purgan.
      all.removeWhere((r) {
        final id = num.tryParse('${r['id']}') ?? 0;
        if (id < 0) return !queued;
        return '${r['date_raw'] ?? r['date'] ?? ''}'.startsWith(_monthPrefix);
      });
      all.addAll(monthRows);
      await _cacheSetRows(all);
      if (mounted) { _reload(); setState(() {}); }
    } catch (_) {}
  }

  /// Refresca TODA la lista (year 0 / month 0) tras guardar o eliminar,
  /// conservando los gastos encolados offline que el servidor aún no tiene
  /// y descartando los locales obsoletos (ya sincronizados).
  Future<void> _refreshAll() async {
    final before = await _cacheRows();
    final pending = before.where((r) => (num.tryParse('${r['id']}') ?? 0) < 0).toList();
    await SyncService.I.pullCache(
        'ws_expenses_list', {'year': 0, 'month': 0}, 'ws_expenses_list',
        dataKey: 'expenses');
    final queued = await _hasQueuedExpenses();
    final rows = await _cacheRows();
    var changed = false;
    if (!queued) {
      final n = rows.length;
      rows.removeWhere((r) => (num.tryParse('${r['id']}') ?? 0) < 0);
      changed = rows.length != n;
    } else {
      final have = rows.map((r) => '${r['id']}').toSet();
      for (final p in pending) {
        if (!have.contains('${p['id']}')) { rows.add(p); changed = true; }
      }
    }
    if (changed) await _cacheSetRows(rows);
    if (mounted) { _reload(); setState(() {}); }
  }

  void _prevMonth() {
    setState(() {
      _month--;
      if (_month < 1) { _month = 12; _year--; }
      _reload();
    });
    _pullMonth();
  }

  void _nextMonth() {
    setState(() {
      _month++;
      if (_month > 12) { _month = 1; _year++; }
      _reload();
    });
    _pullMonth();
  }

  /// Muestra la gasto de [e] bajo su MES: navega la vista al mes de la fecha.
  void _goToMonthOf(String? dateRaw) {
    final m = RegExp(r'^(\d{4})-(\d{2})').firstMatch(dateRaw ?? '');
    if (m != null && mounted) {
      setState(() {
        _year = int.tryParse(m.group(1)!) ?? _year;
        _month = int.tryParse(m.group(2)!) ?? _month;
      });
    }
  }

  /// Suma [n] meses a una fecha 'YYYY-MM-DD' (ajusta el día si el mes no
  /// lo tiene, p. ej. 31 en febrero), igual que addMonths() de la web.
  String _addMonths(String dateStr, int n) {
    final parts = dateStr.split('-');
    var y = int.tryParse(parts.isNotEmpty ? parts[0] : '') ?? _year;
    var m = int.tryParse(parts.length > 1 ? parts[1] : '') ?? _month;
    var d = int.tryParse(parts.length > 2 ? parts[2] : '') ?? 1;
    final total = (m - 1) + n;
    y += total ~/ 12;
    m = total % 12 + 1;
    const daysIn = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    var max = daysIn[m - 1];
    final leap = (y % 4 == 0 && y % 100 != 0) || y % 400 == 0;
    if (m == 2 && leap) max = 29;
    if (d > max) d = max;
    return '$y-${m.toString().padLeft(2, '0')}-${d.toString().padLeft(2, '0')}';
  }

  /// Marca/limpia la repetición del gasto en meses siguientes.
  Future<void> _askRepeatMonths(ValueNotifier<int> repeat) async {
    final sel = await showModalBottomSheet<int>(
      context: context,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (ctx) => SafeArea(
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Padding(
            padding: EdgeInsets.all(14),
            child: Text('¿Repetir en cuántos meses?',
                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
          ),
          ...[1, 3, 6, 12].map((n) => ListTile(
                title: Text(n == 1 ? 'Solo este mes' : '$n meses seguidos'),
                subtitle: n == 1
                    ? null
                    : const Text('Se creará un gasto por mes con estos mismos datos'),
                trailing: n > 1
                    ? const Icon(Icons.calendar_month_outlined,
                        color: AppTheme.primary)
                    : null,
                onTap: () => Navigator.pop(ctx, n),
              )),
          const SizedBox(height: 6),
        ]),
      ),
    );
    if (sel != null) repeat.value = sel;
  }

  /// Campo FECHA con calendario (como el <input type="date"> de la web):
  /// la fecha decide el MES del gasto, sin errores de tecleo.
  Widget _dateField(TextEditingController c) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: () async {
          final initial = DateTime.tryParse(c.text) ??
              DateTime(_year, _month,
                  DateTime.now().day.clamp(1, 28));
          final picked = await showDatePicker(
            context: context,
            initialDate: initial,
            firstDate: DateTime(2000),
            lastDate: DateTime(2100),
          );
          if (picked != null) {
            c.text = '${picked.year}-'
                '${picked.month.toString().padLeft(2, '0')}-'
                '${picked.day.toString().padLeft(2, '0')}';
          }
        },
        child: InputDecorator(
          decoration: const InputDecoration(
            labelText: 'Fecha del gasto *',
            helperText: 'El gasto se registra en el mes de esta fecha',
            suffixIcon: Icon(Icons.calendar_month_outlined),
          ),
          child: Text(
            c.text.isEmpty ? 'Elegir fecha…' : c.text,
            style: TextStyle(
                fontSize: 14,
                color: c.text.isEmpty ? Colors.grey : null),
          ),
        ),
      ),
    );
  }

  /// Categorías disponibles: fijas + las que ya existen en la lista
  /// (igual que el datalist de la web).
  List<String> _categoryOptions() {
    final out = List<String>.from(_cats);
    for (final r in _rows) {
      final c = '${r['category'] ?? ''}'.trim();
      if (c.isNotEmpty && !out.contains(c)) out.add(c);
    }
    return out;
  }

  /// Categoría como en la web: texto libre con sugerencias (el datalist del
  /// panel) de las categorías fijas + las ya usadas en los gastos.
  Widget _categoryField(TextEditingController c) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: TextField(
        controller: c,
        maxLength: 120,
        decoration: InputDecoration(
          labelText: 'Categoría',
          counterText: '',
          hintText: 'Ej.: Alquiler, Servicios…',
          suffixIcon: IconButton(
            tooltip: 'Sugerencias',
            icon: const Icon(Icons.arrow_drop_down_circle, size: 20),
            onPressed: () async {
              final options = _categoryOptions();
              final sel = await showModalBottomSheet<String>(
                context: context,
                shape: const RoundedRectangleBorder(
                    borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
                builder: (ctx) => SafeArea(
                  child: ListView(
                    shrinkWrap: true,
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    children: [
                      const Padding(
                        padding: EdgeInsets.fromLTRB(16, 10, 16, 4),
                        child: Text('Categorías',
                            style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                      ),
                      for (final o in options)
                        ListTile(
                          dense: true,
                          title: Text(o, style: const TextStyle(fontSize: 14)),
                          trailing: o == c.text
                              ? const Icon(Icons.check, size: 16, color: AppTheme.success)
                              : null,
                          onTap: () => Navigator.pop(ctx, o),
                        ),
                    ],
                  ),
                ),
              );
              if (sel != null) c.text = sel;
            },
          ),
        ),
      ),
    );
  }

  /// Etiqueta de ubicación de una fila (web: badge "General" o el nombre).
  String _locText(Map<String, dynamic> e) {
    final lid = num.tryParse('${e['location_id'] ?? 0}') ?? 0;
    if (lid == 0) return 'General';
    final name = '${e['location_name'] ?? ''}';
    return name.isNotEmpty ? name : 'Ubicación #$lid';
  }

  Future<void> _edit(Map<String, dynamic>? e, {Map<String, dynamic>? preset, int? presetRepeat}) async {
    final source = preset ?? e;
    final locations = await DbService.I.all('locations');
    final concept = TextEditingController(text: '${source?['concept'] ?? ''}');
    final amount = TextEditingController(text: '${source?['amount'] ?? ''}');
    final note = TextEditingController(text: '${source?['note'] ?? ''}');
    final category = TextEditingController(text: '${source?['category'] ?? ''}');
    // Por defecto la fecha es del MES VISTO (como la web muestra el mes).
    final dateRaw = TextEditingController(text: source?['date_raw'] ??
        '$_year-${_month.toString().padLeft(2, '0')}-${DateTime.now().day.toString().padLeft(2, '0')}');
    // Ubicaciones del reparto (la primera opción es "General" = todas).
    final locItems = <String>['0', ...locations.map((l) => '${l['id']}')];
    var initLoc = '${source?['location_id'] ?? '0'}';
    if (!locItems.contains(initLoc)) locItems.add(initLoc);
    String locLabel(String id) {
      if (id == '0') return 'General (todas las ubicaciones)';
      for (final l in locations) {
        if ('${l['id']}' == id) return '${l['name'] ?? ''}';
      }
      return 'Ubicación #$id';
    }

    final locId = ValueNotifier<String>(initLoc);
    final repeat = ValueNotifier<int>(presetRepeat ?? 1);

    final ok = await showFormSheet(
      context,
      title: e == null ? 'Nuevo gasto' : 'Editar gasto',
      fields: [
        if (e == null)
          ValueListenableBuilder<int>(
            valueListenable: repeat,
            builder: (_, v, __) => ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.repeat, color: AppTheme.primary),
              title: Text(v <= 1 ? 'Gasto único (este mes)' : 'Se repetirá en $v meses',
                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
              subtitle: v > 1
                  ? const Text('Se crea un gasto por mes con estos datos',
                      style: TextStyle(fontSize: 11))
                  : null,
              trailing: TextButton(
                onPressed: () => _askRepeatMonths(repeat),
                child: const Text('Cambiar'),
              ),
              onTap: () => _askRepeatMonths(repeat),
            ),
          ),
        fField('Concepto *', concept),
        Row(children: [
          Expanded(child: fField('Monto *', amount, type: TextInputType.number)),
          const SizedBox(width: 8),
          Expanded(child: _categoryField(category)),
        ]),
        _dateField(dateRaw),
        ValueListenableBuilder<String>(
          valueListenable: locId,
          builder: (_, v, __) => DropdownButtonFormField<String>(
            key: ValueKey('exp_loc_$v'),
            initialValue: locItems.contains(v) ? v : '0',
            decoration: const InputDecoration(
              labelText: 'Ubicación',
              helperText: 'General = se reparte a todas las ubicaciones',
            ),
            items: [
              for (final id in locItems)
                DropdownMenuItem(value: id, child: Text(locLabel(id))),
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
        // La FECHA decide el MES del gasto (como la web): sin fecha no se guarda.
        final pickedDate = dateRaw.text.trim();
        if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(pickedDate)) {
          U.toast(context, 'Elige la fecha del gasto', kind: 'err');
          return false;
        }
        final basePayload = <String, dynamic>{
          'id': e != null ? (num.tryParse('${e['id']}') ?? 0) : 0,
          'concept': concept.text.trim(),
          'amount': num.tryParse(amount.text) ?? 0,
          'category': category.text.trim(),
          'expense_date': pickedDate,
          'location_id': locId.value,
          'note': note.text.trim(),
        };
        final rowId = num.tryParse('${basePayload['id']}') ?? 0;
        if (rowId < 0) {
          // Gasto creado sin conexión (id temporal negativo): se actualiza
          // solo local y se REESCRIBE la operación encolada, para que al
          // reconectar se envíen los datos ya corregidos.
          final rows = await _cacheRows();
          Map<String, dynamic>? old;
          for (final r in rows) {
            if ('${r['id']}' == '$rowId') {
              old = Map<String, dynamic>.from(r);
              r['concept'] = basePayload['concept'];
              r['amount'] = basePayload['amount'];
              r['category'] = basePayload['category'];
              r['date_raw'] = basePayload['expense_date'];
              r['date_label'] = _dateLabel('${basePayload['expense_date']}');
              r['location_id'] = basePayload['location_id'];
              r['note'] = basePayload['note'];
              break;
            }
          }
          await _cacheSetRows(rows);
          if (old != null) await _requeueExpense(old, basePayload);
          U.toast(context, 'Guardado (pendiente de sincronizar)', kind: 'ok');
          // El gasto vive en el mes de su FECHA: muestra ese mes.
          _goToMonthOf(pickedDate);
          return true;
        }
        // Repetición en varios meses (solo gasto nuevo, como el duplicar de
        // la web): un gasto por mes a partir de la fecha elegida.
        final months = e == null ? (repeat.value.clamp(1, 12)) : 1;
        if (months > 1) {
          var okAll = true, sent = 0;
          for (var i = 0; i < months; i++) {
            final payload = Map<String, dynamic>.from(basePayload);
            payload['id'] = 0;
            payload['expense_date'] = _addMonths(pickedDate, i);
            final res = await U.handlePush(
              context,
              SyncService.I.push('ws_expense_save', payload),
              i == months - 1 ? 'Guardado' : '',
              onQueued: _applyQueued,
            );
            if (!res) { okAll = false; break; }
            sent++;
          }
          if (okAll && mounted) {
            U.toast(context, months > sent
                ? 'Guardados $sent de $months gastos; el resto se enviará al reconectar'
                : '$months gastos guardados',
                kind: months > sent ? 'warn' : 'ok');
            await _refreshAll();
            _goToMonthOf(pickedDate);
          }
          return okAll;
        }
        final saved = await U.handlePush(
          context,
          SyncService.I.push('ws_expense_save', basePayload),
          'Guardado',
          onQueued: _applyQueued,
        );
        if (saved && mounted) {
          await _refreshAll();
          // El gasto vive en el mes de su FECHA (igual que la web, donde se
          // agrupa por la fecha): la vista salta a ese mes.
          _goToMonthOf(pickedDate);
        }
        return saved;
      },
    );
    if (ok == true && mounted) { _reload(); setState(() {}); }
  }

  /// 'YYYY-MM-DD' -> 'DD/MM/YYYY' (etiqueta de la web).
  String _dateLabel(String ymd) {
    final parts = ymd.split('-');
    return parts.length == 3 ? '${parts[2]}/${parts[1]}/${parts[0]}' : ymd;
  }

  /// Refleja en la caché una operación de gasto ENCOLADA (sin conexión):
  /// alta con id temporal negativo o edición de una fila existente.
  Future<void> _applyQueued(Map<String, dynamic> q) async {
    final rows = await _cacheRows();
    final id = '${q['id'] ?? 0}';
    final dateRaw = '${q['expense_date'] ?? ''}';
    if (id == '0') {
      rows.add({
        'id': -DateTime.now().millisecondsSinceEpoch,
        'concept': q['concept'], 'amount': q['amount'],
        'category': q['category'], 'date_raw': dateRaw,
        'date_label': _dateLabel(dateRaw),
        'location_id': q['location_id'], 'note': q['note'],
      });
    } else {
      for (final r in rows) {
        if ('${r['id']}' == id) {
          r['concept'] = q['concept'];
          r['amount'] = q['amount'];
          r['category'] = q['category'];
          r['date_raw'] = dateRaw;
          r['date_label'] = _dateLabel(dateRaw);
          r['location_id'] = q['location_id'];
          r['note'] = q['note'];
          break;
        }
      }
    }
    await _cacheSetRows(rows);
  }

  /// Localiza en la cola offline la operación de ALTA que corresponde a un
  /// gasto local aún no sincronizado y la sustituye por [newPayload]
  /// (o la retira si newPayload es null → eliminación).
  Future<void> _requeueExpense(
      Map<String, dynamic> oldRow, Map<String, dynamic>? newPayload) async {
    final ops = await DbService.I.pending();
    for (final op in ops) {
      if ('${op['action']}' != 'ws_expense_save') continue;
      final data = op['data'] is Map
          ? Map<String, dynamic>.from(op['data'] as Map)
          : <String, dynamic>{};
      final sameId = '${data['id'] ?? '0'}' != '0';
      final sameKey = '${data['concept'] ?? ''}' == '${oldRow['concept'] ?? ''}' &&
          '${data['expense_date'] ?? ''}' == '${oldRow['date_raw'] ?? ''}' &&
          '${data['amount']}' == '${oldRow['amount']}';
      if (sameId || !sameKey) continue;
      await DbService.I.removePending(op['id']);
      if (newPayload != null) {
        await DbService.I.enqueue('ws_expense_save', newPayload);
      }
      return;
    }
  }

  /// Duplicar (icono copy de la web): repite el gasto en 1/3/6/12 meses a
  /// partir del mes siguiente y abre el formulario precargado.
  Future<void> _duplicate(Map<String, dynamic> e) async {
    final repeat = ValueNotifier<int>(1);
    await _askRepeatMonths(repeat);
    if (!mounted) return;
    final startDate = _addMonths('${e['date_raw'] ?? ''}'.isEmpty
        ? '$_year-${_month.toString().padLeft(2, '0')}-01'
        : '${e['date_raw']}',
        1);
    await _edit(null, preset: {
      'concept': e['concept'],
      'amount': e['amount'],
      'category': e['category'],
      'note': e['note'],
      'location_id': e['location_id'] ?? 0,
      'date_raw': startDate,
    }, presetRepeat: repeat.value);
  }

  Future<void> _delete(Map<String, dynamic> e) async {
    if (!await U.confirm(context, '¿Eliminar este gasto?', action: 'Eliminar')) return;
    if (!mounted) return;
    final rowId = num.tryParse('${e['id']}') ?? 0;
    if (rowId < 0) {
      // Gasto pendiente de crear en la nube: se quita solo local y se
      // descarta su operación encolada para que no se cree al reconectar.
      final rows = await _cacheRows();
      Map<String, dynamic>? old;
      for (final r in rows) {
        if ('${r['id']}' == '$rowId') { old = Map<String, dynamic>.from(r); break; }
      }
      rows.removeWhere((r) => '${r['id']}' == '$rowId');
      await _cacheSetRows(rows);
      if (old != null) await _requeueExpense(old, null);
      U.toast(context, 'Eliminado (local)');
      _reload(); setState(() {});
      return;
    }
    await U.handlePush(
      context,
      SyncService.I.push('ws_expense_delete', {'id': e['id']}),
      'Eliminado',
      onOk: _refreshAll,
      onQueued: (qp) async {
        final rows = await _cacheRows();
        rows.removeWhere((r) => '${r['id']}' == '${e['id']}');
        await _cacheSetRows(rows);
      },
    );
    if (!mounted) return;
    _reload(); setState(() {});
  }

  void _view(Map<String, dynamic> e) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final cur = AuthService.I.currency;
    final amount = num.tryParse('${e['amount']}') ?? 0;
    final rowId = num.tryParse('${e['id']}') ?? 0;
    final canManage = AuthService.I.has('expenses_manage');
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Row(children: [
          const Icon(Icons.receipt_long_outlined, color: AppTheme.danger),
          const SizedBox(width: 8),
          const Expanded(child: Text('Detalle del gasto')),
        ]),
        content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          _detailRow('Concepto', '${e['concept'] ?? ''}'),
          _detailRow('Monto', U.money(amount, cur)),
          _detailRow('Categoría', '${e['category'] ?? ''}'),
          _detailRow('Fecha', '${e['date_label'] ?? e['date_raw'] ?? ''}'),
          _detailRow('Ubicación', _locText(e)),
          _detailRow('Nota', '${e['note'] ?? ''}'),
          if (rowId < 0) const SizedBox(height: 6),
          if (rowId < 0) Text('Pendiente por sincronizar en la nube.',
              style: TextStyle(fontSize: 11, color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted)),
        ]),
        actions: [
          if (canManage)
            TextButton(
              onPressed: () { Navigator.pop(ctx); _delete(e); },
              child: const Text('Eliminar', style: TextStyle(color: AppTheme.danger)),
            ),
          if (canManage)
            FilledButton.tonal(
              onPressed: () { Navigator.pop(ctx); _edit(e); },
              child: const Text('Editar'),
            ),
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cerrar')),
        ],
      ),
    );
  }

  Widget _detailRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SizedBox(
          width: 90,
          child: Text(label, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: Colors.grey[600])),
        ),
        Expanded(child: Text(value, style: const TextStyle(fontSize: 13))),
      ]),
    );
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
        // Summary stats (SOLO del mes visto, como la web)
        FutureBuilder<List<Map<String, dynamic>>>(
          future: _future,
          builder: (context, snap) {
            final rows = _monthRows(snap.data ?? const []);
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
                Text('Gastos de ${_months[_month - 1]} · ${rows.length} gasto${rows.length == 1 ? '' : 's'}',
                    style: TextStyle(color: Colors.grey[600], fontSize: 12)),
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
              final rows = _monthRows(snap.data ?? const []);
              if (rows.isEmpty) {
                return RefreshIndicator(
                  onRefresh: () async { await _pullMonth(); _reload(); },
                  child: ListView(children: [
                    SizedBox(height: MediaQuery.of(context).size.height * 0.25),
                    Center(child: Text('Sin gastos este mes.',
                        style: TextStyle(color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted))),
                  ]),
                );
              }
              return RefreshIndicator(
                onRefresh: () async { await _pullMonth(); _reload(); },
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
                            '${e['category'] ?? ''} · ${_locText(e)} · ${e['date_label'] ?? e['date_raw'] ?? ''}',
                            style: TextStyle(color: Colors.grey[600], fontSize: 12)),
                        onTap: () => _view(e),
                        onLongPress: canManage ? () => _edit(e) : null,
                        trailing: canManage
                            ? Row(mainAxisSize: MainAxisSize.min, children: [
                                Text(U.money(amount, cur),
                                    style: const TextStyle(fontWeight: FontWeight.w800, color: AppTheme.danger)),
                                // Menú ⋮: Duplicar / Editar / Eliminar siempre
                                // visibles y sin apretar la fila (igual que la web).
                                PopupMenuButton<String>(
                                  icon: const Icon(Icons.more_vert, size: 20),
                                  padding: EdgeInsets.zero,
                                  onSelected: (v) {
                                    if (v == 'edit') _edit(e);
                                    if (v == 'delete') _delete(e);
                                    if (v == 'duplicate') _duplicate(e);
                                  },
                                  itemBuilder: (_) => [
                                    const PopupMenuItem(
                                        value: 'duplicate',
                                        height: 42,
                                        child: Row(children: [
                                          Icon(Icons.copy_all_outlined, size: 18),
                                          SizedBox(width: 10),
                                          Text('Duplicar en meses…'),
                                        ])),
                                    const PopupMenuItem(
                                        value: 'edit',
                                        height: 42,
                                        child: Row(children: [
                                          Icon(Icons.edit_outlined, size: 18),
                                          SizedBox(width: 10),
                                          Text('Editar'),
                                        ])),
                                    const PopupMenuItem(
                                        value: 'delete',
                                        height: 42,
                                        child: Row(children: [
                                          Icon(Icons.delete_outline,
                                              size: 18, color: AppTheme.danger),
                                          SizedBox(width: 10),
                                          Text('Eliminar',
                                              style: TextStyle(color: AppTheme.danger)),
                                        ])),
                                  ],
                                ),
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
