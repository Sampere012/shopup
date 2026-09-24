import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../theme/app_theme.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/sync_service.dart';
import '../services/tutorial_service.dart';

/// Reto de verificación de correo durante el login: el servidor aceptó la
/// contraseña pero exige confirmar el buzón con un código de 6 dígitos
/// antes de entregar el token de sesión (seguridad anti-robo de acceso).
/// Al verificar, la sesión queda creada y RootGate cambia al panel.
class VerifyLoginScreen extends StatefulWidget {
  const VerifyLoginScreen({super.key, required this.email, this.message = ''});

  final String email;
  final String message;

  @override
  State<VerifyLoginScreen> createState() => _VerifyLoginScreenState();
}

class _VerifyLoginScreenState extends State<VerifyLoginScreen> {
  final List<TextEditingController> _otp =
      List.generate(6, (_) => TextEditingController());
  final List<FocusNode> _otpFocus = List.generate(6, (_) => FocusNode());
  Timer? _resendTimer;
  int _resendIn = 0;
  bool _busy = false;
  String? _error;
  String _okMsg = '';

  @override
  void initState() {
    super.initState();
    _okMsg = widget.message;
    _startResendCooldown();
    Future.delayed(const Duration(milliseconds: 350), () {
      if (mounted) _otpFocus[0].requestFocus();
    });
  }

  @override
  void dispose() {
    for (final c in _otp) {
      c.dispose();
    }
    for (final f in _otpFocus) {
      f.dispose();
    }
    _resendTimer?.cancel();
    super.dispose();
  }

