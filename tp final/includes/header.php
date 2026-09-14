<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Plantilla de cabecera HTML común para el sistema.
 * @var string $pageTitle Título de la pestaña
 * @var string|null $customCss Hoja de estilos específica de la vista
 */
$pageTitle = $pageTitle ?? 'Sistema de Gestión';
$customCss = $customCss ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html;charset=UTF-8">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <!-- Estilos base y navegación -->
    <link rel="stylesheet" href="css/navbar.css" type="text/css">
    <?php if ($customCss): ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($customCss, ENT_QUOTES, 'UTF-8') ?>" type="text/css">
    <?php endif; ?>
</head>
<body>
