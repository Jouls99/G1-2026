# API de inventario (`api/inventario.php`)

Gestiona la consulta, alta, edición, rehabilitación y baja lógica de productos. Requiere sesión activa en todos los métodos. MySQL es la única fuente de persistencia.

## Acceso

- `GET`: cualquier usuario con sesión puede consultar productos habilitados.
- `GET?action=categories`: devuelve las categorías existentes en MySQL, incluidas las que todavía no tienen productos.
- `GET?action=audit`: solo Super Administrador.
- `POST`: requiere rol administrativo o permiso delegable `puede_registrar_stock` para crear productos. La creación de categorías requiere rol administrativo.
- `PUT` y `DELETE`: solo Administrador o Super Administrador.
- `OPTIONS`: preflight CORS.

## `GET`: inventario

`GET api/inventario.php`

Devuelve una lista JSON de productos, con campos como `id`, `ID_stock`, `nombre`, `codigo`, `cantidad`, `cantVendida`, `precio`, `total`, `categoria` e `ID_categoria`. Los Super Administradores también reciben `fase`, que puede ser `habilitado` o `deshabilitado`.

La consulta une productos y categorías; los usuarios que no son Super Administradores no reciben productos deshabilitados ni el campo `fase`. Si falla la consulta, responde con error en lugar de leer un archivo JSON.

### Auditoría de cambios de estado

`GET api/inventario.php?action=audit`

Devuelve como máximo 250 eventos `stock_habilitar` y `stock_deshabilitar`, con `id`, `usuario`, `tipo`, `descripcion`, `detalles` y `fecha`, consultando `actividad_usuario` en MySQL. Si no hay conexión PDO devuelve `503`.

## `POST`: crear o rehabilitar

Por defecto, `POST api/inventario.php` crea un producto. Cuerpo JSON habitual:

```json
{
  "nombre": "Producto",
  "marca": "Marca",
  "sub_nombre": "Nombre secundario opcional",
  "codigo": "001",
  "precio": 1000,
  "cantidad": 10,
  "categoria": "Maquillaje",
  "subcategoria": null
}
```

`nombre`, `codigo` y una categoría existente son obligatorios; `precio` y `cantidad` usan 0 por defecto. Las categorías `General` y `Sin categoría` no se ofrecen ni se aceptan; `General` se conserva únicamente como agrupación del informe PDF para registros históricos sin categoría disponible. Si el código ya existe y el producto está activo, devuelve `409 item_exists`. Si existía deshabilitado, solo un administrador puede rehabilitarlo; se actualizan nombre, precio y cantidad.

Al eliminar una categoría, se debe indicar otra categoría existente como destino. Los productos se reasignan allí y sus subcategorías se recrean o reutilizan en la categoría destino antes de borrar la categoría original.

El nombre admite únicamente letras ASCII `A-Z`/`a-z` y espacios simples entre palabras. Al crear o rehabilitar, el código admite únicamente dígitos `0-9`. El precio admite dígitos y, opcionalmente, un punto seguido de uno o dos decimales. La interfaz y la API validan estas reglas al crear o modificar un producto.

Cada producto puede incluir `marca` y `sub_nombre` opcionales. Las subcategorías se registran en `sub_categoria`, asociadas a la categoría del producto; al crear o editar un producto, la API reutiliza o crea esa subcategoría y asigna `ID_sub_categoria`. Los valores de subcategoría preexistentes se migran automáticamente desde la columna `producto.subcategoria`.

Los administradores pueden rehabilitar explícitamente con `POST?action=rehabilitar` (o `action` en el cuerpo) y enviar `codigo` en cuerpo o query string. La acción marca el producto habilitado en MySQL, registra actividad `stock_habilitar` y devuelve la fase.

Las altas nuevas registran `stock_crear`.

### Categorías

- `GET?action=categories` devuelve `{ "ok": true, "categorias": [...] }`, incluyendo categorías vacías.
- `POST?action=create_category` acepta `{ "nombre": "Cuidado facial" }` y crea la categoría en MySQL. Un nombre duplicado devuelve `409 category_exists`.
- `DELETE?action=delete_category&id=ID` elimina la categoría y deshabilita sus productos en MySQL; los productos y las ventas históricas se conservan. La acción requiere rol administrativo.

## `PUT`: actualizar

Requiere rol administrativo. Acepta un objeto de producto o un array de productos. Cada elemento se identifica por `codigo` y/o `ID_stock`/`id`; puede actualizar nombre, precio, cantidad (`cantidad` o `stock`) y fase (`habilitado`/`deshabilitado`) directamente en MySQL. Valida el nombre y el precio conforme a las restricciones indicadas arriba. El objeto registra `stock_modificar`.

Errores frecuentes: `400 invalid_payload` si el cuerpo no se interpreta como array/objeto; `500 save_error` si falla la actualización procesada.

## `DELETE`: baja lógica

`DELETE api/inventario.php?codigo=COD-001`

También acepta `codigo` en el cuerpo JSON. No elimina el producto: asigna `fase: deshabilitado` en MySQL, conservando el historial. Registra `stock_deshabilitar` si encuentra el producto.

- `400 missing_code`: falta el código/identificador.
- `404 not_found`: no se encontró el producto en MySQL.
- `405 method_not_allowed`: método no soportado.

Relacionado: [helpers.md](./helpers.md), [actividades.md](./actividades.md), [ventas.md](./ventas.md).
