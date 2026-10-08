/**
 * Lógica del panel de control de stock y categorías
 * Estado, persistencia y renderizado del panel de stock cargado por control_stock.php.
 * La misma estructura alimenta la API plana y la interfaz de categorías/subcategorías.
 */
// Datos de demostración iniciales: se reemplazan al leer persistencia disponible.
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

let categoriaSeleccionada = null;
let productoSeleccionado = null;
let categoryContextMenu = null;

// Aplanar categorías y subcategorías para el formato de la API y publicar cambios a otras vistas abiertas.
async function guardarInventario() {
    const flat = [];
    inventario.forEach(cat => {
        cat.productos.forEach(p => {
            flat.push({
                categoria: cat.nombre,
                nombre: p.nombre,
                codigo: p.codigo,
                precio: p.precio,
                cantidad: p.stock,
                total: p.precio * p.stock,
                subcategoria: p.subcategoria
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
                    total: p.precio * p.stock,
                    subcategoria: sub.nombre
                });
            });
        });
    });

    try {
        await API.saveInventario(flat);
        localStorage.setItem('inventarioCosmetica', JSON.stringify(inventario));
        localStorage.setItem('inventarioUpdated', Date.now().toString());
        window.dispatchEvent(new Event('inventario-updated'));
    } catch (e) {
        console.warn('Fallback a localStorage:', e);
        localStorage.setItem('inventarioCosmetica', JSON.stringify(inventario));
    }
}

// Reconstruir la jerarquía visual desde la respuesta API; si falla, recuperar el respaldo local o los datos iniciales.
async function cargarInventario() {
    try {
        const flat = await API.getInventario();
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
                const stockVal = parseInt(item.cantidad ?? item.stock ?? 0, 10);
                const precioVal = parseFloat(item.precio ?? 0);

                if (item.subcategoria) {
                    let sub = cat.subcategorias.find(s => s.nombre.toLowerCase() === item.subcategoria.toLowerCase());
                    if (!sub) {
                        sub = { id: `sub-${Date.now()}-${Math.random()}`, nombre: item.subcategoria, productos: [] };
                        cat.subcategorias.push(sub);
                    }
                    sub.productos.push({
                        codigo: item.codigo,
                        nombre: item.nombre,
                        precio: precioVal,
                        stock: stockVal,
                        subcategoria: item.subcategoria
                    });
                } else {
                    cat.productos.push({
                        codigo: item.codigo,
                        nombre: item.nombre,
                        precio: precioVal,
                        stock: stockVal,
                        subcategoria: item.subcategoria || undefined
                    });
                }
            });
            inventario = Array.from(map.values());
            categoriaSeleccionada = inventario[0] || null;
            return;
        }
    } catch (e) {
        console.warn('No se pudo conectar a la API, usando localStorage o fallback:', e);
    }

    const guardado = localStorage.getItem('inventarioCosmetica');
    if (guardado) {
        try {
            inventario = JSON.parse(guardado);
            categoriaSeleccionada = inventario[0] || null;
        } catch (e) {}
    } else {
        categoriaSeleccionada = inventario[0] || null;
    }
}

// Dibujar navegación de categorías y vincular selección y menú contextual de eliminación.
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
        btn.title = 'Click para abrir • Click derecho para eliminar';
        container.appendChild(btn);
    });
}

// Retirar del DOM el menú contextual anterior para evitar superposiciones y elementos obsoletos.
function hideCategoryContextMenu() {
    if (categoryContextMenu) {
        categoryContextMenu.remove();
        categoryContextMenu = null;
    }
}

// Crear el menú que permite eliminar la categoría elegida con el botón secundario.
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

// Confirmar la baja y sincronizar selección, tabla y detalle después de quitar la categoría y sus productos.
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
        const main = document.getElementById('mainContent');
        if (main) main.innerHTML = '<h3>No hay categorías disponibles.</h3>';
    }

    renderDetailPanel();
    hideCategoryContextMenu();
}

