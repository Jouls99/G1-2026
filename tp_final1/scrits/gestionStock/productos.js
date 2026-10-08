/**
 * Gestion de productos y categorias
 */

// Retira de la página el menú emergente para evitar menús duplicados o persistentes.
function hideCategoryContextMenu() {
    if (categoryContextMenu) {
        categoryContextMenu.remove();
        categoryContextMenu = null;
    }
}

// Retira el menú de producto abierto cuando se cambia de objetivo o se hace clic fuera.
function hideProductContextMenu() {
    if (productContextMenu) {
        productContextMenu.remove();
        productContextMenu = null;
    }
}

// Construye el menú contextual de una categoría y limita sus acciones a usuarios administradores.
function showCategoryContextMenu(event, categoria) {
    if (!usuarioEsAdmin || !categoria.ID_categoria) return;
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
        pedirConfirmacionEliminar(targetProduct.codigo, targetCat?.id || catId, targetProduct.nombre, targetCat?.nombre || '');
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
        categoriaNombre: categoriaNombre || ''
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
        if (!res.ok || data.ok === false) {
            throw new Error(data.message || 'No se pudo eliminar el producto.');
        }
    } catch (e) {
        alert(`No se pudo eliminar el producto: ${e.message}`);
        return;
    }

    if (productoSeleccionado && String(productoSeleccionado.codigo).toLowerCase() === String(codigo).toLowerCase()) {
        productoSeleccionado = null;
    }

    await cargarInventario();
    categoriaSeleccionada = inventario.find(cat => cat.id === catId) || inventario[0] || null;
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

// Mueve primero productos y subcategorías a una categoría destino antes de borrar la seleccionada.
async function deleteCategory(catId) {
    if (!usuarioEsAdmin) return;
    const categoria = inventario.find(c => c.id === catId);
    if (!categoria) {
        hideCategoryContextMenu();
        return;
    }

    const destinationCategories = inventario.filter(category => category.ID_categoria !== categoria.ID_categoria);
    if (!destinationCategories.length) {
        alert('Debe existir otra categoría para reasignar los productos antes de eliminar esta.');
        hideCategoryContextMenu();
        return;
    }
    const destinationList = destinationCategories.map(category => category.nombre).join('\n');
    const destinationName = prompt(
        `Escribí el nombre de la categoría destino para mover los productos de "${categoria.nombre}":\n${destinationList}`
    );
    if (destinationName === null) {
        hideCategoryContextMenu();
        return;
    }
    const destination = destinationCategories.find(category =>
        category.nombre.toLocaleLowerCase('es') === destinationName.trim().toLocaleLowerCase('es')
    );
    if (!destination) {
        alert('Ingresá el nombre exacto de una categoría disponible.');
        hideCategoryContextMenu();
        return;
    }
    const confirmDelete = confirm(`¿Eliminar "${categoria.nombre}" y reasignar sus productos y subcategorías a "${destination.nombre}"?`);
    if (!confirmDelete) {
        hideCategoryContextMenu();
        return;
    }

    const selectedCategoryId = categoriaSeleccionada?.id;
    try {
        const res = await fetch(`api/inventario.php?action=delete_category&id=${encodeURIComponent(categoria.ID_categoria)}`, {
            method: 'DELETE',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ destination_id: destination.ID_categoria })
        });
        const data = await res.json();
        if (!res.ok || data.ok === false) {
            throw new Error(data.message || 'No se pudo eliminar la categoría.');
        }
    } catch (error) {
        alert(error.message);
        hideCategoryContextMenu();
        return;
    }

    if (categoriaFiltro === catId) categoriaFiltro = '';
    if (selectedCategoryId === catId) productoSeleccionado = null;
    await cargarInventario();
    categoriaSeleccionada = inventario.find(cat => cat.id === selectedCategoryId) || inventario[0] || null;
    renderCategories();
    renderCategoryFilter();
    renderCategorySelector();
    renderPriceCategoryFilter();

    if (categoriaSeleccionada) {
        renderTable(categoriaSeleccionada);
    } else {
        document.getElementById('mainContent').innerHTML = '<h3>No hay categorías disponibles.</h3>';
    }

    renderDetailPanel();
    hideCategoryContextMenu();
}

// Crea la categoría en MySQL y recarga las opciones vinculadas al catálogo.
async function addCategory() {
    if (!usuarioEsAdmin) return;
    const input = document.getElementById('newCatName');
    const nombre = input.value.trim();
    if (!nombre) return;

    try {
        const res = await fetch('api/inventario.php?action=create_category', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ nombre })
        });
        const data = await res.json();
        if (!res.ok || data.ok !== true || !data.categoria) {
            throw new Error(data.message || 'No se pudo crear la categoría.');
        }
        input.value = '';
        await cargarInventario();
        categoriaSeleccionada = inventario.find(cat => cat.ID_categoria === Number(data.categoria.ID_categoria)) || inventario[0] || null;
        renderCategories();
        renderCategoryFilter();
        renderCategorySelector();
        renderPriceCategoryFilter();
        if (categoriaSeleccionada) renderTable(categoriaSeleccionada);
        renderDetailPanel();
    } catch (error) {
        alert(error.message);
    }
}

