# API de administración de usuarios (`api/users.php`)

Endpoint para listar cuentas, consultar el detalle de una cuenta, crear usuarios administrativos/vendedores, actualizar datos o rol y eliminar usuarios. La gestión de cuentas está disponible para Administradores y Super Administradores; el detalle con historial de logins sigue reservado al Super Administrador. `data/users.json` se utiliza como respaldo o se sincroniza desde las mutaciones.

## `GET`: listado y detalle

`GET api/users.php` devuelve una lista de usuarios con `id`, `usuario`, `role`, fecha de creación, total de ventas, facturación y actividades. Solo el Super Administrador recibe además fechas y métricas de acceso. Los valores analíticos del respaldo JSON se informan como cero.

`GET api/users.php?action=detail&usuario=NOMBRE` está reservado al Super Administrador y devuelve el usuario, hasta 100 ventas, hasta 100 actividades y hasta 50 eventos de inicio/registro, además de las métricas calculadas. Si falta `usuario`, responde `400`; si no existe, `404`.

Las consultas usan MySQL. La lista general tiene alternativa desde `users.json`, pero el detalle depende de la consulta MySQL y devuelve `500` si falla.

## `POST`: crear usuario

`POST api/users.php`

Cuerpo JSON:

```json
{ "usuario": "nombre", "password": "contraseña", "role": "vendedor" }
```

Requiere nombre y contraseña. Los roles válidos son `vendedor`, `administrador` y `superadmin`; un rol no reconocido se convierte en `vendedor`. Se comprueba nombre duplicado y solo puede existir una cuenta de cada rol administrativo protegido. La contraseña se guarda con `password_hash()`. Crea también respaldo en `users.json` y registra `usuario_creado`.

## `PUT`: modificar cuenta

`PUT api/users.php`

Identifica al usuario mediante `id` o `usuario`. Puede incluir `usuario` nuevo, `role` nuevo y/o `password` nueva. La contraseña se actualiza si no está vacía. Los nombres de roles admiten alias y se normalizan a `superadmin`, `administrador` o `vendedor`. No permite asignar un rol administrativo único a otro usuario si ya está ocupado. Tampoco permite que el único Super Administrador se quite ese rol a sí mismo. El endpoint requiere sesión de Administrador o Super Administrador.

Sin cambios devuelve confirmación sin modificar. Los cambios se sincronizan con JSON y registran `rol_cambiado`, `password_restablecida` o `usuario_modificado`, según el caso.

## `DELETE`: eliminar cuenta

`DELETE api/users.php?id=ID`

También acepta `id` en el cuerpo JSON. Impide que el Administrador o Super Administrador elimine su propia cuenta activa. Si existe el usuario, lo elimina de MySQL y del respaldo JSON, y registra `usuario_eliminado`.

## Errores comunes

- `400`: faltan campos/identificador o parámetros requeridos.
- `403`: no autorizado, o intento de autoeliminación/remoción del único Super Administrador.
- `404`: usuario no encontrado.
- `409`: nombre duplicado o límite de rol administrativo alcanzado.
- `500`: error de persistencia/consulta.
- `405`: método no soportado.

Relacionado: [helpers.md](./helpers.md), [permisos.md](./permisos.md), [actividades.md](./actividades.md).
