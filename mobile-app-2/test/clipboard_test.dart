import 'package:flutter_test/flutter_test.dart';
import 'package:shopup_panel/utils/clipboard.dart';

void main() {
  const items = [
    {'name': 'Coca Cola 33cl', 'qty': 12, 'sale_price': 1.5},
    {'name': 'Pan Bimbo', 'qty': 3, 'sale_price': 2.0},
  ];

  group('buildClipboardText', () {
    test('default: bloque, separador línea, encabezado nombre, pie total', () {
      final out = buildClipboardText(List.of(items), defaultClipboardConfig(),
          currency: '€', locationName: 'Tienda Principal');
      expect(out,
          'Catálogo: Tienda Principal\n'
          'Coca Cola 33cl\n'
          'Stock: 12 uds\n'
          'Precio: 1.50 €\n'
          '---\n'
          'Pan Bimbo\n'
          'Stock: 3 uds\n'
          'Precio: 2.00 €\n'
          'Total: 2 productos');
    });

    test('viñetas con separador vacío y sin pie ni encabezado', () {
      final cfg = {
        'header': 'none',
        'body': ['name', 'qty', 'sale_price'],
        'footer': 'ninguno',
        'format': 'viñetas',
        'separator': 'vacio',
      };
      final out = buildClipboardText(List.of(items), cfg, currency: '€');
      expect(out,
          '• Coca Cola 33cl — Stock: 12 uds — Precio: 1.50 €\n'
          '\n'
          '• Pan Bimbo — Stock: 3 uds — Precio: 2.00 €');
    });

    test('numerada numera los productos', () {
      final cfg = {
        'header': 'none',
        'body': ['name', 'qty', 'sale_price'],
        'footer': 'ninguno',
        'format': 'numerada',
        'separator': 'linea',
      };
      final out = buildClipboardText(List.of(items), cfg, currency: '€');
      expect(out, startsWith('1. Coca Cola 33cl — Stock: 12 uds — Precio: 1.50 €'));
      expect(out, contains('\n---\n2. Pan Bimbo — Stock: 3 uds — Precio: 2.00 €'));
    });

    test('formato linea sin viñetas ni numeración', () {
      final cfg = {
        'header': 'none',
        'body': ['name', 'qty'],
        'footer': 'ninguno',
        'format': 'linea',
        'separator': 'ninguno',
      };
      final out = buildClipboardText(List.of(items), cfg, currency: '€');
      expect(out, 'Coca Cola 33cl — Stock: 12 uds\nPan Bimbo — Stock: 3 uds');
    });

    test('encabezado y pie personalizados', () {
      final cfg = {
        'header': 'custom',
        'custom_header': 'Lista para pedir',
        'body': ['name', 'qty'],
        'footer': 'custom',
        'custom_footer': 'Cambiar antes de las 13:00',
        'format': 'bloque',
        'separator': 'ninguno',
      };
      final out = buildClipboardText(List.of(items), cfg, currency: '€');
      expect(out,
          'Lista para pedir\n'
          'Coca Cola 33cl\n'
          'Stock: 12 uds\n'
          'Pan Bimbo\n'
          'Stock: 3 uds\n'
          'Cambiar antes de las 13:00');
    });

    test('la moneda se formatea con 2 decimales', () {
      final cfg = {
        'header': 'none',
        'body': ['sale_price'],
        'footer': 'ninguno',
        'format': 'linea',
        'separator': 'ninguno',
      };
      final out = buildClipboardText(List.of(items), cfg, currency: 'USD');
      expect(out, contains('Precio: 1.50 USD'));
    });

    test('lista vacía solo muestra el pie total', () {
      final out = buildClipboardText([], defaultClipboardConfig(),
          currency: '€', locationName: 'Tienda Principal');
      expect(out, 'Catálogo: Tienda Principal\nTotal: 0 productos');
    });

    test('campos faltantes se ignoran sin romper el formato', () {
      final cfg = {
        'header': 'none',
        'body': ['name', 'barcode', 'location_name'],
        'footer': 'ninguno',
        'format': 'linea',
        'separator': 'ninguno',
      };
      final out = buildClipboardText(const [
        {'name': 'Sin codigo'},
      ], cfg, currency: '€');
      expect(out, 'Sin codigo');
    });
  });

  group('config json', () {
    test('json inválido devuelve la configuración por defecto', () {
      expect(clipboardConfigFromJson('{no-json'), defaultClipboardConfig());
    });

    test('round-trip conserva la configuración', () {
      final cfg = {
        'header': 'custom',
        'custom_header': 'Hi',
        'body': ['name', 'qty'],
        'footer': 'ninguno',
        'format': 'viñetas',
        'separator': 'vacio',
      };
      final restored =
          clipboardConfigFromJson(clipboardConfigToJson(cfg));
      expect(restored, cfg);
    });
  });
}