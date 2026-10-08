<?php
/**
 * Footer común para todas las vistas PHP
 * Cierre común de las vistas: imprime el pie y carga el helper y los scripts específicos declarados por cada página.
 */
$extraJs = $extraJs ?? [];
?>
</div> <!-- Fin de .main-wrapper -->

<!-- Pie de página con la marca y el año calculado al renderizar. -->
<footer class="app-footer">
    <div class="footer-content">
        <p>© <?= date('Y') ?> Sistema de Gestión de Cosmética y Ventas &bull; PHP & Apache</p>
    </div>
</footer>

<!-- Cargar primero el cliente compartido para que esté disponible en los controladores de cada vista. -->
<script src="assets/js/api.js"></script>
<?php foreach ($extraJs as $jsFile): ?>
    <script src="<?= htmlspecialchars($jsFile) ?>"></script>
<?php endforeach; ?>
</body>
</html>
