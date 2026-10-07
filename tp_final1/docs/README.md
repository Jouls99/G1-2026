# Documentación del proyecto SOS Cosméticos
Este directorio reúne la documentación Markdown de la aplicación ubicada en `tp_final1`. Este archivo es el punto de entrada y describe su propósito, módulos, datos, informes, actividad y puesta en marcha. La carpeta `documentación/` existente conserva los documentos y capturas en otros formatos.

## Guías

- [Funcionamiento del código](./FUNCIONAMIENTO_CODIGO.md): flujo y responsabilidades de los archivos principales de la raíz, `api/`, `scrits/` e `includes/`. Excluye `database/` y `node_modules/`.
- [API de actividades](./actividades.md): consulta de auditoría, filtros, estadísticas y registro de eventos.
- [API de autenticación](./auth.md): consulta de sesión e inicio/cierre de sesión; las cuentas las crea un administrador.
- [Helpers de API](./helpers.md): respuestas, permisos y registro de actividad compartidos.
- [API de inventario](./inventario.md): consulta y operaciones sobre productos y estados de stock.
- [API de permisos](./permisos.md): lectura y actualización de permisos delegables de vendedores.
- [API de usuarios](./users.md): administración de usuarios, detalle, roles y métricas.
- [API de ventas](./ventas.md): consulta, registro, actualización y eliminación de ventas.

## 1. Descripción general

SOS Cosméticos es una aplicación web para gestionar el inventario y las ventas de un comercio de cosméticos. Permite iniciar sesión con distintos roles, administrar productos y usuarios, registrar ventas y consultar informes y actividad del sistema.

La aplicación utiliza PHP para sus páginas y API, JavaScript para las interacciones de la interfaz y MySQL como almacenamiento persistente. La base de datos utilizada por el proyecto se llama `sos_cosmeticos`.

## 2. Estructura relevante

| Ruta | Responsabilidad |
| --- | --- |
| `index.php`, `registroinicio.php`, `logout.php` | Entrada, autenticación y cierre de sesión. |
| `ControlStock.php` | Vista de inventario y operaciones de stock. |
| `venta.php` | Módulo de ventas. |
| `informe.php` | Dashboard de ventas e inventario. |
| `Auditoria.php` | Vista de sesiones, actividad y alertas para Super Administradores. |
| `usuarios.php`, `gestión_usuarios.php`, `permisos_vendedores.php` | Administración de usuarios y permisos. |
| `api/` | Endpoints PHP para autenticación, inventario, ventas, usuarios, permisos y actividad. |
| `includes/auth.php`, `api/helpers.php` | Sesión, autorización, respuestas JSON y registro de actividad. |
| `database/sos_cosmeticos.sql` | Definición inicial de la base de datos y datos de roles de demostración. |
| `database/conexion.php` | Conexión PDO y preparación/actualización de partes del esquema. |
| `data/` | Datos JSON legados disponibles para importación inicial. |
| `scrits/` | Scripts JavaScript de interfaz. El nombre del directorio está escrito así en el proyecto. |
| `css/` | Hojas de estilo de las pantallas. |
| `documentación/` | Documentación existente en PDF e imágenes de pantallas. |

## 3. Datos y persistencia

### Base de datos

El esquema inicial de `database/sos_cosmeticos.sql` define la base `sos_cosmeticos` y estas tablas:

| Tabla | Contenido principal |
| --- | --- |
| `categoria` | Categorías de productos (`ID_categoria`, `nombre`). |
| `sub_categoria` | Subcategorías pertenecientes a una categoría (`ID_sub_categoria`, `ID_categoria`, `nombre`). |
| `producto` | Catálogo e inventario: nombre principal, marca, nombre secundario opcional, código, cantidad, categoría, subcategoría, precio y estado (`fase`). |
| `ventas` | Líneas de venta activas durante la jornada. `ganancia` se calcula a partir de cantidad y precio final. |
| `ventas_historial` | Ventas archivadas al cierre, con su fecha exacta y el lunes de la semana correspondiente en `semana_inicio`. Conserva solo la semana actual y las tres anteriores. |
| `facturacion` | Tabla heredada, conservada como origen de migración de ventas anteriores; las ventas nuevas se registran en `ventas`. |
| `sesion_activa` | Sesiones autenticadas y última actividad, usadas para determinar cuál fue la última sesión de la jornada. |
| `login_intentos` | Contador de intentos fallidos consecutivos y bloqueos temporales de inicio de sesión por usuario. |
| `cierre_jornada` | Resultado y cantidad de ventas archivadas y semanas depuradas por cada cierre diario. |
| `usuario` | Nombre de usuario, hash de contraseña, rol, último acceso y fecha de creación. |
| `actividad_usuario` | Eventos del sistema: usuario, tipo de acción, descripción, detalles y fecha. |
| `amenaza` | Alertas de acceso inusual derivadas de los eventos de actividad. |
| `historial_ajuste_precio` | Auditoría de ajustes de precios aplicados al inventario. |
| `Estado_fase` | Valores de estado habilitado/deshabilitado utilizados por el inventario. |

