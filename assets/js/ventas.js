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

// Cargar inventario al iniciar
async function cargarInventarioParaVentas() {
    try {
        const data = await API.getInventario();
        if (Array.isArray(data)) {
            inventarioProductos = data;
            actualizarDatalist();
        }
    } catch (e) {
        console.error('Error cargando inventario:', e);
        mostrarMensaje('⚠️ No se pudo sincronizar el inventario desde el servidor.', 'error');
    }
}

function actualizarDatalist() {
    if (!datalist) return;
    datalist.innerHTML = '';
    inventarioProductos.forEach(prod => {
        const cant = parseInt(prod.cantidad ?? prod.stock ?? 0, 10);
        if (cant > 0) {
            const option = document.createElement('option');
            option.value = prod.nombre;
            datalist.appendChild(option);
        }
    });
}

// Escuchar cambios en el nombre del producto
if (inputNombre) {
    inputNombre.addEventListener('input', () => {
        const val = inputNombre.value.trim().toLowerCase();
        const prod = inventarioProductos.find(p => p.nombre && p.nombre.trim().toLowerCase() === val);
        if (prod) {
            inputCodigo.value = prod.codigo || '';
            inputPrecio.value = prod.precio || '';
            const cant = parseInt(prod.cantidad ?? prod.stock ?? 0, 10);
            stockInfo.textContent = `(Stock: ${cant})`;
            stockInfo.style.display = 'inline';
            inputCantidad.max = cant;
        } else {
            inputCodigo.value = '';
            inputPrecio.value = '';
            stockInfo.textContent = '';
            stockInfo.style.display = 'none';
            inputCantidad.removeAttribute('max');
        }
    });
}

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
    if (!tablaProductos || !totalMonto) return;

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
        const subtotal = prod.precio * prod.cantidad;
        sumaTotal += subtotal;
        
        fila.innerHTML = `
            <td class="td-codigo">${prod.codigo}</td>
            <td>${prod.nombre} × ${prod.cantidad}</td>
            <td class="td-precio">$${subtotal.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
        `;
        
        tablaProductos.appendChild(fila);
    });

    totalMonto.innerText = `$${sumaTotal.toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

// Capturar el formulario y añadir a la tabla
if (formulario) {
    formulario.addEventListener('submit', (e) => {
        e.preventDefault();

        const nombre = inputNombre.value.trim();
        const codigo = inputCodigo.value.trim();
        const cantidad = parseInt(inputCantidad.value, 10) || 1;
        const precio = parseFloat(inputPrecio.value);

        // Validar que el producto sea válido y tenga stock
        const item = inventarioProductos.find(p => p.codigo && p.codigo.toLowerCase() === codigo.toLowerCase());
        if (!item) {
            mostrarMensaje('❌ Seleccioná un producto válido de la lista.', 'error');
            return;
        }

        const stockDisponible = parseInt(item.cantidad ?? item.stock ?? 0, 10);

        // Calcular cantidad ya cargada
        const yaCargada = productosCargados
            .filter(p => p.codigo.toLowerCase() === codigo.toLowerCase())
            .reduce((sum, p) => sum + p.cantidad, 0);

        if (yaCargada + cantidad > stockDisponible) {
            mostrarMensaje(`❌ Stock insuficiente. Disponible: ${stockDisponible}. Ya cargaste ${yaCargada} unidades.`, 'error');
            return;
        }

        if (!nombre || !codigo || Number.isNaN(precio) || precio <= 0 || cantidad <= 0) {
            mostrarMensaje('❌ Completá todos los campos con valores válidos.', 'error');
            return;
        }

        const productoExistente = productosCargados.find(p => p.codigo.toLowerCase() === codigo.toLowerCase());
        if (productoExistente) {
            productoExistente.cantidad += cantidad;
        } else {
            productosCargados.push({
                id: Date.now(),
                nombre,
                codigo,
                cantidad,
                precio,
                categoria: item.categoria || 'Sin categoría'
            });
        }

        actualizarTabla();
        mostrarMensaje('✅ Producto cargado a la venta.', 'correcto');
        formulario.reset();
        stockInfo.textContent = '';
        stockInfo.style.display = 'none';
        inputNombre.focus();
    });
}

// Registrar Venta (actualiza stock y guarda en PHP)
if (btnRegistrar) {
    btnRegistrar.addEventListener('click', async () => {
        if (productosCargados.length === 0) {
            mostrarMensaje('❌ Agregá al menos un producto antes de registrar.', 'error');
            return;
        }

        limpiarMensaje();

        try {
            const inventarioActual = await API.getInventario();
            if (!Array.isArray(inventarioActual)) throw new Error('El inventario no tiene un formato válido.');

            const nuevoInventario = [...inventarioActual];
            const montoTotalVenta = productosCargados.reduce((sum, producto) => sum + producto.precio * producto.cantidad, 0);

            for (const producto of productosCargados) {
                const item = nuevoInventario.find((entry) => String(entry.codigo).toLowerCase() === String(producto.codigo).toLowerCase());
                if (!item) {
                    throw new Error(`No existe el producto ${producto.nombre} en el inventario.`);
                }
                const stockItem = parseInt(item.cantidad ?? item.stock ?? 0, 10);
                if (stockItem < producto.cantidad) {
                    throw new Error(`Stock insuficiente para ${producto.nombre}.`);
                }

                item.cantidad = stockItem - producto.cantidad;
                item.total = item.precio * item.cantidad;
            }

            // Actualizar inventario en el servidor PHP
            await API.saveInventario(nuevoInventario);

            const payloadVenta = {
                productos: productosCargados.map((producto) => ({ ...producto })),
                total: montoTotalVenta,
                dinero: montoTotalVenta,
                totalInventario: nuevoInventario.reduce((sum, item) => sum + (item.total || 0), 0),
                fecha: new Date().toISOString()
            };

            // Guardar venta en el servidor PHP
            await API.addVenta(payloadVenta);

            localStorage.setItem('inventarioUpdated', Date.now().toString());
            localStorage.setItem('ultimaVenta', JSON.stringify(payloadVenta));
            window.dispatchEvent(new Event('inventario-updated'));
            window.dispatchEvent(new Event('ventas-actualizadas'));

            mostrarMensaje(`✅ Venta registrada correctamente. Se descontó el stock y se sumó $${montoTotalVenta.toLocaleString('es-AR', { minimumFractionDigits: 2 })} al historial.`, 'correcto');
            productosCargados = [];
            actualizarTabla();
            await cargarInventarioParaVentas();
        } catch (error) {
            console.error(error);
            mostrarMensaje(`❌ ${error.message}`, 'error');
        }
    });
}

// Eliminar / Cancelar Venta
if (btnCancelar) {
    btnCancelar.addEventListener('click', () => {
        if (productosCargados.length === 0) {
            mostrarMensaje('ℹ️ No hay productos para cancelar.', 'correcto');
            return;
        }

        if (confirm('¿Estás seguro de que querés eliminar y vaciar esta venta? No se guardará ningún dato.')) {
            productosCargados = [];
            actualizarTabla();
            mostrarMensaje('🗑 Venta cancelada.', 'correcto');
        }
    });
}

// Escuchar cambios de inventario desde otras pestañas
window.addEventListener('storage', (e) => {
    if (e.key === 'inventarioUpdated') {
        cargarInventarioParaVentas();
    }
});

window.addEventListener('inventario-updated', () => {
    cargarInventarioParaVentas();
});

// Inicializar al cargar
document.addEventListener('DOMContentLoaded', () => {
    cargarInventarioParaVentas();
});
