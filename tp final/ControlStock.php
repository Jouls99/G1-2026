<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Proteger vista: requiere sesión activa
requireAuth('registroinicio.php');

$pageTitle = 'Control de Stock e Inventario';
$customCss = 'css/panelstock.css';
$activePage = 'stock';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div style="max-width: 1200px; margin: 20px auto 10px; padding: 0 15px;">
    <h1>📦 Panel de Gestión y Control de Stock</h1>
    <input type="text" id="globalSearch" class="search-box" placeholder="🔍 Buscar producto por nombre o código...">

    <div class="dashboard">
        <div class="sidebar">
            <h3>Categorías</h3>
            <div id="categoriesContainer"></div>
            <hr style="margin: 15px 0; border: 0; border-top: 1px solid #e5e7eb;">
            <label for="newCatName" style="font-weight: bold; font-size: 0.9rem; color: #6b21a8;">Nueva categoría</label>
            <input type="text" id="newCatName" placeholder="Ej: Fragancias">
            <button class="btn btn-success" type="button" onclick="addCategory()">+ Agregar</button>
            <button class="btn btn-export" type="button" onclick="exportarJSON()">⬇ Exportar JSON</button>
            <br>
            <a href="prueba2.php" class="ver-stock" style="display:block; width:100%; box-sizing:border-box; margin-top:8px;">← Volver a Ventas</a>
        </div>

        <div class="content" id="mainContent">
            <h3>Selecciona una categoría para ver el stock</h3>
        </div>

        <aside class="detail-panel" id="detailPanel">
            <h3>Detalle del producto</h3>
            <p>Haz clic en un producto para ver información y subcategorías aquí.</p>
            <button class="btn-reporte" type="button" id="btnReporte" style="display:none;">Generar Reporte</button>
            <div style="margin-top: 20px;">
                <a href="informe.php" class="ver-stock" style="display:block; width:100%; box-sizing:border-box; background:#f5e8ff; border-color:#7c3aed; color:#7c3aed; font-weight:bold;">📊 Ver Informe y Gráficos</a>
            </div>
        </aside>
    </div>
</div>

<script src="scrits/panelGestión.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
