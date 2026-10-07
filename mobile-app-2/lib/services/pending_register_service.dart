import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// Registro a medias: recuerda que el paso 1 del registro pidió un código de
/// verificación para poder retomarlo si la app se cierra antes de
/// introducirlo (el usuario se queda sin pantalla donde escribir el código).
///
/// El borrador vive en el servidor como transiente de 30 minutos, así que el
/// estado local caduca a la vez: si expira se limpia y el registro empieza
/// desde el paso 1 con un código nuevo.
class PendingRegisterService {
  PendingRegisterService._();
  static final PendingRegisterService I = PendingRegisterService._();

  static const _key = 'wsm_pending_register';
  static const _ttl = Duration(minutes: 30);

  /// Email del registro pendiente y vigente, o null si no lo hay.
  Future<String?> resumeEmail() async {
    try {
      final sp = await SharedPreferences.getInstance();
      final raw = sp.getString(_key);
      if (raw == null || raw.isEmpty) return null;
      final data = jsonDecode(raw);
      final email = data is Map ? '${data['email'] ?? ''}' : '';
      final at = data is Map ? DateTime.tryParse('${data['at'] ?? ''}') : null;
      if (email.isEmpty || at == null || DateTime.now().difference(at) > _ttl) {
        await clear();
        return null;
      }
      return email;
    } catch (_) {
      // Estado corrupto: mejor empezar de cero.
      await clear();
      return null;
    }
  }

  Future<void> save(String email) async {
    final clean = email.trim();
    if (clean.isEmpty) return;
    final sp = await SharedPreferences.getInstance();
    await sp.setString(
        _key,
        jsonEncode(
            {'email': clean, 'at': DateTime.now().toIso8601String()}));
  }

  Future<void> clear() async {
    final sp = await SharedPreferences.getInstance();
    await sp.remove(_key);
  }
}