Cada producto debe estar asociado a una categoría existente. Al eliminar una categoría, sus productos se reasignan a otra categoría elegida antes de borrar la original. Cada subcategoría pertenece a una categoría; al reasignar productos se recrean o reutilizan las subcategorías bajo la categoría destino. Las líneas de `ventas` y `ventas_historial` enlazan el producto sin impedir conservar las ventas si este se elimina.

`database/conexion.php` contiene la configuración de conexión PDO y prepara el esquema requerido por la aplicación cuando puede conectarse a MySQL. También incorpora campos de permisos y restricciones adicionales para los roles. Si se cambia el entorno, revisar allí los parámetros de conexión sin guardar credenciales reales en documentación o control de versiones.

### Migración de archivos JSON heredados

La aplicación usa MySQL como única fuente de persistencia en tiempo de ejecución. Los archivos JSON que todavía existan en `data/` pueden importarse al inicializar tablas vacías (inventario, ventas y usuarios) o, para registros de auditoría, una sola vez mediante `migracion_datos`. Una vez importados, los cambios nuevos se guardan en MySQL; la aplicación no sincroniza ni consulta estos archivos como respaldo.

| Archivo | Uso |
| --- | --- |
| `data/inventario.json` | Origen de importación heredado si la tabla `producto` está vacía. |
| `data/ventas.json` | Origen de importación heredado si la tabla `facturacion` está vacía. |
| `data/users.json` | Origen de importación heredado si la tabla `usuario` está vacía. |
| `data/actividades.json`, `data/actividad_ventas.json`, `data/actividad_usuarios.json` | Registros históricos importados una sola vez a `actividad_usuario`. |
| `data/amenazas.json` | Origen de importación heredado; las alertas nuevas se guardan en `amenaza`. |

Los detalles de actividad pueden incluir metadatos de la solicitud, como la dirección IP. Estos registros pueden contener información sensible y deben protegerse como datos de operación; no se deben publicar ni copiar a documentación.

## 4. Ventas e informes

El dashboard de `informe.php`, gestionado por `scrits/dashboard-informe.js`, permite seleccionar una categoría y un período y presenta:

- cantidad de productos vendidos y métricas monetarias;
- valorización del inventario;
- gráficos y resumen de productos por categoría;
- historial de ventas con los nombres reales de los productos y sus datos asociados sin etiquetas genéricas;
- exportación a PDF del historial de ventas por categoría, para el día, semana o mes seleccionado.

Los períodos del dashboard son el día actual, la semana actual (lunes a domingo) y el mes actual. Las métricas suman las ventas disponibles para el período y la categoría seleccionados. Las ventas activas se registran en `ventas`; al cierre se copian a `ventas_historial` y se eliminan de `ventas`, en lotes de hasta 200, dentro de transacciones. El historial guarda la fecha exacta y el lunes de su semana. Se retienen cuatro semanas calendario contando la actual. Los administradores pueden consultar una semana completa o una fecha exacta dentro de ese rango. Los datos se obtienen de las APIs; si alguna carga falla, el dashboard muestra el error y no sustituye datos reales por datos de demostración. La edición/eliminación de ventas activas ajusta también el stock; el historial archivado es de solo lectura.

La exportación PDF utiliza la función de impresión del navegador. Al seleccionar “Guardar como PDF” en el diálogo de impresión, el archivo incluye el historial de todas las categorías para hoy, la semana actual (lunes a domingo) o el mes actual, según el período elegido; no incluye el dashboard y la vista de pantalla no cambia.

Al cerrar la última sesión activa desde las 22:30 de Argentina, el sistema espera a que no haya otras sesiones activas. Entonces archiva las ventas de la jornada, elimina las filas archivadas de `ventas` en lotes de hasta 200 y poda `ventas_historial` para conservar solo cuatro semanas calendario. El resultado se registra en `cierre_jornada`. La actividad de cada sesión se actualiza mientras la aplicación está abierta; una sesión sin actividad se considera vencida según `session.gc_maxlifetime` de PHP. Archivar no repone el stock.

