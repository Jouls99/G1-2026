const sessionsBody = document.getElementById('sessions-body');
const activityBody = document.getElementById('activity-body');
const threatsList = document.getElementById('threats-list');
const auditStatus = document.querySelector('.system-status');
const refreshButton = document.getElementById('refresh-audit');
let auditEvents = [];
let activityEvents = [];
let detectedThreats = [];
let sessionCursor = null;
let activityCursor = null;
let threatCursor = null;
let hasMoreSessions = false;
let hasMoreActivity = false;
let hasMoreThreats = false;
let isLoadingMoreSessions = false;
let isLoadingMoreActivity = false;
let isLoadingMoreThreats = false;
let auditRequestVersion = 0;
const loadMoreSessionsButton = document.getElementById('load-more-sessions');
const loadMoreButton = document.getElementById('load-more-activity');
const loadMoreThreatsButton = document.getElementById('load-more-threats');
const sessionsPaginationStatus = document.getElementById('sessions-pagination-status');
const activityPaginationStatus = document.getElementById('activity-pagination-status');
const threatsPaginationStatus = document.getElementById('threats-pagination-status');

// Convierte contenido de API a texto seguro y normaliza campos variables de los eventos.
function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    })[character]);
}

// Adaptadores de eventos: unifican tipos/detalles y generan etiquetas humanas para el panel.
function eventType(event) {
    return String(event.tipo ?? event.tipo_accion ?? '').toLowerCase();
}

function eventDetails(event) {
    if (event.detalles && typeof event.detalles === 'object') return event.detalles;
    if (typeof event.detalles === 'string') {
        try { return JSON.parse(event.detalles); } catch { return {}; }
    }
    return {};
}

function isAuthenticationEvent(event) {
    const type = eventType(event);
    return type.startsWith('login') || type.startsWith('registro') || type === 'logout';
}

function formatDate(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Fecha no disponible';
    return new Intl.DateTimeFormat('es-AR', {
        dateStyle: 'medium',
        timeStyle: 'short'
    }).format(date);
}

function eventLabel(event) {
    const type = eventType(event);
    if (type === 'login_fallido') return ['Acceso rechazado', 'event-failed'];
    if (type.startsWith('login')) return ['Inicio de sesión', 'event-success'];
    if (type === 'logout') return ['Cierre de sesión', 'event-neutral'];
    if (type.startsWith('registro')) return ['Registro de cuenta', 'event-info'];
    return [type.replaceAll('_', ' ') || 'Actividad', 'event-neutral'];
}

function areaLabel(event) {
    const type = eventType(event);
    if (type.startsWith('venta')) return 'Ventas';
    if (type.startsWith('stock')) return 'Inventario';
    if (type.includes('rol') || type.includes('usuario') || type.startsWith('registro')) return 'Usuarios';
    if (type.startsWith('login') || type === 'logout') return 'Autenticación';
    return 'Sistema';
}

// Dibuja por separado accesos, actividad y amenazas usando los filtros visibles del panel.
function renderSessions() {
    const query = document.getElementById('session-search').value.trim().toLowerCase();
    const rows = auditEvents.filter(isAuthenticationEvent).filter(event => {
        const details = eventDetails(event);
        return [event.usuario, details.ip].some(value => String(value ?? '').toLowerCase().includes(query));
    });

    if (query) {
        sessionsPaginationStatus.textContent = `Mostrando ${rows.length} de ${auditEvents.length} registros cargados`;
    } else {
        sessionsPaginationStatus.textContent = `Mostrando ${auditEvents.length} registros cargados (lotes de 50)`;
    }

    if (hasMoreSessions) {
        loadMoreSessionsButton.hidden = false;
        loadMoreSessionsButton.disabled = isLoadingMoreSessions;
        loadMoreSessionsButton.innerHTML = isLoadingMoreSessions ? '⏳ Cargando 50 registros más...' : '⬇️ Cargar 50 registros más';
    } else {
        loadMoreSessionsButton.hidden = true;
        if (auditEvents.length > 0) {
            sessionsPaginationStatus.textContent += ' · ✅ Todos los registros cargados';
        }
    }

    if (!rows.length) {
        sessionsBody.innerHTML = `<tr><td colspan="4" class="table-message">${query ? 'No se encontraron eventos con la búsqueda actual.' : 'No hay eventos de sesión para mostrar.'}</td></tr>`;
        return;
    }

    sessionsBody.innerHTML = rows.map(event => {
        const details = eventDetails(event);
        const [label, badgeClass] = eventLabel(event);
        return `<tr>
            <td class="user-cell"><strong>${escapeHtml(event.usuario || 'Usuario desconocido')}</strong><span>${escapeHtml(event.rol || 'Rol no disponible')}</span></td>
            <td><span class="event-badge ${badgeClass}">${escapeHtml(label)}</span></td>
            <td>${escapeHtml(details.ip || 'No registrada')}</td>
            <td class="date-cell">${escapeHtml(formatDate(event.fecha))}</td>
        </tr>`;
    }).join('');
}

