# Funcionamiento del código

Esta guía describe cómo se organiza y ejecuta el código de `tp_final1`, limitado a los archivos PHP principales de la raíz y a los archivos de `api/`, `scrits/` e `includes/`.

**Fuera de alcance:** `node_modules/`, `database/`, archivos de datos, hojas de estilo, documentación en PDF e imágenes. Se los menciona solo cuando hace falta explicar una dependencia o un flujo. La ruta `scrits/` conserva la ortografía que tiene el proyecto.

## 1. Vista general del flujo

1. El navegador solicita una página PHP de la raíz. Esta página comprueba la sesión y, cuando corresponde, permisos o rol.
2. Las plantillas de `includes/` abren el HTML común, muestran la navegación y cierran el documento.
3. El JavaScript de `scrits/` conecta los controles de la pantalla con las API PHP mediante `fetch`.
4. Los endpoints de `api/` validan la sesión y autorización, procesan la acción, persisten la información y contestan JSON.
5. Las operaciones que registran actividad llaman a los helpers de `api/helpers.php`; para las funciones con respaldo, este intenta mantener registros en MySQL y en archivos JSON.

La ruta PHP principal para las páginas es `venta.php`; `index.php` envía allí a las sesiones activas y al formulario de acceso a quienes no han iniciado sesión.

## 2. Archivos principales de la raíz

| Archivo | Funcionamiento |
| --- | --- |
| `index.php` | Punto de entrada. Carga la lógica de sesión y redirige a `venta.php` si el usuario ya inició sesión; si no, a `registroinicio.php`. |
| `registroinicio.php` | Presenta los formularios de inicio de sesión y registro. Evita mostrar el registro si ya hay una sesión y carga `scrits/login.js` para procesarlos con la API. |
| `venta.php` | Protege la pantalla con `requireAuth`, intenta precargar desde MySQL los productos habilitados y, si hay un error, lee `data/inventario.json`. Genera la interfaz de caja y carga `scrits/preuba2.js`, responsable de la venta interactiva. Si el usuario es Super Administrador, muestra el indicador del estado de conexión a la base. |
| `ControlStock.php` | Requiere sesión y calcula los permisos que utiliza la pantalla. Renderiza los controles de inventario, filtros, categorías, ficha de producto, modal de baja y, para Super Administradores, auditoría de estados. Expone indicadores de permisos a JavaScript y carga `scrits/panelGestión.js`. |
| `informe.php` | Requiere sesión, calcula si se permite editar informes y pasa ese dato y el rol de administrador al navegador. Define los contenedores del dashboard y de los modales de cambio de usuario/edición de venta; carga `scrits/dashboard-informe.js`. |
| `Auditoria.php` | Limita la página al Super Administrador. Define paneles de sesiones, actividad y señales de acceso; su interfaz se alimenta de `api/actividades.php` a través de `scrits/auditoria.js`. |
| `usuarios.php` | Consola de administración exclusiva del Super Administrador. Define métricas, pestañas, tablas y modales de usuarios, logins y actividades, que `scrits/usuarios.js` completa con datos de API. |
| `permisos_vendedores.php` | Requiere Administrador o Super Administrador. Presenta los permisos delegables de vendedores y carga `scrits/permisos.js`. |
| `gestión_usuarios.php` | Verifica sesión y rol Super Administrador y redirige a `usuarios.php`; funciona como ruta de compatibilidad hacia la consola. |
| `logout.php` | Si existe una sesión, registra el cierre mediante `logActivity`; luego elimina los datos y cookie de sesión con `logoutUser` y redirige al login. |

### Flujo de una venta

1. `venta.php` carga inicialmente productos habilitados para completar las opciones del formulario.
2. `scrits/preuba2.js` pide la lista actualizada a `api/inventario.php`, completa nombre/código/precio/stock y mantiene en memoria los productos de la venta actual.
3. Al confirmar, envía el conjunto de productos y el total como JSON mediante `POST api/ventas.php`.
4. `api/ventas.php` valida la sesión y el contenido, registra las líneas de facturación y ajusta cantidades; registra también la actividad de venta y devuelve un resultado JSON.
5. El JavaScript muestra el resultado y emite eventos de actualización para que las pantallas que observan inventario o ventas vuelvan a consultar los datos.

## 3. API (`api/`)

Todos los endpoints PHP cargan helpers comunes y responden JSON. Según el endpoint, la autenticación proviene de la sesión PHP y la autorización se comprueba en el servidor; ocultar botones en la interfaz no sustituye estas comprobaciones.

