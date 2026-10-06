/**
 * Ajustes de precios y auditoria de stock
 */

function renderPriceCategoryFilter() {
    const selector = document.getElementById('priceCategory');
    if (!selector) return;
    const selected = categoriaSeleccionada?.ID_categoria || categoriaSeleccionada?.id || inventario[0]?.ID_categoria || inventario[0]?.id || '';
    selector.innerHTML = inventario.map(cat =>
        `<option value="${escapeHtml(cat.ID_categoria || cat.id)}">${escapeHtml(cat.nombre)}</option>`
    ).join('');
    selector.value = inventario.some(cat => String(cat.ID_categoria || cat.id) === String(selected))
        ? selected
        : (inventario[0]?.ID_categoria || inventario[0]?.id || '');
    renderPriceTypeFilter();
}

function renderPriceTypeFilter() {
    const selector = document.getElementById('priceType');
    const categoryId = document.getElementById('priceCategory')?.value;
    if (!selector) return;
    const currentValue = selector.value;
    const category = inventario.find(cat => String(cat.ID_categoria || cat.id) === String(categoryId));
    const types = [...new Set((category?.productos || [])
        .map(product => String(product.subcategoria || '').trim())
        .filter(Boolean))];
    selector.innerHTML = '<option value="">Todos los tipos</option>' + types.map(type =>
        `<option value="${escapeHtml(type)}">${escapeHtml(type)}</option>`
    ).join('');
    selector.value = types.includes(currentValue) ? currentValue : '';
}

function togglePriceProduct(id, checked) {
    if (!Number.isInteger(id) || id < 1) return;
    if (checked) {
        productosPrecioSeleccionados.add(id);
    } else {
        productosPrecioSeleccionados.delete(id);
    }
}

function toggleVisiblePriceProducts(checked) {
    const checkboxes = document.querySelectorAll('#mainContent .price-product-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = checked;
        togglePriceProduct(Number(checkbox.dataset.productId), checked);
    });
}

/**
 * Renderizar la tabla de productos (solo muestra productos activos)
 */