function renderActivity() {
    const query = document.getElementById('activity-search').value.trim().toLowerCase();
    const rows = activityEvents.filter(event => {
        return [event.usuario, event.tipo, event.tipo_accion, event.descripcion]
            .some(value => String(value ?? '').toLowerCase().includes(query));
    });

    if (query) {
        activityPaginationStatus.textContent = `Mostrando ${rows.length} de ${activityEvents.length} registros cargados`;
    } else {
        activityPaginationStatus.textContent = `Mostrando ${activityEvents.length} registros cargados (lotes de 50)`;
    }

    if (hasMoreActivity) {
        loadMoreButton.hidden = false;
        loadMoreButton.disabled = isLoadingMoreActivity;
        loadMoreButton.innerHTML = isLoadingMoreActivity ? '⏳ Cargando 50 registros más...' : '⬇️ Cargar 50 registros más';
    } else {
        loadMoreButton.hidden = true;
        if (activityEvents.length > 0) {
            activityPaginationStatus.textContent += ' · ✅ Todos los registros cargados';
        }
    }

    if (!rows.length) {
        activityBody.innerHTML = `<tr><td colspan="4" class="table-message">${query ? 'No se encontró actividad con la búsqueda actual.' : 'No hay actividad registrada para mostrar.'}</td></tr>`;
        return;
    }

    activityBody.innerHTML = rows.map(event => `<tr>
        <td class="date-cell">${escapeHtml(formatDate(event.fecha))}</td>
        <td>${escapeHtml(event.usuario || 'Sistema')}</td>
        <td><span class="area-badge">${escapeHtml(areaLabel(event))}</span></td>
        <td class="detail-cell">${escapeHtml(event.descripcion || event.tipo || event.tipo_accion || 'Evento sin descripción')}</td>
    </tr>`).join('');
}

function renderThreats() {
    detectedThreats = Array.isArray(detectedThreats) ? detectedThreats : [];
    document.getElementById('metric-threats').textContent = detectedThreats.length;
    document.getElementById('threat-tab-count').textContent = detectedThreats.length;

    threatsPaginationStatus.textContent = `Mostrando ${detectedThreats.length} señales cargadas (lotes de 50)`;

    if (hasMoreThreats) {
        loadMoreThreatsButton.hidden = false;
        loadMoreThreatsButton.disabled = isLoadingMoreThreats;
        loadMoreThreatsButton.innerHTML = isLoadingMoreThreats ? '⏳ Cargando 50 registros más...' : '⬇️ Cargar 50 registros más';
    } else {
        loadMoreThreatsButton.hidden = true;
        if (detectedThreats.length > 0) {
            threatsPaginationStatus.textContent += ' · ✅ Todas las señales cargadas';
        }
    }

    if (!detectedThreats.length) {
        threatsList.innerHTML = '<div class="threats-clear">No se detectaron señales que superen los umbrales con los eventos recientes disponibles.</div>';
        return;
    }

    threatsList.innerHTML = detectedThreats.map(alert => `<article class="threat-item">
        <span class="threat-symbol" aria-hidden="true">!</span>
        <div><strong>${escapeHtml(alert.titulo || alert.title || 'Señal detectada')}</strong><p>${escapeHtml(alert.descripcion || alert.description || '')}</p></div>
        <time class="threat-time">${escapeHtml(formatDate(alert.fecha || alert.date))}</time>
    </article>`).join('');
}

// Refleja el resultado de la última consulta en el indicador accesible de estado del servicio.
function setStatus(ok, detail) {
    auditStatus.classList.toggle('is-ok', ok);
    auditStatus.classList.toggle('is-error', !ok);
    document.getElementById('system-status-title').textContent = ok ? 'Registro disponible' : 'No se pudo consultar el registro';
    document.getElementById('system-status-detail').textContent = detail;
    document.getElementById('last-updated').textContent = ok
        ? `Actualizado ${new Intl.DateTimeFormat('es-AR', { timeStyle: 'short' }).format(new Date())}`
        : '';
}

async function requestAuditData(url) {
    const response = await fetch(url, { cache: 'no-store' });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || 'El servicio rechazó la consulta.');
    return data;
}

