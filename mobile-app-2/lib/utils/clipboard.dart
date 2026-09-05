import 'dart:convert';

/// Configuración por defecto del portapapeles (catálogo de stock).
Map<String, dynamic> defaultClipboardConfig() => {
      'header': 'nombre',
      'custom_header': '',
      'body': ['name', 'qty', 'sale_price'],
      'footer': 'total',
      'custom_footer': '',
      'format': 'bloque',
      'separator': 'linea',
    };

/// Etiquetas legibles de cada campo del producto.
const Map<String, String> clipboardFieldLabels = {
  'name': '',
  'qty': 'Stock',
  'sale_price': 'Precio',
  'cost_price': 'Costo',
  'location_name': 'Ubicación',
  'barcode': 'Código',
  'category': 'Categoría',
  'supplier_name': 'Proveedor',
  'min_stock': 'Mínimo',
};

/// Deserializa la configuración guardada; ante cualquier error usa el default.
Map<String, dynamic> clipboardConfigFromJson(String raw) {
  try {
    final m = jsonDecode(raw);
    if (m is Map) return Map<String, dynamic>.from(m);
  } catch (_) {}
  return defaultClipboardConfig();
}

/// Serializa la configuración a JSON para guardarla en SharedPreferences.
String clipboardConfigToJson(Map<String, dynamic> cfg) => jsonEncode(cfg);

/// Construye el texto del portapapeles según la configuración.
/// Formats: bloque (campos en líneas), linea, viñetas (•) o numerada (1.).
/// Separadores: linea (---), vacio (línea en blanco) o ninguno.
String buildClipboardText(
  List<Map<String, dynamic>> items,
  Map<String, dynamic> cfg, {
  required String currency,
  String locationName = '',
}) {
  final header = '${cfg['header'] ?? 'nombre'}';
  final customHeader = '${cfg['custom_header'] ?? ''}';
  final fields = List<String>.from(cfg['body'] ?? ['name', 'qty', 'sale_price']);
  final footer = '${cfg['footer'] ?? 'total'}';
  final customFooter = '${cfg['custom_footer'] ?? ''}';
  final format = '${cfg['format'] ?? 'bloque'}';
  final separator = '${cfg['separator'] ?? 'linea'}';
  final b = StringBuffer();

  // ── Encabezado (opcional, arriba del mensaje) ──
  if (header == 'nombre' && locationName.isNotEmpty) {
    b.writeln('Catálogo: $locationName');
  } else if (header == 'custom' && customHeader.isNotEmpty) {
    b.writeln(customHeader);
  }

  String fieldValue(String f, Map<String, dynamic> r) {
    final label = clipboardFieldLabels[f] ?? '';
    if (f == 'name') return '${r['name'] ?? ''}';
    if (f == 'qty') {
      final qty = (num.tryParse('${r['qty']}') ?? 0).toInt();
      return '$label: $qty uds';
    }
    if (f == 'sale_price') {
      final price = num.tryParse('${r['sale_price'] ?? r['price'] ?? 0}') ?? 0;
      return '$label: ${'${price.toStringAsFixed(2)} $currency'}';
    }
    if (f == 'cost_price') {
      final cost = num.tryParse('${r['cost_price'] ?? 0}') ?? 0;
      return '$label: ${'${cost.toStringAsFixed(2)} $currency'}';
    }
    final val = '${r[f] ?? ''}';
    return (val.isNotEmpty && val != 'null') ? '$label: $val' : '';
  }

  for (var i = 0; i < items.length; i++) {
    final r = items[i];
    if (format == 'bloque') {
      for (final f in fields) {
        final v = fieldValue(f, r);
        if (v.isNotEmpty) b.writeln(v);
      }
    } else {
      final parts = <String>[];
      if (fields.contains('name')) parts.add('${r['name'] ?? ''}');
      for (final f in fields) {
        if (f == 'name') continue;
        final v = fieldValue(f, r);
        if (v.isNotEmpty) parts.add(v);
      }
      final joined = parts.join(' — ');
      if (format == 'viñetas') {
        b.writeln('• $joined');
      } else if (format == 'numerada') {
        b.writeln('${i + 1}. $joined');
      } else {
        b.writeln(joined);
      }
    }
    if (i < items.length - 1) {
      if (separator == 'vacio') {
        b.writeln();
      } else if (separator != 'ninguno') {
        b.writeln('---');
      }
    }
  }

  // ── Pie (opcional, abajo del mensaje) ──
  if (footer == 'total') {
    b.writeln('Total: ${items.length} producto${items.length != 1 ? 's' : ''}');
  } else if (footer == 'custom' && customFooter.isNotEmpty) {
    b.writeln(customFooter);
  }
  return b.toString().trim();
}