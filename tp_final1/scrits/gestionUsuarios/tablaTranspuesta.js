/**
 * Convierte una tabla (matriz bidimensional) en su transpuesta.
 * La primera fila de origen son los nombres de columna; el resultado
 * deja esos nombres como primera columna y apila los datos en cada fila.
 */
/* function transponerMatriz(tabla) {
    if (!Array.isArray(tabla) || tabla.length === 0) {
        return [];
    }

    const totalFilasNueva = tabla.reduce((maximo, fila) => {
        const largo = Array.isArray(fila) ? fila.length : 0;
        return largo > maximo ? largo : maximo;
    }, 0);

    const transpuesta = [];
    for (let i = 0; i < totalFilasNueva; i++) {
        transpuesta[i] = [];
        for (let j = 0; j < tabla.length; j++) {
            const filaOrigen = Array.isArray(tabla[j]) ? tabla[j] : [];
            transpuesta[i][j] = filaOrigen[i];
        }
    }
    return transpuesta;
}

function escaparTextoTabla(valor) {
    return String(valor ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function obtenerContenedorTranspuesto(table) {
    let destino = table.nextElementSibling;
    if (!destino || !destino.classList.contains('tablas-transpuestas')) {
        destino = document.createElement('div');
        destino.className = 'tablas-transpuestas';
        destino.setAttribute('aria-live', 'polite');
        table.after(destino);
    }
    return destino;
}

function esFilaMensaje(tr) {
    const celdas = tr.querySelectorAll(':scope > td');
    return celdas.length === 1 && celdas[0].hasAttribute('colspan');
}

function actualizarTablaTranspuesta(table) {
    if (!table) {
        return;
    }

    const destino = obtenerContenedorTranspuesto(table);
    destino.replaceChildren();

    const nombresColumnas = Array.from(table.querySelectorAll('thead th')).map((th) => th.textContent.trim());
    const filasCuerpo = Array.from(table.querySelectorAll('tbody tr'));

    if (filasCuerpo.length === 0) {
        return;
    }

    filasCuerpo.forEach((tr) => {
        if (esFilaMensaje(tr)) {
            const aviso = document.createElement('div');
            aviso.className = 'tabla-transpuesta-vacia';
            aviso.innerHTML = tr.querySelector('td').innerHTML;
            destino.appendChild(aviso);
            return;
        }

        const celdas = Array.from(tr.querySelectorAll(':scope > td'));
        const matriz = [
            nombresColumnas,
            celdas.map((td) => td.innerHTML)
        ];
        const transpuesta = transponerMatriz(matriz);

        const miniTabla = document.createElement('table');
        miniTabla.className = 'tabla-transpuesta';
        if (tr.dataset.userId) {
            miniTabla.dataset.userId = tr.dataset.userId;
        }

        const tbody = document.createElement('tbody');
        transpuesta.forEach((fila) => {
            const nombre = fila[0] ?? '';
            const contenido = fila[1] ?? '';
            const filaNueva = document.createElement('tr');
            filaNueva.innerHTML = `<th scope="row">${escaparTextoTabla(nombre)}</th><td>${contenido}</td>`;
            tbody.appendChild(filaNueva);
        });

        miniTabla.appendChild(tbody);
        destino.appendChild(miniTabla);
    });
}

function actualizarTodasLasTablasTranspuestas() {
    document.querySelectorAll('.table-card > table.users-table').forEach(actualizarTablaTranspuesta);
}

function observarTablasParaTransponer() {
    document.querySelectorAll('.table-card > table.users-table tbody').forEach((tbody) => {
        if (tbody.dataset.transpuestaObservada === '1') {
            return;
        }
        tbody.dataset.transpuestaObservada = '1';
        const observador = new MutationObserver(() => {
            actualizarTablaTranspuesta(tbody.closest('table'));
        });
        observador.observe(tbody, { childList: true });
        actualizarTablaTranspuesta(tbody.closest('table'));
    });
}

observarTablasParaTransponer();
window.transponerMatriz = transponerMatriz;
window.actualizarTodasLasTablasTranspuestas = actualizarTodasLasTablasTranspuestas;
 */