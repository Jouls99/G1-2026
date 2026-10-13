# API de ventas (`api/ventas.php`)

Permite consultar el historial, registrar ventas, ajustar cantidades y eliminar ventas activas. Requiere una sesión activa. `ventas`, `ventas_historial` y `producto` en MySQL son las fuentes de persistencia. Cada línea conserva el nombre del producto vendido en `nombre_producto`, incluso si posteriormente se elimina el producto del inventario.

## `GET`: listar ventas

`GET api/ventas.php`

`GET api/ventas.php` devuelve las ventas disponibles en el día para los vendedores, y las últimas cuatro semanas para administradores. Cada línea incluye fecha, nombre, nombre secundario, subcategoría, categoría, marca y cantidad. El historial gráfico de informes no expone códigos de producto.

Los administradores pueden filtrar con `GET api/ventas.php?semana=AAAA-MM-DD`, donde la fecha debe ser el lunes de la semana, o consultar un día exacto con `GET api/ventas.php?fecha=AAAA-MM-DD`. Ambas consultas se limitan a la semana actual y las tres anteriores; la consulta general usa `semana_inicio` y la puntual usa `fecha`.

Las nuevas ventas se guardan en `ventas`. Al finalizar la última sesión activa desde las 22:30 de Argentina, el proceso copia las filas de la jornada a `ventas_historial` (incluyendo fecha y lunes de la semana) y después las elimina de `ventas`, en lotes transaccionales de hasta 200. Poda las semanas anteriores a las cuatro semanas calendario retenidas y registra el resultado en `cierre_jornada`. Archivar no modifica el stock.

## `POST`: registrar venta

`POST api/ventas.php`

El cuerpo JSON debe incluir una lista no vacía `productos`. Cada producto normalmente contiene `codigo`, `nombre`, `ID_stock` o `id`, `cantidad`, `precio` y `categoria`. Cantidad y precio negativos se rechazan con `400 invalid_payload`. La fecha puede enviarse como `fecha` o `fecha_hora`; se usa la fecha válida reconocida por PHP.

El endpoint registra las ventas en `ventas`, guarda el nombre vigente del producto en `nombre_producto` y ajusta existencias dentro de una transacción MySQL. Los productos sin código válido se rechazan. Finalmente llama a `logActivity()` con tipo `venta_registrada`.

La respuesta incluye `ok`, `venta` y `totalVenta`. En `venta`, el total puede provenir del campo `total` enviado por el cliente; si no está, se calcula sumando precio por cantidad.

## `PUT`: modificar venta/historial

`PUT api/ventas.php`

Hay dos modos:

La actualización de una sola venta activa requiere el permiso `puede_modificar_informes`. El cuerpo debe incluir `id` de venta, `codigo` de producto y `cantidad` nueva mayor que cero. Ajusta la diferencia de stock y la cantidad en una transacción MySQL. Si el aumento supera el stock disponible, responde `409`. Las ventas archivadas son de solo lectura. El reemplazo masivo de todo el historial no está permitido; cada venta debe modificarse o eliminarse por ID.

La modificación de una línea valida coincidencia entre venta y código de producto y responde `404` si no existe.

## `DELETE`: eliminar venta

`DELETE api/ventas.php?id=ID`

Requiere rol Administrador o Super Administrador. Acepta el ID en query string o en el cuerpo JSON. Elimina una fila de `ventas`, restaura el stock del producto en MySQL y registra `venta_eliminada`. Las filas de `ventas_historial` no se modifican ni se eliminan mediante este endpoint.

## Códigos relevantes

- `401`: no hay sesión.
- `503`: base de datos no disponible.
- `403`: rol/permisos insuficientes.
- `400`: cuerpo de venta inválido o campos obligatorios faltantes.
- `404`: venta/producto indicados no encontrados.
- `409`: stock insuficiente para aumentar una venta.
- `405`: método no soportado.

Relacionado: [helpers.md](./helpers.md), [inventario.md](./inventario.md), [actividades.md](./actividades.md).
