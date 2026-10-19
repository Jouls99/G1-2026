<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireAuth('registroinicio.php');

$pageTitle = 'Cargando, espere un momento...';
$customCss = 'css/pantallaCarga.css';
$activePage = 'pantallaCarga';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/pantallaCarga.php';
require_once __DIR__ . '/includes/footer.php';
