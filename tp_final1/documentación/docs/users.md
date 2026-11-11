# API de administración de usuarios (`api/users.php`)

Endpoint para listar cuentas, consultar el detalle de una cuenta, crear usuarios administrativos/vendedores, actualizar datos o rol y deshabilitar usuarios. No hay registro público: un administrador crea las cuentas y comparte con el usuario la contraseña aleatoria que la API muestra una sola vez tras el alta. La gestión de cuentas está disponible para Administradores y Super Administradores; el detalle con historial de logins sigue reservado al Super Administrador. Los administradores solo ven su propia cuenta y las de vendedores; la cuenta del Super Administrador queda excluida del listado y tampoco puede consultarse ni modificarse mediante peticiones directas. Solo el Super Administrador puede asignar el rol Super Admin. Los usuarios se guardan únicamente en MySQL y las cuentas deshabilitadas conservan su actividad.

## `GET`: listado y detalle

`GET api/users.php` devuelve una lista de usuarios habilitados con `id`, `usuario`, `role`, fecha de creación, total de ventas, facturación y actividades. El administrador recibe únicamente su cuenta y las de vendedores; el Super Administrador recibe todas las cuentas habilitadas. Solo el Super Administrador recibe además fechas y métricas de acceso.

`GET api/users.php?action=disabled_audit` devuelve las cuentas deshabilitadas, quién las deshabilitó, cuándo ocurrió y su actividad histórica conservada para la auditoría de usuarios.

`GET api/users.php?action=detail&usuario=NOMBRE` está reservado al Super Administrador y devuelve el usuario, hasta 100 ventas, hasta 100 actividades y hasta 50 eventos de inicio/registro, además de las métricas calculadas. Si falta `usuario`, responde `400`; si no existe, `404`.

Las consultas usan MySQL y devuelven un error si la base de datos no está disponible.

## `POST`: crear usuario

`POST api/users.php`

Cuerpo JSON:

```json
{ "usuario": "nombre", "role": "vendedor" }
```

Requiere nombre y rol. Los roles válidos son `vendedor`, `administrador` y `superadmin`; un rol no reconocido responde `400 invalid_role`. Solo el Super Administrador puede asignar el rol `superadmin`. Se comprueba nombre duplicado y solo puede existir una cuenta de cada rol administrativo protegido. El servidor genera una contraseña aleatoria de 10 a 34 caracteres con `random_int()`. Todos los caracteres son únicos; incluye minúsculas, mayúsculas, números y símbolos, por lo que tampoco se repiten bloques de caracteres. No es posible garantizar que ninguna subcadena forme una palabra en todos los idiomas. Guarda únicamente su hash Argon2id, con sal automática, y responde con `temporary_password` después de confirmar la cuenta y registrar `usuario_creado`. Si se incluye una contraseña escrita en la petición, responde `400 generated_password_only`. La contraseña no se almacena ni se puede recuperar posteriormente.

## `PUT`: modificar cuenta

`PUT api/users.php`

Identifica al usuario mediante `id` o `usuario`. Puede incluir `usuario` nuevo o `role` nuevo. Los nombres de roles admiten alias y se normalizan a `superadmin`, `administrador` o `vendedor`. No permite asignar un rol administrativo único a otro usuario si ya está ocupado. Tampoco permite que el único Super Administrador se quite ese rol a sí mismo. El endpoint requiere sesión de Administrador o Super Administrador. No acepta contraseñas proporcionadas por el cliente.

`{ "id": 123, "action": "regenerar_password" }` genera una nueva contraseña aleatoria de 10 a 34 caracteres únicos en el servidor, actualiza el hash Argon2id inmediatamente y la devuelve en `temporary_password` una sola vez. Los cambios se guardan en MySQL y registran `rol_cambiado`, `password_restablecida` o `usuario_modificado`, según el caso. Las cuentas con hashes bcrypt u otros hashes reconocidos se migran automáticamente a Argon2id después de una verificación correcta.
Sin cambios devuelve confirmación sin modificar. `{ "id": 123, "action": "regenerar_password" }` genera una nueva contraseña aleatoria de 10 a 34 caracteres únicos en el servidor, actualiza el hash Argon2id inmediatamente y la devuelve en `temporary_password` una sola vez. Los cambios se guardan en MySQL y registran `rol_cambiado`, `password_restablecida` o `usuario_modificado`, según el caso. Las cuentas con hashes bcrypt u otros hashes reconocidos se migran automáticamente a Argon2id después de una verificación correcta.

## `DELETE`: deshabilitar cuenta

`DELETE api/users.php?id=ID`

También acepta `id` en el cuerpo JSON. Impide que el Administrador o Super Administrador deshabilite su propia cuenta activa. Si existe el usuario, actualiza su estado a `deshabilitado`, revoca sus sesiones activas, guarda el responsable y la fecha, conserva la cuenta y toda su actividad, y registra `usuario_deshabilitado`. La cuenta deja de aparecer en el listado activo y no puede iniciar sesión. Los cambios de nombre, rol o contraseña también revocan las sesiones de la cuenta afectada.

## Errores comunes

- `400`: faltan campos/identificador o parámetros requeridos.
- `403`: no autorizado, o intento de autoeliminación/remoción del único Super Administrador.
- `404`: usuario no encontrado.
- `409`: nombre duplicado o límite de rol administrativo alcanzado.
- `500`: error de persistencia/consulta.
- `503`: base de datos no disponible.
- `405`: método no soportado.

Relacionado: [helpers.md](./helpers.md), [permisos.md](./permisos.md), [actividades.md](./actividades.md).
