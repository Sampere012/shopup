import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../config.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../services/websocket_service.dart';
import '../utils/clipboard.dart';
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

  // ── Configuración del negocio (igual que el panel web: Monedas, Tasa,
  //    WhatsApp y Métodos de pago) ──
  final _cCurrencies = TextEditingController();
  final _cWhatsapp = TextEditingController();
  final Map<String, TextEditingController> _rateCtrls = {};
  String _currency = '';
  String _ratesUpdated = '';
  Map<String, dynamic> _rates = {};
  List<String> _paymentMethods = [];
  bool _rateBusy = false;
  // Evita que un refresh de sincronización pise lo que el usuario está
  // escribiendo ahora mismo.
  bool _dirty = false;

  static const _paymentOptions = [
    'Efectivo',
    'Tarjeta',
    'Transferencia',
    'Pago móvil',
    'Cheque',
    'Otro',
  ];

  List<String> get _currencyList => _cCurrencies.text
      .split(',')
      .map((e) => e.trim())
      .where((e) => e.isNotEmpty)
      .toList();

  @override
  void dispose() {
    cMinutes.dispose();
    _cCurrencies.dispose();
    _cWhatsapp.dispose();
    for (final c in _rateCtrls.values) {
      c.dispose();
    }
    super.dispose();
  }
  // Clipboard config
  String _clipHeader = 'nombre';
  String _clipCustomHeader = '';
  List<String> _clipBodyFields = ['name', 'qty', 'sale_price'];
  String _clipFooter = 'total';
  String _clipCustomFooter = '';
  String _clipFormat = 'bloque';
  String _clipSeparator = 'linea';

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
      if (!_dirty) _applyBusinessSettings(server);
    });
  }

  /// Carga las monedas/tasas/WhatsApp/métodos de pago del servidor en los
  /// controles locales (mismas claves que `ws_settings_get`).
  void _applyBusinessSettings(Map<String, dynamic> s) {
    _currency = '${s['currency'] ?? ''}';
    _cCurrencies.text = '${s['currencies'] ?? ''}';
    _cWhatsapp.text = '${s['whatsapp'] ?? ''}';
    _ratesUpdated = '${s['rates_updated'] ?? ''}';
    final r = s['rates'];
    _rates = r is Map ? Map<String, dynamic>.from(r) : <String, dynamic>{};
    final pm = s['payment_methods'];
    _paymentMethods = pm is List
        ? List<String>.from(pm.map((e) => '$e'))
        : List<String>.from(_paymentOptions.take(3));
    // Sincroniza la moneda por defecto con la lista (como syncCurrencies en
    // la web) y actualiza las tasas en los controllers existentes. NO se
    // depuran controllers aquí: disposing mientras el TextField está montado
    // provoca errores; se reciclan por código y se liberan al cerrar.
    final list = _currencyList;
    if (list.isNotEmpty && !list.contains(_currency)) _currency = list.first;
    _rateCtrls.forEach((code, ctl) {
      final v = _rates[code];
      final txt = v == null ? '' : '$v';
      if (ctl.text != txt) ctl.text = txt;
    });
  }

  /// Controller de la tasa de una moneda (se crea una sola vez y se recicla
  /// mientras se teclea).
  TextEditingController _rateCtrl(String code) {
    return _rateCtrls.putIfAbsent(
        code, () => TextEditingController(text: '${_rates[code] ?? ''}'));
  }

  /// Guarda la configuración del negocio. El endpoint `ws_save_settings`
  /// escribe TODOS los campos, así que se envían siempre juntos (igual que
  /// el formulario de la web) para no pisar valores con vacíos.
  Future<void> _saveSettings({String okMsg = 'Configuración guardada'}) async {
    final list = _currencyList;
    if (list.isEmpty) {
      U.toast(context, 'Escribe al menos una moneda', kind: 'err');
      return;
    }
    if (!list.contains(_currency)) _currency = list.first;
    final rates = <String, dynamic>{};
    _rateCtrls.forEach((code, ctl) {
      if (code == _currency) return;
      final v = double.tryParse(ctl.text.trim());
      if (v != null && v > 0) rates[code] = v;
    });
    final ok = await U.handlePush(
      context,
      SyncService.I.push('ws_save_settings', {
        'currency': _currency,
        'currencies': _cCurrencies.text.trim(),
        'whatsapp': _cWhatsapp.text.trim(),
        'payment_methods': jsonEncode(_paymentMethods),
        'rates': jsonEncode(rates),
      }),
      okMsg,
      onOk: () async {
        _dirty = false;
        await SyncService.I.pullCache(
            'ws_settings_get', {}, 'ws_settings_get');
        await _load();
      },
      onQueued: (_) async {
        _dirty = false;
      },
    );
    if (!ok && mounted) {
      // El servidor rechazó la operación: se recupera el estado real.
      _dirty = false;
      await _load();
    }
  }

  /// Consulta la tasa USD/CUP desde El Toque (server-side, persiste en el
  /// servidor como en la web) y refresca los controles.
  Future<void> _fetchRate() async {
    if (_rateBusy) return;
    setState(() => _rateBusy = true);
    try {
      final d = await ApiService.I.req('ws_update_rate', {}, timeoutSec: 30);
      final rate = (d as Map?)?['rate'];
      if (rate is Map) {
        _dirty = false;
        await SyncService.I.pullCache(
            'ws_settings_get', {}, 'ws_settings_get');
        await _load();
        if (mounted) {
          U.toast(context, 'Tasa actualizada desde El Toque', kind: 'ok');
        }
      }
    } on ApiException catch (e) {
      if (mounted) U.toast(context, e.message, kind: 'err');
    } catch (_) {
      if (mounted) U.toast(context, 'No se pudo consultar El Toque', kind: 'err');
    } finally {
      if (mounted) setState(() => _rateBusy = false);
    }
  }

  Widget _bizHeader(IconData icon, String title, {Color color = AppTheme.primary}) {
    return Row(children: [
      Icon(icon, size: 20, color: color),
      const SizedBox(width: 8),
      Text(title,
          style: Theme.of(context)
              .textTheme
              .titleSmall
              ?.copyWith(fontWeight: FontWeight.w700)),
    ]);
  }

  Widget _saveBtn(VoidCallback onPressed) {
    return Align(
      alignment: Alignment.centerRight,
      child: OutlinedButton.icon(
        icon: const Icon(Icons.save_outlined, size: 18),
        label: const Text('Guardar'),
        onPressed: onPressed,
      ),
    );
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
    if (raw == null) return;
    final m = clipboardConfigFromJson(raw);
    setState(() {
      _clipHeader = '${m['header'] ?? 'nombre'}';
      _clipCustomHeader = '${m['custom_header'] ?? ''}';
      _clipBodyFields = List<String>.from(m['body'] ?? ['name', 'qty', 'sale_price']);
      _clipFooter = '${m['footer'] ?? 'total'}';
      _clipCustomFooter = '${m['custom_footer'] ?? ''}';
      _clipFormat = '${m['format'] ?? 'bloque'}';
      _clipSeparator = '${m['separator'] ?? 'linea'}';
    });
  }

  Future<void> _saveClipboardConfig() async {
    final sp = await SharedPreferences.getInstance();
    await sp.setString('wsm_clipboard_config',
        clipboardConfigToJson({
          'header': _clipHeader,
          'custom_header': _clipCustomHeader,
          'body': _clipBodyFields,
          'footer': _clipFooter,
          'custom_footer': _clipCustomFooter,
          'format': _clipFormat,
          'separator': _clipSeparator,
        }));
    if (mounted) U.toast(context, 'Configuración de portapapeles guardada', kind: 'ok');
  }

  String _previewClipboard() {
    const sample = <String, dynamic>{
      'name': 'Producto de ejemplo',
      'qty': 25,
      'sale_price': 12.5,
      'cost_price': 8.0,
      'location_name': 'Tienda Principal',
      'barcode': '123456789',
      'category': 'Bebidas',
      'supplier_name': 'Distribuidora XYZ',
      'min_stock': 5,
    };
    return buildClipboardText([sample], {
      'header': _clipHeader,
      'custom_header': _clipCustomHeader,
      'body': _clipBodyFields,
      'footer': _clipFooter,
      'custom_footer': _clipCustomFooter,
      'format': _clipFormat,
      'separator': _clipSeparator,
    }, currency: '€', locationName: 'Tienda Principal');
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return ListView(padding: const EdgeInsets.all(14), children: [
      // ── Monedas del negocio (igual que la web) ──
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            _bizHeader(Icons.payments_outlined, 'Monedas del negocio'),
            const SizedBox(height: 6),
            Text(
                'Escribe las monedas separadas por coma. La primera es la moneda por defecto al crear un producto. Ejemplo: USD, CUP',
                style: TextStyle(fontSize: 12, color: Colors.grey[600])),
            const SizedBox(height: 12),
            TextField(
              controller: _cCurrencies,
              decoration: const InputDecoration(
                  labelText: 'Monedas (separadas por coma)',
                  hintText: 'USD, CUP'),
              onChanged: (_) {
                _dirty = true;
                final list = _currencyList;
                if (list.isNotEmpty && !list.contains(_currency)) {
                  _currency = list.first;
                }
                setState(() {});
              },
            ),
            const SizedBox(height: 10),
            if (_currencyList.isNotEmpty)
              DropdownButtonFormField<String>(
                key: ValueKey('defCurrency-$_currency'),
                initialValue: _currency,
                decoration: const InputDecoration(labelText: 'Moneda por defecto'),
                items: _currencyList
                    .map((c) => DropdownMenuItem(value: c, child: Text(c)))
                    .toList(),
                onChanged: (v) {
                  if (v == null) return;
                  _dirty = true;
                  setState(() => _currency = v);
                },
              ),
            const SizedBox(height: 8),
            _saveBtn(() => _saveSettings(okMsg: 'Monedas guardadas')),
          ]),
        ),
      ),
      const SizedBox(height: 10),

      // ── Tasa de cambio (igual que la web) ──
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            _bizHeader(Icons.swap_horiz, 'Tasa de cambio'),
            const SizedBox(height: 6),
            Text(
                'Equivalencia de cada moneda respecto a la moneda por defecto. Ejemplo: 1 USD = 670 CUP',
                style: TextStyle(fontSize: 12, color: Colors.grey[600])),
            const SizedBox(height: 12),
            if (_currencyList.where((c) => c != _currency).isEmpty)
              Text('Añade más monedas (Monedas del negocio) para definir sus tasas.',
                  style: TextStyle(fontSize: 12, color: Colors.grey[500])),
            for (final c in _currencyList)
              if (c != _currency)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: TextField(
                    controller: _rateCtrl(c),
                    keyboardType:
                        const TextInputType.numberWithOptions(decimal: true),
                    decoration: InputDecoration(
                        labelText: '1 $c = ? ${_currency.isEmpty ? '' : _currency}'),
                    onChanged: (_) => _dirty = true,
                  ),
                ),
            OutlinedButton.icon(
              onPressed: _rateBusy ? null : _fetchRate,
              icon: _rateBusy
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.cloud_download_outlined, size: 18),
              label: Text(
                  _rateBusy ? 'Consultando El Toque…' : 'Actualizar tasa desde El Toque'),
            ),
            if (_ratesUpdated.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text('Última actualización: $_ratesUpdated',
                  style: TextStyle(fontSize: 11, color: Colors.grey[500])),
            ],
            const SizedBox(height: 8),
            _saveBtn(() => _saveSettings(okMsg: 'Tasa de cambio guardada')),
          ]),
        ),
      ),
      const SizedBox(height: 10),

      // ── WhatsApp para pedidos (igual que la web) ──
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            _bizHeader(Icons.chat_outlined, 'WhatsApp para pedidos',
                color: AppTheme.success),
            const SizedBox(height: 6),
            Text(
                'Números que atienden pedidos. Sepáralos por coma para mostrar un desplegable al cliente. Cada tienda puede tener los suyos.',
                style: TextStyle(fontSize: 12, color: Colors.grey[600])),
            const SizedBox(height: 12),
            TextField(
              controller: _cWhatsapp,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(
                  labelText: 'Números (separados por coma)',
                  hintText: '+58 412 123 4567, +58 416 987 6543'),
              onChanged: (_) => _dirty = true,
            ),
            const SizedBox(height: 8),
            _saveBtn(() => _saveSettings(okMsg: 'WhatsApp guardado')),
          ]),
        ),
      ),
      const SizedBox(height: 10),

      // ── Métodos de pago (igual que la web) ──
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            _bizHeader(Icons.credit_card, 'Métodos de pago'),
            const SizedBox(height: 6),
            Text('Se usan como opciones por defecto en las tiendas.',
                style: TextStyle(fontSize: 12, color: Colors.grey[600])),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              runSpacing: 6,
              children: _paymentOptions.map((m) {
                final sel = _paymentMethods.contains(m);
                return FilterChip(
                  label: Text(m, style: const TextStyle(fontSize: 13)),
                  selected: sel,
                  onSelected: (v) {
                    _dirty = true;
                    setState(() {
                      if (v) {
                        if (!_paymentMethods.contains(m)) {
                          _paymentMethods.add(m);
                        }
                      } else {
                        _paymentMethods.remove(m);
                      }
                    });
                  },
                );
              }).toList(),
            ),
            const SizedBox(height: 8),
            _saveBtn(() => _saveSettings(okMsg: 'Métodos de pago guardados')),
          ]),
        ),
      ),
      const SizedBox(height: 10),

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

            // ── Formato ──
            Text('Formato del mensaje', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: Colors.grey[700])),
            const SizedBox(height: 6),
            DropdownButtonFormField<String>(
              initialValue: _clipFormat,
              isDense: true,
              decoration: const InputDecoration(
                labelText: 'Formato',
                contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              ),
              items: const [
                DropdownMenuItem(value: 'bloque', child: Text('Bloque (campos en líneas)', style: TextStyle(fontSize: 13))),
                DropdownMenuItem(value: 'linea', child: Text('Lista (una línea por producto)', style: TextStyle(fontSize: 13))),
                DropdownMenuItem(value: 'viñetas', child: Text('Lista con viñetas (•)', style: TextStyle(fontSize: 13))),
                DropdownMenuItem(value: 'numerada', child: Text('Lista numerada (1. 2. 3.)', style: TextStyle(fontSize: 13))),
              ],
              onChanged: (v) => setState(() => _clipFormat = v ?? 'bloque'),
            ),
            const SizedBox(height: 12),

            // ── Separador ──
            Text('Separador entre productos', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: Colors.grey[700])),
            const SizedBox(height: 6),
            DropdownButtonFormField<String>(
              initialValue: _clipSeparator,
              isDense: true,
              decoration: const InputDecoration(
                labelText: 'Separador',
                contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 10),
              ),
              items: const [
                DropdownMenuItem(value: 'linea', child: Text('Línea (---)', style: TextStyle(fontSize: 13))),
                DropdownMenuItem(value: 'vacio', child: Text('Línea en blanco', style: TextStyle(fontSize: 13))),
                DropdownMenuItem(value: 'ninguno', child: Text('Ninguno', style: TextStyle(fontSize: 13))),
              ],
              onChanged: (v) => setState(() => _clipSeparator = v ?? 'linea'),
            ),
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
