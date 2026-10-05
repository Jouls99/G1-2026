# API de ventas (`api/ventas.php`)

Permite consultar el historial, registrar ventas, ajustar cantidades y eliminar facturas. Requiere una sesión activa. Usa `facturacion` y `producto` en MySQL, con sincronización/alternativa de archivos `data/ventas.json` e `data/inventario.json`.

## `GET`: listar ventas

`GET api/ventas.php`

Devuelve las ventas de MySQL, con cada línea de factura representada como producto. Si la consulta no produce resultados y existe `ventas.json` con registros, devuelve ese respaldo. La ruta no aplica filtro por rol más allá de exigir sesión.

## `POST`: registrar venta

`POST api/ventas.php`

El cuerpo JSON debe incluir una lista no vacía `productos`. Cada producto normalmente contiene `codigo`, `nombre`, `ID_stock` o `id`, `cantidad`, `precio` y `categoria`. Cantidad y precio negativos se rechazan con `400 invalid_payload`. La fecha puede enviarse como `fecha` o `fecha_hora`; se usa la fecha válida reconocida por PHP.

El endpoint intenta registrar las facturas en MySQL dentro de una transacción y ajustar existencias. Si no se procesan productos en MySQL, construye el resultado con el cuerpo recibido. En ambos casos actualiza el respaldo del inventario y agrega el comprobante a `ventas.json`. Finalmente llama a `logActivity()` con tipo `venta_registrada`.

La respuesta incluye `ok`, `venta` y `totalVenta`. En `venta`, el total puede provenir del campo `total` enviado por el cliente; si no está, se calcula sumando precio por cantidad.

## `PUT`: modificar venta/historial

`PUT api/ventas.php`

Hay dos modos:

1. Un usuario con rol Administrador puede reemplazar el array de ventas o combinar un objeto con la venta de ID coincidente. Se persiste en `ventas.json` y se registra la modificación.
2. Un usuario no administrador necesita el permiso `puede_modificar_informes` y `?action=editar_informe`. El cuerpo debe incluir `id` de factura, `codigo` de producto y `cantidad` nueva mayor que cero. Ajusta la diferencia de stock y la cantidad facturada, y actualiza los respaldos. Si el aumento supera el stock disponible, responde `409`.

La modificación de una línea valida coincidencia entre venta y código de producto y responde `404` si no existe. El historial enviado como array es una operación administrativa que reemplaza el respaldo completo.

## `DELETE`: eliminar venta

`DELETE api/ventas.php?id=ID`

Requiere rol Administrador o Super Administrador. Acepta el ID en query string o en el cuerpo JSON. Intenta borrar la fila de `facturacion`, elimina del historial JSON la venta cuyo `id` coincida y registra `venta_eliminada`. Devuelve confirmación JSON.

## Códigos relevantes

- `401`: no hay sesión.
- `403`: rol/permisos insuficientes.
- `400`: cuerpo de venta inválido o campos obligatorios faltantes.
- `404`: venta/producto indicados no encontrados.
- `409`: stock insuficiente para aumentar una venta.
- `405`: método no soportado.

Relacionado: [helpers.md](./helpers.md), [inventario.md](./inventario.md), [actividades.md](./actividades.md).
