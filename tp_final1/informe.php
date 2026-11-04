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
        function cargarHistorialEdiciones() {
            const tbody = document.getElementById('edit-history-table-body');
            if (!tbody) return;
            fetch('api/ventas/ediciones_historial.php')
                .then(res => {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.json();
                })
                .then(data => {
                    if (!Array.isArray(data)) {
                        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:12px; color:#6b7280;">Respuesta inesperada del servidor.</td></tr>';
                        return;
                    }
                    renderizarTablaHistorial(data);
                })
                .catch((e) => {
                    console.error("[Historial Edit] Error cargando historial:", e);
                    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:12px; color:#6b7280;">No se pudo cargar el historial.</td></tr>';
                });
        }

        function renderizarTablaHistorial(ediciones) {
            const tbody = document.getElementById('edit-history-table-body');
            if (!tbody) return;

            const esSuperAdmin = typeof window.usuarioEsSuperAdmin !== 'undefined'
                ? (window.usuarioEsSuperAdmin === true || window.usuarioEsSuperAdmin === 'true')
                : false;
            const userId = typeof window.usuarioIdActual !== 'undefined'
                ? (window.usuarioIdActual && window.usuarioIdActual !== "null" ? window.usuarioIdActual : null)
                : null;
            const esAdmin = typeof window.usuarioEsAdminInforme !== 'undefined'
                ? (window.usuarioEsAdminInforme === true || window.usuarioEsAdminInforme === 'true')
                : false;

            const filtrados = ediciones.filter(ed => {
                if (!ed || typeof ed !== "object") return false;
                if (esSuperAdmin) return true;
                if (ed.rol === 'superadmin') return false;
                if (esAdmin) return true;
                if (userId !== null && (ed.usuario_id == userId || ed.usuario_id === String(userId))) {
                    return true;
                }
                return false;
            });

            if (filtrados.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:12px; color:#6b7280;">No hay historial de ediciones para mostrar.</td></tr>';
                return;
            }

            const esc = typeof window.escapeHtml === 'function' ? window.escapeHtml : (s => String(s ?? ''));

            tbody.innerHTML = filtrados.map(ed => {
                const usuario = ed.usuario ?? 'Desconocido';
                const rol = ed.rol ?? 'Desconocido';
                const accion = ed.accion === 'venta_deshabilitada'
                    ? 'Venta deshabilitada'
                    : (ed.accion === 'venta_modificada'
                        ? 'Venta modificada'
                        : (ed.accion === 'venta_eliminada' ? 'Venta eliminada' : (ed.accion ?? '')));
                const fecha = ed.fecha ?? '';
                const hora = ed.hora ?? '';
                const detalle = ed.detalles_cambios ?? ed.detalle ?? '';

                return `
                    <tr>
                        <td>${esc(usuario)}</td>
                        <td>${esc(rol)}</td>
                        <td>${esc(accion)}</td>
                        <td>${esc(fecha)}</td>
                        <td>${esc(hora)}</td>
                        <td>${esc(detalle)}</td>
                    </tr>
                `;
            }).join('');
        }

        function verDetalleEdicion(idEdicion) {
            fetch('api/ventas/detalle_edicion.php?id=' + encodeURIComponent(idEdicion))
                .then(res => res.json())
                .then(data => {
                    alert('Detalle de cambios:\n' + (data.detalle || 'Sin información.'));
                })
                .catch(() => alert('No se pudo cargar el detalle de la edición.'));
        }

        window.usuarioEsSuperAdmin = <?= isSuperAdmin() ? 'true' : 'false' ?>;
        window.usuarioIdActual = <?= json_encode($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? null)) ?>;

        document.addEventListener('DOMContentLoaded', () => {
            const historyModal = document.getElementById('edit-history-modal');
            const openBtn = document.getElementById('btnViewEditHistory');
            const closeBtn = document.getElementById('closeEditHistoryModalBtn');

            openBtn?.addEventListener('click', () => {
                cargarHistorialEdiciones();
                if (historyModal) historyModal.style.display = 'block';
            });
            closeBtn?.addEventListener('click', () => {
                if (historyModal) historyModal.style.display = 'none';
            });
            window.addEventListener('click', (e) => {
                if (e.target === historyModal) historyModal.style.display = 'none';
            });
        });
        </script>

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

<script>
    window.puedeModificarInforme = <?= $puedeModificarInforme ? 'true' : 'false' ?>;
    window.usuarioEsAdminInforme = <?= isAdmin() ? 'true' : 'false' ?>;
</script>
<script src="scrits/dashboard-informe/general.js?v=<?= filemtime(__DIR__ . '/scrits/dashboard-informe/general.js') ?>"></script>
<script src="scrits/dashboard-informe/resumenInventario.js?v=<?= filemtime(__DIR__ . '/scrits/dashboard-informe/resumenInventario.js') ?>"></script>
<script src="scrits/dashboard-informe/ventasHistorial.js?v=<?= filemtime(__DIR__ . '/scrits/dashboard-informe/ventasHistorial.js') ?>"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
