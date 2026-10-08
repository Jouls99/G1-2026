
        // Catálogo local inicial: se sustituye con la persistencia disponible al cargar el panel.
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

// Selección y menú temporal que comparten las funciones de renderizado e interacción.
let categoriaSeleccionada = inventario[0];
let productoSeleccionado = null;
let categoryContextMenu = null;

// Serializar productos principales y secundarios en filas planas, persistiendo en API y en respaldo local.
async function guardarInventario() {
    // crear array plano con los campos requeridos: categoria,nombre,codigo,precio,cantidad,total
    // Aplanar el modelo jerárquico al formato de campos que espera el endpoint de inventario.
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
        const res = await fetch('/api/inventario', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(flat)
        });
        if (!res.ok) throw new Error('no_server');
        // también guardamos una copia local
        // Conservar copia local para que el panel pueda recuperar su estado sin depender del servidor.
        localStorage.setItem('inventarioCosmetica', JSON.stringify(inventario));
    } catch (e) {
        // fallback a localStorage
        // Mantener las modificaciones aunque el endpoint no esté disponible.
        localStorage.setItem('inventarioCosmetica', JSON.stringify(inventario));
    }
}

// Leer la API y reconstruir categorías; si no responde, recuperar la copia de localStorage.
async function cargarInventario() {
    try {
        const res = await fetch('/api/inventario');
        if (!res.ok) throw new Error('no_server');
        const flat = await res.json();
        if (Array.isArray(flat) && flat.length > 0) {
            // reconstruir estructura por categorias
            // Agrupar las filas planas por categoría para recuperar el modelo usado por la interfaz.
            const map = new Map();
            flat.forEach(item => {
                const catName = item.categoria || 'Sin categoría';
                if (!map.has(catName)) {
                    map.set(catName, { id: `cat-${catName.replace(/\s+/g,'-').toLowerCase()}`, nombre: catName, productos: [], subcategorias: [] });
                }
                const cat = map.get(catName);
                cat.productos.push({ codigo: item.codigo, nombre: item.nombre, precio: item.precio, stock: item.cantidad, subcategoria: item.subcategoria || undefined });
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

// Repintar la navegación de categorías y asociar apertura y eliminación contextual.
function renderCategories() {
    const container = document.getElementById('categoriesContainer');
    container.innerHTML = '';
    inventario.forEach(cat => {
        const btn = document.createElement('button');
        btn.className = `btn ${categoriaSeleccionada?.id === cat.id ? 'btn-active' : ''}`;
        btn.innerText = cat.nombre;
        btn.onclick = () => selectCategory(cat.id);
        btn.oncontextmenu = (event) => showCategoryContextMenu(event, cat);
        btn.title = 'Click para abrir • Click derecho para eliminar';
        container.appendChild(btn);
    });
}

// Retirar el menú previo al cerrar o mostrar otro.
function hideCategoryContextMenu() {
    if (categoryContextMenu) {
        categoryContextMenu.remove();
        categoryContextMenu = null;
    }
}

// Ubicar junto al puntero el acceso contextual para eliminar la categoría elegida.
function showCategoryContextMenu(event, categoria) {
    event.preventDefault();
    hideCategoryContextMenu();

    const menu = document.createElement('div');
    menu.className = 'context-menu';
    menu.innerHTML = `
        <button type="button" class="context-menu-item">🗑 Eliminar categoría</button>
    `;

    menu.style.top = `${event.clientY}px`;
    menu.style.left = `${event.clientX}px`;

    const deleteBtn = menu.querySelector('button');
    deleteBtn.onclick = () => deleteCategory(categoria.id);

    document.body.appendChild(menu);
    categoryContextMenu = menu;
}

// Confirmar la eliminación y dejar los paneles en una categoría válida o en estado vacío.
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

// Crear una categoría vacía desde el formulario lateral y seleccionarla para continuar la carga.
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

// Cambiar de categoría y limpiar cualquier selección de producto anterior.
function selectCategory(catId) {
    categoriaSeleccionada = inventario.find(c => c.id === catId);
    productoSeleccionado = null;
    renderCategories();
    renderTable(categoriaSeleccionada);
    renderDetailPanel();
}

// Generar el formulario de producto, filas de stock y valor consolidado para la categoría actual.
function renderTable(categoria) {
    const content = document.getElementById('mainContent');
    if (!categoria) return;

    let totalValorizado = 0;
    let rowsHTML = categoria.productos.map(p => {
        const totalProducto = p.precio * p.stock;
        totalValorizado += totalProducto;
        return `<tr class="product-row" onclick="selectProduct('${p.codigo}', '${categoria.id}')">
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
                        <input id="prodPrice" type="number" required>
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

// Buscar el producto seleccionado por código dentro de la categoría y refrescar su ficha.
function selectProduct(codigo, catId) {
    const cat = inventario.find(c => c.id === catId);
    if (!cat) return;
    productoSeleccionado = cat.productos.find(p => p.codigo === codigo);
    renderDetailPanel();
}

// Mostrar ficha del producto o instrucciones iniciales en el panel lateral de detalles.
function renderDetailPanel() {
    const panel = document.getElementById('detailPanel');

    if (!productoSeleccionado) {
        panel.innerHTML = `
            <h3>Detalle del producto</h3>
            <p>Haz clic en un producto para ver información y subcategorías aquí.</p>
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
    `;
}

// Validar el formulario, incorporar el producto y conservarlo tanto en categoría como en subcategoría elegida.
function addProduct() {
    const name = document.getElementById('prodName').value.trim();
    const code = document.getElementById('prodCode').value.trim();
    const price = parseFloat(document.getElementById('prodPrice').value);
    const stock = parseInt(document.getElementById('prodStock').value, 10);
    const subcategory = document.getElementById('prodSub').value.trim();
    const catId = document.getElementById('prodCat').value;

    if (!name || !code || !price || !stock) return;

    const categoriaDestino = inventario.find(c => c.id === catId);
    if (!categoriaDestino) return;

    const nuevoProducto = {
        codigo: code,
        nombre: name,
        precio: price,
        stock,
        subcategoria: subcategory || 'General'
    };

    categoriaDestino.productos.push(nuevoProducto);
    if (subcategory) {
        let sub = categoriaDestino.subcategorias.find(s => s.nombre.toLowerCase() === subcategory.toLowerCase());
        if (!sub) {
            sub = { id: `sub-${Date.now()}`, nombre: subcategory, productos: [] };
            categoriaDestino.subcategorias.push(sub);
        }
        sub.productos.push({ ...nuevoProducto, codigo: `${code}-sub` });
    }
    guardarInventario();
    categoriaSeleccionada = categoriaDestino;
    renderCategories();
    renderTable(categoriaSeleccionada);
    productoSeleccionado = nuevoProducto;
    renderDetailPanel();
    document.getElementById('productForm').reset();
}

// Abrir un resultado de búsqueda en su categoría y cargar el detalle correspondiente.
function selectProductFromSearch(codigo, catId) {
    const cat = inventario.find(c => c.id === catId);
    if (!cat) return;

    const producto = cat.productos.find(p => p.codigo === codigo) ||
        cat.subcategorias.flatMap(sub => sub.productos).find(p => p.codigo === codigo);

    if (!producto) return;

    categoriaSeleccionada = cat;
    productoSeleccionado = producto;
    renderCategories();
    renderTable(categoriaSeleccionada);
    renderDetailPanel();
    document.getElementById('globalSearch').value = '';
}

// Buscar coincidencias en productos principales y secundarios y presentar resultados en la tabla central.
document.getElementById('globalSearch').addEventListener('input', (e) => {
    const query = e.target.value.toLowerCase();
    if (!query) {
        if (categoriaSeleccionada) renderTable(categoriaSeleccionada);
        return;
    }

    let resultados = [];
    inventario.forEach(cat => {
        cat.productos.forEach(p => {
            if (p.nombre.toLowerCase().includes(query) || p.codigo.toLowerCase().includes(query)) {
                resultados.push({ ...p, catId: cat.id, catNombre: cat.nombre });
            }
        });
        cat.subcategorias.forEach(sub => {
            sub.productos.forEach(p => {
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
                    <tr class="product-row" onclick="selectProductFromSearch('${r.codigo}', '${r.catId}')">
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

// Descargar el estado jerárquico del inventario como archivo de respaldo.
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

// Cerrar el menú contextual al hacer clic fuera de él o pulsar Escape.
document.addEventListener('click', hideCategoryContextMenu);
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') hideCategoryContextMenu();
});

// Cargar los datos persistidos y poblar navegación, tabla y ficha inicial al evaluar el script.
(async () => {
    await cargarInventario();
    renderCategories();
    renderTable(categoriaSeleccionada || inventario[0]);
    renderDetailPanel();
})();

// Sincronizar el panel con cambios de inventario escritos desde otra pestaña del navegador.
window.addEventListener('storage', async (event) => {
    if (event.key === 'inventarioUpdated') {
        await cargarInventario();
        renderCategories();
        renderTable(categoriaSeleccionada || inventario[0]);
        renderDetailPanel();
    }
});

// Sincronizar también las actualizaciones originadas en otros módulos de esta misma pestaña.
window.addEventListener('inventario-updated', async () => {
    await cargarInventario();
    renderCategories();
    renderTable(categoriaSeleccionada || inventario[0]);
    renderDetailPanel();
});