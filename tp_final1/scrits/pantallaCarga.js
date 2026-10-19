(function () {
    const barra = document.getElementById('pantalla-carga-barra-progreso');
    const texto = document.getElementById('pantalla-carga-barra-progreso-texto');
    const destino = 'venta.php';
    const duracionMs = 5000;

    if (!barra || !texto) {
        window.location.replace(destino);
        return;
    }

    const inicio = performance.now();

    const actualizar = (ahora) => {
        const progreso = Math.min(100, Math.round(((ahora - inicio) / duracionMs) * 100));
        barra.style.width = `${progreso}%`;
        texto.textContent = `${progreso}%`;

        if (progreso >= 100) {
            window.location.replace(destino);
            return;
        }

        window.requestAnimationFrame(actualizar);
    };

    window.requestAnimationFrame(actualizar);
})();
