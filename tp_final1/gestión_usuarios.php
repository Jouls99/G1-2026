<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Conserva el punto de entrada histórico y conduce solo a administradores al panel actual.
requireAdmin('venta.php');

header('Location: usuarios.php');
exit;