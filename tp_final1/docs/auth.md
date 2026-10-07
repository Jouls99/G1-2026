# API de autenticación (`api/auth.php`)

Gestiona la consulta de sesión, el inicio y cierre de sesión. No existe registro público: las cuentas son creadas por un administrador desde Gestión de Usuarios y el sistema genera una contraseña temporal que se muestra una sola vez.

## Acceso y formato

El endpoint inicia sesión PHP al cargar `api/helpers.php`, procesa `OPTIONS` y responde JSON. No requiere sesión previa para `check` ni `login`.

El cuerpo de `POST` se lee como JSON; si no es JSON válido, el helper también admite datos de formulario.

## Acciones

### `check`

`GET api/auth.php?action=check` devuelve:

```json
{ "ok": true, "loggedIn": false, "user": null }
```

Con sesión activa, `loggedIn` es `true` y `user` contiene el identificador, el nombre, el rol y la fecha de inicio de sesión.

### `login`

`POST api/auth.php?action=login`

Campos requeridos: `usuario` y `password`.

1. Busca el usuario en MySQL y verifica la contraseña con `password_verify()`.
2. Al autenticar, crea `$_SESSION['user']` y actualiza el último acceso en MySQL.
3. Registra `login_exitoso` o `login_fallido` mediante `logActivity()`. El evento contiene IP y agente de usuario; el fallo incluye también el motivo.

Si ya hay una sesión activa, solo el Super Administrador puede usar esta acción para cambiar a otra cuenta. Las sesiones de vendedores y administradores reciben `403 forbidden`.

Respuestas relevantes:

- `400 missing_fields`: falta usuario o contraseña.
- `401 invalid_credentials`: las credenciales no coinciden.
- `200`: autenticación exitosa y objeto `user`.

El alta de cuentas se realiza mediante `POST api/users.php`, restringido a Administradores y Super Administradores. El endpoint recibe `usuario` y `role`, genera una contraseña aleatoria, guarda únicamente su hash y devuelve `temporary_password` solo después de confirmar la creación. La contraseña no se vuelve a consultar desde la API.

### `logout`

`GET` o `POST api/auth.php?action=logout`

Cualquier usuario autenticado puede cerrar su propia sesión. La acción registra `logout`, marca la sesión como finalizada, limpia la sesión PHP, invalida la cookie y la destruye. Desde las 22:30 de Argentina, si esa era la última sesión activa del día, el sistema copia las ventas de la jornada a `ventas_historial`, luego borra las filas archivadas de `ventas` en lotes transaccionales de hasta 200 y poda semanas fuera del límite de cuatro. Si el cierre falla, la sesión se cierra igualmente y se informa el error. El cambio de usuario sigue siendo una opción exclusiva del Super Administrador en la interfaz.

## Errores

- `400 invalid_action`: acción no reconocida.
- El cierre de sesión por API requiere una sesión activa.
- `500 day_close_failed`: la sesión se cerró, pero falló el archivado de jornada.

Relacionado: [helpers.md](./helpers.md), [actividades.md](./actividades.md).
