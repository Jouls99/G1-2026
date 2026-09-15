let inventario = [
  {
    id: "cat-1",
    nombre: "Maquillaje",
    productos: [
      { codigo: "MQL-001", nombre: "Base Matte", precio: 18000, stock: 15, subcategoria: "Bases" },
      { codigo: "MQL-002", nombre: "Rubor Cream", precio: 9500, stock: 22, subcategoria: "Color" }
    ],
    subcategorias: [
      { id: "sub-1-1", nombre: "Bases", productos: [
        { codigo: "BS-001", nombre: "Base Líquida Nude", precio: 16000, stock: 8 }
      ] },
      { id: "sub-1-2", nombre: "Ojos", productos: [
        { codigo: "OJ-001", nombre: "Sombras Compactas", precio: 12000, stock: 10 }
      ] }
    ]
  },
  {
    id: "cat-2",
    nombre: "Skincare",
    productos: [
      { codigo: "SKN-001", nombre: "Serum Vitamina C", precio: 24000, stock: 9, subcategoria: "Tratamientos" },
      { codigo: "SKN-002", nombre: "Crema Hidratante", precio: 15000, stock: 14, subcategoria: "Hidratación" }
    ],
    subcategorias: [
      { id: "sub-2-1", nombre: "Tratamientos", productos: [
        { codigo: "TRT-001", nombre: "Ampolla Vitamina C", precio: 9000, stock: 7 }
      ] }
    ]
  },
  {
    id: "cat-3",
    nombre: "Fragancias",
    productos: [
      { codigo: "FRG-001", nombre: "Perfume Floral", precio: 32000, stock: 6, subcategoria: "Femeninas" }
    ],
    subcategorias: []
  }
];

let categoriaSeleccionada = inventario[0];
let productoSeleccionado = null;
let categoryContextMenu = null;
let productContextMenu = null;

