<?php
/**
 * Tutorial / onboarding del panel.
 *
 * Contenido paso a paso de cada sección. El panel filtra estas secciones con
 * las mismas capacidades que el menú lateral: solo se enseña lo que el rol
 * puede ver, para cualquier rol (dueño, almacenero, vendedor o admin).
 *
 * Cada sección explica de forma completa su interfaz: pestañas, tablas,
 * formularios, botones y pasos reales de trabajo. Algunas secciones tienen
 * más de tres pasos porque su flujo es más largo.
 *
 * @package Workshop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Contenido del tutorial por sección del panel.
 *
 * - `steps`: lista numerada de pasos de trabajo {título, texto}.
 * - `tour`:  recorrido guiado (spotlight) sobre elementos reales de la sección.
 *            Cada paso puede incluir `cap` para mostrarse solo con permiso.
 *
 * @return array<string,array{icon:string,desc:string,steps:array<int,array{string,string}>,tour?:array<int,array{sel:string,title:string,text:string,tip:string,cap?:string}>}>
 */
function ws_tutorial_data() {
    return array(
        'dashboard' => array(
            'icon'  => 'fa-gauge-high',
            'desc'  => __( 'Resumen de tu negocio: ventas, pedidos y avisos en un solo vistazo.', 'workshop' ),
            'steps' => array(
                array( __( 'Revisa tus indicadores', 'workshop' ), __( 'Ventas de hoy, pedidos pendientes y alertas de stock aparecen al inicio, cada uno con su icono y color.', 'workshop' ) ),
                array( __( 'Atiende los avisos', 'workshop' ), __( 'Las notificaciones te avisan de stock bajo, pedidos nuevos o movimientos recientes en el panel.', 'workshop' ) ),
                array( __( 'Revisa tus ubicaciones', 'workshop' ), __( 'La tarjeta «Mis ubicaciones» muestra tus puntos de venta y almacenes, cada uno con su tipo.', 'workshop' ) ),
                array( __( 'Comparte tu tienda', 'workshop' ), __( 'En cada punto de venta pulsa «Ver tienda pública» para abrir y compartir tu tienda con los clientes.', 'workshop' ) ),
                array( __( 'Consulta pedidos recientes', 'workshop' ), __( 'Los últimos pedidos (número, cliente, total y estado), ordenables por sus columnas.', 'workshop' ) ),
                array( __( 'Usa los accesos rápidos', 'workshop' ), __( 'Desde el menú lateral ve directo a cualquier sección: Stock, Pedidos, Ventas…', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-kpis', 'title' => __( 'Tus indicadores de un vistazo', 'workshop' ), 'text' => __( 'Productos activos, stock bajo, pedidos pendientes y ventas de hoy: los números que importan, siempre a la vista.', 'workshop' ), 'tip' => __( 'Revisa el panel cada mañana: si «Stock bajo» sube, entra a Stock y repón antes de que falte.', 'workshop' ) ),
                array( 'sel' => '.ws-card', 'title' => __( 'Mis ubicaciones', 'workshop' ), 'text' => __( 'Tus puntos de venta y almacenes. Cada punto de venta tiene su propia tienda online.', 'workshop' ), 'tip' => __( 'Pulsa «Ver tienda pública» para abrir y compartir tu tienda con los clientes.', 'workshop' ) ),
                array( 'sel' => '.ws-table', 'title' => __( 'Pedidos recientes', 'workshop' ), 'text' => __( 'Los últimos pedidos de tus tiendas: número, cliente, total y estado.', 'workshop' ), 'tip' => __( 'Las cabeceras de la tabla ordenan los datos de un clic.', 'workshop' ) ),
            ),
        ),
        'products' => array(
            'icon'  => 'fa-boxes-stacked',
            'desc'  => __( 'Gestiona tu catálogo: productos, precios, stock mínimo y fraccionamiento.', 'workshop' ),
            'steps' => array(
                array( __( 'Conoce las dos pestañas', 'workshop' ), __( '«Productos» es tu catálogo; «Historial de precios» guarda cada cambio de costo o venta.', 'workshop' ) ),
                array( __( 'Busca un producto', 'workshop' ), __( 'Usa el buscador superior: filtra mientras escribes, sin recargar la página.', 'workshop' ) ),
                array( __( 'Crea un producto', 'workshop' ), __( 'Pulsa «Nuevo producto» y completa el formulario: nombre, código de barras, imagen, descripción, precios de costo y venta, % de transferencia, moneda, proveedor y stock mínimo.', 'workshop' ) ),
                array( __( 'Define el stock mínimo', 'workshop' ), __( 'El sistema te avisará cuando un producto baje de ese número, así sabes cuándo reponer.', 'workshop' ) ),
                array( __( 'Muestra el precio equivalente', 'workshop' ), __( 'Activa la opción «Mostrar precio equivalente» para que la tienda muestre CUP ↔ USD.', 'workshop' ) ),
                array( __( 'Fracciona un producto', 'workshop' ), __( 'En el bloque «Fraccionamiento» conviértelo en unidad menor de un producto madre (Ej.: 1 saco = 3 jabas). Al vender o dar entrada a uno, el otro se ajusta solo.', 'workshop' ) ),
                array( __( 'Edita o clona', 'workshop' ), __( 'Con los iconos de cada fila editas un producto, lo clonas o lo eliminas.', 'workshop' ) ),
                array( __( 'Importa muchos productos', 'workshop' ), __( 'Usa «Importar CSV» para cargar productos en lote: arrastra el archivo y deja que el sistema los dé de alta.', 'workshop' ) ),
                array( __( 'Revisa el historial de precios', 'workshop' ), __( 'En la segunda pestaña ves quién y cuándo subió o bajó cada precio, con el antes y el después.', 'workshop' ) ),
                array( __( 'Navega tu catálogo', 'workshop' ), __( 'La paginación inferior te deja elegir de 10 a 100 productos por página.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-tabs', 'title' => __( 'Dos vistas en una', 'workshop' ), 'text' => __( '«Productos» es tu catálogo; «Historial de precios» guarda cada cambio de costo o venta.', 'workshop' ), 'tip' => __( 'El historial es tu trazabilidad: úsalo si dudas cuándo subió un precio.', 'workshop' ) ),
                array( 'sel' => '.ws-toolbar .ws-search', 'title' => __( 'Buscador instantáneo', 'workshop' ), 'text' => __( 'Filtra tu catálogo mientras escribes, sin recargar la página.', 'workshop' ), 'tip' => __( 'Con cientos de productos, dos o tres letras bastan para encontrar el tuyo.', 'workshop' ) ),
                array( 'sel' => '.ws-toolbar .ws-btn-primary', 'title' => __( 'Nuevo producto', 'workshop' ), 'text' => __( 'Abre el formulario para dar de alta un artículo con todos sus datos: nombre, costo, venta, moneda, proveedor y stock mínimo.', 'workshop' ), 'tip' => __( 'Define bien el stock mínimo: el sistema te avisará cuando baje de ahí.', 'workshop' ) ),
                array( 'sel' => '.ws-fraction-box', 'cap' => 'products_fraction', 'title' => __( 'Fraccionamiento', 'workshop' ), 'text' => __( 'Convierte un producto en unidad menor de otro (Ej.: 1 saco = 3 jabas). La venta o entrada de uno ajusta automáticamente el otro.', 'workshop' ), 'tip' => __( 'Vender 1 jaba descuenta ⅓ de saco; dar entrada a 1 saco suma 3 jabas.', 'workshop' ) ),
                array( 'sel' => '.ws-table', 'title' => __( 'Tu catálogo', 'workshop' ), 'text' => __( 'Cada fila es un producto. Con los iconos de la derecha editas, clonas o eliminas.', 'workshop' ), 'tip' => __( 'Las cabeceras ordenan la tabla y la paginación inferior navega de 10 a 100 por página.', 'workshop' ) ),
            ),
        ),
        'locations' => array(
            'icon'  => 'fa-location-dot',
            'desc'  => __( 'Tus puntos de venta y almacenes, cada uno con su tienda online.', 'workshop' ),
            'steps' => array(
                array( __( 'Crea una ubicación', 'workshop' ), __( 'Pulsa «Nueva ubicación» y elige el tipo: punto de venta (PV) o almacén.', 'workshop' ) ),
                array( __( 'Completa sus datos', 'workshop' ), __( 'Nombre, dirección, foto, moneda, WhatsApp para pedidos y coste de domicilio.', 'workshop' ) ),
                array( __( 'Escribe el enlace', 'workshop' ), __( 'El campo «URL de acceso» genera el enlace público de tu tienda (ej.: mi-tienda).', 'workshop' ) ),
                array( __( 'Elige cómo cobrar', 'workshop' ), __( 'Marca los métodos de pago que acepta: efectivo, tarjeta, transferencia o pago móvil.', 'workshop' ) ),
                array( __( 'Actívala para vender', 'workshop' ), __( 'Marca «Visible» para que la tienda aparezca y los clientes puedan pedir.', 'workshop' ) ),
                array( __( 'Comparte tu tienda', 'workshop' ), __( 'Desde el botón «Tienda» o «Ver tienda pública» abres el enlace para compartirlo.', 'workshop' ) ),
                array( __( 'Edita o elimina', 'workshop' ), __( 'Usa los iconos de cada fila para modificar los datos o borrar la ubicación.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-toolbar', 'title' => __( 'Barra de acciones', 'workshop' ), 'text' => __( 'Desde aquí buscas y creas ubicaciones; el buscador filtra al instante.', 'workshop' ), 'tip' => __( 'Cada ubicación nueva puede ser un punto de venta o un almacén.', 'workshop' ) ),
                array( 'sel' => '.ws-table', 'title' => __( 'Tus ubicaciones', 'workshop' ), 'text' => __( 'Cada fila es una ubicación con su tipo, estado y acciones.', 'workshop' ), 'tip' => __( 'Activa el punto de venta para que su tienda aparezca en el mercado.', 'workshop' ) ),
                array( 'sel' => '.ws-pagination', 'title' => __( 'Paginación', 'workshop' ), 'text' => __( 'Si tienes muchas ubicaciones, navega entre páginas y elige cuántas ver.', 'workshop' ), 'tip' => '' ),
            ),
        ),
        'suppliers' => array(
            'icon'  => 'fa-truck-field',
            'desc'  => __( 'Registra a tus proveedores para controlar las compras.', 'workshop' ),
            'steps' => array(
                array( __( 'Añade un proveedor', 'workshop' ), __( 'Pulsa «Nuevo proveedor» y escribe su nombre (obligatorio).', 'workshop' ) ),
                array( __( 'Completa el contacto', 'workshop' ), __( 'Teléfono, dirección, país y provincia para tenerlo todo a mano.', 'workshop' ) ),
                array( __( 'Edita sus datos', 'workshop' ), __( 'Con el icono de lápiz puedes corregir cualquier dato más adelante.', 'workshop' ) ),
                array( __( 'Elimina proveedores', 'workshop' ), __( 'Usa la papelera para borrar un proveedor que ya no uses.', 'workshop' ) ),
                array( __( 'Busca y ordena', 'workshop' ), __( 'Usa el buscador y las cabeceras de la tabla para encontrar y ordenar proveedores.', 'workshop' ) ),
                array( __( 'Asócialo a productos', 'workshop' ), __( 'Relaciona cada proveedor con sus artículos para saber a quién le compras.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-toolbar', 'title' => __( 'Gestiona proveedores', 'workshop' ), 'text' => __( 'Busca y añade tus proveedores desde esta barra.', 'workshop' ), 'tip' => __( 'Registra el teléfono: te será útil para pedir reposiciones.', 'workshop' ) ),
                array( 'sel' => '.ws-table', 'title' => __( 'Lista de proveedores', 'workshop' ), 'text' => __( 'Cada fila muestra un proveedor con sus acciones de edición o borrado.', 'workshop' ), 'tip' => __( 'Asocia cada producto a su proveedor habitual para saber a quién comprar.', 'workshop' ) ),
                array( 'sel' => '.ws-pagination', 'title' => __( 'Paginación', 'workshop' ), 'text' => __( 'Navega entre páginas cuando tengas muchos proveedores.', 'workshop' ), 'tip' => '' ),
            ),
        ),
        'stock' => array(
            'icon'  => 'fa-warehouse',
            'desc'  => __( 'Controla el stock por ubicación: entradas, salidas, bajas y transferencias.', 'workshop' ),
            'steps' => array(
                array( __( 'Elige la vista', 'workshop' ), __( 'Filtra por ubicación con el selector y marca «Solo stock bajo» para ver lo urgente.', 'workshop' ) ),
                array( __( 'Busca un producto', 'workshop' ), __( 'El buscador filtra el inventario al instante.', 'workshop' ) ),
                array( __( 'Lee la tabla', 'workshop' ), __( 'Cada fila es un producto en una ubicación: stock, mínimo y precio de venta; se resalta en rojo si está bajo el mínimo.', 'workshop' ) ),
                array( __( 'Haz una entrada', 'workshop' ), __( 'Con el icono de flecha hacia abajo registras mercancía que llega, eligiendo ubicación y cantidad.', 'workshop' ) ),
                array( __( 'Haz una salida', 'workshop' ), __( 'El icono de flecha hacia arriba descuenta stock vendido, usado o enviado.', 'workshop' ) ),
                array( __( 'Da de baja', 'workshop' ), __( 'Usa el icono de papelera para eliminar stock por robo, merma o daño.', 'workshop' ) ),
                array( __( 'Transfiere stock', 'workshop' ), __( 'El icono de flechas opuestas mueve productos entre almacén y puntos de venta al instante.', 'workshop' ) ),
                array( __( 'Usa el asistente', 'workshop' ), __( 'El botón «Nuevo movimiento» guía un registro en 3 pasos: tipo y ubicación, elige productos, confirma.', 'workshop' ) ),
                array( __( 'Añade referencia y nota', 'workshop' ), __( 'Registra el nº de factura (referencia) y una nota para dejar trazabilidad.', 'workshop' ) ),
                array( __( 'Navega el inventario', 'workshop' ), __( 'La paginación deja mostrar de 10 a 100 filas por página.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-table', 'title' => __( 'Stock por ubicación', 'workshop' ), 'text' => __( 'Aquí ves cuánto tienes de cada producto en cada tienda o almacén.', 'workshop' ), 'tip' => __( 'Si el stock llega al mínimo del producto, es momento de reponer.', 'workshop' ) ),
                array( 'sel' => '.ws-icon-btn', 'title' => __( 'Acciones rápidas', 'workshop' ), 'text' => __( 'Los iconos de cada fila abren los movimientos: entrada, salida, baja o transferencia.', 'workshop' ), 'tip' => __( 'Usa «transferencia» para mover stock entre el almacén y tus tiendas.', 'workshop' ) ),
                array( 'sel' => '.ws-stock-head .ws-btn', 'title' => __( 'Nuevo movimiento', 'workshop' ), 'text' => __( 'Abre un asistente paso a paso para registrar entrada, salida, baja o traslado de varios productos a la vez.', 'workshop' ), 'tip' => __( 'Para un solo producto, usa los iconos de la fila; para muchos, el asistente.', 'workshop' ) ),
                array( 'sel' => '.ws-pagination', 'title' => __( 'Paginación', 'workshop' ), 'text' => __( 'Navega entre páginas de stock cuando el inventario sea largo.', 'workshop' ), 'tip' => '' ),
            ),
        ),
        'movements' => array(
            'icon'  => 'fa-clock-rotate-left',
            'desc'  => __( 'Historial completo de cada movimiento de tu inventario.', 'workshop' ),
            'steps' => array(
                array( __( 'Consulta el historial', 'workshop' ), __( 'Cada entrada, salida, baja o transferencia queda registrada aquí con su fecha.', 'workshop' ) ),
                array( __( 'Filtra por tipo', 'workshop' ), __( 'Elige el tipo de movimiento: entrada, salida, baja, transferencia o pedido.', 'workshop' ) ),
                array( __( 'Filtra por ubicación', 'workshop' ), __( 'Acota el historial a una sola tienda o almacén.', 'workshop' ) ),
                array( __( 'Busca por texto', 'workshop' ), __( 'El buscador encuentra movimientos por producto, referencia o usuario.', 'workshop' ) ),
                array( __( 'Lee el detalle', 'workshop' ), __( 'Cantidad, referencia, destino y quién lo hizo: todo para cada movimiento.', 'workshop' ) ),
                array( __( 'Detecta problemas', 'workshop' ), __( 'Compara los movimientos con tu stock actual para detectar errores.', 'workshop' ) ),
                array( __( 'Ordena y página', 'workshop' ), __( 'Las cabeceras ordenan la tabla y la paginación navega los historiales largos.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-stock-filters', 'title' => __( 'Filtros del historial', 'workshop' ), 'text' => __( 'Filtra por tipo de movimiento, ubicación o período para encontrar lo que buscas.', 'workshop' ), 'tip' => __( 'Si el stock no cuadra, aquí está el porqué: cada movimiento queda registrado.', 'workshop' ) ),
                array( 'sel' => '.ws-table', 'title' => __( 'Historial de movimientos', 'workshop' ), 'text' => __( 'Entradas, salidas, bajas y transferencias, con su fecha y responsable.', 'workshop' ), 'tip' => __( 'Las cabeceras ordenan la tabla de un clic.', 'workshop' ) ),
                array( 'sel' => '.ws-pagination', 'title' => __( 'Paginación', 'workshop' ), 'text' => __( 'Navega entre páginas en historiales largos.', 'workshop' ), 'tip' => '' ),
            ),
        ),
        'orders' => array(
            'icon'  => 'fa-receipt',
            'desc'  => __( 'Recibe, acepta o rechaza los pedidos de tus tiendas.', 'workshop' ),
            'steps' => array(
                array( __( 'Revisa los pedidos nuevos', 'workshop' ), __( 'Los pedidos pendientes aparecen aquí en cuanto llegan.', 'workshop' ) ),
                array( __( 'Filtra por estado', 'workshop' ), __( 'Pendientes, aceptados, completados, rechazados o cancelados: enfócate en lo que toca.', 'workshop' ) ),
                array( __( 'Explora la tabla', 'workshop' ), __( 'Nº, tienda, cliente, teléfono, total, estado y fecha de cada pedido.', 'workshop' ) ),
                array( __( 'Abre el detalle', 'workshop' ), __( 'El icono del ojo muestra cliente, dirección, artículos, subtotal, domicilio y total.', 'workshop' ) ),
                array( __( 'Acepta el pedido', 'workshop' ), __( 'Al aceptar se descuenta el stock automáticamente.', 'workshop' ) ),
                array( __( 'Rechaza el pedido', 'workshop' ), __( 'Usa el icono X si no tienes stock o no puedes atenderlo.', 'workshop' ) ),
                array( __( 'Despacha y completa', 'workshop' ), __( 'Marca como completado cuando entregues el pedido.', 'workshop' ) ),
                array( __( 'Cierra el día con el total', 'workshop' ), __( 'El acumulado de ventas te ayuda a cuadrar la jornada.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-toolbar', 'title' => __( 'Gestiona tus pedidos', 'workshop' ), 'text' => __( 'Barra de búsqueda y acciones para los pedidos de tus tiendas.', 'workshop' ), 'tip' => __( 'Acepta los pendientes cuanto antes: al aceptar se descuenta el stock automáticamente.', 'workshop' ) ),
                array( 'sel' => '.ws-stock-filters', 'title' => __( 'Filtra por estado', 'workshop' ), 'text' => __( 'Pendientes, aceptados, completados, rechazados o cancelados: enfócate en lo que toca ahora.', 'workshop' ), 'tip' => __( 'El badge de color de cada pedido te dice su estado de un vistazo.', 'workshop' ) ),
                array( 'sel' => '.ws-table', 'title' => __( 'Lista de pedidos', 'workshop' ), 'text' => __( 'Número, cliente, tienda, total y estado de cada pedido, con acciones por fila.', 'workshop' ), 'tip' => __( 'Acepta, despacha o completa desde los iconos sin cambiar de página.', 'workshop' ) ),
                array( 'sel' => '.ws-detail-meta', 'title' => __( 'Detalle del pedido', 'workshop' ), 'text' => __( 'Cliente, dirección, estado y el detalle de artículos y totales.', 'workshop' ), 'tip' => '' ),
            ),
        ),
        'shifts' => array(
            'icon'  => 'fa-calendar-days',
            'desc'  => __( 'Turnos y calendario de tu equipo.', 'workshop' ),
            'steps' => array(
                array( __( 'Elige la vista', 'workshop' ), __( 'Filtra el calendario por ubicación para ver solo el turno que te interesa.', 'workshop' ) ),
                array( __( 'Crea un turno', 'workshop' ), __( 'En el formulario elige ubicación, trabajador, fecha, hora de inicio y de fin.', 'workshop' ) ),
                array( __( 'Añade una nota', 'workshop' ), __( 'Comenta el turno si hace falta aclarar algo para el equipo.', 'workshop' ) ),
                array( __( 'Asigna a un trabajador', 'workshop' ), __( 'Elige quién cubre cada turno desde el desplegable.', 'workshop' ) ),
                array( __( 'Controla el mes', 'workshop' ), __( 'El calendario te muestra el mes completo de un vistazo: quién trabaja y cuándo.', 'workshop' ) ),
                array( __( 'Edita o elimina', 'workshop' ), __( 'Al abrir un turno puedes cambiarlo o eliminarlo cuando cambie el plan.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-calendar', 'title' => __( 'Calendario de turnos', 'workshop' ), 'text' => __( 'El mes completo de tu equipo de un vistazo: quién trabaja y cuándo.', 'workshop' ), 'tip' => __( 'Crea turnos y asígnalos a tus trabajadores desde el formulario superior.', 'workshop' ) ),
                array( 'sel' => '.ws-stock-filters', 'title' => __( 'Filtros', 'workshop' ), 'text' => __( 'Filtra por trabajador o ubicación para ver solo lo que te interesa.', 'workshop' ), 'tip' => '' ),
                array( 'sel' => '.ws-card', 'title' => __( 'Resumen', 'workshop' ), 'text' => __( 'La vista general de tus turnos y del equipo.', 'workshop' ), 'tip' => '' ),
            ),
        ),
        'workers' => array(
            'icon'  => 'fa-user-gear',
            'desc'  => __( 'Invita y gestiona a tu equipo con roles, ubicaciones y permisos.', 'workshop' ),
            'steps' => array(
                array( __( 'Invita a un trabajador', 'workshop' ), __( 'Pulsa «Nuevo trabajador» y crea su usuario con nombre, usuario, email y contraseña.', 'workshop' ) ),
                array( __( 'Asigna un rol', 'workshop' ), __( 'Elige almacenero, vendedor/PV o dueño según lo que deba hacer.', 'workshop' ) ),
                array( __( 'Dale ubicaciones', 'workshop' ), __( 'Marca en qué puntos de venta y almacenes podrá trabajar.', 'workshop' ) ),
                array( __( 'Actívalo', 'workshop' ), __( 'Una cuenta activa puede entrar a su panel; inactiva no (última sesión en 30 días cuenta como activa).', 'workshop' ) ),
                array( __( 'Cambia el rol en línea', 'workshop' ), __( 'Desde la fila puedes cambiar el rol con el desplegable sin abrir el formulario.', 'workshop' ) ),
                array( __( 'Gestiona sus ubicaciones', 'workshop' ), __( 'El icono de ubicación abre un modal para asignarlas de nuevo.', 'workshop' ) ),
                array( __( 'Revisa su actividad', 'workshop' ), __( 'Consulta el último acceso de cada trabajador.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-toolbar', 'title' => __( 'Invita a tu equipo', 'workshop' ), 'text' => __( 'Crea los usuarios de tus trabajadores con su rol: dueño, almacenero o vendedor.', 'workshop' ), 'tip' => __( 'Asigna el rol mínimo necesario: un vendedor no necesita permisos de configuración.', 'workshop' ) ),
                array( 'sel' => '.ws-table', 'title' => __( 'Lista del equipo', 'workshop' ), 'text' => __( 'Cada trabajador con su rol, estado y última actividad.', 'workshop' ), 'tip' => __( 'Desactiva la cuenta si alguien se va: conservas su historial.', 'workshop' ) ),
                array( 'sel' => '.ws-icon-btn', 'title' => __( 'Acciones por trabajador', 'workshop' ), 'text' => __( 'Edita, asigna ubicaciones o elimina desde los iconos de la fila.', 'workshop' ), 'tip' => '' ),
            ),
        ),
        'customers' => array(
            'icon'  => 'fa-users',
            'desc'  => __( 'Tu cartera de clientes con historial de compras.', 'workshop' ),
            'steps' => array(
                array( __( 'Añade un cliente', 'workshop' ), __( 'Pulsa «Nuevo cliente» y registra su nombre completo (obligatorio).', 'workshop' ) ),
                array( __( 'Completa su ficha', 'workshop' ), __( 'Email, teléfono, ciudad, provincia, código postal, dirección y notas.', 'workshop' ) ),
                array( __( 'Busca y filtra', 'workshop' ), __( 'Encuentra por nombre, email o teléfono, y filtra entre activos e inactivos.', 'workshop' ) ),
                array( __( 'Consulta sus compras', 'workshop' ), __( 'En la tabla ves su total de compras y sus puntos de fidelización.', 'workshop' ) ),
                array( __( 'Edita sus datos', 'workshop' ), __( 'Usa el icono de lápiz para corregir cualquier dato.', 'workshop' ) ),
                array( __( 'Elimina con cuidado', 'workshop' ), __( 'La papelera pide confirmación: no se puede deshacer.', 'workshop' ) ),
                array( __( 'Fideliza', 'workshop' ), __( 'Usa sus datos y puntos para ofrecer promociones y mejor atención.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-module-header', 'title' => __( 'Cartera de clientes', 'workshop' ), 'text' => __( 'Tus clientes con su historial: quiénes son, cuánto compran y desde cuándo.', 'workshop' ), 'tip' => __( 'Conocer a tus mejores clientes es el primer paso para fidelizarlos.', 'workshop' ) ),
                array( 'sel' => '.ws-search-box', 'title' => __( 'Búsqueda', 'workshop' ), 'text' => __( 'Encuentra un cliente por nombre o teléfono al instante.', 'workshop' ), 'tip' => '' ),
                array( 'sel' => '.ws-module-customers', 'title' => __( 'Lista de clientes', 'workshop' ), 'text' => __( 'Cada cliente con sus datos de contacto y compras.', 'workshop' ), 'tip' => __( 'Guarda siempre el teléfono: así avisas cuando llega su pedido.', 'workshop' ) ),
            ),
        ),
        'pos' => array(
            'icon'  => 'fa-cash-register',
            'desc'  => __( 'Punto de venta: atiende en mostrador y cobra al instante.', 'workshop' ),
            'steps' => array(
                array( __( 'Abre la caja', 'workshop' ), __( 'Antes de vender, pulsa el botón de caja y abre la caja con el monto inicial y una nota opcional.', 'workshop' ) ),
                array( __( 'Elige la ubicación', 'workshop' ), __( 'Con el selector superior cambias el punto de venta en el que estás atendiendo.', 'workshop' ) ),
                array( __( 'Busca o escanea', 'workshop' ), __( 'Escribe el nombre o pulsa Enter para escanear el código de barras del producto.', 'workshop' ) ),
                array( __( 'Toca el catálogo', 'workshop' ), __( 'También puedes añadir productos tocando su tarjeta; el stock se descuenta al confirmar.', 'workshop' ) ),
                array( __( 'Añade un cliente', 'workshop' ), __( 'Con el icono de usuario seleccionas un cliente guardado o vendes sin cliente.', 'workshop' ) ),
                array( __( 'Prepara el ticket', 'workshop' ), __( 'El carrito muestra cantidades (+/−), subtotal, descuento y total.', 'workshop' ) ),
                array( __( 'Elige el pago', 'workshop' ), __( 'Efectivo, tarjeta, transferencia o ambos. El vuelto se calcula solo.', 'workshop' ) ),
                array( __( 'Paga por transferencia', 'workshop' ), __( 'Si eliges transferencia, completa los datos del cliente (nombre, cédula, teléfono) y el nº de transferencia.', 'workshop' ) ),
                array( __( 'Pago mixto', 'workshop' ), __( 'En «Ambos» indica cuánto en efectivo y cuánto transferido; el sistema cuadra el total.', 'workshop' ) ),
                array( __( 'Completa la venta', 'workshop' ), __( 'Pulsa «Completar Venta»: se registra el ticket y se descuenta el stock.', 'workshop' ) ),
                array( __( 'Cierra la caja', 'workshop' ), __( 'Al final del día cierra la caja con el monto final; verás ventas, esperado, cuadre y diferencia.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-pos-cash-btn', 'title' => __( 'Apertura y cierre de caja', 'workshop' ), 'text' => __( 'Antes de vender debes abrir la caja con el fondo inicial. Al final del día la cierras con el arqueo.', 'workshop' ), 'tip' => __( 'Sin caja abierta no se permite vender: así controlas lo que entra y sale cada jornada.', 'workshop' ) ),
                array( 'sel' => '.ws-search-box', 'title' => __( 'Busca para vender', 'workshop' ), 'text' => __( 'Escribe o escanea el producto y se añade al ticket al instante.', 'workshop' ), 'tip' => '' ),
                array( 'sel' => '.ws-products-grid', 'title' => __( 'Catálogo del mostrador', 'workshop' ), 'text' => __( 'Toca un producto para añadirlo al ticket; verás su precio y stock de continuo.', 'workshop' ), 'tip' => __( 'El stock se descuenta al confirmar la venta.', 'workshop' ) ),
                array( 'sel' => '.ws-summary-row', 'title' => __( 'Tu ticket', 'workshop' ), 'text' => __( 'El resumen de la venta: productos, cantidades, descuento y total.', 'workshop' ), 'tip' => __( 'Revisa el total antes de cobrar: el cambio se calcula solo.', 'workshop' ) ),
                array( 'sel' => '.ws-pay-panel', 'title' => __( 'Cobro', 'workshop' ), 'text' => __( 'Elige el método de pago (efectivo, tarjeta, transferencia u otros) y completa los datos.', 'workshop' ), 'tip' => __( 'Al confirmar, la venta queda registrada y el stock actualizado.', 'workshop' ) ),
            ),
        ),
        'pos-sales' => array(
            'icon'  => 'fa-chart-line',
            'desc'  => __( 'Todas las ventas y el arqueo de caja de tu punto de venta.', 'workshop' ),
            'steps' => array(
                array( __( 'Conoce las dos pestañas', 'workshop' ), __( '"Ventas" lista los tickets; "Arqueo de caja" muestra cada apertura y cierre con su venta y diferencia.', 'workshop' ) ),
                array( __( 'Revisa los totales', 'workshop' ), __( 'Las tarjetas superiores resumen ventas totales, número de ventas y promedio.', 'workshop' ) ),
                array( __( 'Busca una venta', 'workshop' ), __( 'Filtrar por texto usa número, cliente o fecha.', 'workshop' ) ),
                array( __( 'Filtra por fecha', 'workshop' ), __( 'Elige «desde» y «hasta» para ver ventas de ayer, la semana o un período.', 'workshop' ) ),
                array( __( 'Filtra por estado', 'workshop' ), __( 'Completadas, pendientes o canceladas.', 'workshop' ) ),
                array( __( 'Explora la tabla', 'workshop' ), __( 'ID, fecha, cliente, vendedor, método de pago, total y estado de cada venta.', 'workshop' ) ),
                array( __( 'Abre el detalle', 'workshop' ), __( 'El ojo muestra los productos, el cobro (efectivo/transferencia) y el método de pago.', 'workshop' ) ),
                array( __( 'Imprime el ticket', 'workshop' ), __( 'El icono de impresora imprime la venta desde el navegador.', 'workshop' ) ),
                array( __( 'Exporta a CSV', 'workshop' ), __( 'El botón «Exportar» descarga las ventas filtradas en un archivo Excel-compatible.', 'workshop' ) ),
                array( __( 'Cierra el arqueo', 'workshop' ), __( 'En «Arqueo de caja» revisa fondo inicial, ventas, esperado, cuadrado y la diferencia de cada jornada.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-stats-grid', 'title' => __( 'Ventas del día', 'workshop' ), 'text' => __( 'Totales, número de tickets y el promedio de tu punto de venta.', 'workshop' ), 'tip' => __( 'Compara este total con la caja al cierre para cuadrar el día.', 'workshop' ) ),
                array( 'sel' => '.ws-tabs', 'title' => __( 'Ventas y arqueo de caja', 'workshop' ), 'text' => __( 'La pestaña «Ventas» lista los tickets; «Arqueo de caja» muestra cada apertura y cierre con su venta, esperado y diferencia.', 'workshop' ), 'tip' => __( 'Revisa el arqueo diario para detectar faltantes o sobrantes de efectivo.', 'workshop' ) ),
                array( 'sel' => '.ws-search-box', 'title' => __( 'Buscar ventas', 'workshop' ), 'text' => __( 'Encuentra un ticket por número, cliente o fecha.', 'workshop' ), 'tip' => '' ),
                array( 'sel' => '.ws-info-row', 'title' => __( 'Detalle del ticket', 'workshop' ), 'text' => __( 'Cada venta muestra sus productos, el cobro, el método de pago y los datos del cliente.', 'workshop' ), 'tip' => '' ),
            ),
        ),
        'reviews' => array(
            'icon'  => 'fa-star',
            'desc'  => __( 'Valoraciones de tus clientes en el marketplace.', 'workshop' ),
            'steps' => array(
                array( __( 'Revisa tu reputación', 'workshop' ), __( 'Las tarjetas muestran el promedio general, las aprobadas y las pendientes.', 'workshop' ) ),
                array( __( 'Busca reseñas', 'workshop' ), __( 'Encuentra reseñas por texto con el buscador.', 'workshop' ) ),
                array( __( 'Filtra por estado', 'workshop' ), __( 'Aprobadas, pendientes o rechazadas.', 'workshop' ) ),
                array( __( 'Filtra por estrellas', 'workshop' ), __( 'Mira solo las de 5, 4, 3, 2 o 1 estrella.', 'workshop' ) ),
                array( __( 'Lee la valoración', 'workshop' ), __( 'Cada tarjeta muestra el producto, el autor, la puntuación y el comentario.', 'workshop' ) ),
                array( __( 'Modera', 'workshop' ), __( 'Aprueba o rechaza las pendientes para que aparezcan o no en tu tienda.', 'workshop' ) ),
                array( __( 'Elimina si hace falta', 'workshop' ), __( 'La papelera borra una reseña con confirmación.', 'workshop' ) ),
                array( __( 'Responde', 'workshop' ), __( 'Estate pendiente de las críticas: responder suma confianza ante tus clientes.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-stats-grid', 'title' => __( 'Tu reputación', 'workshop' ), 'text' => __( 'Puntuación media y número de valoraciones de tus clientes en el mercado.', 'workshop' ), 'tip' => __( 'Más valoraciones con buena nota = más confianza y más ventas.', 'workshop' ) ),
                array( 'sel' => '.ws-reviews-list', 'title' => __( 'Valoraciones', 'workshop' ), 'text' => __( 'Lee qué opinan de tu negocio y de tus productos.', 'workshop' ), 'tip' => __( 'Responde a las críticas: se nota y suma puntos.', 'workshop' ) ),
                array( 'sel' => '.ws-search-box', 'title' => __( 'Buscar reseñas', 'workshop' ), 'text' => __( 'Filtra las valoraciones por producto o cliente.', 'workshop' ), 'tip' => '' ),
            ),
        ),
        'loyalty' => array(
            'icon'  => 'fa-gift',
            'desc'  => __( 'Programa de fidelización con puntos para tus clientes.', 'workshop' ),
            'steps' => array(
                array( __( 'Revisa el programa', 'workshop' ), __( 'Las tarjetas muestran puntos emitidos, canjeados y clientes activos.', 'workshop' ) ),
                array( __( 'Configura el programa', 'workshop' ), __( 'Pulsa «Configurar» para definir puntos por € gastado, valor de 1 punto y los niveles.', 'workshop' ) ),
                array( __( 'Define los niveles', 'workshop' ), __( 'Indica cuántos puntos se necesitan para cada nivel: plata, oro…', 'workshop' ) ),
                array( __( 'Busca y ordena', 'workshop' ), __( 'Encuentra clientes y ordénalos por más/menos puntos o por nombre.', 'workshop' ) ),
                array( __( 'Consulta la tabla', 'workshop' ), __( 'Cliente, puntos, nivel, total gastado y última actividad.', 'workshop' ) ),
                array( __( 'Ajusta puntos', 'workshop' ), __( 'Con el icono +/− corrige puntos de un cliente y guarda el motivo.', 'workshop' ) ),
                array( __( 'Revisa el historial', 'workshop' ), __( 'El icono de historia muestra cada ganado, canjeado o manual de puntos con su motivo.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-stat-card', 'title' => __( 'Tu programa', 'workshop' ), 'text' => __( 'Puntos entregados, canjeados y clientes activos de tu fidelización.', 'workshop' ), 'tip' => '' ),
                array( 'sel' => '.ws-points-badge', 'title' => __( 'Regla de puntos', 'workshop' ), 'text' => __( 'Define cuántos puntos gana el cliente por cada compra.', 'workshop' ), 'tip' => __( 'Empieza simple: 1 punto por unidad y un premio fácil de alcanzar.', 'workshop' ) ),
                array( 'sel' => '.ws-form-group', 'title' => __( 'Configuración', 'workshop' ), 'text' => __( 'Recompensas, niveles y canje del programa.', 'workshop' ), 'tip' => __( 'La configuración se muestra al abrir el formulario del programa.', 'workshop' ) ),
            ),
        ),
        'plan' => array(
            'icon'  => 'fa-crown',
            'desc'  => __( 'Tu plan actual, los límites y cómo hacer upgrade.', 'workshop' ),
            'steps' => array(
                array( __( 'Revisa tu plan', 'workshop' ), __( 'Mira el plan actual, los días restantes y su estado (precio, duración, estado).', 'workshop' ) ),
                array( __( 'Controla tu uso', 'workshop' ), __( 'Las barras te muestran cuánto has usado de cada límite: productos, tiendas, trabajadores…', 'workshop' ) ),
                array( __( 'Detecta límites al 80%', 'workshop' ), __( 'Si una barra se acerca al tope, el sistema te lo marca para que actúes a tiempo.', 'workshop' ) ),
                array( __( 'Elige otro plan', 'workshop' ), __( 'Pulsa «Solicitar» en el plan que te convenga.', 'workshop' ) ),
                array( __( 'Gestiona tu solicitud', 'workshop' ), __( 'Si ya tienes una upgrade pendiente, puedes cancelarla desde el aviso.', 'workshop' ) ),
                array( __( 'Lee los avisos', 'workshop' ), __( 'El sistema te informa de estados: aprobado, pendiente, rechazado o bloqueado.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-plan-current-card', 'title' => __( 'Tu plan actual', 'workshop' ), 'text' => __( 'Plan, días restantes y estado de tu suscripción.', 'workshop' ), 'tip' => __( 'Mientras estés en prueba, tu negocio sigue visible en el mercado.', 'workshop' ) ),
                array( 'sel' => '.ws-plan-page', 'title' => __( 'Límites y uso', 'workshop' ), 'text' => __( 'Las barras muestran cuánto usas de cada límite: productos, tiendas, trabajadores.', 'workshop' ), 'tip' => __( 'Si tocas un límite, sube de plan para no frenar tu negocio.', 'workshop' ) ),
                array( 'sel' => '.ws-card', 'title' => __( 'Cambio de plan', 'workshop' ), 'text' => __( 'Desde aquí solicitas un upgrade o un cambio de plan.', 'workshop' ), 'tip' => '' ),
            ),
        ),
        'permissions' => array(
            'icon'  => 'fa-shield-halved',
            'desc'  => __( 'Define qué puede hacer cada rol en tu negocio.', 'workshop' ),
            'steps' => array(
                array( __( 'Entiende la matriz', 'workshop' ), __( 'Cada fila es una capacidad (ver, crear, editar, borrar… por módulo) y cada columna es un rol.', 'workshop' ) ),
                array( __( 'Mira las columnas', 'workshop' ), __( 'Dueño, almacenero y vendedor: cada rol tiene sus propios interruptores.', 'workshop' ) ),
                array( __( 'Activa o desactiva', 'workshop' ), __( 'Usa los interruptores para permitir o bloquear cada acción por rol.', 'workshop' ) ),
                array( __( 'Aplica el mínimo privilegio', 'workshop' ), __( 'Da a cada rol solo lo que necesita hacer su trabajo diario.', 'workshop' ) ),
                array( __( 'Guarda los cambios', 'workshop' ), __( 'Pulsa «Guardar» para aplicar los permisos al instante a tu equipo.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-toolbar', 'title' => __( 'Permisos por rol', 'workshop' ), 'text' => __( 'Selecciona el rol (dueño, almacenero o vendedor) y revisa sus capacidades.', 'workshop' ), 'tip' => __( 'Principio de mínimo privilegio: da solo lo que cada rol necesita.', 'workshop' ) ),
                array( 'sel' => '.ws-card', 'title' => __( 'Matriz de capacidades', 'workshop' ), 'text' => __( 'Activa o desactiva ver, crear, editar y borrar por módulo.', 'workshop' ), 'tip' => __( 'Los cambios se aplican al instante a tu equipo.', 'workshop' ) ),
                array( 'sel' => '.ws-center', 'title' => __( 'Guarda', 'workshop' ), 'text' => __( 'Confirma los cambios para aplicarlos de inmediato.', 'workshop' ), 'tip' => '' ),
            ),
        ),
        'reports' => array(
            'icon'  => 'fa-chart-pie',
            'desc'  => __( 'Reportes y datos para decidir con información.', 'workshop' ),
            'steps' => array(
                array( __( 'Filtra por ubicación', 'workshop' ), __( 'Elige «Todas las ubicaciones» o una en concreto para ver sus datos.', 'workshop' ) ),
                array( __( 'Elige el período', 'workshop' ), __( 'Últimos 7, 14, 30 o 90 días, o todo el historial.', 'workshop' ) ),
                array( __( 'Revisa el resumen', 'workshop' ), __( 'Los KPI muestran ventas, pedidos y movimientos del período y la ubicación elegida.', 'workshop' ) ),
                array( __( 'Ventas por día', 'workshop' ), __( 'La tabla muestra el total vendido y el número de pedidos de cada día.', 'workshop' ) ),
                array( __( 'Movimientos por tipo', 'workshop' ), __( 'Ve cuántos movimientos entradas, salidas u otros hay y su cantidad.', 'workshop' ) ),
                array( __( 'Top productos', 'workshop' ), __( 'Los 10 productos más vendidos por unidades, transacciones y total.', 'workshop' ) ),
                array( __( 'Exporta a Excel', 'workshop' ), __( 'El botón «Exportar Excel» descarga un libro con resumen, ventas por día, más/menos vendidos, todos los productos y movimientos.', 'workshop' ) ),
                array( __( 'Ordena las tablas', 'workshop' ), __( 'Las columnas son ordenables para analizar mejor.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-kpis', 'title' => __( 'Resumen ejecutivo', 'workshop' ), 'text' => __( 'Los totales clave de tu negocio en un solo lugar.', 'workshop' ), 'tip' => '' ),
                array( 'sel' => '.ws-card', 'title' => __( 'Reportes', 'workshop' ), 'text' => __( 'Cada tarjeta es un reporte distinto: ventas, stock, movimientos…', 'workshop' ), 'tip' => __( 'Elige el reporte y ajusta el período para ver la evolución.', 'workshop' ) ),
                array( 'sel' => '.ws-table', 'title' => __( 'Detalle del reporte', 'workshop' ), 'text' => __( 'Los datos desglosados para decidir con información.', 'workshop' ), 'tip' => '' ),
            ),
        ),
        'appearance' => array(
            'icon'  => 'fa-palette',
            'desc'  => __( 'La imagen de tu negocio: logo, colores, portada y pie.', 'workshop' ),
            'steps' => array(
                array( __( 'Abre la vista previa', 'workshop' ), __( 'Activa «Vista previa en vivo» para ver los cambios al instante mientras escribes.', 'workshop' ) ),
                array( __( 'Sube tu logo', 'workshop' ), __( 'Pegar la URL del logo: aparece en tu tienda, mercado y panel.', 'workshop' ) ),
                array( __( 'Añade el favicon', 'workshop' ), __( 'El pequeño icono que se muestra en la pestaña del navegador.', 'workshop' ) ),
                array( __( 'Elige tus colores', 'workshop' ), __( 'Define el color principal y el de acento con el selector o escribiéndoles el código hex.', 'workshop' ) ),
                array( __( 'Configura la portada', 'workshop' ), __( 'Etiqueta, título, subtítulo, imagen de fondo y gradiente del hero.', 'workshop' ) ),
                array( __( 'Ajusta el pie de página', 'workshop' ), __( 'Escribe la descripción que se verá al final de tu tienda.', 'workshop' ) ),
                array( __( 'Guarda o restablece', 'workshop' ), __( 'Usa «Guardar» para publicar tus cambios, o «Restablecer» para volver a los valores por defecto.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-toolbar', 'title' => __( 'Personaliza tu negocio', 'workshop' ), 'text' => __( 'Logo, colores y textos de tu marca.', 'workshop' ), 'tip' => __( 'El logo aparece en tu tienda, en el mercado y en el panel.', 'workshop' ) ),
                array( 'sel' => '.ws-card', 'title' => __( 'Logo y colores', 'workshop' ), 'text' => __( 'Sube tu logo y define el color principal y el de acento.', 'workshop' ), 'tip' => __( 'Usa los colores de tu marca: generan confianza y recuerdo.', 'workshop' ) ),
                array( 'sel' => '.ws-pv-hero', 'title' => __( 'Vista previa en vivo', 'workshop' ), 'text' => __( 'Así quedará tu tienda mientras la configuras.', 'workshop' ), 'tip' => __( 'El hero y la portada son lo primero que ve el cliente: cuídalos.', 'workshop' ) ),
            ),
        ),
        'settings' => array(
            'icon'  => 'fa-gear',
            'desc'  => __( 'Monedas, tasas, WhatsApp y métodos de pago de tu negocio.', 'workshop' ),
            'steps' => array(
                array( __( 'Configura las monedas', 'workshop' ), __( 'Escribe las monedas separadas por coma y elige la moneda por defecto.', 'workshop' ) ),
                array( __( 'Ajusta las tasas de cambio', 'workshop' ), __( 'Define cuánto vale cada moneda frente a la base con alta precisión.', 'workshop' ) ),
                array( __( 'Actualiza con El Toque', 'workshop' ), __( 'El botón consulta la tasa de cambio automáticamente para mantenerla al día.', 'workshop' ) ),
                array( __( 'Configura WhatsApp', 'workshop' ), __( 'Escribe los números (separados por coma) donde recibirás pedidos.', 'workshop' ) ),
                array( __( 'Elige cómo cobras', 'workshop' ), __( 'Activa o desactiva efectivo, tarjeta, transferencia, pago móvil, cheque u otro.', 'workshop' ) ),
                array( __( 'Guarda los cambios', 'workshop' ), __( 'Cada tarjeta tiene su propio botón «Guardar» que aplica los cambios.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-card', 'title' => __( 'Datos del negocio', 'workshop' ), 'text' => __( 'Nombre, contacto y configuración básica de tu negocio.', 'workshop' ), 'tip' => __( 'Mantén los datos al día: se muestran en tu tienda.', 'workshop' ) ),
                array( 'sel' => '.ws-form', 'title' => __( 'Monedas y tasas', 'workshop' ), 'text' => __( 'Configura tus monedas y actualiza la tasa con El Toque.', 'workshop' ), 'tip' => __( 'Elige bien la moneda por defecto: afecta a precios y pedidos.', 'workshop' ) ),
                array( 'sel' => '.ws-check-group', 'title' => __( 'Opciones', 'workshop' ), 'text' => __( 'Activa o desactiva los métodos de pago de tu negocio.', 'workshop' ), 'tip' => '' ),
            ),
        ),
        'account' => array(
            'icon'  => 'fa-user',
            'desc'  => __( 'Tu perfil de usuario y seguridad.', 'workshop' ),
            'steps' => array(
                array( __( 'Revisa tus datos', 'workshop' ), __( 'Tu usuario no se puede cambiar; el nombre mostrado y el email sí.', 'workshop' ) ),
                array( __( 'Edita tu perfil', 'workshop' ), __( 'Cambia tu nombre mostrado y tu email en la tarjeta «Mis datos».', 'workshop' ) ),
                array( __( 'Cambia tu contraseña', 'workshop' ), __( 'Indica la actual, escribe la nueva (mín. 8 caracteres) y repítela.', 'workshop' ) ),
                array( __( 'Verifica tu rol', 'workshop' ), __( 'La tarjeta informativa muestra tu rol y tu último acceso.', 'workshop' ) ),
                array( __( 'Mantén tu sesión segura', 'workshop' ), __( 'Usa una contraseña larga y única, y cierra sesión en equipos compartidos.', 'workshop' ) ),
            ),
            'tour' => array(
                array( 'sel' => '.ws-card', 'title' => __( 'Tu perfil', 'workshop' ), 'text' => __( 'Tu nombre, email e información de contacto.', 'workshop' ), 'tip' => '' ),
                array( 'sel' => '.ws-form', 'title' => __( 'Seguridad', 'workshop' ), 'text' => __( 'Cambia tu contraseña cuando quieras desde aquí.', 'workshop' ), 'tip' => __( 'Usa una contraseña larga y única, y no la compartas.', 'workshop' ) ),
                array( 'sel' => '.ws-field', 'title' => __( 'Sesión', 'workshop' ), 'text' => __( 'Mantén tu sesión segura y cierra sesión en equipos compartidos.', 'workshop' ), 'tip' => '' ),
            ),
        ),
    );
}