import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../config.dart';
import '../theme/app_theme.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/saved_accounts_service.dart';
import '../services/sync_service.dart';
import '../services/update_service.dart';
import '../widgets/common.dart' show U;

/// Mi cuenta: datos del usuario, actualización de app y cambio de cuenta.
class AccountScreen extends StatefulWidget {
  const AccountScreen({super.key});

  @override
  State<AccountScreen> createState() => _AccountScreenState();
}

class _AccountScreenState extends State<AccountScreen> {
  bool _checkingUpdate = false;
  bool _hasUpdate = false;
  Map<String, dynamic>? _updateInfo;

  @override
  void initState() {
    super.initState();
    _checkUpdate();
  }

  Future<void> _checkUpdate() async {
    final has = await UpdateService.I.check(silent: true);
    if (mounted) {
      setState(() {
        _hasUpdate = has;
        _updateInfo = UpdateService.I.updateInfo;
      });
    }
  }

  Future<void> _switchTo(BuildContext context, SavedAccount acc) async {
    try {
      await AuthService.I.login(acc.user, acc.pass, server: acc.server);
      await SyncService.I.start();
      unawaited(SyncService.I.syncNow());
    } catch (_) {
      if (context.mounted) {
        U.toast(context, 'No se pudo cambiar a ${acc.name}. Inicia sesión manualmente.', kind: 'err');
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthService>();
    final me = auth.me ?? {};
    final savedAccounts = context.watch<SavedAccountsService>();
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return ListView(padding: const EdgeInsets.all(14), children: [
      // Profile card
      Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: isDark
                ? [const Color(0xFF1E293B), const Color(0xFF334155)]
                : [AppTheme.primary, AppTheme.primaryDark],
            begin: Alignment.topLeft, end: Alignment.bottomRight,
          ),
          borderRadius: BorderRadius.circular(14),
          boxShadow: [BoxShadow(color: AppTheme.primary.withAlpha(50), blurRadius: 16, offset: const Offset(0, 6))],
        ),
        padding: const EdgeInsets.all(20),
        child: Row(children: [
          CircleAvatar(
            radius: 28,
            backgroundColor: Colors.white.withAlpha(40),
            child: Text(
              '${'${me['name'] ?? '?'}'.isNotEmpty ? '${me['name']}'[0].toUpperCase() : '?'}',
              style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w700),
            ),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${me['name'] ?? ''}',
                  style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w800)),
              Text('${me['email'] ?? ''}',
                  style: TextStyle(color: Colors.white.withAlpha(180), fontSize: 13)),
            ]),
          ),
        ]),
      ),
      const SizedBox(height: 12),

      // Info card
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(children: [
            _infoRow('Rol', '${me['roleLabel'] ?? me['role'] ?? ''}'),
            _infoRow('Negocio', auth.businessName),
            _infoRow('Servidor', ApiService.I.server),
            _infoRow('Versión', 'v${AppConfig.appVersion}'),
          ]),
        ),
      ),

      // ── Actualización de app ──
      const SizedBox(height: 10),
      Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Icon(
                _hasUpdate ? Icons.system_update : Icons.check_circle_outline,
                size: 20,
                color: _hasUpdate ? AppTheme.amber : AppTheme.success,
              ),
              const SizedBox(width: 8),
              Text('Actualización de la app',
                  style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
            ]),
            const SizedBox(height: 8),
            if (_hasUpdate && _updateInfo != null) ...[
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: AppTheme.amber.withAlpha(15),
                  borderRadius: BorderRadius.circular(10),
                  border: Border.all(color: AppTheme.amber.withAlpha(40)),
                ),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    const Icon(Icons.new_releases_outlined, size: 16, color: AppTheme.amber),
                    const SizedBox(width: 6),
                    Text('Nueva versión disponible: ${_updateInfo!['version'] ?? ''}',
                        style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
                  ]),
                  if ('${_updateInfo!['changelog'] ?? ''}'.isNotEmpty) ...[
                    const SizedBox(height: 6),
                    Text('${_updateInfo!['changelog']}', style: TextStyle(fontSize: 12, height: 1.4, color: Colors.grey[700])),
                  ],
                  const SizedBox(height: 10),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton.icon(
                      onPressed: () {
                        if (_updateInfo!['has_apk'] == true) {
                          UpdateService.launchDownload(context, _updateInfo!);
                        } else {
                          UpdateService.showUpdateDialog(context, _updateInfo!);
                        }
                      },
                      icon: const Icon(Icons.download, size: 18),
                      label: const Text('Descargar actualización'),
                    ),
                  ),
                ]),
              ),
            ] else ...[
              Row(children: [
                Icon(Icons.check_circle, size: 16, color: AppTheme.success),
                const SizedBox(width: 6),
                const Text('Tienes la última versión', style: TextStyle(fontSize: 13)),
              ]),
              const SizedBox(height: 4),
              Text('Versión actual: v${AppConfig.appVersion}',
                  style: TextStyle(fontSize: 12, color: Colors.grey[600])),
              const SizedBox(height: 8),
              OutlinedButton.icon(
                onPressed: _checkingUpdate
                    ? null
                    : () async {
                        setState(() => _checkingUpdate = true);
                        await _checkUpdate();
                        if (mounted) {
                          setState(() => _checkingUpdate = false);
                          if (!_hasUpdate) U.toast(context, 'Ya estás actualizado');
                        }
                      },
                icon: _checkingUpdate
                    ? const SizedBox(width: 14, height: 14, child: CircularProgressIndicator(strokeWidth: 2))
                    : const Icon(Icons.refresh, size: 16),
                label: const Text('Buscar actualizaciones'),
              ),
            ],
          ]),
        ),
      ),

      // ── Cambiar de cuenta ──
      if (savedAccounts.accounts.any((a) => a.userId != auth.userId)) ...[
        const SizedBox(height: 10),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                const Icon(Icons.swap_horiz, size: 20, color: AppTheme.primary),
                const SizedBox(width: 8),
                Text('Cambiar de cuenta',
                    style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
              ]),
              const SizedBox(height: 8),
              ...savedAccounts.accounts
                  .where((acc) => acc.userId != auth.userId)
                  .map((acc) => ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        leading: CircleAvatar(
                          radius: 18,
                          backgroundColor: AppTheme.primary.withAlpha(25),
                          child: Text(acc.initials,
                              style: const TextStyle(color: AppTheme.primary, fontWeight: FontWeight.w700, fontSize: 12)),
                        ),
                        title: Text(acc.name, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                        subtitle: Text(acc.businessName, style: TextStyle(color: Colors.grey[500], fontSize: 11)),
                        trailing: Icon(
                          acc.pass.isEmpty ? Icons.lock_outline : Icons.swap_horiz,
                          size: 18,
                          color: acc.pass.isEmpty ? Colors.grey[400] : AppTheme.primary,
                        ),
                        onTap: () {
                          if (acc.pass.isNotEmpty) _switchTo(context, acc);
                        },
                      )),
            ]),
          ),
        ),
      ],
    ]);
  }

  Widget _infoRow(String k, String v) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 4),
    child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
      Text(k, style: TextStyle(color: Colors.grey[600], fontSize: 13)),
      Flexible(child: Text(v, textAlign: TextAlign.right,
          style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600))),
    ]),
  );
}
