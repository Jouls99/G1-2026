<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireAuth('registroinicio.php');

if (!isSuperAdmin()) {
    header('Location: prueba2.php?error=unauthorized');
    exit;
}

header('Location: usuarios.php');
exit;
/* El Super Admin tiene acceso exclusivo a la sección de gestión de usuarios y monitoreo de login de usuarios. */