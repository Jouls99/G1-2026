<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Proteger vista: requiere sesión activa
requireAuth('registroinicio.php');

$pageTitle = 'Informe de Stock y Métricas';
$customCss = 'css/estadisticadasboard.css';
$activePage = 'informe';
$puedeModificarInforme = canModifyReports();

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<main class="report-container">
    <header class="report-header">
        <div class="title-area">
            <h1>📊 Informe de Stock y Ventas</h1>
            <p>Este informe muestra ventas, ganancias y resúmenes por categoría y periodo.</p>
        </div>

        <div class="header-actions">
            <a href="ControlStock.php" class="btn-secondary">📦 Control de Stock</a>
            <a href="venta.php" class="btn-secondary">🛒 Ir a Ventas</a>
            <button type="button" class="btn-secondary" id="openSwitchUserBtn">🔄 Cambiar usuario</button>
            <?php if (isSuperAdmin()): ?>
                <a href="usuarios.php" class="btn-secondary" style="background:#fef3c7; border-color:#f59e0b; color:#b45309; font-weight:bold;">👑 Gestión de Usuarios</a>
            <?php endif; ?>
        </div>
    </header>

    <section id="dashboard-informe">
        <div class="dashboard-header">
            <h2>Dashboard dinámico</h2>
            <p>Seleccioná una categoría y un periodo para ver el comportamiento del inventario.</p>
        </div>

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

        <section class="panel sales-history-panel">
            <div class="sales-history-header">
                <h3>Historial de ventas registradas</h3>
                <span id="salesCount">0 ventas</span>
            </div>
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

        <div class="table-wrapper summary-table-wrapper">
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
</main>

<div id="switchUserModal" class="modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="switchUserTitle">
    <div class="modal-content switch-user-modal-content">
        <button type="button" class="close-btn" id="closeSwitchUserBtn" aria-label="Cerrar">&times;</button>
        <h2 id="switchUserTitle">🔄 Cambiar usuario</h2>
        <p class="switch-user-description">Ingresá el nombre y la contraseña de la cuenta a la que querés cambiar.</p>
        <form id="switchUserForm">
            <div class="form-group-modal">
                <label for="switchUserName">Nombre de usuario</label>
                <input type="text" id="switchUserName" class="switch-user-input" required autocomplete="username">
            </div>
            <div class="form-group-modal">
                <label for="switchUserPassword">Contraseña</label>
                <input type="password" id="switchUserPassword" class="switch-user-input" required autocomplete="current-password">
            </div>
            <p id="switchUserMessage" class="switch-user-message" role="alert" aria-live="polite"></p>
            <div class="modal-buttons">
                <button type="submit" class="btn btn-save" id="switchUserSubmit">Ingresar</button>
                <button type="button" class="btn btn-delete-sale" id="cancelSwitchUserBtn">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de Edición de Venta -->
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
    </div>
</div>

<script>
    window.puedeModificarInforme = <?= $puedeModificarInforme ? 'true' : 'false' ?>;
    window.usuarioEsAdminInforme = <?= isAdmin() ? 'true' : 'false' ?>;
</script>
<script src="scrits/dashboard-informe.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
