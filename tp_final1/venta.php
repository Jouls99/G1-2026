<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/database/conexion.php';

// Proteger vista: requiere sesión activa
requireAuth('registroinicio.php');

$pageTitle = 'Panel de Gestión de Ventas (POS)';
$customCss = 'css/prueba2.css';
$activePage = 'ventas';
$puedeVerEstadoBase = isSuperAdmin();

// Cargar productos directamente desde MySQL (tabla producto y categoria)
$productosDB = [];
$dbConectada = false;
$errorDB = null;

try {
    $db = getDBConnection();
    if ($db !== null) {
        $stmt = $db->query("
            SELECT 
                p.ID_stock,
                p.nombre,
                COALESCE(p.codigo, CONCAT('COD-', p.ID_stock)) AS codigo,
                COALESCE(p.cantTotal, 0) AS cantidad,
                COALESCE(p.cantVendida, 0) AS cantVendida,
                CAST(COALESCE(p.precio, 0) AS DECIMAL(10,2)) AS precio,
                COALESCE(c.nombre, 'General') AS categoria,
                COALESCE(p.fase, 'habilitado') AS fase
            FROM `producto` p
            LEFT JOIN `categoria` c ON p.ID_categoria = c.ID_categoria
            WHERE COALESCE(p.fase, 'habilitado') = 'habilitado'
            ORDER BY p.nombre ASC
        ");

        if (method_exists($stmt, 'fetchAll')) {
            $productosDB = $stmt->fetchAll();
        } elseif (method_exists($stmt, 'fetch_assoc')) {
            $productosDB = [];
            while ($row = $stmt->fetch_assoc()) {
                $productosDB[] = $row;
            }
        }
        $dbConectada = true;
    } else {
        throw new RuntimeException('MySQL no está en ejecución. Modo sin conexión activo.');
    }
} catch (Exception $e) {
    $errorDB = $e->getMessage();
    $jsonPath = __DIR__ . '/data/inventario.json';
    if (file_exists($jsonPath)) {
        $json = json_decode(file_get_contents($jsonPath) ?: '[]', true);
        if (is_array($json)) {
            $habilitadosJson = array_filter($json, function($item) {
                return ($item['fase'] ?? 'habilitado') !== 'deshabilitado';
            });
            $productosDB = array_map(function($item) {
                return [
                    'ID_stock'    => $item['id'] ?? $item['ID_stock'] ?? 0,
                    'nombre'      => $item['nombre'] ?? '',
                    'codigo'      => $item['codigo'] ?? '',
                    'cantidad'    => $item['cantidad'] ?? $item['stock'] ?? 0,
                    'cantVendida' => $item['cantVendida'] ?? 0,
                    'precio'      => $item['precio'] ?? 0,
                    'categoria'   => $item['categoria'] ?? 'General',
                    'fase'        => $item['fase'] ?? 'habilitado'
                ];
            }, array_values($habilitadosJson));
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<header style="margin-top: 15px; display: flex; flex-direction: column; align-items: center; gap: 6px;">
    <h1>🛒 Panel de Gestión de Ventas</h1>
    <?php if ($puedeVerEstadoBase): ?>
        <div style="font-size: 0.9rem; padding: 4px 12px; border-radius: 20px; background: <?= $dbConectada ? '#dcfce7; color: #166534; border: 1px solid #86efac;' : '#fee2e2; color: #991b1b; border: 1px solid #fca5a5;' ?>">
            <?= $dbConectada ? '🟢 Conectado a Base de Datos MySQL (<code>sos_cosmeticos</code>)' : '🔴 Error de Conexión: ' . htmlspecialchars($errorDB ?? 'Desconocido') ?>
        </div>
    <?php endif; ?>
</header>

<main>
    <div class="container">
        <!-- Panel Izquierdo: Resumen de Venta Actual -->
        <div class="panel panel-izquierdo">
            <div>
                <h2>🛍️ Productos en la Venta Actual</h2>
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
                    <button type="button" class="btn-cancelar" id="btn-cancelar">❌ Cancelar Venta</button>
                </div>
                <div class="mesage" style="margin-top: 12px;">
                    <i id="mesage"></i>
                </div>
            </div>
        </div>

        <!-- Panel Derecho: Carga de Artículos -->
        <div class="panel" style="height: fit-content;">
            <h2>📥 Ingreso de Artículos</h2>
            <form id="formulario-producto" method="POST">
                <div class="form-group">
                    <label for="nombre">Nombre del Producto</label>
                    <input type="text" id="nombre" name="nombre" list="productos-datalist" placeholder="Buscar o seleccionar..."
                        required autocomplete="off">
                    <datalist id="productos-datalist">
                        <?php foreach ($productosDB as $p): ?>
                            <?php if ((int)$p['cantidad'] > 0): ?>
                                <option value="<?= htmlspecialchars((string)$p['nombre']) ?>" data-codigo="<?= htmlspecialchars((string)$p['codigo']) ?>" data-precio="<?= htmlspecialchars((string)$p['precio']) ?>" data-stock="<?= (int)$p['cantidad'] ?>"></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label for="codigo">Código / N°</label>
                    <input type="text" id="codigo" name="codigo" placeholder="Código de producto" required>
                </div>
                <div class="form-group">
                    <label for="cantidad">
                        Cantidad <span id="stock-info" style="font-size: 0.85em; font-weight: bold; color: #ae3c1d; margin-left: 8px;"></span>
                    </label>
                    <input type="number" id="cantidad" name="cantidad" min="0,0" max="9999" value="0,0" required oninput="this.value = this.value < 0 ? 0 : this.value;">
                </div>
                <div class="form-group">
                    <label for="precio">Precio de Venta</label>
                    <input type="number" id="precio" name="precio" min="0.01" step="0.01" placeholder="$0.00" required oninput="this.value = this.value < 0 ? 0 : this.value;">
                </div>
                <button type="submit" class="btn-cargar">+ Cargar a la Tabla</button>
            </form>

            <div class="extra" style="display: flex; gap: 12px; margin-top: 16px; flex-wrap: wrap;">
                <a href="ControlStock.php" class="ver-stock" style="flex:1; min-width:130px; padding: 8px; border: 1px #4f46e5 solid; border-radius: 8px; text-align: center; color: #4f46e5; text-decoration: none; font-weight: bold;">
                    📦 Ver Stock
                </a>
                <a href="informe.php" class="gen-informe" style="flex:1; min-width:130px; padding: 8px; border: 1px #7c2d92 solid; border-radius: 8px; text-align: center; color: #7c2d92; text-decoration: none; font-weight: bold;">
                    📊 Ver Informe
                </a>
            </div>
        </div>
    </div>
</main>

<script src="scrits/preuba2.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>