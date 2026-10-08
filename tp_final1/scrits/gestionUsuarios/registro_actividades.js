/**
 * Panel de Registro de Actividades
 */

let listaActividades = [];
let metricasGenerales = {};
let filtroTipoActividad = 'todas';

const activityStream = document.getElementById('activity-stream');
const inputSearchActivity = document.getElementById('search-activity');

// Se usa al iniciar la consola y al cambiar filtros para obtener actividad y estadísticas recientes.
/**
 * Cargar y separar el feed de logins y actividades
 */
async function cargarLoginsYActividades() {
    try {
        const response = await fetch('api/actividades.php?limit=250', { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('Error al consultar el registro de actividades.');
        }

        const data = await response.json();
        listaActividades = data.actividades || [];
        metricasGenerales = data.stats || {};

        // Filtrar actividades relacionadas a autenticación para la pestaña de Logins
        listaLogins = listaActividades.filter(act => {
            const tipo = (act.tipo || '').toLowerCase();
            return tipo.startsWith('login') || tipo.startsWith('registro') || tipo.includes('logout');
        });

        if (kpiLoginsHoy && metricasGenerales.logins_hoy !== undefined) {
            kpiLoginsHoy.textContent = metricasGenerales.logins_hoy;
        }

        renderTablaLogins();
        renderActividades();
    } catch (error) {
        console.error('Error cargando actividades y logins:', error);
        if (tbodyLogins) {
            tbodyLogins.innerHTML = `
                <tr>
                    <td colspan="7" style="text-align:center; padding: 30px; color: #ef4444;">
                        ❌ Error al cargar registro de logins: ${error.message}
                    </td>
                </tr>
            `;
        }
        if (activityStream) {
            activityStream.innerHTML = `
                <div style="text-align: center; padding: 30px; color: #ef4444;">
                    ❌ No se pudo cargar el historial de interacciones: ${error.message}
                </div>
            `;
        }
    }
}

// Se llama desde el campo de búsqueda y el cambio de pestaña para volver a pintar el feed general.
/**
 * Renderizar la lista cronológica de actividades generales
 */
function renderActividades() {
    if (!activityStream) return;

    const query = inputSearchActivity ? inputSearchActivity.value.trim().toLowerCase() : '';

    let filtradas = listaActividades.filter(act => {
        const matchUser = (act.usuario || '').toLowerCase().includes(query);
        const matchDesc = (act.descripcion || '').toLowerCase().includes(query);
        return matchUser || matchDesc;
    });

    if (filtradas.length === 0) {
        activityStream.innerHTML = `
            <div style="text-align: center; padding: 40px 16px; color: #6b7280; background: #fafafa; border-radius: 10px;">
                <div style="font-size: 2rem; margin-bottom: 8px;">⚡</div>
                <strong>No hay interacciones registradas con los filtros seleccionados.</strong>
            </div>
        `;
        return;
    }

    activityStream.innerHTML = filtradas.map(act => {
        let icon = '⚡';
        let typeClass = 'type-general';
        const tipo = (act.tipo || '').toLowerCase();

        if (tipo.includes('venta')) {
            icon = '🛒';
            typeClass = 'type-venta';
        } else if (tipo.includes('login') || tipo.includes('registro')) {
            icon = '🔑';
            typeClass = 'type-login';
        } else if (tipo.includes('stock')) {
            icon = '📦';
            typeClass = 'type-stock';
        } else if (tipo.includes('rol') || tipo.includes('usuario')) {
            icon = '👑';
            typeClass = 'type-roles';
        } else if (tipo.includes('delete') || tipo.includes('eliminar')) {
            icon = '🗑️';
            typeClass = 'type-delete';
        }

        let detallesHtml = '';
        if (act.detalles && typeof act.detalles === 'object') {
            const keys = Object.keys(act.detalles);
            if (keys.length > 0) {
                detallesHtml = `
                    <div style="margin-top: 6px;">
                        <span class="activity-details-pill">
                            📋 ${keys.map(k => `${k}: ${Array.isArray(act.detalles[k]) ? act.detalles[k].length + ' items' : act.detalles[k]}`).join(' | ')}
                        </span>
                    </div>
                `;
            }
        }

        const isSuper = (act.rol || '').toLowerCase().includes('super');

        return `
            <div class="activity-item ${typeClass}">
                <div class="activity-icon">${icon}</div>
                <div class="activity-body">
                    <div class="activity-top-line">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span class="activity-user">${escapeHtml(act.usuario)}</span>
                            <span class="badge-role ${isSuper ? 'superadmin' : (act.rol === 'administrador' ? 'admin' : 'vendedor')}" style="font-size:0.7rem; padding: 2px 6px;">
                                ${act.rol || 'Usuario'}
                            </span>
                        </div>
                        <span class="activity-time">🕒 ${formatDate(act.fecha)} (${timeAgo(act.fecha)})</span>
                    </div>
                    <p class="activity-desc">${escapeHtml(act.descripcion)}</p>
                    ${detallesHtml}
                </div>
            </div>
        `;
    }).join('');
}
