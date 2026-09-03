import 'dart:async';
import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:web_socket_channel/web_socket_channel.dart';


/// Servicio WebSocket (bridge opcional) — equivalente a js/sync.js connectWs().
/// Si el servidor tiene el bridge configurado (wsUrl en config), se conecta
/// para recibir notificaciones de cambios en tiempo real. Reconexión con
/// backoff exponencial. Sin bridge, la app funciona igual con polling.
class WebSocketService extends ChangeNotifier {
  WebSocketService._();
  static final WebSocketService I = WebSocketService._();

  WebSocketChannel? _channel;
  Timer? _reconnectTimer;
  int _reconnectAttempt = 0;
  bool _connected = false;
  bool _shouldConnect = false;
  final List<void Function(String)> _messageListeners = [];
  final List<void Function()> _changeListeners = [];

  bool get isConnected => _connected;

  void onMessage(void Function(String type) fn) => _messageListeners.add(fn);
  void onChange(void Function() fn) => _changeListeners.add(fn);

  void _emitMessage(String type) {
    for (final fn in List.of(_messageListeners)) {
      try { fn(type); } catch (_) {}
    }
  }

  void _emitChange() {
    for (final fn in List.of(_changeListeners)) {
      try { fn(); } catch (_) {}
    }
  }

  /// Intenta conectar al WebSocket bridge. Si no hay URL configurada o el
  /// token no está disponible, no hace nada (la app sigue con polling).
  void connect({String? wsUrl, String? token}) {
    final url = wsUrl ?? '';
    if (url.isEmpty || token == null || token.isEmpty) return;
    _shouldConnect = true;
    _doConnect(url, token);
  }

  void _doConnect(String url, String token) {
    if (!_shouldConnect) return;
    try {
      _channel = WebSocketChannel.connect(Uri.parse(url));
      _channel!.stream.listen(
        (data) {
          _connected = true;
          _reconnectAttempt = 0;
          try {
            final msg = jsonDecode('$data') as Map<String, dynamic>;
            final type = msg['type'] as String? ?? '';
            if (type == 'changes') {
              _emitMessage('changes');
              _emitChange();
            } else if (type == 'hello_ok') {
              _emitMessage('connected');
            }
          } catch (_) {}
        },
        onError: (_) => _scheduleReconnect(url, token),
        onDone: () {
          _connected = false;
          notifyListeners();
          _scheduleReconnect(url, token);
        },
      );

      // Send hello
      _channel!.sink.add(jsonEncode({
        'type': 'hello',
        'token': token,
        'app': 'shopup-mobile-flutter',
      }));
      notifyListeners();
    } catch (_) {
      _scheduleReconnect(url, token);
    }
  }

  void _scheduleReconnect(String url, String token) {
    _connected = false;
    notifyListeners();
    if (!_shouldConnect) return;
    final delays = [2, 4, 8, 16, 30];
    final idx = _reconnectAttempt.clamp(0, delays.length - 1);
    final delay = Duration(seconds: delays[idx]);
    _reconnectAttempt++;
    _reconnectTimer?.cancel();
    _reconnectTimer = Timer(delay, () => _doConnect(url, token));
  }

  void disconnect() {
    _shouldConnect = false;
    _reconnectTimer?.cancel();
    try { _channel?.sink.close(); } catch (_) {}
    _channel = null;
    _connected = false;
    notifyListeners();
  }
}
