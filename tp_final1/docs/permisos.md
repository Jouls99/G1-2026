# API de permisos de vendedores (`api/permisos.php`)

Permite a administradores consultar y modificar los permisos delegables de cuentas con rol Vendedor. Todas las operaciones requieren rol Administrador o Super Administrador. Los permisos se leen y guardan en la tabla `usuario` de MySQL.

## `GET`: listar vendedores

`GET api/permisos.php`

Devuelve:

```json
{
  "ok": true,
  "usuarios": [
    {
      "id": 1,
      "usuario": "vendedor",
      "puede_registrar_stock": false,
      "puede_modificar_informes": false
    }
  ]
}
```

Consulta los vendedores y sus permisos en MySQL.

## `PUT`: actualizar permisos

`PUT api/permisos.php`

El cuerpo JSON debe incluir `id`, `password` (la contraseña de inicio de sesión del administrador) y al menos uno de estos campos:

- `puede_registrar_stock`
- `puede_modificar_informes`

Los valores se convierten a booleanos. Solo se permiten esos nombres de permiso. El sistema valida la contraseña del administrador actual mediante `password_verify` antes de aplicar cualquier modificación. El usuario objetivo debe ser un vendedor; si se encuentra y la contraseña es válida, actualiza MySQL y registra `permisos_vendedor_actualizados` con el administrador que hizo el cambio. Si la contraseña no coincide, se deniega la acción y se registra el intento fallido.

Errores:

- `400`: ID inválido, falta la contraseña o no se incluyó ningún permiso.
- `401`: contraseña de administrador incorrecta o sesión no válida.
- `404`: el usuario no existe o no es vendedor.
- `503`: base de datos no disponible.
- `403`: el solicitante no tiene rol administrativo.
- `405`: método no soportado.

Relacionado: [helpers.md](./helpers.md), [users.md](./users.md).
