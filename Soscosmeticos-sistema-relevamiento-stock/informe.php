<?php
// Protege el informe y configura sus recursos; informe.js alimenta los resúmenes, gráficos e historial.
require_once __DIR__ . '/includes/session.php';
require_auth('login.php');

$pageTitle = 'Informe y Estadísticas de Stock';
$activeTab = 'informe';
$extraCss = ['css/estadisticadasboard.css'];
$extraJs = ['assets/js/informe.js'];

require_once __DIR__ . '/includes/header.php';
?>

<!-- Encabezado y tabla consolidada: sus identificadores son los destinos del reloj y del resumen por producto. -->
<div class="informe-page-container" style="max-width: 1300px; margin: 0 auto; padding: 24px; width: 100%;">
    <header>
        <div class="header-top">
            <h1>📊 Informe y Estadísticas de Stock</h1>
            <div class="clock-card" aria-live="polite">
                <span id="liveDate">--</span>
                <strong id="liveTime">--:--:--</strong>
                <small id="liveDay">--</small>
            </div>
        </div>
        <p style="color: #64748b; margin: 8px 0 16px 0;">Este informe muestra ventas, ganancias y resúmenes consolidados por categoría y periodo.</p>
        
        <div class="table-wrapper">
            <table border="1">
                <thead>
                    <tr>
                        <th colspan="10" style="background: #2d112c; color: #fff; font-size: 1rem; text-align: left; padding: 10px 14px;">Resumen Consolidado por Producto</th>
                    </tr>
                    <tr>
                        <th>Categoría</th>
                        <th>Producto</th>
                        <th>Código</th>
                        <th>Cant. Vendida</th>
                        <th>Cant. Disponible</th>
                        <th>Precio</th>
                        <th>Total Ganancia</th>
                        <th>Última Fecha</th>
                        <th>Hora</th>
                        <th>Día</th>
                    </tr>
                </thead>
                <tbody id="stockTableBody"></tbody>
            </table>
        </div>
    </header>

    <!-- Dashboard de métricas, filtros y ventas; el JavaScript reemplaza estos contenedores con datos actuales. -->
    <main style="margin-top: 30px;">
        <section id="dashboard-informe">
            <div class="dashboard-header">
                <h2>📈 Dashboard Dinámico de Rendimiento</h2>
                <p>Seleccioná una categoría y un periodo para filtrar métricas y gráficos en tiempo real.</p>
            </div>

            <div class="dashboard-controls">
                <div>
                    <h3>Filtrar por Categoría</h3>
                    <div id="categoryButtons" class="chip-group"></div>
                </div>
                <div>
                    <h3>Filtrar por Periodo</h3>
                    <div id="periodButtons" class="chip-group"></div>
                </div>
            </div>

            <div class="metrics-grid">
                <article class="metric-card">
                    <span>Productos Vendidos</span>
                    <strong id="metricSold">0</strong>
                </article>
                <article class="metric-card">
                    <span>Ganancia en Ventas</span>
                    <strong id="metricRevenue">$0.00</strong>
                </article>
                <article class="metric-card">
                    <span>Valor en Inventario</span>
                    <strong id="metricSummary">$0.00</strong>
                </article>
            </div>

            <div class="dashboard-layout">
                <section class="panel">
                    <h3 id="dashboardTitle">Evolución de Ventas vs Ganancia</h3>
                    <svg id="lineChart" class="line-chart" viewBox="0 0 640 240"></svg>
                    <div class="legend">
                        <span><i class="dot sold"></i> Unidades Vendidas</span>
                        <span><i class="dot revenue"></i> Ganancia ($)</span>
                    </div>
                </section>

                <aside class="panel">
                    <h3>Productos en la Categoría</h3>
                    <ul id="productList" class="product-list"></ul>
                </aside>
            </div>

            <section class="panel sales-history-panel" style="margin-top: 24px;">
                <div class="sales-history-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h3>Historial de Ventas Registradas</h3>
                    <span id="salesCount" style="background: #e0316d; color: white; padding: 4px 12px; border-radius: 12px; font-size: 0.85rem; font-weight: 600;">0 ventas</span>
                </div>
                <div class="table-wrapper">
                    <table class="sales-table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Hora</th>
                                <th>Día</th>
                                <th>Productos</th>
                                <th style="text-align: right;">Total</th>
                                <th style="text-align: center;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="salesHistoryBody"></tbody>
                    </table>
                </div>
            </section>
        </section>
    </main>

    <!-- Modal de Edición de Venta -->
    <!-- Modal usado por el historial para modificar cantidades y guardar o eliminar una venta. -->
    <div id="editSaleModal" class="modal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; justify-content: center; align-items: center;">
        <div class="modal-content" style="background: white; border-radius: 12px; max-width: 550px; width: 90%; padding: 24px; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.3);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h2 style="margin: 0; font-size: 1.4rem; color: #2d112c;">✏️ Editar Venta</h2>
                <span class="close-btn" id="closeModalBtn" style="font-size: 1.8rem; cursor: pointer; color: #94a3b8;">&times;</span>
            </div>
            
            <form id="edit-sale-form">
                <input type="hidden" id="edit-sale-id">
                
                <div class="form-group-modal" style="margin-bottom: 16px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 6px;">Fecha y Hora Original:</label>
                    <input type="text" id="edit-sale-fecha" readonly class="readonly-input" style="width: 100%; padding: 8px 12px; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px;">
                </div>
                
                <div class="form-group-modal" style="margin-bottom: 16px;">
                    <h3 style="font-size: 1.05rem; margin-bottom: 10px;">Productos en la Venta:</h3>
                    <div id="edit-sale-products-container" style="display: flex; flex-direction: column; gap: 12px;">
                        <!-- Filas inyectadas dinámicamente -->
                    </div>
                </div>

                <div class="modal-footer" style="border-top: 1px solid #e2e8f0; padding-top: 16px; margin-top: 16px;">
                    <div class="total-monto-modal" style="margin-bottom: 16px; font-size: 1.1rem; display: flex; justify-content: space-between;">
                        <span>TOTAL RECALCULADO:</span>
                        <strong id="edit-sale-total" style="color: #e0316d;">$0.00</strong>
                    </div>
                    <div class="modal-buttons" style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="button" class="btn btn-delete-sale" id="btnDeleteSale" style="padding: 10px 16px; background: #fee2e2; color: #dc2626; border: 1px solid #fca5a5; border-radius: 8px; font-weight: 600; cursor: pointer;">🗑 Eliminar Venta</button>
                        <button type="submit" class="btn btn-save" style="padding: 10px 18px; background: #16a34a; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">✔ Guardar Cambios</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
