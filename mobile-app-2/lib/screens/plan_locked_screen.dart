import 'package:flutter/material.dart';
import '../theme/app_theme.dart';

/// Pantalla mostrada cuando el plan del negocio está bloqueado (prueba
/// vencida, suscripción suspendida). Solo el módulo Plan queda accesible.
class PlanLockedScreen extends StatelessWidget {
  const PlanLockedScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            // Animated lock icon
            Container(
              width: 80,
              height: 80,
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  colors: [
                    AppTheme.amber,
                    AppTheme.amber.withAlpha(180),
                  ],
                ),
                shape: BoxShape.circle,
                boxShadow: [
                  BoxShadow(
                    color: AppTheme.amber.withAlpha(60),
                    blurRadius: 24,
                    offset: const Offset(0, 8),
                  ),
                ],
              ),
              child: const Icon(Icons.lock_outline,
                  size: 40, color: Colors.white),
            ),
            const SizedBox(height: 24),
            Text('Plan suspendido',
                style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                      fontWeight: FontWeight.w800,
                    )),
            const SizedBox(height: 8),
            Text(
              'Tu suscripción ha expirado o está suspendida.\n'
              'El negocio está en pausa temporal.',
              textAlign: TextAlign.center,
              style: TextStyle(
                  color: isDark ? AppTheme.darkMuted : AppTheme.lightMuted,
                  fontSize: 14,
                  height: 1.5),
            ),
            const SizedBox(height: 24),
            FilledButton.icon(
              onPressed: () {
                // Navigate to plan screen via shell
                // The shell will handle this via route
              },
              icon: const Icon(Icons.workspace_premium_outlined, size: 18),
              label: const Text('Ver plan'),
              style: FilledButton.styleFrom(
                backgroundColor: AppTheme.amber,
                padding:
                    const EdgeInsets.symmetric(horizontal: 24, vertical: 12),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
