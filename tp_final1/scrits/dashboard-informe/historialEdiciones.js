// --- MODAL E HISTORIAL DE EDICIONES ---
function cargarHistorialEdiciones() {
    const tbody = document.getElementById('edit-history-table-body');
    if (!tbody) return;
    fetch('api/ventas/ediciones_historial.php')
        .then(res => {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then(data => {
            if (!Array.isArray(data)) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:12px; color:#6b7280;">Respuesta inesperada del servidor.</td></tr>';
                return;
            }
            renderizarTablaHistorial(data);
        })
        .catch((e) => {
            console.error("[Historial Edit] Error cargando historial:", e);
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:12px; color:#6b7280;">No se pudo cargar el historial.</td></tr>';
        });
}

function renderizarTablaHistorial(ediciones) {
    const tbody = document.getElementById('edit-history-table-body');
    if (!tbody) return;

    const esSuperAdmin = typeof window.usuarioEsSuperAdmin !== 'undefined'
        ? (window.usuarioEsSuperAdmin === true || window.usuarioEsSuperAdmin === 'true')
        : false;
    const userId = typeof window.usuarioIdActual !== 'undefined'
        ? (window.usuarioIdActual && window.usuarioIdActual !== "null" ? window.usuarioIdActual : null)
        : null;
    const esAdmin = typeof window.usuarioEsAdminInforme !== 'undefined'
        ? (window.usuarioEsAdminInforme === true || window.usuarioEsAdminInforme === 'true')
        : false;

    const filtrados = ediciones.filter(ed => {
        if (!ed || typeof ed !== "object") return false;
        if (esSuperAdmin) return true;
        if (ed.rol === 'superadmin') return false;
        if (esAdmin) return true;
        if (userId !== null && (ed.usuario_id == userId || ed.usuario_id === String(userId))) {
            return true;
        }
        return false;
    });

    if (filtrados.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding:12px; color:#6b7280;">No hay historial de ediciones para mostrar.</td></tr>';
        return;
    }

    const esc = typeof window.escapeHtml === 'function' ? window.escapeHtml : (s => String(s ?? ''));

    tbody.innerHTML = filtrados.map(ed => {
        const usuario = ed.usuario ?? 'Desconocido';
        const rol = ed.rol ?? 'Desconocido';
        const accion = ed.accion === 'venta_deshabilitada'
            ? 'Venta deshabilitada'
            : (ed.accion === 'venta_modificada'
                ? 'Venta modificada'
                : (ed.accion === 'venta_eliminada' ? 'Venta eliminada' : (ed.accion ?? '')));
        const fecha = ed.fecha ?? '';
        const hora = ed.hora ?? '';
        const detalle = ed.detalles_cambios ?? ed.detalle ?? '';

        return `
            <tr class="history-row">
                <td data-label="Usuario">${esc(usuario)}</td>
                <td data-label="Rol">${esc(rol)}</td>
                <td data-label="Acción">${esc(accion)}</td>
                <td data-label="Fecha">${esc(fecha)}</td>
                <td data-label="Hora">${esc(hora)}</td>
                <td data-label="Detalle">${esc(detalle)}</td>
            </tr>
        `;
    }).join('');
}

function verDetalleEdicion(idEdicion) {
    fetch('api/ventas/detalle_edicion.php?id=' + encodeURIComponent(idEdicion))
        .then(res => res.json())
        .then(data => {
            alert('Detalle de cambios:\n' + (data.detalle || 'Sin información.'));
        })
        .catch(() => alert('No se pudo cargar el detalle de la edición.'));
}
