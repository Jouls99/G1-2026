let productosCargados = [];
let inventarioProductos = [];

// Elementos del DOM
const formulario = document.getElementById('formulario-producto');
const tablaProductos = document.getElementById('tabla-productos');
const totalMonto = document.getElementById('total-monto');
const btnRegistrar = document.getElementById('btn-registrar');
const btnCancelar = document.getElementById('btn-cancelar');
const mensageError = document.getElementById('mesage');
const inputNombre = document.getElementById('nombre');
const inputCodigo = document.getElementById('codigo');
const inputCantidad = document.getElementById('cantidad');
const inputPrecio = document.getElementById('precio');
const stockInfo = document.getElementById('stock-info');
const datalist = document.getElementById('productos-datalist');

function normalizarNumerosNoNegativos() {
    if (inputCantidad && Number(inputCantidad.value) < 0) {
        inputCantidad.value = 0;
    }
    if (inputPrecio && Number(inputPrecio.value) < 0) {
        inputPrecio.value = 0;
    }
}

inputCantidad.addEventListener('input', () => {
    if (inputCantidad.value === '') {
        inputCantidad.value = 1;
        return;
    }
    if (Number(inputCantidad.value) < 0) {
        inputCantidad.value = 0;
    }
    if (Number(inputCantidad.value) < 1) {
        inputCantidad.value = 1;
    }
});

inputPrecio.addEventListener('input', () => {
    if (inputPrecio.value === '') {
        inputPrecio.value = 0;
        return;
    }
    if (Number(inputPrecio.value) < 0) {
        inputPrecio.value = 0;
    }
    if (Number(inputPrecio.value) < 0.01) {
        inputPrecio.value = 0.01;
    }
});

// Cargar inventario desde la base de datos MySQL (vía API)
async function cargarInventarioParaVentas() {
    try {
        const response = await fetch('api/inventario.php', { cache: 'no-store' });
        if (response.ok) {
            inventarioProductos = await response.json();
            actualizarDatalist();
        }
    } catch (e) {
        console.error('Error cargando inventario desde la base de datos:', e);
    }
}

function actualizarDatalist() {
    if (!datalist) return;
    datalist.innerHTML = '';
    inventarioProductos.forEach(prod => {
        if (prod.cantidad > 0) {
            const option = document.createElement('option');
            option.value = prod.nombre;
            option.dataset.codigo = prod.codigo;
            option.dataset.precio = prod.precio;
            option.dataset.stock = prod.cantidad;
            datalist.appendChild(option);
        }
    });
}

// Escuchar cambios en el nombre del producto para autocompletar desde la DB
inputNombre.addEventListener('input', () => {
    const val = inputNombre.value.trim().toLowerCase();
    const prod = inventarioProductos.find(p => p.nombre.trim().toLowerCase() === val);
    if (prod) {
        inputCodigo.value = prod.codigo;
        inputPrecio.value = prod.precio;
        stockInfo.textContent = `(Stock disponible: ${prod.cantidad})`;
        stockInfo.style.display = 'inline';
        inputCantidad.max = prod.cantidad;
    } else {
        stockInfo.textContent = '';
        stockInfo.style.display = 'none';
        inputCantidad.removeAttribute('max');
    }
});

// Escuchar cambios en el código para autocompletar si se busca por código
inputCodigo.addEventListener('input', () => {
    const val = inputCodigo.value.trim().toLowerCase();
    if (!val) return;
    const prod = inventarioProductos.find(p => String(p.codigo).trim().toLowerCase() === val);
    if (prod) {
        inputNombre.value = prod.nombre;
        inputPrecio.value = prod.precio;
        stockInfo.textContent = `(Stock disponible: ${prod.cantidad})`;
        stockInfo.style.display = 'inline';
        inputCantidad.max = prod.cantidad;
    }
});

function mostrarMensaje(texto, tipo) {
    if (!mensageError) return;
    mensageError.textContent = texto;
    mensageError.className = `mensaje ${tipo}`;
    mensageError.style.display = 'block';
}

function limpiarMensaje() {
    if (!mensageError) return;
    mensageError.textContent = '';
    mensageError.style.display = 'none';
}

function actualizarTabla() {
    if (productosCargados.length === 0) {
        tablaProductos.innerHTML = `
            <tr>
                <td colspan="3" class="text-empty">
                    Ningún producto cargado. Usá el panel de la derecha para sumar artículos.
                </td>
            </tr>
        `;
        totalMonto.innerText = "$0.00";
        return;
    }

    tablaProductos.innerHTML = "";
    let sumaTotal = 0;

    productosCargados.forEach((prod, index) => {
        const fila = document.createElement('tr');
        
        fila.innerHTML = `
            <td class="td-codigo">${prod.codigo}</td>
            <td>${prod.nombre} × ${prod.cantidad}</td>
            <td class="td-precio" style="text-align: right;">$${(prod.precio * prod.cantidad).toFixed(2)}</td>
        `;
        
        tablaProductos.appendChild(fila);
        sumaTotal += prod.precio * prod.cantidad;
    });

    totalMonto.innerText = `$${sumaTotal.toFixed(2)}`;
}

