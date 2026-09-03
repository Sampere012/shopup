import 'dart:async';
import 'dart:ui' show PlatformDispatcher;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';
import 'theme/app_theme.dart';
import 'services/api_service.dart' show ApiService, wsLog;
import 'services/auth_service.dart';
import 'services/sync_service.dart';
import 'services/theme_service.dart';
import 'services/saved_accounts_service.dart';
import 'services/websocket_service.dart';
import 'services/pos_local_service.dart';
import 'services/update_service.dart';
import 'services/db_service.dart';
import 'screens/login_screen.dart';
import 'screens/shell_screen.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  // Captura global: cualquier error de build o asíncrono queda en el log [WS].
  FlutterError.onError = (details) {
    wsLog('FLUTTER ERROR: ${details.exception}'
        '${details.stack != null ? '\n${details.stack.toString().length > 800 ? details.stack.toString().substring(0, 800) : details.stack}' : ''}');
    FlutterError.presentError(details);
  };
  PlatformDispatcher.instance.onError = (e, st) {
    wsLog('ZONE ERROR: $e'
        '\n${st.toString().length > 800 ? st.toString().substring(0, 800) : st}');
    return true;
  };
  SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
    statusBarColor: Colors.transparent,
    statusBarIconBrightness: Brightness.light,
  ));
  await ApiService.I.load();
  await AuthService.I.load();
  await ThemeService.I.load();
  await SavedAccountsService.I.load();
  runApp(const ShopUpApp());
}

class ShopUpApp extends StatelessWidget {
  const ShopUpApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        ChangeNotifierProvider<AuthService>.value(value: AuthService.I),
        ChangeNotifierProvider<SyncNotifier>(create: (_) => SyncNotifier()),
        ChangeNotifierProvider<ThemeService>.value(value: ThemeService.I),
        ChangeNotifierProvider<SavedAccountsService>.value(
            value: SavedAccountsService.I),
        ChangeNotifierProvider<WebSocketService>.value(
            value: WebSocketService.I),
        ChangeNotifierProvider<PosLocalService>.value(
            value: PosLocalService.I),
        ChangeNotifierProvider<UpdateService>.value(
            value: UpdateService.I),
      ],
      child: Consumer<ThemeService>(
        builder: (context, themeSvc, _) {
          return MaterialApp(
            title: 'ShopUp Panel',
            debugShowCheckedModeBanner: false,
            themeMode: themeSvc.mode,
            theme: AppTheme.light(),
            darkTheme: AppTheme.dark(),
            home: const RootGate(),
          );
        },
      ),
    );
  }
}

/// Notificador que reexpone los eventos del SyncService para que los
/// widgets se repinten en tiempo real.
class SyncNotifier extends ChangeNotifier {
  SyncNotifier() {
    SyncService.I.onChange(_bump);
  }
  void _bump() => notifyListeners();
  SyncService get sync => SyncService.I;
}

/// Decide entre Login y Shell según la sesión válida en caché.
class RootGate extends StatefulWidget {
  const RootGate({super.key});
  @override
  State<RootGate> createState() => _RootGateState();
}

class _RootGateState extends State<RootGate> {
  bool _decided = false;

  @override
  void initState() {
    super.initState();
    _decide();
  }

  Future<void> _decide() async {
    final auth = AuthService.I;
    Map<String, dynamic>? me;
    if (auth.hasValidCachedSession) {
      try {
        me = await auth.refresh();
      } catch (_) {
        me = auth.me;
      }
    }
    if (!mounted) return;
    setState(() => _decided = true);
    if (me != null) {
      await SyncService.I.start();
      // Connect WebSocket for real-time push
      try {
        final settings = await DbService.I.cacheGet('ws_settings_get');
        if (settings is Map && settings['ws_url'] != null) {
          WebSocketService.I.connect(
            wsUrl: '${settings['ws_url']}',
            token: ApiService.I.token ?? '',
          );
        }
      } catch (_) {}
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthService>();
    if (!_decided) {
      return Scaffold(
        body: Container(
          decoration: const BoxDecoration(
            gradient: AppTheme.darkBgGradient,
          ),
          child: Center(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                ClipRRect(
                  borderRadius: BorderRadius.circular(16),
                  child: Image.asset(
                    'assets/images/logo.png',
                    width: 56,
                    height: 56,
                    fit: BoxFit.cover,
                    errorBuilder: (_, __, ___) => const Icon(Icons.storefront, size: 56, color: AppTheme.primaryLight),
                  ),
                ),
                const SizedBox(height: 16),
                const CircularProgressIndicator(color: AppTheme.primaryLight),
              ],
            ),
          ),
        ),
      );
    }
    final showShell = auth.hasValidCachedSession;
    wsLog('RootGate rebuild → ${showShell ? 'SHELL (dashboard)' : 'LOGIN'} '
        'me=${auth.me != null} expiresAt=${auth.hasValidCachedSession}');
    return showShell ? const ShellScreen() : const LoginScreen();
  }
}
