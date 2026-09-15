<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireAuth('registroinicio.php');

if (!isAdmin()) {
    header('Location: prueba2.php?error=unauthorized');
    exit;
}

header('Location: usuarios.php');
exit;
