<?php
// Si la sesión ya existe, evita mostrar el formulario y vuelve al panel de ventas.
require_once __DIR__ . '/includes/session.php';

// Si ya está autenticado, redirigir al panel principal
if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Iniciar Sesión / Registro';
$activeTab = 'login';
$extraCss = ['css/login.css'];
$extraJs = ['assets/js/login.js'];
$hideNav = true;

require_once __DIR__ . '/includes/header.php';
?>

<!-- Formulario de acceso y registro; login.js valida los datos y los envía al backend. -->
<div class="login-page-wrapper" style="display: flex; justify-content: center; align-items: center; min-height: 80vh; padding: 20px;">
    <main style="max-width: 440px; width: 100%;">
        <section class="loginss" style="background: white; border-radius: 16px; padding: 30px; box-shadow: 0 8px 30px rgba(0,0,0,0.08); border: 1px solid #f1f5f9;">
            <div style="text-align: center; margin-bottom: 24px;">
                <span style="font-size: 2.5rem;">💄</span>
                <h2 style="color: #2d112c; font-size: 1.5rem; margin-top: 10px; font-weight: 700;">Iniciar Sesión</h2>
                <p style="color: #64748b; font-size: 0.9rem;">Acceso al panel de ventas e inventario</p>
            </div>

            <!-- Inicio de sesión: usuario y contraseña se leen desde estos campos al enviar. -->
            <section class="ingreso">
                <form class="form-ingreso" id="Form_Ingreso">
                    <div style="margin-bottom: 14px;">
                        <label for="usernameIngreso" style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.9rem; color: #334155;">Usuario:</label>
                        <input type="text" name="usernameIngreso" id="usernameIngreso" placeholder="Ingresá tu usuario" required style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.95rem;">
                    </div>
                    <div style="margin-bottom: 20px;">
                        <label for="passwordIngreso" style="display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.9rem; color: #334155;">Contraseña:</label>
                        <input type="password" name="password" id="passwordIngreso" placeholder="••••••••" required style="width: 100%; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.95rem;">
                    </div>
                    <button type="submit" id="submit:ingreso" style="width: 100%; padding: 12px; background: #e0316d; color: white; border: none; border-radius: 8px; font-weight: 600; font-size: 1rem; cursor: pointer; transition: 0.2s;">
                        Ingresar al Sistema
                    </button>
                </form>
            </section>

            <div style="text-align: center; margin: 20px 0 10px 0;">
                <h3 id="title_ingreso" tabindex="0" role="button" style="color: #e0316d; font-size: 0.95rem; cursor: pointer; text-decoration: underline; font-weight: 600;">
                    ¿No tenés usuario? Registrate aquí
                </h3>
            </div>

            <!-- Registro inicialmente oculto; el control superior permite alternarlo antes de enviarlo. -->
            <section class="registro" id="registroFormContainer" style="display: none; border-top: 1px dashed #cbd5e1; padding-top: 20px; margin-top: 15px;">
                <h3 style="color: #2d112c; font-size: 1.1rem; margin-bottom: 12px;">Crear Cuenta de Usuario</h3>
                <form class="form_registro" id="FormRegistro">
                    <div style="margin-bottom: 12px;">
                        <label for="username" style="display: block; margin-bottom: 4px; font-size: 0.88rem; font-weight: 600;">Nuevo Usuario:</label>
                        <input type="text" name="username" id="username" placeholder="Nombre de usuario" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>
                    <div style="margin-bottom: 12px;">
                        <label for="passwordRegistro" style="display: block; margin-bottom: 4px; font-size: 0.88rem; font-weight: 600;">Contraseña:</label>
                        <input type="password" name="password" id="passwordRegistro" placeholder="Creá tu contraseña" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>
                    <div style="margin-bottom: 18px;">
                        <label for="confirmpassword" style="display: block; margin-bottom: 4px; font-size: 0.88rem; font-weight: 600;">Confirmar Contraseña:</label>
                        <input type="password" name="confirmpassword" id="confirmpassword" placeholder="Repetí la contraseña" required style="width: 100%; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px;">
                    </div>
                    <button type="submit" id="submit" style="width: 100%; padding: 10px; background: #2d112c; color: white; border: none; border-radius: 6px; font-weight: 600; cursor: pointer;">
                        Registrar Usuario
                    </button>
                </form>
            </section>
        </section>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
