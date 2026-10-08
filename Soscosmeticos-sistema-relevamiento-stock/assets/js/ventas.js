// El carrito es temporal; inventarioProductos conserva la última lectura para sugerencias y validaciones de stock.
let productosCargados = [];
let inventarioProductos = [];

// Elementos del DOM
// Referencias a los controles que renderizan el carrito o capturan productos y cantidades.
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
// Consultar el inventario del servidor al iniciar para poblar sugerencias con productos disponibles.
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

// Mostrar en el datalist solo artículos que aún tienen unidades para vender.
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
// Completar código, precio y límite de unidades al reconocer el nombre ingresado.
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

// Presentar avisos de validación y resultado junto al carrito con una clase visual según el tipo.
function mostrarMensaje(texto, tipo) {
    if (!mensageError) return;
    mensageError.textContent = texto;
    mensageError.className = `mensaje ${tipo}`;
    mensageError.style.display = 'block';
}

// Vaciar el aviso anterior antes de iniciar una operación que pueda generar un nuevo resultado.
function limpiarMensaje() {
    if (!mensageError) return;
    mensageError.textContent = '';
    mensageError.style.display = 'none';
}

// Volver a generar filas y total a partir del carrito en memoria, incluidos los estados vacíos.
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
// Validar que el artículo exista y que la suma pedida no exceda stock antes de consolidarlo en el carrito.
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
// Revalidar contra el inventario más reciente, descontar stock y guardar venta e inventario mediante la API.
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
            // Persistir primero el stock reducido para que el inventario disponible coincida con la venta.
            await API.saveInventario(nuevoInventario);

            const payloadVenta = {
                productos: productosCargados.map((producto) => ({ ...producto })),
                total: montoTotalVenta,
                dinero: montoTotalVenta,
                totalInventario: nuevoInventario.reduce((sum, item) => sum + (item.total || 0), 0),
                fecha: new Date().toISOString()
            };

            // Guardar venta en el servidor PHP
            // Agregar el comprobante al historial después de confirmar el cambio de stock.
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
// Vaciar únicamente el carrito sin persistir, ya que la venta aún no fue registrada.
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
// Recargar sugerencias si otra pestaña o módulo modificó el inventario.
window.addEventListener('storage', (e) => {
    if (e.key === 'inventarioUpdated') {
        cargarInventarioParaVentas();
    }
});

// Atender también cambios publicados mediante evento local en esta misma pestaña.
window.addEventListener('inventario-updated', () => {
    cargarInventarioParaVentas();
});

// Inicializar al cargar
// Consultar los productos al terminar de construir el DOM de la página de ventas.
document.addEventListener('DOMContentLoaded', () => {
    cargarInventarioParaVentas();
});
