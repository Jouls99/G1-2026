/**
 * Inventario: estado, carga y visualizacion
 */

/**
 * Panel de Gestión y Control de Stock
 * Manejo de eliminación con confirmación y marcado deshabilitado en base de datos SQL
 */

let inventario = [];
let categoriaSeleccionada = inventario[0];
let productoSeleccionado = null;
let productoPendienteEliminar = null;
let categoryContextMenu = null;
let productContextMenu = null;
const productosPrecioSeleccionados = new Set();
const usuarioEsAdmin = window.usuarioEsAdmin === true;
const usuarioPuedeRegistrarStock = window.usuarioPuedeRegistrarStock === true;
const usuarioEsSuperAdmin = window.usuarioEsSuperAdmin === true;

/**
 * Cargar inventario desde la API MySQL.
 */
async function cargarInventario() {
    try {
        const [inventoryResponse, categoriesResponse] = await Promise.all([
            fetch('api/inventario.php', { cache: 'no-store' }),
            fetch('api/inventario.php?action=categories', { cache: 'no-store' })
        ]);
        const [products, categories] = await Promise.all([
            inventoryResponse.json(),
            categoriesResponse.json()
        ]);
        if (!inventoryResponse.ok || !Array.isArray(products)) {
            throw new Error(products.message || 'No se pudo cargar el inventario desde la base de datos.');
        }
        if (!categoriesResponse.ok || categories.ok !== true || !Array.isArray(categories.categorias)) {
            throw new Error(categories.message || 'No se pudieron cargar las categorías desde la base de datos.');
        }

        const map = new Map();
        categories.categorias.forEach(category => {
            const id = Number(category.ID_categoria);
            map.set(String(id), {
                id: `cat-${id}`,
                ID_categoria: id,
                nombre: category.nombre,
                productos: [],
                subcategorias: category.subcategorias || []
            });
        });
        if (products.some(item => !item.categoria || !item.ID_categoria || !map.has(String(item.ID_categoria)))) {
            throw new Error('Hay productos sin una categoría válida. Reasignalos antes de continuar.');
        }
        products.forEach(item => {
            const categoryId = item.ID_categoria ? String(item.ID_categoria) : null;
            const catName = item.categoria;
            let cat = categoryId ? map.get(categoryId) : null;
            cat.productos.push({
                ID_stock: item.ID_stock || item.id,
                codigo: item.codigo,
                nombre: item.nombre,
                marca: item.marca || undefined,
                sub_nombre: item.sub_nombre || undefined,
                precio: item.precio,
                stock: item.cantidad,
                stock_minimo: item.stock_minimo !== undefined ? Number(item.stock_minimo) : 5,
                fase: item.fase || 'habilitado',
                subcategoria: item.subcategoria || undefined
            });
        });
        inventario = Array.from(map.values());
        categoriaSeleccionada = inventario[0] || null;
    } catch (error) {
        console.error('Error al cargar inventario desde MySQL:', error);
        inventario = [];
        categoriaSeleccionada = null;
        const content = document.getElementById('mainContent');
        if (content) {
            content.innerHTML = '<p role="alert">No se pudo cargar el inventario. Verificá la conexión con la base de datos e intentá nuevamente.</p>';
        }
    }
}

/**
 * Renderizar la lista de categorías laterales
 */
function renderCategories() {
    const container = document.getElementById('categoriesContainer');
    if (!container) return;
    container.innerHTML = '';
    const searchInput = document.getElementById('categorySearch');
    const searchTerm = searchInput?.value.trim().toLocaleLowerCase('es') || '';
    const categoriasVisibles = inventario.filter(cat =>
        cat.nombre.toLocaleLowerCase('es').includes(searchTerm)
    );

    if (categoriasVisibles.length === 0) {
        container.innerHTML = '<p class="category-empty">No se encontraron categorías.</p>';
        return;
    }

    categoriasVisibles.forEach(cat => {
        const btn = document.createElement('button');
        btn.className = `btn ${categoriaSeleccionada?.id === cat.id ? 'btn-active' : ''}`;
        btn.innerText = cat.nombre;
        btn.onclick = () => selectCategory(cat.id);
        btn.oncontextmenu = (event) => showCategoryContextMenu(event, cat);
        btn.title = cat.ID_categoria
            ? 'Click para abrir • Click derecho para eliminar categoría'
            : 'Click para abrir';
        container.appendChild(btn);
    });
}

