<?php
// Restringe el panel a usuarios autenticados y prepara los recursos propios de esta vista.
require_once __DIR__ . '/includes/session.php';
require_auth('login.php');

$pageTitle = 'Control de Stock e Inventario';
$activeTab = 'stock';
$extraCss = ['css/panelstock.css'];
$extraJs = ['assets/js/stock.js'];

require_once __DIR__ . '/includes/header.php';
?>

<!-- Panel de inventario: reúne búsqueda, categorías, productos y sus detalles; stock.js conecta estos contenedores con la API. -->
<main class="container-fluid" style="padding: 24px; max-width: 1300px; margin: 0 auto; width: 100%;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h1 style="color: #2d112c; font-size: 26px; font-weight: 700; margin: 0;">📦 Panel de Gestión y Control de Stock</h1>
        <div style="display: flex; gap: 10px;">
            <a href="index.php" style="padding: 8px 16px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; color: #334155; text-decoration: none; font-weight: 600;">
                ⬅ Ir a Ventas
            </a>
            <a href="informe.php" style="padding: 8px 16px; background: #e0316d; color: #fff; border-radius: 8px; text-decoration: none; font-weight: 600;">
                📊 Ver Informe
            </a>
        </div>
    </div>
    
    <input type="text" id="globalSearch" class="search-box" placeholder="🔍 Buscar producto por nombre o código..." style="margin-bottom: 20px; width: 100%; padding: 12px 16px; border: 1px solid #cbd5e1; border-radius: 10px; font-size: 1rem;">

    <!-- Navegación y acciones de categorías a la izquierda; tabla y detalle se renderizan dinámicamente en los paneles restantes. -->
    <div class="dashboard">
        <div class="sidebar">
            <h3>Categorías</h3>
            <div id="categoriesContainer"></div>
            <hr style="margin: 15px 0; border: none; border-top: 1px solid #e2e8f0;">
            <label for="newCatName" style="font-weight: 600; font-size: 0.9rem;">Nueva categoría</label>
            <input type="text" id="newCatName" placeholder="Ej: Fragancias" style="margin: 8px 0; width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px;">
            <button class="btn btn-success" type="button" onclick="addCategory()" style="width: 100%; margin-bottom: 8px;">+ Agregar Categoría</button>
            <button class="btn btn-export" type="button" onclick="exportarJSON()" style="width: 100%;">⬇ Exportar JSON</button>
        </div>

        <div class="content" id="mainContent">
            <h3>Selecciona una categoría para ver el stock</h3>
        </div>

        <aside class="detail-panel" id="detailPanel">
            <h3>Detalle del producto</h3>
            <p>Haz clic en un producto para ver información y subcategorías aquí.</p>
        </aside>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
