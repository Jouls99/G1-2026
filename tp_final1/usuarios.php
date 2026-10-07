<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$esSuperAdmin = isSuperAdmin();
requireAdmin('venta.php');

$pageTitle = $esSuperAdmin ? 'Consola Super Admin - Gestión de Usuarios y Logins' : 'Gestión de Usuarios y Actividades';
$customCss = 'css/usuarios.css';
$activePage = 'usuarios';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<main class="usuarios-container">
    <header class="usuarios-header superadmin-header">
        <div class="title-area">
            <h1><?= $esSuperAdmin ? '👑 Consola Super Admin' : '🛡️ Gestión de Usuarios' ?></h1>
            <p><?= $esSuperAdmin ? 'Control central de cuentas, asignación de roles y monitoreo en tiempo real de inicios de sesión de usuarios.' : 'Administración de cuentas y consulta del registro de actividades del sistema.' ?></p>
        </div>

        <div class="header-actions">
            <span class="admin-security-badge <?= $esSuperAdmin ? 'superadmin-badge' : '' ?>"><?= $esSuperAdmin ? '👑 Super Administrador' : '🛡️ Administrador' ?></span>
            <a href="ControlStock.php" class="btn-secondary">📦 Control de Stock</a>
            <a href="informe.php" class="btn-secondary">📊 Ver informe</a>
            <button type="button" class="btn-primary btn-superadmin" onclick="abrirModalNuevoUsuario()">＋ Nuevo usuario</button>
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
            <div class="kpi-icon gold">👑</div>
            <div class="kpi-data">
                <span class="kpi-label"><?= $esSuperAdmin ? 'Super Admins / Admins' : 'Administradores' ?></span>
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

        <?php if ($esSuperAdmin): ?>
        <article class="kpi-card">
            <div class="kpi-icon amber">🔑</div>
            <div class="kpi-data">
                <span class="kpi-label">Logins hoy</span>
                <span class="kpi-value" id="kpi-logins-hoy">0</span>
            </div>
        </article>
        <?php endif; ?>

        <article class="kpi-card">
            <div class="kpi-icon rose">💰</div>
            <div class="kpi-data">
                <span class="kpi-label">Ventas totales</span>
                <span class="kpi-value" id="kpi-ventas-total">$0</span>
            </div>
        </article>
    </section>

    <div class="tabs-container" role="tablist" aria-label="Paneles de administración">
        <button type="button" class="tab-btn active" data-tab="tab-usuarios" role="tab" aria-selected="true">👥 Gestión de Usuarios</button>
        <?php if ($esSuperAdmin): ?>
        <button type="button" class="tab-btn" data-tab="tab-logins" role="tab" aria-selected="false">🔑 Monitoreo de Logins</button>
        <?php endif; ?>
        <button type="button" class="tab-btn" data-tab="tab-actividades" role="tab" aria-selected="false">⚡ Registro de Actividades</button>
    </div>

    <!-- PESTAÑA 1: GESTIÓN DE USUARIOS -->
    <section id="tab-usuarios" class="tab-content active" role="tabpanel">
        <div class="panel-layout">
            <div class="filters-card">
                <div class="search-box">
                    <label for="search-user">Buscar usuario</label>
                    <input id="search-user" type="search" placeholder="Nombre de usuario..." autocomplete="off">
                </div>

                <div class="chip-group" aria-label="Filtrar por rol">
                    <button type="button" class="chip-filter active" data-filter-role="todos">Todos</button>
                    <?php if ($esSuperAdmin): ?>
                    <button type="button" class="chip-filter" data-filter-role="superadmin">👑 Super Admins</button>
                    <?php endif; ?>
                    <button type="button" class="chip-filter" data-filter-role="administrador">🛡️ Administradores</button>
                    <button type="button" class="chip-filter" data-filter-role="vendedor">🛒 Vendedores</button>
                </div>
            </div>

            <div class="table-card">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Rol actual</th>
                            <th>Cambiar rol</th>
                            <th>Creado</th>
                            <?php if ($esSuperAdmin): ?>
                            <th>Último login</th>
                            <th>Total Logins</th>
                            <?php endif; ?>
                            <th>Ventas</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-usuarios">
                        <tr>
                            <td colspan="<?= $esSuperAdmin ? '8' : '6' ?>" style="text-align: center; padding: 32px; color: #6b7280;">
                                Cargando usuarios...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <?php if ($esSuperAdmin): ?>
    <!-- PESTAÑA 2: MONITOREO DE LOGINS (EXCLUSIVO SUPER ADMIN) -->
    <section id="tab-logins" class="tab-content" role="tabpanel" aria-hidden="true">
        <div class="panel-layout">
            <div class="filters-card">
                <div class="search-box">
                    <label for="search-login">Buscar en registro de accesos</label>
                    <input id="search-login" type="search" placeholder="Usuario, IP o dispositivo..." autocomplete="off">
                </div>

                <div class="chip-group" aria-label="Filtrar eventos de login">
                    <button type="button" class="chip-filter active" data-filter-login="todos">Todos los eventos</button>
                    <button type="button" class="chip-filter" data-filter-login="login_exitoso">✅ Logins exitosos</button>
                    <button type="button" class="chip-filter" data-filter-login="login_fallido">⚠️ Intentos fallidos</button>
                    <button type="button" class="chip-filter" data-filter-login="registro_usuario">📝 Nuevos registros</button>
                    <button type="button" class="chip-filter" data-filter-login="logout">🚪 Cierres de sesión</button>
                </div>
            </div>

            <div class="table-card">
                <table class="users-table logins-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Evento de acceso</th>
                            <th>Fecha y Hora</th>
                            <th>Dirección IP</th>
                            <th>Dispositivo / Navegador</th>
                            <th>Detalles</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody id="tbody-logins">
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 32px; color: #6b7280;">
                                Cargando registro de inicios de sesión...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- PESTAÑA 3: TODAS LAS ACTIVIDADES DEL SISTEMA -->
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
                    <button type="button" class="chip-filter" data-filter-act="login">Logins</button>
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

