<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

$activePage = $activePage ?? '';
$currentUser = getCurrentUser();
$userName = $currentUser['usuario'] ?? 'Invitado';
$userRole = $currentUser['role'] ?? 'vendedor';
$userInitial = strtoupper(substr($userName, 0, 1));
?>
<nav class="app-navbar">
    <a href="prueba2.php" class="brand">
        <span>💄 Cosmética & Ventas</span>
        <span class="brand-badge">PHP</span>
    </a>

    <ul class="nav-links">
        <li class="nav-item <?= $activePage === 'ventas' ? 'active' : '' ?>">
            <a href="prueba2.php">🛒 Ventas (POS)</a>
        </li>
        <li class="nav-item <?= $activePage === 'stock' ? 'active' : '' ?>">
            <a href="ControlStock.php">📦 Control de Stock</a>
        </li>
        <li class="nav-item <?= $activePage === 'informe' ? 'active' : '' ?>">
            <a href="informe.php">📊 Informes & Métricas</a>
        </li>
        <?php if (isAdmin()): ?>
        <li class="nav-item <?= $activePage === 'usuarios' ? 'active' : '' ?>">
            <a href="usuarios.php" style="background: rgba(124, 58, 237, 0.25); border: 1px solid rgba(167, 139, 250, 0.4);">
                👥 Gestión Usuarios <span style="background: #7c3aed; color: #fff; font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; margin-left: 4px; font-weight: bold;">ADMIN</span>
            </a>
        </li>
        <?php endif; ?>
    </ul>

    <div class="user-section">
        <?php if (isLoggedIn()): ?>
            <div class="user-info" title="Usuario conectado">
                <span class="user-avatar"><?= htmlspecialchars($userInitial, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="user-name"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
                <span class="user-role"><?= htmlspecialchars($userRole, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <a href="logout.php" class="btn-logout" title="Cerrar sesión segura de PHP">
                🚪 Salir
            </a>
        <?php else: ?>
            <a href="registroinicio.php" class="btn-logout" style="background:#4f46e5;color:#fff;border-color:#4f46e5;">
                🔑 Iniciar Sesión
            </a>
        <?php endif; ?>
    </div>
</nav>
