import 'package:flutter_test/flutter_test.dart';
import 'package:shopup_panel/models.dart';

void main() {
  group('availableFor (disponibilidad POS)', () {
    test('stock libre = stock − carrito', () {
      expect(availableFor(stock: 10, inCart: 0), 10);
      expect(availableFor(stock: 5, inCart: 3), 2);
    });

    test('bloquea a 0 cuando stock agotado', () {
      expect(availableFor(stock: 0, inCart: 0), 0);
      expect(availableFor(stock: 2, inCart: 2), 0);
    });

    test('nunca negativo aunque carrito > stock', () {
      expect(availableFor(stock: 1, inCart: 5), 0);
      expect(availableFor(stock: 0, inCart: 100), 0);
    });

    test('stock grande con poco en carrito', () {
      expect(availableFor(stock: 1000, inCart: 1), 999);
    });
  });

  group('PosItem.fromStockRow', () {
    test('parsea fila completa', () {
      final p = PosItem.fromStockRow({
        'product_id': 42,
        'combo_id': 7,
        'name': 'Café espresso',
        'sale_price': '2.50',
        'qty': '15',
        'barcode': ' ABC123 ',
      });
      expect(p.productId, 42);
      expect(p.comboId, 7);
      expect(p.name, 'Café espresso');
      expect(p.price, 2.5);
      expect(p.stock, 15);
      expect(p.barcode, 'abc123');
    });

    test('valores por defecto cuando faltan campos', () {
      final p = PosItem.fromStockRow({});
      expect(p.productId, 0);
      expect(p.comboId, 0);
      expect(p.name, '');
      expect(p.price, 0);
      expect(p.stock, 0);
      expect(p.barcode, '');
    });

    test('precio como string con comas devuelve 0 (sin soporte)', () {
      final p = PosItem.fromStockRow({
        'sale_price': '1,250',
        'qty': '10',
      });
      expect(p.price, 0);
      expect(p.stock, 10);
    });

    test('precio como número directo', () {
      final p = PosItem.fromStockRow({
        'sale_price': 3.75,
        'qty': 8,
      });
      expect(p.price, 3.75);
      expect(p.stock, 8);
    });

    test('product_id como string', () {
      final p = PosItem.fromStockRow({'product_id': '99'});
      expect(p.productId, 99);
    });
  });

  group('CartItem', () {
    test('creación y mutación de qty', () {
      final item = CartItem(
        productId: 1,
        comboId: 0,
        name: 'Agua',
        price: 1.0,
        qty: 3,
      );
      expect(item.qty, 3);
      item.qty = 5;
      expect(item.qty, 5);
    });

    test('igualdad por productId y comboId', () {
      final a = CartItem(
          productId: 1, comboId: 0, name: 'X', price: 1, qty: 1);
      final b = CartItem(
          productId: 1, comboId: 0, name: 'Y', price: 2, qty: 3);
      // Mismo producto/combo
      expect(a.productId, b.productId);
      expect(a.comboId, b.comboId);
    });
  });
}
