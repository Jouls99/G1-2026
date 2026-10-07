# API de actividades (`api/actividades.php`)

Endpoint de consulta de auditoría y registro de eventos. Usa las funciones comunes de `api/helpers.php` y devuelve respuestas JSON mediante `sendJson()`.

## Acceso

- `GET`: requiere sesión de Administrador o Super Administrador.
- `POST`: no aplica una comprobación explícita de sesión ni de rol en este archivo. Acepta el usuario y los datos de actividad enviados por el cliente; no debe considerarse un endpoint público confiable sin protección adicional.
- `OPTIONS`: responde al preflight CORS a través de `handleOptions()`.

## `GET`: consultar auditoría

Ruta: `GET api/actividades.php`

Parámetros opcionales:

| Parámetro | Descripción |
| --- | --- |
| `usuario` | Filtra por nombre de usuario. Los valores vacíos o `todos` no filtran. |
| `tipo` | Filtra por tipo de acción. Reconoce `venta`, `login`, `stock`, `roles`/`rol`; cualquier otro valor se compara como tipo exacto. `todas` no filtra. |
| `limit` | Cantidad máxima de eventos. Predeterminado: 200; rango efectivo: 1–500. |

Consulta `actividad_usuario` y `amenaza` en MySQL, agrega el rol del usuario e interpreta `detalles` como JSON si puede. Para Administradores, el servidor excluye de la lista y de las métricas todas las actividades atribuibles a cuentas Super Admin; los eventos de vendedores y demás usuarios siguen visibles. El Super Administrador conserva acceso a toda la actividad. La respuesta exitosa contiene:

```json
{
  "ok": true,
  "actividades": [],
  "amenazas": [],
  "stats": {
    "total": 0,
    "ventas": 0,
    "logins": 0,
    "logins_hoy": 0,
    "intentos_fallidos": 0,
    "stock": 0,
    "roles": 0
  },
  "fuente": "mysql"
}
```

Si no se puede conectar a MySQL, responde `503 database_unavailable`; si falla la consulta, informa `500`. No lee archivos JSON como alternativa.

## `POST`: registrar evento

Ruta: `POST api/actividades.php`

Acepta un cuerpo JSON opcional:

| Campo | Uso |
| --- | --- |
| `usuario` | Nombre asociado al evento; si no se envía, usa el usuario de sesión o `Sistema`. |
| `tipo` | Tipo de acción; valor predeterminado `accion_general`. |
| `descripcion` | Descripción; valor predeterminado `Actividad registrada`. |
| `detalles` | Objeto JSON opcional. |

Invoca `logActivity()`, que inserta el registro en `actividad_usuario`. Devuelve `ok` y un mensaje de confirmación.

## Códigos y notas

- `403`: usuario sin rol administrativo al consultar por `GET`.
- `503`: base de datos no disponible.
- `405`: método no soportado.
- Las alertas se leen de `amenaza`; la detección se realiza al registrar ciertos eventos de inicio de sesión fallido.
- Los archivos históricos de actividad se importan una sola vez a `actividad_usuario`.

Relacionado: [helpers.md](./helpers.md), [auth.md](./auth.md).
