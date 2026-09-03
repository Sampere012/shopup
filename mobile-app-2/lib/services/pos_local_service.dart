import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Servicio de persistencia local del POS (equivalente a localStorage en Cordova).
/// Guarda: carrito, modo de pago, clientes recientes, pedidos congelados, estado de caja.
class PosLocalService extends ChangeNotifier {
  PosLocalService._();
  static final PosLocalService I = PosLocalService._();

  static const _cartKey = 'wsm_pos_cart_v1';
  static const _payKey = 'wsm_pos_pay_v1';
  static const _custKey = 'wsm_pos_recent_customers_v1';
  static const _frozenKey = 'wsm_pos_frozen_v1';
  static const _cashKey = 'wsm_pos_cash_v1';

  // ── Carrito ──
  List<PosCartItem> cart = [];
  String cartLocationId = '';

  Future<void> loadCart() async {
    final sp = await SharedPreferences.getInstance();
    final raw = sp.getString(_cartKey);
    if (raw != null) {
      try {
        final d = jsonDecode(raw) as Map<String, dynamic>;
        cartLocationId = '${d['locId'] ?? ''}';
        cart = (d['cart'] as List?)
                ?.map((e) => PosCartItem.fromJson(e as Map<String, dynamic>))
                .toList() ??
            [];
      } catch (_) {}
    }
  }

  Future<void> saveCart() async {
    final sp = await SharedPreferences.getInstance();
    await sp.setString(_cartKey, jsonEncode({
      'locId': cartLocationId,
      'cart': cart.map((c) => c.toJson()).toList(),
    }));
    notifyListeners();
  }

  void clearCart() {
    cart.clear();
    saveCart();
  }

  int qtyInCart(PosCatalogItem p) {
    final found = cart.where((c) =>
        c.productId == p.productId && c.comboId == p.comboId).firstOrNull;
    return found?.qty ?? 0;
  }

  int availableFor(PosCatalogItem p) {
    return (p.stock - qtyInCart(p)).clamp(0, 9999);
  }

  void addToCart(PosCatalogItem p) {
    if (availableFor(p) <= 0) return;
    final existing = cart.where((c) =>
        c.productId == p.productId && c.comboId == p.comboId).firstOrNull;
    if (existing != null) {
      existing.qty++;
    } else {
      cart.add(PosCartItem(
        productId: p.productId,
        comboId: p.comboId,
        name: p.name,
        price: p.price,
        qty: 1,
      ));
    }
    saveCart();
  }

  void removeFromCart(int index) {
    if (index >= 0 && index < cart.length) {
      cart.removeAt(index);
      saveCart();
    }
  }

  void updateCartQty(int index, int qty) {
    if (index >= 0 && index < cart.length) {
      if (qty <= 0) {
        cart.removeAt(index);
      } else {
        cart[index].qty = qty;
      }
      saveCart();
    }
  }

  num get cartTotal => cart.fold(0, (a, c) => a + c.qty * c.price);
  int get cartCount => cart.fold(0, (a, c) => a + c.qty);

  // ── Modo de pago ──
  PayState payState = PayState();

  Future<void> loadPay() async {
    final sp = await SharedPreferences.getInstance();
    final raw = sp.getString(_payKey);
    if (raw != null) {
      try {
        final d = jsonDecode(raw) as Map<String, dynamic>;
        payState = PayState.fromJson(d);
      } catch (_) {}
    }
  }

  Future<void> savePay() async {
    final sp = await SharedPreferences.getInstance();
    await sp.setString(_payKey, jsonEncode(payState.toJson()));
  }

  // ── Clientes recientes ──
  List<RecentCustomer> recentCustomers = [];

  Future<void> loadRecentCustomers() async {
    final sp = await SharedPreferences.getInstance();
    final raw = sp.getString(_custKey);
    if (raw != null) {
      try {
        final list = jsonDecode(raw) as List;
        recentCustomers =
            list.map((e) => RecentCustomer.fromJson(e as Map<String, dynamic>)).toList();
      } catch (_) {}
    }
  }

  Future<void> rememberCustomer(String name, String doc, String phone) async {
    if (name.isEmpty && doc.isEmpty && phone.isEmpty) return;
    recentCustomers.removeWhere(
        (c) => c.name == name && c.doc == doc && c.phone == phone);
    recentCustomers.insert(0, RecentCustomer(name: name, doc: doc, phone: phone));
    if (recentCustomers.length > 6) {
      recentCustomers = recentCustomers.sublist(0, 6);
    }
    final sp = await SharedPreferences.getInstance();
    await sp.setString(
        _custKey, jsonEncode(recentCustomers.map((c) => c.toJson()).toList()));
  }

  // ── Pedidos congelados ──
  List<FrozenOrder> frozenOrders = [];

  Future<void> loadFrozen() async {
    final sp = await SharedPreferences.getInstance();
    final raw = sp.getString(_frozenKey);
    if (raw != null) {
      try {
        final list = jsonDecode(raw) as List;
        frozenOrders =
            list.map((e) => FrozenOrder.fromJson(e as Map<String, dynamic>)).toList();
      } catch (_) {}
    }
  }

  Future<void> saveFrozen() async {
    final sp = await SharedPreferences.getInstance();
    await sp.setString(
        _frozenKey, jsonEncode(frozenOrders.map((f) => f.toJson()).toList()));
    notifyListeners();
  }

  void addFrozen(FrozenOrder f) {
    frozenOrders.insert(0, f);
    saveFrozen();
  }

  void removeFrozen(String id) {
    frozenOrders.removeWhere((f) => f.id == id);
    saveFrozen();
  }

  // ── Estado de caja ──
  CashState cashState = CashState();

