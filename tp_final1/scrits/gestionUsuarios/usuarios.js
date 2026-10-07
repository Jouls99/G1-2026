/**
 * Consola de Super Admin: Gestión de Usuarios y Monitoreo de Logins
 * SOS Cosméticos - Exclusivo Super Administrador
 */

// Estado global de la vista
let listaUsuarios = [];

let filtroRolActual = 'todos';
let usuarioSeleccionado = null;
const usuarioEsSuperAdmin = window.usuarioEsSuperAdmin === true;

// Elementos del DOM
const tbodyUsuarios = document.getElementById('tbody-usuarios');
const inputSearchUser = document.getElementById('search-user');

// KPI elements
const kpiTotalUsers = document.getElementById('kpi-total-users');
const kpiAdmins = document.getElementById('kpi-admins');
const kpiVendedores = document.getElementById('kpi-vendedores');
const kpiLoginsHoy = document.getElementById('kpi-logins-hoy');
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

function normalizarRol(role) {
    const normalized = (role || 'vendedor').trim().toLowerCase();
    if (['superadmin', 'super administrador', 'super_admin', 'super-admin'].includes(normalized)) return 'superadmin';
    if (['administrador', 'admin'].includes(normalized)) return 'administrador';
    return 'vendedor';
}

function rolOcupado(role, exceptUserId = 0) {
    if (role === 'vendedor') return false;
    return listaUsuarios.some(user => Number(user.id) !== Number(exceptUserId) && normalizarRol(user.role) === role);
}

