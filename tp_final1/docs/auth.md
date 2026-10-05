# API de autenticación (`api/auth.php`)

Gestiona la consulta de sesión, el inicio de sesión, el registro público de vendedores y el cierre de sesión. Las credenciales se consultan primero en MySQL y, si hace falta, en `data/users.json`.

## Acceso y formato

El endpoint inicia sesión PHP al cargar `api/helpers.php`, procesa `OPTIONS` y responde JSON. No requiere sesión previa para `check`, `login` ni `register`.

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
2. Si no valida en MySQL, busca en `data/users.json`. El respaldo admite hashes y, para datos heredados, contraseñas en texto plano; cuando se encuentra una contraseña plana, intenta reemplazarla por un hash.
3. Al autenticar, crea `$_SESSION['user']`, actualiza el último acceso cuando MySQL está disponible y sincroniza `lastLogin` en el archivo JSON.
4. Registra `login_exitoso` o `login_fallido` mediante `logActivity()`. El evento contiene IP y agente de usuario; el fallo incluye también el motivo.

Respuestas relevantes:

- `400 missing_fields`: falta usuario o contraseña.
- `401 invalid_credentials`: las credenciales no coinciden.
- `200`: autenticación exitosa y objeto `user`.

### `register`

`POST api/auth.php?action=register`

Campos requeridos: `usuario` y `password`. El rol se fija en `vendedor`; el cliente no puede escoger un rol administrativo desde esta acción. Comprueba duplicados, intenta crear la cuenta en MySQL y guarda un respaldo en `data/users.json`. Al completar el registro, inicia sesión automáticamente y registra `registro_usuario`.

- `400 missing_fields`: faltan campos.
- `409 user_exists`: el nombre ya está registrado.
- `200`: usuario registrado y sesión iniciada.

### `logout`

`GET` o `POST api/auth.php?action=logout`

Registra `logout` si corresponde, limpia la sesión PHP, invalida la cookie de sesión y la destruye. Devuelve `ok: true` al finalizar.

## Errores

- `400 invalid_action`: acción no reconocida.
- El endpoint no convierte el cierre de sesión en un error si no había usuario activo.

Relacionado: [helpers.md](./helpers.md), [actividades.md](./actividades.md).
