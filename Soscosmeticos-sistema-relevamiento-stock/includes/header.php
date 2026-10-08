<?php
/**
 * Header común para todas las vistas PHP
 * Inicializa datos compartidos y emite el encabezado HTML que usan todas las vistas PHP.
 */
require_once __DIR__ . '/session.php';

$pageTitle = $pageTitle ?? 'Panel de Gestión';
$extraCss = $extraCss ?? [];
$activeTab = $activeTab ?? '';
?>
<!-- Estructura base, metadatos y estilos; los recursos variables se declaran desde cada página. -->
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Type" content="text/html;charset=UTF-8">
    <title><?= htmlspecialchars($pageTitle) ?> | Cosmética & Gestión</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/custom.css">
    <?php foreach ($extraCss as $cssFile): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($cssFile) ?>">
    <?php endforeach; ?>
</head>
<body class="app-body">
<!-- Ocultar navegación únicamente en pantallas que requieren un acceso sin sesión, como login.php. -->
<?php if (empty($hideNav)): ?>
    <?php include __DIR__ . '/nav.php'; ?>
<?php endif; ?>
<div class="main-wrapper">
