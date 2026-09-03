import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/auth_service.dart';
import '../services/db_service.dart';
import '../services/image_cache_service.dart';
import '../services/sync_service.dart';
import '../widgets/common.dart';
import '../widgets/crud.dart';

/// Productos con crear/editar, escaneo de código de barras,
/// thumbnails de imagen, galería y gestión de combos.
class ProductsScreen extends StatefulWidget {
  const ProductsScreen({super.key});
  @override
  State<ProductsScreen> createState() => _ProductsScreenState();
}

class _ProductsScreenState extends State<ProductsScreen> {
  String _q = '';
  String _tab = 'products'; // products | categories
  final _catKey = GlobalKey<_CategoriesTabState>();
  late Future<List<Map<String, dynamic>>> _future;
  // Densidad de la grilla (pellizco tipo galería). 0 = automático por ancho.
  int _cols = 0;

  @override
  void initState() {
    super.initState();
    SharedPreferences.getInstance().then((sp) {
      if (!mounted) return;
      setState(() => _cols = sp.getInt('wsm_density_products') ?? 0);
    });
    _reload();
    SyncService.I.onChange(_onSync);
  }

  Future<void> _setCols(int v) async {
    if (v == _cols) return;
    setState(() => _cols = v);
    final sp = await SharedPreferences.getInstance();
    await sp.setInt('wsm_density_products', v);
  }

  void _onSync() {
    if (mounted) _reload();
  }

  void _reload() {
    _future = DbService.I.all('products');
    setState(() {});
    _warmImages();
  }

  /// Precarga en disco las imágenes de TODOS los productos (como el
  /// Images.warm() de la app Cordova): ListView solo construye los ítems
  /// visibles, así que sin esto las imágenes no vistas nunca se descargan
  /// y no estarían disponibles offline.
  Future<void> _warmImages() async {
    if (!SyncService.I.isOnline) return;
    try {
      final rows = await _future;
      for (final r in rows) {
        final u = '${r['image'] ?? ''}';
        if (u.isEmpty) continue;
        await ImageDisk.warm(u);
      }
    } catch (_) {}
  }

  Widget _productPlaceholder(bool isCombo) {
    return Container(
      width: 40, height: 40,
      decoration: BoxDecoration(
        color: isCombo
            ? AppTheme.purple.withAlpha(20)
            : AppTheme.primary.withAlpha(20),
        borderRadius: BorderRadius.circular(10),
      ),
      child: Icon(Icons.inventory_2_outlined,
          color: isCombo ? AppTheme.purple : AppTheme.primary, size: 20),
    );
  }

