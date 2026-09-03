import 'dart:io';

import 'package:flutter/material.dart';
import '../theme/app_theme.dart';
import '../services/api_service.dart';
import '../services/image_cache_service.dart';

/// Utilidades compartidas por todos los módulos, con soporte dark mode.
class U {
  /// Formato de dinero: PRECIO primero y el código de moneda después,
  /// separados por un espacio (p.ej. "1500.00 CUP", nunca "CUP1500.00").
  static String money(num v, String currency, {int dec = 2}) =>
      '${v.toStringAsFixed(dec)} $currency';

  static void toast(BuildContext context, String msg,
      {String kind = 'ok'}) {
    final color = switch (kind) {
      'err' => AppTheme.danger,
      'warn' => AppTheme.amber,
      _ => AppTheme.success,
    };
    final icon = switch (kind) {
      'err' => Icons.error_outline,
      'warn' => Icons.warning_amber_outlined,
      _ => Icons.check_circle_outline,
    };
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(
        content: Row(
          children: [
            Icon(icon, color: Colors.white, size: 18),
            const SizedBox(width: 8),
            Expanded(
                child: Text(msg,
                    style:
                        const TextStyle(color: Colors.white, fontSize: 13))),
          ],
        ),
        backgroundColor: color,
        behavior: SnackBarBehavior.floating,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
        duration: const Duration(seconds: 3),
      ));
  }

  /// Maneja el resultado de un Sync.push.
  /// Después de un push exitoso (online), ejecuta [onOk] (pull ligero).
  /// Cuando la operación queda encolada (offline), ejecuta [onQueued]
  /// para actualizar optimísticamente el SQLite local y que la UI
  /// refleje el cambio al momento, sin esperar a que se vacíe la cola.
  static Future<bool> handlePush(
      BuildContext context, Future<dynamic> future, String okMsg,
      {Future<void> Function()? onOk,
       Future<void> Function(Map<String, dynamic> payload)? onQueued}) async {
    try {
      final res = await future;
      if (res is Map && res['queued'] == true) {
        if (context.mounted) {
          toast(context, 'Guardado sin conexión — se enviará al reconectar',
              kind: 'warn');
        }
        // Actualización optimista: guardamos en SQLite local para que
        // la pantalla refresque al instante con los datos nuevos.
        if (onQueued != null) {
          try { await onQueued(res['payload'] as Map<String, dynamic>? ?? {}); } catch (_) {}
        }
      } else {
        if (context.mounted) toast(context, okMsg);
        if (onOk != null) {
          try { await onOk(); } catch (_) {}
        }
      }
      return true;
    } on ApiException catch (e) {
      if (context.mounted) toast(context, e.message, kind: 'err');
      return false;
    } catch (_) {
      if (context.mounted) toast(context, 'Error inesperado', kind: 'err');
      return false;
    }
  }

  /// Confirmación con estilo moderno.
  static Future<bool> confirm(BuildContext context, String message,
      {String action = 'Confirmar'}) async {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final res = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: isDark ? AppTheme.darkCard : Colors.white,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text(action, style: const TextStyle(fontWeight: FontWeight.w700)),
        content: Text(message),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('Cancelar')),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: Text(action)),
        ],
      ),
    );
    return res == true;
  }

  static String fmtDate(Object? iso) {
    if (iso == null || '$iso'.isEmpty) return '';
    final d = DateTime.tryParse('$iso');
    if (d == null) return '$iso';
    final two = (int n) => n.toString().padLeft(2, '0');
    return '${two(d.day)}/${two(d.month)}/${d.year} ${two(d.hour)}:${two(d.minute)}';
  }

  /// Badge chip con color.
  static Widget badge(String text, {Color? color, bool small = false}) {
    final c = color ?? AppTheme.primary;
    return Container(
      padding: EdgeInsets.symmetric(
          horizontal: small ? 6 : 10, vertical: small ? 2 : 4),
      decoration: BoxDecoration(
        color: c.withAlpha(25),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: c.withAlpha(50)),
      ),
      child: Text(text,
          style: TextStyle(
            color: c,
            fontSize: small ? 10 : 12,
            fontWeight: FontWeight.w700,
          )),
    );
  }

  /// Stat card con gradiente (para dashboard).
  static Widget gradientStat({
    required IconData icon,
    required String value,
    required String label,
    required List<Color> colors,
    VoidCallback? onTap,
  }) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: colors,
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(14),
          boxShadow: [
            BoxShadow(
              color: colors.first.withAlpha(50),
              blurRadius: 12,
              offset: const Offset(0, 4),
            ),
          ],
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, size: 18, color: Colors.white.withAlpha(220)),
            const SizedBox(height: 4),
            Text(value,
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 17,
                    fontWeight: FontWeight.w800)),
            Text(label,
                style: TextStyle(
                    color: Colors.white.withAlpha(180), fontSize: 10)),
          ],
        ),
      ),
    );
  }

  /// Glass card container.
  static Widget glassCard({required Widget child, EdgeInsets? padding}) {
    return Container(
      padding: padding ?? const EdgeInsets.all(16),
      decoration: AppTheme.glassCard(),
      child: child,
    );
  }
}

