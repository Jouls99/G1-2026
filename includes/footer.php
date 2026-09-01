<?php
/**
 * Footer común para todas las vistas PHP
 */
$extraJs = $extraJs ?? [];
?>
</div> <!-- Fin de .main-wrapper -->

<footer class="app-footer">
    <div class="footer-content">
        <p>© <?= date('Y') ?> Sistema de Gestión de Cosmética y Ventas &bull; PHP & Apache</p>
    </div>
</footer>

<script src="assets/js/api.js"></script>
<?php foreach ($extraJs as $jsFile): ?>
    <script src="<?= htmlspecialchars($jsFile) ?>"></script>
<?php endforeach; ?>
</body>
</html>