  void _openScanner() {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => _BarcodeScannerScreen(
        onScanned: (code) {
          setState(() => _q = code.toLowerCase());
          _reload();
        },
      ),
    ));
  }

  Future<void> _edit(Map<String, dynamic>? item) async {
    final name = TextEditingController(text: '${item?['name'] ?? ''}');
    final barcode =
        TextEditingController(text: '${item?['barcode'] ?? ''}');
    final salePrice =
        TextEditingController(text: '${item?['sale_price'] ?? ''}');
    final costPrice =
        TextEditingController(text: '${item?['cost_price'] ?? ''}');
    final minStock =
        TextEditingController(text: '${item?['min_stock'] ?? ''}');
    final image = TextEditingController(text: '${item?['image'] ?? ''}');
    final category = TextEditingController(text: '${item?['category'] ?? ''}');
    final description = TextEditingController(text: '${item?['description'] ?? ''}');
    final isCombo = item != null &&
        ('${item['is_combo']}' == '1' || '${item['is_combo']}' == 'true');
    final active = ValueNotifier<bool>(
        item == null || ('${item['active']}' != '0' && '${item['active']}' != 'false'));

    // Gallery
    List<String> gallery = [];
    if (item != null) {
      final raw = item['gallery'];
      if (raw is List) {
        gallery = raw.map((e) => '$e').toList();
      } else if (raw is String && raw.isNotEmpty) {
        try {
          final decoded = jsonDecode(raw);
          if (decoded is List) {
            gallery = decoded.map((e) => '$e').toList();
          }
        } catch (_) {}
      }
    }

    // Check cloudinary
    bool hasCloudinary = false;
    try {
      final settings =
          await DbService.I.cacheGet('ws_settings_get');
      if (settings is Map) {
        hasCloudinary = settings['cloudinary'] != null &&
            '${settings['cloudinary']}'.isNotEmpty;
      }
    } catch (_) {}

    if (!mounted) return;

    final ok = await showFormSheet(
      context,
      title: isCombo
          ? 'Editar combo'
          : (item == null ? 'Nuevo producto' : 'Editar producto'),
      fields: [
        fField('Nombre *', name),
        fField('Código / barras', barcode),
        Row(children: [
          Expanded(
              child: fField('Precio venta', salePrice,
                  type: TextInputType.number)),
          const SizedBox(width: 8),
          Expanded(
              child: fField('Precio costo', costPrice,
                  type: TextInputType.number)),
        ]),
        fField('Stock mínimo', minStock, type: TextInputType.number),
        if (!isCombo) fField('Categoría', category),
        if (!isCombo) fField('Descripción', description, type: TextInputType.multiline),
        if (!isCombo) fField('Imagen URL', image, hint: 'https://…'),
        // Gallery
        if (!isCombo) ...[
          const SizedBox(height: 8),
          Text('Galería de imágenes',
              style: TextStyle(
                  fontSize: 13,
                  fontWeight: FontWeight.w600,
                  color: Colors.grey[600])),
          const SizedBox(height: 4),
          if (gallery.isEmpty)
            Text('Sin imágenes adicionales.',
                style: TextStyle(fontSize: 12, color: Colors.grey[400]))
          else
            SizedBox(
              height: 60,
              child: ListView.builder(
                scrollDirection: Axis.horizontal,
                itemCount: gallery.length,
                itemBuilder: (ctx, i) => Stack(
                  children: [
                    Container(
                      width: 60,
                      height: 60,
                      margin: const EdgeInsets.only(right: 6),
                      child: ClipRRect(
                        borderRadius: BorderRadius.circular(8),
                        child: NetImage(gallery[i],
                            width: 60, height: 60, fit: BoxFit.cover,
                            fallback: (_) => Container(
                                width: 60, height: 60,
                                color: Colors.grey.withAlpha(40),
                                child: const Icon(Icons.broken_image_outlined,
                                    size: 18, color: Colors.grey))),
                      ),
                    ),
                    Positioned(
                      top: 0,
                      right: 0,
                      child: GestureDetector(
                        onTap: () {
                          gallery.removeAt(i);
                          (ctx as Element).markNeedsBuild();
                        },
                        child: Container(
                          padding: const EdgeInsets.all(2),
                          decoration: const BoxDecoration(
                              color: Colors.red,
                              shape: BoxShape.circle),
                          child: const Icon(Icons.close,
                              size: 12, color: Colors.white),
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          if (hasCloudinary) ...[
            const SizedBox(height: 6),
            Row(
              children: [
                Expanded(
                  child: TextField(
                    decoration: const InputDecoration(
                        isDense: true,
                        hintText: 'URL de imagen y añadir'),
                    onSubmitted: (v) {
                      if (v.trim().isNotEmpty) {
                        gallery.add(v.trim());
                      }
                    },
                  ),
                ),
              ],
            ),
          ],
        ],
        ValueListenableBuilder<bool>(
          valueListenable: active,
          builder: (_, v, __) => SwitchListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            title:
                Text(v ? 'Activo' : 'Inactivo', style: const TextStyle(fontSize: 14)),
            value: v,
            onChanged: (val) => active.value = val,
          ),
        ),
      ],
      onSave: () async {
        if (name.text.trim().isEmpty) {
          U.toast(context, 'El nombre es obligatorio', kind: 'err');
          return false;
        }
        final payload = <String, dynamic>{
          'id': item != null ? (num.tryParse('${item['id']}') ?? 0) : 0,
          'name': name.text.trim(),
          'barcode': barcode.text.trim(),
          'sale_price': num.tryParse(salePrice.text) ?? 0,
          'cost_price': num.tryParse(costPrice.text) ?? 0,
          'min_stock': num.tryParse(minStock.text) ?? 0,
          'image': image.text.trim(),
          'category': category.text.trim(),
          'description': description.text.trim(),
          'active': active.value ? 1 : 0,
        };
        if (!isCombo && gallery.isNotEmpty) {
          payload['gallery'] = gallery;
        }
        return U.handlePush(
          context,
          SyncService.I.push(
              isCombo ? 'ws_combo_save' : 'ws_save_product', payload),
          'Guardado',
          onOk: () => SyncService.I.pullStore('ws_cache_products', {}, 'products'),
          onQueued: (queuedPayload) async {
            final rows = await DbService.I.all('products');
            final id = payload['id'] ?? 0;
            if (id == 0) {
              rows.add({
                'id': -DateTime.now().millisecondsSinceEpoch,
                'name': payload['name'], 'barcode': payload['barcode'],
                'sale_price': payload['sale_price'], 'cost_price': payload['cost_price'],
                'min_stock': payload['min_stock'], 'image': payload['image'],
                'category': payload['category'], 'description': payload['description'],
                'active': payload['active'], 'is_combo': isCombo ? 1 : 0,
              });
            } else {
              for (final r in rows) {
                if ('${r['id']}' == '$id') {
                  r['name'] = payload['name']; r['barcode'] = payload['barcode'];
                  r['sale_price'] = payload['sale_price']; r['cost_price'] = payload['cost_price'];
                  r['min_stock'] = payload['min_stock']; r['image'] = payload['image'];
                  r['category'] = payload['category']; r['description'] = payload['description'];
                  r['active'] = payload['active'];
                  break;
                }
              }
            }
            await DbService.I.replaceAll('products', rows);
          },
        );
      },
    );
    if (ok == true && mounted) {
      _reload();
    }
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final canCreate = AuthService.I.has('products_create');
    final canEdit = AuthService.I.has('products_edit');
    final cur = AuthService.I.currency;

    return Scaffold(
      backgroundColor: Colors.transparent,
      floatingActionButton: _tab == 'categories'
          ? (AuthService.I.has('categories_manage')
              ? FloatingActionButton.extended(
                  heroTag: 'addCategory',
                  onPressed: () =>
                      _catKey.currentState?.editNew(context),
                  icon: const Icon(Icons.add),
                  label: const Text('Categoría'),
                )
              : null)
          : (canCreate
              ? FloatingActionButton.extended(
                  heroTag: 'addProduct',
                  onPressed: () => _edit(null),
                  icon: const Icon(Icons.add),
                  label: const Text('Producto'),
                )
              : null),
      body: Column(children: [
        // Segmented Productos | Categorías
        Padding(
          padding: const EdgeInsets.fromLTRB(14, 12, 14, 0),
          child: Row(children: [
            Expanded(
              child: _seg(_tab, 'products', 'Productos',
                  Icons.inventory_2_outlined, () => setState(() => _tab = 'products')),
            ),
            if (AuthService.I.has('categories_manage')) ...[
              const SizedBox(width: 8),
              Expanded(
                child: _seg(_tab, 'categories', 'Categorías',
                    Icons.category_outlined, () => setState(() => _tab = 'categories')),
              ),
            ],
          ]),
        ),
        // Search + scanner (solo productos)
        if (_tab == 'products')
          Padding(
            padding: const EdgeInsets.only(top: 10),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(14, 0, 14, 0),
              child: TextField(
                decoration: InputDecoration(
                  hintText: 'Buscar producto o código…',
                  prefixIcon: const Icon(Icons.search),
                  isDense: true,
                  filled: true,
                  fillColor: isDark ? AppTheme.darkSurface : Colors.white,
                  suffixIcon: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      if (_q.isNotEmpty)
                        IconButton(
                          onPressed: () =>
                              setState(() { _q = ''; _reload(); }),
                          icon: const Icon(Icons.close, size: 18),
                        ),
                      IconButton(
                        onPressed: _openScanner,
                        icon: const Icon(Icons.qr_code_scanner, size: 20),
                        tooltip: 'Escanear código',
                      ),
                    ],
                  ),
                ),
                onChanged: (v) => setState(() => _q = v.trim().toLowerCase()),
              ),
            ),
          ),
        // Content
        Expanded(
          child: _tab == 'categories'
              ? _CategoriesTab(key: _catKey)
              : FutureBuilder<List<Map<String, dynamic>>>(
                  future: _future,
                  builder: (context, snap) {
                    if (snap.connectionState != ConnectionState.done) {
                      return const Center(child: CircularProgressIndicator());
                    }
                    var rows = snap.data ?? [];
                    if (_q.isNotEmpty) {
                      rows = rows.where((r) {
                        final name = '${r['name'] ?? ''}'.toLowerCase();
                        final barcode = '${r['barcode'] ?? ''}'.toLowerCase();
                        return name.contains(_q) || barcode.contains(_q);
                      }).toList();
                    }
                    rows.sort((a, b) => '${a['name'] ?? ''}'
                        .toLowerCase()
                        .compareTo('${b['name'] ?? ''}'.toLowerCase()));
                    final screenWidth = MediaQuery.of(context).size.width;
                    final crossCount =
                        _cols > 0 ? _cols : (screenWidth > 900 ? 3 : screenWidth > 600 ? 2 : 1);
                    if (rows.isEmpty) {
                      return Center(
                          child: Text('Sin productos.',
                              style: TextStyle(
                                  color: isDark
                                      ? AppTheme.darkMuted
                                      : AppTheme.lightMuted)));
                    }
                    return RefreshIndicator(
                      onRefresh: () async { _reload(); await _future; },
                      child: PinchDensity(
                        cols: crossCount,
                        minCols: 1,
                        maxCols: 3,
                        onChanged: _setCols,
                        child: GridView.builder(
                          padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
                          cacheExtent: 300,
                          itemCount: rows.length,
                          gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                            crossAxisCount: crossCount,
                            mainAxisExtent: 76,
                            mainAxisSpacing: 8,
                            crossAxisSpacing: 8,
                          ),
                          itemBuilder: (context, i) {
                            final p = rows[i];
                            final isCombo = '${p['is_combo']}' == '1' ||
                                '${p['is_combo']}' == 'true';
                            final price =
                                num.tryParse('${p['sale_price']}') ?? 0;
                            final active =
                                '${p['active']}' != '0' && '${p['active']}' != 'false';
                            final imageUrl = '${p['image'] ?? ''}';
                            return Card(
                              margin: EdgeInsets.zero,
                              child: InkWell(
                                borderRadius: BorderRadius.circular(12),
                                onTap: () => _showDetail(context, p),
                                child: Padding(
                                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
                                  child: Row(children: [
                                    ClipRRect(
                                      borderRadius: BorderRadius.circular(10),
                                      child: imageUrl.isNotEmpty
                                          ? NetImage(imageUrl,
                                              width: 40, height: 40, fit: BoxFit.cover,
                                              fallback: (_) => _productPlaceholder(isCombo))
                                          : _productPlaceholder(isCombo),
                                    ),
                                    const SizedBox(width: 10),
                                    Expanded(
                                      child: Column(
                                        mainAxisSize: MainAxisSize.min,
                                        crossAxisAlignment: CrossAxisAlignment.start,
                                        children: [
                                          Row(children: [
                                            Expanded(
                                              child: Text('${p['name'] ?? ''}',
                                                  style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                                                  maxLines: 1, overflow: TextOverflow.ellipsis),
                                            ),
                                            if (isCombo) U.badge('Combo', color: AppTheme.purple, small: true),
                                            if (!active) U.badge('Inact.', color: AppTheme.danger, small: true),
                                          ]),
                                          const SizedBox(height: 2),
                                          Text('${p['barcode'] ?? ''} · ${U.money(price, cur)}',
                                              style: TextStyle(color: Colors.grey[600], fontSize: 11),
                                              maxLines: 1, overflow: TextOverflow.ellipsis),
                                        ],
                                      ),
                                    ),
                                    if (canEdit)
                                      IconButton(
                                        icon: const Icon(Icons.edit_outlined, size: 18),
                                        onPressed: () => _edit(p),
                                        constraints: const BoxConstraints(minWidth: 32, minHeight: 32),
                                      ),
                                  ],),
                                ),
                              ),
                            );
                          },
                        ),
                      ),
                    );
                  },
                ),
        ),
      ]),
    );
  }

  Widget _seg(String current, String value, String label, IconData icon,
      VoidCallback onTap) {
    final active = current == value;
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 9),
        decoration: BoxDecoration(
          color: active ? AppTheme.primary : Colors.transparent,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(
              color: active ? AppTheme.primary : AppTheme.primary.withAlpha(80)),
        ),
        alignment: Alignment.center,
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 16, color: active ? Colors.white : AppTheme.primary),
            const SizedBox(width: 6),
            Text(label,
                style: TextStyle(
                    fontSize: 13,
                    fontWeight: FontWeight.w600,
                    color: active ? Colors.white : AppTheme.primary)),
          ],
        ),
      ),
    );
  }
}

