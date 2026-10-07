<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Si ya tiene sesión activa en PHP, redirigir a Ventas
redirectIfLoggedIn('venta.php');

$pageTitle = 'Acceso al Sistema - Cosmética & Stock';
$customCss = 'css/login.css';
require_once __DIR__ . '/includes/header.php';
?>

<header>
    <h1>💄 Sistema de Gestión Comercial</h1>
</header>

<main>
    <section class="loginss">
        <h2>Iniciar Sesión</h2>
        <?php if (($_GET['error'] ?? '') === 'cierre_jornada'): ?>
            <p role="alert" style="color:#b91c1c; margin-bottom:12px;">La sesión se cerró, pero no se pudo completar el cierre de jornada. Contactá a un administrador.</p>
        <?php endif; ?>
        <section class="ingreso">
            <form action="#" class="form-ingreso" id="Form_Ingreso">
                <span>Ingresá tu usuario y contraseña</span>
                <br>
                <input type="text" name="usernameIngreso" id="usernameIngreso" placeholder="Nombre de usuario" required autocomplete="username">
                <br>
                <input type="password" name="password" id="passwordIngreso" placeholder="Contraseña" required autocomplete="current-password">
                <br>
                <input type="submit" value="Ingresar" id="submit:ingreso">
            </form>
        </section>

    </section>
</main>

<script src="scrits/login.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
