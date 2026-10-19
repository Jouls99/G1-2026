<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Verificación de autenticación y privilegios de administrador
$esSuperAdmin = isSuperAdmin();
requireAdmin('venta.php');

$currentUser = getCurrentUser();
$userName = $currentUser['usuario'] ?? 'Administrador';
$userRole = $currentUser['role'] ?? 'vendedor';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Gestión de Usuarios Móvil (Tabla Transpuesta) - SOS Cosméticos</title>
    
    <!-- Hojas de estilos del sistema y estilos táctiles específicos -->
    <link rel="stylesheet" href="css/usuarios.css">
    <link rel="stylesheet" href="css/tabla_transpuesta_mobile.css">
</head>
<body class="mobile-only-page">

    <!-- ESCUDO DE PROTECCIÓN PARA ESCRITORIO (Se oculta automáticamente en celulares <= 768px) -->
    <div id="desktop-only-warning" class="desktop-only-warning" role="dialog" aria-modal="true" aria-labelledby="desktop-warning-title">
        <div class="desktop-warning-card">
            <div class="warning-icon-bubble">📱</div>
            <h2 id="desktop-warning-title">Pantalla Exclusiva para Celulares</h2>
            <p>
                Esta interfaz con <strong>Tablas Transpuestas</strong> está diseñada exclusivamente para smartphones y pantallas táctiles verticales, eliminando el scroll horizontal en dispositivos móviles.
            </p>
            <div class="desktop-warning-actions">
                <button type="button" id="btn-activar-simulador" class="btn-mobile-sim" onclick="activarSimuladorMovil()">
                    <span>📱 Probar en Simulador Móvil aquí</span>
                </button>
                <a href="usuarios.php" class="btn-desktop-back">
                    💻 Ir a Gestión de Usuarios de Escritorio
                </a>
            </div>
            <script>
                function activarSimuladorMovil() {
                    var w = document.getElementById('desktop-only-warning');
                    if (w) {
                        w.classList.add('hidden');
                        w.style.display = 'none';
                    }
                    document.body.classList.add('modo-simulador-activo');
                }
                window.activarSimuladorMovil = activarSimuladorMovil;
            </script>
            <small style="display: block; margin-top: 16px; color: #94a3b8; font-size: 0.8rem;">
                💡 También podés presionar <strong>F12</strong> y activar el modo de dispositivo móvil en las herramientas del navegador.
            </small>
        </div>
    </div>

    <!-- CONTENEDOR DE LA APLICACIÓN MÓVIL (Con marco de smartphone en PC) -->
    <main class="mobile-app-wrapper">
        <div class="mobile-notch" aria-hidden="true"></div>

        <!-- HEADER MÓVIL -->
        <header class="mobile-app-header">
            <div class="header-top-row">
                <div class="brand-section">
                    <a href="usuarios.php" class="btn-back-icon" title="Volver al panel" aria-label="Volver">
                        ←
                    </a>
                    <div class="header-titles">
                        <h1>
                            Usuarios SOS
                            <span class="badge-mobile-only">📱 Móvil</span>
                        </h1>
                        <p><?= $esSuperAdmin ? '👑 Consola Super Admin' : '🛡️ Modo Administrador' ?></p>
                    </div>
                </div>

                <button type="button" id="btn-add-user-top" class="btn-add-user-top" title="Crear usuario">
                    <span>＋</span> Nuevo
                </button>
            </div>

            <!-- CARRUSEL DE KPIS MÓVILES -->
            <div class="mobile-kpi-scroll" aria-label="Métricas rápidas">
                <div class="mobile-kpi-pill">
                    <span class="kpi-num" id="mkpi-total-users">0</span>
                    <span class="kpi-lbl">Total</span>
                </div>
                <div class="mobile-kpi-pill">
                    <span class="kpi-num" id="mkpi-admins">0</span>
                    <span class="kpi-lbl">Admins</span>
                </div>
                <div class="mobile-kpi-pill">
                    <span class="kpi-num" id="mkpi-vendedores">0</span>
                    <span class="kpi-lbl">Vendedores</span>
                </div>
                <div class="mobile-kpi-pill">
                    <span class="kpi-num" id="mkpi-ventas-total">$0</span>
                    <span class="kpi-lbl">Ventas</span>
                </div>
            </div>
        </header>

        <!-- BARRA DE CONTROLES: BÚSQUEDA Y FILTROS POR CHIP -->
        <section class="mobile-controls">
            <div class="mobile-search-box">
                <span class="search-icon-left">🔍</span>
                <input id="mobile-search-input" type="search" placeholder="Buscar por usuario..." autocomplete="off">
                <button type="button" id="btn-clear-search" class="search-clear-btn" aria-label="Limpiar búsqueda">✕</button>
            </div>

            <div class="mobile-filters-chips" role="tablist" aria-label="Filtrar por rol">
                <button type="button" class="chip-btn active" data-role-filter="todos">Todos</button>
                <?php if ($esSuperAdmin): ?>
                <button type="button" class="chip-btn" data-role-filter="superadmin">👑 Super Admins</button>
                <?php endif; ?>
                <button type="button" class="chip-btn" data-role-filter="administrador">🛡️ Admins</button>
                <button type="button" class="chip-btn" data-role-filter="vendedor">🛒 Vendedores</button>
            </div>
        </section>

        <!-- CUERPO DESPLAZABLE CON LAS TABLAS TRANSPUESTAS -->
        <section class="mobile-app-body" id="mobile-body">
            <!-- Contenedor donde se insertan las tablas transpuestas dinámicas -->
            <div id="contenedor-tablas-transpuestas" class="tablas-transpuestas" aria-live="polite">
                <div class="tabla-transpuesta-vacia">
                    <div style="font-size: 2rem; margin-bottom: 8px;">⏳</div>
                    <strong>Cargando usuarios...</strong>
                </div>
            </div>
        </section>

        <div class="mobile-home-indicator" aria-hidden="true"></div>
    </main>

    <!-- MODAL MÓVIL: NUEVO USUARIO -->
    <div id="modal-nuevo-usuario-mobile" class="mobile-modal-overlay" aria-hidden="true">
        <div class="mobile-modal-content">
            <div class="modal-drag-handle"></div>
            <div class="modal-mobile-header">
                <h3>👑 Crear Nuevo Usuario</h3>
                <button type="button" class="modal-close-round" data-close-modal aria-label="Cerrar">×</button>
            </div>

            <form id="form-nuevo-usuario-mobile" class="modal-mobile-form">
                <div class="form-group">
                    <label for="m-new-username">Nombre de usuario</label>
                    <input id="m-new-username" type="text" name="usuario" placeholder="Ej: vendedora02" required autocomplete="off">
                </div>

                <div class="form-group">
                    <label>Asignar Rol</label>
                    <div class="mobile-role-selector">
                        <label class="role-radio-item selected">
                            <input type="radio" name="m-new-role" value="vendedor" checked style="display:none;">
                            <span>🛒 Vendedor</span>
                        </label>
                        <label class="role-radio-item">
                            <input type="radio" name="m-new-role" value="administrador" style="display:none;">
                            <span>🛡️ Administrador</span>
                        </label>
                        <?php if ($esSuperAdmin): ?>
                        <label class="role-radio-item">
                            <input type="radio" name="m-new-role" value="superadmin" style="display:none;">
                            <span>👑 Super Admin</span>
                        </label>
                        <?php endif; ?>
                    </div>
                </div>

                <p style="font-size: 0.8rem; color: #64748b; margin: 0 0 16px;">
                    El sistema generará una contraseña segura aleatoria y te la mostrará al confirmar.
                </p>

                <div class="modal-mobile-actions">
                    <button type="button" class="btn-m-secondary" data-close-modal>Cancelar</button>
                    <button type="submit" class="btn-m-primary">Crear cuenta</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL MÓVIL: CREDENCIALES GENERADAS -->
    <div id="modal-credenciales-mobile" class="mobile-modal-overlay" aria-hidden="true">
        <div class="mobile-modal-content">
            <div class="modal-drag-handle"></div>
            <div class="modal-mobile-header">
                <h3>🔑 Cuenta Creada</h3>
                <button type="button" class="modal-close-round" data-close-modal aria-label="Cerrar">×</button>
            </div>
            <p id="m-cred-user" style="font-weight: 700; margin: 0 0 8px; color: #1e1b4b;"></p>
            <p style="font-size: 0.82rem; color: #64748b; margin: 0 0 10px;">
                Copiá esta contraseña temporal para compartirla con el usuario:
            </p>
            <code id="m-cred-pass" style="display: block; padding: 12px; background: #f1f5f9; border: 1.5px solid #cbd5e1; border-radius: 10px; font-weight: 800; text-align: center; font-size: 1rem; color: #1e293b; overflow-wrap: anywhere; margin-bottom: 16px;"></code>
            <div class="modal-mobile-actions">
                <button type="button" id="btn-copy-m-cred" class="btn-m-secondary" style="flex: 1;">📋 Copiar</button>
                <button type="button" class="btn-m-primary" data-close-modal style="flex: 1;">Listo</button>
            </div>
        </div>
    </div>

    <!-- MODAL MÓVIL: EDITAR USUARIO -->
    <div id="modal-editar-usuario-mobile" class="mobile-modal-overlay" aria-hidden="true">
        <div class="mobile-modal-content">
            <div class="modal-drag-handle"></div>
            <div class="modal-mobile-header">
                <h3>✏️ Editar Usuario</h3>
                <button type="button" class="modal-close-round" data-close-modal aria-label="Cerrar">×</button>
            </div>

            <form id="form-editar-usuario-mobile" class="modal-mobile-form">
                <input id="m-edit-user-id" type="hidden" name="id">

                <div class="form-group">
                    <label for="m-edit-username">Usuario</label>
                    <input id="m-edit-username" type="text" name="usuario" required>
                </div>

                <div class="form-group">
                    <label for="m-edit-password">Restablecer Contraseña (opcional)</label>
                    <input id="m-edit-password" type="password" name="password" placeholder="Dejar vacío para mantener actual" autocomplete="new-password">
                    <div style="margin-top: 6px;">
                        <button type="button" id="btn-m-regenerar-pass" style="background: none; border: none; color: #7c2d92; font-weight: 700; font-size: 0.82rem; padding: 0; cursor: pointer;">
                            🔄 Regenerar contraseña aleatoria
                        </button>
                    </div>
                    <div id="m-edit-pass-preview" style="display: none; margin-top: 8px; padding: 8px 10px; background: #fdf2f8; border: 1px solid #fbcfe8; border-radius: 8px;">
                        <small style="color: #9d174d; font-weight: 700; display: block;">Contraseña generada:</small>
                        <code id="m-edit-pass-val" style="display: block; font-size: 0.84rem; color: #831843; font-weight: 700; word-break: break-all;"></code>
                    </div>
                </div>

                <div class="form-group">
                    <label>Rol</label>
                    <div class="mobile-role-selector">
                        <label class="role-radio-item">
                            <input type="radio" name="m-edit-role" value="vendedor" style="display:none;">
                            <span>🛒 Vendedor</span>
                        </label>
                        <label class="role-radio-item">
                            <input type="radio" name="m-edit-role" value="administrador" style="display:none;">
                            <span>🛡️ Administrador</span>
                        </label>
                        <?php if ($esSuperAdmin): ?>
                        <label class="role-radio-item">
                            <input type="radio" name="m-edit-role" value="superadmin" style="display:none;">
                            <span>👑 Super Admin</span>
                        </label>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="modal-mobile-actions">
                    <button type="button" class="btn-m-secondary" data-close-modal>Cancelar</button>
                    <button type="submit" class="btn-m-primary">Actualizar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL MÓVIL: DETALLE COMPLETO -->
    <div id="modal-detalle-usuario-mobile" class="mobile-modal-overlay" aria-hidden="true">
        <div class="mobile-modal-content">
            <div class="modal-drag-handle"></div>
            <div class="modal-mobile-header">
                <h3 id="m-detalle-title">Detalle del Usuario</h3>
                <button type="button" class="modal-close-round" data-close-modal aria-label="Cerrar">×</button>
            </div>
            <div id="m-detalle-body">
                <!-- Se llena dinámicamente con JS -->
            </div>
        </div>
    </div>

    <!-- TOAST FLOTANTE MÓVIL -->
    <div id="mobile-toast" class="mobile-toast" role="status" aria-live="polite">
        <span id="mobile-toast-msg">Acción realizada.</span>
    </div>

    <!-- SCRIPTS -->
    <script>window.usuarioEsSuperAdmin = <?= $esSuperAdmin ? 'true' : 'false' ?>;</script>
    <script src="scrits/gestionUsuarios/tablaTranspuesta.js"></script>
    <script src="scrits/gestionUsuarios/tablaTranspuestaMobile.js"></script>
</body>
</html>