<!-- MODAL CREAR NUEVO USUARIO -->
<div id="modal-nuevo-usuario" class="modal-overlay" aria-hidden="true">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h3>👑 Crear nuevo usuario</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Cerrar">×</button>
        </div>

        <form id="form-nuevo-usuario">
            <div class="field-group">
                <label for="new-username">Nombre de usuario</label>
                <input id="new-username" type="text" name="usuario" placeholder="Ej: vendedora01" required autocomplete="off">
            </div>

            <p style="color:#6b7280; margin:0 0 16px;">El sistema generará una contraseña aleatoria para la nueva cuenta y la mostrará una sola vez al terminar.</p>

            <div class="field-group">
                <label>Asignar Rol</label>
                <div class="role-picker role-picker-3">
                    <label class="role-radio-card selected">
                        <input type="radio" name="new-role" id="role-new-vendedor" value="vendedor" checked> <!-- este rol solo aparece cuando lo inicia el usuario como vendedor, pero no puede ver que existe un administrador ni sabe que existe un super admin, y tiene funciones básicas -->
                        <span>🛒 Vendedor</span>
                    </label>
                    <label class="role-radio-card"> <!-- este rol solo lo puede ver cuándo el usuario como administrador inicia sesión, pero no puede ver al super admin ni su actividad -->
                        <input type="radio" name="new-role" id="role-new-admin" value="administrador">
                        <span>🛡️ Admin</span>
                    </label>
                    <?php if ($esSuperAdmin): ?>
                    <label class="role-radio-card">
                        <input type="radio" name="new-role" id="role-new-superadmin" value="superadmin">
                        <span>👑 Super Admin</span>
                    </label>
                    <?php endif; ?>
                </div>
            </div>
            <!-- La actividad de todos los usuarios independientemente del rol, su actividad será guardada por seguridad -->

            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-modal-close>Cancelar</button>
                <button type="submit" class="btn-primary btn-superadmin">Guardar usuario</button>
            </div>
        </form>
    </div>
</div>

<div id="modal-credenciales-generadas" class="modal-overlay" aria-hidden="true">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h3>🔑 Usuario creado</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Cerrar">×</button>
        </div>
        <p id="generated-credential-user" style="margin-bottom:12px;"></p>
        <p style="color:#6b7280; margin-bottom:8px;">Compartí esta contraseña con el usuario. No se volverá a mostrar:</p>
        <code id="generated-credential-password" style="display:block; padding:14px; margin-bottom:16px; background:#f3f4f6; border-radius:8px; text-align:center; font-size:1.1rem; font-weight:700; overflow-wrap:anywhere;"></code>
        <div class="modal-actions">
            <button type="button" class="btn-secondary" id="copy-generated-credential">Copiar contraseña</button>
            <button type="button" class="btn-primary" data-modal-close>Listo</button>
        </div>
    </div>
</div>

<!-- MODAL EDITAR USUARIO -->
<div id="modal-editar-usuario" class="modal-overlay" aria-hidden="true">
    <div class="modal-content modal-sm">
        <div class="modal-header">
            <h3>✏️ Editar usuario</h3>
            <button type="button" class="modal-close" data-modal-close aria-label="Cerrar">×</button>
        </div>

        <form id="form-editar-usuario">
            <input id="edit-user-id" type="hidden" name="id">

            <div class="field-group">
                <label for="edit-username">Usuario</label>
                <input id="edit-username" type="text" name="usuario" placeholder="Nombre de usuario" required>
            </div>

            <div class="field-group">
                <label for="edit-password">Restablecer contraseña</label>
                <input id="edit-password" type="password" name="password" placeholder="Dejar vacío para conservarla" minlength="26" maxlength="64" autocomplete="new-password">
                <small>Si la cambiás: más de 25 caracteres, con minúsculas, mayúsculas, números, $, %, operadores y otros símbolos. Usá caracteres ASCII imprimibles.</small>
            </div>

            <div class="field-group">
                <label>Rol</label>
                <div class="role-picker role-picker-3">
                    <label class="role-radio-card">
                        <input type="radio" name="edit-role" id="role-edit-vendedor" value="vendedor">
                        <span>🛒 Vendedor</span>
                    </label>
                    <label class="role-radio-card">
                        <input type="radio" name="edit-role" id="role-edit-admin" value="administrador">
                        <span>🛡️ Admin</span>
                    </label>
                    <?php if ($esSuperAdmin): ?>
                    <label class="role-radio-card">
                        <input type="radio" name="edit-role" id="role-edit-superadmin" value="superadmin">
                        <span>👑 Super Admin</span>
                    </label>
                    <?php endif; ?>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" data-modal-close>Cancelar</button>
                <button type="submit" class="btn-primary btn-superadmin">Actualizar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL DETALLE COMPLETO DE USUARIO (CON LOGINS Y VENTAS) -->
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

<!-- NOTIFICACIÓN TOAST -->
<div id="toast-notification" class="toast-notification" aria-live="polite">
    <span id="toast-message">Acción realizada.</span>
</div>

<script src="scrits/gestionUsuarios/registro_actividades.js"></script>
<script src="scrits/gestionUsuarios/monitoreo_login.js"></script>
<script>window.usuarioEsSuperAdmin = <?= $esSuperAdmin ? 'true' : 'false' ?>;</script>
<script src="scrits/gestionUsuarios/usuarios.js"></script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
