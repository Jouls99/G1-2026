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
let productoSeleccionado = null;
let productoPendienteEliminar = null;
let categoryContextMenu = null;
let productContextMenu = null;
const usuarioEsAdmin = window.usuarioEsAdmin === true;
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
    inventario.forEach(cat => {
        const btn = document.createElement('button');
        btn.className = `btn ${categoriaSeleccionada?.id === cat.id ? 'btn-active' : ''}`;
        btn.innerText = cat.nombre;
        btn.onclick = () => selectCategory(cat.id);
        btn.oncontextmenu = (event) => showCategoryContextMenu(event, cat);
        btn.title = 'Click para abrir • Click derecho para eliminar categoría';
        container.appendChild(btn);
    });
}

function hideCategoryContextMenu() {
    if (categoryContextMenu) {
        categoryContextMenu.remove();
        categoryContextMenu = null;
    }
}

function hideProductContextMenu() {
    if (productContextMenu) {
        productContextMenu.remove();
        productContextMenu = null;
    }
}

function showCategoryContextMenu(event, categoria) {
    if (!usuarioEsAdmin) return;
    event.preventDefault();
    event.stopPropagation();
    hideCategoryContextMenu();
    hideProductContextMenu();

    const menu = document.createElement('div');
    menu.className = 'context-menu';
    menu.innerHTML = `
        <div class="context-menu-header">
            <strong>📁 Categoría: ${escapeHtml(categoria.nombre)}</strong>
        </div>
        <button type="button" class="context-menu-item">
            🗑 Eliminar categoría
        </button>
    `;

    document.body.appendChild(menu);
    categoryContextMenu = menu;

    const menuRect = menu.getBoundingClientRect();
    let top = event.clientY;
    let left = event.clientX;

    if (left + menuRect.width > window.innerWidth) {
        left = window.innerWidth - menuRect.width - 10;
    }
    if (top + menuRect.height > window.innerHeight) {
        top = window.innerHeight - menuRect.height - 10;
    }

    menu.style.top = `${Math.max(10, top)}px`;
    menu.style.left = `${Math.max(10, left)}px`;

    const deleteBtn = menu.querySelector('button');
    deleteBtn.onclick = (e) => {
        e.stopPropagation();
        deleteCategory(categoria.id);
    };
}

function showProductContextMenu(event, codigo, catId) {
    if (!usuarioEsAdmin) return;
    event.preventDefault();
    event.stopPropagation();
    hideCategoryContextMenu();
    hideProductContextMenu();

    let targetProduct = null;
    let targetCat = null;
    for (const cat of inventario) {
        const p = (cat.productos || []).find(prod => String(prod.codigo).toLowerCase() === String(codigo).toLowerCase() && (prod.fase || 'habilitado') !== 'deshabilitado') ||
                  (cat.subcategorias || []).flatMap(s => s.productos || []).find(prod => String(prod.codigo).toLowerCase() === String(codigo).toLowerCase() && (prod.fase || 'habilitado') !== 'deshabilitado');
        if (p) {
            targetProduct = p;
            targetCat = cat;
            break;
        }
    }

    if (!targetProduct) return;

    const menu = document.createElement('div');
    menu.className = 'context-menu';
    menu.innerHTML = `
        <div class="context-menu-header">
            <strong>💄 ${escapeHtml(targetProduct.nombre)}</strong>
            <small style="display:block; color:#6b7280; font-size:0.75rem; margin-top:2px;">Código: ${escapeHtml(targetProduct.codigo)}</small>
        </div>
        <button type="button" class="context-menu-item">
            🗑 Eliminar producto
        </button>
    `;

    document.body.appendChild(menu);
    productContextMenu = menu;

    const menuRect = menu.getBoundingClientRect();
    let top = event.clientY;
    let left = event.clientX;

    if (left + menuRect.width > window.innerWidth) {
        left = window.innerWidth - menuRect.width - 10;
    }
    if (top + menuRect.height > window.innerHeight) {
        top = window.innerHeight - menuRect.height - 10;
    }

    menu.style.top = `${Math.max(10, top)}px`;
    menu.style.left = `${Math.max(10, left)}px`;

    const actionBtn = menu.querySelector('button');
    actionBtn.onclick = (e) => {
        e.stopPropagation();
        hideProductContextMenu();
        pedirConfirmacionEliminar(targetProduct.codigo, targetCat?.id || catId, targetProduct.nombre, targetCat?.nombre || 'General');
    };
}

/**
 * Abrir el modal de confirmación antes de eliminar el producto
 */
