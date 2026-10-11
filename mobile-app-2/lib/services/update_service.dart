import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';
import '../config.dart';
import '../theme/app_theme.dart';
import 'api_service.dart';
import '../widgets/common.dart' show U;


/// Servicio de actualizaciones con changelog y comparación semver.
class UpdateService extends ChangeNotifier {
  UpdateService._();
  static final UpdateService I = UpdateService._();

  static const _infoKey = 'app_version_info';
  static const _notifiedKey = 'app_notified_version';

  bool hasUpdate = false;
  Map<String, dynamic>? updateInfo;

  static int compareVersions(String a, String b) {
    final pa = a.split('.').map((n) => int.tryParse(n) ?? 0).toList();
    final pb = b.split('.').map((n) => int.tryParse(n) ?? 0).toList();
    for (var i = 0; i < [pa.length, pb.length].reduce((a, b) => a > b ? a : b); i++) {
      final x = i < pa.length ? pa[i] : 0;
      final y = i < pb.length ? pb[i] : 0;
      if (x > y) return 1;
      if (x < y) return -1;
    }
    return 0;
  }

  Future<Map<String, dynamic>?> fetchInfo() async {
    try {
      final d = await ApiService.I.req('ws_app_version', {});
      if (d is Map) {
        final sp = await SharedPreferences.getInstance();
        await sp.setString(_infoKey, jsonEncode(d));
        return Map<String, dynamic>.from(d);
      }
    } catch (_) {}
    return null;
  }

  Future<bool> check({bool silent = true}) async {
    final info = await fetchInfo();
    if (info == null || info['version'] == null) return false;
    final serverVersion = '${info['version']}';
    final newVersion = compareVersions(serverVersion, AppConfig.appVersion) > 0;
    hasUpdate = newVersion;
    updateInfo = info;
    notifyListeners();
    if (newVersion && !silent) {
      final sp = await SharedPreferences.getInstance();
      await sp.setString(_notifiedKey, serverVersion);
    }
    return newVersion;
  }

  /// Abre la descarga de la nueva versión y devuelve true si algo se lanzó.
  ///
  /// Prueba primero el APK (el navegador/DownloadManager del sistema lo
  /// descarga y la notificación permite instalarlo) y, si eso no abre nada,
  /// la página de releases del repo. Nunca devuelve true sin haber abierto
  /// realmente un enlace: el navbar (`_updateNow`) y el botón del diálogo
  /// deciden qué avisar según el resultado, así que un fallo se comunica
  /// en lugar de fingir que la descarga va en marcha.
  static Future<bool> launchDownload(Map<String, dynamic> info) async {
    final apk = '${info['apk_url'] ?? ''}'.trim();
    final release = '${info['release_url'] ?? ''}'.trim();
    for (final candidate in <String>[apk, release]) {
      if (candidate.isEmpty) continue;
      try {
        final ok = await launchUrl(Uri.parse(candidate),
            mode: LaunchMode.externalApplication);
        if (ok) return true;
      } catch (_) {
        // Sin visor para este esquema/tipo: probamos con la siguiente URL.
      }
    }
    return false;
  }