/// Imagen de red con CACHÉ PERSISTENTE EN DISCO: la primera vez que se ve
/// con conexión queda guardada (ImageDisk) y después se muestra incluso sin
/// internet. Placeholder con spinner mientras baja y fallback personalizable.
class NetImage extends StatefulWidget {
  final String url;
  final double? width;
  final double? height;
  final BoxFit fit;
  final int? memCacheWidth;
  final Widget Function(BuildContext context)? fallback;

  const NetImage(
    this.url, {
    super.key,
    this.width,
    this.height,
    this.fit = BoxFit.cover,
    this.memCacheWidth,
    this.fallback,
  });

  @override
  State<NetImage> createState() => _NetImageState();
}

class _NetImageState extends State<NetImage> {
  File? _file;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant NetImage old) {
    super.didUpdateWidget(old);
    if (old.url != widget.url) _load();
  }

  Future<void> _load() async {
    final f = await ImageDisk.get(widget.url);
    if (!mounted) return;
    setState(() {
      _file = f;
      _failed = f == null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final boxColor =
        Theme.of(context).colorScheme.surfaceContainerHighest.withAlpha(80);
    if (_file != null) {
      return ClipRect(
        child: Image.file(_file!,
            width: widget.width,
            height: widget.height,
            fit: widget.fit,
            cacheWidth: widget.memCacheWidth,
            errorBuilder: (_, __, ___) =>
                widget.fallback?.call(context) ??
                Container(
                    width: widget.width,
                    height: widget.height,
                    color: boxColor)),
      );
    }
    if (_failed) return widget.fallback?.call(context) ?? SizedBox(width: widget.width, height: widget.height);
    // Descargando por primera vez.
    return Container(
      width: widget.width,
      height: widget.height,
      color: boxColor,
      child: const Center(
        child: SizedBox(
          width: 16,
          height: 16,
          child: CircularProgressIndicator(strokeWidth: 2),
        ),
      ),
    );
  }
}

/// Visor de imagen a PANTALLA COMPLETA: solo la imagen sobre fondo negro,
/// zoom con pellizco/doble arrastre y botón de flecha para regresar.
class ImageViewerScreen extends StatelessWidget {
  final String url;
  const ImageViewerScreen({super.key, required this.url});

  /// Abre el visor (no hace nada si la URL está vacía).
  static Future<void> show(BuildContext context, String url) {
    if (url.isEmpty) return Future.value();
    return Navigator.of(context).push(MaterialPageRoute(
      fullscreenDialog: true,
      builder: (_) => ImageViewerScreen(url: url),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: Stack(children: [
        Positioned.fill(
          child: FutureBuilder<File?>(
            future: ImageDisk.get(url),
            builder: (_, snap) {
              if (snap.connectionState != ConnectionState.done) {
                return const Center(
                  child: SizedBox(
                    width: 26,
                    height: 26,
                    child:
                        CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                  ),
                );
              }
              final f = snap.data;
              if (f == null) {
                return Center(
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    const Icon(Icons.broken_image_outlined,
                        color: Colors.white38, size: 44),
                    const SizedBox(height: 8),
                    Text('No se pudo cargar la imagen',
                        style: TextStyle(color: Colors.white38, fontSize: 13)),
                  ]),
                );
              }
              return InteractiveViewer(
                maxScale: 6,
                boundaryMargin: const EdgeInsets.all(80),
                child: Center(child: Image.file(f, fit: BoxFit.contain)),
              );
            },
          ),
        ),
        SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(10),
            child: Align(
              alignment: Alignment.topLeft,
              child: Material(
                color: Colors.white.withAlpha(28),
                shape: const CircleBorder(),
                child: IconButton(
                  icon: const Icon(Icons.arrow_back, color: Colors.white),
                  tooltip: 'Regresar',
                  onPressed: () => Navigator.pop(context),
                ),
              ),
            ),
          ),
        ),
      ]),
    );
  }
}