Los valores monetarios y la palabra “ganancia” corresponden a las métricas implementadas en la aplicación; deben interpretarse de acuerdo con cómo se registra el precio de cada venta y no como un sistema contable o fiscal.

## 5. Actividad y auditoría

`Auditoria.php` presenta tres paneles:

1. **Sesiones:** eventos exitosos y fallidos de autenticación y cierres registrados, con búsqueda por usuario o IP.
2. **Actividad y logs:** acciones registradas por autenticación, usuarios, ventas e inventario.
3. **Amenazas:** señales calculadas a partir de intentos fallidos recientes.

La página obtiene la información de `api/actividades.php`. El endpoint requiere el rol Administrador o Super Administrador y limita a 500 los eventos devueltos (el valor predeterminado es 200). Calcula métricas de accesos exitosos, accesos del día e intentos fallidos consultando MySQL.

La interfaz informa estas reglas de detección en una ventana de 15 minutos: cinco fallos asociados a una misma combinación de IP y usuario, o diez fallos desde una IP entre distintos usuarios. Las alertas son indicios calculados por la aplicación; no reemplazan un sistema externo de monitoreo o seguridad.

## 6. Roles y permisos

La autorización se comprueba tanto en las páginas como en los endpoints:

| Rol o permiso | Capacidades generales |
| --- | --- |
| Vendedor | Operación habitual de ventas y acceso a páginas protegidas para usuarios autenticados. |
| Administrador | Administración de inventario, capacidad de modificar informes y gestión completa de usuarios; puede consultar el registro de actividades, pero no el monitoreo de logins. |
| Super Administrador | Funciones de administrador, gestión de usuarios, monitoreo de logins y acceso al centro de auditoría. |
| `puede_registrar_stock` | Permiso delegable que habilita el registro de stock a un usuario autorizado. |
| `puede_modificar_informes` | Permiso delegable para las modificaciones de informes permitidas por la aplicación. |

La asignación de permisos delegables se gestiona desde la administración correspondiente. Los permisos no sustituyen la comprobación de sesión: las API verifican también que el usuario esté autenticado y tenga el rol requerido para cada operación.

## 7. API principal

Los endpoints de `api/` responden principalmente en JSON:

| Endpoint | Función |
| --- | --- |
| `api/auth.php` | Comprobación de sesión e inicio/cierre de sesión. El registro público está deshabilitado; los administradores crean las cuentas desde Gestión de Usuarios. |
| `api/inventario.php` | Lectura y operaciones de inventario, incluidos cambios de estado y auditoría de stock. |
| `api/ventas.php` | Consulta, registro, edición y eliminación de ventas según autorización. |
| `api/actividades.php` | Consulta de actividad, métricas de sesión y alertas para auditoría. |
| `api/users.php` | Gestión y consulta de usuarios; requiere Super Administrador. |
| `api/permisos.php` | Administración de permisos; requiere Administrador o superior. |
| `api/helpers.php` | Funciones compartidas para autenticación de API, persistencia JSON, permisos y logs. |

Las acciones concretas y los datos aceptados se definen en cada endpoint; este resumen no reemplaza sus validaciones ni constituye una especificación de integración externa.

## 8. Puesta en marcha local

1. Usar una instalación local de PHP con PDO para MySQL y un servidor MySQL, por ejemplo XAMPP.
2. Colocar el proyecto bajo el directorio público del servidor web.
3. Iniciar Apache y MySQL.
4. Crear/importar el esquema con `database/sos_cosmeticos.sql` si aún no existe y verificar la configuración de conexión en `database/conexion.php`. Las tablas vacías importan los datos JSON heredados una vez.
5. Abrir la aplicación desde el servidor local, por ejemplo `http://localhost/tp_final1/`.

La estructura y migraciones de `database/conexion.php` deben mantenerse alineadas con el SQL inicial. Antes de actualizar una instalación con datos reales, generar y verificar un respaldo de la base de datos.

## 9. Mantenimiento de esta documentación

- Mantener esta guía como índice y documentación general del sistema.
- Agregar documentos temáticos en `docs/` cuando un módulo necesite más detalle y enlazarlos desde aquí.
- Actualizar la descripción de datos, permisos, endpoints o configuración cuando cambie su implementación.
- No incluir contraseñas, hashes, datos reales de usuarios, IPs, registros de actividad ni copias de archivos JSON.