async function guardarInventario() {
    // crear array plano con los campos requeridos: categoria,nombre,codigo,precio,cantidad,total
    const flat = [];
    inventario.forEach(cat => {
        cat.productos.forEach(p => {
            flat.push({ categoria: cat.nombre, nombre: p.nombre, codigo: p.codigo, precio: p.precio, cantidad: p.stock, total: p.precio * p.stock });
        });
        // incluir productos de subcategorias también
        (cat.subcategorias || []).forEach(sub => {
            (sub.productos || []).forEach(p => {
                flat.push({ categoria: cat.nombre, nombre: p.nombre, codigo: p.codigo, precio: p.precio, cantidad: p.stock, total: p.precio * p.stock });
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
        // fallback a localStorage
        localStorage.setItem('inventarioCosmetica', JSON.stringify(inventario));
    }
}

async function cargarInventario() {
    try {
        const res = await fetch('api/inventario.php');
        if (!res.ok) throw new Error('no_server');
        const flat = await res.json();
        if (Array.isArray(flat) && flat.length > 0) {
            // reconstruir estructura por categorias
            const map = new Map();
            flat.forEach(item => {
                const catName = item.categoria || 'Sin categoría';
                if (!map.has(catName)) {
                    map.set(catName, { id: `cat-${catName.replace(/\s+/g,'-').toLowerCase()}`, nombre: catName, productos: [], subcategorias: [] });
                }
                const cat = map.get(catName);
                cat.productos.push({ 
                    ID_stock: item.ID_stock || item.id,
                    codigo: item.codigo, 
                    nombre: item.nombre, 
                    precio: item.precio, 
                    stock: item.cantidad, 
                    subcategoria: item.subcategoria || undefined 
                });
            });
            inventario = Array.from(map.values());
            categoriaSeleccionada = inventario[0] || null;
            return;
        }
    } catch (e) {
        // ignore and fallback to localStorage
    }

    const guardado = localStorage.getItem('inventarioCosmetica');
    if (guardado) {
        inventario = JSON.parse(guardado);
        categoriaSeleccionada = inventario[0] || null;
    }
}

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
    event.preventDefault();
    event.stopPropagation();
    hideCategoryContextMenu();
    hideProductContextMenu();

    const menu = document.createElement('div');
    menu.className = 'context-menu';
    menu.innerHTML = `
        <div class="context-menu-header">
            <strong>📁 Categoría: ${categoria.nombre}</strong>
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
    event.preventDefault();
    event.stopPropagation();
    hideCategoryContextMenu();
    hideProductContextMenu();

    // Buscar el producto en el inventario
    let targetProduct = null;
    let targetCat = null;
    for (const cat of inventario) {
        const p = (cat.productos || []).find(prod => String(prod.codigo).toLowerCase() === String(codigo).toLowerCase()) ||
                  (cat.subcategorias || []).flatMap(s => s.productos || []).find(prod => String(prod.codigo).toLowerCase() === String(codigo).toLowerCase());
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
            <strong>💄 ${targetProduct.nombre}</strong>
            <small style="display:block; color:#6b7280; font-size:0.75rem; margin-top:2px;">Código: ${targetProduct.codigo}</small>
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

    const deleteBtn = menu.querySelector('button');
    deleteBtn.onclick = (e) => {
        e.stopPropagation();
        deleteProduct(targetProduct.codigo, targetCat?.id || catId, targetProduct.nombre);
    };
}

async function deleteProduct(codigo, catId, nombre) {
    hideProductContextMenu();
    hideCategoryContextMenu();

    const nombreMostrado = nombre || codigo;
    const confirmDelete = confirm(`¿Estás seguro de que deseás eliminar el producto "${nombreMostrado}" (${codigo})?\n\nEsta acción lo borrará permanentemente de la tabla y de la base de datos.`);
    if (!confirmDelete) return;

    try {
        // 1. Llamar a la API DELETE para borrar de MySQL y de inventario.json
        const res = await fetch(`api/inventario.php?codigo=${encodeURIComponent(codigo)}`, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' }
        });
        const data = await res.json();
        if (!res.ok && !data.ok) {
            console.warn('Aviso al eliminar producto de la base de datos:', data);
        }
    } catch (e) {
        console.error('Error al contactar con api/inventario.php:', e);
    }

    // 2. Eliminar del estado local en memoria
    inventario.forEach(cat => {
        cat.productos = (cat.productos || []).filter(p => String(p.codigo).toLowerCase() !== String(codigo).toLowerCase());
        if (Array.isArray(cat.subcategorias)) {
            cat.subcategorias.forEach(sub => {
                if (Array.isArray(sub.productos)) {
                    sub.productos = sub.productos.filter(p => String(p.codigo).toLowerCase() !== String(codigo).toLowerCase());
                }
            });
        }
    });

    // 3. Si el producto eliminado estaba seleccionado en el panel de detalle, limpiarlo
    if (productoSeleccionado && String(productoSeleccionado.codigo).toLowerCase() === String(codigo).toLowerCase()) {
        productoSeleccionado = null;
    }

    // 4. Guardar inventario actualizado para consistencia
    await guardarInventario();
    localStorage.setItem('inventarioUpdated', Date.now().toString());
    window.dispatchEvent(new Event('inventario-updated'));

    // 5. Refrescar la interfaz
    if (categoriaSeleccionada) {
        renderTable(categoriaSeleccionada);
    }
    renderDetailPanel();

    // Si hay una búsqueda activa, refrescar resultados
    const searchInput = document.getElementById('globalSearch');
    if (searchInput && searchInput.value.trim() !== '') {
        searchInput.dispatchEvent(new Event('input'));
    }

    alert(`✅ El producto "${nombreMostrado}" fue eliminado correctamente de la interfaz y de la base de datos.`);
}

function deleteCategory(catId) {
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
    renderTable(categoriaSeleccionada);
}

function selectCategory(catId) {
    categoriaSeleccionada = inventario.find(c => c.id === catId);
    productoSeleccionado = null;
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

    let totalValorizado = 0;
    let rowsHTML = (categoria.productos || []).map(p => {
        const totalProducto = p.precio * p.stock;
        totalValorizado += totalProducto;
        return `<tr class="product-row" onclick="selectProduct('${p.codigo}', '${categoria.id}')" oncontextmenu="showProductContextMenu(event, '${p.codigo}', '${categoria.id}')" title="Click izquierdo: ver detalles • Click derecho: borrar producto">
            <td>${p.nombre}</td>
            <td>${p.codigo}</td>
            <td>$${p.precio.toLocaleString()}</td>
            <td>${p.stock}</td>
            <td>$${totalProducto.toLocaleString()}</td>
        </tr>`;
    }).join('');

    let html = `
        <h2>Categoría: ${categoria.nombre}</h2>
        <div class="product-form">
            <h3>Agregar nuevo producto</h3>
            <form id="productForm" onsubmit="event.preventDefault(); addProduct();">
                <div class="form-grid">
                    <div>
                        <label for="prodName">Nombre</label>
                        <input id="prodName" required>
                    </div>
                    <div>
                        <label for="prodCode">Código</label>
                        <input id="prodCode" required>
                    </div>
                    <div>
                        <label for="prodPrice">Precio</label>
                        <input id="prodPrice" type="number" step="0.01" required>
                    </div>
                    <div>
                        <label for="prodStock">Stock</label>
                        <input id="prodStock" type="number" required>
                    </div>
                    <div>
                        <label for="prodSub">Subcategoría</label>
                        <input id="prodSub" placeholder="Ej: Labios">
                    </div>
                    <div>
                        <label for="prodCat">Categoría</label>
                        <select id="prodCat">
                            ${inventario.map(cat => `<option value="${cat.id}" ${cat.id === categoria.id ? 'selected' : ''}>${cat.nombre}</option>`).join('')}
                        </select>
                    </div>
                </div>
                <button class="btn btn-success" type="submit" style="margin-top:10px;">+ Agregar producto</button>
            </form>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Producto</th>
                    <th>Código</th>
                    <th>Precio</th>
                    <th>Stock</th>
                    <th>Total Inventario</th>
                </tr>
            </thead>
            <tbody>
                ${rowsHTML || '<tr><td colspan="5">No hay productos en esta categoría.</td></tr>'}
                <tr class="total-row">
                    <td colspan="4">TOTAL VALORIZADO</td>
                    <td>$${totalValorizado.toLocaleString()}</td>
                </tr>
            </tbody>
        </table>
    `;

    content.innerHTML = html;
}

function selectProduct(codigo, catId) {
    const cat = inventario.find(c => c.id === catId);
    if (!cat) return;
    productoSeleccionado = cat.productos.find(p => String(p.codigo).toLowerCase() === String(codigo).toLowerCase());
    renderDetailPanel();
}

function renderDetailPanel() {
    const panel = document.getElementById('detailPanel');
    if (!panel) return;

    if (!productoSeleccionado) {
        panel.innerHTML = `
            <h3>Detalle del producto</h3>
            <p>Haz clic en un producto para ver información y subcategorías aquí.</p>
            <p style="color:#6b7280; font-size:0.85rem; margin-top:10px;">💡 Tip: Haz <strong>click derecho</strong> sobre cualquier producto en la tabla para eliminarlo.</p>
        `;
        return;
    }

    const categoria = categoriaSeleccionada;
    panel.innerHTML = `
        <h3>${productoSeleccionado.nombre}</h3>
        <p><strong>Código:</strong> ${productoSeleccionado.codigo}</p>
        <p><strong>Precio:</strong> $${productoSeleccionado.precio.toLocaleString()}</p>
        <p><strong>Stock:</strong> ${productoSeleccionado.stock}</p>
        <p><strong>Subcategoría:</strong> ${productoSeleccionado.subcategoria || 'Sin subcategoría'}</p>
        <h4>Subcategorías de la categoría</h4>
        <ul class="detail-list">
            ${(categoria?.subcategorias || []).map(sub => `<li>${sub.nombre}</li>`).join('') || '<li>No hay subcategorías</li>'}
        </ul>
        <div style="margin-top: 20px; border-top: 1px solid #e5e7eb; padding-top: 12px;">
            <button class="btn" style="background:#fee2e2; color:#b91c1c; font-weight:bold; border:1px solid #fca5a5; text-align:center;" onclick="deleteProduct('${productoSeleccionado.codigo}', '${categoria?.id}', '${productoSeleccionado.nombre}')">
                🗑 Eliminar este producto
            </button>
            <button class="btn" style="background:#dbeafe; color:#1d4ed8; font-weight:bold; border:1px solid #93c5fd; text-align:center;" onclick="mostrarFormularioEdicion()">
                ✏️ Editar producto
            </button>
        </div>
    `;
}

function mostrarFormularioEdicion() {
    if (!productoSeleccionado) return;

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
    event.preventDefault();
    if (!productoSeleccionado) return;

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
                cantidad: stock
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
    const name = document.getElementById('prodName').value.trim();
    const code = document.getElementById('prodCode').value.trim();
    const price = parseFloat(document.getElementById('prodPrice').value);
    const stock = parseInt(document.getElementById('prodStock').value, 10);
    const subcategory = document.getElementById('prodSub').value.trim();
    const catId = document.getElementById('prodCat').value;

    if (!name || !code || isNaN(price) || isNaN(stock)) return;

    const categoriaDestino = inventario.find(c => c.id === catId);
    if (!categoriaDestino) return;

    const nuevoProducto = {
        codigo: code,
        nombre: name,
        precio: price,
        stock,
        subcategoria: subcategory || 'General'
    };

    try {
        // Enviar a la API POST para persistir en MySQL y JSON
        const res = await fetch('api/inventario.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                nombre: name,
                codigo: code,
                precio: price,
                cantidad: stock,
                categoria: categoriaDestino.nombre,
                subcategoria: subcategory || null
            })
        });
        const data = await res.json();
        if (data.ok && data.item) {
            nuevoProducto.ID_stock = data.item.ID_stock || data.item.id;
        }
    } catch (e) {
        console.warn('Guardado local como respaldo tras aviso de API:', e);
    }

    categoriaDestino.productos.push(nuevoProducto);
    if (subcategory) {
        let sub = categoriaDestino.subcategorias.find(s => s.nombre.toLowerCase() === subcategory.toLowerCase());
        if (!sub) {
            sub = { id: `sub-${Date.now()}`, nombre: subcategory, productos: [] };
            categoriaDestino.subcategorias.push(sub);
        }
        sub.productos.push({ ...nuevoProducto, codigo: `${code}-sub` });
    }

    await guardarInventario();
    categoriaSeleccionada = categoriaDestino;
    renderCategories();
    renderTable(categoriaSeleccionada);
    productoSeleccionado = nuevoProducto;
    renderDetailPanel();
    document.getElementById('productForm').reset();
}

function selectProductFromSearch(codigo, catId) {
    const cat = inventario.find(c => c.id === catId);
    if (!cat) return;

    const producto = (cat.productos || []).find(p => String(p.codigo).toLowerCase() === String(codigo).toLowerCase()) ||
        (cat.subcategorias || []).flatMap(sub => sub.productos || []).find(p => String(p.codigo).toLowerCase() === String(codigo).toLowerCase());

    if (!producto) return;

    categoriaSeleccionada = cat;
    productoSeleccionado = producto;
    renderCategories();
    renderTable(categoriaSeleccionada);
    renderDetailPanel();
    document.getElementById('globalSearch').value = '';
}

document.getElementById('globalSearch').addEventListener('input', (e) => {
    const query = e.target.value.toLowerCase();
    if (!query) {
        if (categoriaSeleccionada) renderTable(categoriaSeleccionada);
        return;
    }

    let resultados = [];
    inventario.forEach(cat => {
        (cat.productos || []).forEach(p => {
            if (p.nombre.toLowerCase().includes(query) || p.codigo.toLowerCase().includes(query)) {
                resultados.push({ ...p, catId: cat.id, catNombre: cat.nombre });
            }
        });
        (cat.subcategorias || []).forEach(sub => {
            (sub.productos || []).forEach(p => {
                if (p.nombre.toLowerCase().includes(query) || p.codigo.toLowerCase().includes(query)) {
                    resultados.push({ ...p, catId: cat.id, catNombre: `${cat.nombre} > ${sub.nombre}` });
                }
            });
        });
    });

    const content = document.getElementById('mainContent');
    content.innerHTML = `
        <h2>Resultados de la búsqueda: "${e.target.value}"</h2>
        <table>
            <thead>
                <tr><th>Producto</th><th>Código</th><th>Precio</th><th>Stock</th><th>Ubicación</th></tr>
            </thead>
            <tbody>
                ${resultados.map(r => `
                    <tr class="product-row" onclick="selectProductFromSearch('${r.codigo}', '${r.catId}')" oncontextmenu="showProductContextMenu(event, '${r.codigo}', '${r.catId}')" title="Click izquierdo: ver detalles • Click derecho: borrar producto">
                        <td>${r.nombre}</td>
                        <td>${r.codigo}</td>
                        <td>$${r.precio.toLocaleString()}</td>
                        <td>${r.stock}</td>
                        <td><small>${r.catNombre}</small></td>
                    </tr>
                `).join('') || '<tr><td colspan="5">No se encontraron coincidencias.</td></tr>'}
            </tbody>
        </table>
    `;
});

function exportarJSON() {
    const dataStr = JSON.stringify(inventario, null, 2);
    const blob = new Blob([dataStr], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'inventario-cosmetica.json';
    link.click();
    URL.revokeObjectURL(url);
}

document.addEventListener('click', () => {
    hideCategoryContextMenu();
    hideProductContextMenu();
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        hideCategoryContextMenu();
        hideProductContextMenu();
    }
});

(async () => {
    await cargarInventario();
    renderCategories();
    renderTable(categoriaSeleccionada || inventario[0]);
    renderDetailPanel();
})();

window.addEventListener('storage', async (event) => {
    if (event.key === 'inventarioUpdated') {
        await cargarInventario();
        renderCategories();
        renderTable(categoriaSeleccionada || inventario[0]);
        renderDetailPanel();
    }
});

window.addEventListener('inventario-updated', async () => {
    await cargarInventario();
    renderCategories();
    renderTable(categoriaSeleccionada || inventario[0]);
    renderDetailPanel();
});