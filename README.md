<div align="center">

# ShopUp — MultiTienda

**Marketplace multi-negocio sobre WordPress** · Paneles por rol · POS · Stock · Chatbot con IA · PWA offline

Plataforma donde cada negocio tiene su propia tienda en línea con panel de gestión completo,
mientras el administrador del sistema controla toda la plataforma desde `wp-admin`.

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat&logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-6.5%2B-21759b?style=flat&logo=wordpress&logoColor=white)
![Alpine.js](https://img.shields.io/badge/Alpine.js-8BC0EA?style=flat&logo=alpinedotjs&logoColor=white)
![PWA](https://img.shields.io/badge/PWA-offline-5C0DAC?style=flat&logo=pwa&logoColor=white)
![Playwright](https://img.shields.io/badge/tests-Playwright-2EAD33?style=flat&logo=playwright&logoColor=white)
![License](https://img.shields.io/badge/license-GPLv2%2B-blue?style=flat)

</div>

---

## Índice

- [¿Qué es?](#qué-es)
- [Roles](#roles)
- [Características](#características)
- [Stack tecnológico](#stack-tecnológico)
- [Estructura del tema](#estructura-del-tema)
- [Instalación local](#instalación-local)
- [Pruebas (E2E)](#pruebas-e2e)
- [Despliegue a producción](#despliegue-a-producción)
- [Licencia](#licencia)

---

## ¿Qué es?

**ShopUp** es un marketplace multi-negocio construido como tema de WordPress. Cada negocio tiene:

- Una **tienda pública** (`/mi-tienda/`) con su propia portada, catálogo y pedidos.
- Un **panel de gestión** (`/panel/…`) con módulos según el rol.
- Su propio esquema de tablas en la base de datos (aislamiento por negocio).

El **administrador del sistema** gestiona la plataforma completa desde `wp-admin`:
negocios, usuarios, planes, suscripciones, contenidos del sitio, SMTP, logs y más.

---

## Roles

| Rol | Acceso | Qué puede hacer |
|---|---|---|
| **Administrador del sistema** | `wp-admin` | Gestiona toda la plataforma: negocios, usuarios, planes, suscripciones, páginas, asistente, SMTP, permisos y logs. No vende ni gestiona negocios. |
| **Dueño del negocio** | `Panel` | Administra su tienda completa: productos, proveedores, ubicaciones, stock, pedidos, POS, caja, gastos y utilidades, turnos, trabajadores, permisos, reseñas, clientes, lealtad y anuncios. |
| **Almacenero** | `Panel` | Gestiona inventario: productos, proveedores, ubicaciones, stock y movimientos. |
| **Vendedor / Punto de Venta** | `Panel` | POS, caja, pedidos y consulta de stock en tiempo real. |

Los permisos se asignan por rol **y** por negocio (`ws_capabilities`), y el panel se
construye en función de los módulos permitidos de cada usuario.

---

## Características

### Market & tiendas
- Portada con hero, contadores en vivo y carrusel de tiendas destacadas.
- Directorio de tiendas con búsqueda y **filtros por categoría**.
- Tienda pública por negocio con catálogo, reseñas y pedidos.
- Pedidos en tiempo real con notificaciones.

### Panel de negocio
- **Productos** con foto, precios, stock y **categorías en árbol** (tab dentro de Productos).
- **Proveedores**, **ubicaciones** y **stock con movimientos**.
- **POS** (punto de venta) con carrito, caja y ventas.
- **Gastos y utilidad mensual** por ubicación (ingresos − gastos).
- **Pedidos**, **clientes (CRM)**, **reseñas** con anti-duplicados y **lealtad/recompensas**.
- **Turnos**, **trabajadores** con **sesiones de trabajo** (check-in / check-out) y **permisos**.
- **Anuncios**: mensajes y notificaciones ancladas para los usuarios del negocio; el admin puede emitir **anuncios globales del sitio** visibles en todos los paneles y portadas.

### Planes y suscripciones
- Planes con características configurables desde `wp-admin`.
- Landing de cada negocio con las características de su plan.
- Bloqueo del chatbot por plan (sin LLM ni consultas si el plan no lo incluye).

### Chatbot con IA
- Asistente con **identidad del usuario**: sabe quién es, su rol, negocio y módulos permitidos.
- **Datos en vivo** del sitio (nombre, hero, contadores) y del negocio (stock, precios).
- Busca productos y tiendas con **filtros por categoría, precio y stock**, y devuelve fichas de producto.
- Atajos y guías por página, según rol (incluye modo **administrador del sistema** en `wp-admin`).

### App nativa (Android)
- APK descargable desde la web (badge en el footer) y desde el asistente del panel.
- La app guarda su **cola de sincronización offline** (pedidos y ventas no se pierden); el soporte server (`ws_offline_sync`) queda en el backend.
- La web ya no es PWA: no hay Service Worker, IndexedDB ni `manifest.json`.

---

## Stack tecnológico

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.0+, WordPress 6.5+, MySQL/MariaDB |
| Frontend | HTML, CSS, **Alpine.js**, JavaScript vanilla |
| Tiempo real | Polling + endpoints AJAX (`wp-admin/admin-ajax.php`) |
| App móvil | APK Android con cola de sincronización offline (`ws_offline_sync`) |
| Tests | Playwright (E2E) |
| CI/CD | GitHub Actions → FTP (InfinityFree) |

---

## Estructura del tema

```
wp-content/themes/workshop/
├── functions.php            # Bootstrap del tema (WS_VERSION, WS_DB_VERSION, rutas)
├── inc/                     # Lógica de negocio por módulo
│   ├── db.php               # Esquema de BD + migraciones (tablas por negocio)
│   ├── ajax.php             # Endpoints AJAX del panel y del front
│   ├── router.php           # Enrutado de páginas del panel
│   ├── roles.php            # Roles de negocio y capacidades
│   ├── capabilities.php     # Permisos por rol y por negocio
│   ├── business.php         # Negocios, ubicaciones, landing
│   ├── products|stock|orders|pos|expenses|categories|reports|shifts|sessions.php
│   ├── chatbot.php          # Asistente con contexto del usuario y datos en vivo
│   ├── announcements.php    # Anuncios del negocio y globales del sitio
│   ├── notifications.php    # Notificaciones y badges
│   └── plans.php            # Planes y suscripciones
├── templates/               # Front (market, tienda, login, registro, landing)
│   ├── marketplace.php      # Portada del mercado
│   ├── store.php            # Tienda pública de un negocio
│   ├── panel.php            # Shell del panel (sidebar + banner de anuncios)
│   └── panel/               # Módulos del panel (dashboard, products, pos, …)
├── assets/                  # CSS y JS del tema
└── style.css                # Cabecera del tema
```

---

## Instalación local

Requisitos: **XAMPP** (Apache + MySQL), **PHP 8** y WordPress 6.5+.

1. Clona el repositorio dentro de `htdocs/`:
   ```bash
   git clone https://github.com/Sampere012/shopup.git
   ```
2. Instala WordPress y activa el tema **Workshop MultiTienda** en `wp-admin > Apariencia`.
3. Asegúrate de que el esquema de base de datos se instale (la primera carga del front
   dispara `ws_lazy_install`). Los permisos de escritura de Apache deben permitir crear tablas.
4. Accede a `/wp-admin/` para gestionar la plataforma.

> Las tablas se crean **por negocio** con el prefijo `wp_ws_{sufijo}_` y se migran
> automáticamente con `WS_DB_VERSION`.

---

## Pruebas (E2E)

Suite de Playwright en `tests/e2e/` (Chromium, un worker, base local `http://localhost/workshop/`).

```bash
npm install
$env:WS_E2E_PHP = "C:\xampp\php\php.exe"   # PHP con mysqli (no el del PATH)
npx playwright test
```

Los tests usan `tests/e2e/helpers.js` y el helper de línea de comandos `ws-e2e-helper.php`
para sembrar usuarios, negocios, planes y limpiar datos (usuarios `ws_e2e_*`).

```bash
php ws-e2e-helper.php seed-users
php ws-e2e-helper.php create-biz <slug> "Nombre" <owner> <pass>
php ws-e2e-helper.php cleanup-biz <slug>
```

---

## Despliegue a producción

El repositorio incluye el workflow [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml)
que despliega automáticamente a producción al hacer `push` a `main`:

1. Sube `wp-content/` por FTP (InfinityFree) con `FTP-Deploy-Action`.
2. Sube los archivos raíz (`.htaccess` y páginas de error). El `sw.js` y el `manifest.json` ya no se despliegan (la web dejó de ser PWA; la app nativa se descarga como APK).
3. Dispara la migración de URLs de producción.

**Secrets requeridos:** `FTP_HOST`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_REMOTE_DIR`.

```bash
git add .
git commit -m "tu cambio"
git push origin main
```

---

## Licencia

**GPL v2 or later** · Tema basado en WordPress, por lo que se distribuye bajo la licencia
General Public License de WordPress.