  Future<void> loadCash() async {
    final sp = await SharedPreferences.getInstance();
    final raw = sp.getString(_cashKey);
    if (raw != null) {
      try {
        final d = jsonDecode(raw) as Map<String, dynamic>;
        cashState = CashState.fromJson(d);
      } catch (_) {}
    }
  }

  Future<void> saveCash() async {
    final sp = await SharedPreferences.getInstance();
    await sp.setString(_cashKey, jsonEncode(cashState.toJson()));
    notifyListeners();
  }
}

// ── Modelos ──

class PosCartItem {
  int productId;
  int comboId;
  String name;
  num price;
  int qty;

  PosCartItem({
    required this.productId,
    required this.comboId,
    required this.name,
    required this.price,
    required this.qty,
  });

  factory PosCartItem.fromJson(Map<String, dynamic> j) => PosCartItem(
        productId: (j['product_id'] as num?)?.toInt() ?? 0,
        comboId: (j['combo_id'] as num?)?.toInt() ?? 0,
        name: '${j['name'] ?? ''}',
        price: (j['price'] as num?) ?? 0,
        qty: (j['qty'] as num?)?.toInt() ?? 1,
      );

  Map<String, dynamic> toJson() => {
        'product_id': productId,
        'combo_id': comboId,
        'name': name,
        'price': price,
        'qty': qty,
      };
}

class PosCatalogItem {
  final int productId;
  final int comboId;
  final String name;
  final num price;
  final int stock;
  final String barcode;
  final String image;

  const PosCatalogItem({
    required this.productId,
    required this.comboId,
    required this.name,
    required this.price,
    required this.stock,
    this.barcode = '',
    this.image = '',
  });

  factory PosCatalogItem.fromStockRow(Map<String, dynamic> r) => PosCatalogItem(
        productId: (r['product_id'] as num?)?.toInt() ?? 0,
        comboId: (r['combo_id'] as num?)?.toInt() ?? 0,
        name: '${r['name'] ?? ''}',
        price: (r['sale_price'] as num?) ?? 0,
        stock: (r['qty'] as num?)?.toInt() ?? 0,
        barcode: '${r['barcode'] ?? ''}'.trim().toLowerCase(),
        image: '${r['image'] ?? ''}',
      );
}

class PayState {
  String mode;
  String cashAmount;
  String cname;
  String cdoc;
  String cphone;
  String tno;

  PayState({
    this.mode = 'cash',
    this.cashAmount = '',
    this.cname = '',
    this.cdoc = '',
    this.cphone = '',
    this.tno = '',
  });

  factory PayState.fromJson(Map<String, dynamic> j) => PayState(
        mode: '${j['mode'] ?? 'cash'}',
        cashAmount: '${j['cashAmount'] ?? ''}',
        cname: '${j['cname'] ?? ''}',
        cdoc: '${j['cdoc'] ?? ''}',
        cphone: '${j['cphone'] ?? ''}',
        tno: '${j['tno'] ?? ''}',
      );

  Map<String, dynamic> toJson() => {
        'mode': mode,
        'cashAmount': cashAmount,
        'cname': cname,
        'cdoc': cdoc,
        'cphone': cphone,
        'tno': tno,
      };

  void clear() {
    mode = 'cash';
    cashAmount = '';
    cname = '';
    cdoc = '';
    cphone = '';
    tno = '';
  }
}

class RecentCustomer {
  final String name;
  final String doc;
  final String phone;

  const RecentCustomer({required this.name, required this.doc, required this.phone});

  factory RecentCustomer.fromJson(Map<String, dynamic> j) => RecentCustomer(
        name: '${j['name'] ?? ''}',
        doc: '${j['doc'] ?? ''}',
        phone: '${j['phone'] ?? ''}',
      );

  Map<String, dynamic> toJson() => {'name': name, 'doc': doc, 'phone': phone};
}

class FrozenOrder {
  final String id;
  String name;
  String doc;
  String phone;
  String note;
  String locationId;
  List<PosCartItem> items;
  String createdAt;

  FrozenOrder({
    required this.id,
    required this.name,
    required this.doc,
    required this.phone,
    this.note = '',
    required this.locationId,
    required this.items,
    required this.createdAt,
  });

  num get total => items.fold(0, (a, c) => a + c.qty * c.price);
  int get itemCount => items.fold(0, (a, c) => a + c.qty);

  factory FrozenOrder.fromJson(Map<String, dynamic> j) => FrozenOrder(
        id: '${j['id'] ?? ''}',
        name: '${j['name'] ?? ''}',
        doc: '${j['doc'] ?? ''}',
        phone: '${j['phone'] ?? ''}',
        note: '${j['note'] ?? ''}',
        locationId: '${j['location_id'] ?? ''}',
        items: (j['items'] as List?)
                ?.map((e) => PosCartItem.fromJson(e as Map<String, dynamic>))
                .toList() ??
            [],
        createdAt: '${j['created_at'] ?? ''}',
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'doc': doc,
        'phone': phone,
        'note': note,
        'location_id': locationId,
        'items': items.map((c) => c.toJson()).toList(),
        'created_at': createdAt,
      };
}

class CashState {
  bool open;
  String openedAt;
  String sellerName;
  num openingAmount;

  CashState({
    this.open = false,
    this.openedAt = '',
    this.sellerName = '',
    this.openingAmount = 0,
  });

  factory CashState.fromJson(Map<String, dynamic> j) => CashState(
        open: j['open'] == true || j['open'] == 1,
        openedAt: '${j['opened_at'] ?? ''}',
        sellerName: '${j['seller_name'] ?? ''}',
        openingAmount: (j['opening_amount'] as num?) ?? 0,
      );

  Map<String, dynamic> toJson() => {
        'open': open,
        'opened_at': openedAt,
        'seller_name': sellerName,
        'opening_amount': openingAmount,
      };
}
