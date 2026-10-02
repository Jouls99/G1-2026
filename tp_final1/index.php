<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Si el usuario ya inició sesión, ir a Ventas; de lo contrario ir a Login
if (isLoggedIn()) {
    header('Location: venta.php');
} else {
    header('Location: registroinicio.php');
}
exit;