| Archivo | Métodos y comportamiento |
| --- | --- |
| `api/helpers.php` | Biblioteca compartida, no una pantalla de API independiente. Inicia/usa la sesión, normaliza respuestas JSON y solicitudes HTTP, lee cuerpos JSON o formularios, resuelve rutas de archivos de respaldo, implementa comprobaciones de sesión/roles/permisos, lee y escribe JSON con bloqueos, y centraliza el registro de actividad y la detección de señales de acceso. |
| `api/auth.php` | La acción `check` devuelve si hay usuario en sesión. `POST?action=login` valida credenciales consultando MySQL y luego el respaldo de usuarios, crea la sesión, actualiza el último acceso cuando es posible y registra éxitos o fallos. `POST?action=register` crea únicamente cuentas de vendedor e inicia su sesión. `action=logout` registra y destruye la sesión. |
| `api/inventario.php` | `GET` entrega los productos, usando MySQL y guardando una copia en JSON; si falla la consulta, usa el respaldo. La acción `GET?action=audit` devuelve cambios de estado para Super Administradores. `POST` crea productos o permite rehabilitarlos según rol. `PUT` actualiza el inventario para administradores. `DELETE?codigo=...` deshabilita un producto conservando el historial en vez de borrar la fila. Las operaciones relevantes generan actividad. |
| `api/ventas.php` | Requiere sesión. `GET` lista ventas desde MySQL o utiliza `ventas.json` cuando corresponde. `POST` valida y registra una nueva venta, actualiza inventario y mantiene el respaldo. `PUT` permite las modificaciones autorizadas desde el informe y sincroniza datos de venta/inventario; `DELETE?id=...` elimina una venta y está limitado a administradores. |
| `api/actividades.php` | `GET` está reservado a Super Administradores. Acepta filtros opcionales de usuario y tipo y un límite entre 1 y 500; entrega eventos, métricas y alertas desde MySQL o combina archivos JSON como alternativa. `POST` registra un evento recibido mediante `logActivity`. |
| `api/users.php` | Requiere Super Administrador. `GET` lista cuentas y acepta `action=detail` para consultar el detalle de una persona. `POST`, `PUT` y `DELETE` crean, actualizan roles/datos y eliminan usuarios, respectivamente. Las operaciones validan duplicados y restricciones de roles, y registran acciones administrativas. |
| `api/permisos.php` | Requiere Administrador o Super Administrador. `GET` lista vendedores con sus permisos delegables. `PUT` recibe un identificador y los permisos a cambiar, actualiza la persistencia disponible y registra quién hizo el cambio. |

Los métodos, parámetros y códigos de respuesta exactos deben confirmarse en cada endpoint antes de integrarlo desde otro cliente; esta tabla resume las rutas que consume la interfaz actual.

## 4. JavaScript de interfaz (`scrits/`)

| Archivo | Funcionamiento |
| --- | --- |
| `scrits/login.js` | Abre/cierra visualmente el formulario de registro, valida que los campos estén completos y que ambas contraseñas coincidan, y llama a `api/auth.php` con las acciones `login` y `register`. En caso exitoso redirige a la aplicación. |
| `scrits/preuba2.js` | Gestiona la venta en curso: consulta inventario, autocompleta los datos de producto, valida existencia y stock disponible, actualiza tabla y total, cancela el borrador y envía la venta final a `api/ventas.php`. Al terminar, notifica cambios de inventario y ventas. |
| `scrits/panelGestión.js` | Construye la interfaz de inventario: carga productos, los agrupa por categoría, permite filtrarlos, ver detalles, editar, agregar, exportar JSON y eliminar/deshabilitar con confirmación. Utiliza `api/inventario.php`, atiende permisos expuestos por la página y sincroniza recargas con el evento `inventario-updated`. Incluye consulta de auditoría de estados para Super Administradores. Si falla la carga, intenta recuperar una copia de `localStorage`. |
| `scrits/dashboard-informe.js` | Carga ventas e inventario y genera los selectores de categoría/período, métricas, gráfico SVG, listas y tablas de resumen e historial. Implementa el modal para cambiar de cuenta mediante autenticación y los flujos de edición/eliminación de ventas con las API. Escucha eventos de almacenamiento y `inventario-updated` para refrescar el dashboard. Si no logra obtener inventario, utiliza un conjunto de datos local de demostración. |
| `scrits/auditoria.js` | Consulta hasta 500 registros a `api/actividades.php`, calcula qué eventos se muestran como sesiones o actividad, filtra por texto y presenta métricas y señales. También maneja pestañas, actualización manual, estado del servicio y escape de texto insertado en la tabla. |
| `scrits/usuarios.js` | Controla la consola Super Administrador: carga la lista de cuentas y métricas; busca/filtra usuarios; cambia roles; consulta eventos y detalle individual; abre formularios para crear/editar y permite borrar. Consume `api/users.php` y `api/actividades.php`, y actualiza la pantalla con mensajes y notificaciones. |
| `scrits/permisos.js` | Carga vendedores desde `api/permisos.php`, dibuja casillas para los permisos de stock e informes, y envía los cambios seleccionados con `PUT`. Presenta el resultado y escapa los valores dinámicos al construir filas. |
| `scrits/server.js` | Servidor auxiliar independiente en Node/Express. Expone rutas REST de ejemplo que leen o escriben `users.json`, `inventario.json` y `ventas.json`, sirve archivos estáticos y escucha en el puerto `PORT` o `3000`. No es el runtime de los endpoints PHP: las pantallas PHP documentadas consumen `api/*.php`. |

