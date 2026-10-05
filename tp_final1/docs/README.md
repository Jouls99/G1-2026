# Documentación del proyecto SOS Cosméticos

Este directorio reúne la documentación Markdown de la aplicación ubicada en `tp_final1`. Este archivo es el punto de entrada y describe su propósito, módulos, datos, informes, actividad y puesta en marcha. La carpeta `documentación/` existente conserva los documentos y capturas en otros formatos.

## Guías

- [Funcionamiento del código](./FUNCIONAMIENTO_CODIGO.md): flujo y responsabilidades de los archivos principales de la raíz, `api/`, `scrits/` e `includes/`. Excluye `database/` y `node_modules/`.
- [API de actividades](./actividades.md): consulta de auditoría, filtros, estadísticas y registro de eventos.
- [API de autenticación](./auth.md): consulta de sesión, inicio, registro y cierre de sesión.
- [Helpers de API](./helpers.md): respuestas, permisos, persistencia JSON y registro de actividad compartidos.
- [API de inventario](./inventario.md): consulta y operaciones sobre productos y estados de stock.
- [API de permisos](./permisos.md): lectura y actualización de permisos delegables de vendedores.
- [API de usuarios](./users.md): administración de usuarios, detalle, roles y métricas.
- [API de ventas](./ventas.md): consulta, registro, actualización y eliminación de ventas.

## 1. Descripción general

SOS Cosméticos es una aplicación web para gestionar el inventario y las ventas de un comercio de cosméticos. Permite iniciar sesión con distintos roles, administrar productos y usuarios, registrar ventas y consultar informes y actividad del sistema.

La aplicación utiliza PHP para sus páginas y API, JavaScript para las interacciones de la interfaz, MySQL como almacenamiento principal y archivos JSON como respaldo para los módulos que lo implementan. La base de datos utilizada por el proyecto se llama `sos_cosmeticos`.

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
| `includes/auth.php`, `api/helpers.php` | Sesión, autorización, respuestas JSON, persistencia auxiliar y registro de actividad. |
| `database/sos_cosmeticos.sql` | Definición inicial de la base de datos y datos de roles de demostración. |
| `database/conexion.php` | Conexión PDO y preparación/actualización de partes del esquema. |
| `data/` | Archivos JSON usados como respaldo y para ciertos registros auxiliares. |
| `scrits/` | Scripts JavaScript de interfaz. El nombre del directorio está escrito así en el proyecto. |
| `css/` | Hojas de estilo de las pantallas. |
| `documentación/` | Documentación existente en PDF e imágenes de pantallas. |

## 3. Datos y persistencia

### Base de datos

El esquema inicial de `database/sos_cosmeticos.sql` define la base `sos_cosmeticos` y estas tablas:

| Tabla | Contenido principal |
| --- | --- |
| `categoria` | Categorías de productos (`ID_categoria`, `nombre`). |
| `producto` | Catálogo e inventario: nombre, código, cantidad total y vendida, categoría, precio y estado (`fase`). |
| `facturacion` | Líneas de venta: fecha, cantidad, precio final, producto y usuario asociado. `ganancia` es una columna calculada a partir de cantidad y precio final. |
| `usuario` | Nombre de usuario, hash de contraseña, rol, último acceso y fecha de creación. |
| `actividad_usuario` | Eventos del sistema: usuario, tipo de acción, descripción, detalles y fecha. |
| `Estado_fase` | Valores de estado habilitado/deshabilitado utilizados por el inventario. |

La relación entre `producto` y `categoria` permite que un producto quede sin categoría si se elimina esta. La relación entre `facturacion` y `producto` conserva la venta aunque el producto relacionado deje de existir.

`database/conexion.php` contiene la configuración de conexión PDO y prepara el esquema requerido por la aplicación cuando puede conectarse a MySQL. También incorpora campos de permisos y restricciones adicionales para los roles. Si se cambia el entorno, revisar allí los parámetros de conexión sin guardar credenciales reales en documentación o control de versiones.

### Archivos JSON

Los archivos de `data/` complementan a MySQL. Los endpoints consultan o sincronizan algunos de ellos como respaldo cuando no pueden utilizar la base de datos; por eso no deben tratarse como una segunda fuente independiente ni editarse manualmente mientras la aplicación está en uso.