// 1. EVENTO: Capturar el formulario y añadir a la tabla temporal de venta
formulario.addEventListener('submit', (e) => {
    e.preventDefault();

    const nombre = inputNombre.value.trim();
    const codigo = inputCodigo.value.trim();
    const cantidad = parseInt(inputCantidad.value, 10) || 1;
    const precio = parseFloat(inputPrecio.value);

    // Validar que el producto sea válido y tenga stock en la base de datos
    const item = inventarioProductos.find(p => 
        String(p.codigo).toLowerCase() === codigo.toLowerCase() || 
        p.nombre.toLowerCase() === nombre.toLowerCase()
    );

    if (!item) {
        mostrarMensaje('❌ Seleccioná un producto existente en la base de datos.', 'error');
        return;
    }

    // Calcular cantidad ya cargada en la venta actual
    const yaCargada = productosCargados
        .filter(p => String(p.codigo).toLowerCase() === String(item.codigo).toLowerCase())
        .reduce((sum, p) => sum + p.cantidad, 0);

    if (yaCargada + cantidad > item.cantidad) {
        mostrarMensaje(`❌ Stock insuficiente en base de datos. Disponible: ${item.cantidad}. Ya cargaste ${yaCargada} unidades.`, 'error');
        return;
    }

    if (!nombre || !codigo || Number.isNaN(precio) || precio <= 0 || cantidad <= 0 || cantidad < 0 || precio < 0) {
        mostrarMensaje('❌ Completá todos los campos con valores válidos. Cantidad y precio no pueden ser negativos.', 'error');
        return;
    }

    normalizarNumerosNoNegativos();

    const productoExistente = productosCargados.find(p => String(p.codigo).toLowerCase() === String(item.codigo).toLowerCase());
    if (productoExistente) {
        productoExistente.cantidad += cantidad;
    } else {
        productosCargados.push({
            id: item.ID_stock || item.id || Date.now(),
            ID_stock: item.ID_stock || item.id,
            nombre: item.nombre,
            codigo: item.codigo,
            cantidad,
            precio,
            categoria: item.categoria || 'Sin categoría'
        });
    }

    actualizarTabla();
    mostrarMensaje('✅ Producto cargado a la venta actual.', 'correcto');
    formulario.reset();
    stockInfo.textContent = '';
    stockInfo.style.display = 'none';
    inputNombre.focus();
});

// 2. EVENTO: Registrar Venta en la Base de Datos MySQL (tabla `facturacion` y actualización en `producto`)
btnRegistrar.addEventListener('click', async () => {
    if (productosCargados.length === 0) {
        mostrarMensaje('❌ Agregá al menos un producto antes de registrar la venta.', 'error');
        return;
    }

    limpiarMensaje();
    btnRegistrar.disabled = true;
    btnRegistrar.textContent = '⏳ Guardando en base de datos...';

    try {
        const montoTotalVenta = productosCargados.reduce((sum, producto) => sum + producto.precio * producto.cantidad, 0);

        const payloadVenta = {
            productos: productosCargados.map((producto) => ({ ...producto })),
            total: montoTotalVenta,
            dinero: montoTotalVenta,
            fecha: new Date().toISOString()
        };

        const ventasResponse = await fetch('api/ventas.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payloadVenta)
        });

        const dataVenta = await ventasResponse.json();

        if (!ventasResponse.ok || !dataVenta.ok) {
            throw new Error(dataVenta.message || 'Error al guardar la venta en MySQL.');
        }

        localStorage.setItem('inventarioUpdated', Date.now().toString());
        localStorage.setItem('ultimaVenta', JSON.stringify(payloadVenta));
        window.dispatchEvent(new Event('inventario-updated'));
        window.dispatchEvent(new Event('ventas-actualizadas'));

        mostrarMensaje(`✅ ¡Venta registrada exitosamente en MySQL! Factura generada y stock descontado (Monto: $${montoTotalVenta.toFixed(2)}).`, 'correcto');
        productosCargados = [];
        actualizarTabla();
        await cargarInventarioParaVentas();
    } catch (error) {
        mostrarMensaje(`❌ ${error.message}`, 'error');
    } finally {
        btnRegistrar.disabled = false;
        btnRegistrar.textContent = '✔ Registrar Venta';
    }
});

// 3. EVENTO: Cancelar / Vaciar Venta Actual
btnCancelar.addEventListener('click', () => {
    if (productosCargados.length === 0) {
        mostrarMensaje('ℹ️ No hay productos para cancelar.', 'correcto');
        return;
    }

    if (confirm('¿Estás seguro de que querés vaciar esta venta actual?')) {
        productosCargados = [];
        actualizarTabla();
        mostrarMensaje('🗑 Venta cancelada.', 'correcto');
    }
});

// Inicializar al cargar la página
cargarInventarioParaVentas();