function pedirConfirmacionEliminar(codigo, catId, nombre, categoriaNombre) {
    hideProductContextMenu();
    hideCategoryContextMenu();

    productoPendienteEliminar = {
        codigo: codigo,
        catId: catId,
        nombre: nombre || codigo,
        categoriaNombre: categoriaNombre || 'General'
    };

    const modal = document.getElementById('modal-confirmar-eliminar');
    const elNombre = document.getElementById('modal-prod-nombre');
    const elCodigo = document.getElementById('modal-prod-codigo');
    const elCat = document.getElementById('modal-prod-categoria');

    if (elNombre) elNombre.textContent = productoPendienteEliminar.nombre;
    if (elCodigo) elCodigo.textContent = productoPendienteEliminar.codigo;
    if (elCat) elCat.textContent = productoPendienteEliminar.categoriaNombre;

    if (modal) {
        modal.classList.add('active');
        modal.removeAttribute('aria-hidden');
    }
}

/**
 * Cerrar el modal de confirmación
 */
function cerrarModalConfirmacion() {
    const modal = document.getElementById('modal-confirmar-eliminar');
    if (modal) {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
    }
    productoPendienteEliminar = null;
}

/**
 * Ejecutar la eliminación (en la base de datos SQL se actualiza a fase = 'deshabilitado')
 */
async function ejecutarEliminacion() {
    if (!productoPendienteEliminar) return;
    const { codigo, catId, nombre } = productoPendienteEliminar;
    cerrarModalConfirmacion();

    try {
        // Llamar a la API DELETE: en SQL se actualiza a fase = 'deshabilitado'
        const res = await fetch(`api/inventario.php?codigo=${encodeURIComponent(codigo)}`, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' }
        });
        const data = await res.json();
        if (!res.ok && !data.ok) {
            console.warn('Aviso al eliminar producto:', data);
        }
    } catch (e) {
        console.error('Error al contactar con api/inventario.php:', e);
    }

    // Actualizar estado local (marcar como deshabilitado para que no se muestre en pantalla)
    inventario.forEach(cat => {
        (cat.productos || []).forEach(p => {
            if (String(p.codigo).toLowerCase() === String(codigo).toLowerCase()) {
                p.fase = 'deshabilitado';
            }
        });
        (cat.subcategorias || []).forEach(sub => {
            (sub.productos || []).forEach(p => {
                if (String(p.codigo).toLowerCase() === String(codigo).toLowerCase()) {
                    p.fase = 'deshabilitado';
                }
            });
        });
    });

    if (productoSeleccionado && String(productoSeleccionado.codigo).toLowerCase() === String(codigo).toLowerCase()) {
        productoSeleccionado = null;
    }

    await guardarInventario();
    localStorage.setItem('inventarioUpdated', Date.now().toString());
    window.dispatchEvent(new Event('inventario-updated'));

    if (categoriaSeleccionada) {
        renderTable(categoriaSeleccionada);
    }
    renderDetailPanel();

    const searchInput = document.getElementById('globalSearch');
    if (searchInput && searchInput.value.trim() !== '') {
        searchInput.dispatchEvent(new Event('input'));
    }

    alert(`✅ El producto "${nombre}" fue eliminado correctamente.`);
}

function deleteCategory(catId) {
    if (!usuarioEsAdmin) return;
    const categoria = inventario.find(c => c.id === catId);
    if (!categoria) {
        hideCategoryContextMenu();
        return;
    }

    const confirmDelete = confirm(`¿Eliminar la categoría "${categoria.nombre}" y todo su contenido?`);
    if (!confirmDelete) {
        hideCategoryContextMenu();
        return;
    }

    inventario = inventario.filter(c => c.id !== catId);

    if (categoriaSeleccionada?.id === catId) {
        categoriaSeleccionada = inventario[0] || null;
        productoSeleccionado = null;
    }

    guardarInventario();
    renderCategories();

    if (categoriaSeleccionada) {
        renderTable(categoriaSeleccionada);
    } else {
        document.getElementById('mainContent').innerHTML = '<h3>No hay categorías disponibles.</h3>';
    }

    renderDetailPanel();
    hideCategoryContextMenu();
}

function addCategory() {
    if (!usuarioEsAdmin) return;
    const input = document.getElementById('newCatName');
    const nombre = input.value.trim();
    if (!nombre) return;

    const newCat = {
        id: `cat-${Date.now()}`,
        nombre,
        productos: [],
        subcategorias: []
    };

    inventario.push(newCat);
    input.value = '';
    categoriaSeleccionada = newCat;
    guardarInventario();
    renderCategories();
    renderCategorySelector();
    renderTable(categoriaSeleccionada);
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
    renderCategories();
    renderTable(categoriaSeleccionada);
    renderDetailPanel();
}

