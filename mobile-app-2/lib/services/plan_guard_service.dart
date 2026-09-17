import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api_service.dart';
import '../config.dart';

/// Worker local de licencia: verifica PERIÓDICAMENTE contra la nube que la
/// suscripción del negocio sigue activa (endpoint ligero ws_mobile_subscription).
///
/// Es la fuente de verdad del bloqueo en la app:
/// - Si la nube responde locked → el negocio se bloquea (pantalla de plan).
/// - Si la nube no responde y pasó el período de gracia desde la última
///   verificación correcta → bloqueo fail-closed (no se trabaja sin poder
///   confirmar la suscripción).
/// - Si el token fue revocado (servidor devuelve invalidSession) → se fuerza
///   el logout (la suscripción venció y el servidor revocó el acceso).
///
/// Los demás servicios (SyncService, ShellScreen) consultan [isBlocked].
class PlanGuardService extends ChangeNotifier {
  PlanGuardService._();
  static final PlanGuardService I = PlanGuardService._();

  static const _blockedKey = 'wsm_plan_blocked';
  static const _statusKey = 'wsm_plan_status';
  static const _lockKey = 'wsm_plan_lock';
  static const _lastOkKey = 'wsm_plan_last_ok';
  static const _lastCheckKey = 'wsm_plan_last_check';

  Timer? _timer;
  bool _inFlight = false;
  bool _blocked = false;
  String _status = '';
  Map<String, dynamic>? _lock;
  int _lastOkMs = 0;
  int _lastCheckMs = 0;
  bool _loaded = false;

  /// Hook que ejecuta un cierre de sesión completo (logout + wipe) cuando el
  /// servidor informa que el token fue revocado. Lo registra main.dart para
  /// no acoplar este servicio a AuthService/DbService.
  Future<void> Function()? onForceLogout;

  /// ¿El negocio está bloqueado por plan (vencido/suspendido/límite/offline)?
  bool get isBlocked => _blocked;
  bool get isLoaded => _loaded;

  /// Estado de la suscripción según la última verificación (trial/active/...).
  String get status => _status;

  /// Motivo del bloqueo {key, title, message, ...} cuando [isBlocked].
  Map<String, dynamic>? get lock => _lock;