// Carga por separado las sesiones recientes y el primer lote de actividad (límite de 50 registros por lote).
async function loadAudit() {
    const requestVersion = ++auditRequestVersion;
    isLoadingMoreSessions = false;
    isLoadingMoreActivity = false;
    isLoadingMoreThreats = false;
    refreshButton.disabled = true;
    loadMoreSessionsButton.disabled = true;
    loadMoreButton.disabled = true;
    loadMoreThreatsButton.disabled = true;
    auditEvents = [];
    activityEvents = [];
    detectedThreats = [];
    sessionCursor = null;
    activityCursor = null;
    threatCursor = null;
    hasMoreSessions = false;
    hasMoreActivity = false;
    hasMoreThreats = false;
    loadMoreSessionsButton.hidden = true;
    loadMoreButton.hidden = true;
    loadMoreThreatsButton.hidden = true;
    sessionsPaginationStatus.textContent = 'Cargando sesiones...';
    activityPaginationStatus.textContent = 'Cargando actividad...';
    threatsPaginationStatus.textContent = 'Cargando señales...';
    try {
        const [sessionData, activityData] = await Promise.all([
            requestAuditData('api/actividades.php?stream=sessions&limit=50'),
            requestAuditData('api/actividades.php?stream=activity&limit=50')
        ]);
        if (requestVersion !== auditRequestVersion) return;

        auditEvents = Array.isArray(sessionData.actividades) ? sessionData.actividades : [];
        sessionCursor = sessionData.next_cursor;
        hasMoreSessions = sessionData.has_more === true;
        activityEvents = Array.isArray(activityData.actividades) ? activityData.actividades : [];
        activityCursor = activityData.next_cursor;
        hasMoreActivity = activityData.has_more === true;
        detectedThreats = Array.isArray(sessionData.amenazas) ? sessionData.amenazas : [];
        threatCursor = sessionData.next_threat_cursor;
        hasMoreThreats = sessionData.has_more_threats === true;
        const stats = sessionData.stats || {};
        document.getElementById('metric-total').textContent = Number(stats.total ?? auditEvents.length);
        document.getElementById('metric-logins').textContent = Number(stats.logins_hoy ?? 0);
        document.getElementById('metric-failed').textContent = Number(stats.intentos_fallidos ?? 0);

        const sourceLabel = sessionData.fuente === 'mysql' ? 'MySQL' : 'respaldo JSON';
        setStatus(true, `API de auditoría activa · Fuente: ${sourceLabel}`);
        renderSessions();
        renderActivity();
        renderThreats();
    } catch (error) {
        if (requestVersion !== auditRequestVersion) return;
        const message = error instanceof Error ? error.message : 'Error desconocido';
        setStatus(false, message);
        sessionsBody.innerHTML = `<tr><td colspan="4" class="table-message">No fue posible cargar las sesiones.</td></tr>`;
        activityBody.innerHTML = `<tr><td colspan="4" class="table-message">No fue posible cargar los logs.</td></tr>`;
        activityPaginationStatus.textContent = 'No se pudo cargar la actividad.';
        threatsList.innerHTML = '<div class="threats-clear">No es posible analizar amenazas sin acceso al registro.</div>';
    } finally {
        if (requestVersion === auditRequestVersion) {
            refreshButton.disabled = false;
            loadMoreSessionsButton.disabled = false;
            loadMoreButton.disabled = false;
            loadMoreThreatsButton.disabled = false;
        }
    }
}

async function loadMoreSessions() {
    if (!hasMoreSessions || isLoadingMoreSessions || !sessionCursor) return;

    const requestVersion = auditRequestVersion;
    isLoadingMoreSessions = true;
    renderSessions();
    try {
        const data = await requestAuditData(
            `api/actividades.php?stream=sessions&limit=50&before_id=${encodeURIComponent(sessionCursor)}`
        );
        if (requestVersion !== auditRequestVersion) return;

        const nextEvents = Array.isArray(data.actividades) ? data.actividades : [];
        auditEvents.push(...nextEvents);
        sessionCursor = data.next_cursor;
        hasMoreSessions = data.has_more === true;
    } catch (error) {
        if (requestVersion !== auditRequestVersion) return;
        const message = error instanceof Error ? error.message : 'Error desconocido';
        setStatus(false, message);
    } finally {
        if (requestVersion === auditRequestVersion) {
            isLoadingMoreSessions = false;
            renderSessions();
        }
    }
}

