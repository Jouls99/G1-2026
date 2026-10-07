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

El cuerpo JSON debe incluir `id` y al menos uno de estos campos:

- `puede_registrar_stock`
- `puede_modificar_informes`

Los valores se convierten a booleanos. Solo se permiten esos nombres de permiso. El usuario objetivo debe ser un vendedor; si se encuentra, actualiza MySQL y registra `permisos_vendedor_actualizados` con el administrador que hizo el cambio.

Errores:

- `400`: ID inválido o no se incluyó ningún permiso.
- `404`: el usuario no existe o no es vendedor.
- `503`: base de datos no disponible.
- `403`: el solicitante no tiene rol administrativo.
- `405`: método no soportado.

Relacionado: [helpers.md](./helpers.md), [users.md](./users.md).
