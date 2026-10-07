# Funciones compartidas de API (`api/helpers.php`)

Biblioteca cargada por los endpoints de `api/`. Inicia la sesión PHP cuando todavía no está activa e incluye `database/conexion.php`. No es un endpoint independiente.

## Solicitudes y respuestas

| Función | Responsabilidad |
| --- | --- |
| `sendJson($data, $status = 200)` | Define el estado HTTP y encabezados JSON/CORS, serializa la respuesta y termina la ejecución. |
| `handleOptions()` | Responde a solicitudes `OPTIONS` con JSON para el preflight CORS. |
| `getJsonBody()` | Lee un objeto/array JSON del cuerpo; si no puede, intenta usar `$_POST`; devuelve `null` si no hay cuerpo interpretable. |
| `requestMethod()` | Devuelve el método HTTP en mayúsculas. |
Los encabezados CORS configurados por `sendJson()` permiten origen `*` y los métodos `GET`, `POST`, `PUT`, `DELETE` y `OPTIONS`.

La salida JSON se usa únicamente para la comunicación HTTP. Los datos persistentes se consultan y modifican en MySQL.

## Sesión, roles y permisos

| Función | Comportamiento |
| --- | --- |
| `getApiUser()` | Devuelve `$_SESSION['user']` o `null`. |
| `requireApiAuth()` | Termina con respuesta `401` si no hay sesión. |
| `isAdminApi()` / `isSuperAdminApi()` | Comprueban los roles administrativos; el Super Administrador cuenta también como administrador. |
| `requireAdminApi()` / `requireSuperAdminApi()` | Aplican la comprobación de rol y terminan con respuesta `403` si falla. |
| `hasUserPermissionApi($permission)` | Consulta `puede_registrar_stock` o `puede_modificar_informes` en MySQL. |
| `canRegisterStockApi()` / `requireStockRegistrationApi()` | Autorizan la carga de productos a administradores y vendedores con el permiso delegable. |
| `canModifyReportsApi()` | Autoriza la modificación de informes a administradores y usuarios con permiso delegable. |

## Registro de actividad y señales

`logActivity($usuario, $tipo, $descripcion, $detalles = null, $idUsuario = null)` inserta el evento en `actividad_usuario`. Los eventos `login_fallido` también llaman a `logThreatIfDetected()`, que consulta los últimos 15 minutos en MySQL y guarda alertas en `amenaza`: cinco fallos para la misma combinación de cuenta/IP, o diez fallos desde una IP contra tres o más cuentas distintas. `appendThreatOnce()` evita duplicar la misma regla/clave durante esa ventana.

`includes/security_monitor.php` inspecciona de forma pasiva los parámetros de URL, formularios, cuerpos JSON y algunos encabezados de solicitudes a páginas PHP y APIs en busca de patrones comunes de inyección SQL, XSS, ejecución de comandos, traversal y ejecución de PHP. Las solicitudes sospechosas generan una alerta `request_code` en `amenaza`, visible en Auditoría. Se guardan la categoría detectada, la ruta PHP, el método y la IP; el contenido enviado no se almacena ni se bloquea. Es un detector heurístico y no reemplaza validación de entradas, consultas preparadas ni un WAF.

## Dependencias y uso

- La conexión PDO se obtiene desde `database/conexion.php` mediante `getDBConnection()`.
- Los endpoints deben aplicar sus propias reglas de autenticación/autorización; incluir este archivo por sí solo no protege una ruta.
- `requireApiDatabase()` devuelve la conexión PDO o responde `503 database_unavailable`; las rutas no continúan en modo de respaldo.

Relacionado: [actividades.md](./actividades.md), [auth.md](./auth.md), [inventario.md](./inventario.md), [ventas.md](./ventas.md), [users.md](./users.md), [permisos.md](./permisos.md).
