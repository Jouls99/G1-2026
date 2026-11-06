<?php
declare(strict_types=1);
?>
<!-- Pie común con el año vigente para evitar duplicar el cierre visual en cada página. -->
    <footer style="text-align: center; padding: 20px; font-size: 0.85rem; color: #94a3b8; font-family: sans-serif;">
        <p>&copy; <?= date('Y') ?> Sistema de Gestión y Stock - Desarrollado por</p>
        <div>
           <i class="Mensage_interactivo" id="F_abajo" onclick='window.alert("")'></i> 
           <div class="DESARROLLADORES">
            <ol style="list-style-type: none;" >
                <li>< Gomez Santiago/></li>
                <li>< Fernandez María de la esperanza/> </li>
                <li>< Fabricio Barrios/></li>
            </ol>
           </div>
        </div>
        
    </footer>
    <?php if (function_exists('isLoggedIn') && isLoggedIn()): ?>
    <!-- Mantiene el seguimiento de la sesión activa y permite aplicar el cierre de jornada. -->
    <script>
        const MensajeInteractivo = document.getElementById('F_abajo')
        

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

        const TIEMPO_MINIMO_SUSPENSION = 30000;
        let momentoOcultamiento = null;
        let cierrePorSuspensionEnviado = false;

        const cerrarSesionPorSuspension = () => {
            if (cierrePorSuspensionEnviado) return;
            cierrePorSuspensionEnviado = true;

            const datos = new Blob([], { type: 'application/x-www-form-urlencoded' });
            if (!navigator.sendBeacon || !navigator.sendBeacon('logout.php', datos)) {
                fetch('logout.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    keepalive: true
                }).catch((error) => {
                    console.error('No se pudo cerrar la sesión tras la suspensión:', error);
                });
            }
            window.location.replace('registroinicio.php');
        };

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') {
                momentoOcultamiento = Date.now();
                return;
            }

            if (momentoOcultamiento !== null
                && Date.now() - momentoOcultamiento >= TIEMPO_MINIMO_SUSPENSION) {
                cerrarSesionPorSuspension();
            }
            momentoOcultamiento = null;
        });

        window.addEventListener('freeze', cerrarSesionPorSuspension);
        registrarLatidoSesion();
        window.setInterval(registrarLatidoSesion, 60000);
    </script>
    <?php endif; ?>
</body>
</html>