async function cargarAuditoriaStock() {
    const content = document.getElementById('auditoria-stock-content');
    if (!content || !usuarioEsSuperAdmin) return;

    try {
        const response = await fetch('api/inventario.php?action=audit', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(data.message || 'No se pudo cargar la auditoría.');

        if (!data.auditoria.length) {
            content.innerHTML = '<p class="audit-empty">Todavía no hay cambios de estado registrados.</p>';
            return;
        }

        content.innerHTML = `<table class="audit-table"><thead><tr><th>Fecha</th><th>Producto</th><th>Estado</th><th>Usuario</th><th>Detalle</th></tr></thead><tbody>${data.auditoria.map(evento => {
            const estadoHabilitado = evento.tipo === 'stock_habilitar';
            const detalle = evento.detalles?.codigo || evento.descripcion;
            return `<tr><td>${formatAuditDate(evento.fecha)}</td><td>${escapeHtml(evento.detalles?.nombre || evento.detalles?.codigo || 'Producto')}</td><td><span class="badge-fase badge-fase-${estadoHabilitado ? 'habilitado' : 'deshabilitado'}">${estadoHabilitado ? 'Habilitado' : 'Deshabilitado'}</span></td><td>${escapeHtml(evento.usuario)}</td><td>${escapeHtml(detalle)}</td></tr>`;
        }).join('')}</tbody></table>`;
    } catch (error) {
        content.innerHTML = `<p class="audit-error">${escapeHtml(error.message)}</p>`;
    }
}

function formatAuditDate(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString('es-AR');
}

async function aplicarAjustePrecios() {
    if (!usuarioEsAdmin) return;
    const scope = document.getElementById('priceScope').value;
    const adjustmentType = document.getElementById('priceAdjustmentType').value;
    const adjustmentValue = Number(document.getElementById('priceAdjustmentValue').value);
    const categoryId = document.getElementById('priceCategory').value;
    const type = document.getElementById('priceType').value;
    const name = document.getElementById('priceProductName').value.trim();

    if (!Number.isFinite(adjustmentValue) || adjustmentValue === 0
        || (adjustmentType === 'percentage' && adjustmentValue <= -100)) {
        alert('Ingresá un ajuste distinto de cero. El porcentaje no puede ser -100% o menor.');
        return;
    }
    if (scope === 'type' && !type) {
        alert('Seleccioná un tipo de producto para aplicar el ajuste.');
        return;
    }
    if (scope === 'name' && !name) {
        alert('Ingresá el nombre exacto de los productos que querés ajustar.');
        return;
    }
    if (scope === 'selected' && productosPrecioSeleccionados.size === 0) {
        alert('Seleccioná al menos un producto en la tabla.');
        return;
    }

    const scopeLabel = {
        category: 'toda la categoría seleccionada',
        type: `el tipo "${type}"`,
        name: `los productos llamados "${name}"`,
        selected: `${productosPrecioSeleccionados.size} producto(s) seleccionado(s)`
    }[scope];
    if (!confirm(`¿Aplicar ${adjustmentValue > 0 ? '+' : ''}${adjustmentValue}${adjustmentType === 'percentage' ? '%' : ' pesos'} a ${scopeLabel}?`)) {
        return;
    }

    const button = document.getElementById('applyPriceAdjustment');
    button.disabled = true;
    try {
        const response = await fetch('api/inventario.php?action=adjust_prices', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                scope,
                categoryId,
                type,
                name,
                productIds: Array.from(productosPrecioSeleccionados),
                adjustmentType,
                adjustmentValue
            })
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'No se pudieron actualizar los precios.');
        }

        productosPrecioSeleccionados.clear();
        await cargarInventario();
        const updatedCategory = inventario.find(category => String(category.ID_categoria || category.id) === String(categoryId));
        if (updatedCategory) categoriaSeleccionada = updatedCategory;
        renderPriceCategoryFilter();
        renderCategories();
        renderTable(categoriaSeleccionada);
        renderDetailPanel();
        document.getElementById('priceAdjustmentValue').value = '';
        alert(`Se actualizaron ${data.updated} precio(s) y el ajuste quedó registrado en el historial.`);
    } catch (error) {
        alert(error.message);
    } finally {
        button.disabled = false;
    }
}

async function cargarHistorialPrecios() {
    const container = document.getElementById('priceHistory');
    if (!container || !usuarioEsAdmin) return;
    container.hidden = false;
    container.innerHTML = '<p>Cargando historial...</p>';
    try {
        const response = await fetch('api/inventario.php?action=price_history', { cache: 'no-store' });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.message || 'No se pudo cargar el historial.');
        }
        if (!data.history.length) {
            container.innerHTML = '<p>No hay ajustes de precios registrados.</p>';
            return;
        }
        container.innerHTML = `
            <h3>Historial reciente de ajustes</h3>
            <div class="price-history-table-wrap">
                <table>
                    <thead><tr><th>Fecha</th><th>Usuario</th><th>Alcance</th><th>Categoría / tipo / nombre</th><th>Ajuste</th><th>Productos</th></tr></thead>
                    <tbody>${data.history.map(entry => `
                        <tr>
                            <td>${escapeHtml(formatAuditDate(entry.fecha))}</td>
                            <td>${escapeHtml(entry.usuario)}</td>
                            <td>${escapeHtml(({ category: 'Categoría', type: 'Tipo', name: 'Nombre', selected: 'Selección manual' })[entry.alcance] || entry.alcance)}</td>
                            <td>${escapeHtml([entry.categoria, entry.tipo, entry.nombre ? `Nombre: ${entry.nombre}` : ''].filter(Boolean).join(' / ') || 'Selección')}</td>
                            <td>${entry.tipo_ajuste === 'percentage' ? `${entry.valor}%` : `$${Number(entry.valor).toLocaleString('es-AR')}`}</td>
                            <td>${entry.cantidad}</td>
                        </tr>
                    `).join('')}</tbody>
                </table>
            </div>`;
    } catch (error) {
        container.innerHTML = `<p class="price-history-error">${escapeHtml(error.message)}</p>`;
    }
}

// ==========================================================================
// Inicialización y Event Listeners
// ==========================================================================
