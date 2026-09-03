import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../config.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../services/theme_service.dart';
import '../services/websocket_service.dart';
import '../widgets/common.dart';

/// Configuración: settings del servidor + settings locales del dispositivo.
class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});
  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  final cMinutes =
      TextEditingController(text: '${SyncService.I.autoSyncMinutes}');
  Map<String, dynamic> server = {};
  // Clipboard config
  String _clipHeader = 'nombre';
  String _clipCustomHeader = '';
  List<String> _clipBodyFields = ['name', 'qty', 'sale_price'];
  String _clipFooter = 'total';
  String _clipCustomFooter = '';

  @override
  void initState() {
    super.initState();
    _load();
    _loadClipboardConfig();
    SyncService.I.onChange(_onSync);
  }

  void _onSync() {
    if (mounted) _load();
  }

  Future<void> _load() async {
    final raw = await DbService.I.cacheGet('ws_settings_get');
    if (!mounted) return;
    setState(() {
      server = raw is Map ? Map<String, dynamic>.from(raw) : {};
    });
  }

  static const _clipAllFields = <String, String>{
    'name': 'Nombre',
    'qty': 'Stock',
    'sale_price': 'Precio venta',
    'cost_price': 'Precio costo',
    'location_name': 'Ubicación',
    'barcode': 'Código barras',
    'category': 'Categoría',
    'supplier_name': 'Proveedor',
    'min_stock': 'Stock mínimo',
  };

  Future<void> _loadClipboardConfig() async {
    final sp = await SharedPreferences.getInstance();
    final raw = sp.getString('wsm_clipboard_config');
    if (raw != null) {
      try {
        final m = jsonDecode(raw) as Map<String, dynamic>;
        setState(() {
          _clipHeader = m['header'] ?? 'nombre';
          _clipCustomHeader = m['custom_header'] ?? '';
          _clipBodyFields = List<String>.from(m['body'] ?? ['name', 'qty', 'sale_price']);
          _clipFooter = m['footer'] ?? 'total';
          _clipCustomFooter = m['custom_footer'] ?? '';
        });
        return;
      } catch (_) {}
    }
  }

  Future<void> _saveClipboardConfig() async {
    final sp = await SharedPreferences.getInstance();
    await sp.setString('wsm_clipboard_config', jsonEncode({
      'header': _clipHeader,
      'custom_header': _clipCustomHeader,
      'body': _clipBodyFields,
      'footer': _clipFooter,
      'custom_footer': _clipCustomFooter,
    }));
    if (mounted) U.toast(context, 'Configuración de portapapeles guardada', kind: 'ok');
  }

  String _previewClipboard() {
    final b = StringBuffer();
    // Header
    if (_clipHeader == 'nombre') {
      b.writeln('Producto de ejemplo');
    } else if (_clipHeader == 'custom' && _clipCustomHeader.isNotEmpty) {
      b.writeln(_clipCustomHeader);
    }
    // Body
    final sampleValues = <String, String>{
      'name': 'Nombre: Producto de ejemplo',
      'qty': 'Stock: 25 uds',
      'sale_price': 'Precio venta: €12.50',
      'cost_price': 'Precio costo: €8.00',
      'location_name': 'Ubicación: Tienda Principal',
      'barcode': 'Código barras: 123456789',
      'category': 'Categoría: Bebidas',
      'supplier_name': 'Proveedor: Distribuidora XYZ',
      'min_stock': 'Stock mínimo: 5 uds',
    };
    for (final f in _clipBodyFields) {
      b.writeln(sampleValues[f] ?? f);
    }
    // Footer
    if (_clipFooter == 'total') {
      b.writeln('---');
      b.writeln('Total: 1 producto');
    } else if (_clipFooter == 'custom' && _clipCustomFooter.isNotEmpty) {
      b.writeln(_clipCustomFooter);
    }
    return b.toString().trim();
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return ListView(padding: const EdgeInsets.all(14), children: [
      // ── Sincronización ──
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              const Icon(Icons.sync, size: 20, color: AppTheme.primary),
              const SizedBox(width: 8),
              Text('Sincronización',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
            ]),
            const SizedBox(height: 12),
            TextField(
              controller: cMinutes,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(
                  labelText: 'Autosync cada (minutos)',
                  helperText: 'Se guarda en este dispositivo, no necesita internet'),
              onChanged: (_) => setState(() {}),
            ),
            const SizedBox(height: 8),
            OutlinedButton.icon(
              icon: const Icon(Icons.timer_outlined, size: 18),
              label: const Text('Guardar intervalo'),
              onPressed: () async {
                await SyncService.I
                    .setAutoSyncMinutes(int.tryParse(cMinutes.text) ?? 25);
                if (!mounted) return;
                U.toast(context, 'Intervalo guardado: cada ${SyncService.I.autoSyncMinutes} min');
              },
            ),
          ]),
        ),
      ),
      const SizedBox(height: 10),

      // ── Dispositivo (local) ──
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              const Icon(Icons.phone_android, size: 20, color: AppTheme.primary),
              const SizedBox(width: 8),
              Text('Configuración del dispositivo',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
            ]),
            const SizedBox(height: 12),
            // Dark mode toggle
            SwitchListTile.adaptive(
              contentPadding: EdgeInsets.zero,
              title: const Text('Modo oscuro', style: TextStyle(fontSize: 14)),
              subtitle: Text(
                ThemeService.I.mode == ThemeMode.dark ? 'Activado' : (ThemeService.I.mode == ThemeMode.light ? 'Desactivado' : 'Según sistema'),
                style: TextStyle(fontSize: 12, color: Colors.grey[600]),
              ),
              value: ThemeService.I.mode == ThemeMode.dark,
              onChanged: (v) => ThemeService.I.setMode(v ? ThemeMode.dark : ThemeMode.light),
            ),
            const Divider(),
            // DB cache info
            FutureBuilder<int>(
              future: _dbSize(),
              builder: (_, snap) => ListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Caché local', style: TextStyle(fontSize: 14)),
                subtitle: Text('Tamaño: ${snap.data != null ? '${(snap.data! / 1024).toStringAsFixed(1)} KB' : '...'}',
                    style: TextStyle(fontSize: 12, color: Colors.grey[600])),
              ),
            ),
          ]),
        ),
      ),
      const SizedBox(height: 10),

      // ── Portapapeles (clipboard) ──
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              const Icon(Icons.content_copy, size: 20, color: AppTheme.primary),
              const SizedBox(width: 8),
              Text('Portapapeles',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
            ]),
            const SizedBox(height: 4),
            Text('Configura qué se copia al portapapeles desde Stock.',
                style: TextStyle(fontSize: 12, color: Colors.grey[600])),
            const SizedBox(height: 12),

            // ── Encabezado ──
            Text('Encabezado', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: Colors.grey[700])),
            const SizedBox(height: 6),
            DropdownButtonFormField<String>(
              initialValue: _clipHeader,
              isDense: true,
              decoration: const InputDecoration(
                labelText: 'Contenido del encabezado',
                contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              ),
              items: const [
                DropdownMenuItem(value: 'nombre', child: Text('Nombre del producto', style: TextStyle(fontSize: 13))),
                DropdownMenuItem(value: 'custom', child: Text('Texto personalizado', style: TextStyle(fontSize: 13))),
                DropdownMenuItem(value: 'none', child: Text('Sin encabezado', style: TextStyle(fontSize: 13))),
              ],
              onChanged: (v) => setState(() => _clipHeader = v ?? 'nombre'),
            ),
            if (_clipHeader == 'custom') ...[
              const SizedBox(height: 8),
              TextField(
                controller: TextEditingController(text: _clipCustomHeader),
                decoration: const InputDecoration(
                  hintText: 'Ej: Lista de precios',
                  labelText: 'Texto personalizado',
                  isDense: true,
                ),
                onChanged: (v) => _clipCustomHeader = v.trim(),
              ),
            ],
            const SizedBox(height: 14),

            // ── Cuerpo (campos) ──
            Text('Cuerpo (campos a incluir)', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: Colors.grey[700])),
            const SizedBox(height: 6),
            Wrap(
              spacing: 6,
              runSpacing: 4,
              children: _clipAllFields.entries.map((e) {
                final active = _clipBodyFields.contains(e.key);
                return FilterChip(
                  label: Text(e.value, style: const TextStyle(fontSize: 12)),
                  selected: active,
                  onSelected: (sel) {
                    setState(() {
                      if (sel) {
                        _clipBodyFields.add(e.key);
                      } else {
                        _clipBodyFields.remove(e.key);
                      }
                    });
                  },
                );
              }).toList(),
            ),
            const SizedBox(height: 14),

            // ── Pie ──
            Text('Pie de página', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: Colors.grey[700])),
            const SizedBox(height: 6),
            DropdownButtonFormField<String>(
              initialValue: _clipFooter,
              isDense: true,
              decoration: const InputDecoration(
                labelText: 'Contenido del pie',
                contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              ),
              items: const [
                DropdownMenuItem(value: 'total', child: Text('Total de productos', style: TextStyle(fontSize: 13))),
                DropdownMenuItem(value: 'custom', child: Text('Texto personalizado', style: TextStyle(fontSize: 13))),
                DropdownMenuItem(value: 'none', child: Text('Sin pie', style: TextStyle(fontSize: 13))),
              ],
              onChanged: (v) => setState(() => _clipFooter = v ?? 'total'),
            ),
            if (_clipFooter == 'custom') ...[
              const SizedBox(height: 8),
              TextField(
                controller: TextEditingController(text: _clipCustomFooter),
                decoration: const InputDecoration(
                  hintText: 'Ej: Generado por ShopUp',
                  labelText: 'Texto personalizado',
                  isDense: true,
                ),
                onChanged: (v) => _clipCustomFooter = v.trim(),
              ),
            ],
            const SizedBox(height: 14),

            // ── Preview ──
            Container(
              padding: const EdgeInsets.all(10),
              decoration: BoxDecoration(
                color: isDark ? AppTheme.darkSurface : Colors.grey[100],
                borderRadius: BorderRadius.circular(8),
              ),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('Vista previa:', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: Colors.grey[500])),
                const SizedBox(height: 4),
                Text(_previewClipboard(), style: const TextStyle(fontSize: 12, fontFamily: 'monospace')),
              ]),
            ),
            const SizedBox(height: 12),

            // ── Guardar ──
            OutlinedButton.icon(
              icon: const Icon(Icons.save_outlined, size: 18),
              label: const Text('Guardar configuración'),
              onPressed: _saveClipboardConfig,
            ),
          ]),
        ),
      ),
      const SizedBox(height: 10),

      // ── WebSocket (tiempo real) ──
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              const Icon(Icons.wifi, size: 20, color: AppTheme.success),
              const SizedBox(width: 8),
              Text('Tiempo real',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
            ]),
            const SizedBox(height: 8),
            Row(children: [
              Container(
                width: 8, height: 8,
                decoration: BoxDecoration(
                  color: WebSocketService.I.isConnected ? AppTheme.success : AppTheme.lightMuted,
                  shape: BoxShape.circle,
                ),
              ),
              const SizedBox(width: 8),
              Text(
                WebSocketService.I.isConnected
                    ? 'Conectado — recibirás cambios en tiempo real'
                    : 'Desconectado — usa botón sincronizar',
                style: TextStyle(fontSize: 12, color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted),
              ),
            ]),
          ]),
        ),
      ),
      const SizedBox(height: 10),

      // ── Info del servidor ──
      if (server.isNotEmpty)
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                const Icon(Icons.business_outlined, size: 20, color: AppTheme.primary),
                const SizedBox(width: 8),
                Text('Negocio (servidor)',
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
              ]),
              const SizedBox(height: 8),
              for (final e in server.entries.take(15))
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 3),
                  child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                    Flexible(child: Text('${e.key}',
                        style: TextStyle(color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted, fontSize: 13),
                        overflow: TextOverflow.ellipsis)),
                    Flexible(child: Text('${e.value}', textAlign: TextAlign.right,
                        style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600))),
                  ]),
                ),
            ]),
          ),
        ),

      // ── Sesión ──
      const SizedBox(height: 10),
      Card(
        child: ListTile(
          contentPadding: const EdgeInsets.symmetric(horizontal: 16),
          leading: const Icon(Icons.person_outline, size: 20, color: AppTheme.primary),
          title: Text('${AuthService.I.me?['name'] ?? ''}',
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
          subtitle: Text('${AuthService.I.me?['role'] ?? ''}', style: TextStyle(fontSize: 12, color: Colors.grey[600])),
        ),
      ),

      // Version
      const SizedBox(height: 10),
      Center(
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 16),
          child: Text('ShopUp Panel v${AppConfig.appVersion}',
              style: TextStyle(fontSize: 12, color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted)),
        ),
      ),
    ]);
  }

  Future<int> _dbSize() async {
    try {
      // Approximate size from pending count + cached keys
      return await DbService.I.pendingCount() * 512; // rough estimate
    } catch (_) {
      return 0;
    }
  }
}
