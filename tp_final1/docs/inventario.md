# API de inventario (`api/inventario.php`)

Gestiona la consulta, alta, edición, rehabilitación y baja lógica de productos. Requiere sesión activa en todos los métodos. Usa MySQL como fuente principal y `data/inventario.json` como respaldo/sincronización.

## Acceso

- `GET`: cualquier usuario con sesión puede consultar productos habilitados.
- `GET?action=audit`: solo Super Administrador.
- `POST`: requiere rol administrativo o permiso delegable `puede_registrar_stock`. La creación es la única acción disponible a un vendedor que solo tenga ese permiso.
- `PUT` y `DELETE`: solo Administrador o Super Administrador.
- `OPTIONS`: preflight CORS.

## `GET`: inventario

`GET api/inventario.php`

Devuelve una lista JSON de productos, con campos como `id`, `ID_stock`, `nombre`, `codigo`, `cantidad`, `cantVendida`, `precio`, `total`, `categoria` e `ID_categoria`. Los Super Administradores también reciben `fase`, que puede ser `habilitado` o `deshabilitado`.

Con MySQL, la consulta une productos y categorías, y guarda el resultado normalizado en `data/inventario.json`. Si falla la consulta, responde con el JSON local; los usuarios que no son Super Administradores no reciben productos deshabilitados ni el campo `fase`.

### Auditoría de cambios de estado

`GET api/inventario.php?action=audit`

Devuelve como máximo 250 eventos `stock_habilitar` y `stock_deshabilitar`, con `id`, `usuario`, `tipo`, `descripcion`, `detalles` y `fecha`. Normalmente consulta `actividad_usuario`; si la consulta falla, busca el respaldo en `actividades.json`. Si no hay conexión PDO devuelve `503`.

## `POST`: crear o rehabilitar

Por defecto, `POST api/inventario.php` crea un producto. Cuerpo JSON habitual:

```json
{
  "nombre": "Producto",
  "codigo": "COD-001",
  "precio": 1000,
  "cantidad": 10,
  "categoria": "General",
  "subcategoria": null
}
```

`nombre` y `codigo` son obligatorios; `precio` y `cantidad` usan 0 por defecto, y la categoría predeterminada es `General`. Si el código ya existe y el producto está activo, devuelve `409 item_exists`. Si existía deshabilitado, solo un administrador puede rehabilitarlo; se actualizan nombre, precio y cantidad.

Los administradores pueden rehabilitar explícitamente con `POST?action=rehabilitar` (o `action` en el cuerpo) y enviar `codigo` en cuerpo o query string. La acción marca el producto habilitado en MySQL y en el JSON, registra actividad `stock_habilitar` y devuelve la fase.

Las altas nuevas registran `stock_crear`. Las operaciones sincronizan `inventario.json` cuando corresponde.

## `PUT`: actualizar

Requiere rol administrativo. Acepta un objeto de producto o un array de productos. Cada elemento se identifica por `codigo` y/o `ID_stock`/`id`; puede actualizar nombre, precio, cantidad (`cantidad` o `stock`) y fase (`habilitado`/`deshabilitado`). Un array reemplaza el contenido del respaldo JSON tras aplicar actualizaciones a MySQL; un objeto actualiza la fila coincidente y sincroniza el JSON. El objeto registra `stock_modificar`.

Errores frecuentes: `400 invalid_payload` si el cuerpo no se interpreta como array/objeto; `500 save_error` si falla la actualización procesada.

## `DELETE`: baja lógica

`DELETE api/inventario.php?codigo=COD-001`

También acepta `codigo` en el cuerpo JSON. No elimina el producto: asigna `fase: deshabilitado` en MySQL y/o en `inventario.json`, conservando el historial. Registra `stock_deshabilitar` si encuentra y actualiza el producto.

- `400 missing_code`: falta el código/identificador.
- `404 not_found`: no se encontró el producto en ninguna fuente.
- `405 method_not_allowed`: método no soportado.

Relacionado: [helpers.md](./helpers.md), [actividades.md](./actividades.md), [ventas.md](./ventas.md).
