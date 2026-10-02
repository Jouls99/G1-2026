<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireAdmin('venta.php');

$pageTitle = 'Permisos de vendedores';
$customCss = 'css/usuarios.css';
$activePage = 'permisos';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<main class="usuarios-container permisos-container">
    <header class="usuarios-header permisos-header">
        <div class="title-area">
            <h1>🔐 Permisos de vendedores</h1>
            <p>Asignación de tareas habilitadas para las cuentas de vendedores.</p>
        </div>
        <div class="header-actions">
            <span class="admin-security-badge">🛡️ <?= isSuperAdmin() ? 'Super Administrador' : 'Administrador' ?></span>
            <a href="venta.php" class="btn-secondary">🛒 Volver a ventas</a>
        </div>
    </header>

    <section class="panel-layout">
        <div class="table-card">
            <div class="permissions-table-scroll">
        <table class="users-table permissions-table">
            <thead>
                <tr>
                    <th scope="col">Vendedor</th>
                    <th scope="col">Registrar productos en stock</th>
                    <th scope="col">Modificar ventas en informes</th>
                    <th scope="col">Acciones</th>
                </tr>
            </thead>
            <tbody id="seller-permissions-body">
                <tr><td colspan="4" class="permissions-empty">Cargando vendedores...</td></tr>
            </tbody>
        </table>
            </div>
        </div>
        <p id="permissions-message" class="permissions-message" role="status" aria-live="polite"></p>
    </section>
</main>

<script src="scrits/permisos.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>