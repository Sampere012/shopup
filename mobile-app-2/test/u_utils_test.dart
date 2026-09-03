import 'package:flutter_test/flutter_test.dart';
import 'package:shopup_panel/widgets/common.dart';

void main() {
  group('U.money', () {
    test('formato básico: precio espacio moneda', () {
      expect(U.money(1500, 'CUP'), '1500.00 CUP');
    });

    test('decimales 0', () {
      expect(U.money(1500, 'CUP', dec: 0), '1500 CUP');
    });

    test('número decimal', () {
      expect(U.money(25.5, 'EUR'), '25.50 EUR');
    });

    test('cero', () {
      expect(U.money(0, 'USD'), '0.00 USD');
    });

    test('negativo', () {
      expect(U.money(-42.1, 'CUP'), '-42.10 CUP');
    });

    test('moneda vacía (sin espacio extra al final)', () {
      expect(U.money(10, ''), '10.00 ');
    });

    test('grandes cantidades', () {
      expect(U.money(9999999.99, 'CUP'), '9999999.99 CUP');
    });

    test('un decimal', () {
      expect(U.money(3.1, 'EUR', dec: 1), '3.1 EUR');
    });
  });
}
