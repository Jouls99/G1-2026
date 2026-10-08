<?php
// Protege la pantalla de ventas y selecciona los estilos y el controlador que la inicializan.
require_once __DIR__ . '/includes/session.php';
require_auth('login.php');

$pageTitle = 'Panel de Gestión de Ventas';
$activeTab = 'ventas';
$extraCss = ['css/prueba2.css'];
$extraJs = ['assets/js/ventas.js'];

require_once __DIR__ . '/includes/header.php';
?>

<!-- Vista de venta: la tabla y el total reflejan el carrito temporal hasta confirmar o cancelar. -->
<main class="container-fluid" style="padding: 24px; max-width: 1300px; margin: 0 auto; width: 100%;">
    <header style="margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #e2e8f0;">
        <h1 style="color: #2d112c; font-size: 26px; font-weight: 700;">💄 Panel de Gestión de Ventas</h1>
    </header>

    <!-- El panel izquierdo presenta el carrito; el derecho captura productos del inventario y enlaza vistas complementarias. -->
    <div class="container">
        <!-- Panel Izquierdo: Productos en la Venta -->
        <div class="panel panel-izquierdo">
            <div>
                <h2>🛒 Productos en la Venta Actual</h2>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre Producto</th>
                                <th style="text-align: right;">Precio</th>
                            </tr>
                        </thead>
                        <tbody id="tabla-productos">
                            <tr>
                                <td colspan="3" class="text-empty">
                                    Ningún producto cargado. Usá el panel de la derecha para sumar artículos.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel-footer">
                <div class="total-container">
                    <span class="total-label">TOTAL:</span>
                    <span class="total-monto" id="total-monto">$0.00</span>
                </div>
                <div class="acciones-venta">
                    <button type="button" class="btn-registrar" id="btn-registrar">✔ Registrar Venta</button>
                    <button type="button" class="btn-cancelar" id="btn-cancelar">❌ Eliminar / Cancelar</button>
                </div>
                <div class="mesage" style="margin-top: 10px;">
                    <i id="mesage"></i>
                </div>
            </div>
        </div>

        <!-- Panel Derecho: Ingreso de Artículos -->
        <div class="panel" style="height: fit-content;">
            <h2>📥 Ingreso de Artículos</h2>
            <form id="formulario-producto">
                <div class="form-group">
                    <label for="nombre">Nombre del Producto</label>
                    <input type="text" id="nombre" list="productos-datalist" placeholder="Buscar o seleccionar..." required autocomplete="off">
                    <datalist id="productos-datalist"></datalist>
                </div>
                <div class="form-group">
                    <label for="codigo">Código / N°</label>
                    <input type="text" id="codigo" placeholder="Código de barra" required>
                </div>
                <div class="form-group">
                    <label for="cantidad">
                        Cantidad 
                        <span id="stock-info" style="font-size: 0.85em; font-weight: bold; color: #ae3c1d; margin-left: 8px;"></span>
                    </label>
                    <input type="number" id="cantidad" min="1" value="1" required>
                </div>
                <div class="form-group">
                    <label for="precio">Precio de Venta</label>
                    <input type="number" id="precio" step="0.01" placeholder="$0.00" required>
                </div>
                <button type="submit" class="btn-cargar">+ Cargar a la Tabla</button>
            </form>

            <div class="extra" style="display: flex; gap: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0; margin-top: 15px;">
                <a href="control_stock.php" class="ver-stock" style="flex: 1; padding: 10px; border: 1px #d84374 solid; border-radius: 8px; text-align: center; color: #d84374; text-decoration: none; font-weight: 600; transition: 0.2s;">
                    📦 Ver Stock
                </a>
                <a href="informe.php" class="gen-informe" style="flex: 1; padding: 10px; border: 1px #4a1942 solid; border-radius: 8px; text-align: center; color: #4a1942; text-decoration: none; font-weight: 600; transition: 0.2s;">
                    📊 Ver Informe
                </a>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