/// Detalle completo del producto en un modal (al tocar la tarjeta).
void _showDetail(BuildContext context, Map<String, dynamic> p) {
  final cur = '${p['currency'] ?? ''}'.isNotEmpty
      ? '${p['currency']}'
      : AuthService.I.currency;
  final canEdit = AuthService.I.has('products_edit');
  final isCombo =
      '${p['is_combo']}' == '1' || '${p['is_combo']}' == 'true';
  final active = '${p['active']}' != '0' && '${p['active']}' != 'false';
  final expired = '${p['expired'] ?? 0}' == '1';
  final expiring = '${p['expiring'] ?? 0}' == '1';
  final imageUrl = '${p['image'] ?? ''}';
  final salePrice = num.tryParse('${p['sale_price']}') ?? 0;
  final costPrice = num.tryParse('${p['cost_price']}') ?? 0;
  final minStock = num.tryParse('${p['min_stock'] ?? 0}') ?? 0;

  showModalBottomSheet(
    context: context,
    isScrollControlled: true,
    builder: (sheetCtx) => SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(
            maxHeight: MediaQuery.of(sheetCtx).size.height * 0.85),
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(18, 16, 18, 18),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            // Cabecera: imagen + nombre + badges
            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              GestureDetector(
                onTap:
                    imageUrl.isNotEmpty ? () => ImageViewerScreen.show(context, imageUrl) : null,
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(12),
                  child: imageUrl.isNotEmpty
                      ? NetImage(imageUrl,
                          width: 84, height: 84, fit: BoxFit.cover,
                          fallback: (_) => Container(
                              width: 84, height: 84,
                              color: AppTheme.primary.withAlpha(20),
                              child: const Icon(Icons.inventory_2_outlined,
                                  color: AppTheme.primary, size: 32)))
                      : Container(
                          width: 84, height: 84,
                          color: isCombo
                              ? AppTheme.purple.withAlpha(20)
                              : AppTheme.primary.withAlpha(20),
                          child: Icon(Icons.inventory_2_outlined,
                              color: isCombo ? AppTheme.purple : AppTheme.primary,
                              size: 32),
                        ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('${p['name'] ?? ''}',
                          style: const TextStyle(
                              fontSize: 17, fontWeight: FontWeight.w800)),
                      const SizedBox(height: 6),
                      Wrap(spacing: 6, runSpacing: 4, children: [
                        if (isCombo) U.badge('Combo', color: AppTheme.purple, small: true),
                        if (!active) U.badge('Inactivo', color: AppTheme.danger, small: true),
                        if (expired) U.badge('Vencido', color: AppTheme.danger, small: true),
                        if (expiring) U.badge('Por vencer', color: AppTheme.amber, small: true),
                      ]),
                    ]),
              ),
            ]),
            const SizedBox(height: 14),
            // Precio de venta (y costo si puede editar)
            Text(U.money(salePrice, cur),
                style: TextStyle(
                    fontSize: 22,
                    fontWeight: FontWeight.w900,
                    color: AppTheme.success)),
            if (canEdit && costPrice > 0)
              Padding(
                padding: const EdgeInsets.only(top: 2),
                child: Text('Costo: ${U.money(costPrice, cur)}',
                    style: TextStyle(fontSize: 13, color: Colors.grey[600])),
              ),
            const Divider(height: 24),
            // Datos generales
            _detailRow('Código de barras', '${p['barcode'] ?? ''}'),
            if ('${p['category_path'] ?? p['category'] ?? ''}'.isNotEmpty)
              _detailRow('Categoría', '${p['category_path'] ?? p['category']}'),
            if ('${p['supplier_name'] ?? ''}'.isNotEmpty)
              _detailRow('Proveedor', '${p['supplier_name']}'),
            if ('${p['production_date'] ?? ''}'.isNotEmpty)
              _detailRow('Producción', '${p['production_date']}'),
            if ('${p['expiry_date'] ?? ''}'.isNotEmpty)
              _detailRow('Vencimiento', '${p['expiry_date']}'),
            if (minStock > 0) _detailRow('Stock mínimo', '$minStock'),
            if ('${p['description'] ?? ''}'.isNotEmpty) ...[
              const SizedBox(height: 10),
              Text('Descripción',
                  style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
              const SizedBox(height: 4),
              Text('${p['description']}',
                  style: TextStyle(fontSize: 13, color: Colors.grey[700])),
            ],
            const SizedBox(height: 14),
            // Stock por ubicación (espejo SQLite, disponible offline)
            Text('Stock por ubicación',
                style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            const SizedBox(height: 6),
            FutureBuilder<List<Widget>>(
              future: _stockByLocation(p),
              builder: (_, snap) {
                if (snap.connectionState != ConnectionState.done) {
                  return const Padding(
                    padding: EdgeInsets.all(8),
                    child: Center(child: SizedBox(width: 18, height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2))),
                  );
                }
                final tiles = snap.data ?? [];
                if (tiles.isEmpty) {
                  return Text('Sin stock registrado.',
                      style: TextStyle(fontSize: 13, color: Colors.grey[600]));
                }
                return Column(children: tiles);
              },
            ),
            const SizedBox(height: 12),
            FilledButton(
              onPressed: () => Navigator.pop(sheetCtx),
              child: const Text('Cerrar'),
            ),
          ]),
        ),
      ),
    ),
  );
}