function actualizarDisponibilidadRoles(formId, exceptUserId = 0) {
    const form = document.getElementById(formId);
    if (!form) return;
    form.querySelectorAll('input[type="radio"][name$="role"]').forEach(radio => {
        const occupied = rolOcupado(normalizarRol(radio.value), exceptUserId);
        radio.disabled = occupied;
        radio.closest('.role-radio-card')?.classList.toggle('disabled', occupied);
    });
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
 * Calcular tiempo transcurrido relativo (hace X minutos, hoy a las...)
 */
function timeAgo(isoString) {
    if (!isoString) return 'Sin registros';
    try {
        const d = new Date(isoString);
        if (isNaN(d.getTime())) return isoString;
        const now = new Date();
        const diffMs = now - d;
        const diffMins = Math.floor(diffMs / 60000);
        const diffHours = Math.floor(diffMins / 60);
        const diffDays = Math.floor(diffHours / 24);

        if (diffMins < 1) return '🟢 Justo ahora';
        if (diffMins < 60) return `Hace ${diffMins} min`;
        if (diffHours < 24) return `Hace ${diffHours} h`;
        if (diffDays === 1) return 'Ayer';
        if (diffDays < 7) return `Hace ${diffDays} días`;
        return formatDate(isoString);
    } catch (e) {
        return isoString;
    }
}

/**
 * Interpretar User Agent para extraer Navegador y Sistema Operativo amigable
 */
function parseUserAgent(ua) {
    if (!ua || typeof ua !== 'string') return 'Navegador Web';

    let os = 'PC';
    if (/windows/i.test(ua)) os = 'Windows';
    else if (/android/i.test(ua)) os = 'Android';
    else if (/iphone|ipad|ipod/i.test(ua)) os = 'iOS';
    else if (/macintosh|mac os x/i.test(ua)) os = 'Mac';
    else if (/linux/i.test(ua)) os = 'Linux';

    let browser = 'Web';
    if (/edg/i.test(ua)) browser = 'Edge';
    else if (/chrome|crios/i.test(ua)) browser = 'Chrome';
    else if (/firefox|fxios/i.test(ua)) browser = 'Firefox';
    else if (/safari/i.test(ua)) browser = 'Safari';
    else if (/opera|opr/i.test(ua)) browser = 'Opera';

    return `${browser} (${os})`;
}

/**
 * Cargar usuarios y sus estadísticas desde la API
 */
async function cargarUsuarios() {
    try {
        const response = await fetch('api/users.php', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || (data && !Array.isArray(data) && data.ok === false)) {
            if (response.status === 403) {
                window.location.href = 'venta.php?error=unauthorized';
                return;
            }
            throw new Error((data && data.message) || 'Error al conectar con la API de usuarios.');
        }
        listaUsuarios = Array.isArray(data) ? data : [];
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
    const superAdmins = listaUsuarios.filter(u => (u.role || '').toLowerCase() === 'superadmin').length;
    const regularAdmins = listaUsuarios.filter(u => (u.role || '').toLowerCase() === 'administrador' || (u.role || '').toLowerCase() === 'admin').length;
    const vendedores = listaUsuarios.filter(u => (u.role || '').toLowerCase() === 'vendedor').length;
    const totalVentasDinero = listaUsuarios.reduce((sum, u) => sum + (parseFloat(u.total_facturado) || 0), 0);
    const loginsHoy = metricasGenerales.logins_hoy ?? listaUsuarios.reduce((sum, u) => sum + (parseInt(u.total_logins) || 0), 0);

    if (kpiTotalUsers) kpiTotalUsers.textContent = totalUsers;
    if (kpiAdmins) kpiAdmins.textContent = `${superAdmins + regularAdmins} (${superAdmins} Super)`;
    if (kpiVendedores) kpiVendedores.textContent = vendedores;
    if (kpiLoginsHoy) kpiLoginsHoy.textContent = loginsHoy;
    if (kpiVentasTotal) kpiVentasTotal.textContent = formatMoney(totalVentasDinero);
}

/**
 * Renderizar la tabla de usuarios con filtros y selector de rol
 */
function renderTablaUsuarios() {
    if (!tbodyUsuarios) return;

    const query = (inputSearchUser ? inputSearchUser.value.trim().toLowerCase() : '');
    
    let filtrados = listaUsuarios.filter(u => {
        const coincideNombre = (u.usuario || '').toLowerCase().includes(query);
        let rolNorm = (u.role || 'vendedor').toLowerCase();
        if (rolNorm === 'admin') rolNorm = 'administrador';
        
        let coincideRol = true;
        if (filtroRolActual !== 'todos') {
            coincideRol = (rolNorm === filtroRolActual);
        }
        return coincideNombre && coincideRol;
    });

    if (filtrados.length === 0) {
        tbodyUsuarios.innerHTML = `
            <tr>
                    <td colspan="${usuarioEsSuperAdmin ? 8 : 6}" style="text-align: center; padding: 36px 16px; color: #6b7280;">
                    <div style="font-size: 2rem; margin-bottom: 8px;">🔍</div>
                    <strong>No se encontraron usuarios que coincidan con los filtros.</strong>
                </td>
            </tr>
        `;
        return;
    }

    tbodyUsuarios.innerHTML = filtrados.map(u => {
        const role = (u.role || 'vendedor').toLowerCase();
        const adminOcupado = rolOcupado('administrador', u.id);
        const superadminOcupado = rolOcupado('superadmin', u.id);
        const isSuper = role === 'superadmin' || role === 'super administrador';
        const isAdmin = isSuper || role === 'administrador' || role === 'admin';
        
        let roleBadgeHtml = '';
        let roleSelectClass = 'role-vendedor';

        if (isSuper) {
            roleBadgeHtml = `<span class="badge-role superadmin">👑 Super Admin</span>`;
            roleSelectClass = 'role-superadmin';
        } else if (isAdmin) {
            roleBadgeHtml = `<span class="badge-role admin">🛡️ Administrador</span>`;
            roleSelectClass = 'role-admin';
        } else {
            roleBadgeHtml = `<span class="badge-role vendedor">🛒 Vendedor</span>`;
            roleSelectClass = 'role-vendedor';
        }

        const avatarInitial = u.usuario ? u.usuario.charAt(0).toUpperCase() : '?';
        const lastLoginStr = u.ultimo_acceso || u.lastLogin;

        return `
            <tr data-user-id="${u.id}">
                <td>
                    <div class="user-profile-cell">
                        <div class="avatar-bubble ${isSuper ? 'superadmin' : (isAdmin ? 'admin' : 'vendedor')}">${avatarInitial}</div>
                        <div>
                            <div class="user-details-title">${escapeHtml(u.usuario)}</div>
                            <div class="user-details-sub">ID: #${u.id}</div>
                        </div>
                    </div>
                </td>
                <td>
                    ${roleBadgeHtml}
                </td>
                <td>
                    <select class="role-select-box ${roleSelectClass}" onchange="cambiarRolUsuario(${u.id}, '${escapeHtml(u.usuario)}', this.value)">
                        <option value="vendedor" ${role === 'vendedor' ? 'selected' : ''}>🛒 Vendedor</option>
                        <option value="administrador" ${role === 'administrador' || role === 'admin' ? 'selected' : ''} ${adminOcupado ? 'disabled' : ''}>🛡️ Administrador</option>
                        <option value="superadmin" ${isSuper ? 'selected' : ''} ${superadminOcupado ? 'disabled' : ''}>👑 Super Admin</option>
                    </select>
                </td>
                <td>
                    <span style="font-size: 0.88rem; color: #4b5563;">
                        ${formatDate(u.fecha_creacion || u.createdAt)}
                    </span>
                </td>
                ${usuarioEsSuperAdmin ? `<td>
                    <div style="font-size: 0.88rem; font-weight: 600; color: ${lastLoginStr ? '#1e1b4b' : '#9ca3af'};">
                        ${formatDate(lastLoginStr)}
                    </div>
                    <small style="color: #6b7280;">${timeAgo(lastLoginStr)}</small>
                </td>
                <td>
                    <div class="login-count-badge" title="Total de inicios de sesión registrados">
                        🔑 <strong>${u.total_logins || 0}</strong> accesos
                    </div>
                </td>` : ''}
                <td>
                    <div style="font-weight: 700; color: #166534;">
                        ${formatMoney(u.total_facturado)}
                    </div>
                    <small style="color: #6b7280;">${u.total_ventas || 0} ventas</small>
                </td>
                <td>
                    <div class="table-actions">
                        ${usuarioEsSuperAdmin ? `<button type="button" class="btn-action-sm btn-inspect" onclick="verDetalleUsuario('${escapeHtml(u.usuario)}')" title="Ver detalle, historial de logins y ventas">
                            🔍 Inspeccionar
                        </button>` : ''}
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
 * Cambiar el rol de un usuario de forma instantánea (Solo Super Admin)
 */
async function cambiarRolUsuario(id, usuario, nuevoRol) {
    const normalizedRole = normalizarRol(nuevoRol);
    if (rolOcupado(normalizedRole, id)) {
        showToast(`❌ Ya existe una cuenta con el rol ${normalizedRole}.`, true);
        await cargarUsuarios();
        return;
    }

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
        cargarLoginsYActividades();
    } catch (error) {
        showToast('❌ ' + error.message, true);
        cargarUsuarios();
    }
}

/**
 * Abrir modal de detalle completo de interacciones, logins y ventas de un usuario específico
 */
async function verDetalleUsuario(username) {
    if (!modalDetalleUsuario) return;

    const modalBody = document.getElementById('detalle-usuario-body');
    const modalTitle = document.getElementById('detalle-usuario-title');
    
    if (modalTitle) modalTitle.textContent = `Consola de Auditoría: ${username}`;
    if (modalBody) {
        modalBody.innerHTML = `
            <div style="text-align: center; padding: 40px;">
                <div style="font-size: 2rem; margin-bottom: 12px;">⏳</div>
                <p>Cargando información, historial de logins y ventas de <strong>${escapeHtml(username)}</strong>...</p>
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
        const logins = data.logins || [];

        const isSuper = (u.rol || '').toLowerCase().includes('super');
        const isAdmin = isSuper || (u.rol || '').toLowerCase() === 'administrador' || (u.rol || '').toLowerCase() === 'admin';

        modalBody.innerHTML = `
            <div class="user-summary-card">
                <div class="name-and-role">
                    <div class="avatar-bubble ${isSuper ? 'superadmin' : (isAdmin ? 'admin' : 'vendedor')}" style="width: 52px; height: 52px; font-size: 1.4rem;">
                        ${u.nombre.charAt(0).toUpperCase()}
                    </div>
                    <div>
                        <h3 style="margin: 0; color: #1e1b4b; font-size: 1.3rem;">${escapeHtml(u.nombre)}</h3>
                        <span class="badge-role ${isSuper ? 'superadmin' : (isAdmin ? 'admin' : 'vendedor')}">
                            ${isSuper ? '👑 Super Admin' : (isAdmin ? '🛡️ Administrador' : '🛒 Vendedor')}
                        </span>
                    </div>
                </div>
                <div style="font-size: 0.85rem; color: #4b5563;">
                    <div>📅 Fecha de Registro: <strong>${formatDate(u.fecha_creacion)}</strong></div>
                    <div>🔑 Último Inicio de Sesión: <strong>${formatDate(u.ultimo_acceso)}</strong> <small>(${timeAgo(u.ultimo_acceso)})</small></div>
                </div>
            </div>

            <div class="user-stats-mini-grid">
                <div class="stat-mini-box">
                    <small>Logins Registrados</small>
                    <strong style="color: #d97706;">🔑 ${m.total_logins || logins.length} accesos</strong>
                </div>
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

            <!-- Sub-tabs del detalle -->
            <div style="display: flex; gap: 8px; margin-bottom: 14px; flex-wrap: wrap;">
                <button type="button" class="chip-filter active" onclick="cambiarSubtabDetalle(this, 'detalle-logins')">
                    🔑 Historial de Logins (${logins.length})
                </button>
                <button type="button" class="chip-filter" onclick="cambiarSubtabDetalle(this, 'detalle-ventas')">
                    🛒 Historial de Ventas (${ventas.length})
                </button>
                <button type="button" class="chip-filter" onclick="cambiarSubtabDetalle(this, 'detalle-actividades')">
                    ⚡ Registro de Actividades (${actividades.length})
                </button>
            </div>

            <!-- Panel 1: Logins del usuario -->
            <div id="detalle-logins" class="detalle-subtab-content" style="display: block;">
                ${logins.length === 0 ? `
                    <p style="text-align: center; color: #6b7280; padding: 24px; background: #fafafa; border-radius: 8px;">No hay registros de inicio de sesión para este usuario.</p>
                ` : `
                    <div style="max-height: 280px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                            <thead style="background: #fef3c7; position: sticky; top: 0; z-index: 2;">
                                <tr>
                                    <th style="padding: 8px 12px; text-align: left; border-bottom: 1px solid #fcd34d;">Fecha y Hora</th>
                                    <th style="padding: 8px 12px; text-align: left; border-bottom: 1px solid #fcd34d;">Dirección IP</th>
                                    <th style="padding: 8px 12px; text-align: left; border-bottom: 1px solid #fcd34d;">Dispositivo / Navegador</th>
                                    <th style="padding: 8px 12px; text-align: left; border-bottom: 1px solid #fcd34d;">Evento</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${logins.map(l => `
                                    <tr style="border-bottom: 1px solid #f1f5f9;">
                                        <td style="padding: 8px 12px; font-weight: 600;">${formatDate(l.fecha)} <br><small style="color:#6b7280; font-weight:normal;">${timeAgo(l.fecha)}</small></td>
                                        <td style="padding: 8px 12px;"><span class="ip-chip">🌐 ${escapeHtml(l.ip || '127.0.0.1')}</span></td>
                                        <td style="padding: 8px 12px;"><span class="device-chip">💻 ${escapeHtml(parseUserAgent(l.user_agent))}</span></td>
                                        <td style="padding: 8px 12px;">${escapeHtml(l.descripcion)}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                `}
            </div>

            <!-- Panel 2: Ventas del usuario -->
            <div id="detalle-ventas" class="detalle-subtab-content" style="display: none;">
                ${ventas.length === 0 ? `
                    <p style="text-align: center; color: #6b7280; padding: 24px; background: #fafafa; border-radius: 8px;">Este usuario aún no ha registrado ventas.</p>
                ` : `
                    <div style="max-height: 280px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
                            <thead style="background: #faf5ff; position: sticky; top: 0; z-index: 2;">
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

            <!-- Panel 3: Actividades del usuario -->
            <div id="detalle-actividades" class="detalle-subtab-content" style="display: none;">
                ${actividades.length === 0 ? `
                    <p style="text-align: center; color: #6b7280; padding: 24px; background: #fafafa; border-radius: 8px;">No hay registros de actividad general para este usuario.</p>
                ` : `
                    <div style="max-height: 280px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px;">
                        ${actividades.map(a => `
                            <div style="background: #f8fafc; border-left: 3px solid #7c3aed; padding: 8px 12px; border-radius: 4px; font-size: 0.88rem;">
                                <div style="display: flex; justify-content: space-between; color: #6b7280; font-size: 0.78rem; margin-bottom: 2px;">
                                    <strong>${a.tipo}</strong>
                                    <span>${formatDate(a.fecha)} (${timeAgo(a.fecha)})</span>
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
 * Abrir modal de creación de usuario con selector de 3 roles
 */
function abrirModalNuevoUsuario() {
    const form = document.getElementById('form-nuevo-usuario');
    if (form) form.reset();
    
    const radioVendedor = document.getElementById('role-new-vendedor');
    if (radioVendedor) {
        radioVendedor.checked = true;
        actualizarEstiloRadioRoles('form-nuevo-usuario');
    }
    actualizarDisponibilidadRoles('form-nuevo-usuario');
    abrirModal(modalNuevoUsuario);
}

/**
 * Abrir modal de edición de usuario con selector de 3 roles
 */
function abrirModalEditar(id, usuario, rolActual) {
    const formEditar = document.getElementById('form-editar-usuario');
    if (formEditar) formEditar.dataset.rolActual = rolActual;
    document.getElementById('edit-user-id').value = id;
    document.getElementById('edit-username').value = usuario;
    document.getElementById('edit-password').value = '';
    
    const radioSuper = document.getElementById('role-edit-superadmin');
    const radioAdmin = document.getElementById('role-edit-admin');
    const radioVendedor = document.getElementById('role-edit-vendedor');
    
    const roleNorm = (rolActual || '').toLowerCase();
    if (roleNorm === 'superadmin' || roleNorm === 'super administrador') {
        if (radioSuper) radioSuper.checked = true;
    } else if (roleNorm === 'administrador' || roleNorm === 'admin') {
        if (radioAdmin) radioAdmin.checked = true;
    } else {
        if (radioVendedor) radioVendedor.checked = true;
    }

    actualizarEstiloRadioRoles('form-editar-usuario');
    actualizarDisponibilidadRoles('form-editar-usuario', id);
    abrirModal(modalEditarUsuario);
}

/**
 * Eliminar usuario con confirmación de seguridad
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
        cargarLoginsYActividades();
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
    cargarLoginsYActividades();

    // 2. Buscadores en vivo
    if (inputSearchUser) {
        inputSearchUser.addEventListener('input', renderTablaUsuarios);
    }
    if (inputSearchLogin) {
        inputSearchLogin.addEventListener('input', renderTablaLogins);
    }
    if (inputSearchActivity) {
        inputSearchActivity.addEventListener('input', renderActividades);
    }

    // 3. Filtros por rol en usuarios
    document.querySelectorAll('.chip-filter[data-filter-role]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            document.querySelectorAll('.chip-filter[data-filter-role]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filtroRolActual = btn.getAttribute('data-filter-role');
            renderTablaUsuarios();
        });
    });

    // 4. Filtros de logins
    document.querySelectorAll('.chip-filter[data-filter-login]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            document.querySelectorAll('.chip-filter[data-filter-login]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filtroLoginActual = btn.getAttribute('data-filter-login');
            renderTablaLogins();
        });
    });

    // 5. Filtros de actividades
    document.querySelectorAll('.chip-filter[data-filter-act]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            document.querySelectorAll('.chip-filter[data-filter-act]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filtroTipoActividad = btn.getAttribute('data-filter-act');
            cargarLoginsYActividades();
        });
    });

    // 6. Cambio de pestañas principales (Tabs)
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const targetTab = btn.getAttribute('data-tab');
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

            btn.classList.add('active');
            btn.setAttribute('aria-selected', 'true');
            const content = document.getElementById(targetTab);
            if (content) {
                content.classList.add('active');
                content.removeAttribute('aria-hidden');
            }
            document.querySelectorAll('.tab-content').forEach(c => {
                if (c.id !== targetTab) {
                    c.setAttribute('aria-hidden', 'true');
                }
            });
            document.querySelectorAll('.tab-btn').forEach(b => {
                if (b !== btn) b.setAttribute('aria-selected', 'false');
            });

            if (targetTab === 'tab-logins') {
                renderTablaLogins();
            } else if (targetTab === 'tab-actividades') {
                renderActividades();
            }
        });
    });

    // 7. Cierre de modales
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

    // 8. Radio cards selector de roles
    document.querySelectorAll('.role-radio-card').forEach(card => {
        card.addEventListener('click', () => {
            const radio = card.querySelector('input[type="radio"]');
            if (radio && !radio.disabled) {
                radio.checked = true;
                const form = card.closest('form');
                if (form) actualizarEstiloRadioRoles(form.id);
            }
        });
    });

    // 9. Formulario Crear Nuevo Usuario
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
            if (rolOcupado(normalizarRol(role))) {
                showToast(`❌ Ya existe una cuenta con el rol ${normalizarRol(role)}.`, true);
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

                showToast(`✅ Usuario '${usuario}' creado con rol '${role}'.`);
                cerrarModal(modalNuevoUsuario);
                await cargarUsuarios();
                cargarLoginsYActividades();
            } catch (err) {
                showToast('❌ ' + err.message, true);
            }
        });
    }

    // 10. Formulario Editar Usuario
    const formEditarUsuario = document.getElementById('form-editar-usuario');
    if (formEditarUsuario) {
        formEditarUsuario.addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('edit-user-id').value;
            const usuario = document.getElementById('edit-username').value.trim();
            const password = document.getElementById('edit-password').value;
            const role = document.querySelector('input[name="edit-role"]:checked')?.value || 'vendedor';

            if (rolOcupado(normalizarRol(role), id)) {
                showToast(`❌ Ya existe otra cuenta con el rol ${normalizarRol(role)}.`, true);
                return;
            }

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

                showToast(`✅ Usuario '${usuario}' actualizado correctamente.`);
                cerrarModal(modalEditarUsuario);
                await cargarUsuarios();
                cargarLoginsYActividades();
            } catch (err) {
                showToast('❌ ' + err.message, true);
            }
        });
    }
});