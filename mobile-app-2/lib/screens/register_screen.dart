import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../config.dart';
import '../theme/app_theme.dart';
import '../services/api_service.dart';
import '../services/auth_service.dart';
import '../services/pending_register_service.dart';
import '../services/sync_service.dart';
import '../services/tutorial_service.dart';

/// Registro público de negocios desde la app (2 pasos), igual que la web:
/// 1) datos del negocio y del dueño → el servidor envía un código de 6
///    dígitos al correo; 2) verificar el código → crea el negocio con su
///    prueba gratis, deja la sesión iniciada y abre la bienvenida/tour.
class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _bizName = TextEditingController();
  final _slug = TextEditingController();
  final _ownerName = TextEditingController();
  final _email = TextEditingController();
  final _phone = TextEditingController();
  final _username = TextEditingController();
  final _password = TextEditingController();
  final _formKey = GlobalKey<FormState>();

  int _step = 1;
  bool _busy = false;
  bool _showPass = false;
  String? _error;
  final List<TextEditingController> _otp =
      List.generate(6, (_) => TextEditingController());
  final List<FocusNode> _otpFocus = List.generate(6, (_) => FocusNode());
  Timer? _resendTimer;
  int _resendIn = 0;
  String _okMsg = '';

  @override
  void initState() {
    super.initState();
    // Retoma un registro que quedó a medias: si la app se cerró en el paso 2
    // (código ya enviado), se vuelve a esa pantalla con el email puesto en
    // vez de perder el estado y dejar al usuario sin dónde escribir el código.
    PendingRegisterService.I.resumeEmail().then((email) {
      if (!mounted || email == null || _step != 1) return;
      setState(() {
        _email.text = email;
        _step = 2;
        _okMsg = 'Retomamos tu registro: te enviamos un código de 6 dígitos '
            'a $email. Si no lo ves, solicita uno nuevo.';
        _error = null;
      });
    });
  }

  @override
  void dispose() {
    _bizName.dispose();
    _slug.dispose();
    _ownerName.dispose();
    _email.dispose();
    _phone.dispose();
    _username.dispose();
    _password.dispose();
    for (final c in _otp) {
      c.dispose();
    }
    for (final f in _otpFocus) {
      f.dispose();
    }
    _resendTimer?.cancel();
    super.dispose();
  }

  /// Igual que slugify() en la web: minúsculas, sin acentos, guiones.
  String _slugify(String s) {
    const withAccent = 'áàäéèëíìïóòöúùüñç';
    const noAccent = 'aaaeeeiiiooouuuunc';
    var out = s.toLowerCase();
    for (var i = 0; i < withAccent.length; i++) {
      out = out.replaceAll(withAccent[i], noAccent[i]);
    }
    out = out.replaceAll(RegExp(r'[^a-z0-9]+'), '-');
    out = out.replaceAll(RegExp(r'^-+|-+$'), '');
    return out;
  }

  void _setError(String msg) {
    setState(() {
      _error = msg;
      _okMsg = '';
    });
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

  Future<void> _submitStep1() async {
    if (_busy) return;
    FocusScope.of(context).unfocus();
    if (!(_formKey.currentState?.validate() ?? false)) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final d = await ApiService.I.req('ws_mobile_register_step1', {
        'biz_name': _bizName.text.trim(),
        'slug': _slugify(_slug.text.trim()),
        'owner_name': _ownerName.text.trim(),
        'email': _email.text.trim(),
        'phone': _phone.text.trim(),
        'username': _username.text.trim(),
        'password': _password.text,
      });
      if (!mounted) return;
      // El código ya salió: guardar el estado para retomar si cierran la app.
      unawaited(PendingRegisterService.I.save(_email.text.trim()));
      setState(() {
        _step = 2;
        _okMsg = '${d is Map ? d['msg'] ?? '' : ''}';
        _error = null;
      });
      _startResendCooldown();
      Future.delayed(const Duration(milliseconds: 350), () {
        if (mounted) _otpFocus[0].requestFocus();
      });
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
          .req('ws_mobile_register_resend', {'email': _email.text.trim()});
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

  Future<void> _submitStep2() async {
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
      final d = await ApiService.I.req('ws_mobile_register_verify', {
        'email': _email.text.trim(),
        'code': code,
      });
      if (!mounted) return;
      // Login automático, igual que la web: el servidor devuelve el token
      // móvil y el payload de sesión; RootGate cambia a la app al notificarse.
      if (d is Map && d['token'] != null) {
        // Cuenta creada y confirmada: ya no hay registro pendiente.
        unawaited(PendingRegisterService.I.clear());
        await ApiService.I.setToken('${d['token']}');
        final rawDays = d['sessionDays'];
        final days = (rawDays is num && rawDays >= 1) ? rawDays.toInt() : 30;
        final me =
            (d['me'] is Map) ? Map<String, dynamic>.from(d['me'] as Map) : <String, dynamic>{};
        await AuthService.I.store(me, days);
        // Primer uso: la app abre la bienvenida + tour (igual que la web).
        await TutorialService.I.markWelcomePending();
        unawaited(SyncService.I.start());
        unawaited(SyncService.I.syncNow());
      } else {
        _setError('Registro incompleto. Intenta iniciar sesión.');
      }
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
      // Paste: repartir los dígitos entre los campos.
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
      // Backspace en un campo vacío: volver al anterior.
      _otpFocus[i - 1].requestFocus();
    }
    setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final otpFilled = _otp.every((c) => c.text.isNotEmpty);

    InputDecoration deco(String label, {Widget? suffix}) => InputDecoration(
          labelText: label,
          suffixIcon: suffix,
        );

    Widget field(TextEditingController c, String label,
        {TextInputType type = TextInputType.text,
        Widget? suffix,
        String? Function(String?)? validator}) {
      return Padding(
        padding: const EdgeInsets.only(bottom: 12),
        child: TextFormField(
          controller: c,
          keyboardType: type,
          decoration: deco(label, suffix: suffix),
          validator: validator ??
              (v) => (v == null || v.trim().isEmpty) ? 'Campo obligatorio' : null,
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Text(_step == 1 ? 'Crear cuenta' : 'Verifica tu correo'),
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
                child: _step == 1 ? _buildStep1(field) : _buildStep2(otpFilled, isDark),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _messages() {
    return Column(children: [
      if (_error != null)
        Container(
          key: ValueKey(_error),
          width: double.infinity,
          padding: const EdgeInsets.all(10),
          margin: const EdgeInsets.only(bottom: 12),
          decoration: BoxDecoration(
            color: AppTheme.danger.withAlpha(20),
            borderRadius: BorderRadius.circular(10),
            border: Border.all(color: AppTheme.danger.withAlpha(60)),
          ),
          child: Row(children: [
            const Icon(Icons.error_outline, size: 18, color: AppTheme.danger),
            const SizedBox(width: 8),
            Expanded(
                child: Text(_error!,
                    style: const TextStyle(color: AppTheme.danger, fontSize: 13))),
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
            border: Border.all(color: AppTheme.success.withAlpha(60)),
          ),
          child: Row(children: [
            const Icon(Icons.mark_email_read_outlined,
                size: 18, color: AppTheme.success),
            const SizedBox(width: 8),
            Expanded(
                child: Text(_okMsg,
                    style: const TextStyle(color: AppTheme.success, fontSize: 13))),
          ]),
        ),
    ]);
  }

  Widget _buildStep1(
      Widget Function(TextEditingController, String,
              {TextInputType type, Widget? suffix, String? Function(String?)? validator})
          field) {
    return Form(
      key: _formKey,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        const Text('Crea tu negocio gratis',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
        const SizedBox(height: 4),
        Text('Con ${AppConfig.trialDays} días de prueba gratis',
            style: TextStyle(color: Colors.grey[500], fontSize: 12)),
        const SizedBox(height: 16),
        _messages(),
        field(_bizName, 'Nombre del negocio *'),
        field(_slug, 'Dirección de tu tienda (URL) *',
            validator: (v) =>
                (v == null || _slugify(v).isEmpty) ? 'Elige una dirección' : null),
        field(_ownerName, 'Tu nombre *'),
        field(_email, 'Email *',
            type: TextInputType.emailAddress,
            validator: (v) =>
                (v == null || !v.contains('@') || !v.contains('.')) ? 'Email no válido' : null),
        field(_phone, 'Teléfono / WhatsApp', type: TextInputType.phone),
        field(_username, 'Usuario *'),
        Padding(
          padding: const EdgeInsets.only(bottom: 4),
          child: TextFormField(
            controller: _password,
            obscureText: !_showPass,
            decoration: InputDecoration(
              labelText: 'Contraseña * (mínimo 8 caracteres)',
              suffixIcon: IconButton(
                icon: Icon(_showPass ? Icons.visibility_outlined : Icons.visibility_off_outlined,
                    size: 20),
                onPressed: () => setState(() => _showPass = !_showPass),
              ),
            ),
            validator: (v) =>
                (v == null || v.length < 8) ? 'Mínimo 8 caracteres' : null,
          ),
        ),
        const SizedBox(height: 12),
        FilledButton.icon(
          onPressed: _busy ? null : _submitStep1,
          icon: _busy
              ? const SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
              : const Icon(Icons.send_outlined, size: 18),
          label: Text(_busy ? 'Enviando…' : 'Crear cuenta y enviar código'),
        ),
      ]),
    );
  }

  Widget _buildStep2(bool otpFilled, bool isDark) {
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      const Text('Verifica tu correo',
          style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
      const SizedBox(height: 6),
      Text.rich(TextSpan(children: [
        const TextSpan(text: 'Enviamos un código de 6 dígitos a '),
        TextSpan(
            text: _email.text.trim(),
            style: const TextStyle(color: AppTheme.primary, fontWeight: FontWeight.w600)),
      ], style: TextStyle(color: Colors.grey[600], fontSize: 13))),
      const SizedBox(height: 16),
      _messages(),
      Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
        for (var i = 0; i < 6; i++)
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(
                  left: i == 0 ? 0 : 4, right: i == 5 ? 0 : 4),
              child: TextField(
                controller: _otp[i],
                focusNode: _otpFocus[i],
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                maxLength: 1,
                textAlign: TextAlign.center,
                autofocus: i == 0,
                style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w700),
                decoration: InputDecoration(
                  counterText: '',
                  isDense: true,
                  contentPadding: const EdgeInsets.symmetric(vertical: 12),
                  border: OutlineInputBorder(
                      borderRadius: BorderRadius.circular(10)),
                ),
                onChanged: (v) => _onOtpInput(i, v),
              ),
            ),
          ),
      ]),
      const SizedBox(height: 14),
      FilledButton.icon(
        onPressed: (_busy || !otpFilled) ? null : _submitStep2,
        icon: _busy
            ? const SizedBox(
                width: 16,
                height: 16,
                child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
            : const Icon(Icons.shield_outlined, size: 18),
        label: Text(_busy ? 'Verificando…' : 'Verificar y crear mi negocio'),
      ),
      const SizedBox(height: 8),
      TextButton.icon(
        onPressed: _resendIn > 0 || _busy ? null : _resend,
        icon: const Icon(Icons.refresh, size: 18),
        label: Text(_resendIn > 0 ? 'Reenviar en ${_resendIn}s' : 'Reenviar código'),
      ),
      TextButton.icon(
        onPressed: _busy
            ? null
            : () => setState(() {
                  _step = 1;
                  _error = null;
                  _okMsg = '';
                }),
        icon: const Icon(Icons.arrow_back, size: 16),
        label: const Text('Volver a los datos'),
      ),
    ]);
  }
}
