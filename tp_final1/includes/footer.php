<?php
declare(strict_types=1);
?>
    <footer style="text-align: center; padding: 20px; font-size: 0.85rem; color: #94a3b8; font-family: sans-serif;">
        <p>&copy; <?= date('Y') ?> Sistema de Gestión y Stock - Desarrollado en PHP 8</p>
    </footer>
    <?php if (function_exists('isLoggedIn') && isLoggedIn()): ?>
    <script>
        const registrarLatidoSesion = () => {
            fetch('api/session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: '{}',
                credentials: 'same-origin'
            }).then((response) => {
                if (!response.ok) console.error('No se pudo actualizar la sesión activa.');
            }).catch((error) => {
                console.error('Error al actualizar la sesión activa:', error);
            });
        };
        registrarLatidoSesion();
        window.setInterval(registrarLatidoSesion, 60000);
    </script>
    <?php endif; ?>
</body>
</html>
