<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireAuth('registroinicio.php');

if (!isAdmin()) {
    header('Location: prueba2.php?error=unauthorized');
    exit;
}

$pageTitle = 'Gestión de Usuarios';
$customCss = 'css/usuarios.css';
$activePage = 'usuarios';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<main class="usuarios-container">
    <header class="usuarios-header">
        <div class="title-area">
            <h1>👥 Gestión de Usuarios</h1>
            <p>Administración de cuentas, roles y seguimiento de actividad del sistema.</p>
        </div>

        <div class="header-actions">
            <span class="admin-security-badge">🛡️ Administrador</span>
            <button type="button" class="btn-primary" onclick="abrirModalNuevoUsuario()">＋ Nuevo usuario</button>
        </div>
    </header>

    <section class="kpi-grid" aria-label="Métricas del sistema">
        <article class="kpi-card">
            <div class="kpi-icon purple">👤</div>
            <div class="kpi-data">
                <span class="kpi-label">Total usuarios</span>
                <span class="kpi-value" id="kpi-total-users">0</span>
            </div>
        </article>

        <article class="kpi-card">
            <div class="kpi-icon indigo">🛡️</div>
            <div class="kpi-data">
                <span class="kpi-label">Administradores</span>
                <span class="kpi-value" id="kpi-admins">0</span>
            </div>
        </article>

        <article class="kpi-card">
            <div class="kpi-icon emerald">🛒</div>
            <div class="kpi-data">
                <span class="kpi-label">Vendedores</span>
                <span class="kpi-value" id="kpi-vendedores">0</span>
            </div>
        </article>

        <article class="kpi-card">
            <div class="kpi-icon amber">⚡</div>
            <div class="kpi-data">
                <span class="kpi-label">Interacciones</span>
                <span class="kpi-value" id="kpi-interacciones">0</span>
            </div>
        </article>

        <article class="kpi-card">
            <div class="kpi-icon rose">💰</div>
            <div class="kpi-data">
                <span class="kpi-label">Ventas totales</span>
                <span class="kpi-value" id="kpi-ventas-total">$0</span>
            </div>
        </article>
    </section>

    <div class="tabs-container" role="tablist" aria-label="Paneles de administración">
        <button type="button" class="tab-btn active" data-tab="tab-usuarios" role="tab" aria-selected="true">👥 Usuarios</button>
        <button type="button" class="tab-btn" data-tab="tab-actividades" role="tab" aria-selected="false">⚡ Actividades</button>
    </div>

    <section id="tab-usuarios" class="tab-content active" role="tabpanel">
        <div class="panel-layout">
            <div class="filters-card">
                <div class="search-box">
                    <label for="search-user">Buscar usuario</label>
                    <input id="search-user" type="search" placeholder="Nombre de usuario..." autocomplete="off">
                </div>

                <div class="chip-group" aria-label="Filtrar por rol">
                    <button type="button" class="chip-filter active" data-filter-role="todos">Todos</button>
                    <button type="button" class="chip-filter" data-filter-role="administrador">Administradores</button>
                    <button type="button" class="chip-filter" data-filter-role="vendedor">Vendedores</button>
                </div>
            </div>

            <div class="table-card">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Rol</th>
                            <th>Cambiar rol</th>
                            <th>Creado</th>
                            <th>Último acceso</th>
                            <th>Ventas</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-usuarios">
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 32px; color: #6b7280;">
                                Cargando usuarios...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section id="tab-actividades" class="tab-content" role="tabpanel" aria-hidden="true">
        <div class="panel-layout">
            <div class="filters-card">
                <div class="search-box">
                    <label for="search-activity">Buscar actividad</label>
                    <input id="search-activity" type="search" placeholder="Usuario o descripción..." autocomplete="off">
                </div>

                <div class="chip-group" aria-label="Filtrar actividades">
                    <button type="button" class="chip-filter active" data-filter-act="todas">Todas</button>
                    <button type="button" class="chip-filter" data-filter-act="venta">Ventas</button>
                    <button type="button" class="chip-filter" data-filter-act="login">Login</button>
                    <button type="button" class="chip-filter" data-filter-act="rol">Roles</button>
                    <button type="button" class="chip-filter" data-filter-act="stock">Stock</button>
                </div>
            </div>

            <div class="activity-card">
                <div id="activity-stream">
                    <div style="text-align:center; padding: 36px; color: #6b7280;">Cargando historial de interacciones...</div>
                </div>
            </div>
        </div>
    </section>
</main>

<div id="modal-nuevo-usuario" class="modal-overlay" aria-hidden="true">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h3>Crear nuevo usuario</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Cerrar">×</button>
        </div>

        <form id="form-nuevo-usuario">
            <div class="field-group">
                <label for="new-username">Usuario</label>
                <input id="new-username" type="text" name="usuario" placeholder="Ej: vendedora01" required>
            </div>

            <div class="field-group">
                <label for="new-password">Contraseña</label>
                <input id="new-password" type="password" name="password" placeholder="Asignar contraseña" required>
            </div>

            <div class="field-group">
                <label>Rol del usuario</label>
                <div class="role-picker">
                    <label class="role-radio-card selected">
                        <input type="radio" name="new-role" id="role-new-vendedor" value="vendedor" checked>
                        <span>🛒 Vendedor</span>
                    </label>
                    <label class="role-radio-card">
                        <input type="radio" name="new-role" id="role-new-admin" value="administrador">
                        <span>🛡️ Administrador</span>
                    </label>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-modal-close>Cancelar</button>
                <button type="submit" class="btn-primary">Guardar usuario</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-editar-usuario" class="modal-overlay" aria-hidden="true">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h3>Editar usuario</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Cerrar">×</button>
        </div>

        <form id="form-editar-usuario">
            <input id="edit-user-id" type="hidden" name="id">

            <div class="field-group">
                <label for="edit-username">Usuario</label>
                <input id="edit-username" type="text" name="usuario" placeholder="Nombre de usuario" required>
            </div>

            <div class="field-group">
                <label for="edit-password">Nueva contraseña</label>
                <input id="edit-password" type="password" name="password" placeholder="Dejar vacío para no cambiarla">
            </div>

            <div class="field-group">
                <label>Rol</label>
                <div class="role-picker">
                    <label class="role-radio-card">
                        <input type="radio" name="edit-role" id="role-edit-vendedor" value="vendedor" checked>
                        <span>🛒 Vendedor</span>
                    </label>
                    <label class="role-radio-card">
                        <input type="radio" name="edit-role" id="role-edit-admin" value="administrador">
                        <span>🛡️ Administrador</span>
                    </label>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-modal-close>Cancelar</button>
                <button type="submit" class="btn-primary">Actualizar</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-detalle-usuario" class="modal-overlay large" aria-hidden="true">
    <div class="modal-content modal-lg">
        <div class="modal-header">
            <h3 id="detalle-usuario-title">Detalle del usuario</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Cerrar">×</button>
        </div>

        <div id="detalle-usuario-body" class="modal-body-scroll">
            <div style="text-align:center; padding: 32px; color: #6b7280;">Seleccioná un usuario para ver su detalle.</div>
        </div>
    </div>
</div>

<div id="toast-notification" class="toast-notification" aria-live="polite">
    <span id="toast-message">Acción realizada.</span>
</div>

<script src="scrits/usuarios.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
