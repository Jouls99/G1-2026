<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';

// Esta vista concentra la auditoría y queda restringida al Super Administrador.
requireSuperAdmin('venta.php');

// La cabecera y la navegación reciben el título, el estilo propio y la sección activa.
$pageTitle = 'Centro de auditoría';
$customCss = 'css/auditoria.css';
$activePage = 'configuracion';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>
<main class="audit-shell">
    <!-- Resume el propósito del panel y ofrece la recarga manual del registro. -->
    <section class="audit-heading" aria-labelledby="audit-title">
        <div>
            <p class="eyebrow">Administración / Seguridad</p>
            <h1 id="audit-title">Centro de auditoría</h1>
            <p class="audit-intro">Sesiones, actividad registrada y señales de acceso inusual.</p>
        </div>
        <button type="button" class="refresh-button" id="refresh-audit" title="Actualizar auditoría">
            <span aria-hidden="true">&#8635;</span> Actualizar
        </button>
    </section>

    <!-- Métricas generales que auditoria.js completa con las estadísticas de la API. -->
    <section class="audit-metrics" aria-label="Resumen de actividad">
        <article class="metric">
            <span class="metric-label">Eventos registrados</span>
            <strong id="metric-total">--</strong>
            <span class="metric-note">Lotes de 50 registros</span>
        </article>
        <article class="metric">
            <span class="metric-label">Accesos hoy</span>
            <strong id="metric-logins">--</strong>
            <span class="metric-note">Inicios y registros exitosos</span>
        </article>
        <article class="metric metric-warning">
            <span class="metric-label">Intentos fallidos</span>
            <strong id="metric-failed">--</strong>
            <span class="metric-note">Historial disponible</span>
        </article>
        <article class="metric">
            <span class="metric-label">Señales recientes</span>
            <strong id="metric-threats">--</strong>
            <span class="metric-note">Reglas de detección activas</span>
        </article>
    </section>

    <!-- Indica el estado de consulta y la hora de la última respuesta del servicio. -->
    <section class="system-status" aria-live="polite">
        <span class="status-indicator" id="status-indicator"></span>
        <strong id="system-status-title">Consultando registro</strong>
        <span id="system-status-detail">Esperando respuesta del servicio de auditoría</span>
        <time id="last-updated"></time>
    </section>

    <!-- Las pestañas alternan entre sesiones, actividad general y alertas detectadas. -->
    <nav class="audit-tabs" aria-label="Secciones de auditoría" role="tablist">
        <button type="button" class="audit-tab is-active" id="tab-sessions" role="tab" aria-selected="true" aria-controls="panel-sessions" data-panel="panel-sessions">Sesiones</button>
        <button type="button" class="audit-tab" id="tab-activity" role="tab" aria-selected="false" aria-controls="panel-activity" data-panel="panel-activity">Actividad y logs</button>
        <button type="button" class="audit-tab" id="tab-threats" role="tab" aria-selected="false" aria-controls="panel-threats" data-panel="panel-threats">Amenazas <span class="tab-count" id="threat-tab-count">0</span></button>
    </nav>
    <section class="regenerate-section" aria-label="Utilidades avanzadas" style="margin-bottom: 1.7rem; margin-top:0.2rem;">
        <button type="button" id="regenerate-data" class="btn btn-danger" style="background: #fbbf24; color: #78350f; font-weight: bold; border: 1px solid #f59e42; display: flex; align-items: center; gap: 0.6em; margin-bottom: 0.6em;">
            <span aria-hidden="true" style="font-size: 1.35em;">&#8634;</span>
            Regenerar datos: stock, productos y auditoría
        </button>
        <span id="regenerate-status" style="display: block; margin-top: 3px; font-size: 0.97em; color: #888;"></span>
        <p style="margin-top:3px; color:#ba661c; font-size:0.93em;">Solo visible para administradores. Actualiza las tablas principales de auditoría para testeo o mantenimiento.</p>
    </section>

    <!-- Cada panel deja el destino vacío para que auditoria.js lo rellene al consultar la API. -->
    <section class="audit-panel is-visible" id="panel-sessions" role="tabpanel" aria-labelledby="tab-sessions">
        <div class="panel-heading">
            <div>
                <h2>Inicios y cierres de sesión</h2>
                <p>Incluye accesos correctos, intentos fallidos y cierres registrados.</p>
            </div>
            <label class="search-field">
                <span>Buscar</span>
                <input type="search" id="session-search" placeholder="Usuario o IP" autocomplete="off">
            </label>
        </div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Usuario</th><th>Evento</th><th>Dirección IP</th><th>Fecha y hora</th></tr></thead>
                <tbody id="sessions-body"><tr><td colspan="4" class="table-message">Cargando sesiones...</td></tr></tbody>
            </table>
        </div>
        <div class="activity-pagination">
            <span id="sessions-pagination-status" aria-live="polite">Cargando sesiones...</span>
            <button type="button" class="load-more-button" id="load-more-sessions" hidden>Cargar 50 registros más</button>
        </div>
    </section>

    <!-- Busca y lista eventos de ventas, inventario, usuarios y demás módulos. -->
    <section class="audit-panel" id="panel-activity" role="tabpanel" aria-labelledby="tab-activity" hidden>
        <div class="panel-heading">
            <div>
                <h2>Actividad y logs del sistema</h2>
                <p>Acciones registradas por los módulos de ventas, inventario, usuarios y autenticación.</p>
            </div>
            <label class="search-field">
                <span>Buscar</span>
                <input type="search" id="activity-search" placeholder="Usuario, acción o detalle" autocomplete="off">
            </label>
        </div>
        <div class="table-scroll">
            <table>
                <thead><tr><th>Fecha y hora</th><th>Usuario</th><th>Área</th><th>Descripción</th></tr></thead>
                <tbody id="activity-body"><tr><td colspan="4" class="table-message">Cargando actividad...</td></tr></tbody>
            </table>
        </div>
        <div class="activity-pagination">
            <span id="activity-pagination-status" aria-live="polite">Cargando actividad...</span>
            <button type="button" class="load-more-button" id="load-more-activity" hidden>Cargar 50 registros más</button>
        </div>
    </section>

    <!-- Expone alertas derivadas de reglas sobre intentos fallidos y explica su alcance. -->
    <section class="audit-panel" id="panel-threats" role="tabpanel" aria-labelledby="tab-threats" hidden>
        <div class="panel-heading">
            <div>
                <h2>Señales de acceso inusual</h2>
                <p>Reglas sobre intentos fallidos registrados en los últimos 15 minutos.</p>
            </div>
            <span class="rule-note">5 fallos por IP y usuario, o 10 por IP entre usuarios</span>
        </div>
        <div id="threats-list" class="threats-list" aria-live="polite">
            <p class="table-message">Analizando eventos disponibles...</p>
        </div>
        <div class="activity-pagination">
            <span id="threats-pagination-status" aria-live="polite">Cargando señales...</span>
            <button type="button" class="load-more-button" id="load-more-threats" hidden>Cargar 50 registros más</button>
        </div>
        <p class="audit-disclaimer">Estas alertas son indicios basados en el registro de la aplicación; no sustituyen un sistema de monitoreo de red o antivirus.</p>
    </section>
</main>
<script src="scrits/auditoria.js" defer></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>