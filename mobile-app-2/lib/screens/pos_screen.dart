import 'dart:async';
import 'package:collection/collection.dart';
import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../theme/app_animations.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../services/pos_local_service.dart';
import '../widgets/common.dart';

/// POS completo: catálogo con imágenes, carrito persistente con vuelto,
/// pedidos congelados, selector de cliente, caja, escáner QR, responsive.
class PosScreen extends StatefulWidget {
  const PosScreen({super.key});
  @override
  State<PosScreen> createState() => _PosScreenState();
}

class _PosScreenState extends State<PosScreen> {
  List<Map<String, dynamic>> _locations = [];
  String _loc = '';
  String _q = '';
  List<PosCatalogItem> catalog = [];
  bool loading = true;
  Timer? _reloadDebounce;
  // Densidad de la grilla (pellizco tipo galería). 0 = automático por ancho.
  int _cols = 0;
  // Controladores persistentes del pago por transferencia. Evitan que el
  // cursor salte al inicio al recrearse el TextField en cada rebuild.
  final _nameCtrl = TextEditingController();
  final _docCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  final _tnoCtrl = TextEditingController();
  bool _payCtrlsReady = false;

  void _initPayCtrls() {
    if (_payCtrlsReady) return;
    final pos = PosLocalService.I;
    _nameCtrl.text = pos.payState.cname;
    _docCtrl.text = pos.payState.cdoc;
    _phoneCtrl.text = pos.payState.cphone;
    _tnoCtrl.text = pos.payState.tno;
    _payCtrlsReady = true;
  }

  @override
  void initState() {
    super.initState();
    SyncService.I.onChange(_onSyncChanged);
    SharedPreferences.getInstance().then((sp) {
      if (!mounted) return;
      setState(() => _cols = sp.getInt('wsm_density_pos') ?? 0);
    });
    _bootstrap();
  }

  Future<void> _setCols(int v) async {
    if (v == _cols) return;
    setState(() => _cols = v);
    final sp = await SharedPreferences.getInstance();
    await sp.setInt('wsm_density_pos', v);
  }

  @override
  void dispose() {
    _reloadDebounce?.cancel();
    _nameCtrl.dispose();
    _docCtrl.dispose();
    _phoneCtrl.dispose();
    _tnoCtrl.dispose();
    super.dispose();
  }

  Future<void> _bootstrap() async {
    // Load persisted state
    await PosLocalService.I.loadCart();
    await PosLocalService.I.loadPay();
    await PosLocalService.I.loadRecentCustomers();
    await PosLocalService.I.loadFrozen();
    await PosLocalService.I.loadCash();

    var allLocs = (await DbService.I.all('locations'))
        .where((l) => '${l['pos_enabled']}' != '0' && '${l['pos_enabled']}' != 'false')
        .toList();
    // Filtrar por ubicaciones del trabajador si tiene asignadas
    final workerLocs = AuthService.I.locationIds;
    if (workerLocs.isNotEmpty) {
      allLocs = allLocs
          .where((l) => workerLocs.contains(int.tryParse('${l['id']}') ?? 0))
          .toList();
    }
    _locations = allLocs;
    if (_locations.isNotEmpty) {
      _loc = PosLocalService.I.cartLocationId.isNotEmpty
          ? PosLocalService.I.cartLocationId
          : '${_locations.first['id']}';
      PosLocalService.I.cartLocationId = _loc;
    }
    await _loadCatalog();
    if (mounted) setState(() => loading = false);
  }

  Future<void> _loadCatalog() async {
    if (_loc.isEmpty) return;
    try {
      var rows = await DbService.I.all('stock');
      rows = rows.where((r) => '${r['location_id']}' == _loc).toList()
        ..sort((a, b) => '${a['name'] ?? ''}'.compareTo('${b['name'] ?? ''}'));
      catalog = rows.map(PosCatalogItem.fromStockRow).toList();
      if (!mounted) return;
      setState(() {});
    } catch (_) {}
  }

  void _onSyncChanged() {
    _reloadDebounce?.cancel();
    _reloadDebounce = Timer(const Duration(milliseconds: 250), () {
      if (mounted) _loadCatalog();
    });
  }