| Archivo | Uso |
| --- | --- |
| `data/inventario.json` | Respaldo del inventario. |
| `data/ventas.json` | Respaldo del historial de ventas. |
| `data/users.json` | Respaldo de usuarios y permisos. |
| `data/actividades.json` | Registro general de actividad usado por la ruta alternativa JSON. |
| `data/actividad_ventas.json` | Eventos relacionados con ventas. |
| `data/actividad_usuarios.json` | Eventos de autenticación y usuarios; también se consulta para evaluar señales de acceso. |
| `data/amenazas.json` | Alertas de acceso inusual detectadas por la aplicación. |

Los detalles de actividad pueden incluir metadatos de la solicitud, como la dirección IP. Estos registros pueden contener información sensible y deben protegerse como datos de operación; no se deben publicar ni copiar a documentación.

## 4. Ventas e informes

El dashboard de `informe.php`, gestionado por `scrits/dashboard-informe.js`, permite seleccionar una categoría y un período y presenta:

- cantidad de productos vendidos y métricas monetarias;
- valorización del inventario;
- gráficos y resumen de productos por categoría;
- historial de ventas y una tabla de resumen con producto, cantidades, precio y fecha.

Los períodos disponibles se calculan en la interfaz e incluyen vistas diaria, semanal, mensual y anual. Los datos se obtienen de los endpoints de inventario y ventas. El API de ventas registra operaciones en `facturacion` y actualiza las cantidades del producto; la edición de una venta ajusta también el stock. El acceso a modificaciones depende del rol o permiso correspondiente.

Los valores monetarios y la palabra “ganancia” corresponden a las métricas implementadas en la aplicación; deben interpretarse de acuerdo con cómo se registra el precio de cada venta y no como un sistema contable o fiscal.

## 5. Actividad y auditoría

`Auditoria.php` presenta tres paneles:

1. **Sesiones:** eventos exitosos y fallidos de autenticación y cierres registrados, con búsqueda por usuario o IP.
2. **Actividad y logs:** acciones registradas por autenticación, usuarios, ventas e inventario.
3. **Amenazas:** señales calculadas a partir de intentos fallidos recientes.

La página obtiene la información de `api/actividades.php`. El endpoint requiere el rol Super Administrador y limita a 500 los eventos devueltos (el valor predeterminado es 200). Calcula métricas de accesos exitosos, accesos del día e intentos fallidos. Cuando utiliza el respaldo JSON, combina los registros de actividad de los archivos correspondientes y los ordena por fecha.

La interfaz informa estas reglas de detección en una ventana de 15 minutos: cinco fallos asociados a una misma combinación de IP y usuario, o diez fallos desde una IP entre distintos usuarios. Las alertas son indicios calculados por la aplicación; no reemplazan un sistema externo de monitoreo o seguridad.

## 6. Roles y permisos

La autorización se comprueba tanto en las páginas como en los endpoints:

| Rol o permiso | Capacidades generales |
| --- | --- |
| Vendedor | Operación habitual de ventas y acceso a páginas protegidas para usuarios autenticados. |
| Administrador | Administración de inventario y capacidad de modificar informes, además de las funciones generales. |
| Super Administrador | Funciones de administrador, gestión de usuarios y acceso al centro de auditoría. |
| `puede_registrar_stock` | Permiso delegable que habilita el registro de stock a un usuario autorizado. |
| `puede_modificar_informes` | Permiso delegable para las modificaciones de informes permitidas por la aplicación. |

La asignación de permisos delegables se gestiona desde la administración correspondiente. Los permisos no sustituyen la comprobación de sesión: las API verifican también que el usuario esté autenticado y tenga el rol requerido para cada operación.

## 7. API principal

Los endpoints de `api/` responden principalmente en JSON:

| Endpoint | Función |
| --- | --- |
| `api/auth.php` | Comprobación de sesión, inicio/cierre de sesión y registro de usuarios. |
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
4. Crear/importar el esquema con `database/sos_cosmeticos.sql` si aún no existe y verificar la configuración de conexión en `database/conexion.php`.
5. Abrir la aplicación desde el servidor local, por ejemplo `http://localhost/tp_final1/`.

La estructura y migraciones de `database/conexion.php` deben mantenerse alineadas con el SQL inicial. Antes de actualizar una instalación con datos reales, generar y verificar un respaldo de la base de datos y de `data/`.

## 9. Mantenimiento de esta documentación

- Mantener esta guía como índice y documentación general del sistema.
- Agregar documentos temáticos en `docs/` cuando un módulo necesite más detalle y enlazarlos desde aquí.
- Actualizar la descripción de datos, permisos, endpoints o configuración cuando cambie su implementación.
- No incluir contraseñas, hashes, datos reales de usuarios, IPs, registros de actividad ni copias de archivos JSON.
