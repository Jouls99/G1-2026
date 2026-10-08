<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireAdmin('venta.php');

// Ajustes de la plantilla compartida para identificar esta pantalla en la navegación.
$pageTitle = 'Permisos de vendedores';
$customCss = 'css/usuarios.css';
$activePage = 'permisos';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<main class="usuarios-container permisos-container">
    <!-- Presenta el alcance de la herramienta administrativa y el rol de quien la utiliza. -->
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

    <!-- permisos.js consulta los vendedores, renderiza sus permisos y envía los cambios a la API. -->
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

<!-- MODAL DE CONFIRMACIÓN CON CONTRASEÑA -->
<div id="modal-confirmar-permisos" class="modal-overlay" aria-hidden="true">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h3>🔐 Confirmar delegación de privilegios</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Cerrar">×</button>
        </div>

        <form id="form-confirmar-permisos">
            <div style="padding: 20px 24px;">
                <p style="margin: 0 0 12px; font-size: 0.95rem; color: #374151;">
                    Estás a punto de actualizar los privilegios de <strong id="modal-target-user-name">vendedor</strong>:
                </p>
                <div id="modal-permissions-summary" style="margin: 0 0 18px; padding: 12px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: var(--radius-md); font-size: 0.88rem; color: #334155; line-height: 1.6;">
                </div>
                
                <div class="field-group" style="margin: 0;">
                    <label for="admin-confirm-password">Contraseña de Administrador</label>
                    <div style="position: relative; display: flex; align-items: center;">
                        <input id="admin-confirm-password" type="password" name="password" placeholder="Ingresá tu contraseña de inicio de sesión" required autocomplete="current-password" style="padding-right: 42px;">
                        <button type="button" id="btn-toggle-password" style="position: absolute; right: 10px; background: none; border: none; cursor: pointer; color: #6b7280; font-size: 1.1rem; padding: 4px;" aria-label="Mostrar/Ocultar contraseña" title="Mostrar/Ocultar contraseña">👁️</button>
                    </div>
                    <small style="color: #6b7280; display: block; margin-top: 6px;">Por seguridad, confirmá con la misma contraseña con la que iniciaste sesión.</small>
                </div>

                <p id="modal-error-message" class="permissions-message error" style="margin-top: 14px; display: none; margin-bottom: 0;" role="alert"></p>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-modal-close>Cancelar</button>
                <button type="submit" class="btn-primary" id="btn-confirm-save">💾 Confirmar y Guardar</button>
            </div>
        </form>
    </div>
</div>

<script src="scrits/permisos.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>