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

1. Busca el usuario en MySQL y verifica el hash de la contraseña con `password_verify()`. Las contraseñas se guardan usando Argon2id con sal automática y parámetros de coste del servidor.
2. Si el hash almacenado es antiguo (por ejemplo, bcrypt), una autenticación correcta lo actualiza automáticamente a Argon2id; la contraseña no cambia y no se requiere migración masiva.
3. Al autenticar, crea `$_SESSION['user']` y actualiza el último acceso en MySQL.
4. Registra `login_exitoso` o `login_fallido` mediante `logActivity()`. El evento contiene IP y agente de usuario; el fallo incluye también el motivo.
5. Después de cuatro contraseñas incorrectas consecutivas para el mismo usuario, bloquea nuevos intentos durante 10 segundos. Un inicio correcto reinicia el contador. La restricción también se aplica a nombres de usuario que no existen.
Si ya hay una sesión activa, no es posible iniciar sesión con otra cuenta sin antes cerrar la sesión actual (`400 already_logged_in`).

Respuestas relevantes:

- `400 missing_fields`: falta usuario o contraseña.
- `401 invalid_credentials`: las credenciales no coinciden.
- `429 login_temporarily_locked`: se alcanzaron cuatro intentos fallidos y el acceso está bloqueado temporalmente; `retry_after` indica los segundos restantes.
- `200`: autenticación exitosa y objeto `user`.

El alta de cuentas se realiza mediante `POST api/users.php`, restringido a Administradores y Super Administradores. El endpoint recibe `usuario` y `role`, genera una contraseña aleatoria de 10 a 34 caracteres sin repetir caracteres, incluyendo minúsculas, mayúsculas, números y símbolos, guarda únicamente su hash Argon2id y devuelve `temporary_password` solo después de confirmar la creación. La generación evita palabras intencionales, pero no puede garantizar que ninguna subcadena coincida con una palabra en todos los idiomas. Las contraseñas se regeneran en el servidor y no se pueden proporcionar manualmente. El hash se actualiza al autenticarse cuando los parámetros vigentes cambian. La contraseña no se vuelve a consultar desde la API.

### `logout`

`POST api/auth.php?action=logout`

Un usuario autenticado cierra su propia sesión por `POST`; las solicitudes mutables de la API y el cierre web verifican que `Origin` o `Referer` coincida con el origen de la aplicación. Las solicitudes sin origen verificable o con origen externo se rechazan. La acción registra `logout`, marca la sesión como finalizada, limpia la sesión PHP, invalida la cookie y la destruye. Desde las 22:30 de Argentina, si esa era la última sesión activa del día, el sistema copia las ventas de la jornada a `ventas_historial`, luego borra las filas archivadas de `ventas` en lotes transaccionales de hasta 200 y poda semanas fuera del límite de cuatro. Si el cierre falla, la sesión se cierra igualmente y se informa el error. El cambio de usuario sigue siendo una opción exclusiva del Super Administrador en la interfaz.

Las cookies de sesión usan `HttpOnly`, `SameSite=Lax`, modo estricto y solo cookies. Por HTTPS se habilita `Secure` automáticamente; detrás de un proxy TLS, configurar `SESSION_COOKIE_SECURE=1` y `APP_ORIGIN` con el origen público exacto (por ejemplo, `https://sistema.ejemplo.com`).

Las peticiones protegidas verifican en MySQL que la cuenta siga habilitada, que su nombre y rol coincidan con la sesión y que esta continúe activa en `sesion_activa`. Al editar, cambiar el rol, regenerar la contraseña o deshabilitar una cuenta, se revocan sus sesiones registradas; la siguiente petición protegida las invalida y pide volver a iniciar sesión. Las páginas autenticadas también cargan el control de sesión de `includes/footer.php`. Si el navegador informa que la página fue congelada (`freeze`) o permanece oculta durante al menos 30 segundos, se envía el cierre a `logout.php` y se redirige al formulario de acceso. Esto permite cerrar la sesión cuando el dispositivo se suspende; cambiar brevemente de pestaña no la cierra.

## Errores

- `400 invalid_action`: acción no reconocida.
- El cierre de sesión por API requiere una sesión activa.
- `500 day_close_failed`: la sesión se cerró, pero falló el archivado de jornada.

Relacionado: [helpers.md](./helpers.md), [actividades.md](./actividades.md).
