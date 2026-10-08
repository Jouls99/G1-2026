<?php
declare(strict_types=1);

/**
 * Helper de inclusión para la conexión a Base de Datos
 */
// Delega en el inicializador central para compartir la misma conexión PDO en la aplicación.
require_once dirname(__DIR__) . '/database/conexion.php';