Widget _detailRow(String label, String value) => Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SizedBox(
            width: 130,
            child: Text(label,
                style: TextStyle(fontSize: 13, color: Colors.grey[600]))),
        Expanded(
          child: Text(value,
              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
        ),
      ]),
    );

/// Cantidades por ubicación desde el espejo de stock (respeta las
/// ubicaciones asignadas al trabajador).
Future<List<Widget>> _stockByLocation(Map<String, dynamic> p) async {
  final pid = '${p['id'] ?? ''}';
  final workerLocs = AuthService.I.locationIds;
  final stock = await DbService.I.all('stock');
  final locs = {for (final l in await DbService.I.all('locations')) '${l['id']}': '${l['name'] ?? ''}'};
  final rows = stock.where((r) => '${r['product_id'] ?? ''}' == pid).where((r) =>
      workerLocs.isEmpty ||
      workerLocs.contains(int.tryParse('${r['location_id']}') ?? 0));
  return rows.map((r) {
    final lid = '${r['location_id'] ?? ''}';
    final name = locs[lid] ?? (lid.isEmpty ? '—' : '#$lid');
    final qty = (num.tryParse('${r['qty']}') ?? 0).toInt();
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(children: [
        Expanded(
            child: Text(name,
                style: const TextStyle(fontSize: 13),
                overflow: TextOverflow.ellipsis)),
        Text('$qty uds',
            style: TextStyle(
                fontSize: 13,
                fontWeight: FontWeight.w700,
                color: qty > 0 ? AppTheme.success : AppTheme.danger)),
      ]),
    );
  }).toList();
}