// Crear una categoría vacía desde el control lateral y abrirla para cargar productos.
function addCategory() {
    const input = document.getElementById('newCatName');
    if (!input) return;
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

// Actualizar la categoría activa y reiniciar el producto seleccionado antes de renderizar sus paneles.
function selectCategory(catId) {
    categoriaSeleccionada = inventario.find(c => c.id === catId);
    productoSeleccionado = null;
    renderCategories();
    renderTable(categoriaSeleccionada);
    renderDetailPanel();
}

// Renderizar formulario, productos y valorización total de la categoría activa.
function renderTable(categoria) {
    const content = document.getElementById('mainContent');
    if (!content || !categoria) return;

    let totalValorizado = 0;
    let allProducts = [...categoria.productos];
    (categoria.subcategorias || []).forEach(sub => {
        allProducts.push(...(sub.productos || []));
    });

    let rowsHTML = allProducts.map(p => {
        const totalProducto = p.precio * p.stock;
        totalValorizado += totalProducto;
        return `<tr class="product-row" onclick="selectProduct('${p.codigo}', '${categoria.id}')">
            <td>${p.nombre}</td>
            <td>${p.codigo}</td>
            <td>$${p.precio.toLocaleString('es-AR', { minimumFractionDigits: 2 })}</td>
            <td>${p.stock}</td>
            <td>$${totalProducto.toLocaleString('es-AR', { minimumFractionDigits: 2 })}</td>
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
                        <input id="prodStock" type="number" min="0" required>
                    </div>
                    <div>
                        <label for="prodSub">Subcategoría (opcional)</label>
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
                    <td>$${totalValorizado.toLocaleString('es-AR', { minimumFractionDigits: 2 })}</td>
                </tr>
            </tbody>
        </table>
    `;

    content.innerHTML = html;
}

// Resolver el producto seleccionado dentro de la categoría y mostrar sus datos en el panel lateral.
function selectProduct(codigo, catId) {
    const cat = inventario.find(c => c.id === catId);
    if (!cat) return;
    productoSeleccionado = cat.productos.find(p => p.codigo === codigo) ||
        (cat.subcategorias || []).flatMap(s => s.productos).find(p => p.codigo === codigo);
    renderDetailPanel();
}

// Mostrar los detalles del producto o la instrucción inicial si aún no hay selección.
function renderDetailPanel() {
    const panel = document.getElementById('detailPanel');
    if (!panel) return;

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
        <p><strong>Precio:</strong> $${productoSeleccionado.precio.toLocaleString('es-AR', { minimumFractionDigits: 2 })}</p>
        <p><strong>Stock:</strong> ${productoSeleccionado.stock}</p>
        <p><strong>Subcategoría:</strong> ${productoSeleccionado.subcategoria || 'Sin subcategoría'}</p>
        <h4>Subcategorías de la categoría</h4>
        <ul class="detail-list">
            ${(categoria?.subcategorias || []).map(sub => `<li>${sub.nombre}</li>`).join('') || '<li>No hay subcategorías</li>'}
        </ul>
    `;
}

// Validar datos del formulario, incorporar el producto en su categoría/subcategoría y persistir el inventario.
function addProduct() {
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
        subcategoria: subcategory || undefined
    };

    if (subcategory) {
        let sub = categoriaDestino.subcategorias.find(s => s.nombre.toLowerCase() === subcategory.toLowerCase());
        if (!sub) {
            sub = { id: `sub-${Date.now()}`, nombre: subcategory, productos: [] };
            categoriaDestino.subcategorias.push(sub);
        }
        sub.productos.push(nuevoProducto);
    } else {
        categoriaDestino.productos.push(nuevoProducto);
    }

    guardarInventario();
    categoriaSeleccionada = categoriaDestino;
    renderCategories();
    renderTable(categoriaSeleccionada);
    productoSeleccionado = nuevoProducto;
    renderDetailPanel();
    const form = document.getElementById('productForm');
    if (form) form.reset();
}

// Abrir desde los resultados de búsqueda el producto y su categoría en los paneles principales.
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
    // Buscar por nombre o código en productos principales y secundarios y reemplazar la tabla por coincidencias.
    const searchInput = document.getElementById('globalSearch');
    if (searchInput) searchInput.value = '';
}

const searchInput = document.getElementById('globalSearch');
if (searchInput) {
    searchInput.addEventListener('input', (e) => {
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
            (cat.subcategorias || []).forEach(sub => {
                (sub.productos || []).forEach(p => {
                    if (p.nombre.toLowerCase().includes(query) || p.codigo.toLowerCase().includes(query)) {
                        resultados.push({ ...p, catId: cat.id, catNombre: `${cat.nombre} > ${sub.nombre}` });
                    }
                });
            });
        });

        const content = document.getElementById('mainContent');
        if (content) {
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
                                <td>$${r.precio.toLocaleString('es-AR', { minimumFractionDigits: 2 })}</td>
                                <td>${r.stock}</td>
                                <td><small>${r.catNombre}</small></td>
                            </tr>
                        `).join('') || '<tr><td colspan="5">No se encontraron coincidencias.</td></tr>'}
                    </tbody>
                </table>
            `;
        }
    });
}

// Descargar una copia JSON de la jerarquía actual para respaldo o intercambio de datos.
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

// Cerrar el menú contextual con clic externo o Escape para mantener limpia la interacción del panel.
document.addEventListener('click', hideCategoryContextMenu);
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') hideCategoryContextMenu();
});

// Inicializar
// Cargar primero la fuente persistida y después dibujar todos los paneles iniciales.
(async () => {
    await cargarInventario();
    renderCategories();
    renderTable(categoriaSeleccionada || inventario[0]);
    renderDetailPanel();
})();

// Sincronizar pestañas distintas cuando localStorage anuncia una actualización de inventario.
window.addEventListener('storage', async (event) => {
    if (event.key === 'inventarioUpdated') {
        await cargarInventario();
        renderCategories();
        renderTable(categoriaSeleccionada || inventario[0]);
        renderDetailPanel();
    }
});

// Refrescar en la misma pestaña cuando ventas u otro módulo emite el evento de inventario.
window.addEventListener('inventario-updated', async () => {
    await cargarInventario();
    renderCategories();
    renderTable(categoriaSeleccionada || inventario[0]);
    renderDetailPanel();
});
