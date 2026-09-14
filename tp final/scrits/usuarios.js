/**
 * Panel de Gestión de Usuarios y Monitoreo de Interacciones
 * SOS Cosméticos - Exclusivo Administrador
 */

// Estado global de la vista
let listaUsuarios = [];
let listaActividades = [];
let filtroRolActual = 'todos';
let filtroTipoActividad = 'todas';
let usuarioSeleccionado = null;

// Elementos del DOM
const tbodyUsuarios = document.getElementById('tbody-usuarios');
const activityStream = document.getElementById('activity-stream');
const inputSearchUser = document.getElementById('search-user');
const inputSearchActivity = document.getElementById('search-activity');

// KPI elements
const kpiTotalUsers = document.getElementById('kpi-total-users');
const kpiAdmins = document.getElementById('kpi-admins');
const kpiVendedores = document.getElementById('kpi-vendedores');
const kpiInteracciones = document.getElementById('kpi-interacciones');
const kpiVentasTotal = document.getElementById('kpi-ventas-total');

// Modales
const modalNuevoUsuario = document.getElementById('modal-nuevo-usuario');
const modalEditarUsuario = document.getElementById('modal-editar-usuario');
const modalDetalleUsuario = document.getElementById('modal-detalle-usuario');
const toastNotification = document.getElementById('toast-notification');
const toastMessage = document.getElementById('toast-message');

/**
 * Mostrar mensaje Toast flotante
 */
function showToast(mensaje, esError = false) {
    if (!toastNotification || !toastMessage) return;
    toastMessage.textContent = mensaje;
    toastNotification.className = `toast-notification active ${esError ? 'error' : ''}`;
    setTimeout(() => {
        toastNotification.className = 'toast-notification';
    }, 3500);
}

/**
 * Formatear montos en pesos
 */
function formatMoney(amount) {
    return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(amount || 0);
}

/**
 * Formatear fechas legibles
 */
