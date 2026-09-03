import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/db_service.dart';

/// Motor genérico de listas offline-first con dark mode.
class CrudSpec {
  const CrudSpec({
    required this.store,
    this.searchFields = const ['name'],
    required this.tile,
    this.emptyText = 'Sin elementos.',
  });
  final String store;
  final List<String> searchFields;
  final Widget Function(BuildContext, Map<String, dynamic>) tile;
  final String emptyText;
}

class CrudListScreen extends StatefulWidget {
  const CrudListScreen({super.key, required this.spec, this.fab});
  final CrudSpec spec;
  final Widget? fab;
  @override
  State<CrudListScreen> createState() => _CrudListScreenState();
}

class _CrudListScreenState extends State<CrudListScreen> {
  String _q = '';
  late Future<List<Map<String, dynamic>>> _future;

  @override
  void initState() {
    super.initState();
    _load();
  }

  void _load() {
    _future = DbService.I.all(widget.spec.store);
    setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Column(children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 6),
        child: TextField(
          decoration: InputDecoration(
            hintText: 'Buscar…',
            prefixIcon: const Icon(Icons.search),
            isDense: true,
            filled: true,
            fillColor: isDark ? AppTheme.darkSurface : Colors.white,
            suffixIcon: IconButton(
                onPressed: () {
                  _q = '';
                  _load();
                },
                icon: const Icon(Icons.close, size: 18)),
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
              rows = rows.where((r) {
                for (final f in widget.spec.searchFields) {
                  if ('${r[f] ?? ''}'.toLowerCase().contains(_q)) {
                    return true;
                  }
                }
                return false;
              }).toList();
            }
            if (rows.isNotEmpty) {
              rows.sort((a, b) => '${a['name'] ?? ''}'
                  .toLowerCase()
                  .compareTo('${b['name'] ?? ''}'.toLowerCase()));
            }
            if (rows.isEmpty) {
              return Center(
                child: Text(widget.spec.emptyText,
                    style: TextStyle(
                        color: isDark
                            ? AppTheme.darkMuted
                            : AppTheme.lightMuted)),
              );
            }
            return RefreshIndicator(
              onRefresh: () async {
                _load();
                await _future;
              },
              child: ListView.separated(
                padding: const EdgeInsets.fromLTRB(14, 4, 14, 90),
                itemCount: rows.length,
                separatorBuilder: (_, __) =>
                    const SizedBox(height: 8),
                itemBuilder: (context, i) => KeyedSubtree(
                    key: ValueKey(rows[i].hashCode),
                    child: widget.spec.tile(context, rows[i])),
              ),
            );
          },
        ),
      ),
    ]);
  }
}

/// Formulario modal reutilizable con dark mode.
Future<bool> showFormSheet(
  BuildContext context, {
  required String title,
  required List<Widget> fields,
  required Future<bool> Function() onSave,
}) async {
  var busy = false;
  return await showModalBottomSheet<bool>(
        context: context,
        isScrollControlled: true,
        shape: const RoundedRectangleBorder(
            borderRadius: BorderRadius.vertical(top: Radius.circular(18))),
        builder: (ctx) => StatefulBuilder(
          builder: (ctx, setSheet) => Padding(
            padding: EdgeInsets.only(
                left: 18,
                right: 18,
                top: 16,
                bottom: MediaQuery.of(ctx).viewInsets.bottom + 18),
            child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(title,
                      style: Theme.of(ctx)
                          .textTheme
                          .titleMedium
                          ?.copyWith(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 14),
                  ...fields,
                  const SizedBox(height: 16),
                  Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                    TextButton(
                        onPressed: busy ? null : () => Navigator.pop(ctx, false),
                        child: const Text('Cancelar')),
                    const SizedBox(width: 8),
                    FilledButton.icon(
                      onPressed: busy
                          ? null
                          : () async {
                              setSheet(() => busy = true);
                              try {
                                final ok = await onSave();
                                if (ctx.mounted) Navigator.pop(ctx, ok);
                              } catch (e) {
                                if (ctx.mounted) Navigator.pop(ctx, false);
                              }
                            },
                      icon: busy
                          ? const SizedBox(
                              width: 16,
                              height: 16,
                              child: CircularProgressIndicator(
                                  strokeWidth: 2, color: Colors.white))
                          : const Icon(Icons.save_outlined, size: 18),
                      label: const Text('Guardar'),
                    ),
                  ]),
                ]),
          ),
        ),
      ) ??
      false;
}

Widget fField(String label, TextEditingController c,
    {String? hint,
    TextInputType type = TextInputType.text,
    bool enabled = true}) {
  return Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: TextField(
      controller: c,
      enabled: enabled,
      keyboardType: type,
      decoration: InputDecoration(labelText: label, hintText: hint),
    ),
  );
}

Widget fSwitch(String label, bool value, ValueChanged<bool> onChanged) {
  return SwitchListTile.adaptive(
    contentPadding: EdgeInsets.zero,
    title: Text(label, style: const TextStyle(fontSize: 14)),
    value: value,
    onChanged: onChanged,
  );
}

Widget fDropdown(String label, Object? value,
    List<DropdownMenuItem<Object>> items, ValueChanged<Object?> onChanged) {
  return Padding(
    padding: const EdgeInsets.only(bottom: 12),
    child: DropdownButtonFormField<Object>(
      initialValue: value,
      decoration: InputDecoration(labelText: label),
      items: items,
      onChanged: onChanged,
    ),
  );
}
