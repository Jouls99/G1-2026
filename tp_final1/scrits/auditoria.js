const sessionsBody = document.getElementById('sessions-body');
const activityBody = document.getElementById('activity-body');
const threatsList = document.getElementById('threats-list');
const auditStatus = document.querySelector('.system-status');
const refreshButton = document.getElementById('refresh-audit');
let auditEvents = [];
let detectedThreats = [];

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    })[character]);
}

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

function renderSessions() {
    const query = document.getElementById('session-search').value.trim().toLowerCase();
    const rows = auditEvents.filter(isAuthenticationEvent).filter(event => {
        const details = eventDetails(event);
        return [event.usuario, details.ip].some(value => String(value ?? '').toLowerCase().includes(query));
    });

    if (!rows.length) {
        sessionsBody.innerHTML = `<tr><td colspan="4" class="table-message">No hay eventos de sesión para mostrar.</td></tr>`;
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
    const rows = auditEvents.filter(event => !isAuthenticationEvent(event)).filter(event => {
        return [event.usuario, event.tipo, event.tipo_accion, event.descripcion]
            .some(value => String(value ?? '').toLowerCase().includes(query));
    });

    if (!rows.length) {
        activityBody.innerHTML = `<tr><td colspan="4" class="table-message">No hay actividad registrada para mostrar.</td></tr>`;
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

function setStatus(ok, detail) {
    auditStatus.classList.toggle('is-ok', ok);
    auditStatus.classList.toggle('is-error', !ok);
    document.getElementById('system-status-title').textContent = ok ? 'Registro disponible' : 'No se pudo consultar el registro';
    document.getElementById('system-status-detail').textContent = detail;
    document.getElementById('last-updated').textContent = ok
        ? `Actualizado ${new Intl.DateTimeFormat('es-AR', { timeStyle: 'short' }).format(new Date())}`
        : '';
}

async function loadAudit() {
    refreshButton.disabled = true;
    try {
        const response = await fetch('api/actividades.php?limit=500', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'El servicio rechazó la consulta.');

        auditEvents = Array.isArray(data.actividades) ? data.actividades : [];
        detectedThreats = Array.isArray(data.amenazas) ? data.amenazas : [];
        const stats = data.stats || {};
        document.getElementById('metric-total').textContent = Number(stats.total ?? auditEvents.length);
        document.getElementById('metric-logins').textContent = Number(stats.logins_hoy ?? 0);
        document.getElementById('metric-failed').textContent = Number(stats.intentos_fallidos ?? 0);

        const sourceLabel = data.fuente === 'mysql' ? 'MySQL' : 'respaldo JSON';
        setStatus(true, `API de auditoría activa · Fuente: ${sourceLabel}`);
        renderSessions();
        renderActivity();
        renderThreats();
    } catch (error) {
        const message = error instanceof Error ? error.message : 'Error desconocido';
        setStatus(false, message);
        sessionsBody.innerHTML = `<tr><td colspan="4" class="table-message">No fue posible cargar las sesiones.</td></tr>`;
        activityBody.innerHTML = `<tr><td colspan="4" class="table-message">No fue posible cargar los logs.</td></tr>`;
        threatsList.innerHTML = '<div class="threats-clear">No es posible analizar amenazas sin acceso al registro.</div>';
    } finally {
        refreshButton.disabled = false;
    }
}

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
loadAudit();