async function loadMoreActivity() {
    if (!hasMoreActivity || isLoadingMoreActivity || !activityCursor) return;

    const requestVersion = auditRequestVersion;
    isLoadingMoreActivity = true;
    renderActivity();
    try {
        const data = await requestAuditData(
            `api/actividades.php?stream=activity&limit=50&before_id=${encodeURIComponent(activityCursor)}`
        );
        if (requestVersion !== auditRequestVersion) return;

        const nextEvents = Array.isArray(data.actividades) ? data.actividades : [];
        activityEvents.push(...nextEvents);
        activityCursor = data.next_cursor;
        hasMoreActivity = data.has_more === true;
    } catch (error) {
        if (requestVersion !== auditRequestVersion) return;
        const message = error instanceof Error ? error.message : 'Error desconocido';
        setStatus(false, message);
    } finally {
        if (requestVersion === auditRequestVersion) {
            isLoadingMoreActivity = false;
            renderActivity();
        }
    }
}

async function loadMoreThreats() {
    if (!hasMoreThreats || isLoadingMoreThreats || !threatCursor) return;

    const requestVersion = auditRequestVersion;
    isLoadingMoreThreats = true;
    renderThreats();
    try {
        const data = await requestAuditData(
            `api/actividades.php?stream=threats&limit=50&threat_before_id=${encodeURIComponent(threatCursor)}`
        );
        if (requestVersion !== auditRequestVersion) return;

        const nextThreats = Array.isArray(data.amenazas) ? data.amenazas : [];
        detectedThreats.push(...nextThreats);
        threatCursor = data.next_threat_cursor;
        hasMoreThreats = data.has_more_threats === true;
    } catch (error) {
        if (requestVersion !== auditRequestVersion) return;
        const message = error instanceof Error ? error.message : 'Error desconocido';
        setStatus(false, message);
    } finally {
        if (requestVersion === auditRequestVersion) {
            isLoadingMoreThreats = false;
            renderThreats();
        }
    }
}

// Los tabs cambian qué panel se muestra; búsqueda y botón de actualización vuelven a renderizar datos.
document.querySelectorAll('.audit-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.audit-tab').forEach(otherTab => {
            const selected = otherTab === tab;
            otherTab.classList.toggle('is-active', selected);
            otherTab.setAttribute('aria-selected', String(selected));
        });
        document.querySelectorAll('.audit-panel').forEach(panel => {
            panel.hidden = panel.id !== tab.dataset.panel;
            panel.classList.toggle('is-visible', !panel.hidden);
        });
    });
});

document.getElementById('session-search').addEventListener('input', renderSessions);
document.getElementById('activity-search').addEventListener('input', renderActivity);
refreshButton.addEventListener('click', loadAudit);
loadMoreSessionsButton.addEventListener('click', loadMoreSessions);
loadMoreButton.addEventListener('click', loadMoreActivity);
loadMoreThreatsButton.addEventListener('click', loadMoreThreats);
loadAudit();
// Botón para regenerar datos (stock/productos/auditoría)
const regenerateButton = document.createElement('button');
regenerateButton.textContent = '🔄 Regenerar datos de stock/productos/auditoría';
regenerateButton.className = 'btn regenerate-data-btn';
regenerateButton.style.margin = '12px 0 18px 0';
regenerateButton.style.background = '#f3f4f6';
regenerateButton.style.color = '#1e293b';
regenerateButton.style.border = '1px solid #d1d5db';
regenerateButton.style.fontWeight = 'bold';

regenerateButton.addEventListener('click', async () => {
    if (!window.confirm('¿Estás seguro que quieres regenerar los datos de productos, stock, inventario, ventas y auditoría? Esta acción NO eliminará productos, usuarios ni categorías, pero restablecerá los valores a su estado inicial.')) {
        return;
    }

    regenerateButton.disabled = true;
    regenerateButton.textContent = 'Regenerando...';

    try {
        // Llama un endpoint que debe implementar el reseteo de productos, stock, ventas y registros de auditoría
        const res = await fetch('api/regenerar_datos.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        const data = await res.json();

        if (!res.ok || !data.ok) {
            throw new Error(data.message || 'Error al regenerar datos');
        }

        // Refresca todas las vistas relevantes después de la regeneración
        if (typeof cargarInventario === 'function') await cargarInventario();
        if (typeof renderSessions === 'function') renderSessions();
        if (typeof renderActivity === 'function') renderActivity();
        if (typeof renderThreats === 'function') renderThreats();
        if (typeof loadAudit === 'function') await loadAudit();

        alert('✅ Regeneración de datos completada con éxito.');
    } catch (err) {
        alert('❌ Error al regenerar datos: ' + (err.message || err));
    } finally {
        regenerateButton.disabled = false;
        regenerateButton.textContent = '🔄 Regenerar datos de stock/productos/auditoría';
    }
});

// Inserta el botón arriba de los paneles de auditoría
const auditPanels = document.querySelector('.audit-panels') || document.querySelector('.audit-panel');
if (auditPanels && auditPanels.parentNode) {
    auditPanels.parentNode.insertBefore(regenerateButton, auditPanels);
}