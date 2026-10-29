<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Proteger vista: requiere sesión activa
requireAuth('registroinicio.php');

// Configura la plantilla y comunica a JavaScript los permisos de edición vigentes.
$pageTitle = 'Informe de Stock y Métricas';
$customCss = 'css/estadisticadasboard.css?v=' . filemtime(__DIR__ . '/css/estadisticadasboard.css');
$activePage = 'informe';
$puedeModificarInforme = canModifyReports();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<main class="report-container">
    <!-- Accesos principales del informe; las opciones administrativas dependen del rol. -->
    <header class="report-header">
        <div class="title-area">
            <h1>📊 Informe de Stock y Ventas</h1>
            <p>Este informe muestra ventas, ganancias y resúmenes por categoría y periodo.</p>
        </div>

        <div class="header-actions">
            <button type="button" class="btn-secondary" id="exportReportPdfBtn">📄 Exportar informe PDF</button>
            <a href="ControlStock.php" class="btn-secondary">📦 Control de Stock</a>
            <a href="venta.php" class="btn-secondary">🛒 Ir a Ventas</a>
            <?php if (isSuperAdmin()): ?>
                <a href="usuarios.php" class="btn-secondary" style="background:#fef3c7; border-color:#f59e0b; color:#b45309; font-weight:bold;">👑 Gestión de Usuarios</a>
            <?php endif; ?>
        </div>
    </header>

    <!-- Controles, indicadores y visualizaciones que dashboard-informe.js calcula con la API. -->
    <section id="dashboard-informe">
        <div class="dashboard-header">
            <h2>Dashboard de ventas</h2>
            <p>Seleccioná una categoría y un periodo para ver el comportamiento del inventario.</p>
        </div>
        <p id="dashboardLoadError" class="dashboard-load-error" role="alert" hidden></p>

        <div class="dashboard-controls">
            <div>
                <h3>Categorías</h3>
                <div id="categoryButtons" class="chip-group"></div>
            </div>
            <div>
                <h3>Periodo</h3>
                <div id="periodButtons" class="chip-group"></div>
            </div>
        </div>

        <div class="metrics-grid">
            <article class="metric-card">
                <span>Productos vendidos</span>
                <strong id="metricSold">0</strong>
            </article>
            <article class="metric-card">
                <span>Ganancia</span>
                <strong id="metricRevenue">$0</strong>
            </article>
            <article class="metric-card">
                <span>Resumen</span>
                <strong id="metricSummary">$0</strong>
            </article>
            <article class="metric-card">
                <span>Valorización del stock</span>
                <strong id="metricInventoryValue">$0</strong>
            </article>
        </div>

        <div class="dashboard-layout">
            <section class="panel">
                <h3 id="dashboardTitle">Categoría</h3>
                <svg id="lineChart" class="line-chart" viewBox="0 0 640 280"></svg>
                <div class="legend">
                    <span><i class="dot sold"></i>Vendidos</span>
                    <span><i class="dot revenue"></i>Ganancia</span>
                </div>
            </section>

            <aside class="panel">
                <h3>Productos de la categoría</h3>
                <ul id="productList" class="product-list"></ul>
            </aside>
        </div>
        <!-- Historial de ventas y resumen de inventario se despliegan bajo demanda. -->
        <button type="button" id="btn-despliegueventas" aria-controls="seccion-ventas" aria-expanded="false">Desplegar Ventas</button>
        <button type="button" id="btn-despliegueresumen" aria-controls="seccion-resumen" aria-expanded="false">Desplegar Resumen</button>
        <div id="edit-history-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close-btn" id="closeEditHistoryModalBtn">&times;</span>
            <h2>📜 Historial de Edición de Venta</h2>
            <div id="edit-history-content">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Rol</th>
                            <th>Acción</th>
                            <th>Fecha</th>
                            <th>Hora</th>
                            <th>Detalle de cambios</th>
                        </tr>
                    </thead>
                    <tbody id="edit-history-table-body">
                        <!-- Filas agregadas dinámicamente mediante JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <button type="button" class="btn btn-history" id="btnViewEditHistory" style="margin-top:12px;">🔍 Ver historial de edición</button>

    <script>
    // --- MODAL E HISTORIAL DE EDICIONES ---
    // --- Diagnóstico y ajuste de carga de historial de ediciones de venta ---

    /*
    Observaciones:
      - La tabla se muestra, pero el historial no se carga, o siempre se muestra vacío.
      - Al cargar el historial se ejecuta cargarHistorialEdiciones().
      - El fetch apunta a 'api/ventas/ediciones_historial.php', espera array de ediciones JSON.
      - En renderizarTablaHistorial, la variable window.usuarioEsAdminInforme a veces podría no estar inicializada o estar indefinida.

    Correcciones y sugerencias:
      1. Asegúrate de que window.usuarioEsAdminInforme esté definida ANTES de usarla en renderizarTablaHistorial.
         (Está inicializada en otro bloque <script>, por lo que, si el orden o el caché JS lo impiden, podría ser undefined.)
      2. Registra en consola lo recibido por el fetch para descartar una respuesta malinterpretada (ej: error backend, no array).
      3. Mejora el filtro/acceso defensivo en renderizarTablaHistorial.
      4. Asegúrate de que los campos de la edición coincidan con lo esperado (rol, usuario_id, etc).
    */

    // 1. DEBUG: Log el resultado del fetch para detectar problemas de datos.
    function cargarHistorialEdiciones() {
        fetch('api/ventas/ediciones_historial.php')
            .then(res => {
                if (!res.ok) throw new Error('HTTP error');
                return res.json();
            })
            .then(data => {
                // DEBUG: log data para diagnóstico
                console.log("[Historial Edit] Datos recibidos:", data);

                // Si la respuesta no es array, mostrar error
                if (!Array.isArray(data)) {
                    document.getElementById('edit-history-table-body').innerHTML = '<tr><td colspan="6">Respuesta inesperada del servidor.</td></tr>';
                    return;
                }
                renderizarTablaHistorial(data);
            })
            .catch((e) => {
                // DEBUG: log error
                console.error("[Historial Edit] Error cargando historial:", e);
                document.getElementById('edit-history-table-body').innerHTML = '<tr><td colspan="6">No se pudo cargar el historial.</td></tr>';
            });
    }

    // 2. Reforzar control de variables globales y tolerancia si no están aún definidas
    function renderizarTablaHistorial(ediciones) {
        // Obtener flags desde window, con fallback
        const esSuperAdmin = typeof window.usuarioEsSuperAdmin !== 'undefined'
            ? (window.usuarioEsSuperAdmin === true || window.usuarioEsSuperAdmin === 'true')
            : false;
        const userId = typeof window.usuarioIdActual !== 'undefined'
            ? (window.usuarioIdActual && window.usuarioIdActual !== "null" ? window.usuarioIdActual : null)
            : null;
        const esAdmin = typeof window.usuarioEsAdminInforme !== 'undefined'
            ? (window.usuarioEsAdminInforme === true || window.usuarioEsAdminInforme === 'true')
            : false;

        // DEBUG: mostrar usuario y roles actuales en consola:
        console.log("[Historial Edit] usuarioIdActual:", userId, "esAdmin:", esAdmin, "esSuperAdmin:", esSuperAdmin);

        // Filtrado defensivo del historial según permisos (ajusta lógica según tu backend/API real)
        const filtrados = ediciones.filter(ed => {
            // Chequeo básico de objetos: evitar errores si falta algún campo
            if (!ed || typeof ed !== "object") return false;

            if (esSuperAdmin) {
                // Superadmin ve todo
                return true;
            }
            if (ed.rol === 'superadmin') {
                // No ver las de superadmin si no sos superadmin
                return false;
            }
            if (esAdmin) {
                // Admin: podría ver todo menos superadmin (ajustar si hay restricción de 'sector')
                return true;
            }
            // Vendedor: solo lo propio
            if (userId !== null && (ed.usuario_id == userId || ed.usuario_id === String(userId))) {
                return true;
            }
            return false;
        });

        // DEBUG: cuántos pasan el filtro
        console.log("[Historial Edit] cantidad tras filtrar:", filtrados.length);

        // Actualizar tabla o mensaje 'sin registros'
        const tbody = document.getElementById('edit-history-table-body');
        if (filtrados.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6">No hay historial de ediciones para mostrar.</td></tr>';
            return;
        }

        // Renderizar filas
        tbody.innerHTML = filtrados.map(ed => {
            // Manejo seguro de campos nulos/undefined
            const usuario = ed.usuario ?? 'Desconocido';
            const rol = ed.rol ?? 'Desconocido';
            const accion = ed.accion ?? '';
            const fecha = ed.fecha ?? '';
            const hora = ed.hora ?? '';
            const detalle = ed.detalles_cambios ?? ed.detalle ?? '';

            // (Opcional: sanitizar HTML si vienen tags peligrosos)
            return `
                <tr>
                    <td>${escapeHtml(usuario)}</td>
                    <td>${escapeHtml(rol)}</td>
                    <td>${escapeHtml(accion)}</td>
                    <td>${escapeHtml(fecha)}</td>
                    <td>${escapeHtml(hora)}</td>
                    <td>${escapeHtml(detalle)}</td>
                </tr>
            `;
        }).join('');
    }

    // Se asume que escapeHtml está definido en tu dashboard-informe.js y disponible globalmente. 
    // Si está solo en el archivo JS importado, asegurate que ese <script> se ejecuta ANTES de esta sección, 
    // o agrega aquí una copia sencilla para evitar ReferenceError si sólo usas informe.php puro.

    // Permisos PHP -> JS
    window.usuarioEsSuperAdmin = <?= isSuperAdmin() ? 'true' : 'false' ?>;
    window.usuarioIdActual = <?= json_encode($_SESSION['user_id'] ?? null) ?>;

    document.addEventListener('DOMContentLoaded', () => {
        const historyModal = document.getElementById('edit-history-modal');
        const openBtn = document.getElementById('btnViewEditHistory');
        const closeBtn = document.getElementById('closeEditHistoryModalBtn');
        const tableBody = document.getElementById('edit-history-table-body');

        openBtn.addEventListener('click', () => {
            cargarHistorialEdiciones();
            historyModal.style.display = 'block';
        });
        closeBtn.addEventListener('click', () => historyModal.style.display = 'none');
        window.addEventListener('click', (e) => {
            if (e.target === historyModal) historyModal.style.display = 'none';
        });
    });

    function cargarHistorialEdiciones() {
        // Simulación de endpoint para traer historial real:
        fetch('api/ventas/ediciones_historial.php')
            .then(res => res.json())
            .then(data => {
                renderizarTablaHistorial(data);
            })
            .catch(() => {
                document.getElementById('edit-history-table-body').innerHTML = '<tr><td colspan="6">No se pudo cargar el historial.</td></tr>';
            });
    }

    function renderizarTablaHistorial(ediciones) {
        // Filtros por rol para mostrar solo lo correspondiente
        const esSuperAdmin = window.usuarioEsSuperAdmin;
        const userId = window.usuarioIdActual;
        const esAdmin = window.usuarioEsAdminInforme;

        const filtrados = ediciones.filter(ed => {
            if (esSuperAdmin) {
                // Ve todo
                return true;
            }
            if (ed.rol === 'superadmin') {
                // Admin/vendedor NO ve ediciones de superadmin
                return false;
            }
            // Solo veo mis ediciones y las de otros vendedores/admins
            // (Nunca superadmin)
            // Además admin ve solo las de su sector; adaptarlo según lógica de roles
            return ed.usuario_id == userId || ed.rol === 'admin' || ed.rol === 'vendedor';
        });

        if (filtrados.length === 0) {
            tableBody.innerHTML = '<tr><td colspan="6">No hay ediciones registradas.</td></tr>';
            return;
        }

        tableBody.innerHTML = filtrados.map(ed => `
            <tr>
                <td>${ed.usuario}</td>
                <td>${ed.rol === 'superadmin' ? 'Super Admin' : ed.rol === 'admin' ? 'Admin' : 'Vendedor'}</td>
                <td>${ed.accion}</td>
                <td>${ed.fecha}</td>
                <td>${ed.hora}</td>
                <td>
                    <button type="button" class="btn btn-link" onclick="verDetalleEdicion(${ed.id})">Ver detalle</button>
                </td>
            </tr>
        `).join('');
    }

    // Modal sencillo para mostrar detalles de cambios
    function verDetalleEdicion(idEdicion) {
        fetch('api/ventas/detalle_edicion.php?id=' + encodeURIComponent(idEdicion))
            .then(res => res.json())
            .then(data => {
                alert('Detalle de cambios:\n' + (data.detalle || 'Sin información.'));
            });
    }
    </script>
    </div>

        <section id="seccion-ventas" class="panel sales-history-panel" hidden>
            <div class="sales-history-header">
                <h3>Historial de ventas registradas</h3>
                <span id="salesCount">0 ventas</span>
            </div>
            <?php if (isAdmin()): ?>
            <div class="sales-history-filters">
                <label for="historyWeekSelect">Período</label>
                <select id="historyWeekSelect"></select>
                <label for="historyDateSelect">Día exacto</label>
                <input type="date" id="historyDateSelect">
            </div>
            <?php endif; ?>
            <div class="table-wrapper">
                <table class="sales-table">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Hora</th>
                            <th>Día</th>
                            <th>Productos</th>
                            <th>Total</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="salesHistoryBody"></tbody>
                </table>
            </div>
        </section>

        <div id="seccion-resumen" class="table-wrapper summary-table-wrapper" hidden>
            <table border="1">
                <thead>
                    <tr>
                        <th colspan="10">Resumen de Inventario</th>
                    </tr>
                    <tr>
                        <th>Categoría</th>
                        <th>Producto</th>
                        <th>Código</th>
                        <th>Cantidad vendida</th>
                        <th>Cantidad disponible</th>
                        <th>Precio</th>
                        <th>Total ganancia</th>
                        <th>Fecha</th>
                        <th>Hora</th>
                        <th>Día</th>
                    </tr>
                </thead>
                <tbody id="stockTableBody"></tbody>
            </table>
        </div>
    </section>
    
    <section id="reportPdfContent" class="report-pdf-only" aria-hidden="true"></section>
