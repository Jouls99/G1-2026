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
                const response = await fetch('/api/inventario', { cache: 'no-store' });
                if (response.ok) {
                    inventarioProductos = await response.json();
                    actualizarDatalist();
                }
            } catch (e) {
                console.error('Error cargando inventario:', e);
            }
        }

        function actualizarDatalist() {
            if (!datalist) return;
            datalist.innerHTML = '';
            inventarioProductos.forEach(prod => {
                if (prod.cantidad > 0) {
                    const option = document.createElement('option');
                    option.value = prod.nombre;
                    datalist.appendChild(option);
                }
            });
        }

        // Escuchar cambios en el nombre del producto
        inputNombre.addEventListener('input', () => {
            const val = inputNombre.value.trim().toLowerCase();
            const prod = inventarioProductos.find(p => p.nombre.trim().toLowerCase() === val);
            if (prod) {
                inputCodigo.value = prod.codigo;
                inputPrecio.value = prod.precio;
                stockInfo.textContent = `(Stock: ${prod.cantidad})`;
                stockInfo.style.display = 'inline';
                inputCantidad.max = prod.cantidad;
            } else {
                inputCodigo.value = '';
                inputPrecio.value = '';
                stockInfo.textContent = '';
                stockInfo.style.display = 'none';
                inputCantidad.removeAttribute('max');
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

            productosCargados.forEach(prod => {
                const fila = document.createElement('tr');
                
                fila.innerHTML = `
                    <td class="td-codigo">${prod.codigo}</td>
                    <td>${prod.nombre} × ${prod.cantidad}</td>
                    <td class="td-precio">$${(prod.precio * prod.cantidad).toFixed(2)}</td>
                `;
                
                tablaProductos.appendChild(fila);
                sumaTotal += prod.precio * prod.cantidad;
            });

            totalMonto.innerText = `$${sumaTotal.toFixed(2)}`;
        }

        // 1. EVENTO: Capturar el formulario y añadir a la tabla
        formulario.addEventListener('submit', (e) => {
            e.preventDefault();

            const nombre = inputNombre.value.trim();
            const codigo = inputCodigo.value.trim();
            const cantidad = parseInt(inputCantidad.value, 10) || 1;
            const precio = parseFloat(inputPrecio.value);

            // Validar que el producto sea válido y tenga stock
            const item = inventarioProductos.find(p => p.codigo.toLowerCase() === codigo.toLowerCase());
            if (!item) {
                mostrarMensaje('❌ Seleccioná un producto válido de la lista.', 'error');
                return;
            }

            // Calcular cantidad ya cargada
            const yaCargada = productosCargados
                .filter(p => p.codigo.toLowerCase() === codigo.toLowerCase())
                .reduce((sum, p) => sum + p.cantidad, 0);

            if (yaCargada + cantidad > item.cantidad) {
                mostrarMensaje(`❌ Stock insuficiente. Disponible: ${item.cantidad}. Ya cargaste ${yaCargada} unidades.`, 'error');
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

        // 3. EVENTO: Registrar Venta (actualiza stock y guarda en JSON)
        btnRegistrar.addEventListener('click', async () => {
            if (productosCargados.length === 0) {
                mostrarMensaje('❌ Agregá al menos un producto antes de registrar.', 'error');
                return;
            }

            limpiarMensaje();

            try {
                const inventarioResponse = await fetch('/api/inventario', { cache: 'no-store' });
                if (!inventarioResponse.ok) throw new Error('No se pudo leer el inventario.');

                const inventarioActual = await inventarioResponse.json();
                if (!Array.isArray(inventarioActual)) throw new Error('El inventario no tiene un formato válido.');

                const nuevoInventario = [...inventarioActual];
                const montoTotalVenta = productosCargados.reduce((sum, producto) => sum + producto.precio * producto.cantidad, 0);

                for (const producto of productosCargados) {
                    const item = nuevoInventario.find((entry) => String(entry.codigo).toLowerCase() === String(producto.codigo).toLowerCase());
                    if (!item) {
                        throw new Error(`No existe el producto ${producto.nombre} en el inventario.`);
                    }
                    if (item.cantidad < producto.cantidad) {
                        throw new Error(`Stock insuficiente para ${producto.nombre}.`);
                    }

                    item.cantidad -= producto.cantidad;
                    item.total = item.precio * item.cantidad;
                }

                const saveResponse = await fetch('/api/inventario', {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(nuevoInventario)
                });

                if (!saveResponse.ok) throw new Error('No se pudo actualizar el inventario.');

                const payloadVenta = {
                    productos: productosCargados.map((producto) => ({ ...producto })),
                    total: montoTotalVenta,
                    dinero: montoTotalVenta,
                    totalInventario: nuevoInventario.reduce((sum, item) => sum + (item.total || 0), 0),
                    fecha: new Date().toISOString()
                };

                const ventasResponse = await fetch('/api/ventas', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payloadVenta)
                });

                if (!ventasResponse.ok) throw new Error('No se pudo guardar la venta.');

                localStorage.setItem('inventarioUpdated', Date.now().toString());
                localStorage.setItem('ultimaVenta', JSON.stringify(payloadVenta));
                window.dispatchEvent(new Event('inventario-updated'));
                window.dispatchEvent(new Event('ventas-actualizadas'));

                mostrarMensaje(`✅ Venta registrada correctamente. Se descontó el stock y se sumó $${montoTotalVenta.toFixed(2)} al informe.`, 'correcto');
                productosCargados = [];
                actualizarTabla();
                await cargarInventarioParaVentas();
            } catch (error) {
                mostrarMensaje(`❌ ${error.message}`, 'error');
            }
        });

        // 4. EVENTO: Eliminar / Cancelar Venta (Si no se concreta)
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

        // Inicializar al cargar
        cargarInventarioParaVentas();