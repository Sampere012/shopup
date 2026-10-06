import 'dart:async';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../config.dart';
import '../theme/app_theme.dart';
import '../services/api_service.dart' show ApiException, ApiService, wsLog;
import '../services/auth_service.dart';
import '../services/sync_service.dart';
import '../services/saved_accounts_service.dart';
import 'verify_login_screen.dart';
import 'register_screen.dart';
import '../widgets/common.dart' show U;

/// Pantalla de login con gradientes y cuentas guardadas.
/// El servidor es fijo (AppConfig.defaultServer); el registro abre la web.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen>
    with TickerProviderStateMixin {
  final _user = TextEditingController();
  final _pass = TextEditingController();
  bool _busy = false;
  bool _remember = true;
  bool _popupOpen = false;
  late AnimationController _logoCtrl;
  late AnimationController _cardCtrl;
  late Animation<double> _logoScale;
  late Animation<double> _cardFade;
  late Animation<Offset> _cardSlide;

  @override
  void initState() {
    super.initState();
    // Logo: scale + fade in
    _logoCtrl = AnimationController(
        vsync: this, duration: const Duration(milliseconds: 500));
    _logoScale = Tween<double>(begin: 0.5, end: 1.0).animate(
      CurvedAnimation(parent: _logoCtrl, curve: Curves.easeOutBack),
    );
    // Card: fade + slide up with delay
    _cardCtrl = AnimationController(
        vsync: this, duration: const Duration(milliseconds: 600));
    _cardFade = Tween<double>(begin: 0.0, end: 1.0).animate(
      CurvedAnimation(parent: _cardCtrl, curve: Curves.easeOut),
    );
    _cardSlide = Tween<Offset>(
      begin: const Offset(0, 0.12),
      end: Offset.zero,
    ).animate(CurvedAnimation(parent: _cardCtrl, curve: Curves.easeOutCubic));
    // Stagger: logo first, then card
    _logoCtrl.forward().then((_) => _cardCtrl.forward());
  }


  /// Aviso del negocio BLOQUEADO (advertencia, no error): suscripción en
  /// pausa, vencida o negocio desactivado por la administración.
  void _showLockedPopup(String msg) {
    _showPopup(msg, warn: true);
  }

  /// Popup animado que aparece, se auto-descarta a los ~5 s y puede
  /// cerrarse tocando fuera o con «Entendido».
  void _showPopup(String msg, {bool warn = false}) {
    if (!mounted || _popupOpen) return;
    _popupOpen = true;
    final color = warn ? AppTheme.amber : AppTheme.danger;
    final icon = warn ? Icons.pause_circle_outline : Icons.error_outline;
    final title = warn ? 'Aviso de acceso' : 'Error de acceso';
    final isDark = Theme.of(context).brightness == Brightness.dark;
    showGeneralDialog(
      context: context,
      barrierDismissible: true,
      barrierLabel: 'login_popup',
      barrierColor: Colors.black.withAlpha(140),
      transitionDuration: const Duration(milliseconds: 340),
      pageBuilder: (ctx, a1, a2) {
        // Desaparece solo (como pidió la UI): 5 s y se cierra si nadie tocó.
        Future.delayed(const Duration(milliseconds: 5000), () {
          if (ctx.mounted && Navigator.of(ctx).canPop()) Navigator.of(ctx).pop();
        });
        return Dialog(
              backgroundColor: Colors.transparent,
              insetPadding: const EdgeInsets.symmetric(horizontal: 36),
              child: Container(
                decoration: BoxDecoration(
                  color: isDark ? AppTheme.darkCard : Colors.white,
                  borderRadius: BorderRadius.circular(20),
                ),
                padding: const EdgeInsets.fromLTRB(20, 22, 20, 16),
                child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.center, children: [
                  Container(
                    width: 74,
                    height: 74,
                    decoration: BoxDecoration(
                      color: color.withAlpha(24),
                      shape: BoxShape.circle,
                    ),
                    child: Icon(icon, size: 40, color: color),
                  ),
                  const SizedBox(height: 14),
                  Text(title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                  const SizedBox(height: 8),
                  Text(msg, textAlign: TextAlign.center, style: TextStyle(fontSize: 13.5, height: 1.35, color: isDark ? AppTheme.darkText : AppTheme.lightText)),
                  const SizedBox(height: 16),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      style: FilledButton.styleFrom(backgroundColor: color),
                      onPressed: () => Navigator.of(ctx).pop(),
                      child: const Text('Entendido'),
                    ),
                  ),
                ]),
              ),
            );
      },
      transitionBuilder: (ctx, anim, anim2, child) => ScaleTransition(
        scale: CurvedAnimation(parent: anim, curve: Curves.easeOutBack),
        child: FadeTransition(
          opacity: CurvedAnimation(parent: anim, curve: Curves.easeOut),
          child: child,
        ),
      ),
    ).then((_) {
      _popupOpen = false;
    });
  }

  @override
  void dispose() {
    _logoCtrl.dispose();
    _cardCtrl.dispose();
    _user.dispose();
    _pass.dispose();
    super.dispose();
  }

  Future<void> _login({String? username, String? password, String? server}) async {
    if (_busy) return;
    setState(() => _busy = true);
    wsLog('LOGIN UI → intento');
    var stage = 'inicio';
    final user = username ?? _user.text;
    final pass = password ?? _pass.text;
    final srv = server ?? ApiService.I.server;
    // Watchdog: si el hilo vive pero un await nunca resuelve, lo veremos aquí.
    final watchdog = Timer.periodic(const Duration(seconds: 5),
        (_) => wsLog('WATCHDOG vivo, stage=$stage'));
    try {
      stage = 'ws_mobile_login';
      await AuthService.I.login(user, pass, server: srv);
      wsLog('LOGIN UI ✓ AuthService.login completó; RootGate debe cambiar a Shell');

      // Redirección inmediata: RootGate cambia a Shell al notificarse la
      // sesión. El resto (cuenta guardada + primera sync) va en background
      // para que el botón nunca quede "dando vueltas" durante el pull.
      stage = 'post-login';
      unawaited(_postLogin(user, pass, srv));
    } on NeedVerifyException catch (e) {
      wsLog('LOGIN UI ⚠ reto de verificación de correo');
      if (!mounted) return;
      // El servidor pidió confirmar el buzón: pantalla del código de 6
      // dígitos. Al verificar, la sesión queda creada y RootGate cambia.
      final ok = await Navigator.of(context).push<bool>(MaterialPageRoute(
          builder: (_) =>
              VerifyLoginScreen(email: e.email, message: e.message)));
      if (ok == true && mounted) {
        wsLog('LOGIN UI ✓ verificación completada; RootGate cambia a Shell');
        unawaited(_postLogin(user, pass, srv));
      }
    } on ApiException catch (e) {
      wsLog('LOGIN UI ✗ ApiException: ${e.message} response=${e.response}');
      if (!mounted) return;
      // Bloqueo del negocio → popup de advertencia (no error SMTP ni rojo).
      final isLocked = e.response?['locked'] == true;
      if (isLocked) {
        _showLockedPopup(e.message);
      } else {
        _showPopup(e.message);
      }
    } catch (e, st) {
      wsLog('LOGIN UI ✗ error inesperado en stage=$stage: $e\n$st');
      if (!mounted) return;
      _showPopup('No se pudo conectar con el servidor');
    } finally {
      watchdog.cancel();
      wsLog('LOGIN UI finally busy=false');
      if (mounted) setState(() => _busy = false);
    }
  }

  /// Tareas posteriores al login SIN bloquear la navegación.
  Future<void> _postLogin(String user, String pass, String srv) async {
    try {
      if (_remember) {
        final me = AuthService.I.me ?? {};
        await SavedAccountsService.I.saveAccount(
          user: user.trim(),
          pass: pass,
          server: srv.trim(),
          name: '${me['name'] ?? user}',
          role: '${me['roleLabel'] ?? me['role'] ?? ''}',
          businessName: '${me['businessName'] ?? ''}',
          userId: AuthService.I.userId,
        );
        wsLog('POST-LOGIN cuenta recordada');
      }
    } catch (e) {
      wsLog('POST-LOGIN ✗ saveAccount: $e');
    }
    try {
      wsLog('POST-LOGIN iniciando sync…');
      await SyncService.I.start();
      await SyncService.I.syncNow();
      wsLog('POST-LOGIN sync terminada');
    } catch (e) {
      wsLog('POST-LOGIN ✗ sync: $e');
    }
  }

  /// Abre el registro DENTRO de la app (2 pasos con código de email),
  /// igual que el registro público de la web.
  Future<void> _register() async {
    await Navigator.of(context).push(
        MaterialPageRoute(builder: (_) => const RegisterScreen()));
  }

  /// Bottom sheet con las cuentas guardadas: se abre al tocar el campo de
  /// usuario o contraseña. Tocar una cuenta la autocompleta y, si tiene
  /// contraseña recordada, entra directamente.
  void _showSavedSheet() {
    final accounts = context.read<SavedAccountsService>().accounts;
    if (accounts.isEmpty) return;
    final isDark = Theme.of(context).brightness == Brightness.dark;
    showModalBottomSheet(
      context: context,
      backgroundColor: Colors.transparent,
      builder: (sheetCtx) => Container(
        decoration: BoxDecoration(
          color: isDark ? AppTheme.darkCard : Colors.white,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
        ),
        child: SafeArea(
          top: false,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const SizedBox(height: 10),
            Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(
                color: Colors.grey.withAlpha(80),
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            const Padding(
              padding: EdgeInsets.fromLTRB(20, 14, 20, 4),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Text('Cuentas guardadas',
                    style:
                        TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
              ),
            ),
            ...sheetCtx.watch<SavedAccountsService>().accounts.map((acc) =>
                ListTile(
                  leading: CircleAvatar(
                    radius: 19,
                    backgroundColor: AppTheme.primary.withAlpha(30),
                    child: Text(acc.initials,
                        style: const TextStyle(
                            color: AppTheme.primary,
                            fontWeight: FontWeight.w700,
                            fontSize: 13)),
                  ),
                  title: Text(acc.name,
                      style: const TextStyle(
                          fontWeight: FontWeight.w600, fontSize: 14)),
                  subtitle: Text(
                      '${acc.businessName}${acc.pass.isEmpty ? ' · pedirá contraseña' : ''}',
                      style:
                          TextStyle(color: Colors.grey[500], fontSize: 12)),
                  trailing: IconButton(
                    icon: const Icon(Icons.close, size: 16),
                    onPressed: () async {
                      if (await U.confirm(sheetCtx,
                          '¿Eliminar cuenta guardada?',
                          action: 'Eliminar')) {
                        SavedAccountsService.I.removeAccount(acc.user, acc.server);
                      }
                    },
                  ),
                  onTap: () {
                    Navigator.pop(sheetCtx);
                    _user.text = acc.user;
                    _pass.text = acc.pass;
                    if (acc.pass.isNotEmpty) {
                      _login(
                          username: acc.user,
                          password: acc.pass,
                          server: acc.server);
                    }
                  },
                )),
            const SizedBox(height: 8),
          ]),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      body: Container(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            colors: isDark
                ? [const Color(0xFF0F172A), const Color(0xFF1E293B)]
                : [const Color(0xFF171B3A), const Color(0xFF242A58)],
            begin: Alignment.topLeft,
            end: Alignment.bottomRight,
          ),
        ),
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  // Logo + branding (animated scale)
                  ScaleTransition(
                    scale: _logoScale,
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(18),
                      child: Image.asset(
                        'assets/images/logo.png',
                        width: 72,
                        height: 72,
                        fit: BoxFit.cover,
                        errorBuilder: (_, __, ___) => Container(
                          width: 72,
                          height: 72,
                          decoration: AppTheme.gradientCard(radius: 18),
                          child: const Icon(Icons.storefront,
                              size: 36, color: Colors.white),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),
                  const Text('ShopUp Panel',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 26,
                        fontWeight: FontWeight.w800,
                        fontFamily: 'PlusJakartaSans',
                      )),
                  const SizedBox(height: 4),
                  Text('v${AppConfig.appVersion}',
                      style: TextStyle(
                          color: Colors.white.withAlpha(150),
                          fontSize: 13)),
                  const SizedBox(height: 32),

                  // Login card (animated slide + fade)
                  SlideTransition(
                    position: _cardSlide,
                    child: FadeTransition(
                      opacity: _cardFade,
                      child: Container(
                    constraints: const BoxConstraints(maxWidth: 400),
                    decoration: BoxDecoration(
                      color: isDark
                          ? AppTheme.darkCard
                          : Colors.white,
                      borderRadius: BorderRadius.circular(18),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withAlpha(30),
                          blurRadius: 32,
                          offset: const Offset(0, 12),
                        ),
                      ],
                    ),
                    padding: const EdgeInsets.all(28),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Text('Iniciar sesión',
                            style: Theme.of(context)
                                .textTheme
                                .titleMedium
                                ?.copyWith(fontWeight: FontWeight.w700)),
                        const SizedBox(height: 18),
                        TextField(
                          controller: _user,
                          decoration: const InputDecoration(
                            labelText: 'Usuario o email',
                            prefixIcon: Icon(Icons.person_outline),
                          ),
                          autofillHints: const [AutofillHints.username],
                          onTap: _showSavedSheet,
                        ),
                        const SizedBox(height: 12),
                        TextField(
                          controller: _pass,
                          obscureText: true,
                          decoration: const InputDecoration(
                            labelText: 'Contraseña',
                            prefixIcon: Icon(Icons.lock_outline),
                          ),
                          autofillHints: const [AutofillHints.password],
                          onSubmitted: (_) => _login(),
                        ),
                        const SizedBox(height: 20),
                        FilledButton(
                          onPressed: _busy ? null : () => _login(),
                          child: _busy
                              ? const SizedBox(
                                  height: 18,
                                  width: 18,
                                  child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                      color: Colors.white))
                              : const Text('Entrar'),
                        ),

                        // Recordar credenciales (aparecen en el drawer).
                        CheckboxListTile(
                          value: _remember,
                          onChanged: (v) =>
                              setState(() => _remember = v ?? true),
                          title: const Text('Recordar credenciales',
                              style: TextStyle(fontSize: 13)),
                          subtitle: Text('Para cambiar de cuenta desde el menú',
                              style: TextStyle(
                                  color: Colors.grey[500], fontSize: 11)),
                          contentPadding: EdgeInsets.zero,
                          dense: true,
                          controlAffinity: ListTileControlAffinity.leading,
                        ),

                        // Registro: abre la web como en la app Cordova.
                        const SizedBox(height: 4),
                        Wrap(
                          alignment: WrapAlignment.center,
                          crossAxisAlignment: WrapCrossAlignment.center,
                          children: [
                            Text('¿No tienes cuenta?',
                                style: TextStyle(
                                    color: Colors.grey[500], fontSize: 13)),
                            TextButton(
                              onPressed: _register,
                              child: const Text('Regístrate aquí'),
                            ),
                          ],
                        ),
                      ],
                    ),
                    ), // Container
                    ), // FadeTransition
                    ), // SlideTransition
                ],
              ),
            ),
          ),
        ),
    );
  }
}
