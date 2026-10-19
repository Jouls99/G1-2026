<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// Obtiene el contexto de usuario para resaltar la sección y mostrar identidad/rol en la barra.
$activePage = $activePage ?? '';
$currentUser = getCurrentUser();
$userName = $currentUser['usuario'] ?? 'Invitado';
$userRole = $currentUser['role'] ?? 'vendedor';
$userInitial = strtoupper(substr($userName, 0, 1));
?>
<!-- Navegación compartida: enlaces administrativos y menú responsivo para celulares. -->
<nav class="app-navbar" id="app-navbar">
    <div class="navbar-brand-group">
        <a href="venta.php" class="brand">
            <span>S.O.S cosméticos</span>
        </a>

        <!-- Botón Menú Hamburguesa para Celulares / Pantallas Pequeñas -->
        <button type="button" class="navbar-toggle-btn" id="navbar-toggle-btn" onclick="toggleAppNavbar(event)" aria-label="Abrir menú de navegación" aria-expanded="false" aria-controls="navbar-collapse">
            <span class="toggle-icon-bar"></span>
            <span class="toggle-icon-bar"></span>
            <span class="toggle-icon-bar"></span>
        </button>
    </div>

    <!-- Menú Desplegable de Opciones (se colapsa en móvil, en fila en PC) -->
    <div class="navbar-collapse" id="navbar-collapse">
        <ul class="nav-links">
            <li class="nav-item <?= $activePage === 'ventas' ? 'active' : '' ?>">
                <a href="venta.php">🛒 Ventas</a>
            </li>
            <li class="nav-item <?= $activePage === 'stock' ? 'active' : '' ?>">
                <a href="ControlStock.php">📦 Control de Stock</a>
            </li>
            <li class="nav-item <?= $activePage === 'informe' ? 'active' : '' ?>">
                <a href="informe.php">📊 Informes & Métricas</a>
            </li>
            <?php if (isAdmin()): ?>
            <li class="nav-item <?= $activePage === 'usuarios' ? 'active' : '' ?>">
                <a href="usuarios.php" style="<?= isSuperAdmin() ? 'background: linear-gradient(135deg, rgba(124, 58, 237, 0.2), rgba(217, 119, 6, 0.2)); border: 1px solid #f59e0b; color: #fff;' : '' ?>">
                    <?= isSuperAdmin() ? '👑' : '🛡️' ?> Gestión Usuarios <span class="badge-role-nav" style="background: <?= isSuperAdmin() ? 'linear-gradient(135deg, #f59e0b, #d97706)' : '#4f46e5' ?>; color: #fff; font-size: 0.68rem; padding: 2px 6px; border-radius: 4px; margin-left: 4px; font-weight: 800; letter-spacing: 0.5px;"><?= isSuperAdmin() ? 'SUPER ADMIN' : 'ADMIN' ?></span>
                </a>
            </li>
            <?php endif; ?>
            <?php if (isSuperAdmin()): ?>
            <li class="nav-item <?= $activePage === 'configuracion' ? 'active' : '' ?>">
                <a href="Auditoria.php">⚙️ Auditoría</a>
            </li>
            <?php endif; ?>
            <?php if (isAdmin()): ?>
            <li class="nav-item <?= $activePage === 'permisos' ? 'active' : '' ?>">
                <a href="permisos_vendedores.php">🔐 Permisos de vendedores</a>
            </li>
            <?php endif; ?>
        </ul>

        <div class="user-section">
            <?php if (isLoggedIn()): ?>
                <?php 
                    $roleClass = isSuperAdmin() ? 'role-badge-superadmin' : (isAdmin() ? 'role-badge-admin' : 'role-badge-vendedor');
                    $roleLabel = isSuperAdmin() ? '👑 Super Admin' : (isAdmin() ? '🛡️ Admin' : '🛒 Vendedor');
                ?>
                <div class="user-info" title="Usuario conectado">
                    <span class="user-avatar" style="<?= isSuperAdmin() ? 'background: linear-gradient(135deg, #f59e0b, #7c3aed);' : '' ?>"><?= htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="user-name"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="user-role <?= $roleClass ?>" style="<?= isSuperAdmin() ? 'background: #fef3c7; color: #b45309; border: 1px solid #fcd34d; font-weight: 700;' : '' ?>"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <a href="logout.php" class="btn-logout" title="Cerrar sesión">
                    🚪 Salir
                </a>
            <?php else: ?>
                <a href="registroinicio.php" class="btn-logout" style="background:#4f46e5;color:#fff;border-color:#4f46e5;">
                    🔑 Iniciar Sesión
                </a>
            <?php endif; ?>
        </div>
    </div>
</nav>

<!-- Controlador Interactivo del Menú para Celulares -->
<script>
function toggleAppNavbar(e) {
    if (e) {
        if (typeof e.preventDefault === 'function') e.preventDefault();
        if (typeof e.stopPropagation === 'function') e.stopPropagation();
    }
    const toggleBtn = document.getElementById('navbar-toggle-btn');
    const collapseMenu = document.getElementById('navbar-collapse');
    if (!collapseMenu) return;

    const isOpen = collapseMenu.classList.toggle('show');
    if (toggleBtn) {
        toggleBtn.classList.toggle('active', isOpen);
        toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }
}
window.toggleAppNavbar = toggleAppNavbar;

(function() {
    // Cerrar el menú al tocar fuera de él
    document.addEventListener('click', function(e) {
        const toggleBtn = document.getElementById('navbar-toggle-btn');
        const collapseMenu = document.getElementById('navbar-collapse');
        if (!collapseMenu || !toggleBtn) return;

        if (!collapseMenu.contains(e.target) && !toggleBtn.contains(e.target)) {
            if (collapseMenu.classList.contains('show')) {
                collapseMenu.classList.remove('show');
                toggleBtn.classList.remove('active');
                toggleBtn.setAttribute('aria-expanded', 'false');
            }
        }
    });

    // Cerrar con la tecla Escape
    document.addEventListener('keydown', function(e) {
        const toggleBtn = document.getElementById('navbar-toggle-btn');
        const collapseMenu = document.getElementById('navbar-collapse');
        if (!collapseMenu || !toggleBtn) return;

        if (e.key === 'Escape' && collapseMenu.classList.contains('show')) {
            collapseMenu.classList.remove('show');
            toggleBtn.classList.remove('active');
            toggleBtn.setAttribute('aria-expanded', 'false');
        }
    });
})();
</script>
