<?php
/**
 * Barra de navegación principal
 */
$currentUser = current_user();
$currentRole = current_role();
$activeTab = $activeTab ?? '';
?>
<nav class="app-navbar">
    <div class="nav-brand">
        <a href="index.php" class="brand-link">
            <span class="brand-icon">💄</span>
            <span class="brand-name">Cosmética & Ventas</span>
        </a>
    </div>

    <?php if ($currentUser): ?>
    <div class="nav-links">
        <a href="index.php" class="nav-item <?= $activeTab === 'ventas' ? 'active' : '' ?>">
            <span class="nav-icon">🛒</span> Panel de Ventas
        </a>
        <a href="control_stock.php" class="nav-item <?= $activeTab === 'stock' ? 'active' : '' ?>">
            <span class="nav-icon">📦</span> Control de Stock
        </a>
        <a href="informe.php" class="nav-item <?= $activeTab === 'informe' ? 'active' : '' ?>">
            <span class="nav-icon">📊</span> Informes & Dashboard
        </a>
    </div>
    <?php endif; ?>

    <div class="nav-user-actions">
        <?php if ($currentUser): ?>
            <div class="user-badge" title="Rol: <?= htmlspecialchars($currentRole) ?>">
                <span class="user-avatar">👤</span>
                <span class="user-name"><?= htmlspecialchars($currentUser) ?></span>
                <span class="user-role-tag"><?= htmlspecialchars($currentRole) ?></span>
            </div>
            <a href="logout.php" class="btn-logout" title="Cerrar sesión">🚪 Salir</a>
        <?php else: ?>
            <a href="login.php" class="btn-login-link">🔑 Iniciar Sesión</a>
        <?php endif; ?>
    </div>
</nav>
