/**
 * Inicializacion del panel de gestion
 */

document.addEventListener('DOMContentLoaded', async () => {
    // Carga los datos persistentes antes de pintar categorías, productos y opciones de precio.
    await cargarInventario();
    renderCategoryFilter();
    renderCategorySelector();
    renderPriceCategoryFilter();

    const btnVerStock = document.getElementById('btn-ver-stock');
    const stockWorkspace = document.getElementById('stock-workspace');
    const stockEmptyState = document.getElementById('stock-empty-state');
    const mostrarWorkspaceStock = () => {
        stockWorkspace.hidden = false;
        stockEmptyState.hidden = true;
        if (btnVerStock) btnVerStock.hidden = true;
        renderCategories();
        if (categoriaSeleccionada) renderTable(categoriaSeleccionada);
        renderDetailPanel();
    };
    if (btnVerStock) {
        btnVerStock.addEventListener('click', () => {
            mostrarWorkspaceStock();
        });
    }
    const btnTogglePriceAdjustment = document.getElementById('btn-toggle-price-adjustment');
    const priceAdjustmentPanel = document.getElementById('price-adjustment-panel');
    if (btnTogglePriceAdjustment && priceAdjustmentPanel) {
        btnTogglePriceAdjustment.addEventListener('click', () => {
            priceAdjustmentPanel.hidden = !priceAdjustmentPanel.hidden;
            const isExpanded = !priceAdjustmentPanel.hidden;
            if (!isExpanded) productosPrecioSeleccionados.clear();
            btnTogglePriceAdjustment.setAttribute('aria-expanded', String(isExpanded));
            btnTogglePriceAdjustment.textContent = isExpanded
                ? '✖ Cerrar actualización de precios'
                : '💲 Actualizar precios';
            if (categoriaSeleccionada) renderTable(categoriaSeleccionada);
        });
    }

    const btnAuditoria = document.getElementById('btn-auditoria-stock');
    const auditoria = document.getElementById('auditoria-stock');
    const btnCerrarAuditoria = document.getElementById('btn-cerrar-auditoria');
    if (btnAuditoria && auditoria) {
        btnAuditoria.addEventListener('click', async () => {
            auditoria.hidden = false;
            await cargarAuditoriaStock();
        });
    }
    if (btnCerrarAuditoria && auditoria) {
        btnCerrarAuditoria.addEventListener('click', () => {
            auditoria.hidden = true;
        });
    }

    // Busca en todo el inventario y genera una tabla agregada sin perder la categoría de cada coincidencia.
    // Buscador global de productos
    const searchInput = document.getElementById('globalSearch');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            const term = e.target.value.toLowerCase().trim();
            if (!term) {
                if (categoriaSeleccionada) renderTable(categoriaSeleccionada);
                return;
            }

            const content = document.getElementById('mainContent');
            if (!content) return;

            let matches = [];
            inventario.forEach(cat => {
                (cat.productos || []).forEach(p => {
                    const isActive = (p.fase || 'habilitado') !== 'deshabilitado';
                    if ((usuarioEsSuperAdmin || isActive) && (p.nombre.toLowerCase().includes(term) || String(p.codigo).toLowerCase().includes(term))) {
                        matches.push({ ...p, categoriaNombre: cat.nombre, catId: cat.id });
                    }
                });
            });

            let totalValorizado = 0;
            let rows = matches.map(p => {
                const total = p.precio * p.stock;
                totalValorizado += total;

                return `
                    <tr class="product-row" 
                        onclick="selectProduct('${p.codigo}', '${p.catId}')" 
                        oncontextmenu="showProductContextMenu(event, '${p.codigo}', '${p.catId}')">
                        <td><strong>${escapeHtml(p.nombre)}</strong> <small style="color:#6b7280;">(${escapeHtml(p.categoriaNombre)})</small></td>
                        <td><code>${escapeHtml(p.codigo)}</code></td>
                        <td>$${p.precio.toLocaleString()}</td>
                        <td>${p.stock}</td>
                        <td>$${total.toLocaleString()}</td>
                        ${usuarioEsSuperAdmin ? `<td><span class="badge-fase badge-fase-${p.fase === 'deshabilitado' ? 'deshabilitado' : 'habilitado'}">${p.fase === 'deshabilitado' ? 'Deshabilitado' : 'Habilitado'}</span></td>` : ''}
                    </tr>
                `;
            }).join('');

            content.innerHTML = `
                <h2>Resultados de búsqueda: "${escapeHtml(term)}" (${matches.length})</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Código</th>
                            <th>Precio</th>
                            <th>Stock</th>
                            <th>Total Inventario</th>
                            ${usuarioEsSuperAdmin ? '<th>Estado</th>' : ''}
                        </tr>
                    </thead>
                    <tbody>
                        ${rows || `<tr><td colspan="${usuarioEsSuperAdmin ? '6' : '5'}" style="text-align:center; padding:24px;">No se encontraron productos coincidentes.</td></tr>`}
                        <tr class="total-row">
                            <td colspan="4">TOTAL VALORIZADO</td>
                            <td>$${totalValorizado.toLocaleString()}</td>
                            ${usuarioEsSuperAdmin ? '<td></td>' : ''}
                        </tr>
                    </tbody>
                </table>
            `;
        });
    }

    const categoryFilter = document.getElementById('categoryFilter');
    if (categoryFilter) {
        categoryFilter.addEventListener('change', (event) => {
            categoriaFiltro = event.target.value;
            renderCategories();
        });
    }

    const priceCategory = document.getElementById('priceCategory');
    if (priceCategory) {
        priceCategory.addEventListener('change', () => {
            renderPriceTypeFilter();
        categoriaSeleccionada = inventario.find(category => String(category.ID_categoria || category.id) === String(priceCategory.value)) || null;
            renderCategories();
            renderTable(categoriaSeleccionada);
            renderDetailPanel();
        });
    }
    const priceType = document.getElementById('priceType');
    if (priceType) {
        priceType.addEventListener('change', () => renderTable(categoriaSeleccionada));
    }
    const priceScope = document.getElementById('priceScope');
    if (priceScope) {
        priceScope.addEventListener('change', () => {
            document.getElementById('priceNameField').hidden = priceScope.value !== 'name';
        });
    }
    document.getElementById('applyPriceAdjustment')?.addEventListener('click', aplicarAjustePrecios);
    document.getElementById('togglePriceHistory')?.addEventListener('click', cargarHistorialPrecios);

    // Confirma o cancela bajas lógicas y cierra el cuadro al pulsar fuera de él.
    // Modal de confirmación de eliminación
    const btnConfirmarEliminar = document.getElementById('btn-confirmar-eliminar');
    const btnCancelarEliminar = document.getElementById('btn-cancelar-eliminar');
    const modalConfirmar = document.getElementById('modal-confirmar-eliminar');

    if (btnConfirmarEliminar) {
        btnConfirmarEliminar.addEventListener('click', ejecutarEliminacion);
    }
    if (btnCancelarEliminar) {
        btnCancelarEliminar.addEventListener('click', cerrarModalConfirmacion);
    }
    if (modalConfirmar) {
        modalConfirmar.addEventListener('click', (e) => {
            if (e.target === modalConfirmar) {
                cerrarModalConfirmacion();
            }
        });
    }

    // Cierra los menús contextuales globales y vuelve a cargar la vista cuando otra acción actualiza stock.
    // Ocultar menús contextuales al hacer clic en cualquier lugar
    document.addEventListener('click', () => {
        hideCategoryContextMenu();
        hideProductContextMenu();
    });

    window.addEventListener('inventario-updated', async () => {
        await cargarInventario();
        renderCategories();
        if (categoriaSeleccionada) {
            renderTable(categoriaSeleccionada);
        }
        renderDetailPanel();
    });
});