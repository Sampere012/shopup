import 'dart:ui' as ui;

import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../theme/app_theme.dart';

/// Gráfica de fluctuación del precio de un producto (venta y costo) por
/// semana / mes / año, alimentada por `ws_mobile_price_history`.
///
/// Pinta con CustomPainter (sin dependencias extra): la serie completa se
/// traza como una polilínea y se resalta el punto seleccionado. Las pestañas
/// sólo cambian el rango visible — los datos se descargan una sola vez.
class PriceHistorySheet {
  static Future<void> show(BuildContext context, Map<String, dynamic> product) {
    final pid = int.tryParse('${product['id']}') ?? 0;
    final name = '${product['name'] ?? ''}';
    final cur = '${product['currency'] ?? ''}'.isNotEmpty
        ? '${product['currency']}'
        : AuthService.I.currency;
    return showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      builder: (sheetCtx) => SafeArea(
        child: ConstrainedBox(
          constraints:
              BoxConstraints(maxHeight: MediaQuery.of(sheetCtx).size.height * 0.85),
          child: _PriceHistoryBody(productId: pid, title: name, currency: cur),
        ),
      ),
    );
  }
}

class _PriceHistoryBody extends StatefulWidget {
  const _PriceHistoryBody(
      {required this.productId, required this.title, required this.currency});
  final int productId;
  final String title;
  final String currency;

  @override
  State<_PriceHistoryBody> createState() => _PriceHistoryBodyState();
}

class _PriceHistoryBodyState extends State<_PriceHistoryBody> {
  late Future<List<Map<String, dynamic>>> _future;
  String _range = 'month'; // week | month | year

  @override
  void initState() {
    super.initState();
    _future = _load();
  }

  Future<List<Map<String, dynamic>>> _load() async {
    try {
      final d = await ApiService.I.req('ws_mobile_price_history', {
        'product_id': widget.productId,
      });
      final list = (d['history'] as List?) ?? [];
      return List<Map<String, dynamic>>.from(list);
    } catch (_) {
      return <Map<String, dynamic>>[];
    }
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(18, 16, 18, 18),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text('Historial de precios — ${widget.title}',
              style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
          const SizedBox(height: 12),
          // Pestañas de rango
          SegmentedButton<String>(
            segments: const [
              ButtonSegment(value: 'week', label: Text('Semana')),
              ButtonSegment(value: 'month', label: Text('Mes')),
              ButtonSegment(value: 'year', label: Text('Año')),
            ],
            selected: {_range},
            onSelectionChanged: (s) => setState(() => _range = s.first),
          ),
          const SizedBox(height: 12),
          FutureBuilder<List<Map<String, dynamic>>>(
            future: _future,
            builder: (_, snap) {
              if (snap.connectionState != ConnectionState.done) {
                return const Padding(
                  padding: EdgeInsets.all(24),
                  child: Center(child: SizedBox(width: 20, height: 20,
                      child: CircularProgressIndicator(strokeWidth: 2))),
                );
              }
              final rows = snap.data ?? [];
              if (rows.isEmpty) {
                return const Padding(
                  padding: EdgeInsets.all(24),
                  child: Text(
                    'Sin cambios de precio registrados todavía.\n'
                    'Cada vez que edites el precio de venta o costo, quedará aquí.',
                    textAlign: TextAlign.center,
                  ),
                );
              }
              final cutoff = _cutoff();
              final visible = rows
                  .where((r) => DateTime.tryParse('${r['created_at']}')
                          ?.isAfter(cutoff) ??
                      true)
                  .toList();
              if (visible.isEmpty) {
                return const Padding(
                  padding: EdgeInsets.all(24),
                  child: Text('Sin cambios en este rango.',
                      textAlign: TextAlign.center),
                );
              }
              return Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _Legend(cur: widget.currency),
                  const SizedBox(height: 8),
                  SizedBox(
                    height: 180,
                    child: CustomPaint(
                      painter: _PriceChartPainter(visible, widget.currency),
                      child: const SizedBox.expand(),
                    ),
                  ),
                  const SizedBox(height: 10),
                  ConstrainedBox(
                    constraints: const BoxConstraints(maxHeight: 240),
                    child: ListView.separated(
                      shrinkWrap: true,
                      itemCount: visible.length,
                      separatorBuilder: (_, __) => const Divider(height: 1),
                      itemBuilder: (_, i) {
                        final r = visible[i];
                        final dt = DateTime.tryParse('${r['created_at']}');
                        final ns = (r['new_sale'] as num?) ?? 0;
                        final os = (r['old_sale'] as num?) ?? 0;
                        final diff = ns - os;
                        final color =
                            diff > 0 ? AppTheme.success : AppTheme.danger;
                        return ListTile(
                          dense: true,
                          title: Text(
                            '${_fmt(dt)} · ${_money(ns, widget.currency)}',
                            style: const TextStyle(
                                fontWeight: FontWeight.w600, fontSize: 13),
                          ),
                          subtitle: Text(
                            'Antes: ${_money(os, widget.currency)}',
                            style:
                                TextStyle(fontSize: 12, color: Colors.grey[600]),
                          ),
                          trailing: Text(
                            '${diff > 0 ? '+' : ''}${_money(diff, widget.currency)}',
                            style: TextStyle(
                                fontWeight: FontWeight.w700,
                                fontSize: 13,
                                color: color),
                          ),
                        );
                      },
                    ),
                  ),
                ],
              );
            },
          ),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cerrar'),
          ),
        ],
      ),
    );
  }

  DateTime _cutoff() {
    final now = DateTime.now();
    switch (_range) {
      case 'week':
        return now.subtract(const Duration(days: 7));
      case 'year':
        return DateTime(now.year - 1, now.month, now.day);
      case 'month':
      default:
        return DateTime(now.year, now.month - 1, now.day);
    }
  }

  static String _fmt(DateTime? dt) =>
      dt == null ? '—' : DateFormat('dd/MM/yyyy').format(dt);

  static String _money(num v, String cur) => '$cur ${v.toStringAsFixed(2)}';
}