/// Barcode scanner screen using mobile_scanner.
class _BarcodeScannerScreen extends StatelessWidget {
  final void Function(String code) onScanned;
  const _BarcodeScannerScreen({required this.onScanned});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Escanear código')),
      body: MobileScanner(
        onDetect: (capture) {
          final code = capture.barcodes.first.rawValue;
          if (code != null) {
            onScanned(code);
            Navigator.pop(context);
          }
        },
      ),
    );
  }
}

/// Pestaña de categorías (árbol padre/hijo): listado local con refresh
/// desde el servidor y CRUD que encola ws_category_save / ws_category_delete.
class _CategoriesTab extends StatefulWidget {
  const _CategoriesTab({super.key});

  @override
  State<_CategoriesTab> createState() => _CategoriesTabState();
}

class _CategoriesTabState extends State<_CategoriesTab> {
  List<Map<String, dynamic>> _cats = [];
  bool _loading = true;
  // Categorías desplegadas en el acordeón (mismo comportamiento que el panel
  // web: tocar una categoría con subcategorías las muestra/oculta).
  final Set<Object> _expanded = {};

  @override
  void initState() {
    super.initState();
    _load();
    SyncService.I.onChange(_onSync);
  }

  void _onSync() {
    if (mounted) _load();
  }

  Future<void> _load() async {
    try {
      final rows = await DbService.I.all('categories');
      if (mounted) {
        setState(() {
          _cats = rows;
          _loading = false;
        });
      }
    } catch (_) {
      // Una lectura fallida de la tabla local nunca debe dejar el loader
      // infinito: se muestra la lista vacía y se reintenta desde el server.
      if (mounted) {
        setState(() {
          _cats = [];
          _loading = false;
        });
      }
    }
    try {
      await _refreshFromServer();
    } catch (_) {
      // Error de red/sync: silencioso, la vista ya tiene la lista local.
    }
  }