/// Gesto de pellizco tipo galería de Samsung sobre una grilla:
/// acercar (zoom in) → ítems más grandes / menos columnas;
/// alejar (zoom out) → más columnas / ítems más pequeños.
/// Usa Listener (raw pointer events) para PASAR por encima del gesture
/// arena de Flutter: así funciona incluso cuando el dedo toca un hijo
/// (Card, ListTile, etc.) que ya tiene GestureDetector propio.
/// Solo reacciona con 2 dedos, así que no interfiere con el scroll
/// normal ni con los toques en los ítems.
class PinchDensity extends StatefulWidget {
  final int cols;
  final int minCols;
  final int maxCols;
  final ValueChanged<int> onChanged;
  final Widget child;

  const PinchDensity({
    super.key,
    required this.cols,
    required this.onChanged,
    this.minCols = 1,
    this.maxCols = 5,
    required this.child,
  });

  @override
  State<PinchDensity> createState() => _PinchDensityState();
}

class _PinchDensityState extends State<PinchDensity> {
  // Raw pointer tracking — no gesture arena, no competition with children.
  final Map<int, Offset> _pointers = {};
  double? _baseDistance;
  int? _pinchColsStart;
  bool _pinching = false;

  // Dead zone: solo cambia cols cuando la distancia entre dedos
  // varía ≥ 15 % respecto al inicio del pinch.
  static const _deadZone = 0.15;

  @override
  void dispose() {
    super.dispose();
  }

  static double _dist(Offset a, Offset b) => (a - b).distance;

  @override
  Widget build(BuildContext context) {
    return Listener(
      behavior: HitTestBehavior.translucent,
      onPointerDown: (e) {
        _pointers[e.pointer] = e.position;
        if (_pointers.length == 2) {
          final pts = _pointers.values.toList();
          _baseDistance = _dist(pts[0], pts[1]);
          _pinchColsStart = widget.cols;
          _pinching = false;
        }
      },
      onPointerMove: (e) {
        _pointers[e.pointer] = e.position;
        if (_pointers.length == 2 &&
            _baseDistance != null &&
            _pinchColsStart != null) {
          final pts = _pointers.values.toList();
          final curDist = _dist(pts[0], pts[1]);
          final ratio = curDist / _baseDistance!;
          // Dead zone: ignorar micro-movimientos (< 15 % cambio)
          if (!_pinching && (ratio - 1.0).abs() < _deadZone) return;
          _pinching = true;
          final rawTarget = _pinchColsStart! / ratio;
          final target =
              rawTarget.round().clamp(widget.minCols, widget.maxCols);
          if (target != widget.cols) widget.onChanged(target);
        }
      },
      onPointerUp: (e) {
        _pointers.remove(e.pointer);
        if (_pointers.length < 2) {
          _baseDistance = null;
          _pinchColsStart = null;
          _pinching = false;
        }
      },
      onPointerCancel: (e) {
        _pointers.remove(e.pointer);
        if (_pointers.length < 2) {
          _baseDistance = null;
          _pinchColsStart = null;
          _pinching = false;
        }
      },
      child: widget.child,
    );
  }
}