class _Legend extends StatelessWidget {
  const _Legend({required this.cur});
  final String cur;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        _dot(AppTheme.success, 'Precio de venta'),
        const SizedBox(width: 16),
        _dot(Colors.orange, 'Costo'),
        const SizedBox(width: 16),
        Text('Moneda: $cur', style: TextStyle(fontSize: 12, color: Colors.grey[600])),
      ],
    );
  }

  Widget _dot(Color c, String label) => Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(width: 10, height: 10, color: c),
          const SizedBox(width: 4),
          Text(label, style: TextStyle(fontSize: 12, color: Colors.grey[700])),
        ],
      );
}

/// Pinta la polilínea de precio de venta (verde) y de costo (naranja) con
/// puntos resaltados en cada cambio. Sin datos dibuja un placeholder neutro.
class _PriceChartPainter extends CustomPainter {
  _PriceChartPainter(this.rows, this.currency);
  final List<Map<String, dynamic>> rows;
  final String currency;

  @override
  void paint(Canvas canvas, Size size) {
    if (rows.length < 2) {
      final tp = TextPainter(
        text: const TextSpan(
            text: 'Pocos datos para graficar',
            style: TextStyle(fontSize: 12, color: Colors.grey)),
        textDirection: ui.TextDirection.ltr,
      )..layout();
      tp.paint(canvas,
          Offset((size.width - tp.width) / 2, (size.height - tp.height) / 2));
      return;
    }

    // Orden ascendente por fecha (la API trae DESC).
    final pts = rows.reversed.toList();
    final sales = pts
        .map((r) => ((r['new_sale'] as num?) ?? 0).toDouble())
        .toList();
    final costs = pts
        .map((r) => ((r['new_cost'] as num?) ?? 0).toDouble())
        .toList();

    double minV = double.infinity, maxV = double.negativeInfinity;
    for (final v in [...sales, ...costs]) {
      if (v < minV) minV = v;
      if (v > maxV) maxV = v;
    }
    if (!minV.isFinite || maxV <= minV) {
      minV = 0;
      maxV = 1;
    }
    // Padding vertical para que la línea no pegue a los bordes.
    final pad = (maxV - minV) * 0.15;
    minV -= pad;
    maxV += pad;

    final padL = 44.0, padR = 8.0, padT = 14.0, padB = 22.0;
    final w = size.width - padL - padR;
    final h = size.height - padT - padB;

    double dx(int i) => padL + (w * i / (pts.length - 1));
    double dy(double v) => padT + h - (h * (v - minV) / (maxV - minV));

    // Ejes
    final axis = Paint()
      ..color = Colors.grey.shade300
      ..strokeWidth = 1;
    canvas.drawLine(Offset(padL, padT), Offset(padL, padT + h), axis);
    canvas.drawLine(Offset(padL, padT + h), Offset(padL + w, padT + h), axis);

    // Etiquetas min/max
    final lblStyle =
        TextStyle(fontSize: 10, color: Colors.grey.shade600);
    final tpMax = TextPainter(
        text: TextSpan(text: maxV.toStringAsFixed(0), style: lblStyle),
        textDirection: ui.TextDirection.ltr)
      ..layout();
    tpMax.paint(canvas, Offset(padL - tpMax.width - 4, padT - 2));
    final tpMin = TextPainter(
        text: TextSpan(text: minV.toStringAsFixed(0), style: lblStyle),
        textDirection: ui.TextDirection.ltr)
      ..layout();
    tpMin.paint(canvas, Offset(padL - tpMin.width - 4, padT + h - 8));

    void drawLine(List<double> vals, Color c) {
      final path = Path();
      for (int i = 0; i < vals.length; i++) {
        final x = dx(i), y = dy(vals[i]);
        i == 0 ? path.moveTo(x, y) : path.lineTo(x, y);
      }
      canvas.drawPath(
          path,
          Paint()
            ..color = c
            ..style = PaintingStyle.stroke
            ..strokeWidth = 2.2
            ..strokeCap = StrokeCap.round
            ..strokeJoin = StrokeJoin.round);
      // Puntos
      for (int i = 0; i < vals.length; i++) {
        canvas.drawCircle(Offset(dx(i), dy(vals[i])), 2.6, Paint()..color = c);
      }
    }

    drawLine(costs, Colors.orange);
    drawLine(sales, AppTheme.success);
  }

  @override
  bool shouldRepaint(covariant _PriceChartPainter old) =>
      old.rows != rows || old.currency != currency;
}
