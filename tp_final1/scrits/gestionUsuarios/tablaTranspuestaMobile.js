/**
 * Gestión Móvil de Usuarios: Pantalla de Tabla Transpuesta
 * SOS Cosméticos - Exclusivo para Celulares
 */

(function () {
    'use strict';

    // Estado global de la vista móvil
    let listaUsuarios = [];
    let filtroRolActual = 'todos';
    const esSuperAdmin = window.usuarioEsSuperAdmin === true;

    // Elementos del DOM
    const contenedorTranspuestas = document.getElementById('contenedor-tablas-transpuestas');
    const inputSearch = document.getElementById('mobile-search-input');
    const btnClearSearch = document.getElementById('btn-clear-search');
    const desktopWarning = document.getElementById('desktop-only-warning');
    const btnActivarSimulador = document.getElementById('btn-activar-simulador');
    const toastElement = document.getElementById('mobile-toast');
    const toastMsg = document.getElementById('mobile-toast-msg');

    // KPIs
    const kpiTotalUsers = document.getElementById('mkpi-total-users');
    const kpiAdmins = document.getElementById('mkpi-admins');
    const kpiVendedores = document.getElementById('mkpi-vendedores');
    const kpiVentasTotal = document.getElementById('mkpi-ventas-total');

    // Modales
    const modalNuevo = document.getElementById('modal-nuevo-usuario-mobile');
    const modalEditar = document.getElementById('modal-editar-usuario-mobile');
    const modalDetalle = document.getElementById('modal-detalle-usuario-mobile');
    const modalCredenciales = document.getElementById('modal-credenciales-mobile');

    /**
     * Muestra mensaje Toast flotante
     */
    function showToast(mensaje, esError = false) {
        if (!toastElement || !toastMsg) return;
        toastMsg.textContent = mensaje;
        toastElement.className = `mobile-toast active ${esError ? 'error' : ''}`;
        setTimeout(() => {
            toastElement.className = 'mobile-toast';
        }, 3500);
    }

    /**
     * Formateo de dinero en Pesos Argentinos
     */
    function formatMoney(amount) {
        return new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(amount || 0);
    }

    /**
     * Formateo de fechas
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
            if (diffDays < 7) return `Hace ${diffDays} d`;
            return formatDate(isoString);
        } catch (e) {
            return isoString;
        }
    }

    function escapeHtml(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, m => map[m]);
    }

    function normalizarRol(role) {
        const normalized = (role || 'vendedor').trim().toLowerCase();
        if (['superadmin', 'super administrador', 'super_admin', 'super-admin'].includes(normalized)) return 'superadmin';
        if (['administrador', 'admin'].includes(normalized)) return 'administrador';
        return 'vendedor';
    }

    function rolOcupado(role, exceptUserId = 0) {
        if (role === 'vendedor') return false;
        return listaUsuarios.some(user => Number(user.id_usuario || user.id) !== Number(exceptUserId) && normalizarRol(user.role || user.rol) === role);
    }

    /**
     * Cargar usuarios desde la API
     */
    async function cargarUsuarios() {
        if (contenedorTranspuestas) {
            contenedorTranspuestas.innerHTML = `
                <div class="tabla-transpuesta-vacia">
                    <div style="font-size: 2rem; margin-bottom: 8px;">⏳</div>
                    <strong>Cargando usuarios del sistema...</strong>
                </div>
            `;
        }

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
            actualizarKPIs();
            renderTablasTranspuestas();
        } catch (error) {
            console.error('Error cargando usuarios:', error);
            showToast('❌ No se pudieron cargar los usuarios: ' + error.message, true);
            if (contenedorTranspuestas) {
                contenedorTranspuestas.innerHTML = `
                    <div class="tabla-transpuesta-vacia" style="color: #be123c;">
                        <div style="font-size: 2rem; margin-bottom: 8px;">⚠️</div>
                        <strong>Error al cargar usuarios</strong>
                        <p style="margin: 4px 0 0; font-size: 0.85rem;">${escapeHtml(error.message)}</p>
                    </div>
                `;
            }
        }
    }

    /**
     * Actualiza métricas de la barra superior
     */
    function actualizarKPIs() {
        const total = listaUsuarios.length;
        const superAdmins = listaUsuarios.filter(u => normalizarRol(u.role || u.rol) === 'superadmin').length;
        const regularAdmins = listaUsuarios.filter(u => normalizarRol(u.role || u.rol) === 'administrador').length;
        const vendedores = listaUsuarios.filter(u => normalizarRol(u.role || u.rol) === 'vendedor').length;
        const facturado = listaUsuarios.reduce((acc, u) => acc + (parseFloat(u.total_facturado) || 0), 0);

        if (kpiTotalUsers) kpiTotalUsers.textContent = total;
        if (kpiAdmins) kpiAdmins.textContent = `${superAdmins + regularAdmins}`;
        if (kpiVendedores) kpiVendedores.textContent = vendedores;
        if (kpiVentasTotal) kpiVentasTotal.textContent = formatMoney(facturado);
    }

    /**
     * Renderizado de las Tablas Transpuestas en Celular
     */
    function renderTablasTranspuestas() {
        if (!contenedorTranspuestas) return;

        const query = (inputSearch ? inputSearch.value.trim().toLowerCase() : '');

        const filtrados = listaUsuarios.filter(u => {
            const nombre = (u.usuario || u.nombre || '').toLowerCase();
            const coincideNombre = nombre.includes(query);
            const rolNorm = normalizarRol(u.role || u.rol);

            let coincideRol = true;
            if (filtroRolActual !== 'todos') {
                coincideRol = (rolNorm === filtroRolActual);
            }
            return coincideNombre && coincideRol;
        });

        if (filtrados.length === 0) {
            contenedorTranspuestas.innerHTML = `
                <div class="tabla-transpuesta-vacia">
                    <div style="font-size: 2.2rem; margin-bottom: 8px;">🔍</div>
                    <strong>No se encontraron usuarios</strong>
                    <p style="margin: 4px 0 0; font-size: 0.84rem; color: #94a3b8;">Probá modificando el término de búsqueda o el filtro de rol.</p>
                </div>
            `;
            return;
        }

        contenedorTranspuestas.innerHTML = filtrados.map(u => {
            const id = u.id_usuario || u.id;
            const usuarioNombre = u.usuario || u.nombre || '';
            const roleNorm = normalizarRol(u.role || u.rol);
            const isSuper = roleNorm === 'superadmin';
            const isAdmin = isSuper || roleNorm === 'administrador';

            const adminOcupado = rolOcupado('administrador', id);
            const superadminOcupado = rolOcupado('superadmin', id);

            let badgeHtml = '';
            if (isSuper) {
                badgeHtml = `<span class="badge-role-mobile superadmin">👑 Super Admin</span>`;
            } else if (isAdmin) {
                badgeHtml = `<span class="badge-role-mobile admin">🛡️ Administrador</span>`;
            } else {
                badgeHtml = `<span class="badge-role-mobile vendedor">🛒 Vendedor</span>`;
            }

            const avatarInitial = usuarioNombre ? usuarioNombre.charAt(0).toUpperCase() : '?';
            const fechaAlta = formatDate(u.fecha_creacion || u.createdAt);
            const facturadoStr = formatMoney(u.total_facturado);
            const cantVentas = u.total_ventas || 0;
            const ultimoLogin = u.ultimo_acceso || u.lastLogin;

            return `
                <table class="tabla-transpuesta" data-user-id="${id}">
                    <tbody>
                        <tr>
                            <th scope="row">👤 Usuario</th>
                            <td>
                                <div class="mobile-user-cell">
                                    <div class="avatar-circle ${isSuper ? 'superadmin' : (isAdmin ? 'admin' : 'vendedor')}">
                                        ${avatarInitial}
                                    </div>
                                    <div>
                                        <div class="user-name-title">${escapeHtml(usuarioNombre)}</div>
                                        <div class="user-id-sub">ID: #${id}</div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">🏷️ Rol actual</th>
                            <td>${badgeHtml}</td>
                        </tr>
                        <tr>
                            <th scope="row">🔄 Cambiar rol</th>
                            <td>
                                <select class="role-select-mobile" data-user-id="${id}" data-user-name="${escapeHtml(usuarioNombre)}">
                                    <option value="vendedor" ${roleNorm === 'vendedor' ? 'selected' : ''}>🛒 Vendedor</option>
                                    <option value="administrador" ${roleNorm === 'administrador' ? 'selected' : ''} ${adminOcupado ? 'disabled' : ''}>🛡️ Administrador</option>
                                    <option value="superadmin" ${isSuper ? 'selected' : ''} ${superadminOcupado ? 'disabled' : ''}>👑 Super Admin</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">📅 Creado</th>
                            <td><span style="font-size: 0.84rem; color: #475569;">${fechaAlta}</span></td>
                        </tr>
                        <tr>
                            <th scope="row">💰 Ventas</th>
                            <td>
                                <strong style="color: #15803d;">${facturadoStr}</strong>
                                <small style="display: block; color: #64748b;">${cantVentas} ventas realizadas</small>
                            </td>
                        </tr>
                        ${esSuperAdmin ? `
                        <tr>
                            <th scope="row">🔑 Accesos</th>
                            <td>
                                <div style="font-size: 0.84rem; font-weight: 600; color: #1e1b4b;">
                                    ${formatDate(ultimoLogin)}
                                </div>
                                <small style="color: #64748b;">${timeAgo(ultimoLogin)} (${u.total_logins || 0} logins)</small>
                            </td>
                        </tr>
                        ` : ''}
                        <tr>
                            <th scope="row">⚡ Acciones</th>
                            <td>
                                <div class="mobile-card-actions">
                                    <button type="button" class="btn-m-action btn-m-edit" data-edit-id="${id}" data-edit-name="${escapeHtml(usuarioNombre)}" data-edit-role="${roleNorm}">
                                        ✏️ Editar
                                    </button>
                                    <button type="button" class="btn-m-action btn-m-del" data-del-id="${id}" data-del-name="${escapeHtml(usuarioNombre)}">
                                        🗑️ Borrar
                                    </button>
                                    <button type="button" class="btn-m-action btn-m-inspect" data-inspect-name="${escapeHtml(usuarioNombre)}">
                                        🔍 Ver
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            `;
        }).join('');

        // Adjuntar eventos interactivos dentro de las tablas transpuestas
        attachEventosTablasTranspuestas();
    }

    /**
     * Asignar listeners a controles generados en las tablas
     */
    function attachEventosTablasTranspuestas() {
        if (!contenedorTranspuestas) return;

        // 1. Selector de rol
        contenedorTranspuestas.querySelectorAll('.role-select-mobile').forEach(sel => {
            sel.addEventListener('change', async (e) => {
                const id = sel.getAttribute('data-user-id');
                const nombre = sel.getAttribute('data-user-name');
                const nuevoRol = sel.value;
                await cambiarRol(id, nombre, nuevoRol);
            });
        });

        // 2. Botón Editar
        contenedorTranspuestas.querySelectorAll('.btn-m-edit').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-edit-id');
                const nombre = btn.getAttribute('data-edit-name');
                const rol = btn.getAttribute('data-edit-role');
                abrirModalEditar(id, nombre, rol);
            });
        });

        // 3. Botón Eliminar
        contenedorTranspuestas.querySelectorAll('.btn-m-del').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-del-id');
                const nombre = btn.getAttribute('data-del-name');
                confirmarEliminarUsuario(id, nombre);
            });
        });

        // 4. Botón Inspeccionar / Detalle
        contenedorTranspuestas.querySelectorAll('.btn-m-inspect').forEach(btn => {
            btn.addEventListener('click', () => {
                const nombre = btn.getAttribute('data-inspect-name');
                verDetalleUsuario(nombre);
            });
        });
    }

    /**
     * Cambiar rol de usuario con validación
     */
    async function cambiarRol(id, nombre, nuevoRol) {
        const norm = normalizarRol(nuevoRol);
        if (rolOcupado(norm, id)) {
            showToast(`❌ Ya existe otra cuenta con el rol '${norm}'.`, true);
            await cargarUsuarios();
            return;
        }

        try {
            const res = await fetch('api/users.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: Number(id), usuario: nombre, role: nuevoRol })
            });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error(data.message || 'Error al actualizar el rol.');
            }
            showToast(`✅ Rol de '${nombre}' cambiado a '${nuevoRol}'.`);
            await cargarUsuarios();
        } catch (err) {
            showToast('❌ ' + err.message, true);
            await cargarUsuarios();
        }
    }

    /**
     * Eliminar usuario
     */
    async function confirmarEliminarUsuario(id, nombre) {
        if (!confirm(`⚠️ ¿Deseás eliminar la cuenta '${nombre}'?\nEsta acción es irreversible.`)) {
            return;
        }

        try {
            const res = await fetch(`api/users.php?id=${id}`, { method: 'DELETE' });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error(data.message || 'Error al eliminar usuario.');
            }
            showToast(`🗑️ Usuario '${nombre}' eliminado con éxito.`);
            await cargarUsuarios();
        } catch (err) {
            showToast('❌ ' + err.message, true);
        }
    }

    /**
     * Generador de contraseña segura aleatoria (Fisher-Yates)
     */
    function generarContraseñaSegura() {
        const groups = [
            'abcdefghijklmnopqrstuvwxyz',
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            '0123456789',
            '$',
            '%',
            '+-*/=<>',
            '!@#&()[]{}:;_.,~^|\\'
        ];
        const chars = [];
        groups.forEach(g => {
            chars.push(g[Math.floor(Math.random() * g.length)]);
        });

        const all = groups.join('');
        while (chars.length < 32) {
            chars.push(all[Math.floor(Math.random() * all.length)]);
        }

        for (let i = chars.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [chars[i], chars[j]] = [chars[j], chars[i]];
        }
        return chars.join('');
    }

    /**
     * Modales Móviles
     */
    function abrirModal(modal) {
        if (!modal) return;
        modal.classList.add('active');
    }

    function cerrarModal(modal) {
        if (!modal) return;
        modal.classList.remove('active');
    }

    function abrirModalNuevo() {
        const form = document.getElementById('form-nuevo-usuario-mobile');
        if (form) form.reset();
        actualizarSeleccionRolesModal('form-nuevo-usuario-mobile', 'vendedor');
        abrirModal(modalNuevo);
    }

    function abrirModalEditar(id, nombre, rol) {
        const inputId = document.getElementById('m-edit-user-id');
        const inputName = document.getElementById('m-edit-username');
        const inputPass = document.getElementById('m-edit-password');
        const previewBox = document.getElementById('m-edit-pass-preview');

        if (inputId) inputId.value = id;
        if (inputName) inputName.value = nombre;
        if (inputPass) inputPass.value = '';
        if (previewBox) previewBox.style.display = 'none';

        actualizarSeleccionRolesModal('form-editar-usuario-mobile', rol, id);
        abrirModal(modalEditar);
    }

    function actualizarSeleccionRolesModal(formId, rolSeleccionado, exceptUserId = 0) {
        const form = document.getElementById(formId);
        if (!form) return;

        const rolNorm = normalizarRol(rolSeleccionado);
        form.querySelectorAll('.role-radio-item').forEach(item => {
            const radio = item.querySelector('input[type="radio"]');
            if (radio) {
                const radioRol = normalizarRol(radio.value);
                const ocupado = rolOcupado(radioRol, exceptUserId);

                radio.disabled = ocupado;
                item.classList.toggle('disabled', ocupado);

                if (radioRol === rolNorm) {
                    radio.checked = true;
                    item.classList.add('selected');
                } else {
                    item.classList.remove('selected');
                }
            }
        });
    }

    /**
     * Ver Detalle Completo en Celular
     */
    async function verDetalleUsuario(nombre) {
        if (!modalDetalle) return;
        const body = document.getElementById('m-detalle-body');
        const title = document.getElementById('m-detalle-title');

        if (title) title.textContent = `Detalle: ${nombre}`;
        if (body) {
            body.innerHTML = `
                <div style="text-align: center; padding: 30px;">
                    <div style="font-size: 2rem; margin-bottom: 8px;">⏳</div>
                    <p style="color: #64748b; margin: 0;">Consultando historial de <strong>${escapeHtml(nombre)}</strong>...</p>
                </div>
            `;
        }
        abrirModal(modalDetalle);

        try {
            const res = await fetch(`api/users.php?action=detail&usuario=${encodeURIComponent(nombre)}`, { cache: 'no-store' });
            const data = await res.json();
            if (!res.ok || !data.ok) {
                throw new Error(data.message || 'Error al obtener datos del usuario.');
            }

            const u = data.usuario;
            const m = data.metricas;
            const ventas = data.ventas || [];
            const logins = data.logins || [];

            body.innerHTML = `
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid #f1f5f9;">
                    <div class="avatar-circle ${normalizarRol(u.rol)}" style="width: 48px; height: 48px; font-size: 1.25rem;">
                        ${u.nombre.charAt(0).toUpperCase()}
                    </div>
                    <div>
                        <h4 style="margin: 0; font-size: 1.1rem; color: #1e1b4b;">${escapeHtml(u.nombre)}</h4>
                        <span class="badge-role-mobile ${normalizarRol(u.rol)}">${escapeHtml(u.rol)}</span>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 16px;">
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px;">
                        <small style="color: #64748b; display: block; font-size: 0.75rem;">Facturado</small>
                        <strong style="color: #15803d; font-size: 0.98rem;">${formatMoney(m.total_facturado)}</strong>
                    </div>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px;">
                        <small style="color: #64748b; display: block; font-size: 0.75rem;">Ventas Realizadas</small>
                        <strong style="font-size: 0.98rem;">${m.total_ventas} tickets</strong>
                    </div>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px;">
                        <small style="color: #64748b; display: block; font-size: 0.75rem;">Inicios de Sesión</small>
                        <strong style="color: #d97706; font-size: 0.98rem;">🔑 ${m.total_logins || logins.length}</strong>
                    </div>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 10px;">
                        <small style="color: #64748b; display: block; font-size: 0.75rem;">Ticket Promedio</small>
                        <strong style="color: #7c2d92; font-size: 0.98rem;">${formatMoney(m.ticket_promedio)}</strong>
                    </div>
                </div>

                <h5 style="margin: 0 0 8px; font-size: 0.9rem; color: #1e1b4b;">Últimas ventas registradas (${ventas.length})</h5>
                ${ventas.length === 0 ? `
                    <p style="color: #94a3b8; font-size: 0.85rem; text-align: center; padding: 14px; background: #f8fafc; border-radius: 8px;">No posee ventas aún.</p>
                ` : `
                    <div style="max-height: 180px; overflow-y: auto; display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px;">
                        ${ventas.slice(0, 10).map(v => `
                            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 10px; background: #faf5ff; border: 1px solid #ede9fe; border-radius: 8px; font-size: 0.82rem;">
                                <div>
                                    <strong>${escapeHtml(v.nombre_producto)}</strong>
                                    <div style="color: #64748b; font-size: 0.74rem;">${formatDate(v.fecha)}</div>
                                </div>
                                <strong style="color: #15803d;">${formatMoney(v.ganancia)}</strong>
                            </div>
                        `).join('')}
                    </div>
                `}
            `;
        } catch (err) {
            body.innerHTML = `
                <div style="text-align: center; padding: 20px; color: #be123c;">
                    ❌ No se pudo cargar el detalle: ${escapeHtml(err.message)}
                </div>
            `;
        }
    }

    /**
     * Inicialización y Eventos
     */
    function iniciarAppMovil() {
        cargarUsuarios();

        // 1. Buscador en vivo
        if (inputSearch) {
            inputSearch.addEventListener('input', () => {
                if (btnClearSearch) {
                    btnClearSearch.style.display = inputSearch.value ? 'flex' : 'none';
                }
                renderTablasTranspuestas();
            });
        }

        if (btnClearSearch) {
            btnClearSearch.addEventListener('click', () => {
                inputSearch.value = '';
                btnClearSearch.style.display = 'none';
                renderTablasTranspuestas();
            });
        }

        // 2. Filtros por chip de rol
        document.querySelectorAll('.chip-btn[data-role-filter]').forEach(chip => {
            chip.addEventListener('click', () => {
                document.querySelectorAll('.chip-btn[data-role-filter]').forEach(c => c.classList.remove('active'));
                chip.classList.add('active');
                filtroRolActual = chip.getAttribute('data-role-filter');
                renderTablasTranspuestas();
            });
        });

        // 3. Activar Simulador Móvil en escritorio
        if (btnActivarSimulador && desktopWarning) {
            btnActivarSimulador.addEventListener('click', () => {
                desktopWarning.classList.add('hidden');
                document.body.classList.add('modo-simulador-activo');
            });
        }

        // Si la pantalla es reducida (celular), oculta el warning automáticamente
        const checkViewport = () => {
            if (window.innerWidth <= 768 && desktopWarning) {
                desktopWarning.classList.add('hidden');
            }
        };
        checkViewport();
        window.addEventListener('resize', checkViewport);

        // 4. Botón superior + Nuevo
        const btnAddTop = document.getElementById('btn-add-user-top');
        if (btnAddTop) {
            btnAddTop.addEventListener('click', abrirModalNuevo);
        }

        // 5. Cierre de modales
        document.querySelectorAll('.mobile-modal-overlay [data-close-modal]').forEach(btn => {
            btn.addEventListener('click', () => {
                const modal = btn.closest('.mobile-modal-overlay');
                if (modal) cerrarModal(modal);
            });
        });

        document.querySelectorAll('.mobile-modal-overlay').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) cerrarModal(modal);
            });
        });

        // 6. Selección táctil de radio buttons en modales
        document.querySelectorAll('.role-radio-item').forEach(item => {
            item.addEventListener('click', () => {
                const radio = item.querySelector('input[type="radio"]');
                if (radio && !radio.disabled) {
                    radio.checked = true;
                    const container = item.closest('.mobile-role-selector');
                    if (container) {
                        container.querySelectorAll('.role-radio-item').forEach(i => i.classList.remove('selected'));
                        item.classList.add('selected');
                    }
                }
            });
        });

        // 7. Formulario Crear Nuevo Usuario
        const formNuevo = document.getElementById('form-nuevo-usuario-mobile');
        if (formNuevo) {
            formNuevo.addEventListener('submit', async (e) => {
                e.preventDefault();
                const usuario = document.getElementById('m-new-username').value.trim();
                const role = formNuevo.querySelector('input[name="m-new-role"]:checked')?.value || 'vendedor';

                if (!usuario) {
                    showToast('❌ Ingresá el nombre de usuario.', true);
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
                        body: JSON.stringify({ usuario, role })
                    });
                    const data = await res.json();
                    if (!res.ok || !data.ok) {
                        throw new Error(data.message || 'Error al crear usuario.');
                    }

                    cerrarModal(modalNuevo);

                    // Mostrar credenciales
                    const credUser = document.getElementById('m-cred-user');
                    const credPass = document.getElementById('m-cred-pass');
                    if (credUser) credUser.textContent = `Usuario: ${data.user.usuario}`;
                    if (credPass) credPass.textContent = data.temporary_password;

                    abrirModal(modalCredenciales);
                    await cargarUsuarios();
                } catch (err) {
                    showToast('❌ ' + err.message, true);
                }
            });
        }

        // 8. Copiar contraseña generada
        const btnCopyCred = document.getElementById('btn-copy-m-cred');
        if (btnCopyCred) {
            btnCopyCred.addEventListener('click', async () => {
                const pass = document.getElementById('m-cred-pass')?.textContent || '';
                try {
                    await navigator.clipboard.writeText(pass);
                    showToast('📋 Contraseña copiada al portapapeles.');
                } catch (e) {
                    showToast('No se pudo copiar automáticamente. Copiala manualmente.', true);
                }
            });
        }

        // 9. Formulario Editar Usuario
        const formEditar = document.getElementById('form-editar-usuario-mobile');
        if (formEditar) {
            formEditar.addEventListener('submit', async (e) => {
                e.preventDefault();
                const id = document.getElementById('m-edit-user-id').value;
                const usuario = document.getElementById('m-edit-username').value.trim();
                const password = document.getElementById('m-edit-password').value;
                const role = formEditar.querySelector('input[name="m-edit-role"]:checked')?.value || 'vendedor';

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
                    cerrarModal(modalEditar);
                    await cargarUsuarios();
                } catch (err) {
                    showToast('❌ ' + err.message, true);
                }
            });
        }

        // 10. Regenerar contraseña en edición móvil
        const btnRegenerar = document.getElementById('btn-m-regenerar-pass');
        if (btnRegenerar) {
            btnRegenerar.addEventListener('click', () => {
                const pass = generarContraseñaSegura();
                const inputPass = document.getElementById('m-edit-password');
                const previewBox = document.getElementById('m-edit-pass-preview');
                const previewCode = document.getElementById('m-edit-pass-val');

                if (inputPass) {
                    inputPass.type = 'text';
                    inputPass.value = pass;
                }
                if (previewCode) previewCode.textContent = pass;
                if (previewBox) previewBox.style.display = 'block';

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(pass).catch(() => {});
                }
                showToast('🔑 Nueva contraseña segura generada y copiada.');
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciarAppMovil);
    } else {
        iniciarAppMovil();
    }

    // Exponer utilidades por si se requieren externamente
    window.cargarUsuariosMovil = cargarUsuarios;
})();
