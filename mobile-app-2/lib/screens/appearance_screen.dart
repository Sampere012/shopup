import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../main.dart';
import '../theme/app_theme.dart';
import '../services/db_service.dart';
import '../services/sync_service.dart';
import '../services/theme_service.dart';
import '../widgets/common.dart';

/// Apariencia: selector de tema (light/dark/system) + configuración del sitio
/// con todos los campos del backend.
class AppearanceScreen extends StatefulWidget {
  const AppearanceScreen({super.key});
  @override
  State<AppearanceScreen> createState() => _AppearanceScreenState();
}

class _AppearanceScreenState extends State<AppearanceScreen> {
  Map<String, dynamic> data = {};
  final cName = TextEditingController();
  final cPrimary = TextEditingController();
  final cSecondary = TextEditingController();
  final cLogoUrl = TextEditingController();
  final cFavicon = TextEditingController();
  final cFooter = TextEditingController();
  bool _saving = false;

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
    final raw = await DbService.I.cacheGet('ws_appearance_get');
    if (!mounted || raw is! Map) return;
    setState(() {
      data = Map<String, dynamic>.from(raw);
      cName.text = '${data['site_name'] ?? ''}';
      cPrimary.text = '${data['primary_color'] ?? ''}';
      cSecondary.text = '${data['secondary_color'] ?? ''}';
      cLogoUrl.text = '${data['logo_url'] ?? data['logo'] ?? ''}';
      cFavicon.text = '${data['favicon'] ?? ''}';
      cFooter.text = '${data['footer_text'] ?? data['footer'] ?? ''}';
    });
  }

  Future<void> _save() async {
    setState(() => _saving = true);
    await U.handlePush(
      context,
      SyncService.I.push('ws_save_site_theme', {
        'site_name': cName.text.trim(),
        'primary_color': cPrimary.text.trim(),
        'secondary_color': cSecondary.text.trim(),
        'logo_url': cLogoUrl.text.trim(),
        'favicon': cFavicon.text.trim(),
        'footer_text': cFooter.text.trim(),
      }),
      'Apariencia guardada',
      onOk: () => SyncService.I.pullCache('ws_appearance_get', {}, 'ws_appearance_get'),
      onQueued: (qp) async {
        await DbService.I.cacheSet('ws_appearance_get', {
          'site_name': cName.text.trim(),
          'primary_color': cPrimary.text.trim(),
          'secondary_color': cSecondary.text.trim(),
          'logo_url': cLogoUrl.text.trim(),
          'favicon': cFavicon.text.trim(),
          'footer_text': cFooter.text.trim(),
        });
      },
    );
    if (mounted) setState(() => _saving = false);
  }

  @override
  Widget build(BuildContext context) {
    context.watch<SyncNotifier>();
    final themeSvc = context.watch<ThemeService>();

    return ListView(padding: const EdgeInsets.all(14), children: [
      // Theme selector
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              Icon(Icons.palette_outlined, color: AppTheme.primary, size: 20),
              const SizedBox(width: 8),
              Text('Tema de la aplicación',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
            ]),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(child: _themeOption(context, 'Claro', Icons.light_mode_outlined, ThemeMode.light, themeSvc.mode)),
              const SizedBox(width: 8),
              Expanded(child: _themeOption(context, 'Oscuro', Icons.dark_mode_outlined, ThemeMode.dark, themeSvc.mode)),
              const SizedBox(width: 8),
              Expanded(child: _themeOption(context, 'Sistema', Icons.phone_android_outlined, ThemeMode.system, themeSvc.mode)),
            ]),
          ]),
        ),
      ),
      const SizedBox(height: 10),

      // Site appearance — all backend fields
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              Icon(Icons.store_outlined, color: AppTheme.primary, size: 20),
              const SizedBox(width: 8),
              Text('Apariencia del sitio',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
            ]),
            const SizedBox(height: 12),
            TextField(controller: cName, decoration: const InputDecoration(labelText: 'Nombre del sitio')),
            const SizedBox(height: 10),
            TextField(controller: cPrimary, decoration: const InputDecoration(labelText: 'Color primario (#RRGGBB)', hintText: '#4F46E5')),
            const SizedBox(height: 10),
            TextField(controller: cSecondary, decoration: const InputDecoration(labelText: 'Color secundario (#RRGGBB)', hintText: '#7C3AED')),
            const SizedBox(height: 10),
            TextField(controller: cLogoUrl, decoration: const InputDecoration(labelText: 'URL del logo', hintText: 'https://...')),
            if (cLogoUrl.text.isNotEmpty) ...[
              const SizedBox(height: 8),
              ClipRRect(
                borderRadius: BorderRadius.circular(8),
                child: NetImage(cLogoUrl.text, height: 50, fit: BoxFit.contain,
                    fallback: (_) => const Text('No se pudo cargar la imagen',
                        style: TextStyle(fontSize: 11, color: Colors.grey))),
              ),
            ],
            const SizedBox(height: 10),
            TextField(controller: cFavicon, decoration: const InputDecoration(labelText: 'URL del favicon', hintText: 'https://...')),
            const SizedBox(height: 10),
            TextField(controller: cFooter, maxLines: 2, decoration: const InputDecoration(labelText: 'Texto del pie de página')),
            const SizedBox(height: 14),

            // Show all data keys from backend (read-only info)
            if (data.isNotEmpty) ...[
              Text('Datos del servidor',
                  style: TextStyle(fontSize: 12, color: Colors.grey[600], fontWeight: FontWeight.w600)),
              const SizedBox(height: 6),
              ...data.entries.where((e) => ![
                'site_name', 'primary_color', 'secondary_color',
                'logo_url', 'logo', 'favicon', 'footer_text', 'footer'
              ].contains(e.key)).take(10).map((e) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 2),
                child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                  Flexible(child: Text('${e.key}', style: TextStyle(fontSize: 11, color: Colors.grey[500]),
                      overflow: TextOverflow.ellipsis)),
                  Flexible(child: Text('${e.value}', textAlign: TextAlign.right,
                      style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600))),
                ]),
              )),
            ],
            const SizedBox(height: 14),

            FilledButton.icon(
              icon: _saving
                  ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.save_outlined, size: 18),
              label: Text(_saving ? 'Guardando…' : 'Guardar apariencia'),
              onPressed: _saving ? null : _save,
            ),
          ]),
        ),
      ),
    ]);
  }

  Widget _themeOption(BuildContext context, String label, IconData icon, ThemeMode mode, ThemeMode current) {
    final selected = mode == current;
    return InkWell(
      borderRadius: BorderRadius.circular(12),
      onTap: () => ThemeService.I.setMode(mode),
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 12),
        decoration: BoxDecoration(
          color: selected ? AppTheme.primary.withAlpha(20) : null,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: selected ? AppTheme.primary : AppTheme.lightBorder, width: selected ? 2 : 1),
        ),
        child: Column(children: [
          Icon(icon, size: 22, color: selected ? AppTheme.primary : AppTheme.lightMuted),
          const SizedBox(height: 4),
          Text(label, style: TextStyle(fontSize: 12,
              fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
              color: selected ? AppTheme.primary : null)),
        ]),
      ),
    );
  }
}