  /// Título/mensaje de bloqueo con respaldo por defecto (UI nunca queda vacía).
  String get lockTitle =>
      '${_lock?['title'] ?? (_status == 'suspended'
          ? 'Tu negocio está suspendido'
          : 'Tu plan venció o fue suspendido')}';
  String get lockMessage =>
      '${_lock?['message'] ?? 'El negocio está en pausa temporal. Renueva tu plan para reactivarlo.'}';

  /// Timestamp de la última verificación correcta contra la nube.
  int get lastOkMs => _lastOkMs;

  /// Fecha del último aviso de bloqueo guardado (para UI // rebuild estable).
  Map<String, dynamic>? get persistedLock => _lock;

  /// Carga el estado persistido (bloqueo al arrancar sin esperar la nube).
  Future<void> load() async {
    if (_loaded) return;
    final sp = await SharedPreferences.getInstance();
    _blocked = sp.getBool(_blockedKey) ?? false;
    _status = sp.getString(_statusKey) ?? '';
    _lastOkMs = sp.getInt(_lastOkKey) ?? 0;
    _lastCheckMs = sp.getInt(_lastCheckKey) ?? 0;
    final raw = sp.getString(_lockKey);
    if (raw != null) {
      try {
        _lock = jsonDecode(raw) as Map<String, dynamic>;
      } catch (_) {
        _lock = null;
      }
    }
    _loaded = true;
    notifyListeners();
  }

  /// Arranca el worker periódico + verificación inmediata (si hay sesión).
  void start() {
    if (_timer != null) return;
    _timer = Timer.periodic(
        Duration(minutes: AppConfig.licenseCheckMinutes),
        (_) => checkFromCloud());
    checkFromCloud();
  }

  void stop() {
    _timer?.cancel();
    _timer = null;
  }

  /// Aplica el plan que llegó en el payload de sesión (login / ws_mobile_me):
  /// fuente de verdad inmediata SIN petición extra. [fromCloud] distingue
  /// una respuesta nueva de la nube (resetea lastOk) de una restauración,
  /// para no romper el contador de gracia offline con datos cacheados.
  Future<void> applyMe(Map<String, dynamic>? me,
      {bool fromCloud = true}) async {
    final plan = (me != null)
        ? (me['plan'] is Map
            ? Map<String, dynamic>.from(me['plan'] as Map)
            : null)
        : null;
    if (plan == null) return;
    final locked = plan['locked'] == true || plan['locked'] == 1;
    final prevBlocked = _blocked;
    _status = '${plan['status'] ?? ''}';
    _lock = plan['lock'] is Map
        ? Map<String, dynamic>.from(plan['lock'] as Map)
        : null;
    _blocked = locked;
    if (fromCloud) {
      _lastOkMs = DateTime.now().millisecondsSinceEpoch;
    }
    _lastCheckMs = DateTime.now().millisecondsSinceEpoch;
    await _persist();
    if (prevBlocked != locked) notifyListeners();
  }

  /// Chequeo ligero contra la nube (worker). No lanza excepciones: degrada a
  /// fail-closed si no hay respuesta y pasó el período de gracia.
  Future<void> checkFromCloud() async {
    if (_inFlight) return;
    _inFlight = true;
    try {
      if (ApiService.I.token == null) return;
      final data = await ApiService.I.req('ws_mobile_subscription', {});
      final plan = (data is Map) && (data['plan'] is Map)
          ? Map<String, dynamic>.from(data['plan'] as Map)
          : null;
      if (plan == null) return;
      final locked = plan['locked'] == true || plan['locked'] == 1;
      _status = '${plan['status'] ?? ''}';
      _lock = plan['lock'] is Map ? Map<String, dynamic>.from(plan['lock'] as Map) : null;
      _blocked = locked;
      // Respuesta correcta de la nube: reinicia el contador de gracia
      // (locked o no, la suscripción quedó verificada ahora mismo).
      _lastOkMs = DateTime.now().millisecondsSinceEpoch;
      _lastCheckMs = DateTime.now().millisecondsSinceEpoch;
      await _persist();
      notifyListeners();
    } on ApiException catch (e) {
      if (e.response is Map && (e.response!['data'] is Map) &&
          (e.response!['data']['invalidSession'] == true ||
              '${e.response!['data']['msg']}'.contains('Sesión inválida'))) {
        // El servidor revocó el token (p.ej. venció la suscripción):
        // forzar cierre de sesión, el token ya no sirve.
        wsLog('PLAN GUARD → sesión inválida en la nube, forzando logout');
        unawaited(onForceLogout?.call() ?? Future.value());
        return;
      }
      // Red caída / 5xx / timeout: fail-closed con período de gracia desde
      // la última verificación correcta. Solo bloquea si existe un registro
      // previo (lastOk > 0): sin verificación anterior confiamos en la sesión
      // en caché hasta que la nube responda.
      final stale = _lastOkMs > 0 &&
          DateTime.now()
              .difference(DateTime.fromMillisecondsSinceEpoch(_lastOkMs))
              .inHours >=
              AppConfig.licenseGraceHours;
      if (stale && !_blocked) {
        _status = 'offline_unverified';
        _lock = {
          'key': 'offline_grace',
          'title': 'No se pudo verificar tu suscripción',
          'message':
              'Llevamos más de ${AppConfig.licenseGraceHours}h sin confirmar tu plan contra la nube. Conecta el dispositivo para reactivar el negocio.',
        };
        _blocked = true;
        await _persist();
        notifyListeners();
      }
    } catch (e) {
      wsLog('PLAN GUARD ✗ check: $e');
    } finally {
      _inFlight = false;
    }
  }

  Future<void> _persist() async {
    final sp = await SharedPreferences.getInstance();
    await sp.setBool(_blockedKey, _blocked);
    await sp.setString(_statusKey, _status);
    await sp.setString(_lockKey, _lock == null ? '' : jsonEncode(_lock));
    await sp.setInt(_lastOkKey, _lastOkMs);
    await sp.setInt(_lastCheckKey, _lastCheckMs);
  }

  /// Reinicia el estado (tras logout / cambio de cuenta).
  Future<void> reset() async {
    _timer?.cancel();
    _timer = null;
    _blocked = false;
    _status = '';
    _lock = null;
    _lastOkMs = 0;
    _lastCheckMs = 0;
    await _persist();
    notifyListeners();
  }
}