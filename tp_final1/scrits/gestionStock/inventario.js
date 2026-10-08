/**
 * Inventario: estado, carga y visualizacion
 */

/**
 * Panel de Gestión y Control de Stock
 * Manejo de eliminación con confirmación y marcado deshabilitado en base de datos SQL
 */

let inventario = [];
let categoriaSeleccionada = inventario[0];
let categoriaFiltro = '';
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
    inventario
        .filter(cat => !categoriaFiltro || cat.id === categoriaFiltro)
        .forEach(cat => {
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

function renderCategoryFilter() {
    const filter = document.getElementById('categoryFilter');
    if (!filter) return;

    filter.innerHTML = '<option value="">Todas las categorías</option>';
    inventario.forEach(cat => {
        const option = document.createElement('option');
        option.value = cat.id;
        option.textContent = cat.nombre;
        filter.appendChild(option);
    });
    filter.value = inventario.some(cat => cat.id === categoriaFiltro) ? categoriaFiltro : '';
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
}

// Selecciona una categoría y actualiza tabla, detalle y filtros de ajustes asociados.
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

    let totalValorizado = 0;
    let rowsHTML = productosVisibles.map(p => {
        const totalProducto = p.precio * p.stock;
        totalValorizado += totalProducto;
        const productId = Number(p.ID_stock || p.id);

        return `
            <tr class="product-row" 
                onclick="selectProduct('${p.codigo}', '${categoria.id}')" 
                oncontextmenu="showProductContextMenu(event, '${p.codigo}', '${categoria.id}')" 
                title="Click izquierdo: ver detalles • Click derecho: eliminar producto">
                ${mostrarSeleccionPrecios ? `<td><input class="price-product-checkbox" data-product-id="${productId}" type="checkbox" aria-label="Seleccionar ${escapeHtml(p.nombre)}" onclick="event.stopPropagation()" onchange="togglePriceProduct(${productId}, this.checked)" ${productosPrecioSeleccionados.has(productId) ? 'checked' : ''}></td>` : ''}
                <td>
                    <strong>${escapeHtml(p.nombre)}</strong>
                    ${(p.marca || p.sub_nombre) ? `<small style="display:block;color:#6b7280;">${[p.marca, p.sub_nombre].filter(Boolean).map(escapeHtml).join(' · ')}</small>` : ''}
                </td>
                <td><code>${escapeHtml(p.codigo)}</code></td>
                <td>$${p.precio.toLocaleString()}</td>
                <td>${p.stock}</td>
                <td>$${totalProducto.toLocaleString()}</td>
                ${usuarioEsSuperAdmin ? `<td><span class="badge-fase badge-fase-${p.fase === 'deshabilitado' ? 'deshabilitado' : 'habilitado'}">${p.fase === 'deshabilitado' ? 'Deshabilitado' : 'Habilitado'}</span></td>` : ''}
            </tr>
        `;
    }).join('');

    let html = `
        <h2>Categoría: ${escapeHtml(categoria.nombre)}</h2>
        <table>
            <thead>
                <tr>
                    ${mostrarSeleccionPrecios ? '<th><input type="checkbox" aria-label="Seleccionar todos los productos visibles" onclick="event.stopPropagation()" onchange="toggleVisiblePriceProducts(this.checked)"></th>' : ''}
                    <th>Producto</th>
                    <th>Código</th>
                    <th>Precio</th>
                    <th>Stock</th>
                    <th>Total Inventario</th>
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
function selectProduct(codigo, catId) {
    const cat = inventario.find(c => c.id === catId);
    if (!cat) return;
    productoSeleccionado = cat.productos.find(p => String(p.codigo).toLowerCase() === String(codigo).toLowerCase() && (p.fase || 'habilitado') !== 'deshabilitado');
    renderDetailPanel();
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
            <p style="color:#6b7280; font-size:0.85rem; margin-top:10px;">💡 Tip: Haz <strong>click derecho</strong> sobre cualquier producto en la tabla para eliminarlo.</p>
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
        <p><strong>Stock:</strong> ${productoSeleccionado.stock}</p>
        <p><strong>Subcategoría:</strong> ${escapeHtml(productoSeleccionado.subcategoria || 'Sin subcategoría')}</p>
        <h4>Subcategorías de la categoría</h4>
        <ul class="detail-list">
            ${(categoria?.subcategorias || []).map(sub => `<li>${escapeHtml(sub.nombre)}</li>`).join('') || '<li>No hay subcategorías</li>'}
        </ul>
        ${usuarioEsAdmin ? `<div style="margin-top: 20px; border-top: 1px solid #e5e7eb; padding-top: 12px; display:flex; flex-direction:column; gap:8px;">
            <button class="btn" style="background:#fee2e2; color:#b91c1c; font-weight:bold; border:1px solid #fca5a5; text-align:center;" onclick="pedirConfirmacionEliminar('${productoSeleccionado.codigo}', '${categoria?.id}', '${productoSeleccionado.nombre}', '${categoria?.nombre}')">
                🗑 Eliminar este producto
            </button>
            <button class="btn" style="background:#dbeafe; color:#1d4ed8; font-weight:bold; border:1px solid #93c5fd; text-align:center;" onclick="mostrarFormularioEdicion()">
                ✏️ Editar producto
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