  Future<void> _refreshFromServer() async {
    if (!SyncService.I.isOnline) return;
    final rows =
        await SyncService.I.pullStore('ws_categories_list', {}, 'categories',
            cacheKey: 'ws_categories_list', dataKey: 'categories');
    if (rows != null && mounted) {
      setState(() => _cats = rows);
    }
  }

  // Profundidad en el árbol (para la indentación y el desplegable de padre).
  int _depth(Map<String, dynamic> c, List<Map<String, dynamic>> all) {
    var d = 0;
    var parent = int.tryParse('${c['parent_id'] ?? 0}') ?? 0;
    final ids = <int>{};
    while (parent != 0 && ids.add(parent)) {
      final p = all.where((x) => (int.tryParse('${x['id'] ?? 0}') ?? 0) == parent).firstOrNull;
      if (p == null) break;
      d++;
      parent = int.tryParse('${p['parent_id'] ?? 0}') ?? 0;
      if (d > 20) break;
    }
    return d;
  }

  // ¿Cuántos hijos DIRECTOS tiene `c`? (cuenta los que apuntan a su id).
  int _childrenCount(Map<String, dynamic> c, List<Map<String, dynamic>> all) {
    final id = '${c['id']}';
    return all.where((x) => '${x['parent_id'] ?? 0}' == id).length;
  }