// Inserta en el panel de detalle un formulario temporal con los datos actuales del producto.
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
                <input id="editProdName" required pattern="[A-Za-z]+(?: [A-Za-z]+)*" title="Usá solo letras A-Z y espacios entre palabras.">
            </div>
            <div>
                <label for="editProdBrand">Marca</label>
                <input id="editProdBrand">
            </div>
            <div>
                <label for="editProdSubName">Nombre secundario (opcional)</label>
                <input id="editProdSubName">
            </div>
            <div>
                <label for="editProdCode">Código</label>
                <input id="editProdCode" readonly>
            </div>
            <div>
                <label for="editProdPrice">Precio</label>
                <input id="editProdPrice" type="text" inputmode="decimal" pattern="[0-9]+(?:\\.[0-9]{1,2})?" title="Usá números y, opcionalmente, punto con hasta dos decimales." required>
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
    document.getElementById('editProdBrand').value = productoSeleccionado.marca || '';
    document.getElementById('editProdSubName').value = productoSeleccionado.sub_nombre || '';
    document.getElementById('editProdCode').value = productoSeleccionado.codigo;
    document.getElementById('editProdPrice').value = productoSeleccionado.precio;
    document.getElementById('editProdStock').value = productoSeleccionado.stock;
    document.getElementById('editProdSub').value = productoSeleccionado.subcategoria || '';
    form.addEventListener('submit', guardarEdicionProducto);
}

function cancelarEdicionProducto() {
    document.getElementById('editProductForm')?.remove();
}

// Valida y guarda los campos editables, sincronizando después la tabla y el detalle visibles.
async function guardarEdicionProducto(event) {
    if (!usuarioEsAdmin || !productoSeleccionado) return;
    event.preventDefault();

    const nombre = document.getElementById('editProdName').value.trim();
    const marca = document.getElementById('editProdBrand').value.trim();
    const subNombre = document.getElementById('editProdSubName').value.trim();
    const precioRaw = document.getElementById('editProdPrice').value.trim();
    const precio = parseFloat(precioRaw);
    const stock = parseInt(document.getElementById('editProdStock').value, 10);
    const subcategoria = document.getElementById('editProdSub').value.trim();

    if (!/^[A-Za-z]+(?: [A-Za-z]+)*$/.test(nombre)) {
        alert('El nombre solo puede contener letras de la A a la Z y espacios entre palabras.');
        return;
    }
    if (!/^[0-9]+(?:\.[0-9]{1,2})?$/.test(precioRaw)) {
        alert('El precio debe contener números y, opcionalmente, un punto decimal con hasta dos decimales.');
        return;
    }
    if (Number.isNaN(precio) || Number.isNaN(stock) || precio < 0 || stock < 0) {
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
                marca,
                sub_nombre: subNombre,
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
    productoSeleccionado.marca = marca || undefined;
    productoSeleccionado.sub_nombre = subNombre || undefined;
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

    renderTable(categoriaSeleccionada);
    renderDetailPanel();
    alert(`✅ El producto "${nombre}" fue actualizado correctamente.`);
}

// Valida los campos y registra un producto nuevo ligado a la categoría elegida.
async function addProduct() {
    if (!usuarioPuedeRegistrarStock) return;
    const name = document.getElementById('prodName').value.trim();
    const brand = document.getElementById('prodBrand').value.trim();
    const subName = document.getElementById('prodSubName').value.trim();
    const code = document.getElementById('prodCode').value.trim();
    const priceRaw = document.getElementById('prodPrice').value.trim();
    const price = parseFloat(priceRaw);
    const stock = parseInt(document.getElementById('prodStock').value, 10);
    const subcategory = document.getElementById('prodSub').value.trim();
    const catId = document.getElementById('prodCat').value;

    if (!/^[A-Za-z]+(?: [A-Za-z]+)*$/.test(name)) {
        alert('El nombre solo puede contener letras de la A a la Z y espacios entre palabras.');
        return;
    }
    if (!/^[0-9]+$/.test(code)) {
        alert('El código solo puede contener números del 0 al 9.');
        return;
    }
    if (!/^[0-9]+(?:\.[0-9]{1,2})?$/.test(priceRaw)) {
        alert('El precio debe contener números y, opcionalmente, un punto decimal con hasta dos decimales.');
        return;
    }
    if (isNaN(price) || isNaN(stock) || price < 0 || stock < 0) {
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
                marca: brand,
                sub_nombre: subName,
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
            marca: brand || undefined,
            sub_nombre: subName || undefined,
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