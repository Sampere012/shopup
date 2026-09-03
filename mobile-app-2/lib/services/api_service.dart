import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import '../config.dart';

/// Log centralizado del flujo de red/login. Visible con `flutter run`
/// o `adb logcat -s flutter`.
void wsLog(String msg) {
  debugPrint('[WS] $msg');
}

class ApiException implements Exception {
  final String message;
  final Map<String, dynamic>? response; // no nulo = rechazo definitivo del servidor
  ApiException(this.message, {this.response});
  @override
  String toString() => message;
}

/// Cliente de la API WordPress (admin-ajax) autenticado por X-WS-Token.
/// Equivalente a js/api.js: dedupe de peticiones idénticas en vuelo y
/// errores tipados ({offline|network|server} + response si el servidor
/// respondió {success:false}).
class ApiService {
  ApiService._();
  static final ApiService I = ApiService._();

  static const _tokenKey = 'wsm_token';
  static const _serverKey = 'wsm_server';

  String? _token;
  String? _server;

  String get server => _server ?? AppConfig.defaultServer;
  Uri get endpoint => Uri.parse('$server/wp-admin/admin-ajax.php');

  Future<void> load() async {
    final sp = await SharedPreferences.getInstance();
    _token = sp.getString(_tokenKey);
    _server = sp.getString(_serverKey);
  }

  String? get token => _token;

  Future<void> setToken(String? t) async {
    _token = t;
    final sp = await SharedPreferences.getInstance();
    if (t == null || t.isEmpty) {
      await sp.remove(_tokenKey);
    } else {
      await sp.setString(_tokenKey, t);
    }
  }

  Future<void> setServer(String s) async {
    s = s.trim().replaceAll(RegExp(r'/+$'), '');
    _server = s;
    final sp = await SharedPreferences.getInstance();
    await sp.setString(_serverKey, s);
  }

  /// Petición admin-ajax. [timeoutSec] ampliable para reportes pesados.
  /// NOTA: sin dedupe de peticiones en vuelo — el wrapper whenComplete+mapa
  /// rompía la cadena de Futures (el await del caller nunca reanudaba).
  Future<dynamic> req(String action, Map<String, dynamic> data,
      {int timeoutSec = 20}) {
    return _doReq(action, data, timeoutSec);
  }

  Future<dynamic> _doReq(String action, Map<String, dynamic> data,
      int timeoutSec) async {
    try {
      final body = <String, String>{'action': action};
      data.forEach((k, v) {
        if (v == null) return;
        // Igual que la app anterior: objetos/arreglos van como string JSON.
        body[k] = (v is List || v is Map) ? jsonEncode(v) : '$v';
      });
      wsLog('→ $action endpoint=$endpoint');
      final res = await http
          .post(endpoint,
              headers: {
                'X-WS-Token': _token ?? '',
                'X-Requested-With': 'XMLHttpRequest',
              },
              body: body)
          .timeout(Duration(seconds: timeoutSec));
      wsLog('← $action status=${res.statusCode}');
      final raw = utf8.decode(res.bodyBytes, allowMalformed: true);
      wsLog('← $action body(${raw.length}): '
          '${raw.length > 160 ? raw.substring(0, 160) : raw}');
      if (res.statusCode >= 500) {
        wsLog('✗ $action HTTP ${res.statusCode}');
        throw ApiException('Servidor no disponible (${res.statusCode})',
            response: null);
      }
      dynamic json;
      try {
        json = jsonDecode(raw);
      } catch (e) {
        wsLog('✗ $action JSON inválido: $e');
        throw ApiException('Respuesta inválida del servidor');
      }
      if (json is! Map<String, dynamic>) {
        wsLog('✗ $action respuesta no es Map: ${json.runtimeType}');
        throw ApiException('Respuesta inválida del servidor');
      }
      final ok = json['success'] == true || json['success'] == 1;
      wsLog('← $action success=$ok dataKeys='
          '${json['data'] is Map ? (json['data'] as Map).keys.toList() : json['data'].runtimeType}');
      if (!ok) {
        final msg = (json['data'] is Map
                ? (json['data']['message'] ?? json['data'].toString())
                : (json['data']?.toString() ?? 'Error'))
            .toString();
        wsLog('✗ $action success=false msg="$msg" raw=${json.toString().length > 300 ? json.toString().substring(0, 300) : json}');
        throw ApiException(msg, response: json);
      }
      return json['data'];
    } on TimeoutException {
      wsLog('✗ $action TIMEOUT (${timeoutSec}s)');
      throw ApiException('Tiempo de espera agotado');
    } on ApiException {
      rethrow;
    } catch (e) {
      // Sin red / DNS / socket → error de red (encolable).
      wsLog('✗ $action error de red: $e');
      throw ApiException('Sin conexión con el servidor', response: null);
    }
  }

  /// Descarga binaria (PDFs del servidor, p.ej. catálogo de stock).
  /// Misma lógica que la web (theme.js downloadCatalog): si el servidor
  /// responde JSON es un rechazo → se lanza su msg; si no, se devuelven los
  /// bytes y el nombre sugerido (header X-WS-Filename).
  Future<ApiBytes> reqBytes(String action, Map<String, dynamic> data,
      {int timeoutSec = 60}) async {
    try {
      final body = <String, String>{'action': action};
      data.forEach((k, v) {
        if (v == null) return;
        body[k] = (v is List || v is Map) ? jsonEncode(v) : '$v';
      });
      wsLog('→ $action (bytes) endpoint=$endpoint');
      final res = await http
          .post(endpoint,
              headers: {
                'X-WS-Token': _token ?? '',
                'X-Requested-With': 'XMLHttpRequest',
              },
              body: body)
          .timeout(Duration(seconds: timeoutSec));
      wsLog('← $action status=${res.statusCode} bytes=${res.bodyBytes.length}');
      if (res.statusCode >= 500) {
        throw ApiException('Servidor no disponible (${res.statusCode})',
            response: null);
      }
      final ct = res.headers['content-type'] ?? '';
      if (ct.contains('application/json')) {
        // Rechazo del servidor con {success:false,data:{msg}} igual que la web.
        final raw = utf8.decode(res.bodyBytes, allowMalformed: true);
        String msg = 'No se pudo exportar.';
        try {
          final j = jsonDecode(raw);
          if (j is Map && j['data'] is Map && '${j['data']['msg'] ?? ''}'.isNotEmpty) {
            msg = '${j['data']['msg']}';
          }
        } catch (_) {}
        throw ApiException(msg);
      }
      if (res.bodyBytes.isEmpty) throw ApiException('El servidor no devolvió datos.');
      final filename = res.headers['x-ws-filename'] ??
          'catalogo_${DateTime.now().toIso8601String().substring(0, 10)}.pdf';
      return ApiBytes(res.bodyBytes, filename);
    } on TimeoutException {
      throw ApiException('Tiempo de espera agotado');
    } on ApiException {
      rethrow;
    } catch (e) {
      if (e is ApiException) rethrow;
      throw ApiException('Sin conexión con el servidor', response: null);
    }
  }
}

/// Respuesta binaria del servidor + nombre de archivo sugerido.
class ApiBytes {
  final Uint8List bytes;
  final String filename;
  ApiBytes(this.bytes, this.filename);
}
