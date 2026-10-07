<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireAdmin('venta.php');

header('Location: usuarios.php');
exit;