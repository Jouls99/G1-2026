/**
 * Monitoreo de Login
 */

let listaLogins = [];
let filtroLoginActual = 'todos';

const tbodyLogins = document.getElementById('tbody-logins');
const inputSearchLogin = document.getElementById('search-login');

// Se invoca al cargar el feed o cambiar la búsqueda/tipo para refrescar la tabla de accesos.
/**
 * Renderizar la tabla de Monitoreo de Logins
 */
function renderTablaLogins() {
    if (!tbodyLogins) return;

    const query = inputSearchLogin ? inputSearchLogin.value.trim().toLowerCase() : '';

    let filtrados = listaLogins.filter(log => {
        const matchUser = (log.usuario || '').toLowerCase().includes(query);
        const matchDesc = (log.descripcion || '').toLowerCase().includes(query);
        const matchIp = log.detalles && log.detalles.ip ? String(log.detalles.ip).includes(query) : false;
        const matchCoincide = matchUser || matchDesc || matchIp;

        let coincideTipo = true;
        if (filtroLoginActual !== 'todos') {
            coincideTipo = (log.tipo === filtroLoginActual);
        }

        return matchCoincide && coincideTipo;
    });

    if (filtrados.length === 0) {
        tbodyLogins.innerHTML = `
            <tr>
                <td colspan="7" style="text-align: center; padding: 36px 16px; color: #6b7280;">
                    <div style="font-size: 2rem; margin-bottom: 8px;">🔑</div>
                    <strong>No hay eventos de inicio de sesión con los filtros seleccionados.</strong>
                </td>
            </tr>
        `;
        return;
    }

    tbodyLogins.innerHTML = filtrados.map(log => {
        let eventBadge = '<span class="login-event-badge success">✅ Login exitoso</span>';
        const tipo = (log.tipo || '').toLowerCase();

        if (tipo === 'login_fallido') {
            eventBadge = '<span class="login-event-badge danger">⚠️ Fallido</span>';
        } else if (tipo === 'registro_usuario') {
            eventBadge = '<span class="login-event-badge info">📝 Registro</span>';
        } else if (tipo === 'logout') {
            eventBadge = '<span class="login-event-badge neutral">🚪 Logout</span>';
        }

        const ip = log.detalles && log.detalles.ip ? log.detalles.ip : '127.0.0.1';
        const rawUa = log.detalles && log.detalles.user_agent ? log.detalles.user_agent : '';
        const deviceInfo = parseUserAgent(rawUa);
        const roleStr = log.rol || 'usuario';

        return `
            <tr>
                <td>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span class="avatar-bubble small ${roleStr.toLowerCase().includes('super') ? 'superadmin' : (roleStr.toLowerCase().includes('admin') ? 'admin' : 'vendedor')}">
                            ${(log.usuario || '?').charAt(0).toUpperCase()}
                        </span>
                        <div>
                            <strong style="color: #1e1b4b;">${escapeHtml(log.usuario)}</strong>
                            <div style="font-size: 0.72rem; color: #6b7280;">${escapeHtml(roleStr)}</div>
                        </div>
                    </div>
                </td>
                <td>
                    ${eventBadge}
                </td>
                <td>
                    <div style="font-weight: 600; color: #374151;">${formatDate(log.fecha)}</div>
                    <small style="color: #6b7280;">${timeAgo(log.fecha)}</small>
                </td>
                <td>
                    <span class="ip-chip" title="Dirección IP del cliente">🌐 ${escapeHtml(ip)}</span>
                </td>
                <td>
                    <span class="device-chip" title="${escapeHtml(rawUa)}">💻 ${escapeHtml(deviceInfo)}</span>
                </td>
                <td>
                    <span style="font-size: 0.85rem; color: #4b5563;">${escapeHtml(log.descripcion)}</span>
                </td>
                <td>
                    <button type="button" class="btn-action-sm btn-inspect" onclick="verDetalleUsuario('${escapeHtml(log.usuario)}')" title="Ver historial de este usuario">
                        🔍 Ver
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}
