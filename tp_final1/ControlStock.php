<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Requiere sesión activa
requireAuth('registroinicio.php');

$soloLectura = !isAdmin();
$puedeCargarStock = canRegisterStock();

$pageTitle = 'Control de Stock e Inventario';
$customCss = 'css/panelstock.css';
$activePage = 'stock';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<div style="max-width: 1200px; margin: 20px auto 10px; padding: 0 15px;">
    <header class="stock-page-header">
        <div>
            <h1>📦 Panel de Gestión y Control de Stock</h1>
            <p class="stock-page-subtitle">Inventario, categorías y productos del sistema.</p>
        </div>
        <div class="stock-page-actions">
            <a href="venta.php" class="stock-nav-btn">🛒 Ir a Ventas</a>
            <a href="informe.php" class="stock-nav-btn">📊 Ver informe</a>
            <?php if (isSuperAdmin()): ?>
            <a href="usuarios.php" class="stock-nav-btn stock-nav-btn-admin">👑 Gestión de Usuarios</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($puedeCargarStock): ?>
    <section class="product-form stock-load-form">
        <h2>Cargar producto</h2>
        <form id="stockProductForm" onsubmit="event.preventDefault(); addProduct();">
            <div class="form-grid">
                <div>
                    <label for="prodName">Nombre</label>
                    <input id="prodName" required placeholder="Ej: Labial Matte">
                </div>
                <div>
                    <label for="prodCode">Código</label>
                    <input id="prodCode" required placeholder="Ej: LBL-001">
                </div>
                <div>
                    <label for="prodPrice">Precio</label>
                    <input id="prodPrice" type="number" min="0" step="0.01" required placeholder="0.00">
                </div>
                <div>
                    <label for="prodStock">Stock</label>
                    <input id="prodStock" type="number" min="0" required placeholder="0">
                </div>
                <div>
                    <label for="prodSub">Subcategoría</label>
                    <input id="prodSub" placeholder="Ej: Labios">
                </div>
                <div>
                    <label for="prodCat">Categoría</label>
                    <select id="prodCat" required></select>
                </div>
            </div>
            <button class="btn btn-success" type="submit">+ Cargar producto</button>
        </form>
    </section>
    <?php endif; ?>

    <div class="stock-entry-actions">
        <button type="button" class="stock-nav-btn" id="btn-ver-stock">📦 Ver stock por categoría</button>
        <?php if (isSuperAdmin()): ?>
        <button type="button" class="stock-nav-btn stock-nav-btn-admin" id="btn-auditoria-stock">🛡️ Auditoría de estados</button>
        <?php endif; ?>
    </div>

    <section id="stock-empty-state" class="stock-empty-state">
        <h2>Stock separado por categorías</h2>
        <p>Seleccioná “Ver stock por categoría” para consultar los productos.</p>
    </section>

    <div id="stock-workspace" hidden>
    <input type="text" id="globalSearch" class="search-box" placeholder="🔍 Buscar producto por nombre o código...">

    <div class="dashboard">
        <div class="sidebar">
            <h3>Categorías</h3>
            <label for="categoryFilter" class="category-filter-label">Filtrar categoría</label>
            <select id="categoryFilter" class="category-filter">
                <option value="">Todas las categorías</option>
            </select>
            <div id="categoriesContainer"></div>
            <hr style="margin: 15px 0; border: 0; border-top: 1px solid #e5e7eb;">
            <?php if (!$soloLectura): ?>
            <label for="newCatName" style="font-weight: bold; font-size: 0.9rem; color: #6b21a8;">Nueva categoría</label>
            <input type="text" id="newCatName" placeholder="Ej: Fragancias">
            <button class="btn btn-success" type="button" onclick="addCategory()">+ Agregar</button>
            <?php endif; ?>
            <button class="btn btn-export" type="button" onclick="exportarJSON()">⬇ Exportar JSON</button>
            <br>
            <a href="venta.php" class="ver-stock" style="display:block; width:100%; box-sizing:border-box; margin-top:8px;">← Volver a Ventas</a>
            <?php if (isSuperAdmin()): ?>
            <a href="usuarios.php" class="ver-stock" style="display:block; width:100%; box-sizing:border-box; margin-top:8px; background:#fef3c7; border-color:#f59e0b; color:#b45309; font-weight:bold;">👑 Ir a Gestión de Usuarios</a>
            <?php endif; ?>
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
                <?php if (isSuperAdmin()): ?>
                <a href="usuarios.php" class="ver-stock" style="display:block; width:100%; box-sizing:border-box; margin-top:8px; background:#fef3c7; border-color:#f59e0b; color:#b45309; font-weight:bold;">👑 Administración de usuarios</a>
                <?php endif; ?>
            </div>
        </aside>
    </div>
    </div>
</div>

<?php if (isSuperAdmin()): ?>
<section id="auditoria-stock" class="audit-panel" hidden>
    <div class="audit-panel-header">
        <div>
            <h2>Auditoría de estados de productos</h2>
            <p>Historial de productos habilitados y deshabilitados.</p>
        </div>
        <button type="button" class="stock-nav-btn" id="btn-cerrar-auditoria">Cerrar auditoría</button>
    </div>
    <div id="auditoria-stock-content" class="audit-table-wrap">Cargando historial...</div>
</section>
<?php endif; ?>

<!-- MODAL DE CONFIRMACIÓN PARA ELIMINAR PRODUCTO -->
<div id="modal-confirmar-eliminar" class="modal-overlay" aria-hidden="true">
    <div class="modal-box">
        <div class="modal-header-danger">
            <span class="modal-icon-warn">⚠️</span>
            <h3>Confirmar Eliminación</h3>
        </div>
        <div class="modal-body-content">
            <p style="font-size: 1rem; color: #1f2937; margin-bottom: 12px;">
                ¿Estás seguro de que deseás eliminar este producto?
            </p>
            <div class="product-to-delete-card">
                <div id="modal-prod-nombre" class="prod-delete-title">Nombre del Producto</div>
                <div class="prod-delete-meta">
                    <span>Código: <strong id="modal-prod-codigo">COD-000</strong></span>
                    <span>Categoría: <strong id="modal-prod-categoria">General</strong></span>
                </div>
            </div>
        </div>
        <div class="modal-actions-bar">
            <button type="button" class="btn-cancel" id="btn-cancelar-eliminar">↩️ Cancelar</button>
            <button type="button" class="btn-danger-confirm" id="btn-confirmar-eliminar">🗑️ Sí, eliminar producto</button>
        </div>
    </div>
</div>

<script>
    window.usuarioEsAdmin = <?= $soloLectura ? 'false' : 'true' ?>;
    window.usuarioPuedeRegistrarStock = <?= $puedeCargarStock ? 'true' : 'false' ?>;
    window.usuarioEsSuperAdmin = <?= isSuperAdmin() ? 'true' : 'false' ?>;
</script>
<script src="scrits/panelGestión.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
