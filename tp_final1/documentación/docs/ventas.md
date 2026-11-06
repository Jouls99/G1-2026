# API de ventas (`api/ventas.php`)

Permite consultar el historial, registrar ventas y deshabilitar ventas diarias. Requiere una sesión activa. `ventas`, `ventas_historial` y `producto` en MySQL son las fuentes de persistencia. Cada línea conserva el nombre del producto vendido en `nombre_producto`, incluso si posteriormente se elimina el producto del inventario.

## `GET`: listar ventas

`GET api/ventas.php`

`GET api/ventas.php` devuelve las ventas disponibles en el día para los vendedores, y las últimas cuatro semanas para administradores. Cada línea incluye fecha, nombre, nombre secundario, subcategoría, categoría, marca y cantidad. El historial gráfico de informes no expone códigos de producto. Las cuatro semanas se cuentan desde el lunes de la semana actual e incluyen las cuatro semanas anteriores.

Los administradores pueden filtrar con `GET api/ventas.php?semana=AAAA-MM-DD`, donde la fecha debe ser el lunes de la semana, o consultar un día exacto con `GET api/ventas.php?fecha=AAAA-MM-DD`. Ambas consultas se limitan a la semana actual y las tres anteriores; la consulta general usa `semana_inicio` y la puntual usa `fecha`.

Las nuevas ventas se guardan en `ventas` con `estado = habilitada`. Al finalizar la última sesión activa desde las 22:30 de Argentina, el proceso copia las filas de la jornada a `ventas_historial` (incluyendo estado, fecha y lunes de la semana) y después las elimina de `ventas`, en lotes transaccionales de hasta 200. Poda las semanas anteriores a las cuatro semanas calendario retenidas y registra el resultado en `cierre_jornada`. Archivar no modifica el stock.

## `POST`: registrar venta

`POST api/ventas.php`

El cuerpo JSON debe incluir una lista no vacía `productos`. Cada producto normalmente contiene `codigo`, `nombre`, `ID_stock` o `id`, `cantidad`, `precio` y `categoria`. Cantidad y precio negativos se rechazan con `400 invalid_payload`. La fecha puede enviarse como `fecha` o `fecha_hora`; se usa la fecha válida reconocida por PHP.

El endpoint registra las ventas en `ventas`, guarda el nombre vigente del producto en `nombre_producto` y ajusta existencias dentro de una transacción MySQL. Los productos sin código válido se rechazan. Finalmente llama a `logActivity()` con tipo `venta_registrada`.

La respuesta incluye `ok`, `venta` y `totalVenta`. En `venta`, el total puede provenir del campo `total` enviado por el cliente; si no está, se calcula sumando precio por cantidad.

## `POST`: deshabilitar venta diaria

`POST api/ventas.php`

El cuerpo JSON debe ser `{ "action": "deshabilitar", "id": ID }` y requiere `puede_modificar_informes`. Solo permite ventas del día actual. Dentro de una transacción, suma nuevamente la cantidad vendida al stock, resta esa cantidad de `cantVendida`, cambia la venta a `estado = deshabilitada` y registra `venta_deshabilitada` en el historial de edición de ventas. El registro de la venta no se elimina y deja de contar en totales e informes.

No se permite modificar ni eliminar ventas mediante `PUT` o `DELETE`; esos métodos responden `405`.

## Códigos relevantes

- `401`: no hay sesión.
- `503`: base de datos no disponible.
- `403`: rol/permisos insuficientes.
- `400`: cuerpo de venta inválido o campos obligatorios faltantes.
- `404`: venta/producto indicados no encontrados.
- `409`: stock insuficiente para aumentar una venta.
- `405`: método no soportado.

Relacionado: [helpers.md](./helpers.md), [inventario.md](./inventario.md), [actividades.md](./actividades.md).
