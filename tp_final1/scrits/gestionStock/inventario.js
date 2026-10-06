/**
 * Inventario: estado, carga y visualizacion
 */

/**
 * Panel de Gestión y Control de Stock
 * Manejo de eliminación con confirmación y marcado deshabilitado en base de datos SQL
 */

let inventario = [
  {
    id: "cat-1",
    nombre: "Maquillaje",
    productos: [
      { codigo: "MQL-001", nombre: "Base Matte", precio: 18000, stock: 15, subcategoria: "Bases", fase: "habilitado" },
      { codigo: "MQL-002", nombre: "Rubor Cream", precio: 9500, stock: 22, subcategoria: "Color", fase: "habilitado" }
    ],
    subcategorias: [
      { id: "sub-1-1", nombre: "Bases", productos: [
        { codigo: "BS-001", nombre: "Base Líquida Nude", precio: 16000, stock: 8, fase: "habilitado" }
      ] },
      { id: "sub-1-2", nombre: "Ojos", productos: [
        { codigo: "OJ-001", nombre: "Sombras Compactas", precio: 12000, stock: 10, fase: "habilitado" }
      ] }
    ]
  },
  {
    id: "cat-2",
    nombre: "Skincare",
    productos: [
      { codigo: "SKN-001", nombre: "Serum Vitamina C", precio: 24000, stock: 9, subcategoria: "Tratamientos", fase: "habilitado" },
      { codigo: "SKN-002", nombre: "Crema Hidratante", precio: 15000, stock: 14, subcategoria: "Hidratación", fase: "habilitado" }
    ],
    subcategorias: [
      { id: "sub-2-1", nombre: "Tratamientos", productos: [
        { codigo: "TRT-001", nombre: "Ampolla Vitamina C", precio: 9000, stock: 7, fase: "habilitado" }
      ] }
    ]
  },
  {
    id: "cat-3",
    nombre: "Fragancias",
    productos: [
      { codigo: "FRG-001", nombre: "Perfume Floral", precio: 32000, stock: 6, subcategoria: "Femeninas", fase: "habilitado" }
    ],
    subcategorias: []
  }
];

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
 * Guardar inventario plano en la base de datos MySQL (y respaldo JSON)
 */
async function guardarInventario() {
    if (!usuarioEsAdmin) return;
    const flat = [];
    inventario.forEach(cat => {
        (cat.productos || []).forEach(p => {
            flat.push({
                categoria: cat.nombre,
                nombre: p.nombre,
                codigo: p.codigo,
                subcategoria: p.subcategoria || null,
                precio: p.precio,
                cantidad: p.stock,
                fase: p.fase || 'habilitado',
                total: p.precio * p.stock
            });
        });
        (cat.subcategorias || []).forEach(sub => {
            (sub.productos || []).forEach(p => {
                flat.push({
                    categoria: cat.nombre,
                    nombre: p.nombre,
                    codigo: p.codigo,
                    subcategoria: p.subcategoria || sub.nombre || null,
                    precio: p.precio,
                    cantidad: p.stock,
                    fase: p.fase || 'habilitado',
                    total: p.precio * p.stock
                });
            });
        });
    });

    try {
        const res = await fetch('api/inventario.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(flat)
        });
        if (!res.ok) throw new Error('no_server');
        localStorage.setItem('inventarioCosmetica', JSON.stringify(inventario));
    } catch (e) {
        localStorage.setItem('inventarioCosmetica', JSON.stringify(inventario));
    }
}

/**
 * Cargar inventario desde la API (MySQL / inventario.json)
 */
