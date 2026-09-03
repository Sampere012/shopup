import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';
import 'dart:convert';
import 'api_service.dart';

/// Generación de PDFs (recibos POS, listados) con impresión/compartir,
/// equivalente funcional al módulo PDF del panel web.
class PdfService {
  static Future<void> exportPosSales(
      List<Map<String, dynamic>> sales, String currency) async {
    final pdf = pw.Document();
    final total = sales.fold<num>(
        0, (a, s) => a + (num.tryParse('${s['total']}') ?? 0));
    pdf.addPage(pw.MultiPage(
      build: (ctx) => [
        pw.Header(child: pw.Text('Ventas POS',
            style: pw.TextStyle(fontSize: 20, fontWeight: pw.FontWeight.bold))),
        pw.Table.fromTextArray(
          headers: ['#', 'Fecha', 'Cliente', 'Pago', 'Total'],
          data: sales.take(300).map((s) => [
                '${s['id'] ?? ''}',
                '${(s['created_at'] ?? '').toString().split('T').first}',
                '${s['customer_name'] ?? 'Mostrador'}',
                '${s['payment_method'] == 'transfer' ? 'Transfer.' : 'Efectivo'}',
                '${(num.tryParse('${s['total']}')?.toStringAsFixed(2) ?? '0.00')} $currency',
              ]).toList(),
        ),
        pw.Padding(
          padding: const pw.EdgeInsets.only(top: 12),
          child: pw.Align(
            alignment: pw.Alignment.centerRight,
            child: pw.Text('TOTAL: ${total.toStringAsFixed(2)} $currency',
                style:
                    pw.TextStyle(fontSize: 14, fontWeight: pw.FontWeight.bold)),
          ),
        ),
      ],
    ));
    await Printing.sharePdf(
        bytes: await pdf.save(), filename: 'ventas-pos.pdf');
  }

  /// Catálogo PDF de productos disponibles de una ubicación.
  /// Igual que la web (theme.js downloadCatalog → ws_stock_catalog_pdf):
  /// el servidor genera el PDF con la misma construcción (marca, foto,
  /// nombre y precio) y aquí se comparte con cualquier app del teléfono.
  static Future<void> exportStockCatalog(int locationId) async {
    final res = await ApiService.I
        .reqBytes('ws_stock_catalog_pdf', {'location_id': locationId});
    await Share.shareXFiles(
      [
        XFile.fromData(res.bytes,
            mimeType: 'application/pdf', name: res.filename),
      ],
      fileNameOverrides: [res.filename],
      subject: 'Catálogo de productos',
      text: 'Catálogo de productos',
    );
  }

  /// Recibo individual de venta.
  static Future<void> receipt(Map<String, dynamic> sale, String currency) async {
    final items = _items(sale);
    final total = num.tryParse('${sale['total']}') ?? 0;
    final pdf = pw.Document();
    pdf.addPage(pw.Page(
      build: (ctx) => pw.Column(crossAxisAlignment: pw.CrossAxisAlignment.start, children: [
        pw.Center(child: pw.Text('Recibo de venta',
            style: pw.TextStyle(fontSize: 18, fontWeight: pw.FontWeight.bold))),
        pw.SizedBox(height: 8),
        pw.Text('Nº ${sale['id']} · ${sale['created_at'] ?? ''}'),
        if ('${sale['customer_name'] ?? ''}'.isNotEmpty)
          pw.Text('Cliente: ${sale['customer_name']}'),
        pw.Divider(),
        for (final it in items)
          pw.Row(mainAxisAlignment: pw.MainAxisAlignment.spaceBetween, children: [
            pw.Text('${it['product_name'] ?? it['name'] ?? ''} × ${it['qty']}'),
            pw.Text('${(num.tryParse('${it['price']}') ?? 0).toStringAsFixed(2)} $currency'),
          ]),
        pw.Divider(),
        pw.Row(mainAxisAlignment: pw.MainAxisAlignment.spaceBetween, children: [
          pw.Text('TOTAL', style: pw.TextStyle(fontWeight: pw.FontWeight.bold)),
          pw.Text('${total.toStringAsFixed(2)} $currency',
              style: pw.TextStyle(fontWeight: pw.FontWeight.bold)),
        ]),
      ]),
    ));
    await Printing.layoutPdf(onLayout: (_) async => pdf.save());
  }

  static List<Map<String, dynamic>> _items(Map<String, dynamic> s) {
    final raw = s['items'];
    try {
      if (raw is String && raw.isNotEmpty) {
        final d = jsonDecode(raw);
        if (d is List) return d.whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
      }
    } catch (_) {}
    return [];
  }
}
