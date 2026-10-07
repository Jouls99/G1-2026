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

Cuando MySQL está disponible, consulta `actividad_usuario`, agrega el rol del usuario, interpreta `detalles` como JSON si puede y devuelve estadísticas globales. La respuesta exitosa contiene:

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

Si la consulta falla, combina `data/actividad_ventas.json`, `data/actividad_usuarios.json` y `data/actividades.json`, ordena por fecha y devuelve `fuente: "json"`. El filtro de tipo y las estadísticas del respaldo se calculan sobre esos registros locales.

## `POST`: registrar evento

Ruta: `POST api/actividades.php`

Acepta un cuerpo JSON opcional:

| Campo | Uso |
| --- | --- |
| `usuario` | Nombre asociado al evento; si no se envía, usa el usuario de sesión o `Sistema`. |
| `tipo` | Tipo de acción; valor predeterminado `accion_general`. |
| `descripcion` | Descripción; valor predeterminado `Actividad registrada`. |
| `detalles` | Objeto JSON opcional. |

Invoca `logActivity()`, que intenta insertar el registro en MySQL y escribe además el respaldo JSON correspondiente. Devuelve `ok` y un mensaje de confirmación.

## Códigos y notas

- `403`: usuario sin rol administrativo al consultar por `GET`.
- `405`: método no soportado.
- Las amenazas se leen de `data/amenazas.json`; su evaluación se realiza al registrar ciertos eventos de inicio de sesión fallido.

Relacionado: [helpers.md](./helpers.md), [auth.md](./auth.md).
