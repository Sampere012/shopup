import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Onboarding de la app, réplica del tutorial web (inc/tutorial.php + Alpine
/// wsTutorial): bienvenida de primer uso tras registrarse y guía paso a paso
/// por sección, accesible en cualquier momento desde Mi cuenta.
class TutorialService extends ChangeNotifier {
  TutorialService._();
  static final TutorialService I = TutorialService._();

  static const _key = 'ws_tutorial_pending';

  bool _welcomePending = false;

  /// La bienvenida se muestra UNA sola vez (primer acceso tras registrarse),
  /// igual que la meta ws_tutorial_pending de la web.
  bool get welcomePending => _welcomePending;

  Future<void> load() async {
    final sp = await SharedPreferences.getInstance();
    _welcomePending = sp.getBool(_key) ?? false;
  }

  /// Lo llama el registro al crear la sesión (equivale a la meta del usuario).
  Future<void> markWelcomePending() async {
    _welcomePending = true;
    final sp = await SharedPreferences.getInstance();
    await sp.setBool(_key, true);
    notifyListeners();
  }

  /// Se consume al mostrar la bienvenida (como delete_user_meta en la web).
  Future<void> consumeWelcome() async {
    if (!_welcomePending) return;
    _welcomePending = false;
    final sp = await SharedPreferences.getInstance();
    await sp.setBool(_key, false);
    notifyListeners();
  }
}

/// Secciones de la guía: mismas que la web, contadas para el panel del móvil
/// (dashboard, productos, stock, POS, pedidos, gastos, reportes, plan).
class TutorialSection {
  final String key;
  final String title;
  final IconData icon;
  final String description;
  final List<TutorialStep> steps;
  const TutorialSection({
    required this.key,
    required this.title,
    required this.icon,
    required this.description,
    required this.steps,
  });
}

class TutorialStep {
  final String title;
  final String text;
  const TutorialStep(this.title, this.text);
}

const tutorialSections = <TutorialSection>[
  TutorialSection(
    key: 'dashboard',
    title: 'Dashboard',
    icon: Icons.speed,
    description: 'El resumen diario de tu negocio: ventas, pedidos y estados.',
    steps: [
      TutorialStep('Resumen en vivo',
          'Arriba verás las tarjetas del día: ingresos, pedidos y ventas de POS. Se actualizan solas al sincronizar.'),
      TutorialStep('Pedidos recientes',
          'Abajo están los últimos pedidos con su estado. Toca uno para ver el detalle y aceptarlo si corresponde.'),
      TutorialStep('Sincronizar',
          'Con el botón de sincronizar del menú puedes refrescar todo cuando quieras; también se hace sola.'),
    ],
  ),
  TutorialSection(
    key: 'products',
    title: 'Productos',
    icon: Icons.inventory_2_outlined,
    description: 'Crea y edita tu catálogo con precio, costo e imágenes.',
    steps: [
      TutorialStep('Buscar y filtrar',
          'Usa el buscador para encontrar un producto por nombre o código al instante.'),
      TutorialStep('Crear producto',
          'Con el botón + abres el formulario: nombre, precio, costo, categoría e imagen. Se guarda en la nube o queda en cola si no hay red.'),
      TutorialStep('Editar o eliminar',
          'Toca un producto para ver su detalle; desde ahí puedes editarlo o eliminarlo si tu rol lo permite.'),
    ],
  ),
  TutorialSection(
    key: 'stock',
    title: 'Stock y movimientos',
    icon: Icons.warehouse_outlined,
    description: 'Entradas, salidas, mermas y transferencias entre ubicaciones.',
    steps: [
      TutorialStep('Stock por ubicación',
          'Elige la ubicación para ver las cantidades disponibles de cada producto.'),
      TutorialStep('Movimientos',
          'Registra entradas de compra, salidas de venta, mermas y transferencias; cada movimiento queda en el Historial.'),
      TutorialStep('Cuadre',
          'Con el Cuadre comparas el stock teórico contra el real y ajustas diferencias.'),
    ],
  ),
  TutorialSection(
    key: 'pos',
    title: 'Punto de venta (POS)',
    icon: Icons.point_of_sale,
    description: 'Cobra rápido, sin conexión, con tu catálogo siempre a mano.',
    steps: [
      TutorialStep('Cobrar',
          'Añade productos al carrito, aplica descuento si quieres y cobra con el método de pago del cliente.'),
      TutorialStep('Sin conexión',
          'El POS funciona sin internet: las ventas se envían solas al reconectar, con su ticket y descuento.'),
      TutorialStep('Ventas POS',
          'En Ventas POS ves el historial completo con sus estados y puedes reenviar el ticket.'),
    ],
  ),
  TutorialSection(
    key: 'orders',
    title: 'Pedidos',
    icon: Icons.receipt_long_outlined,
    description: 'Los pedidos de tu tienda online, listos para aceptar.',
    steps: [
      TutorialStep('Nuevos pedidos',
          'Cuando un cliente compra en tu tienda, el pedido aparece aquí y la app te avisa en tiempo real.'),
      TutorialStep('Aceptar y completar',
          'Acepta el pedido, prepara la venta y márcalo completado; el stock se descuenta solo.'),
      TutorialStep('Notificaciones',
          'Las notificaciones te avisan de cada pedido nuevo aunque la app esté en segundo plano.'),
    ],
  ),
  TutorialSection(
    key: 'expenses',
    title: 'Gastos',
    icon: Icons.payments_outlined,
    description: 'Registra los gastos del negocio, organizados por mes.',
    steps: [
      TutorialStep('El gasto es por mes',
          'Cada gasto tiene su fecha: se agrupa en el mes que elijas con las flechas de navegación, y el total del mes se recalcula al momento.'),
      TutorialStep('Crear y repetir',
          'Crea un gasto con concepto, monto, categoría y fecha. Si es recurrente (alquiler, servicios), usa Repetir para crearlo de una vez en 3, 6 o 12 meses.'),
      TutorialStep('Editar o eliminar',
          'Abre un gasto para ver su detalle; desde ahí o desde la fila puedes editarlo o eliminarlo si tu rol lo permite.'),
    ],
  ),
  TutorialSection(
    key: 'reports',
    title: 'Reportes',
    icon: Icons.pie_chart_outline,
    description: 'Ventas, utilidad y gastos por período y ubicación.',
    steps: [
      TutorialStep('Período y ubicación',
          'Elige los días y la ubicación: el reporte calcula ingresos (pedidos + POS), gastos del mes y utilidad.'),
      TutorialStep('Top productos',
          'Descubre qué productos venden más para decidir qué reponer o promocionar.'),
    ],
  ),
  TutorialSection(
    key: 'plan',
    title: 'Plan y suscripción',
    icon: Icons.workspace_premium_outlined,
    description: 'Tu plan actual, el uso de los límites y cómo ampliarlo.',
    steps: [
      TutorialStep('Uso del plan',
          'Revisa cuántos productos, ubicaciones o trabajadores estás usando frente a los límites de tu plan.'),
      TutorialStep('Cambiar de plan',
          'Si necesitas más, elige un plan y solicita el upgrade: el administrador lo revisará y habilitará tu negocio. Verás el estado de tu solicitud en todo momento.'),
    ],
  ),
];