  // ── Scanner ──
  Future<void> _scanCode() async {
    final code = await Navigator.of(context)
        .push<String>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => const ScannerPage()));
    if (code == null || code.isEmpty || !mounted) return;
    final hit = catalog.where((p) => p.barcode == code.trim().toLowerCase()).firstOrNull;
    if (hit != null) {
      PosLocalService.I.addToCart(hit);
      setState(() {});
      return;
    }
    U.toast(context, 'No se encontró el código $code', kind: 'err');
  }

  // ── Frozen Orders (with inline edit) ──
  void _openFrozenList() {
    final cur = AuthService.I.currency;
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setS) {
          final frozen = PosLocalService.I.frozenOrders;
          return Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                  Text('Pedidos congelados (${frozen.length})',
                      style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                  Text('${frozen.fold<int>(0, (a, f) => a + f.itemCount)} items',
                      style: TextStyle(color: Colors.grey[600], fontSize: 13)),
                ]),
                const SizedBox(height: 12),
                if (frozen.isEmpty)
                  const Padding(
                    padding: EdgeInsets.all(24),
                    child: Text('No hay pedidos congelados.', textAlign: TextAlign.center),
                  )
                else
                  Flexible(
                    child: ListView.builder(
                      shrinkWrap: true,
                      itemCount: frozen.length,
                      itemBuilder: (context, i) {
                        final f = frozen[i];
                        return Card(
                          child: ExpansionTile(
                            leading: const Icon(Icons.ac_unit, color: AppTheme.primary),
                            title: Text(f.name, style: const TextStyle(fontWeight: FontWeight.w600)),
                            subtitle: Text(
                                'C.I. ${f.doc} · ${f.phone} · ${f.itemCount} ítems · ${U.money(f.total, cur)}',
                                style: TextStyle(color: Colors.grey[600], fontSize: 12)),
                            children: [
                              // Inline items edit
                              for (int j = 0; j < f.items.length; j++)
                                ListTile(
                                  dense: true,
                                  title: Text(f.items[j].name, style: const TextStyle(fontSize: 13)),
                                  subtitle: Text(U.money(f.items[j].price, cur),
                                      style: TextStyle(fontSize: 11, color: Colors.grey[600])),
                                  trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                                    IconButton(
                                      icon: const Icon(Icons.remove_circle_outline, size: 18),
                                      onPressed: () {
                                        setS(() {
                                          if (f.items[j].qty > 1) f.items[j].qty--;
                                          else f.items.removeAt(j--);
                                          if (f.items.isEmpty) {
                                            PosLocalService.I.removeFrozen(f.id);
                                          } else {
                                            PosLocalService.I.saveFrozen();
                                          }
                                        });
                                      },
                                    ),
                                    Text('${f.items[j].qty}',
                                        style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                                    IconButton(
                                      icon: const Icon(Icons.add_circle_outline, size: 18),
                                      onPressed: () {
                                        setS(() {
                                          f.items[j].qty++;
                                          PosLocalService.I.saveFrozen();
                                        });
                                      },
                                    ),
                                  ]),
                                ),
                              // Customer info fields
                              Padding(
                                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                                child: Row(children: [
                                  Expanded(child: TextFormField(
                                    initialValue: f.name,
                                    decoration: const InputDecoration(labelText: 'Nombre', isDense: true, contentPadding: EdgeInsets.symmetric(horizontal: 8, vertical: 8)),
                                    style: const TextStyle(fontSize: 12),
                                    onChanged: (v) { f.name = v; PosLocalService.I.saveFrozen(); },
                                  )),
                                  const SizedBox(width: 8),
                                  Expanded(child: TextFormField(
                                    initialValue: f.doc,
                                    decoration: const InputDecoration(labelText: 'Carnet', isDense: true, contentPadding: EdgeInsets.symmetric(horizontal: 8, vertical: 8)),
                                    style: const TextStyle(fontSize: 12),
                                    onChanged: (v) { f.doc = v; PosLocalService.I.saveFrozen(); },
                                  )),
                                ]),
                              ),
                              // Actions row
                              Padding(
                                padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
                                child: Row(children: [
                                  Expanded(child: OutlinedButton.icon(
                                    onPressed: () {
                                      Navigator.pop(ctx);
                                      _unfreeze(f);
                                    },
                                    icon: const Icon(Icons.thermostat, size: 16),
                                    label: const Text('Descongelar'),
                                  )),
                                  const SizedBox(width: 8),
                                  IconButton(
                                    icon: const Icon(Icons.delete_outline, size: 20, color: AppTheme.danger),
                                    onPressed: () {
                                      PosLocalService.I.removeFrozen(f.id);
                                      setS(() {});
                                    },
                                  ),
                                ]),
                              ),
                            ],
                          ),
                        );
                      },
                    ),
                  ),
                const SizedBox(height: 8),
                TextButton(
                    onPressed: () => Navigator.pop(ctx), child: const Text('Cerrar')),
              ],
            ),
          );
        },
      ),
    );
  }

  void _unfreeze(FrozenOrder f) {
    int added = 0;
    for (final it in f.items) {
      final found = catalog
          .where((x) => x.productId == it.productId && x.comboId == it.comboId)
          .firstOrNull;
      if (found == null) continue;
      final avail = PosLocalService.I.availableFor(found);
      if (avail <= 0) continue;
      final qty = [it.qty, avail].reduce((a, b) => a < b ? a : b);
      final existing = PosLocalService.I.cart
          .where((c) => c.productId == found.productId && c.comboId == found.comboId)
          .firstOrNull;
      if (existing != null) {
        existing.qty += qty;
      } else {
        PosLocalService.I.cart.add(PosCartItem(
            productId: found.productId, comboId: found.comboId,
            name: found.name, price: found.price, qty: qty));
      }
      added++;
    }
    if (added == 0) {
      if (mounted) U.toast(context, 'Productos no disponibles', kind: 'err');
      return;
    }
    PosLocalService.I.payState.cname = f.name;
    PosLocalService.I.payState.cdoc = f.doc;
    PosLocalService.I.payState.cphone = f.phone;
    PosLocalService.I.savePay();
    PosLocalService.I.removeFrozen(f.id);
    PosLocalService.I.saveCart();
    if (mounted) {
      U.toast(context, 'Pedido descongelado: revisa el carrito');
      setState(() {});
    }
  }

  void _freezeOrder() {
    final pos = PosLocalService.I;
    if (pos.cart.isEmpty) {
      U.toast(context, 'El carrito está vacío', kind: 'err');
      return;
    }
    final cname = TextEditingController(text: pos.payState.cname);
    final cdoc = TextEditingController(text: pos.payState.cdoc);
    final cphone = TextEditingController(text: pos.payState.cphone);
    final cnote = TextEditingController();

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => Padding(
        padding: EdgeInsets.only(
            left: 18, right: 18, top: 16,
            bottom: MediaQuery.of(ctx).viewInsets.bottom + 18),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Text('Congelar pedido', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
          const SizedBox(height: 12),
          TextField(controller: cname, decoration: const InputDecoration(labelText: 'Nombre *')),
          TextField(controller: cdoc, decoration: const InputDecoration(labelText: 'Carnet / Cédula *')),
          TextField(controller: cphone, decoration: const InputDecoration(labelText: 'Teléfono *')),
          TextField(controller: cnote, decoration: const InputDecoration(labelText: 'Nota')),
          const SizedBox(height: 14),
          FilledButton(
            onPressed: () {
              if (cname.text.trim().isEmpty || cdoc.text.trim().isEmpty || cphone.text.trim().isEmpty) {
                U.toast(context, 'Completa: nombre, carnet y teléfono', kind: 'err');
                return;
              }
              final f = FrozenOrder(
                id: 'f_${DateTime.now().millisecondsSinceEpoch}_${DateTime.now().microsecond}',
                name: cname.text.trim(), doc: cdoc.text.trim(), phone: cphone.text.trim(),
                note: cnote.text.trim(), locationId: _loc,
                items: List.from(pos.cart),
                createdAt: DateTime.now().toIso8601String(),
              );
              pos.addFrozen(f);
              pos.clearCart();
              pos.payState.clear();
              pos.savePay();
              Navigator.pop(ctx);
              U.toast(context, 'Pedido congelado: ${f.name}');
              setState(() {});
            },
            child: const Text('Congelar'),
          ),
        ]),
      ),
    );
  }

  // ── Cash Register ──
  void _openCashModal() {
    final cash = PosLocalService.I.cashState;
    final openingCtrl = TextEditingController();
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setS) => Padding(
          padding: EdgeInsets.only(
              left: 18, right: 18, top: 16,
              bottom: MediaQuery.of(ctx).viewInsets.bottom + 18),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text(cash.open ? 'Caja abierta' : 'Abrir caja',
                style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
            const SizedBox(height: 12),
            if (cash.open) ...[
              Text('Abierta desde ${cash.openedAt}', style: TextStyle(color: Colors.grey[600])),
              Text('Vendedor: ${cash.sellerName}', style: TextStyle(color: Colors.grey[600])),
              Text('Apertura: ${cash.openingAmount}', style: TextStyle(color: Colors.grey[600])),
              const SizedBox(height: 12),
              FilledButton(
                style: FilledButton.styleFrom(backgroundColor: AppTheme.danger),
                onPressed: () async {
                  await SyncService.I.push('ws_pos_cash_close', {'location_id': _loc});
                  PosLocalService.I.cashState = CashState();
                  PosLocalService.I.saveCash();
                  Navigator.pop(ctx);
                  U.toast(context, 'Caja cerrada');
                },
                child: const Text('Cerrar caja'),
              ),
            ] else ...[
              TextField(
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(labelText: 'Monto de apertura'),
                controller: openingCtrl,
              ),
              const SizedBox(height: 12),
              FilledButton(
                onPressed: () async {
                  final amount = num.tryParse(openingCtrl.text.trim()) ?? 0;
                  await SyncService.I.push('ws_pos_cash_open', {
                    'location_id': _loc,
                    'opening_amount': amount,
                    'note': '',
                  });
                  PosLocalService.I.cashState = CashState(
                    open: true,
                    openedAt: DateTime.now().toString(),
                    sellerName: '${AuthService.I.me?['name'] ?? ''}',
                    openingAmount: amount,
                  );
                  PosLocalService.I.saveCash();
                  Navigator.pop(ctx);
                  U.toast(context, 'Caja abierta');
                },
                child: const Text('Abrir caja'),
              ),
            ],
          ]),
        ),
      ),
    );
  }

  // ── Customer Picker (live search from SQLite + recent) ──
  void _openCustomerPicker(void Function()? onPicked) {
    final pos = PosLocalService.I;
    List<Map<String, dynamic>> dbCustomers = [];
    String query = '';

    void applyCustomer({String name = '', String doc = '', String phone = ''}) {
      pos.payState.cname = name;
      pos.payState.cdoc = doc;
      pos.payState.cphone = phone;
      pos.savePay();
      _nameCtrl.text = name;
      _docCtrl.text = doc;
      _phoneCtrl.text = phone;
      onPicked?.call();
    }

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setS) {
          // Load from SQLite on first build
          if (dbCustomers.isEmpty) {
            DbService.I.all('customers').then((rows) {
              if (ctx.mounted) setS(() => dbCustomers = rows);
            });
          }

          // Filter
          final filteredDb = query.isEmpty ? dbCustomers : dbCustomers.where((c) {
            final q = query.toLowerCase();
            return '${c['name'] ?? ''}'.toLowerCase().contains(q) ||
                   '${c['phone'] ?? ''}'.contains(q) ||
                   '${c['email'] ?? ''}'.toLowerCase().contains(q);
          }).toList();

          final filteredRecent = query.isEmpty ? pos.recentCustomers : pos.recentCustomers.where((c) {
            final q = query.toLowerCase();
            return c.name.toLowerCase().contains(q) || c.phone.contains(q);
          }).toList();

          return Padding(
            padding: EdgeInsets.only(
                left: 16, right: 16, top: 16,
                bottom: MediaQuery.of(ctx).viewInsets.bottom + 16),
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const Text('Seleccionar cliente',
                  style: TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
              const SizedBox(height: 8),
              // Search field
              TextField(
                decoration: const InputDecoration(
                  hintText: 'Buscar por nombre o teléfono…',
                  prefixIcon: Icon(Icons.search, size: 20),
                  isDense: true,
                ),
                autofocus: true,
                onChanged: (v) => setS(() => query = v.trim()),
              ),
              const SizedBox(height: 4),
              Text('${filteredRecent.length} recientes · ${filteredDb.length} en base',
                  style: TextStyle(color: Colors.grey[600], fontSize: 11)),
              const SizedBox(height: 8),
              Flexible(
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    // Recent customers
                    if (filteredRecent.isNotEmpty) ...[
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 4),
                        child: Text('RECIENTES', style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: Colors.grey[500], letterSpacing: 1)),
                      ),
                      ...filteredRecent.map((c) => ListTile(
                        dense: true,
                        leading: CircleAvatar(backgroundColor: AppTheme.primary.withAlpha(25),
                            child: Text(c.name.isNotEmpty ? c.name[0].toUpperCase() : '?',
                                style: const TextStyle(color: AppTheme.primary, fontSize: 12))),
                        title: Text(c.name, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                        subtitle: Text('${c.doc} · ${c.phone}', style: TextStyle(color: Colors.grey[600], fontSize: 11)),
                        onTap: () {
                          applyCustomer(
                              name: c.name, doc: c.doc, phone: c.phone);
                          Navigator.pop(ctx);
                          U.toast(context, 'Cliente cargado: ${c.name}');
                        },
                      )),
                    ],
                    // DB customers
                    if (filteredDb.isNotEmpty) ...[
                      Padding(
                        padding: const EdgeInsets.only(top: 8, bottom: 4),
                        child: Text('BASE DE DATOS', style: TextStyle(fontSize: 10, fontWeight: FontWeight.w700, color: Colors.grey[500], letterSpacing: 1)),
                      ),
                      ...filteredDb.take(50).map((c) => ListTile(
                        dense: true,
                        leading: CircleAvatar(backgroundColor: AppTheme.success.withAlpha(25),
                            child: Text('${('${c['name'] ?? '?'}'.isNotEmpty ? '${c['name']}'[0] : '?').toUpperCase()}',
                                style: const TextStyle(color: AppTheme.success, fontSize: 12))),
                        title: Text('${c['name'] ?? ''}', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                        subtitle: Text('${c['phone'] ?? ''} · ${c['email'] ?? ''}', style: TextStyle(color: Colors.grey[600], fontSize: 11)),
                        onTap: () {
                          applyCustomer(
                              name: '${c['name'] ?? ''}',
                              doc: '${c['document'] ?? c['doc'] ?? ''}',
                              phone: '${c['phone'] ?? ''}');
                          Navigator.pop(ctx);
                          U.toast(context, 'Cliente cargado: ${c['name'] ?? ''}');
                        },
                      )),
                    ],
                    if (filteredDb.isEmpty && filteredRecent.isEmpty)
                      const Padding(padding: EdgeInsets.all(24), child: Text('Sin coincidencias.', textAlign: TextAlign.center)),
                  ],
                ),
              ),
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cerrar')),
            ]),
          );
        },
      ),
    );
  }

  // ── Cart Modal with change calculation ──
  void _openCart() {
    final pos = PosLocalService.I;
    final me = AuthService.I;
    final cur = me.currency;
    _initPayCtrls();

    // Autollenado: "Recibido" arranca igual al total (editable a mano).
    bool cashManual = false;
    if ((num.tryParse(pos.payState.cashAmount) ?? -1) != pos.cartTotal) {
      pos.payState.cashAmount = pos.cartTotal.toStringAsFixed(2);
      pos.savePay();
    }
    final cashCtrl = TextEditingController(text: pos.payState.cashAmount);

    // Controladores de cantidad editables por ítem del carrito.
    final qtyCtrls = <String, TextEditingController>{};

    // Re-sincroniza los campos de cantidad con el carrito real (tras +/-).
    void syncQtyCtrls() {
      for (final c in pos.cart) {
        final ctl = qtyCtrls['${c.productId}:${c.comboId}'];
        if (ctl != null && ctl.text != '${c.qty}') ctl.text = '${c.qty}';
      }
    }

    // Ejecuta una mutación del carrito y re-sincroniza "Recibido" con el
    // nuevo total salvo que el usuario lo haya editado manualmente.
    void bump(void Function() mutate, StateSetter setSheet) {
      mutate();
      if (!cashManual) {
        pos.payState.cashAmount = pos.cartTotal.toStringAsFixed(2);
        pos.savePay();
        cashCtrl.text = pos.payState.cashAmount;
      }
      setSheet(() {});
    }

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      builder: (sheetCtx) => StatefulBuilder(
        builder: (sheetCtx, setSheet) {
          final total = pos.cartTotal;
          final received = num.tryParse(pos.payState.cashAmount) ?? 0;
          final change = received - total;

          return Padding(
            padding: EdgeInsets.only(
                left: 18, right: 18, top: 16,
                bottom: MediaQuery.of(sheetCtx).viewInsets.bottom + 18),
            child: ConstrainedBox(
              constraints: BoxConstraints(
                maxHeight: MediaQuery.of(sheetCtx).size.height * 0.82,
              ),
              child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // Header (fixed)
                    Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                      Text('Carrito (${pos.cartCount})',
                          style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
                      Text(U.money(total, cur),
                          style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: AppTheme.success)),
                    ]),
                    const SizedBox(height: 8),
                    // Cart items (scrollable)
                    if (pos.cart.isNotEmpty)
                      Flexible(
                        child: ListView.separated(
                          shrinkWrap: true,
                          padding: EdgeInsets.zero,
                          itemCount: pos.cart.length,
                          separatorBuilder: (_, __) => const Divider(height: 1),
                          itemBuilder: (ctx, i) {
                            final c = pos.cart[i];
                            final p = catalog.where((x) => x.productId == c.productId && x.comboId == c.comboId).firstOrNull;
                            final canAdd = p != null && PosLocalService.I.availableFor(p) > 0;
                            final qtyCtrl = qtyCtrls.putIfAbsent(
                                '${c.productId}:${c.comboId}',
                                () => TextEditingController(text: '${c.qty}'));
                            return Padding(
                              padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 2),
                              child: Row(children: [
                                // Qty controls
                                Container(
                                  decoration: BoxDecoration(
                                    color: AppTheme.primary.withAlpha(15),
                                    borderRadius: BorderRadius.circular(8),
                                  ),
                                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                                    IconButton(
                                        icon: const Icon(Icons.remove_circle_outline, size: 20),
                                        onPressed: () => bump(() {
                                              pos.updateCartQty(i, c.qty - 1);
                                              syncQtyCtrls();
                                            }, setSheet),
                                        constraints: const BoxConstraints(minWidth: 32, minHeight: 32)),
                                    // Cantidad editable a mano (o con +/-)
                                    SizedBox(
                                      width: 34,
                                      child: TextField(
                                        controller: qtyCtrl,
                                        keyboardType: TextInputType.number,
                                        textAlign: TextAlign.center,
                                        style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13),
                                        decoration: const InputDecoration(
                                          isDense: true,
                                          border: InputBorder.none,
                                          contentPadding: EdgeInsets.zero,
                                        ),
                                        onChanged: (v) {
                                          final q = int.tryParse(v.trim()) ?? 0;
                                          if (q < 1 || q == c.qty) return;
                                          var maxQty = c.qty;
                                          if (p != null) maxQty = c.qty + PosLocalService.I.availableFor(p);
                                          final nq = q > maxQty ? maxQty : q;
                                          if (nq == c.qty) {
                                            qtyCtrl.text = '$nq';
                                            return;
                                          }
                                          bump(() => pos.updateCartQty(i, nq), setSheet);
                                        },
                                        onSubmitted: (v) {
                                          if (int.tryParse(v.trim()) == null || (int.tryParse(v.trim()) ?? 0) < 1) {
                                            qtyCtrl.text = '${c.qty}';
                                          }
                                        },
                                      ),
                                    ),
                                    IconButton(
                                        icon: Icon(Icons.add_circle_outline, size: 20,
                                            color: canAdd ? AppTheme.primary : Colors.grey.shade400),
                                        onPressed: canAdd
                                            ? () => bump(() {
                                                  pos.updateCartQty(i, c.qty + 1);
                                                  syncQtyCtrls();
                                                }, setSheet)
                                            : null,
                                        constraints: const BoxConstraints(minWidth: 32, minHeight: 32)),
                                  ]),
                                ),
                                const SizedBox(width: 8),
                                // Name + price (read-only) + subtotal
                                Expanded(
                                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                                    Text(c.name, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                                        maxLines: 1, overflow: TextOverflow.ellipsis),
                                    Row(children: [
                                      Text('${U.money(c.price, cur)} × ${c.qty} = ',
                                          style: TextStyle(fontSize: 11, color: Colors.grey[500])),
                                      Text(U.money(c.price * c.qty, cur),
                                          style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700)),
                                    ]),
                                  ]),
                                ),
                                // Delete
                                IconButton(
                                    icon: const Icon(Icons.delete_outline, size: 18, color: AppTheme.danger),
                                    onPressed: () => bump(() => pos.removeFromCart(i), setSheet)),
                              ]),
                            );
                          },
                        ),
                      ),
                    if (pos.cart.isEmpty) ...[
                      const Padding(
                          padding: EdgeInsets.all(24),
                          child: Text('Carrito vacío.', textAlign: TextAlign.center)),
                      FilledButton(
                        onPressed: () => Navigator.pop(sheetCtx),
                        child: const Text('Cerrar'),
                      ),
                    ],
                    if (pos.cart.isNotEmpty) const Divider(),
                    // Payment mode tabs (fixed)
                    SegmentedButton<String>(
                      segments: const [
                        ButtonSegment(value: 'cash', label: Text('Efectivo'), icon: Icon(Icons.payments_outlined)),
                        ButtonSegment(value: 'transfer', label: Text('Transferencia'), icon: Icon(Icons.account_balance_outlined)),
                      ],
                      selected: {pos.payState.mode},
                      onSelectionChanged: (s) => setSheet(() {
                        pos.payState.mode = s.first;
                        pos.savePay();
                      }),
                    ),
                    // Payment details (scrollable within remaining space)
                    if (pos.payState.mode == 'cash') ...[
                      const SizedBox(height: 8),
                      TextField(
                        keyboardType: TextInputType.number,
                        decoration: InputDecoration(
                          labelText: 'Recibido',
                          hintText: total.toStringAsFixed(2),
                        ),
                        controller: cashCtrl,
                        onChanged: (v) {
                          cashManual = true;
                          pos.payState.cashAmount = v;
                          pos.savePay();
                          setSheet(() {});
                        },
                      ),
                      if (received > 0)
                        Padding(
                          padding: const EdgeInsets.only(top: 4),
                          child: Text(
                            change >= 0 ? 'Vuelto: ${U.money(change, cur)}' : 'Faltan: ${U.money(-change, cur)}',
                            style: TextStyle(
                              fontWeight: FontWeight.w700,
                              color: change >= 0 ? AppTheme.success : AppTheme.danger,
                            ),
                          ),
                        ),
                    ],
                    if (pos.payState.mode == 'transfer') ...[
                      const SizedBox(height: 8),
                      // Customer picker button
                      OutlinedButton.icon(
                        onPressed: () => _openCustomerPicker(() => setSheet(() {})),
                        icon: const Icon(Icons.people_outline, size: 18),
                        label: const Text('Seleccionar cliente'),
                      ),
                      const SizedBox(height: 8),
                      SizedBox(
                        height: 40,
                        child: TextField(
                          decoration: const InputDecoration(labelText: 'Nombre del cliente *', isDense: true, contentPadding: EdgeInsets.fromLTRB(12, 10, 12, 10)),
                          controller: _nameCtrl,
                          onChanged: (v) { pos.payState.cname = v; pos.savePay(); },
                        ),
                      ),
                      const SizedBox(height: 4),
                      SizedBox(
                        height: 40,
                        child: TextField(
                          decoration: const InputDecoration(labelText: 'Carnet / Cédula *', isDense: true, contentPadding: EdgeInsets.fromLTRB(12, 10, 12, 10)),
                          controller: _docCtrl,
                          onChanged: (v) { pos.payState.cdoc = v; pos.savePay(); },
                        ),
                      ),
                      const SizedBox(height: 4),
                      SizedBox(
                        height: 40,
                        child: TextField(
                          decoration: const InputDecoration(labelText: 'Teléfono *', isDense: true, contentPadding: EdgeInsets.fromLTRB(12, 10, 12, 10)),
                          controller: _phoneCtrl,
                          keyboardType: TextInputType.phone,
                          onChanged: (v) { pos.payState.cphone = v; pos.savePay(); },
                        ),
                      ),
                      const SizedBox(height: 4),
                      SizedBox(
                        height: 40,
                        child: TextField(
                          decoration: const InputDecoration(labelText: 'Nº de transferencia *', isDense: true, contentPadding: EdgeInsets.fromLTRB(12, 10, 12, 10)),
                          controller: _tnoCtrl,
                          onChanged: (v) { pos.payState.tno = v; pos.savePay(); },
                        ),
                      ),
                    ],
                    const SizedBox(height: 14),
                    // Action buttons (fixed at bottom)
                    Row(children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: pos.cart.isEmpty ? null : _freezeOrder,
                          icon: const Icon(Icons.ac_unit, size: 18),
                          label: const Text('Congelar'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: FilledButton.icon(
                          onPressed: pos.cart.isEmpty ? null : () => _checkout(sheetCtx),
                          icon: const Icon(Icons.check_circle_outline, size: 18),
                          label: const Text('Cobrar'),
                        ),
                      ),
                    ]),
                  ]),
            ),
          );
        },
      ),
    );
  }

  Future<void> _checkout(BuildContext sheetCtx) async {
    final pos = PosLocalService.I;
    final me = AuthService.I;
    final total = pos.cartTotal;
    final mode = pos.payState.mode;
    final cur = me.currency;

    if (mode == 'cash') {
      final received = num.tryParse(pos.payState.cashAmount) ?? 0;
      if (received < total - 0.001) {
        U.toast(context, 'Monto insuficiente: faltan ${U.money(total - received, cur)}', kind: 'err');
        return;
      }
    } else {
      if (pos.payState.cname.trim().isEmpty || pos.payState.cdoc.trim().isEmpty ||
          pos.payState.cphone.trim().isEmpty || pos.payState.tno.trim().isEmpty) {
        U.toast(context, 'Completa: nombre, carnet, teléfono y nº transferencia', kind: 'err');
        return;
      }
    }

    final cashReceived = mode == 'cash' ? (num.tryParse(pos.payState.cashAmount) ?? 0) : 0;

    final payload = <String, dynamic>{
      'location_id': num.tryParse(_loc) ?? 0,
      'seller_id': me.userId,
      'currency': cur,
      'subtotal': total.toStringAsFixed(2),
      'discount': 0,
      'total': total.toStringAsFixed(2),
      'payment_method': mode,
      'cash_amount': mode == 'cash' ? cashReceived.toStringAsFixed(2) : 0,
      'transfer_amount': mode == 'transfer' ? total.toStringAsFixed(2) : 0,
      'transfer_number': mode == 'transfer' ? pos.payState.tno : '',
      'customer_name': mode == 'transfer' ? pos.payState.cname : '',
      'customer_doc': mode == 'transfer' ? pos.payState.cdoc : '',
      'customer_phone': mode == 'transfer' ? pos.payState.cphone : '',
      'register_id': pos.cashState.open ? 1 : 0,
      'client_ref': 'app-${DateTime.now().millisecondsSinceEpoch}',
      'items': pos.cart.map((c) => {
        'product_id': c.productId, 'combo_id': c.comboId,
        'product_name': c.name, 'qty': c.qty, 'price': c.price,
        'subtotal': c.qty * c.price,
      }).toList(),
      'ws_offline_sync': 1,
    };

    final localSale = <String, dynamic>{
      'id': 'pending_${DateTime.now().millisecondsSinceEpoch}',
      'location_id': num.tryParse(_loc) ?? 0,
      'seller_id': me.userId,
      'seller_name': '${me.me?['name'] ?? ''}',
      'currency': cur,
      'subtotal': total.toStringAsFixed(2),
      'discount': '0',
      'total': total.toStringAsFixed(2),
      'payment_method': mode,
      'cash_amount': mode == 'cash' ? cashReceived.toStringAsFixed(2) : '0',
      'transfer_amount': mode == 'transfer' ? total.toStringAsFixed(2) : '0',
      'transfer_number': mode == 'transfer' ? pos.payState.tno : '',
      'customer_name': mode == 'transfer' ? pos.payState.cname : '',
      'status': 'pending',
      'created_at': DateTime.now().toIso8601String(),
      'items': payload['items'],
    };

dynamic res;
    bool pushOk = false;
    try {
      res = await SyncService.I.push('ws_pos_sale_save', payload);
      pushOk = true;
    } catch (_) {
      if (context.mounted) {
        U.toast(context, 'No se pudo guardar la venta', kind: 'err');
      }
    }
    if (!pushOk) return;

    final queued = res is Map && res['queued'] == true;
    // En online, `res` es el `data` de la respuesta: {sale_id, ...}.
    final realId = (res is Map) ? (res['sale_id'] ?? res['data']?['sale_id']) : null;
    if (realId != null) localSale['id'] = realId;
    if (queued) {
      if (context.mounted) {
        U.toast(context, 'Guardado sin conexión — se enviará al reconectar',
            kind: 'warn');
      }
    } else {
      if (context.mounted) U.toast(context, 'Venta registrada');
      localSale['status'] = 'completed';
    }
    // Reflectir la venta en el historial local (cache + tabla), con el id real
    // del servidor cuando esté disponible, para que el detalle cargue sus ítems.
    final salesCache = await DbService.I.cacheGet('ws_pos_sales_get_all');
    final list = salesCache is List
        ? List<Map<String, dynamic>>.from(salesCache)
        : <Map<String, dynamic>>[];
    list.removeWhere((s) => '${s['id']}' == '${localSale['id']}');
    list.insert(0, localSale);
    await DbService.I.cacheSet('ws_pos_sales_get', list);
    await DbService.I.cacheSet('ws_pos_sales_get_all', list);
    await DbService.I.putAll('pos_sales', list);

    // Adjust local stock
    for (final c in pos.cart) {
      await SyncService.I.adjustLocalStock(_loc, c.productId, -c.qty);
    }

    // Remember customer for transfers
    if (mode == 'transfer') {
      await pos.rememberCustomer(pos.payState.cname, pos.payState.cdoc, pos.payState.cphone);
    }

    pos.clearCart();
    pos.payState.clear();
    pos.savePay();
    if (sheetCtx.mounted) Navigator.pop(sheetCtx);
    await _loadCatalog();
    setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    // Reconstruye toda la pantalla (barra flotante del carrito, pills de
    // stock, etc.) cuando el carrito cambia desde cualquier parte —
    // incluidas las eliminaciones hechas dentro del modal del carrito.
    context.watch<PosLocalService>();
    if (loading) return const Center(child: CircularProgressIndicator());
    if (_locations.isEmpty) {
      return Center(child: Text('No tienes ubicaciones con POS activo.',
          style: TextStyle(color: Colors.grey[600])));
    }
    final cur = AuthService.I.currency;
    final rows = _q.isEmpty
        ? catalog
        : catalog.where((p) => p.name.toLowerCase().contains(_q.toLowerCase())).toList();
    final screenWidth = MediaQuery.of(context).size.width;
    final crossCount =
        _cols > 0 ? _cols : (screenWidth > 900 ? 5 : screenWidth > 600 ? 4 : 3);
    final pos = PosLocalService.I;

    return Stack(children: [
      Column(children: [
        // Location selector
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 6),
          child: DropdownButtonFormField<String>(
            initialValue: _loc,
            isDense: true,
            items: _locations.map((l) => DropdownMenuItem(value: '${l['id']}', child: Text('${l['name']}'))).toList(),
            onChanged: (v) {
              if (v != null) { _loc = v; pos.cartLocationId = v; pos.saveCart(); _loadCatalog(); }
            },
          ),
        ),
        // Search + tools
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 4, 14, 6),
          child: Row(children: [
            Expanded(child: TextField(
              decoration: const InputDecoration(hintText: 'Buscar producto…', prefixIcon: Icon(Icons.search), isDense: true),
              onChanged: (v) => setState(() => _q = v),
            )),
            const SizedBox(width: 6),
            // Frozen badge
            Stack(clipBehavior: Clip.none, children: [
              IconButton.filledTonal(
                onPressed: _openFrozenList,
                tooltip: 'Pedidos congelados',
                icon: const Icon(Icons.ac_unit, size: 20),
              ),
              if (pos.frozenOrders.isNotEmpty)
                Positioned(right: -2, top: -2, child: Container(
                  padding: const EdgeInsets.all(4),
                  constraints: const BoxConstraints(minWidth: 18, minHeight: 18),
                  decoration: const BoxDecoration(color: AppTheme.primary, shape: BoxShape.circle),
                  child: Text('${pos.frozenOrders.length}', style: const TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.w800)),
                )),
            ]),
            // Cash register
            IconButton.filledTonal(
              onPressed: _openCashModal,
              tooltip: pos.cashState.open ? 'Caja abierta' : 'Abrir caja',
              icon: Icon(Icons.point_of_sale, size: 20,
                  color: pos.cashState.open ? AppTheme.success : null),
            ),
            // Scanner
            IconButton.filledTonal(
              onPressed: _scanCode,
              tooltip: 'Escanear código / QR',
              icon: const Icon(Icons.qr_code_scanner),
            ),
          ]),
        ),
        // Product grid
        Expanded(
          child: PinchDensity(
            cols: crossCount,
            minCols: 2,
            maxCols: 3,
            onChanged: _setCols,
            child: GridView.builder(
              padding: const EdgeInsets.fromLTRB(14, 4, 14, 100),
              cacheExtent: 300,
              gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: crossCount, mainAxisSpacing: 8, crossAxisSpacing: 8, mainAxisExtent: 150,
              ),
              itemCount: rows.length,
            itemBuilder: (context, i) {
              final p = rows[i];
              final avail = pos.availableFor(p);
              final isDark = Theme.of(context).brightness == Brightness.dark;
              final outOfStock = avail <= 0;
              return AnimatedPressCard(
                onTap: outOfStock ? null : () { pos.addToCart(p); setState(() {}); },
                decoration: BoxDecoration(
                  color: outOfStock
                      ? (isDark ? AppTheme.darkSurface.withAlpha(140) : const Color(0xFFF1F5F9))
                      : (isDark ? AppTheme.darkCard : Colors.white),
                  border: Border.all(
                    color: outOfStock
                        ? (isDark ? AppTheme.danger.withAlpha(40) : AppTheme.danger.withAlpha(30))
                        : (isDark ? AppTheme.darkBorder : AppTheme.lightBorder),
                    width: outOfStock ? 1.2 : 1,
                  ),
                  borderRadius: BorderRadius.circular(14),
                  boxShadow: outOfStock ? null : [
                    BoxShadow(
                      color: (isDark ? Colors.black : Colors.grey).withAlpha(18),
                      blurRadius: 8,
                      offset: const Offset(0, 2),
                    ),
                  ],
                ),
                padding: const EdgeInsets.all(6),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  // Product image — takes available space, no flex overflow
                  Flexible(
                    flex: 3,
                    child: Container(
                      width: double.infinity,
                      constraints: const BoxConstraints(minHeight: 40),
                      decoration: BoxDecoration(
                        color: isDark ? AppTheme.darkSurface : const Color(0xFFF8FAFC),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(10),
                        child: p.image.isNotEmpty
                            ? Stack(fit: StackFit.expand, children: [
                                NetImage(
                                  p.image,
                                  fit: BoxFit.cover,
                                  memCacheWidth: 200,
                                  fallback: (_) => const Center(child: Icon(Icons.inventory_2_outlined, color: AppTheme.lightMuted, size: 28)),
                                ),
                                if (outOfStock)
                                  Positioned.fill(
                                    child: Container(
                                      decoration: BoxDecoration(
                                        color: Colors.black.withAlpha(100),
                                        borderRadius: BorderRadius.circular(10),
                                      ),
                                      child: const Center(
                                        child: Icon(Icons.remove_shopping_cart_outlined, color: Colors.white, size: 24),
                                      ),
                                    ),
                                  ),
                              ])
                            : Center(
                                child: Icon(Icons.inventory_2_outlined,
                                    color: outOfStock ? AppTheme.danger.withAlpha(80) : AppTheme.lightMuted, size: 28)),
                      ),
                    ),
                  ),
                  // Info section — compact, never overflows
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      Text(p.name, textAlign: TextAlign.center, maxLines: 1, overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            fontSize: 11, fontWeight: FontWeight.w600,
                            color: outOfStock ? Colors.grey : null,
                          )),
                      const SizedBox(height: 1),
                      Text(U.money(p.price, cur),
                          style: TextStyle(
                            fontSize: 10, fontWeight: FontWeight.w800,
                            color: outOfStock ? Colors.grey : AppTheme.success,
                          )),
                      Container(
                        margin: const EdgeInsets.only(top: 2),
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
                        decoration: BoxDecoration(
                          color: outOfStock
                              ? AppTheme.danger.withAlpha(20)
                              : AppTheme.primary.withAlpha(15),
                          borderRadius: BorderRadius.circular(999),
                          border: Border.all(
                            color: outOfStock
                                ? AppTheme.danger.withAlpha(40)
                                : AppTheme.primary.withAlpha(30),
                          ),
                        ),
                        child: Text(
                          outOfStock ? 'Agotado' : '$avail uds',
                          style: TextStyle(fontSize: 9, fontWeight: FontWeight.w700,
                              color: outOfStock ? AppTheme.danger : AppTheme.primary),
                        ),
                      ),
                    ]),
                  ),
                 ]),
               );
             },
           ),
          ),
        ),
      ]),

      // Floating cart bar with glass effect
      if (pos.cart.isNotEmpty)
        Positioned(
          left: 14, right: 14, bottom: 16,
          child: SafeArea(
            child: Container(
              decoration: BoxDecoration(
                gradient: LinearGradient(colors: [AppTheme.darkBg.withAlpha(230), AppTheme.darkSurface.withAlpha(230)]),
                borderRadius: BorderRadius.circular(14),
                boxShadow: [BoxShadow(color: Colors.black.withAlpha(40), blurRadius: 20, offset: const Offset(0, 8))],
              ),
              child: Material(
                color: Colors.transparent,
                child: InkWell(
                  borderRadius: BorderRadius.circular(14),
                  onTap: _openCart,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    child: Row(children: [
                      Container(
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(color: AppTheme.primary.withAlpha(60), borderRadius: BorderRadius.circular(10)),
                        child: const Icon(Icons.shopping_cart_outlined, color: AppTheme.primaryLight, size: 20),
                      ),
                      const SizedBox(width: 12),
                      Expanded(child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min,
                        children: [
                          Text('${pos.cartCount} items en el carrito', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 13)),
                          Text('Ver carrito', style: TextStyle(color: Colors.white.withAlpha(150), fontSize: 11)),
                        ],
                      )),
                      Text(U.money(pos.cartTotal, cur), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 16)),
                      const SizedBox(width: 4),
                      Icon(Icons.chevron_right, color: Colors.white.withAlpha(150)),
                    ]),
                  ),
                ),
              ),
            ),
          ),
        ),
    ]);
  }
}

class ScannerPage extends StatefulWidget {
  const ScannerPage({super.key});
  @override
  State<ScannerPage> createState() => _ScannerPageState();
}

class _ScannerPageState extends State<ScannerPage> {
  final MobileScannerController controller = MobileScannerController();
  bool done = false;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Escanear código')),
      body: MobileScanner(
        controller: controller,
        onDetect: (capture) {
          if (done) return;
          for (final bar in capture.barcodes) {
            if (bar.rawValue != null && bar.rawValue!.isNotEmpty) {
              done = true;
              Navigator.pop(context, bar.rawValue);
              return;
            }
          }
        },
      ),
    );
  }
}
