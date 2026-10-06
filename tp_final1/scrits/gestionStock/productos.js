/**
 * Gestion de productos y categorias
 */

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
    if (categoriaFiltro === catId) categoriaFiltro = '';

    if (categoriaSeleccionada?.id === catId) {
        categoriaSeleccionada = inventario[0] || null;
        productoSeleccionado = null;
    }

    guardarInventario();
    renderCategories();
    renderCategoryFilter();

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
    renderCategoryFilter();
    renderCategorySelector();
    renderPriceCategoryFilter();
    renderTable(categoriaSeleccionada);
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
                subcategoria: subcategoria || null,
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
    if (!usuarioPuedeRegistrarStock) return;
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
        if (data.item?.ID_categoria) {
            catTarget.ID_categoria = data.item.ID_categoria;
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
        renderPriceTypeFilter();
        document.getElementById('prodCat').value = catId;
        renderCategories();
        renderTable(categoriaSeleccionada);
        alert(`✅ Producto "${name}" agregado exitosamente.`);
    } catch (err) {
        alert('❌ ' + err.message);
    }
}