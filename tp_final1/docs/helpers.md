# Funciones compartidas de API (`api/helpers.php`)

Biblioteca cargada por los endpoints de `api/`. Inicia la sesión PHP cuando todavía no está activa e incluye `database/conexion.php`. No es un endpoint independiente.

## Solicitudes y respuestas

| Función | Responsabilidad |
| --- | --- |
| `sendJson($data, $status = 200)` | Define el estado HTTP y encabezados JSON/CORS, serializa la respuesta y termina la ejecución. |
| `handleOptions()` | Responde a solicitudes `OPTIONS` con JSON para el preflight CORS. |
| `getJsonBody()` | Lee un objeto/array JSON del cuerpo; si no puede, intenta usar `$_POST`; devuelve `null` si no hay cuerpo interpretable. |
| `requestMethod()` | Devuelve el método HTTP en mayúsculas. |
| `dataPath($filename)` | Resuelve una ruta dentro de `tp_final1/data/`. |

Los encabezados CORS configurados por `sendJson()` permiten origen `*` y los métodos `GET`, `POST`, `PUT`, `DELETE` y `OPTIONS`.

## Persistencia JSON

- `readJsonFile($path)` lee bajo bloqueo compartido. Si el archivo no existe, no se puede leer, está vacío o su contenido no decodifica a un array, devuelve `[]`.
- `writeJsonFile($path, $data)` codifica JSON legible, crea el directorio si hace falta y escribe con bloqueo exclusivo.
- `appendJsonRecord($filename, $record, $limit = 1000)` lee el array, agrega el registro, conserva como máximo los últimos 1000 elementos y persiste el resultado.

Archivos usados por los módulos: `inventario.json`, `ventas.json`, `users.json`, `actividades.json`, `actividad_ventas.json`, `actividad_usuarios.json` y `amenazas.json`.

## Sesión, roles y permisos

| Función | Comportamiento |
| --- | --- |
| `getApiUser()` | Devuelve `$_SESSION['user']` o `null`. |
| `requireApiAuth()` | Termina con respuesta `401` si no hay sesión. |
| `isAdminApi()` / `isSuperAdminApi()` | Comprueban los roles administrativos; el Super Administrador cuenta también como administrador. |
| `requireAdminApi()` / `requireSuperAdminApi()` | Aplican la comprobación de rol y terminan con respuesta `403` si falla. |
| `hasUserPermissionApi($permission)` | Permite consultar `puede_registrar_stock` o `puede_modificar_informes` en MySQL, `users.json` o la sesión como alternativa. |
| `canRegisterStockApi()` / `requireStockRegistrationApi()` | Autorizan la carga de productos a administradores y vendedores con el permiso delegable. |
| `canModifyReportsApi()` | Autoriza la modificación de informes a administradores y usuarios con permiso delegable. |

## Registro de actividad y señales

`logActivity($usuario, $tipo, $descripcion, $detalles = null, $idUsuario = null)` intenta insertar el evento en `actividad_usuario` y luego guarda un respaldo en JSON:

- `venta_registrada` → `actividad_ventas.json`.
- `login_exitoso`, `login_fallido` y `logout` → `actividad_usuarios.json`.
- Otros tipos → `actividades.json`.

Los eventos `login_fallido` también llaman a `logThreatIfDetected()`. Esta función analiza los últimos 15 minutos de `actividad_usuarios.json` y genera una alerta si hay al menos cinco fallos para una misma combinación de cuenta/IP, o al menos diez fallos desde una IP contra tres o más cuentas distintas. `appendThreatOnce()` evita duplicar la misma regla/clave durante esa ventana y conserva hasta 1000 alertas en `amenazas.json`.

## Dependencias y uso

- La conexión PDO se obtiene desde `database/conexion.php` mediante `getDBConnection()`.
- Los endpoints deben aplicar sus propias reglas de autenticación/autorización; incluir este archivo por sí solo no protege una ruta.
- El retorno booleano de escritura permite conocer el resultado de escritura JSON, pero algunos llamadores de la aplicación no lo verifican.

Relacionado: [actividades.md](./actividades.md), [auth.md](./auth.md), [inventario.md](./inventario.md), [ventas.md](./ventas.md), [users.md](./users.md), [permisos.md](./permisos.md).