function formatDate(isoString) {
    if (!isoString) return 'Nunca';
    try {
        const d = new Date(isoString);
        if (isNaN(d.getTime())) return isoString;
        return d.toLocaleString('es-AR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    } catch (e) {
        return isoString;
    }
}

/**
 * Cargar usuarios y sus estadísticas desde la API
 */
async function cargarUsuarios() {
    try {
        const response = await fetch('api/users.php', { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('Error al conectar con la API de usuarios.');
        }
        listaUsuarios = await response.json();
        renderKPIs();
        renderTablaUsuarios();
    } catch (error) {
        console.error('Error cargando usuarios:', error);
        showToast('❌ No se pudieron cargar los usuarios: ' + error.message, true);
    }
}

/**
 * Actualizar las métricas KPI superiores
 */
function renderKPIs() {
    const totalUsers = listaUsuarios.length;
    const admins = listaUsuarios.filter(u => u.role.toLowerCase() === 'administrador' || u.role.toLowerCase() === 'admin').length;
    const vendedores = listaUsuarios.filter(u => u.role.toLowerCase() === 'vendedor').length;
    const totalVentasDinero = listaUsuarios.reduce((sum, u) => sum + (parseFloat(u.total_facturado) || 0), 0);
    const totalActividades = listaUsuarios.reduce((sum, u) => sum + (parseInt(u.total_actividades) || 0), 0);

    if (kpiTotalUsers) kpiTotalUsers.textContent = totalUsers;
    if (kpiAdmins) kpiAdmins.textContent = admins;
    if (kpiVendedores) kpiVendedores.textContent = vendedores;
    if (kpiVentasTotal) kpiVentasTotal.textContent = formatMoney(totalVentasDinero);
    if (kpiInteracciones) kpiInteracciones.textContent = totalActividades;
}

/**
 * Renderizar la tabla de usuarios con filtros y buscador
 */
function renderTablaUsuarios() {
    if (!tbodyUsuarios) return;

    const query = (inputSearchUser ? inputSearchUser.value.trim().toLowerCase() : '');
    
    let filtrados = listaUsuarios.filter(u => {
        const coincideNombre = u.usuario.toLowerCase().includes(query);
        const coincideRol = filtroRolActual === 'todos' || u.role.toLowerCase() === filtroRolActual;
        return coincideNombre && coincideRol;
    });

    if (filtrados.length === 0) {
        tbodyUsuarios.innerHTML = `
            <tr>
                <td colspan="7" style="text-align: center; padding: 36px 16px; color: #6b7280;">
                    <div style="font-size: 2rem; margin-bottom: 8px;">🔍</div>
                    <strong>No se encontraron usuarios que coincidan con los filtros.</strong>
                </td>
            </tr>
        `;
        return;
    }

    tbodyUsuarios.innerHTML = filtrados.map(u => {
        const isAdmin = u.role.toLowerCase() === 'administrador' || u.role.toLowerCase() === 'admin';
        const avatarInitial = u.usuario ? u.usuario.charAt(0).toUpperCase() : '?';
        const roleLabel = isAdmin ? 'Administrador' : 'Vendedor';
        const badgeClass = isAdmin ? 'admin' : 'vendedor';
        const roleSelectClass = isAdmin ? 'role-admin' : 'role-vendedor';

        return `
            <tr data-user-id="${u.id}">
                <td>
                    <div class="user-profile-cell">
                        <div class="avatar-bubble ${badgeClass}">${avatarInitial}</div>
                        <div>
                            <div class="user-details-title">${escapeHtml(u.usuario)}</div>
                            <div class="user-details-sub">ID: #${u.id}</div>
                        </div>
                    </div>
                </td>
                <td>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <span class="badge-role ${badgeClass}">
                            ${isAdmin ? '🛡️' : '🛒'} ${roleLabel}
                        </span>
                    </div>
                </td>
                <td>
                    <select class="role-select-box ${roleSelectClass}" onchange="cambiarRolUsuario(${u.id}, '${escapeHtml(u.usuario)}', this.value)">
                        <option value="vendedor" ${!isAdmin ? 'selected' : ''}>🛒 Vendedor</option>
                        <option value="administrador" ${isAdmin ? 'selected' : ''}>🛡️ Administrador</option>
                    </select>
                </td>
                <td>
                    <span style="font-size: 0.88rem; color: #4b5563;">
                        ${formatDate(u.fecha_creacion || u.createdAt)}
                    </span>
                </td>
                <td>
                    <span style="font-size: 0.88rem; color: #4b5563;">
                        ${formatDate(u.ultimo_acceso || u.lastLogin)}
                    </span>
                </td>
                <td>
                    <div style="font-weight: 700; color: #166534;">
                        ${formatMoney(u.total_facturado)}
                    </div>
                    <small style="color: #6b7280;">${u.total_ventas || 0} tickets emitidos</small>
                </td>
                <td>
                    <div class="table-actions">
                        <button type="button" class="btn-action-sm btn-inspect" onclick="verDetalleUsuario('${escapeHtml(u.usuario)}')" title="Ver todas las interacciones y ventas">
                            🔍 Interacción
                        </button>
                        <button type="button" class="btn-action-sm btn-edit" onclick="abrirModalEditar(${u.id}, '${escapeHtml(u.usuario)}', '${u.role}')" title="Editar credenciales">
                            ✏️
                        </button>
                        <button type="button" class="btn-action-sm btn-del" onclick="eliminarUsuario(${u.id}, '${escapeHtml(u.usuario)}')" title="Eliminar usuario">
                            🗑️
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

/**
 * Cambiar el rol de un usuario de forma instantánea
 */
async function cambiarRolUsuario(id, usuario, nuevoRol) {
    try {
        const response = await fetch('api/users.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: id,
                usuario: usuario,
                role: nuevoRol
            })
        });

        const data = await response.json();

        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'Error al actualizar rol de usuario.');
        }

        showToast(`✅ Rol de '${usuario}' actualizado a '${nuevoRol}'.`);
        await cargarUsuarios();
        cargarActividades();
    } catch (error) {
        showToast('❌ ' + error.message, true);
        cargarUsuarios(); // Restaurar selector en caso de error
    }
}

/**
 * Cargar el feed de actividades / interacciones
 */
async function cargarActividades() {
    if (!activityStream) return;

    try {
        let url = 'api/actividades.php?limit=150';
        if (filtroTipoActividad && filtroTipoActividad !== 'todas') {
            url += `&tipo=${encodeURIComponent(filtroTipoActividad)}`;
        }

        const response = await fetch(url, { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('Error al consultar el registro de actividades.');
        }

        const data = await response.json();
        listaActividades = data.actividades || [];
        renderActividades();
    } catch (error) {
        console.error('Error cargando actividades:', error);
        activityStream.innerHTML = `
            <div style="text-align: center; padding: 30px; color: #ef4444;">
                ❌ No se pudo cargar el historial de interacciones: ${error.message}
            </div>
        `;
    }
}

/**
 * Renderizar la lista cronológica de actividades
 */
function renderActividades() {
    if (!activityStream) return;

    const query = inputSearchActivity ? inputSearchActivity.value.trim().toLowerCase() : '';

    let filtradas = listaActividades.filter(act => {
        const matchUser = act.usuario.toLowerCase().includes(query);
        const matchDesc = act.descripcion.toLowerCase().includes(query);
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
            icon = '🛡️';
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

        return `
            <div class="activity-item ${typeClass}">
                <div class="activity-icon">${icon}</div>
                <div class="activity-body">
                    <div class="activity-top-line">
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span class="activity-user">${escapeHtml(act.usuario)}</span>
                            <span class="badge-role ${act.rol === 'administrador' ? 'admin' : 'vendedor'}" style="font-size:0.7rem; padding: 2px 6px;">
                                ${act.rol || 'Usuario'}
                            </span>
                        </div>
                        <span class="activity-time">🕒 ${formatDate(act.fecha)}</span>
                    </div>
                    <p class="activity-desc">${escapeHtml(act.descripcion)}</p>
                    ${detallesHtml}
                </div>
            </div>
        `;
    }).join('');
}

/**
 * Abrir modal de detalle completo de interacciones de un usuario específico
 */
async function verDetalleUsuario(username) {
    if (!modalDetalleUsuario) return;

    const modalBody = document.getElementById('detalle-usuario-body');
    const modalTitle = document.getElementById('detalle-usuario-title');
    
    if (modalTitle) modalTitle.textContent = `Interacciones y Ventas: ${username}`;
    if (modalBody) {
        modalBody.innerHTML = `
            <div style="text-align: center; padding: 40px;">
                <div style="font-size: 2rem; margin-bottom: 12px;">⏳</div>
                <p>Cargando información y métricas de <strong>${escapeHtml(username)}</strong>...</p>
            </div>
        `;
    }

    abrirModal(modalDetalleUsuario);

    try {
        const response = await fetch(`api/users.php?action=detail&usuario=${encodeURIComponent(username)}`, { cache: 'no-store' });
        const data = await response.json();

        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'No se pudo obtener el detalle del usuario.');
        }

        const u = data.usuario;
        const m = data.metricas;
        const ventas = data.ventas || [];
        const actividades = data.actividades || [];

        modalBody.innerHTML = `
            <div class="user-summary-card">
                <div class="name-and-role">
                    <div class="avatar-bubble ${u.rol === 'administrador' ? 'admin' : ''}" style="width: 48px; height: 48px; font-size: 1.3rem;">
                        ${u.nombre.charAt(0).toUpperCase()}
                    </div>
                    <div>
                        <h3 style="margin: 0; color: #1e1b4b; font-size: 1.3rem;">${escapeHtml(u.nombre)}</h3>
                        <span class="badge-role ${u.rol === 'administrador' ? 'admin' : 'vendedor'}">
                            ${u.rol === 'administrador' ? '🛡️ Administrador' : '🛒 Vendedor'}
                        </span>
                    </div>
                </div>
                <div style="font-size: 0.85rem; color: #4b5563;">
                    <div>📅 Registrado: <strong>${formatDate(u.fecha_creacion)}</strong></div>
                    <div>🔑 Último acceso: <strong>${formatDate(u.ultimo_acceso)}</strong></div>
                </div>
            </div>

            <div class="user-stats-mini-grid">
                <div class="stat-mini-box">
                    <small>Total Facturado</small>
                    <strong style="color: #15803d;">${formatMoney(m.total_facturado)}</strong>
                </div>
                <div class="stat-mini-box">
                    <small>Tickets / Ventas</small>
                    <strong>${m.total_ventas} ventas</strong>
                </div>
                <div class="stat-mini-box">
                    <small>Ticket Promedio</small>
                    <strong style="color: #7c2d92;">${formatMoney(m.ticket_promedio)}</strong>
                </div>
            </div>

            <!-- Tabs internas del detalle -->
            <div style="display: flex; gap: 8px; margin-bottom: 14px;">
                <button type="button" class="chip-filter active" onclick="cambiarSubtabDetalle(this, 'detalle-ventas')">
                    🛒 Historial de Ventas (${ventas.length})
                </button>
                <button type="button" class="chip-filter" onclick="cambiarSubtabDetalle(this, 'detalle-actividades')">
                    ⚡ Registro de Actividades (${actividades.length})
                </button>
            </div>

            <!-- Panel de Ventas del usuario -->
            <div id="detalle-ventas" class="detalle-subtab-content" style="display: block;">
                ${ventas.length === 0 ? `
                    <p style="text-align: center; color: #6b7280; padding: 20px;">Este usuario aún no ha registrado ventas.</p>
                ` : `
                    <div style="max-height: 280px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                            <thead style="background: #faf5ff; position: sticky; top: 0;">
                                <tr>
                                    <th style="padding: 8px 12px; text-align: left; border-bottom: 1px solid #e9d5ff;">Factura #</th>
                                    <th style="padding: 8px 12px; text-align: left; border-bottom: 1px solid #e9d5ff;">Fecha</th>
                                    <th style="padding: 8px 12px; text-align: left; border-bottom: 1px solid #e9d5ff;">Producto</th>
                                    <th style="padding: 8px 12px; text-align: right; border-bottom: 1px solid #e9d5ff;">Cant.</th>
                                    <th style="padding: 8px 12px; text-align: right; border-bottom: 1px solid #e9d5ff;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${ventas.map(v => `
                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                        <td style="padding: 8px 12px; font-weight: 700;">#${v.ID_factura}</td>
                                        <td style="padding: 8px 12px; color: #6b7280;">${formatDate(v.fecha)}</td>
                                        <td style="padding: 8px 12px;">${escapeHtml(v.nombre_producto)} <small style="color:#9ca3af;">(${v.codigo_producto})</small></td>
                                        <td style="padding: 8px 12px; text-align: right;">${v.cantidadVendida}</td>
                                        <td style="padding: 8px 12px; text-align: right; font-weight: 700; color: #166534;">${formatMoney(v.ganancia)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `}
            </div>

            <!-- Panel de Actividades del usuario -->
            <div id="detalle-actividades" class="detalle-subtab-content" style="display: none;">
                ${actividades.length === 0 ? `
                    <p style="text-align: center; color: #6b7280; padding: 20px;">No hay registros de actividad para este usuario.</p>
                ` : `
                    <div style="max-height: 280px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px;">
                        ${actividades.map(a => `
                            <div style="background: #f8fafc; border-left: 3px solid #7c3aed; padding: 8px 12px; border-radius: 4px; font-size: 0.88rem;">
                                <div style="display: flex; justify-content: space-between; color: #6b7280; font-size: 0.78rem; margin-bottom: 2px;">
                                    <strong>${a.tipo}</strong>
                                    <span>${formatDate(a.fecha)}</span>
                                </div>
                                <div>${escapeHtml(a.descripcion)}</div>
                            </div>
                        `).join('')}
                    </div>
                `}
            </div>
        `;
    } catch (error) {
        modalBody.innerHTML = `
            <div style="text-align: center; padding: 30px; color: #ef4444;">
                ❌ Error al cargar detalle del usuario: ${error.message}
            </div>
        `;
    }
}

/**
 * Alternar subpestaña dentro del modal de detalle
 */
function cambiarSubtabDetalle(btn, targetId) {
    document.querySelectorAll('.detalle-subtab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('#detalle-usuario-body .chip-filter').forEach(el => el.classList.remove('active'));
    
    const target = document.getElementById(targetId);
    if (target) target.style.display = 'block';
    if (btn) btn.classList.add('active');
}

/**
 * Abrir modal de creación de usuario
 */
function abrirModalNuevoUsuario() {
    const form = document.getElementById('form-nuevo-usuario');
    if (form) form.reset();
    
    // Seleccionar vendedor por defecto
    const radioVendedor = document.getElementById('role-new-vendedor');
    if (radioVendedor) {
        radioVendedor.checked = true;
        actualizarEstiloRadioRoles('form-nuevo-usuario');
    }
    abrirModal(modalNuevoUsuario);
}

/**
 * Abrir modal de edición de usuario
 */
function abrirModalEditar(id, usuario, rolActual) {
    document.getElementById('edit-user-id').value = id;
    document.getElementById('edit-username').value = usuario;
    document.getElementById('edit-password').value = '';
    
    const radioAdmin = document.getElementById('role-edit-admin');
    const radioVendedor = document.getElementById('role-edit-vendedor');
    
    const isAdmin = rolActual.toLowerCase() === 'administrador' || rolActual.toLowerCase() === 'admin';
    if (isAdmin && radioAdmin) radioAdmin.checked = true;
    if (!isAdmin && radioVendedor) radioVendedor.checked = true;

    actualizarEstiloRadioRoles('form-editar-usuario');
    abrirModal(modalEditarUsuario);
}

/**
 * Eliminar usuario con confirmación
 */
async function eliminarUsuario(id, usuario) {
    if (!confirm(`⚠️ ¿Estás seguro de que deseás eliminar la cuenta del usuario '${usuario}'?\nEsta acción no se puede deshacer.`)) {
        return;
    }

    try {
        const response = await fetch(`api/users.php?id=${id}`, {
            method: 'DELETE'
        });
        const data = await response.json();

        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'Error al eliminar usuario.');
        }

        showToast(`🗑️ Usuario '${usuario}' eliminado correctamente.`);
        await cargarUsuarios();
        cargarActividades();
    } catch (error) {
        showToast('❌ ' + error.message, true);
    }
}

/**
 * Utilidades para Modales
 */
function abrirModal(modal) {
    if (!modal) return;
    modal.classList.add('active');
}

function cerrarModal(modal) {
    if (!modal) return;
    modal.classList.remove('active');
}

function actualizarEstiloRadioRoles(formId) {
    const form = document.getElementById(formId);
    if (!form) return;
    form.querySelectorAll('.role-radio-card').forEach(card => {
        const radio = card.querySelector('input[type="radio"]');
        if (radio && radio.checked) {
            card.classList.add('selected');
        } else {
            card.classList.remove('selected');
        }
    });
}

/**
 * Escapar HTML para prevenir XSS
 */
function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// ==========================================================================
// Event Listeners e Inicialización
// ==========================================================================
document.addEventListener('DOMContentLoaded', () => {
    // 1. Cargar datos iniciales
    cargarUsuarios();
    cargarActividades();

    // 2. Buscadores en vivo
    if (inputSearchUser) {
        inputSearchUser.addEventListener('input', renderTablaUsuarios);
    }
    if (inputSearchActivity) {
        inputSearchActivity.addEventListener('input', renderActividades);
    }

    // 3. Filtros por rol
    document.querySelectorAll('.chip-filter[data-filter-role]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            document.querySelectorAll('.chip-filter[data-filter-role]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filtroRolActual = btn.getAttribute('data-filter-role');
            renderTablaUsuarios();
        });
    });

    // 4. Filtros de actividades
    document.querySelectorAll('.chip-filter[data-filter-act]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            document.querySelectorAll('.chip-filter[data-filter-act]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filtroTipoActividad = btn.getAttribute('data-filter-act');
            cargarActividades();
        });
    });

    // 5. Cambio de pestañas principales (Tabs)
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetTab = btn.getAttribute('data-tab');
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            btn.classList.add('active');
            const content = document.getElementById(targetTab);
            if (content) content.classList.add('active');

            if (targetTab === 'tab-actividades') {
                cargarActividades();
            }
        });
    });

    // 6. Cierre de modales con botones de cerrar o backdrop
    document.querySelectorAll('.modal-close, [data-modal-close]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const modal = btn.closest('.modal-overlay');
            if (modal) cerrarModal(modal);
        });
    });

    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                cerrarModal(modal);
            }
        });
    });

    // 7. Radio cards selector de roles
    document.querySelectorAll('.role-radio-card').forEach(card => {
        card.addEventListener('click', () => {
            const radio = card.querySelector('input[type="radio"]');
            if (radio) {
                radio.checked = true;
                const form = card.closest('form');
                if (form) actualizarEstiloRadioRoles(form.id);
            }
        });
    });

    // 8. Formulario Crear Nuevo Usuario
    const formNuevoUsuario = document.getElementById('form-nuevo-usuario');
    if (formNuevoUsuario) {
        formNuevoUsuario.addEventListener('submit', async (e) => {
            e.preventDefault();
            const usuario = document.getElementById('new-username').value.trim();
            const password = document.getElementById('new-password').value;
            const role = document.querySelector('input[name="new-role"]:checked')?.value || 'vendedor';

            if (!usuario || !password) {
                showToast('❌ Completá todos los campos.', true);
                return;
            }

            try {
                const res = await fetch('api/users.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ usuario, password, role })
                });
                const data = await res.json();

                if (!res.ok || !data.ok) {
                    throw new Error(data.message || 'Error al crear usuario.');
                }

                showToast(`✅ Usuario '${usuario}' creado con éxito.`);
                cerrarModal(modalNuevoUsuario);
                await cargarUsuarios();
                cargarActividades();
            } catch (err) {
                showToast('❌ ' + err.message, true);
            }
        });
    }

    // 9. Formulario Editar Usuario
    const formEditarUsuario = document.getElementById('form-editar-usuario');
    if (formEditarUsuario) {
        formEditarUsuario.addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('edit-user-id').value;
            const usuario = document.getElementById('edit-username').value.trim();
            const password = document.getElementById('edit-password').value;
            const role = document.querySelector('input[name="edit-role"]:checked')?.value || 'vendedor';

            const payload = { id: parseInt(id), usuario, role };
            if (password && password.trim() !== '') {
                payload.password = password;
            }

            try {
                const res = await fetch('api/users.php', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();

                if (!res.ok || !data.ok) {
                    throw new Error(data.message || 'Error al actualizar usuario.');
                }

                showToast(`✅ Usuario '${usuario}' actualizado.`);
                cerrarModal(modalEditarUsuario);
                await cargarUsuarios();
                cargarActividades();
            } catch (err) {
                showToast('❌ ' + err.message, true);
            }
        });
    }
});
