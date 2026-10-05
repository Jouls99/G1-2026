# API de permisos de vendedores (`api/permisos.php`)

Permite a administradores consultar y modificar los permisos delegables de cuentas con rol Vendedor. Todas las operaciones requieren rol Administrador o Super Administrador. Usa `api/helpers.php`; mantiene `data/users.json` como respaldo.

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

Primero consulta vendedores en MySQL. Si falla o no está disponible, devuelve los usuarios con rol vendedor de `users.json` (si no hay campo `role`, el respaldo los considera vendedores).

## `PUT`: actualizar permisos

`PUT api/permisos.php`

El cuerpo JSON debe incluir `id` y al menos uno de estos campos:

- `puede_registrar_stock`
- `puede_modificar_informes`

Los valores se convierten a booleanos. Solo se permiten esos nombres de permiso. El usuario objetivo debe ser un vendedor; si se encuentra, actualiza MySQL y el respaldo JSON, y registra `permisos_vendedor_actualizados` con el administrador que hizo el cambio.

Errores:

- `400`: ID inválido o no se incluyó ningún permiso.
- `404`: el usuario no existe o no es vendedor (cuando se valida en MySQL), o no fue hallado en el respaldo si MySQL no está disponible.
- `403`: el solicitante no tiene rol administrativo.
- `405`: método no soportado.

Relacionado: [helpers.md](./helpers.md), [users.md](./users.md).
