<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

// Si ya tiene sesión activa en PHP, redirigir a Ventas
redirectIfLoggedIn('prueba2.php');

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

        <?php if (!isLoggedIn()): ?>
        <h3 id="title_ingreso" tabindex="0" role="button">¿No tenés cuenta? Registrate aquí</h3>

        <section class="registro" id="registroFormContainer">
            <form action="#" class="form_registro" id="FormRegistro">
                <span>Creá tu nombre de usuario</span>
                <br>
                <div>
                    <input type="text" name="username" id="username" placeholder="Nombre de usuario" required autocomplete="username">
                </div>
                <br>
                <span>Creá tu contraseña y confirmala</span>
                <br>
                <div>
                    <input type="password" name="password" id="passwordRegistro" placeholder="Contraseña" required autocomplete="new-password">
                </div>
                <br>
                <div>
                    <input type="password" name="confirmpassword" id="confirmpassword" placeholder="Confirmá tu contraseña" required autocomplete="new-password">
                </div>
                <br>
                <input type="submit" value="Crear Cuenta" id="submit">
            </form>
        </section>
        <?php endif; ?>
    </section>
</main>

<script src="scrits/login.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
