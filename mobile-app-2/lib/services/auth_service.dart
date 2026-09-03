import 'dart:async';
import 'dart:convert';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api_service.dart';
import 'db_service.dart';

/// Sesión y perfil (equivalente a js/auth.js).
/// REGLA ANTI-CIERRE ACCIDENTAL: la sesión SOLO se borra cuando el servidor
/// responde explícitamente loggedIn:false. Errores transitorios (red, 5xx,
/// timeout) conservan la sesión en caché mientras siga vigente.
class AuthService extends ChangeNotifier {
  AuthService._();
  static final AuthService I = AuthService._();

  static const _sessKey = 'wsm_session';

  Map<String, dynamic>? _me;
  int? _expiresAt;
  bool _loaded = false;

  Map<String, dynamic>? get me => _me;
  int? get userId {
    final v = _me?['id'] ?? _me?['user_id'] ?? _me?['userId'];
    return v is int ? v : int.tryParse('$v') ?? 0;
  }

  String get businessName => '${_me?['businessName'] ?? _me?['business_name'] ?? ''}';
  String get currency => '${_me?['currency'] ?? '€'}';

  List<dynamic> get menu => (_me?['menu'] as List?) ?? const [];

  /// IDs de ubicaciones asignadas al trabajador (vacío = todas).
  List<int> get locationIds {
    final raw = _me?['locations'] ?? _me?['location_ids'];
    if (raw is List) {
      return raw.map((e) {
        if (e is Map) return int.tryParse('${e['id'] ?? ''}') ?? 0;
        return int.tryParse('$e') ?? 0;
      }).where((id) => id > 0).toList();
    }
    return [];
  }

  /// Igual que JS: has('stock_count_view') mira la clave EXACTA en me.caps.
  bool has(String cap) => caps[cap] == true;

  Map<String, dynamic> get caps =>
      (_me?['caps'] as Map<String, dynamic>?) ?? const {};

  /// El menú del sidebar viene del servidor (me.menu): key/label/icon.
  bool canSeeMenu(String key) => menu
      .whereType<Map>()
      .any((m) => '${m['key']}' == key);

  Future<void> load() async {
    if (_loaded) return;
    final sp = await SharedPreferences.getInstance();
    final raw = sp.getString(_sessKey);
    if (raw != null) {
      try {
        final d = jsonDecode(raw) as Map<String, dynamic>;
        _me = (d['me'] as Map<String, dynamic>?);
        _expiresAt = (d['expiresAt'] as num?)?.toInt();
      } catch (_) {}
    }
    _loaded = true;
    notifyListeners();
  }

  bool get hasValidCachedSession =>
      _me != null &&
      _expiresAt != null &&
      DateTime.now().millisecondsSinceEpoch < _expiresAt!;

  Future<void> store(Map<String, dynamic> me, int sessionDays) async {
    _me = me;
    _expiresAt =
        DateTime.now().add(Duration(days: sessionDays)).millisecondsSinceEpoch;
    final sp = await SharedPreferences.getInstance();
    await sp.setString(
        _sessKey, jsonEncode({'me': me, 'expiresAt': _expiresAt}));
    notifyListeners();
  }

  Future<void> clear() async {
    _me = null;
    _expiresAt = null;
    final sp = await SharedPreferences.getInstance();
    await sp.remove(_sessKey);
    await ApiService.I.setToken(null);
    notifyListeners();
  }

  Future<Map<String, dynamic>?> login(String user, String pass,
      {String? server}) async {
    wsLog('login() inicio user="$user" server=$server');
    // Limpiar sesión anterior para evitar menú cacheado de otro usuario.
    _me = null;
    _expiresAt = null;
    notifyListeners();
    if (server != null && server.trim().isNotEmpty) {
      await ApiService.I.setServer(server);
    }
    // IMPORTANTE: el backend lee $_POST['ws_user'] y $_POST['ws_pass']
    // (inc/ajax.php ws_ajax_mobile_login), igual que la app anterior.
    // Timeout duro: si el Future nunca completa pese a la respuesta HTTP,
    // lo sabremos por este log en vez de quedarnos colgados.
    final data = await ApiService.I
        .req('ws_mobile_login', {'ws_user': user.trim(), 'ws_pass': pass})
        .timeout(const Duration(seconds: 30), onTimeout: () {
      wsLog('login() ✗ TIMEOUT 30s: el Future de req() nunca completó');
      throw ApiException('Tiempo de espera agotado');
    });
    wsLog('login() respuesta keys=${data is Map ? data.keys.toList() : data.runtimeType}');
    // El backend de login NO envía 'loggedIn': el éxito se confirma por el
    // token (igual que auth.js: setToken(data.token) directo).
    if (data is Map && data['token'] != null) {
      await ApiService.I.setToken('${data['token']}');
      final rawDays = data['sessionDays'];
      final days =
          (rawDays is num && rawDays >= 1) ? rawDays.toInt() : 30;
      final me = (data['me'] is Map)
          ? Map<String, dynamic>.from(data['me'] as Map)
          : <String, dynamic>{};
      await store(me, days);
      wsLog('login() OK sesión guardada días=$days menuItems=${(me['menu'] as List?)?.length ?? 0}');
      return _me;
    }
    wsLog('login() RECHAZADA sin token');
    throw ApiException('Usuario o contraseña incorrectos',
        response: data is Map<String, dynamic> ? data : null);
  }

  /// Valida contra ws_mobile_me. Devuelve me o null.
  Future<Map<String, dynamic>?> refresh() async {
    try {
      final data = await ApiService.I.req('ws_mobile_me', {});
      if (data is! Map || data['loggedIn'] != true) {
        await clear();
        return null;
      }
      final me = (data['me'] is Map)
          ? Map<String, dynamic>.from(data['me'] as Map)
          : <String, dynamic>{};
      final rawDays = data['sessionDays'];
      await store(
          me, (rawDays is num && rawDays >= 1) ? rawDays.toInt() : 30);
      return me;
    } on ApiException {
      // Transitorio: conservar sesión válida en caché.
      if (hasValidCachedSession) return _me;
      rethrow;
    }
  }

  Future<void> logout() async {
    try {
      await ApiService.I.req('ws_mobile_logout', {});
    } catch (_) {}
    await DbService.I.wipe();
    await clear();
  }

  /// ¿Hay red según el sistema?
  static Future<bool> online() async {
    final r = await Connectivity().checkConnectivity();
    return !r.contains(ConnectivityResult.none);
  }
}