async function cargarInventario() {
    try {
        const res = await fetch('api/inventario.php', { cache: 'no-store' });
        if (!res.ok) throw new Error('no_server');
        const flat = await res.json();
        if (Array.isArray(flat) && flat.length > 0) {
            const map = new Map();
            flat.forEach(item => {
                const catName = item.categoria || 'Sin categoría';
                if (!map.has(catName)) {
                    map.set(catName, {
                        id: `cat-${catName.replace(/\s+/g,'-').toLowerCase()}`,
                        ID_categoria: item.ID_categoria || null,
                        nombre: catName,
                        productos: [],
                        subcategorias: []
                    });
                }
                const cat = map.get(catName);
                cat.productos.push({ 
                    ID_stock: item.ID_stock || item.id,
                    codigo: item.codigo, 
                    nombre: item.nombre, 
                    precio: item.precio, 
                    stock: item.cantidad,
                    fase: item.fase || 'habilitado',
                    subcategoria: item.subcategoria || undefined 
                });
            });
            inventario = Array.from(map.values());
            categoriaSeleccionada = inventario[0] || null;
            return;
        }
    } catch (e) {
        // Fallback a localStorage
    }

    const guardado = localStorage.getItem('inventarioCosmetica');
    if (guardado) {
        inventario = JSON.parse(guardado);
        if (!usuarioEsSuperAdmin) {
            inventario.forEach(cat => {
                (cat.productos || []).forEach(p => delete p.fase);
                (cat.subcategorias || []).forEach(sub => (sub.productos || []).forEach(p => delete p.fase));
            });
        }
        categoriaSeleccionada = inventario[0] || null;
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
        btn.title = 'Click para abrir • Click derecho para eliminar categoría';
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
                ${usuarioEsAdmin ? `<td><input class="price-product-checkbox" data-product-id="${productId}" type="checkbox" aria-label="Seleccionar ${escapeHtml(p.nombre)}" onclick="event.stopPropagation()" onchange="togglePriceProduct(${productId}, this.checked)" ${productosPrecioSeleccionados.has(productId) ? 'checked' : ''}></td>` : ''}
                <td><strong>${escapeHtml(p.nombre)}</strong></td>
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
                    ${usuarioEsAdmin ? '<th><input type="checkbox" aria-label="Seleccionar todos los productos visibles" onclick="event.stopPropagation()" onchange="toggleVisiblePriceProducts(this.checked)"></th>' : ''}
                    <th>Producto</th>
                    <th>Código</th>
                    <th>Precio</th>
                    <th>Stock</th>
                    <th>Total Inventario</th>
                    ${usuarioEsSuperAdmin ? '<th>Estado</th>' : ''}
                </tr>
            </thead>
            <tbody>
                ${rowsHTML || `<tr><td colspan="${(usuarioEsSuperAdmin ? 6 : 5) + (usuarioEsAdmin ? 1 : 0)}" style="text-align:center; padding: 24px; color: #6b7280;">No hay productos que coincidan con este filtro.</td></tr>`}
                <tr class="total-row">
                    <td colspan="${(usuarioEsAdmin ? 1 : 0) + 4}">TOTAL VALORIZADO</td>
                    <td>$${totalValorizado.toLocaleString()}</td>
                    ${usuarioEsSuperAdmin ? '<td></td>' : ''}
                </tr>
            </tbody>
        </table>
    `;

    content.innerHTML = html;
}

function selectProduct(codigo, catId) {
    const cat = inventario.find(c => c.id === catId);
    if (!cat) return;
    productoSeleccionado = cat.productos.find(p => String(p.codigo).toLowerCase() === String(codigo).toLowerCase() && (p.fase || 'habilitado') !== 'deshabilitado');
    renderDetailPanel();
}

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

/**
 * Exportar archivo JSON
 */
function exportarJSON() {
    const flat = [];
    inventario.forEach(cat => {
        (cat.productos || []).forEach(p => {
            if ((p.fase || 'habilitado') !== 'deshabilitado') {
                flat.push({
                    ID_stock: p.ID_stock || p.id,
                    categoria: cat.nombre,
                    nombre: p.nombre,
                    codigo: p.codigo,
                    precio: p.precio,
                    cantidad: p.stock,
                    total: p.precio * p.stock
                });
            }
        });
    });

    const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(flat, null, 2));
    const dlAnchor = document.createElement('a');
    dlAnchor.setAttribute("href", dataStr);
    dlAnchor.setAttribute("download", `inventario_completo_${new Date().toISOString().slice(0,10)}.json`);
    dlAnchor.click();
    dlAnchor.remove();
}