  /// Show update dialog with changelog.
  static void showUpdateDialog(BuildContext context, Map<String, dynamic> info) {
    final version = '${info['version'] ?? ''}';
    final changelog = '${info['changelog'] ?? ''}';
    final hasApk = info['has_apk'] == true;

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
        title: Row(children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              gradient: AppTheme.successGradient,
              borderRadius: BorderRadius.circular(10),
            ),
            child: const Icon(Icons.system_update, color: Colors.white, size: 20),
          ),
          const SizedBox(width: 10),
          Expanded(child: Text('Nueva versión $version',
              style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16))),
        ]),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Tu versión: ${AppConfig.appVersion}',
                style: TextStyle(color: Colors.grey[600], fontSize: 13)),
            const SizedBox(height: 4),
            Text('Nueva: $version',
                style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
            if (changelog.isNotEmpty) ...[
              const SizedBox(height: 12),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AppTheme.primary.withAlpha(15),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: AppTheme.primary.withAlpha(30)),
                ),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  const Text('Novedades:', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 12)),
                  const SizedBox(height: 4),
                  Text(changelog, style: const TextStyle(fontSize: 12, height: 1.5)),
                ]),
              ),
            ],
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Más tarde'),
          ),
          FilledButton.icon(
              onPressed: () async {
                Navigator.pop(ctx);
                final ok = await launchDownload(info);
                if (context.mounted) {
                  U.toast(
                      context,
                      ok
                          ? 'Descargando la actualización. Busca la notificación al terminar'
                          : 'No se pudo abrir la descarga. Entra en la página de versiones del repo',
                      kind: ok ? 'ok' : 'err');
                }
              },
              icon: const Icon(Icons.download, size: 18),
              label: Text(hasApk ? 'Descargar' : 'Ver versiones'),
            ),
        ],
      ),
    );
  }

  /// Shows release notes once after the installed app version changes.
  static Future<void> showInstalledChangelog(
      BuildContext context, Map<String, dynamic> info) async {
    final version = '${info['version'] ?? ''}';
    final date = '${info['release_date'] ?? ''}'.trim();
    final changelog = '${info['changelog'] ?? ''}'.trim();
    final lines = changelog
        .split(RegExp(r'\r?\n'))
        .map((line) => line.trim())
        .where((line) => line.isNotEmpty)
        .toList();

    await showDialog<void>(
      context: context,
      builder: (ctx) => Dialog(
        backgroundColor: const Color(0xFF0F172A),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              const Icon(Icons.terminal, color: Color(0xFF4ADE80), size: 20),
              const SizedBox(width: 8),
              const Expanded(
                child: Text('ShopUp · Registro de cambios',
                    style: TextStyle(
                        color: Colors.white,
                        fontWeight: FontWeight.w700,
                        fontFamily: 'monospace')),
              ),
              IconButton(
                tooltip: 'Cerrar',
                onPressed: () => Navigator.pop(ctx),
                icon: const Icon(Icons.close, color: Colors.white70, size: 18),
              ),
            ]),
            const Divider(color: Color(0xFF334155)),
            Text('v$version${date.isNotEmpty ? '  ·  $date' : ''}',
                style: const TextStyle(
                    color: Color(0xFF93C5FD),
                    fontSize: 12,
                    fontFamily: 'monospace')),
            const SizedBox(height: 10),
            const Text('NOVEDADES',
                style: TextStyle(
                    color: Color(0xFF4ADE80),
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    fontFamily: 'monospace')),
            const SizedBox(height: 8),
            if (lines.isEmpty)
              const Text('Esta versión incluye mejoras y correcciones.',
                  style: TextStyle(
                      color: Colors.white70,
                      fontSize: 13,
                      fontFamily: 'monospace'))
            else
              ...List.generate(lines.length, (index) => Padding(
                    padding: const EdgeInsets.only(bottom: 5),
                    child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          SizedBox(
                            width: 24,
                            child: Text('${index + 1}',
                                style: const TextStyle(
                                    color: Color(0xFF64748B),
                                    fontSize: 12,
                                    fontFamily: 'monospace')),
                          ),
                          const Text('› ',
                              style: TextStyle(
                                  color: Color(0xFF4ADE80),
                                  fontFamily: 'monospace')),
                          Expanded(
                            child: Text(lines[index],
                                style: const TextStyle(
                                    color: Colors.white70,
                                    fontSize: 13,
                                    height: 1.4,
                                    fontFamily: 'monospace')),
                          ),
                        ]),
                  )),
            const SizedBox(height: 12),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                onPressed: () => Navigator.pop(ctx),
                child: const Text('Entendido'),
              ),
            ),
          ]),
        ),
      ),
    );
  }
}
