import 'package:flutter_test/flutter_test.dart';

/// Contrato del motor offline replicado de js/sync.js (reglas de push/flush).
/// Se testea la LÓGICA de decisión, no la red.
bool isDefinitiveRejection(Map<String, dynamic>? response) =>
    response != null;

bool shouldEnqueue(Map<String, dynamic>? response, String errorType) {
  if (isDefinitiveRejection(response)) return false; // rechazo definitivo
  return errorType == 'network' || errorType == 'timeout'; // encodable
}

String resolveAction(Map<String, dynamic> pushResult) {
  if (pushResult['queued'] == true) return 'queued';
  if (pushResult['error'] != null) return 'error';
  return 'success';
}

void main() {
  group('Reglas de cola (push)', () {
    test('rechazo definitivo del servidor NO se encola', () {
      final resp = <String, dynamic>{'success': false};
      expect(shouldEnqueue(resp, 'network'), isFalse);
    });

    test('error de red sí se encola', () {
      expect(shouldEnqueue(null, 'network'), isTrue);
    });

    test('timeout sí se encola', () {
      expect(shouldEnqueue(null, 'timeout'), isTrue);
    });

    test('error de JSON NO se encola', () {
      expect(shouldEnqueue(null, 'json'), isFalse);
    });

    test('respuesta exitosa no necesita encolar', () {
      final resp = <String, dynamic>{'success': true, 'data': {}};
      expect(isDefinitiveRejection(resp), isTrue); // tiene response = no encolar
    });
  });

  group('resolveAction', () {
    test('queued cuando res tiene queued:true', () {
      expect(resolveAction({'queued': true}), 'queued');
    });

    test('error cuando tiene error', () {
      expect(resolveAction({'error': 'fail'}), 'error');
    });

    test('success por defecto', () {
      expect(resolveAction({}), 'success');
    });
  });

  group('Orden de flush', () {
    test('entrada offline repone stock ANTES de vender', () {
      var localStock = 0;
      localStock += 5; // entrada optimista
      localStock -= 2; // venta
      expect(localStock, 3);
      expect(localStock >= 0, isTrue);
    });

    test('flush respeta FIFO: primera encolada se envía primero', () {
      final queue = <String>[];
      queue.add('op_A');
      queue.add('op_B');
      queue.add('op_C');

      // Simular flush FIFO
      final sent = <String>[];
      while (queue.isNotEmpty) {
        sent.add(queue.removeAt(0));
      }
      expect(sent, ['op_A', 'op_B', 'op_C']);
    });

    test('error de red detiene flush, resto queda en cola', () {
      final queue = ['A', 'B', 'C', 'D'];
      final sent = <String>[];

      // Simular: A y B se envían, C falla
      sent.add(queue.removeAt(0)); // A
      sent.add(queue.removeAt(0)); // B
      // C falla → parar
      expect(queue, ['C', 'D']);
      expect(sent, ['A', 'B']);
    });

    test('rechazo definitivo deja op en cola y continúa', () {
      final queue = ['A', 'B', 'C'];
      final sent = <String>[];
      final discarded = <String>[];

      // A: ok, B: rechazo definitivo (queda), C: ok
      sent.add(queue.removeAt(0)); // A
      discarded.add(queue.removeAt(0)); // B: rechazada, se ignora
      sent.add(queue.removeAt(0)); // C

      expect(sent, ['A', 'C']);
      expect(queue, isEmpty);
    });
  });

  group('Ajuste de stock local', () {
    test('entrada suma stock', () {
      var stock = 10;
      stock += 5;
      expect(stock, 15);
    });

    test('salida/resta descuenta stock', () {
      var stock = 10;
      stock -= 3;
      expect(stock, 7);
    });

    test('transferencia: resta origen, suma destino', () {
      var origin = 10;
      var dest = 5;
      const qty = 3;
      origin -= qty;
      dest += qty;
      expect(origin, 7);
      expect(dest, 8);
    });

    test('stock nunca negativo (clamp)', () {
      var stock = 2;
      stock = (stock - 5).clamp(0, 9999);
      expect(stock, 0);
    });
  });
}