function renderCategorySelector() {
    const selector = document.getElementById('prodCat');
    if (!selector) return;

    const selectedCategory = selector.value;
    selector.innerHTML = inventario.map(cat =>
        `<option value="${escapeHtml(cat.id)}">${escapeHtml(cat.nombre)}</option>`
    ).join('');

    if (inventario.some(cat => cat.id === selectedCategory)) {
        selector.value = selectedCategory;
    } else if (categoriaSeleccionada) {
        selector.value = categoriaSeleccionada.id;
    }

    const emptyMessage = document.getElementById('product-category-empty');
    const creator = document.getElementById('product-category-creator');
    const toggle = document.getElementById('btn-toggle-product-category');
    if (emptyMessage) emptyMessage.hidden = inventario.length > 0;
    if (inventario.length === 0 && creator && toggle) {
        creator.hidden = false;
        toggle.setAttribute('aria-expanded', 'true');
    }
}

// Selecciona una categoría y actualiza listado, detalle y filtros de ajustes asociados.
function selectCategory(catId) {
    categoriaSeleccionada = inventario.find(c => c.id === catId);
    productoSeleccionado = null;
    const priceCategory = document.getElementById('priceCategory');
    if (priceCategory && categoriaSeleccionada) {
        priceCategory.value = categoriaSeleccionada.ID_categoria || categoriaSeleccionada.id;
        renderPriceTypeFilter();
    }
    renderCategories();
    renderTable(categoriaSeleccionada);
    renderDetailPanel();

    if (window.matchMedia('(max-width: 600px)').matches) {
        document.getElementById('mainContent')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// Filtra por rol/tipo y presenta productos, stock y valorización para la categoría activa.
function renderTable(categoria) {
    const content = document.getElementById('mainContent');
    if (!categoria) {
        if (content) content.innerHTML = '<h3>No hay categorías disponibles.</h3>';
        return;
    }

    const productosActivos = usuarioEsSuperAdmin
        ? (categoria.productos || [])
        : (categoria.productos || []).filter(p => (p.fase || 'habilitado') !== 'deshabilitado');
    const typeFilter = usuarioEsAdmin ? document.getElementById('priceType')?.value : '';
    const productosVisibles = typeFilter
        ? productosActivos.filter(product => String(product.subcategoria || '') === typeFilter)
        : productosActivos;
    const mostrarSeleccionPrecios = usuarioEsAdmin
        && !document.getElementById('price-adjustment-panel')?.hidden;

    let totalValorizado = productosVisibles.reduce(
        (total, product) => total + (product.precio * product.stock),
        0
    );
    let rowsHTML = productosVisibles.map(p => {
        const totalProducto = p.precio * p.stock;
        const productId = Number(p.ID_stock || p.id);
        const stockMin = p.stock_minimo !== undefined ? Number(p.stock_minimo) : 5;
        const bajoStock = p.stock <= stockMin;

        return `
            <tr class="product-row"
                onclick="selectProduct('${p.ID_stock || p.id}', '${categoria.id}')"
                oncontextmenu="showProductContextMenu(event, '${p.ID_stock || p.id}', '${categoria.id}')"
                title="Click izquierdo: ver detalles • Click derecho: eliminar producto">
                ${mostrarSeleccionPrecios ? `<td data-label="Seleccionar"><input class="price-product-checkbox" data-product-id="${productId}" type="checkbox" aria-label="Seleccionar ${escapeHtml(p.nombre)}" onclick="event.stopPropagation()" onchange="togglePriceProduct(${productId}, this.checked)" ${productosPrecioSeleccionados.has(productId) ? 'checked' : ''}></td>` : ''}
                <td data-label="Producto">
                    <strong>${escapeHtml(p.nombre)}</strong>
                    ${(p.marca || p.sub_nombre) ? `<small style="display:block;color:#6b7280;">${[p.marca, p.sub_nombre].filter(Boolean).map(escapeHtml).join(' · ')}</small>` : ''}
                </td>
                <td data-label="Código"><code>${escapeHtml(p.codigo)}</code></td>
                <td data-label="Precio">$${p.precio.toLocaleString()}</td>
                <td data-label="Stock">
                    ${p.stock}
                    ${bajoStock ? `<span class="badge-stock-bajo" title="Stock por debajo o igual al mínimo (${stockMin})">⚠️ Bajo</span>` : ''}
                </td>
                <td data-label="Total Inventario">$${totalProducto.toLocaleString()}</td>
                ${usuarioEsSuperAdmin ? `<td data-label="Estado"><span class="badge-fase badge-fase-${p.fase === 'deshabilitado' ? 'deshabilitado' : 'habilitado'}">${p.fase === 'deshabilitado' ? 'Deshabilitado' : 'Habilitado'}</span></td>` : ''}
            </tr>
        `;
    }).join('');

    let html = `
        <h2>Categoría: ${escapeHtml(categoria.nombre)}</h2>
        <table>
            <thead>
                <tr>
                    ${mostrarSeleccionPrecios ? '<th><input type="checkbox" aria-label="Seleccionar todos los productos visibles" onclick="event.stopPropagation()" onchange="toggleVisiblePriceProducts(this.checked)"></th>' : ''}
                    <th>Producto</th><th>Código</th><th>Precio</th><th>Stock</th><th>Total Inventario</th>
                    ${usuarioEsSuperAdmin ? '<th>Estado</th>' : ''}
                </tr>
            </thead>
            <tbody>
                ${rowsHTML || `<tr><td colspan="${(usuarioEsSuperAdmin ? 6 : 5) + (mostrarSeleccionPrecios ? 1 : 0)}" style="text-align:center; padding: 24px; color: #6b7280;">No hay productos que coincidan con este filtro.</td></tr>`}
                <tr class="total-row">
                    <td colspan="${(mostrarSeleccionPrecios ? 1 : 0) + 4}">TOTAL VALORIZADO</td>
                    <td>$${totalValorizado.toLocaleString()}</td>
                    ${usuarioEsSuperAdmin ? '<td></td>' : ''}
                </tr>
            </tbody>
        </table>
    `;

    content.innerHTML = html;
}

// Resuelve un producto activo de la categoría elegida para que el panel lateral lo detalle.
function selectProduct(productId, catId) {
    const cat = inventario.find(c => c.id === catId);
    if (!cat) return;
    productoSeleccionado = cat.productos.find(p =>
        String(p.ID_stock || p.id) === String(productId)
        && (p.fase || 'habilitado') !== 'deshabilitado'
    );
    renderDetailPanel();

    if (window.matchMedia('(max-width: 600px)').matches) {
        document.getElementById('detailPanel')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

/**
 * Renderizar panel de detalle lateral
 */
/**
 * Renderizar panel de detalle lateral
 */
function renderDetailPanel() {
    const panel = document.getElementById('detailPanel');
    if (!panel) return;

    if (!productoSeleccionado || (productoSeleccionado.fase || 'habilitado') === 'deshabilitado') {
        panel.innerHTML = `
            <h3>Detalle del producto</h3>
            <p>Haz clic en un producto para ver información y subcategorías aquí.</p>
            <p style="color:#6b7280; font-size:0.85rem; margin-top:10px;">💡 Tip: Haz <strong>click derecho</strong> sobre cualquier producto en el listado para eliminarlo.</p>
        `;
        return;
    }

    const categoria = categoriaSeleccionada;

    panel.innerHTML = `
        <h3>${escapeHtml(productoSeleccionado.nombre)}</h3>
        <p><strong>Código:</strong> <code>${escapeHtml(productoSeleccionado.codigo)}</code></p>
        ${productoSeleccionado.marca ? `<p><strong>Marca:</strong> ${escapeHtml(productoSeleccionado.marca)}</p>` : ''}
        ${productoSeleccionado.sub_nombre ? `<p><strong>Nombre secundario:</strong> ${escapeHtml(productoSeleccionado.sub_nombre)}</p>` : ''}
        <p><strong>Precio:</strong> $${productoSeleccionado.precio.toLocaleString()}</p>
        <p><strong>Stock:</strong> ${productoSeleccionado.stock} ${(productoSeleccionado.stock <= (productoSeleccionado.stock_minimo ?? 5)) ? '<span class="badge-stock-bajo">⚠️ Stock bajo</span>' : ''}</p>
        <p><strong>Stock mínimo configurado:</strong> ${productoSeleccionado.stock_minimo ?? 5}</p>
        <p><strong>Subcategoría:</strong> ${escapeHtml(productoSeleccionado.subcategoria || 'Sin subcategoría')}</p>
        <h4>Subcategoría del producto</h4>
        <ul class="detail-list">
            <li>${escapeHtml(productoSeleccionado.subcategoria || 'Sin subcategoría')}</li>
        </ul>
        ${usuarioEsAdmin ? `<div style="margin-top: 20px; border-top: 1px solid #e5e7eb; padding-top: 12px; display:flex; flex-direction:column; gap:8px;">
            <button class="btn" style="background:#fee2e2; color:#b91c1c; font-weight:bold; border:1px solid #fca5a5; text-align:center;" onclick="pedirConfirmacionEliminar('${productoSeleccionado.codigo}', '${categoria?.id}', '${productoSeleccionado.nombre}', '${categoria?.nombre}')">
                🗑 Eliminar este producto
            </button>
            <button class="btn" style="background:#dbeafe; color:#1d4ed8; font-weight:bold; border:1px solid #93c5fd; text-align:center;" onclick="mostrarFormularioEdicion()">
                ✏️ Editar producto y contar stock
            </button>
        </div>` : ''}
    `;
}

/**
 * Escapar texto HTML
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

/**
 * ========================================================
 * Módulo exclusivo para Vendedores sin permiso de carga
 * Buscador por categoría y visualización de productos abajo
 * ========================================================
 */

function initVendedorStock() {
    const input = document.getElementById('vendedorCategoryInput');
    const select = document.getElementById('vendedorCategorySelect');
    const resultsContainer = document.getElementById('vendedorStockResults');
    if (!input || !resultsContainer) return;

    renderVendedorCategoryOptions();

    // Si ya hay categorías cargadas, seleccionar la primera por defecto
    if (inventario.length > 0) {
        categoriaSeleccionada = inventario[0];
        if (select) select.value = categoriaSeleccionada.id;
        input.value = categoriaSeleccionada.nombre;
        renderVendedorCategoryView(categoriaSeleccionada);
    } else {
        resultsContainer.innerHTML = `
            <div class="vendedor-empty-state">
                <span style="font-size: 2.2rem;">📦</span>
                <h3>Sin productos disponibles</h3>
                <p>No se encontraron categorías ni productos en la base de datos.</p>
            </div>
        `;
    }

    // Evento de selección directa desde el dropdown
    if (select) {
        select.addEventListener('change', () => {
            const catId = select.value;
            if (!catId) {
                input.value = '';
                if (inventario.length > 0) {
                    categoriaSeleccionada = inventario[0];
                    renderVendedorCategoryView(categoriaSeleccionada);
                }
                return;
            }
            const found = inventario.find(c => String(c.id) === String(catId) || String(c.ID_categoria) === String(catId));
            if (found) {
                categoriaSeleccionada = found;
                input.value = found.nombre;
                renderVendedorCategoryView(found);
            }
        });
    }

    // Evento de búsqueda interactiva en tiempo real ("vaya apareciendo abajo")
    input.addEventListener('input', () => {
        const query = input.value.trim().toLowerCase();
        handleVendedorSearch(query);
    });
}

function renderVendedorCategoryOptions() {
    const select = document.getElementById('vendedorCategorySelect');
    const datalist = document.getElementById('vendedorCategorySuggestions');
    if (!select) return;

    const currentVal = select.value;
    select.innerHTML = '<option value="">-- Seleccionar categoría --</option>' +
        inventario.map(cat => {
            const count = (cat.productos || []).filter(p => (p.fase || 'habilitado') !== 'deshabilitado').length;
            return `<option value="${escapeHtml(cat.id)}">${escapeHtml(cat.nombre)} (${count} productos)</option>`;
        }).join('');

    if (inventario.some(c => String(c.id) === String(currentVal))) {
        select.value = currentVal;
    }

    if (datalist) {
        datalist.innerHTML = inventario.map(cat => `<option value="${escapeHtml(cat.nombre)}"></option>`).join('');
    }
}

function handleVendedorSearch(query) {
    const select = document.getElementById('vendedorCategorySelect');
    const resultsContainer = document.getElementById('vendedorStockResults');
    if (!resultsContainer) return;

    if (!query) {
        if (select && select.value) {
            const found = inventario.find(c => String(c.id) === String(select.value));
            if (found) {
                renderVendedorCategoryView(found);
                return;
            }
        }
        if (inventario.length > 0) {
            categoriaSeleccionada = inventario[0];
            if (select) select.value = categoriaSeleccionada.id;
            renderVendedorCategoryView(categoriaSeleccionada);
        } else {
            resultsContainer.innerHTML = `
                <div class="vendedor-empty-state">
                    <p>No hay categorías registradas.</p>
                </div>
            `;
        }
        return;
    }

    // 1. Coincidencia con nombre de categoría
    const matchingCategories = inventario.filter(c => c.nombre.toLowerCase().includes(query));

    if (matchingCategories.length > 0) {
        const exact = matchingCategories.find(c => c.nombre.toLowerCase() === query) || matchingCategories[0];
        categoriaSeleccionada = exact;
        if (select) select.value = exact.id;

        if (matchingCategories.length === 1) {
            renderVendedorCategoryView(exact);
        } else {
            renderVendedorMultipleCategoriesView(matchingCategories, query);
        }
        return;
    }

    // 2. Coincidencia con productos dentro de alguna categoría (código, nombre, marca o subcategoría)
    const categoriesWithMatchingProducts = [];
    inventario.forEach(cat => {
        const matchingProds = (cat.productos || []).filter(p => {
            const isActive = (p.fase || 'habilitado') !== 'deshabilitado';
            if (!isActive) return false;
            return p.nombre.toLowerCase().includes(query)
                || String(p.codigo).toLowerCase().includes(query)
                || (p.marca && p.marca.toLowerCase().includes(query))
                || (p.subcategoria && p.subcategoria.toLowerCase().includes(query));
        });
        if (matchingProds.length > 0) {
            categoriesWithMatchingProducts.push({
                ...cat,
                productos: matchingProds
            });
        }
    });

    if (categoriesWithMatchingProducts.length > 0) {
        if (select) select.value = '';
        renderVendedorMultipleCategoriesView(categoriesWithMatchingProducts, query);
    } else {
        if (select) select.value = '';
        resultsContainer.innerHTML = `
            <div class="vendedor-empty-state">
                <span style="font-size: 2.2rem;">🔍</span>
                <h3>No se encontraron resultados</h3>
                <p>No se encontraron categorías ni productos que coincidan con <strong>"${escapeHtml(query)}"</strong>.</p>
            </div>
        `;
    }
}

function renderVendedorCategoryView(categoria) {
    const resultsContainer = document.getElementById('vendedorStockResults');
    if (!resultsContainer || !categoria) return;

    const productosActivos = (categoria.productos || []).filter(p => (p.fase || 'habilitado') !== 'deshabilitado');
    let totalValorizado = 0;
    let totalUnidades = 0;

    let rowsHtml = productosActivos.map(p => {
        const total = p.precio * p.stock;
        totalValorizado += total;
        totalUnidades += p.stock;
        const stockMin = p.stock_minimo !== undefined ? Number(p.stock_minimo) : 5;
        const bajoStock = p.stock <= stockMin;

        return `
            <tr>
                <td data-label="Producto">
                    <strong>${escapeHtml(p.nombre)}</strong>
                    ${(p.marca || p.sub_nombre) ? `<small style="display:block;color:#6b7280;">${[p.marca, p.sub_nombre].filter(Boolean).map(escapeHtml).join(' · ')}</small>` : ''}
                </td>
                <td data-label="Código"><code>${escapeHtml(p.codigo)}</code></td>
                <td data-label="Subcategoría">${escapeHtml(p.subcategoria || '—')}</td>
                <td data-label="Precio">$${p.precio.toLocaleString()}</td>
                <td data-label="Stock">
                    <span class="vendedor-stock-num">${p.stock}</span>
                    ${bajoStock ? `<span class="badge-stock-bajo" title="Stock por debajo o igual al mínimo (${stockMin})">⚠️ Bajo</span>` : ''}
                </td>
                <td data-label="Total">$${total.toLocaleString()}</td>
            </tr>
        `;
    }).join('');

    resultsContainer.innerHTML = `
        <div class="vendedor-category-block">
            <div class="vendedor-category-header">
                <div>
                    <h3 class="vendedor-category-title">📁 Categoría: <span>${escapeHtml(categoria.nombre)}</span></h3>
                    <p class="vendedor-category-subtitle">${productosActivos.length} productos en esta categoría</p>
                </div>
            </div>
            <div class="vendedor-table-card">
                <table class="vendedor-table">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Código</th>
                            <th>Subcategoría</th>
                            <th>Precio</th>
                            <th>Stock</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rowsHtml || '<tr><td colspan="6" style="text-align:center; padding: 24px; color:#6b7280;">No hay productos disponibles en esta categoría.</td></tr>'}
                        ${productosActivos.length > 0 ? `
                        <tr class="total-row">
                            <td colspan="4">TOTAL VALORIZADO EN ${escapeHtml(categoria.nombre).toUpperCase()}</td>
                            <td>${totalUnidades} u.</td>
                            <td>$${totalValorizado.toLocaleString()}</td>
                        </tr>
                        ` : ''}
                    </tbody>
                </table>
            </div>
        </div>
    `;
}

function renderVendedorMultipleCategoriesView(categories, query) {
    const resultsContainer = document.getElementById('vendedorStockResults');
    if (!resultsContainer) return;

    let html = `
        <div class="vendedor-results-header">
            <h3>Resultados de búsqueda: "${escapeHtml(query)}"</h3>
            <p>Se encontraron ${categories.length} categoría(s) con coincidencias:</p>
        </div>
    `;

    categories.forEach(categoria => {
        const productosActivos = (categoria.productos || []).filter(p => (p.fase || 'habilitado') !== 'deshabilitado');
        let totalValorizado = 0;
        let totalUnidades = 0;

        let rowsHtml = productosActivos.map(p => {
            const total = p.precio * p.stock;
            totalValorizado += total;
            totalUnidades += p.stock;
            const stockMin = p.stock_minimo !== undefined ? Number(p.stock_minimo) : 5;
            const bajoStock = p.stock <= stockMin;

            return `
                <tr>
                    <td data-label="Producto">
                        <strong>${escapeHtml(p.nombre)}</strong>
                        ${(p.marca || p.sub_nombre) ? `<small style="display:block;color:#6b7280;">${[p.marca, p.sub_nombre].filter(Boolean).map(escapeHtml).join(' · ')}</small>` : ''}
                    </td>
                    <td data-label="Código"><code>${escapeHtml(p.codigo)}</code></td>
                    <td data-label="Subcategoría">${escapeHtml(p.subcategoria || '—')}</td>
                    <td data-label="Precio">$${p.precio.toLocaleString()}</td>
                    <td data-label="Stock">
                        <span class="vendedor-stock-num">${p.stock}</span>
                        ${bajoStock ? `<span class="badge-stock-bajo" title="Stock por debajo o igual al mínimo (${stockMin})">⚠️ Bajo</span>` : ''}
                    </td>
                    <td data-label="Total">$${total.toLocaleString()}</td>
                </tr>
            `;
        }).join('');

        html += `
            <div class="vendedor-category-block" style="margin-bottom: 25px;">
                <div class="vendedor-category-header">
                    <div>
                        <h3 class="vendedor-category-title">📁 Categoría: <span>${escapeHtml(categoria.nombre)}</span></h3>
                        <p class="vendedor-category-subtitle">${productosActivos.length} productos coincidentes</p>
                    </div>
                </div>
                <div class="vendedor-table-card">
                    <table class="vendedor-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Código</th>
                                <th>Subcategoría</th>
                                <th>Precio</th>
                                <th>Stock</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${rowsHtml || '<tr><td colspan="6" style="text-align:center; padding:20px; color:#6b7280;">No hay productos coincidentes.</td></tr>'}
                            ${productosActivos.length > 0 ? `
                            <tr class="total-row">
                                <td colspan="4">TOTAL VALORIZADO EN ${escapeHtml(categoria.nombre).toUpperCase()}</td>
                                <td>${totalUnidades} u.</td>
                                <td>$${totalValorizado.toLocaleString()}</td>
                            </tr>
                            ` : ''}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    });

    resultsContainer.innerHTML = html;
}

