import 'package:flutter/material.dart';
import '../theme/app_theme.dart';

/// Loader global con animación de pulso (equivalente a ws-loader en CSS).
/// Se muestra como overlay cuando hay una operación en curso.
class GlobalLoader extends StatefulWidget {
  final bool visible;
  final String? message;

  const GlobalLoader({super.key, required this.visible, this.message});

  @override
  State<GlobalLoader> createState() => _GlobalLoaderState();
}

class _GlobalLoaderState extends State<GlobalLoader>
    with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl;
  late final Animation<double> _scaleAnim;
  late final Animation<double> _opacityAnim;

  @override
  void initState() {
    super.initState();
    _ctrl = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1100),
    );
    _scaleAnim = TweenSequence<double>([
      TweenSequenceItem(tween: Tween(begin: 0.8, end: 1.0), weight: 50),
      TweenSequenceItem(tween: Tween(begin: 1.0, end: 0.8), weight: 50),
    ]).animate(CurvedAnimation(parent: _ctrl, curve: Curves.easeInOut));
    _opacityAnim = TweenSequence<double>([
      TweenSequenceItem(tween: Tween(begin: 0.6, end: 1.0), weight: 50),
      TweenSequenceItem(tween: Tween(begin: 1.0, end: 0.6), weight: 50),
    ]).animate(CurvedAnimation(parent: _ctrl, curve: Curves.easeInOut));
    if (widget.visible) _ctrl.repeat();
  }

  @override
  void didUpdateWidget(GlobalLoader old) {
    super.didUpdateWidget(old);
    if (widget.visible && !_ctrl.isAnimating) {
      _ctrl.repeat();
    } else if (!widget.visible && _ctrl.isAnimating) {
      _ctrl.stop();
    }
  }

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedOpacity(
      opacity: widget.visible ? 1.0 : 0.0,
      duration: const Duration(milliseconds: 200),
      child: IgnorePointer(
        ignoring: !widget.visible,
        child: Container(
          color: const Color(0x590F172A),
          child: Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                AnimatedBuilder(
                  animation: _ctrl,
                  builder: (_, child) {
                    return Transform.scale(
                      scale: _scaleAnim.value,
                      child: Container(
                        width: 52,
                        height: 52,
                        decoration: BoxDecoration(
                          color: Colors.white,
                          borderRadius: BorderRadius.circular(16),
                          boxShadow: [
                            BoxShadow(
                              color: AppTheme.primary.withAlpha(
                                  (_opacityAnim.value * 100).toInt()),
                              blurRadius: 20,
                              spreadRadius: _opacityAnim.value * 8,
                            ),
                          ],
                        ),
                        child: const Center(
                          child: CircularProgressIndicator(
                            strokeWidth: 2.5,
                            color: AppTheme.primary,
                          ),
                        ),
                      ),
                    );
                  },
                ),
                if (widget.message != null) ...[
                  const SizedBox(height: 14),
                  Text(widget.message!,
                      style: const TextStyle(
                          color: Colors.white,
                          fontSize: 13,
                          fontWeight: FontWeight.w500)),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }
}
