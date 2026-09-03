/// Modelos ligeros del POS (equivalente a los objetos anónimos de la app JS).

/// Disponibilidad real de venta: stock local − unidades ya en el carrito.
/// Nunca negativa; 0 bloquea agregar más unidades (límite por stock).
int availableFor({required int stock, required int inCart}) {
  final a = stock - inCart;
  return a < 0 ? 0 : a;
}

class PosItem {
  PosItem({
    required this.productId,
    required this.comboId,
    required this.name,
    required this.price,
    required this.stock,
    this.barcode = '',
  });

  factory PosItem.fromStockRow(Map<String, dynamic> r) => PosItem(
        productId: num.tryParse('${r['product_id']}')?.toInt() ?? 0,
        comboId: num.tryParse('${r['combo_id']}')?.toInt() ?? 0,
        name: '${r['name'] ?? ''}',
        price: num.tryParse('${r['sale_price']}') ?? 0,
        stock: num.tryParse('${r['qty']}')?.toInt() ?? 0,
        barcode: '${r['barcode'] ?? ''}'.trim().toLowerCase(),
      );

  final int productId;
  final int comboId;
  final String name;
  final num price;
  final int stock;
  final String barcode;
}

class CartItem {
  CartItem({
    required this.productId,
    required this.comboId,
    required this.name,
    required this.price,
    required this.qty,
  });

  final int productId;
  final int comboId;
  final String name;
  final num price;
  int qty;
}
