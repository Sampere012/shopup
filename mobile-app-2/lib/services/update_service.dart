import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../config.dart';
import '../theme/app_theme.dart';
import 'api_service.dart';

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
          if (hasApk)
            FilledButton.icon(
              onPressed: () {
                Navigator.pop(ctx);
                // TODO: launch URL for APK download
              },
              icon: const Icon(Icons.download, size: 18),
              label: const Text('Descargar'),
            ),
        ],
      ),
    );
  }
}