/**
 * Renderizar la tabla de productos (solo muestra productos activos)
 */
function renderTable(categoria) {
    const content = document.getElementById('mainContent');
    if (!categoria) {
        if (content) content.innerHTML = '<h3>No hay categorías disponibles.</h3>';
        return;
    }

    const productosActivos = usuarioEsSuperAdmin
        ? (categoria.productos || [])
        : (categoria.productos || []).filter(p => (p.fase || 'habilitado') !== 'deshabilitado');

    let totalValorizado = 0;
    let rowsHTML = productosActivos.map(p => {
        const totalProducto = p.precio * p.stock;
        totalValorizado += totalProducto;

        return `
            <tr class="product-row" 
                onclick="selectProduct('${p.codigo}', '${categoria.id}')" 
                oncontextmenu="showProductContextMenu(event, '${p.codigo}', '${categoria.id}')" 
                title="Click izquierdo: ver detalles • Click derecho: eliminar producto">
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
                    <th>Producto</th>
                    <th>Código</th>
                    <th>Precio</th>
                    <th>Stock</th>
                    <th>Total Inventario</th>
                    ${usuarioEsSuperAdmin ? '<th>Estado</th>' : ''}
                </tr>
            </thead>
            <tbody>
                ${rowsHTML || `<tr><td colspan="${usuarioEsSuperAdmin ? '6' : '5'}" style="text-align:center; padding: 24px; color: #6b7280;">No hay productos en esta categoría.</td></tr>`}
                <tr class="total-row">
                    <td colspan="4">TOTAL VALORIZADO</td>
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

function mostrarFormularioEdicion() {
    if (!usuarioEsAdmin || !productoSeleccionado) return;

    const panel = document.getElementById('detailPanel');
    if (!panel || document.getElementById('editProductForm')) return;

    const form = document.createElement('form');
    form.id = 'editProductForm';
    form.className = 'product-form edit-product-form';
    form.innerHTML = `
        <h4>Editar datos del producto</h4>
        <div class="form-grid">
            <div>
                <label for="editProdName">Nombre</label>
                <input id="editProdName" required>
            </div>
            <div>
                <label for="editProdCode">Código</label>
                <input id="editProdCode" readonly>
            </div>
            <div>
                <label for="editProdPrice">Precio</label>
                <input id="editProdPrice" type="number" min="0" step="0.01" required>
            </div>
            <div>
                <label for="editProdStock">Stock</label>
                <input id="editProdStock" type="number" min="0" required>
            </div>
            <div>
                <label for="editProdSub">Subcategoría</label>
                <input id="editProdSub" placeholder="Ej: Labios">
            </div>
        </div>
        <div style="display:flex; gap:8px; margin-top:10px;">
            <button class="btn btn-success" type="submit" style="margin:0;">Guardar cambios</button>
            <button class="btn" type="button" style="margin:0; text-align:center;" onclick="cancelarEdicionProducto()">Cancelar</button>
        </div>
    `;

    panel.appendChild(form);
    document.getElementById('editProdName').value = productoSeleccionado.nombre;
    document.getElementById('editProdCode').value = productoSeleccionado.codigo;
    document.getElementById('editProdPrice').value = productoSeleccionado.precio;
    document.getElementById('editProdStock').value = productoSeleccionado.stock;
    document.getElementById('editProdSub').value = productoSeleccionado.subcategoria || '';
    form.addEventListener('submit', guardarEdicionProducto);
}

function cancelarEdicionProducto() {
    document.getElementById('editProductForm')?.remove();
}

async function guardarEdicionProducto(event) {
    if (!usuarioEsAdmin || !productoSeleccionado) return;
    event.preventDefault();

    const nombre = document.getElementById('editProdName').value.trim();
    const precio = parseFloat(document.getElementById('editProdPrice').value);
    const stock = parseInt(document.getElementById('editProdStock').value, 10);
    const subcategoria = document.getElementById('editProdSub').value.trim();

    if (!nombre || Number.isNaN(precio) || Number.isNaN(stock) || precio < 0 || stock < 0) {
        alert('Completá correctamente el nombre, el precio y el stock.');
        return;
    }

    const productoAnterior = { ...productoSeleccionado };
    try {
        const res = await fetch('api/inventario.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: productoSeleccionado.ID_stock || productoSeleccionado.id,
                ID_stock: productoSeleccionado.ID_stock || productoSeleccionado.id,
                codigo: productoSeleccionado.codigo,
                nombre,
                precio,
                cantidad: stock,
                fase: productoSeleccionado.fase || 'habilitado'
            })
        });
        const data = await res.json();
        if (!res.ok || data.error) {
            throw new Error(data.message || 'No se pudo actualizar el producto.');
        }
    } catch (error) {
        alert(error.message);
        return;
    }

    productoSeleccionado.nombre = nombre;
    productoSeleccionado.precio = precio;
    productoSeleccionado.stock = stock;
    productoSeleccionado.subcategoria = subcategoria || undefined;

    inventario.forEach(cat => {
        cat.productos = (cat.productos || []).map(producto =>
            String(producto.codigo).toLowerCase() === String(productoAnterior.codigo).toLowerCase()
                ? productoSeleccionado
                : producto
        );
    });

    await guardarInventario();
    renderTable(categoriaSeleccionada);
    renderDetailPanel();
    alert(`✅ El producto "${nombre}" fue actualizado correctamente.`);
}

async function addProduct() {
    if (!usuarioEsAdmin) return;
    const name = document.getElementById('prodName').value.trim();
    const code = document.getElementById('prodCode').value.trim();
    const price = parseFloat(document.getElementById('prodPrice').value);
    const stock = parseInt(document.getElementById('prodStock').value, 10);
    const subcategory = document.getElementById('prodSub').value.trim();
    const catId = document.getElementById('prodCat').value;

    if (!name || !code || isNaN(price) || isNaN(stock) || price < 0 || stock < 0) {
        alert('Por favor complete todos los campos requeridos correctamente.');
        return;
    }

    let codeExists = false;
    inventario.forEach(cat => {
        if ((cat.productos || []).some(p => String(p.codigo).toLowerCase() === code.toLowerCase() && (p.fase || 'habilitado') !== 'deshabilitado')) {
            codeExists = true;
        }
        (cat.subcategorias || []).forEach(sub => {
            if ((sub.productos || []).some(p => String(p.codigo).toLowerCase() === code.toLowerCase() && (p.fase || 'habilitado') !== 'deshabilitado')) {
                codeExists = true;
            }
        });
    });

    if (codeExists) {
        alert(`Ya existe un producto activo con el código "${code}".`);
        return;
    }

    const catTarget = inventario.find(c => c.id === catId);
    if (!catTarget) return;

    try {
        const res = await fetch('api/inventario.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                nombre: name,
                codigo: code,
                precio: price,
                cantidad: stock,
                categoria: catTarget.nombre,
                subcategoria: subcategory || null,
                fase: 'habilitado'
            })
        });
        const data = await res.json();
        if (!res.ok || data.error) {
            throw new Error(data.message || 'Error al guardar producto.');
        }

        const newId = data.item ? data.item.id : Date.now();
        const newProduct = {
            id: newId,
            ID_stock: newId,
            nombre: name,
            codigo: code,
            precio: price,
            stock: stock,
            fase: 'habilitado',
            subcategoria: subcategory || undefined
        };

        catTarget.productos = catTarget.productos || [];
        catTarget.productos.push(newProduct);

        if (subcategory) {
            catTarget.subcategorias = catTarget.subcategorias || [];
            let sub = catTarget.subcategorias.find(s => s.nombre.toLowerCase() === subcategory.toLowerCase());
            if (!sub) {
                sub = { id: `sub-${Date.now()}`, nombre: subcategory, productos: [] };
                catTarget.subcategorias.push(sub);
            }
            sub.productos = sub.productos || [];
            sub.productos.push(newProduct);
        }

        await guardarInventario();
        localStorage.setItem('inventarioUpdated', Date.now().toString());
        window.dispatchEvent(new Event('inventario-updated'));

        document.getElementById('stockProductForm')?.reset();
        renderCategorySelector();
        document.getElementById('prodCat').value = catId;
        renderCategories();
        renderTable(categoriaSeleccionada);
        alert(`✅ Producto "${name}" agregado exitosamente.`);
    } catch (err) {
        alert('❌ ' + err.message);
    }
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

// ==========================================================================
// Inicialización y Event Listeners
// ==========================================================================
document.addEventListener('DOMContentLoaded', async () => {
    await cargarInventario();
    renderCategorySelector();

    const btnVerStock = document.getElementById('btn-ver-stock');
    const stockWorkspace = document.getElementById('stock-workspace');
    const stockEmptyState = document.getElementById('stock-empty-state');
    if (btnVerStock) {
        btnVerStock.addEventListener('click', () => {
            stockWorkspace.hidden = false;
            stockEmptyState.hidden = true;
            btnVerStock.hidden = true;
            renderCategories();
            if (categoriaSeleccionada) renderTable(categoriaSeleccionada);
            renderDetailPanel();
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