</main>



<!-- Modal de Edición de Venta -->
<!-- Permite modificar los productos de una venta y recalcular su total antes de persistirla. -->
<div id="editSaleModal" class="modal" style="display: none;">
    <div class="modal-content">
        <span class="close-btn" id="closeModalBtn">&times;</span>
        <h2>✏️ Editar Venta</h2>
        <form id="edit-sale-form">
            <input type="hidden" id="edit-sale-id">
            <div class="form-group-modal">
                <label>Fecha de la Venta:</label>
                <input type="text" id="edit-sale-fecha" readonly class="readonly-input">
            </div>
            <div class="form-group-modal">
                <h3>Productos en la Venta:</h3>
                <div id="edit-sale-products-container">
                    <!-- Filas inyectadas dinámicamente -->
                </div>
            </div>

            <div class="modal-footer">
                <div class="total-monto-modal">
                    <span>TOTAL NUEVO:</span>
                    <strong id="edit-sale-total">$0.00</strong>
                </div>
                <div class="modal-buttons">
                    <button type="submit" class="btn btn-save">✔ Guardar Cambios</button>
                    <button type="button" class="btn btn-delete-sale" id="btnDeleteSale">🗑 Eliminar Venta</button>
                </div>
            </div>
        </form>
    <!-- Historial de edición de ventas -->
    
</div>

<script>
    window.puedeModificarInforme = <?= $puedeModificarInforme ? 'true' : 'false' ?>;
    window.usuarioEsAdminInforme = <?= isAdmin() ? 'true' : 'false' ?>;
</script>
<script src="scrits/dashboard-informe/general.js?v=<?= filemtime(__DIR__ . '/scrits/dashboard-informe/general.js') ?>"></script>
<script src="scrits/dashboard-informe/resumenInventario.js?v=<?= filemtime(__DIR__ . '/scrits/dashboard-informe/resumenInventario.js') ?>"></script>
<script src="scrits/dashboard-informe/ventasHistorial.js?v=<?= filemtime(__DIR__ . '/scrits/dashboard-informe/ventasHistorial.js') ?>"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