### Comunicación entre JavaScript y PHP

- La mayoría de llamadas usa rutas relativas como `api/inventario.php`, así que el navegador las resuelve bajo la misma instalación.
- Las operaciones JSON envían `Content-Type: application/json`; los endpoints leen el cuerpo mediante `getJsonBody()`.
- Las respuestas se interpretan como JSON. El cliente usa el estado HTTP y campos como `ok`/`message` para mostrar éxito o error.
- El estado de autenticación y los roles efectivos están en la sesión de PHP. Los valores `window.*` que las páginas pasan a los scripts son indicadores para representar controles; las API vuelven a validar las operaciones protegidas.

## 5. Componentes reutilizables (`includes/`)

| Archivo | Funcionamiento |
| --- | --- |
| `includes/auth.php` | Inicia la sesión si aún no existe y agrupa funciones de autenticación/autorización para páginas: leer el usuario, saber si inició sesión, redirigir, reconocer roles, verificar permisos delegables y proteger vistas. `logoutUser()` limpia la sesión, invalida la cookie y destruye la sesión PHP. Para permisos delegables consulta primero la fuente persistente y contempla el respaldo disponible. |
| `includes/db.php` | Adaptador mínimo para cargar la conexión compartida desde la configuración de la carpeta `database/`. |
| `includes/header.php` | Abre el documento HTML en español, inicia la sesión si hace falta, define título por defecto, incluye la hoja base y opcionalmente la hoja indicada por `$customCss`. |
| `includes/navbar.php` | Presenta navegación común, resalta `$activePage`, muestra usuario y rol escapados, y enseña opciones según los roles de sesión. |
| `includes/footer.php` | Cierra el documento HTML y muestra el pie con el año actual. |

## 6. Ciclo de autenticación y permisos

1. `registroinicio.php` entrega los formularios y `scrits/login.js` envía las credenciales a `api/auth.php`.
2. Si las credenciales son correctas, la API almacena `id`, nombre, rol y hora de inicio en `$_SESSION['user']`.
3. Las páginas privadas incluyen `includes/auth.php` y llaman `requireAuth`, `requireAdmin` o `requireSuperAdmin`.
4. Las API emplean equivalentes del lado servidor en `api/helpers.php`, y algunas consultan los permisos delegables actuales.
5. `logout.php` registra la salida y limpia la sesión antes de volver a la pantalla de acceso.

## 7. Consideraciones al modificar

- Mantener sincronizados el nombre de la página, los identificadores HTML que espera su JavaScript y la ruta del script cargado.
- Al añadir una operación protegida, validar rol/permisos en el endpoint además de adaptar la interfaz.
- Si se modifica una respuesta JSON, actualizar tanto el endpoint como todos los scripts que la consumen.
- Las páginas cargan ciertos datos para representarlos inicialmente, pero las operaciones posteriores usan la API. Evitar asumir que el HTML precargado es la fuente de verdad.
- El cliente y los endpoints incluyen rutas de respaldo con comportamientos distintos; probar el flujo con MySQL disponible y, cuando aplique, con la alternativa JSON/local.
- `index.php` y algunos manejadores cliente todavía contienen redirecciones a `prueba2.php`; la página de ventas presente en la raíz se llama `venta.php`. Tener en cuenta esta diferencia al revisar rutas de navegación o errores de autorización.