  void _startResendCooldown() {
    _resendIn = 60;
    _resendTimer?.cancel();
    _resendTimer = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) return t.cancel();
      setState(() => _resendIn--);
      if (_resendIn <= 0) t.cancel();
    });
  }

  void _setError(String msg) {
    setState(() {
      _error = msg;
      _okMsg = '';
    });
  }

  Future<void> _verify() async {
    if (_busy) return;
    final code = _otp.map((c) => c.text).join();
    if (code.length < 6) {
      _setError('Introduce el código de 6 dígitos');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final d = await ApiService.I.req('ws_mobile_verify_login', {
        'email': widget.email,
        'code': code,
      });
      if (!mounted) return;
      if (d is Map && d['token'] != null) {
        await AuthService.I.completeLoginFromVerify(
            Map<String, dynamic>.from(d));
        // Como en el registro: primera vez → bienvenida + tour.
        await TutorialService.I.markWelcomePending();
        unawaited(SyncService.I.start());
        unawaited(SyncService.I.syncNow());
        if (mounted) Navigator.of(context).pop(true);
      } else {
        _setError('Verificación incompleta. Inténtalo de nuevo.');
      }
    } on ApiException catch (e) {
      _setError(e.message);
    } catch (_) {
      _setError('No se pudo conectar con el servidor');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _resend() async {
    if (_busy || _resendIn > 0) return;
    setState(() => _busy = true);
    try {
      final d = await ApiService.I
          .req('ws_mobile_resend_login_code', {'email': widget.email});
      if (!mounted) return;
      setState(() {
        _okMsg = '${d is Map ? d['msg'] ?? '' : 'Código reenviado'}';
        _error = null;
      });
      _startResendCooldown();
    } on ApiException catch (e) {
      _setError(e.message);
    } catch (_) {
      _setError('No se pudo conectar con el servidor');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _onOtpInput(int i, String v) {
    if (v.length > 1) {
      final digits = v.replaceAll(RegExp(r'[^0-9]'), '');
      for (var k = 0; k < _otp.length; k++) {
        _otp[k].text = k < digits.length ? digits[k] : '';
      }
      if (digits.isNotEmpty) {
        _otpFocus[digits.length.clamp(0, 5)].requestFocus();
      }
      setState(() {});
      return;
    }
    if (v.isNotEmpty && i < 5) {
      _otpFocus[i + 1].requestFocus();
    } else if (v.isEmpty && i > 0) {
      _otpFocus[i - 1].requestFocus();
    }
    setState(() {});
    // Auto-envío al completar los 6 dígitos.
    if (_otp.every((c) => c.text.isNotEmpty)) {
      _verify();
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final otpFilled = _otp.every((c) => c.text.isNotEmpty);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Confirma tu identidad'),
        backgroundColor: Colors.transparent,
      ),
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
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: Container(
                constraints: const BoxConstraints(maxWidth: 420),
                decoration: BoxDecoration(
                  color: isDark ? AppTheme.darkCard : Colors.white,
                  borderRadius: BorderRadius.circular(18),
                  boxShadow: [
                    BoxShadow(
                      color: Colors.black.withAlpha(30),
                      blurRadius: 32,
                      offset: const Offset(0, 12),
                    ),
                  ],
                ),
                padding: const EdgeInsets.all(22),
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      const Text('Verifica tu correo',
                          style: TextStyle(
                              fontSize: 16, fontWeight: FontWeight.w700)),
                      const SizedBox(height: 6),
                      Text.rich(TextSpan(children: [
                        const TextSpan(
                            text: 'Enviamos un código de 6 dígitos a '),
                        TextSpan(
                            text: widget.email,
                            style: const TextStyle(
                                color: AppTheme.primary,
                                fontWeight: FontWeight.w600)),
                      ],
                          style: TextStyle(
                              color: Colors.grey[600], fontSize: 13))),
                      const SizedBox(height: 16),
                      if (_error != null)
                        Container(
                          key: ValueKey(_error),
                          width: double.infinity,
                          padding: const EdgeInsets.all(10),
                          margin: const EdgeInsets.only(bottom: 12),
                          decoration: BoxDecoration(
                            color: AppTheme.danger.withAlpha(20),
                            borderRadius: BorderRadius.circular(10),
                            border:
                                Border.all(color: AppTheme.danger.withAlpha(60)),
                          ),
                          child: Row(children: [
                            const Icon(Icons.error_outline,
                                size: 18, color: AppTheme.danger),
                            const SizedBox(width: 8),
                            Expanded(
                                child: Text(_error!,
                                    style: const TextStyle(
                                        color: AppTheme.danger, fontSize: 13))),
                          ]),
                        ),
                      if (_okMsg.isNotEmpty)
                        Container(
                          width: double.infinity,
                          padding: const EdgeInsets.all(10),
                          margin: const EdgeInsets.only(bottom: 12),
                          decoration: BoxDecoration(
                            color: AppTheme.success.withAlpha(20),
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(
                                color: AppTheme.success.withAlpha(60)),
                          ),
                          child: Row(children: [
                            const Icon(Icons.mark_email_read_outlined,
                                size: 18, color: AppTheme.success),
                            const SizedBox(width: 8),
                            Expanded(
                                child: Text(_okMsg,
                                    style: const TextStyle(
                                        color: AppTheme.success,
                                        fontSize: 13))),
                          ]),
                        ),
                      Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            for (var i = 0; i < 6; i++)
                              Expanded(
                                child: Padding(
                                  padding: EdgeInsets.only(
                                      left: i == 0 ? 0 : 4,
                                      right: i == 5 ? 0 : 4),
                                  child: TextField(
                                    controller: _otp[i],
                                    focusNode: _otpFocus[i],
                                    keyboardType: TextInputType.number,
                                    inputFormatters: [
                                      FilteringTextInputFormatter.digitsOnly
                                    ],
                                    maxLength: 1,
                                    textAlign: TextAlign.center,
                                    autofocus: i == 0,
                                    style: const TextStyle(
                                        fontSize: 20,
                                        fontWeight: FontWeight.w700),
                                    decoration: InputDecoration(
                                      counterText: '',
                                      isDense: true,
                                      contentPadding:
                                          const EdgeInsets.symmetric(
                                              vertical: 12),
                                      border: OutlineInputBorder(
                                          borderRadius:
                                              BorderRadius.circular(10)),
                                    ),
                                    onChanged: (v) => _onOtpInput(i, v),
                                  ),
                                ),
                              ),
                          ]),
                      const SizedBox(height: 14),
                      FilledButton.icon(
                        onPressed: (_busy || !otpFilled) ? null : _verify,
                        icon: _busy
                            ? const SizedBox(
                                width: 16,
                                height: 16,
                                child: CircularProgressIndicator(
                                    strokeWidth: 2, color: Colors.white))
                            : const Icon(Icons.shield_outlined, size: 18),
                        label: Text(_busy
                            ? 'Verificando…'
                            : 'Verificar y entrar al panel'),
                      ),
                      const SizedBox(height: 8),
                      TextButton.icon(
                        onPressed: _resendIn > 0 || _busy ? null : _resend,
                        icon: const Icon(Icons.refresh, size: 18),
                        label: Text(_resendIn > 0
                            ? 'Reenviar en ${_resendIn}s'
                            : 'Reenviar código'),
                      ),
                    ]),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
