import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';
import '../widgets/crud.dart';

/// Turnos con calendario (semana/mes), crear/editar/eliminar,
/// filtro por ubicación y asignación de calendario semanal.
class ShiftsScreen extends StatefulWidget {
  const ShiftsScreen({super.key});
  @override
  State<ShiftsScreen> createState() => _ShiftsScreenState();
}

class _ShiftsScreenState extends State<ShiftsScreen> {
  List<Map<String, dynamic>> _shifts = [];
  List<Map<String, dynamic>> _workers = [];
  List<Map<String, dynamic>> _locations = [];
  String _view = 'week'; // week | month
  DateTime _weekStart = DateTime.now();
  DateTime _monthStart = DateTime(DateTime.now().year, DateTime.now().month);
  String _selectedDay = '';
  String _locFilter = '';
  bool _isManager = false;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _weekStart = _monday(now);
    _selectedDay = _iso(now);
    _isManager = AuthService.I.has('shifts_manage');
    _loadAll();
    SyncService.I.onChange(_onSync);
  }

  void _onSync() {
    if (mounted) _loadAll();
  }

  DateTime _monday(DateTime d) {
    final x = DateTime(d.year, d.month, d.day);
    return x.subtract(Duration(days: (x.weekday - 1) % 7));
  }

  String _iso(DateTime d) =>
      '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  String _fmtMonth(DateTime d) {
    const months = [
      '', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
      'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
    ];
    return '${months[d.month]} ${d.year}';
  }

  String _fmtDay(DateTime d) {
    const days = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    const months = [
      '', 'ene', 'feb', 'mar', 'abr', 'may', 'jun',
      'jul', 'ago', 'sep', 'oct', 'nov', 'dic'
    ];
    return '${days[d.weekday - 1]} ${d.day} ${months[d.month]}';
  }

  List<Map<String, dynamic>> get _visibleShifts {
    var list = _shifts;
    if (!_isManager) {
      final uid = AuthService.I.userId;
      list = list.where((s) => '${s['user_id']}' == '$uid').toList();
    }
    if (_locFilter.isNotEmpty) {
      list = list.where((s) => '${s['location_id']}' == _locFilter).toList();
    }
    return list;
  }

  Map<String, List<Map<String, dynamic>>> get _shiftsByDate {
    final map = <String, List<Map<String, dynamic>>>{};
    for (final s in _visibleShifts) {
      final d = '${s['shift_date'] ?? ''}'.substring(0, 10);
      map.putIfAbsent(d, () => []).add(s);
    }
    return map;
  }

  Future<void> _loadAll() async {
    // Load workers from DB first (fast)
    var workersFromDb = await DbService.I.all('workers');
    if (workersFromDb.isNotEmpty) _workers = workersFromDb;
    // Also try cache
    final workersCache = await DbService.I.cacheGet('ws_workers_list');
    if (workersCache is List && workersCache.isNotEmpty) {
      _workers = workersCache.whereType<Map>().toList().cast<Map<String, dynamic>>();
    }
    // Load shifts from DB
    final cached = await DbService.I.all('shifts');
    if (cached.isNotEmpty) _shifts = cached;
    final locs = await DbService.I.all('locations');
    _locations = locs;
    setState(() => _loading = false);
    _refreshFromServer();
  }

  Future<void> _refreshFromServer() async {
    if (SyncService.I.isPulling) return;
    try {
      final r = _range();
      final d = await ApiService.I.req('ws_shifts_list', {
        'start': r['from'],
        'end': r['to'],
      });
      final rows =
          List<Map<String, dynamic>>.from((d['shifts'] as List?) ?? []);
      _shifts = rows;
      await DbService.I.cacheSet('ws_shifts_list', rows);
      await DbService.I.replaceAll('shifts', rows);
      // Workers from server
      if (_isManager) {
        try {
          final wd = await ApiService.I.req(
              'ws_workers_list', {'pageSize': 200, 'page': 1});
          _workers = List<Map<String, dynamic>>.from(
              (wd['workers'] as List?) ?? []);
          await DbService.I.cacheSet('ws_workers_list', _workers);
          await DbService.I.replaceAll('workers', _workers);
        } catch (_) {}
      }
      // Locations from server
      try {
        final locData = await ApiService.I.req('ws_cache_locations', {});
        final serverLocs = List<Map<String, dynamic>>.from(
            ((locData as Map)['data'] as List?) ?? []);
        if (serverLocs.isNotEmpty) {
          _locations = serverLocs;
          await DbService.I.replaceAll('locations', _locations);
        }
      } catch (_) {}
      if (mounted) setState(() {});
    } catch (_) {}
  }

  Map<String, String> _range() {
    if (_view == 'month') {
      final lastDay =
          DateTime(_monthStart.year, _monthStart.month + 1, 0);
      return {
        'from': '${_iso(_monthStart)} 00:00:00',
        'to': '${_iso(lastDay)} 23:59:59'
      };
    }
    final weekEnd = _weekStart.add(const Duration(days: 6));
    return {
      'from': '${_iso(_weekStart)} 00:00:00',
      'to': '${_iso(weekEnd)} 23:59:59'
    };
  }

  /// Assign a weekly schedule to a worker across multiple days and locations.
  void _openScheduleAssignment() {
    if (_workers.isEmpty) {
      U.toast(context, 'No hay trabajadores disponibles', kind: 'err');
      return;
    }
    if (_locations.isEmpty) {
      U.toast(context, 'No hay ubicaciones disponibles', kind: 'err');
      return;
    }

    final workerCtrl = ValueNotifier<String>('${_workers.first['id']}');
    final locCtrl = ValueNotifier<String>(
        _locations.isNotEmpty ? '${_locations.first['id']}' : '');
    final startTime = TextEditingController(text: '08:00');
    final endTime = TextEditingController(text: '16:00');
    final noteCtrl = TextEditingController();

    // Track which days of the week are selected (Mon=0 ... Sun=6)
    final selectedDays = ValueNotifier<Set<int>>({0, 1, 2, 3, 4}); // Mon-Fri

    // Get worker's locations (filtered list)
    List<Map<String, dynamic>> _workerLocs(String workerId) {
      final w = _workers.firstWhere(
          (x) => '${x['id']}' == workerId,
          orElse: () => {});
      if (w.isNotEmpty && w['locations'] is List && (w['locations'] as List).isNotEmpty) {
        final wLocIds = (w['locations'] as List)
            .map((l) => '${(l as Map)['id']}')
            .toSet();
        return _locations
            .where((l) => wLocIds.contains('${l['id']}'))
            .toList();
      }
      return _locations;
    }

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) {
          final locs = _workerLocs(workerCtrl.value);
          // Ensure locCtrl value is valid
          if (!locs.any((l) => '${l['id']}' == locCtrl.value) &&
              locs.isNotEmpty) {
            locCtrl.value = '${locs.first['id']}';
          }
          return Padding(
            padding: EdgeInsets.only(
                left: 18,
                right: 18,
                top: 16,
                bottom: MediaQuery.of(ctx).viewInsets.bottom + 18),
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text('Asignar calendario semanal',
                      style: Theme.of(ctx)
                          .textTheme
                          .titleMedium
                          ?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 4),
                  Text('Crea turnos recurrentes para un trabajador.',
                      style: TextStyle(color: Colors.grey[600], fontSize: 12)),
                  const SizedBox(height: 14),
                  // Worker
                  ValueListenableBuilder<String>(
                    valueListenable: workerCtrl,
                    builder: (_, v, __) => DropdownButtonFormField<String>(
                      value: v.isNotEmpty ? v : null,
                      decoration: const InputDecoration(labelText: 'Trabajador *'),
                      items: _workers
                          .map((w) => DropdownMenuItem(
                              value: '${w['id']}',
                              child: Text(
                                  '${w['display_name'] ?? w['name'] ?? ''}')))
                          .toList(),
                      onChanged: (val) {
                        workerCtrl.value = val ?? '';
                        setSheet(() {});
                      },
                    ),
                  ),
                  // Location
                  ValueListenableBuilder<String>(
                    valueListenable: locCtrl,
                    builder: (_, v, __) {
                      final safeVal = locs.any((l) => '${l['id']}' == v)
                          ? v
                          : (locs.isNotEmpty ? '${locs.first['id']}' : null);
                      return DropdownButtonFormField<String>(
                        value: safeVal,
                        decoration:
                            const InputDecoration(labelText: 'Ubicación *'),
                        items: locs
                            .map((l) => DropdownMenuItem(
                                value: '${l['id']}',
                                child: Text('${l['name'] ?? ''}')))
                            .toList(),
                        onChanged: (val) => locCtrl.value = val ?? '',
                      );
                    },
                  ),
                  // Times
                  Row(children: [
                    Expanded(
                        child: fField('Hora inicio *', startTime,
                            hint: '08:00')),
                    const SizedBox(width: 8),
                    Expanded(
                        child: fField('Hora fin *', endTime, hint: '16:00')),
                  ]),
                  const SizedBox(height: 8),
                  // Day selector
                  ValueListenableBuilder<Set<int>>(
                    valueListenable: selectedDays,
                    builder: (_, days, __) {
                      const dayNames = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
                      return Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: List.generate(7, (i) {
                              final selected = days.contains(i);
                              return Expanded(
                                child: GestureDetector(
                                  onTap: () {
                                    final newSet = Set<int>.from(days);
                                    if (newSet.contains(i)) {
                                      newSet.remove(i);
                                    } else {
                                      newSet.add(i);
                                    }
                                    selectedDays.value = newSet;
                                  },
                                  child: Container(
                                    margin: const EdgeInsets.symmetric(horizontal: 2),
                                    padding: const EdgeInsets.symmetric(vertical: 8),
                                    decoration: BoxDecoration(
                                      color: selected
                                          ? AppTheme.primary
                                          : AppTheme.primary.withAlpha(15),
                                      borderRadius: BorderRadius.circular(8),
                                    ),
                                    child: Center(
                                      child: Text(dayNames[i],
                                          style: TextStyle(
                                              fontSize: 11,
                                              fontWeight: FontWeight.w600,
                                              color: selected
                                                  ? Colors.white
                                                  : AppTheme.primary)),
                                    ),
                                  ),
                                ),
                              );
                            }),
                          ),
                          const SizedBox(height: 4),
                          Row(children: [
                            TextButton(
                              onPressed: () => selectedDays.value = {0, 1, 2, 3, 4},
                              child: const Text('L-V', style: TextStyle(fontSize: 11)),
                            ),
                            TextButton(
                              onPressed: () => selectedDays.value = {0, 1, 2, 3, 4, 5, 6},
                              child: const Text('Todos', style: TextStyle(fontSize: 11)),
                            ),
                            TextButton(
                              onPressed: () => selectedDays.value = {},
                              child: const Text('Ninguno', style: TextStyle(fontSize: 11)),
                            ),
                          ]),
                        ],
                      );
                    },
                  ),
                  fField('Nota', noteCtrl, hint: 'Opcional'),
                  const SizedBox(height: 12),
                  Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                    TextButton(
                        onPressed: () => Navigator.pop(ctx),
                        child: const Text('Cancelar')),
                    const SizedBox(width: 8),
                    FilledButton(
                      onPressed: () async {
                        final days = selectedDays.value;
                        if (days.isEmpty) {
                          U.toast(ctx, 'Selecciona al menos un día', kind: 'err');
                          return;
                        }
                        if (workerCtrl.value.isEmpty || locCtrl.value.isEmpty) {
                          U.toast(ctx, 'Completa los campos', kind: 'err');
                          return;
                        }
                        Navigator.pop(ctx);

                        // Find the start of the current week (Monday)
                        final now = DateTime.now();
                        final monday = _monday(now);

                        // Create a shift for each selected day
                        var created = 0;
                        for (final dayIdx in days) {
                          final date = monday.add(Duration(days: dayIdx));
                          final dateStr = _iso(date);
                          final payload = <String, dynamic>{
                            'id': 0,
                            'user_id': workerCtrl.value,
                            'location_id': locCtrl.value,
                            'shift_date': dateStr,
                            'time_start': startTime.text.trim(),
                            'time_end': endTime.text.trim(),
                            'note': noteCtrl.text.trim(),
                          };
                          try {
                            final res = await SyncService.I.push('ws_save_shift', payload);
                            final ok = res is Map && res['queued'] != true;
                            if (ok) created++;
                          } catch (_) {}
                        }
                        if (mounted) {
                          U.toast(context, '$created turno${created == 1 ? '' : 's'} creado${created == 1 ? '' : 's'}', kind: 'ok');
                          if (created > 0 && SyncService.I.isOnline) {
                            await SyncService.I.pullStore('ws_shifts_list', {'start': '${now.year}-${now.month.toString().padLeft(2, '0')}-01 00:00:00', 'end': '${now.year}-12-31 23:59:59'}, 'shifts', cacheKey: 'ws_shifts_list', dataKey: 'shifts');
                          }
                          _loadAll();
                        }
                      },
                      child: const Text('Crear turnos'),
                    ),
                  ]),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  /// Assign a full month of shifts to a worker across selected weekdays.
  void _openMonthAssignment() {
    if (_workers.isEmpty) {
      U.toast(context, 'No hay trabajadores disponibles', kind: 'err');
      return;
    }
    if (_locations.isEmpty) {
      U.toast(context, 'No hay ubicaciones disponibles', kind: 'err');
      return;
    }

    final workerCtrl = ValueNotifier<String>('${_workers.first['id']}');
    final locCtrl = ValueNotifier<String>(
        _locations.isNotEmpty ? '${_locations.first['id']}' : '');
    final monthCtrl = ValueNotifier<DateTime>(
        DateTime(DateTime.now().year, DateTime.now().month));
    final startTime = TextEditingController(text: '08:00');
    final endTime = TextEditingController(text: '16:00');
    final noteCtrl = TextEditingController();
    final selectedDays = ValueNotifier<Set<int>>({0, 1, 2, 3, 4}); // Mon-Fri

    List<Map<String, dynamic>> _workerLocs(String workerId) {
      final w = _workers.firstWhere(
          (x) => '${x['id']}' == workerId,
          orElse: () => {});
      if (w.isNotEmpty && w['locations'] is List && (w['locations'] as List).isNotEmpty) {
        final wLocIds = (w['locations'] as List)
            .map((l) => '${(l as Map)['id']}')
            .toSet();
        return _locations
            .where((l) => wLocIds.contains('${l['id']}'))
            .toList();
      }
      return _locations;
    }

    // All dates in the current month that fall on a selected weekday.
    List<String> _monthDates(DateTime month, Set<int> days) {
      final lastDay = DateTime(month.year, month.month + 1, 0).day;
      final out = <String>[];
      for (var d = 1; d <= lastDay; d++) {
        final date = DateTime(month.year, month.month, d);
        if (days.contains(date.weekday - 1)) out.add(_iso(date));
      }
      return out;
    }

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) {
          final locs = _workerLocs(workerCtrl.value);
          if (!locs.any((l) => '${l['id']}' == locCtrl.value) &&
              locs.isNotEmpty) {
            locCtrl.value = '${locs.first['id']}';
          }
          return Padding(
            padding: EdgeInsets.only(
                left: 18,
                right: 18,
                top: 16,
                bottom: MediaQuery.of(ctx).viewInsets.bottom + 18),
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text('Asignar mes completo',
                      style: Theme.of(ctx)
                          .textTheme
                          .titleMedium
                          ?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 4),
                  Text('Crea los turnos de un mes para un trabajador.',
                      style: TextStyle(color: Colors.grey[600], fontSize: 12)),
                  const SizedBox(height: 14),
                  // Worker
                  ValueListenableBuilder<String>(
                    valueListenable: workerCtrl,
                    builder: (_, v, __) => DropdownButtonFormField<String>(
                      value: v.isNotEmpty ? v : null,
                      decoration: const InputDecoration(labelText: 'Trabajador *'),
                      items: _workers
                          .map((w) => DropdownMenuItem(
                              value: '${w['id']}',
                              child: Text(
                                  '${w['display_name'] ?? w['name'] ?? ''}')))
                          .toList(),
                      onChanged: (val) {
                        workerCtrl.value = val ?? '';
                        setSheet(() {});
                      },
                    ),
                  ),
                  // Location
                  ValueListenableBuilder<String>(
                    valueListenable: locCtrl,
                    builder: (_, v, __) {
                      final safeVal = locs.any((l) => '${l['id']}' == v)
                          ? v
                          : (locs.isNotEmpty ? '${locs.first['id']}' : null);
                      return DropdownButtonFormField<String>(
                        value: safeVal,
                        decoration:
                            const InputDecoration(labelText: 'Ubicación *'),
                        items: locs
                            .map((l) => DropdownMenuItem(
                                value: '${l['id']}',
                                child: Text('${l['name'] ?? ''}')))
                            .toList(),
                        onChanged: (val) => locCtrl.value = val ?? '',
                      );
                    },
                  ),
                  // Month picker
                  ValueListenableBuilder<DateTime>(
                    valueListenable: monthCtrl,
                    builder: (_, month, __) => Row(
                      children: [
                        IconButton(
                          icon: const Icon(Icons.chevron_left),
                          onPressed: () => setSheet(() => monthCtrl.value =
                              DateTime(month.year, month.month - 1)),
                        ),
                        Expanded(
                          child: Text(
                            _fmtMonth(month),
                            textAlign: TextAlign.center,
                            style: const TextStyle(
                                fontWeight: FontWeight.w700, fontSize: 14),
                          ),
                        ),
                        IconButton(
                          icon: const Icon(Icons.chevron_right),
                          onPressed: () => setSheet(() => monthCtrl.value =
                              DateTime(month.year, month.month + 1)),
                        ),
                      ],
                    ),
                  ),
                  // Time
                  Row(children: [
                    Expanded(
                        child: fField('Hora inicio *', startTime,
                            hint: '08:00')),
                    const SizedBox(width: 8),
                    Expanded(
                        child: fField('Hora fin *', endTime, hint: '16:00')),
                  ]),
                  const SizedBox(height: 8),
                  // Weekday selector
                  ValueListenableBuilder<Set<int>>(
                    valueListenable: selectedDays,
                    builder: (_, days, __) {
                      const dayNames = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
                      return Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: List.generate(7, (i) {
                              final selected = days.contains(i);
                              return Expanded(
                                child: GestureDetector(
                                  onTap: () {
                                    final newSet = Set<int>.from(days);
                                    if (newSet.contains(i)) {
                                      newSet.remove(i);
                                    } else {
                                      newSet.add(i);
                                    }
                                    selectedDays.value = newSet;
                                  },
                                  child: Container(
                                    margin: const EdgeInsets.symmetric(horizontal: 2),
                                    padding: const EdgeInsets.symmetric(vertical: 8),
                                    decoration: BoxDecoration(
                                      color: selected
                                          ? AppTheme.primary
                                          : AppTheme.primary.withAlpha(15),
                                      borderRadius: BorderRadius.circular(8),
                                    ),
                                    child: Center(
                                      child: Text(dayNames[i],
                                          style: TextStyle(
                                              fontSize: 11,
                                              fontWeight: FontWeight.w600,
                                              color: selected
                                                  ? Colors.white
                                                  : AppTheme.primary)),
                                    ),
                                  ),
                                ),
                              );
                            }),
                          ),
                          const SizedBox(height: 4),
                          Row(children: [
                            TextButton(
                              onPressed: () => selectedDays.value = {0, 1, 2, 3, 4},
                              child: const Text('L-V', style: TextStyle(fontSize: 11)),
                            ),
                            TextButton(
                              onPressed: () => selectedDays.value = {0, 1, 2, 3, 4, 5, 6},
                              child: const Text('Todos', style: TextStyle(fontSize: 11)),
                            ),
                            TextButton(
                              onPressed: () => selectedDays.value = {},
                              child: const Text('Ninguno', style: TextStyle(fontSize: 11)),
                            ),
                            const Spacer(),
                            ValueListenableBuilder<Set<int>>(
                              valueListenable: selectedDays,
                              builder: (_, d, __) => Text(
                                '${_monthDates(monthCtrl.value, d).length} días',
                                style: TextStyle(
                                    fontSize: 11,
                                    color: Colors.grey[500],
                                    fontWeight: FontWeight.w600),
                              ),
                            ),
                          ]),
                        ],
                      );
                    },
                  ),
                  fField('Nota', noteCtrl, hint: 'Opcional'),
                  const SizedBox(height: 12),
                  Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                    TextButton(
                        onPressed: () => Navigator.pop(ctx),
                        child: const Text('Cancelar')),
                    const SizedBox(width: 8),
                    FilledButton(
                      onPressed: () async {
                        final days = selectedDays.value;
                        if (days.isEmpty) {
                          U.toast(ctx, 'Selecciona al menos un día', kind: 'err');
                          return;
                        }
                        if (workerCtrl.value.isEmpty || locCtrl.value.isEmpty) {
                          U.toast(ctx, 'Completa los campos', kind: 'err');
                          return;
                        }
                        final dates = _monthDates(monthCtrl.value, days);
                        final payload = <String, dynamic>{
                          'id': 0,
                          'user_id': workerCtrl.value,
                          'location_id': locCtrl.value,
                          'dates': dates,
                          'time_start': startTime.text.trim(),
                          'time_end': endTime.text.trim(),
                          'note': noteCtrl.text.trim(),
                        };
                        Navigator.pop(ctx);
                        U.handlePush(
                          context,
                          SyncService.I.push('ws_save_shift', payload),
                          '${dates.length} turno${dates.length == 1 ? '' : 's'} creado${dates.length == 1 ? '' : 's'}',
onOk: () => SyncService.I.pullStore('ws_shifts_list', {'start': '${DateTime.now().year}-${DateTime.now().month.toString().padLeft(2, '0')}-01 00:00:00', 'end': '${DateTime.now().year}-12-31 23:59:59'}, 'shifts', cacheKey: 'ws_shifts_list', dataKey: 'shifts'),
                          onQueued: (qp) async {
                            final rows = await DbService.I.all('shifts');
                            final baseId = -DateTime.now().millisecondsSinceEpoch;
                            for (var i = 0; i < dates.length; i++) {
                              rows.add({
                                'id': baseId - i,
                                'user_id': payload['user_id'],
                                'location_id': payload['location_id'],
                                'shift_date': dates[i],
                                'time_start': payload['time_start'],
                                'time_end': payload['time_end'],
                                'note': payload['note'],
                              });
                            }
                            await DbService.I.replaceAll('shifts', rows);
                          },
                        );
                      },
                      child: const Text('Crear turnos'),
                    ),
                  ]),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final byDate = _shiftsByDate;

    return Scaffold(
      backgroundColor: Colors.transparent,
      floatingActionButton: _isManager
          ? FloatingActionButton(
              heroTag: 'monthShift',
              tooltip: 'Asignar mes completo',
              onPressed: _openMonthAssignment,
              child: const Icon(Icons.calendar_month),
            )
          : null,
      body: Column(
        children: [
          // Navigation bar
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
            child: Row(
              children: [
                IconButton(
                  icon: const Icon(Icons.chevron_left, size: 28),
                  onPressed: () => setState(() {
                    if (_view == 'month') {
                      _monthStart = DateTime(
                          _monthStart.year, _monthStart.month - 1);
                    } else {
                      _weekStart =
                          _weekStart.subtract(const Duration(days: 7));
                    }
                  }),
                ),
                Expanded(
                  child: Text(
                    _view == 'month'
                        ? _fmtMonth(_monthStart)
                        : '${_fmtDay(_weekStart)} – ${_fmtDay(_weekStart.add(const Duration(days: 6)))}',
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        fontWeight: FontWeight.w700, fontSize: 14),
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.chevron_right, size: 28),
                  onPressed: () => setState(() {
                    if (_view == 'month') {
                      _monthStart = DateTime(
                          _monthStart.year, _monthStart.month + 1);
                    } else {
                      _weekStart =
                          _weekStart.add(const Duration(days: 7));
                    }
                  }),
                ),
              ],
            ),
          ),
          // View toggle + location filter
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 4, 14, 0),
            child: Row(
              children: [
                _segBtn('Semana', 'week'),
                const SizedBox(width: 6),
                _segBtn('Mes', 'month'),
                const SizedBox(width: 8),
                // Location filter
                if (_isManager && _locations.isNotEmpty)
                  Expanded(
                    child: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8),
                      decoration: BoxDecoration(
                        color: Theme.of(context).brightness == Brightness.dark
                            ? AppTheme.darkSurface
                            : Colors.white,
                        borderRadius: BorderRadius.circular(10),
                        border: Border.all(
                            color: Theme.of(context).brightness == Brightness.dark
                                ? Colors.white.withAlpha(20)
                                : Colors.black.withAlpha(20)),
                      ),
                      child: DropdownButtonHideUnderline(
                        child: DropdownButton<String>(
                          value: _locFilter.isEmpty ? null : _locFilter,
                          isDense: true,
                          isExpanded: true,
                          hint: Text('Todas',
                              style: TextStyle(
                                  fontSize: 11,
                                  color: Theme.of(context).brightness == Brightness.dark
                                      ? AppTheme.darkMuted
                                      : AppTheme.lightMuted)),
                          items: _locations
                              .map((l) => DropdownMenuItem(
                                  value: '${l['id']}',
                                  child: Text('${l['name'] ?? ''}',
                                      style: const TextStyle(fontSize: 11))))
                              .toList(),
                          onChanged: (v) => setState(() => _locFilter = v ?? ''),
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
          if (!_isManager)
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 6, 14, 0),
              child: Text('Tu calendario de trabajo',
                  style: TextStyle(
                      fontSize: 13,
                      color: Colors.grey[500],
                      fontStyle: FontStyle.italic)),
            ),
          // Content
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _view == 'month'
                    ? _buildMonthCalendar(byDate)
                    : _buildWeekList(byDate),
          ),
        ],
      ),
    );
  }

  Widget _segBtn(String label, String value) {
    final active = _view == value;
    return Expanded(
      child: GestureDetector(
        onTap: () => setState(() => _view = value),
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 8),
          decoration: BoxDecoration(
            color: active ? AppTheme.primary : Colors.transparent,
            borderRadius: BorderRadius.circular(10),
            border: Border.all(
                color: active
                    ? AppTheme.primary
                    : AppTheme.primary.withAlpha(80)),
          ),
          alignment: Alignment.center,
          child: Text(label,
              style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: active ? Colors.white : AppTheme.primary)),
        ),
      ),
    );
  }

  // ---- Week list view ----
  Widget _buildWeekList(Map<String, List<Map<String, dynamic>>> byDate) {
    final days = <DateTime>[];
    for (var i = 0; i < 7; i++) {
      days.add(_weekStart.add(Duration(days: i)));
    }
    final today = _iso(DateTime.now());
    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
      itemCount: days.length,
      itemBuilder: (ctx, i) {
        final d = days[i];
        final ds = _iso(d);
        final dayShifts = byDate[ds] ?? [];
        final isToday = ds == today;
        return Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 6),
              child: Row(
                children: [
                  Text(
                    '${['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'][d.weekday - 1]} ${d.day}',
                    style: TextStyle(
                        fontWeight: FontWeight.w700,
                        fontSize: 14,
                        color: isToday ? AppTheme.primary : null),
                  ),
                  if (isToday)
                    Container(
                      margin: const EdgeInsets.only(left: 6),
                      padding: const EdgeInsets.symmetric(
                          horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(
                        color: AppTheme.primary.withAlpha(20),
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: const Text('Hoy',
                          style: TextStyle(
                              fontSize: 10,
                              color: AppTheme.primary,
                              fontWeight: FontWeight.w700)),
                    ),
                ],
              ),
            ),
            if (dayShifts.isEmpty)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text('Sin turnos.',
                    style: TextStyle(
                        fontSize: 12, color: Colors.grey[400])),
              )
            else
              ...dayShifts.map((s) => _shiftTile(s)),
          ],
        );
      },
    );
  }

  // ---- Month calendar view ----
  Widget _buildMonthCalendar(
      Map<String, List<Map<String, dynamic>>> byDate) {
    final first =
        DateTime(_monthStart.year, _monthStart.month, 1);
    final offset = (first.weekday - 1) % 7;
    final gridStart = first.subtract(Duration(days: offset));
    final today = _iso(DateTime.now());

    return Column(
      children: [
        // Day headers
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 10),
          child: Row(
            children: ['L', 'M', 'X', 'J', 'V', 'S', 'D']
                .map((h) => Expanded(
                      child: Center(
                          child: Text(h,
                              style: TextStyle(
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                  color: Colors.grey[500]))),
                    ))
                .toList(),
          ),
        ),
        // Calendar grid
        SizedBox(
          height: 260,
          child: GridView.builder(
            padding: const EdgeInsets.symmetric(horizontal: 10),
            gridDelegate:
                const SliverGridDelegateWithFixedCrossAxisCount(
              crossAxisCount: 7,
              childAspectRatio: 1,
            ),
            itemCount: 42,
            itemBuilder: (ctx, i) {
              final d = gridStart.add(Duration(days: i));
              final ds = _iso(d);
              final inMonth =
                  d.month == _monthStart.month;
              final dayShifts = byDate[ds] ?? [];
              final isToday = ds == today;
              final isSelected = ds == _selectedDay;
              return GestureDetector(
                onTap: () => setState(() => _selectedDay = ds),
                child: Container(
                  margin: const EdgeInsets.all(2),
                  decoration: BoxDecoration(
                    color: isSelected
                        ? AppTheme.primary.withAlpha(30)
                        : isToday
                            ? AppTheme.primary.withAlpha(10)
                            : null,
                    borderRadius: BorderRadius.circular(8),
                    border: isSelected
                        ? Border.all(color: AppTheme.primary)
                        : null,
                  ),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      Text('${d.day}',
                          style: TextStyle(
                              fontSize: 13,
                              fontWeight: isToday
                                  ? FontWeight.w800
                                  : FontWeight.w500,
                              color: inMonth
                                  ? (isToday
                                      ? AppTheme.primary
                                      : null)
                                  : Colors.grey[300])),
                      if (dayShifts.isNotEmpty)
                        Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: dayShifts
                              .take(3)
                              .map((_) => Container(
                                    width: 4,
                                    height: 4,
                                    margin:
                                        const EdgeInsets.all(1),
                                    decoration: const BoxDecoration(
                                        color: AppTheme.primary,
                                        shape: BoxShape.circle),
                                  ))
                              .toList(),
                        ),
                    ],
                  ),
                ),
              );
            },
          ),
        ),
        // Selected day shifts
        Expanded(
          child: Builder(builder: (ctx) {
            final selShifts = byDate[_selectedDay] ?? [];
            return ListView(
              padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
              children: [
                Text(
                  DateTime.parse('${_selectedDay}T12:00:00')
                      .toString()
                      .substring(0, 10),
                  style: const TextStyle(
                      fontWeight: FontWeight.w700, fontSize: 14),
                ),
                const SizedBox(height: 6),
                if (selShifts.isEmpty)
                  Text('Sin turnos este día.',
                      style: TextStyle(
                          fontSize: 12, color: Colors.grey[400]))
                else
                  ...selShifts.map((s) => _shiftTile(s)),
              ],
            );
          }),
        ),
      ],
    );
  }

  Widget _shiftTile(Map<String, dynamic> s) {
    final locName = _locations
        .where((l) => '${l['id']}' == '${s['location_id']}')
        .map((l) => '${l['name'] ?? ''}')
        .firstOrNull ?? '';
    // Find worker name
    final workerName = _workers
        .where((w) => '${w['id']}' == '${s['user_id']}')
        .map((w) => '${w['display_name'] ?? w['name'] ?? ''}')
        .firstOrNull ?? '';
    return Card(
      margin: const EdgeInsets.only(bottom: 6),
      child: ListTile(
        dense: true,
        leading: Container(
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(
            color: AppTheme.primary.withAlpha(20),
            borderRadius: BorderRadius.circular(10),
          ),
          child: const Icon(Icons.schedule,
              color: AppTheme.primary, size: 20),
        ),
        title: Text(
            '${s['time_start'] ?? ''} – ${s['time_end'] ?? ''}',
            style:
                const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
        subtitle: Text(
          '${workerName.isNotEmpty ? '$workerName · ' : ''}${locName.isNotEmpty ? '$locName' : ''}${'${s['note'] ?? ''}'.isNotEmpty ? ' · ${s['note']}' : ''}',
          style: TextStyle(color: Colors.grey[600], fontSize: 12),
        ),
        trailing: _isManager
            ? Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  IconButton(
                    icon: const Icon(Icons.edit_outlined, size: 20),
                    onPressed: () => _openForm(s),
                  ),
                  IconButton(
                    icon: const Icon(Icons.delete_outline,
                        size: 20, color: AppTheme.danger),
                    onPressed: () => _deleteShift(s),
                  ),
                ],
              )
            : null,
      ),
    );
  }

  Future<void> _deleteShift(Map<String, dynamic> s) async {
    final ok = await U.confirm(context, '¿Eliminar este turno?',
        action: 'Eliminar');
    if (!ok) return;
    final result = await U.handlePush(
      context,
      SyncService.I.push(
          'ws_delete_shift', {'id': s['id']}),
      'Turno eliminado',
      onOk: () => SyncService.I.pullStore('ws_shifts_list', {'start': '${DateTime.now().year}-${DateTime.now().month.toString().padLeft(2, '0')}-01 00:00:00', 'end': '${DateTime.now().year}-12-31 23:59:59'}, 'shifts', cacheKey: 'ws_shifts_list', dataKey: 'shifts'),
      onQueued: (qp) async {
        final rows = await DbService.I.all('shifts');
        rows.removeWhere((r) => '${r['id']}' == '${s['id']}');
        await DbService.I.replaceAll('shifts', rows);
      },
    );
    if (result) {
      _shifts.removeWhere(
          (x) => '${x['id']}' == '${s['id']}');
      await DbService.I.replaceAll('shifts', _shifts);
      setState(() {});
    }
  }

  void _openForm(Map<String, dynamic>? existing) {
    if (_workers.isEmpty) {
      DbService.I.cacheGet('ws_workers_list').then((c) {
        if (c is List) {
          _workers = c.whereType<Map>().toList().cast<Map<String, dynamic>>();
        }
        _showFormDialog(existing);
      });
    } else {
      _showFormDialog(existing);
    }
  }

  void _showFormDialog(Map<String, dynamic>? s) {
    final workerCtrl = ValueNotifier<String>(
        '${s?['user_id'] ?? (_workers.isNotEmpty ? _workers.first['id'] : '')}');
    final locCtrl = ValueNotifier<String>(
        '${s?['location_id'] ?? (_locations.isNotEmpty ? _locations.first['id'] : '')}');
    final dateCtrl = TextEditingController(
        text: s != null
            ? '${s['shift_date'] ?? ''}'.substring(0, 10)
            : _selectedDay);
    final startCtrl =
        TextEditingController(text: s?['time_start'] ?? '09:00');
    final endCtrl =
        TextEditingController(text: s?['time_end'] ?? '17:00');
    final noteCtrl =
        TextEditingController(text: s?['note'] ?? '');

    // Worker locations helper
    List<Map<String, dynamic>> workerLocs(List<Map<String, dynamic>> allLocs) {
      final wid = workerCtrl.value;
      final w = _workers.firstWhere(
          (x) => '${x['id']}' == wid,
          orElse: () => {});
      if (w.isNotEmpty && w['locations'] is List && (w['locations'] as List).isNotEmpty) {
        final wLocIds =
            (w['locations'] as List).map((l) => '${(l as Map)['id']}').toSet();
        return allLocs
            .where((l) => wLocIds.contains('${l['id']}'))
            .toList();
      }
      return allLocs;
    }

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setSheet) {
          final locs = workerLocs(_locations);
          return Padding(
            padding: EdgeInsets.only(
                left: 18,
                right: 18,
                top: 16,
                bottom: MediaQuery.of(ctx).viewInsets.bottom + 18),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text(s != null ? 'Editar turno' : 'Nuevo turno',
                    style: Theme.of(ctx)
                        .textTheme
                        .titleMedium
                        ?.copyWith(fontWeight: FontWeight.w700)),
                const SizedBox(height: 14),
                // Worker
                ValueListenableBuilder<String>(
                  valueListenable: workerCtrl,
                  builder: (_, v, __) => DropdownButtonFormField<String>(
                    value: v.isNotEmpty ? v : null,
                    decoration:
                        const InputDecoration(labelText: 'Trabajador *'),
                    items: _workers
                        .map((w) => DropdownMenuItem(
                            value: '${w['id']}',
                            child: Text('${w['display_name'] ?? w['name'] ?? ''}')))
                        .toList(),
                    onChanged: (val) {
                      workerCtrl.value = val ?? '';
                      setSheet(() {});
                    },
                  ),
                ),
                // Location
                ValueListenableBuilder<String>(
                  valueListenable: locCtrl,
                  builder: (_, v, __) {
                    final safeVal = locs.any((l) => '${l['id']}' == v)
                        ? v
                        : (locs.isNotEmpty ? '${locs.first['id']}' : null);
                    return DropdownButtonFormField<String>(
                      value: safeVal,
                      decoration:
                          const InputDecoration(labelText: 'Ubicación *'),
                      items: locs
                          .map((l) => DropdownMenuItem(
                              value: '${l['id']}',
                              child: Text('${l['name'] ?? ''}')))
                          .toList(),
                      onChanged: (val) => locCtrl.value = val ?? '',
                    );
                  },
                ),
                fField('Fecha *', dateCtrl, hint: 'YYYY-MM-DD'),
                Row(
                  children: [
                    Expanded(
                        child: fField('Inicio *', startCtrl, hint: '09:00')),
                    const SizedBox(width: 8),
                    Expanded(
                        child: fField('Fin *', endCtrl, hint: '17:00')),
                  ],
                ),
                fField('Nota', noteCtrl),
                const SizedBox(height: 12),
                Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                  TextButton(
                      onPressed: () => Navigator.pop(ctx),
                      child: const Text('Cancelar')),
                  const SizedBox(width: 8),
                  FilledButton(
                    onPressed: () async {
                      if (workerCtrl.value.isEmpty ||
                          locCtrl.value.isEmpty ||
                          dateCtrl.text.isEmpty ||
                          startCtrl.text.isEmpty ||
                          endCtrl.text.isEmpty) {
                        U.toast(ctx, 'Completa todos los campos',
                            kind: 'err');
                        return;
                      }
                      Navigator.pop(ctx);
                      final payload = <String, dynamic>{
                        'id': s != null ? s['id'] : 0,
                        'user_id': workerCtrl.value,
                        'location_id': locCtrl.value,
                        'shift_date': dateCtrl.text.trim(),
                        'time_start': startCtrl.text.trim(),
                        'time_end': endCtrl.text.trim(),
                        'note': noteCtrl.text.trim(),
                      };
                      final ok = await U.handlePush(
                          context,
                          SyncService.I.push('ws_save_shift', payload),
                          'Guardado',
                          onOk: () => SyncService.I.pullStore('ws_shifts_list', {'start': '${DateTime.now().year}-${DateTime.now().month.toString().padLeft(2, '0')}-01 00:00:00', 'end': '${DateTime.now().year}-12-31 23:59:59'}, 'shifts', cacheKey: 'ws_shifts_list', dataKey: 'shifts'),
                          onQueued: (qp) async {
                            final rows = await DbService.I.all('shifts');
                            final id = payload['id'] ?? 0;
                            if (id == 0) {
                              rows.add({
                                'id': -DateTime.now().millisecondsSinceEpoch,
                                'user_id': payload['user_id'], 'location_id': payload['location_id'],
                                'shift_date': payload['shift_date'], 'time_start': payload['time_start'],
                                'time_end': payload['time_end'], 'note': payload['note'],
                              });
                            } else {
                              for (final r in rows) {
                                if ('${r['id']}' == '$id') {
                                  r['shift_date'] = payload['shift_date'];
                                  r['time_start'] = payload['time_start'];
                                  r['time_end'] = payload['time_end'];
                                  r['user_id'] = payload['user_id'];
                                  r['location_id'] = payload['location_id'];
                                  r['note'] = payload['note'];
                                  break;
                                }
                              }
                            }
                            await DbService.I.replaceAll('shifts', rows);
                          });
                      if (ok) _loadAll();
                    },
                    child: const Text('Guardar'),
                  ),
                ]),
              ],
            ),
          );
        },
      ),
    );
  }
}