  // ¿Es visible? Una categoría se muestra si es raíz (parent_id=0) o si su
  // categoría padre está desplegada. Mismo comportamiento que isVisible() en
  // la pantalla web del panel.
  bool _visible(Map<String, dynamic> c) {
    final pid = '${c['parent_id'] ?? 0}';
    return pid == '0' || pid.isEmpty || _expanded.contains(pid);
  }

  void _toggle(Map<String, dynamic> c) {
    setState(() {
      final id = c['id'];
      if (id != null) {
        if (_expanded.contains(id)) {
          _expanded.remove(id);
        } else {
          _expanded.add(id);
        }
      }
    });
  }

  void _collapseAll() => setState(_expanded.clear);

  // Nueva categoría desde el FAB de la pantalla madre.
  void editNew(BuildContext context) => _edit(context, null, _load);

  Future<void> _edit(BuildContext context, Map<String, dynamic>? existing,
      void Function() after) async {
    final name = TextEditingController(text: '${existing?['name'] ?? ''}');
    final sort = TextEditingController(text: '${existing?['sort_order'] ?? 0}');
    final active = ValueNotifier<bool>(
        existing == null || ('${existing['active']}' != '0' && '${existing['active']}' != 'false'));
    final parentNotifier = ValueNotifier<int>(
        int.tryParse('${existing?['parent_id'] ?? 0}') ?? 0);

    // Flat list of parent candidates (excluye la propia y sus hijos).
    final excluded = <int>{};
    if (existing != null) {
      final eid = int.tryParse('${existing['id'] ?? 0}') ?? 0;
      excluded.add(eid);
      for (final c in _cats) {
        if (c['parent_id'] == existing['id']) excluded.add(int.tryParse('${c['id'] ?? 0}') ?? 0);
      }
    }
    final parentOptions = _cats
        .where((c) => !excluded.contains(int.tryParse('${c['id'] ?? 0}') ?? 0))
        .map((c) => (c, _depth(c, _cats)))
        .toList();

    final ok = await showFormSheet(
      context,
      title: existing == null ? 'Nueva categoría' : 'Editar categoría',
      fields: [
        fField('Nombre *', name),
        ValueListenableBuilder<int>(
          valueListenable: parentNotifier,
          builder: (_, pv, __) => DropdownButtonFormField<int>(
            value: pv,
            decoration: const InputDecoration(labelText: 'Categoría padre'),
            items: [
              const DropdownMenuItem(value: 0, child: Text('Sin padre (raíz)')),
              ...parentOptions.map((e) {
                final pid = int.tryParse('${e.$1['id'] ?? 0}') ?? 0;
                return DropdownMenuItem(
                  value: pid,
                  child: Text('${'     ' * e.$2}${e.$1['name']}'),
                );
              }),
            ],
            onChanged: (v) => parentNotifier.value = v ?? 0,
          ),
        ),
        fField('Orden', sort, type: TextInputType.number),
        ValueListenableBuilder<bool>(
          valueListenable: active,
          builder: (_, v, __) => SwitchListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            title: Text(v ? 'Activa' : 'Inactiva',
                style: const TextStyle(fontSize: 14)),
            value: v,
            onChanged: (val) => active.value = val,
          ),
        ),
      ],
      onSave: () async {
        if (name.text.trim().isEmpty) {
          U.toast(context, 'El nombre es obligatorio', kind: 'err');
          return false;
        }
        final payload = <String, dynamic>{
          'id': existing != null ? (num.tryParse('${existing['id']}') ?? 0) : 0,
          'name': name.text.trim(),
          'parent_id': parentNotifier.value,
          'sort_order': num.tryParse(sort.text) ?? 0,
          'active': active.value ? 1 : 0,
        };
        return U.handlePush(
          context,
          SyncService.I.push('ws_category_save', payload),
          'Guardado',
          onOk: () => SyncService.I.pullStore('ws_categories_list', {}, 'categories', cacheKey: 'ws_categories_list', dataKey: 'categories'),
          onQueued: (queuedPayload) async {
            final rows = await DbService.I.all('categories');
            final id = payload['id'] ?? 0;
            if (id == 0) {
              rows.add({
                'id': -DateTime.now().millisecondsSinceEpoch,
                'parent_id': payload['parent_id'],
                'name': payload['name'],
                'sort_order': payload['sort_order'],
                'active': payload['active'],
                'path': '',
                'children': 0,
                'products': 0,
                'slug': '',
              });
            } else {
              for (final r in rows) {
                if ('${r['id']}' == '$id') {
                  r['name'] = payload['name'];
                  r['parent_id'] = payload['parent_id'];
                  r['sort_order'] = payload['sort_order'];
                  r['active'] = payload['active'];
                  break;
                }
              }
            }
            await DbService.I.replaceAll('categories', rows);
          },
        );
      },
    );
    if (ok == true) after();
  }

  Future<void> _delete(BuildContext context, Map<String, dynamic> c,
      VoidCallback after) async {
    final ok = await U.confirm(
        context,
        '¿Eliminar "${c['name']}"?\n'
        'Se borrará junto a sus subcategorías. Los productos pasarán a la categoría padre.',
        action: 'Eliminar');
    if (!ok) return;
    final done = await U.handlePush(
      context,
      SyncService.I.push('ws_category_delete', {'id': c['id']}),
      'Categoría eliminada',
      onOk: () => SyncService.I.pullStore('ws_categories_list', {}, 'categories', cacheKey: 'ws_categories_list', dataKey: 'categories'),
      onQueued: (queuedPayload) async {
        final rows = await DbService.I.all('categories');
        rows.removeWhere((r) => '${r['id']}' == '${c['id']}');
        await DbService.I.replaceAll('categories', rows);
      },
    );
    if (done) after();
  }

  @override
  Widget build(BuildContext context) {
    final fromServer = SyncService.I.isOnline;
    final visible = _cats.where(_visible).toList();
    final hasCollapsible = _cats.any((c) => _childrenCount(c, _cats) > 0);
    return RefreshIndicator(
      onRefresh: () async { await _refreshFromServer(); await _load(); },
      child: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView.builder(
              padding: const EdgeInsets.fromLTRB(14, 8, 14, 90),
              itemCount: visible.length + 1,
              itemBuilder: (ctx, i) {
                if (i == 0) {
                  return Padding(
                    padding: const EdgeInsets.only(bottom: 6),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(
                            fromServer
                                ? 'Pulsa una categoría para desplegar sus subcategorías.'
                                : 'Sin conexión · mostrando copia local.',
                            style:
                                TextStyle(fontSize: 12, color: Colors.grey[500]),
                          ),
                        ),
                        if (hasCollapsible && visible.length > 1)
                          GestureDetector(
                            onTap: _collapseAll,
                            child: Text('Colapsar',
                                style: TextStyle(
                                    fontSize: 12,
                                    color: AppTheme.primary,
                                    fontWeight: FontWeight.w600)),
                          ),
                      ],
                    ),
                  );
                }
                final c = visible[i - 1];
                final active =
                    '${c['active']}' != '0' && '${c['active']}' != 'false';
                final indent = _depth(c, _cats);
                final products = int.tryParse('${c['products'] ?? 0}') ?? 0;
                final children = _childrenCount(c, _cats);
                final isOpen = _expanded.contains(c['id']);
                return Card(
                  margin: const EdgeInsets.only(bottom: 6),
                  child: InkWell(
                    borderRadius: BorderRadius.circular(10),
                    onTap: children > 0 ? () => _toggle(c) : null,
                    child: Padding(
                      padding: EdgeInsets.only(
                          left: 8.0 + (indent * 16).clamp(0, 64),
                          right: 4,
                          top: 2,
                          bottom: 2),
                      child: ListTile(
                        dense: true,
                        contentPadding:
                            const EdgeInsets.symmetric(horizontal: 6),
                        leading: Icon(
                            children > 0
                                ? (isOpen
                                    ? Icons.expand_more
                                    : Icons.chevron_right)
                                : Icons.category_outlined,
                            color: children > 0 ? Colors.grey[600] : AppTheme.primary,
                            size: 20),
                        title: Text('${c['name'] ?? ''}',
                            style: const TextStyle(
                                fontWeight: FontWeight.w600, fontSize: 14)),
                        subtitle: Text([
                          if (children > 0)
                            '$children sub${children == 1 ? 'cat.' : 'cats.'}',
                          '$products prod.',
                          if (!active) 'inactiva',
                        ].join(' · '),
                            style:
                                TextStyle(fontSize: 11, color: Colors.grey[600])),
                        trailing: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              IconButton(
                                icon: const Icon(Icons.edit_outlined, size: 19),
                                onPressed: () => _edit(context, c, () => _load()),
                              ),
                              IconButton(
                                icon: const Icon(Icons.delete_outline,
                                    size: 19, color: AppTheme.danger),
                                onPressed: () => _delete(context, c, () => _load()),
                              ),
                            ]),
                      ),
                    ),
                  ),
                );
              },
            ),
    );
  }
}
