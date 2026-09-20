
const registroContainer = document.getElementById('registroFormContainer');
const tituloIngreso = document.getElementById('title_ingreso');
const formRegistro = document.getElementById('FormRegistro');
const formIngreso = document.getElementById('Form_Ingreso');

if (tituloIngreso) {
    tituloIngreso.addEventListener('click', () => {
        if (registroContainer) {
            registroContainer.classList.toggle('activo');
        }
    });

    tituloIngreso.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            if (registroContainer) {
                registroContainer.classList.toggle('activo');
            }
        }
    });
}

async function obtenerUsuarios() {
    try {
        const res = await fetch('/api/users');
        if (!res.ok) throw new Error('no server');
        return await res.json();
    } catch (e) {
        return JSON.parse(localStorage.getItem('usuarios') || '[]');
    }
}

async function guardarUsuarioServidor(usuario, password, role = 'vendedor') {
    try {
        const res = await fetch('/api/users', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ usuario, password, role })
        });
        if (res.ok) return true;
        if (res.status === 409) {
            alert('Ese usuario ya existe en el servidor.');
            return false;
        }
        throw new Error('server error');
    } catch (e) {
        const usuarios = JSON.parse(localStorage.getItem('usuarios') || '[]');
        const existe = usuarios.some((u) => u.usuario.toLowerCase() === usuario.toLowerCase());
        if (existe) {
            alert('Ese usuario ya existe.');
            return false;
        }
        usuarios.push({ usuario, password, role });
        localStorage.setItem('usuarios', JSON.stringify(usuarios));
        return true;
    }
}

if (formRegistro) {
    formRegistro.addEventListener('submit', async (event) => {
        event.preventDefault();

        const usuario = document.getElementById('username').value.trim();
        const password = document.getElementById('passwordRegistro').value;
        const confirmPassword = document.getElementById('confirmpassword').value;

        if (!usuario || !password || !confirmPassword) {
            alert('Completá todos los campos.');
            return;
        }

        if (password !== confirmPassword) {
            alert('Las contraseñas no coinciden.');
            return;
        }

        const ok = await guardarUsuarioServidor(usuario, password);
        if (ok) {
            localStorage.setItem('usuarioActual', usuario);
            window.location.href = 'prueba2.html';
        }
    });
}

if (formIngreso) {
    formIngreso.addEventListener('submit', async (event) => {
        event.preventDefault();

        const usuario = document.getElementById('usernameIngreso').value.trim();
        const password = document.getElementById('passwordIngreso').value;
        if (!usuario || !password) {
            alert('Completá todos los campos.');
            return;
        }

        const usuarios = await obtenerUsuarios();
        const usuarioEncontrado = usuarios.find((user) => user.usuario.toLowerCase() === usuario.toLowerCase());

        if (usuarioEncontrado && usuarioEncontrado.password === password) {
            localStorage.setItem('usuarioActual', usuario);
            window.location.href = 'prueba2.html';
        } else {
            alert('Usuario o contraseña incorrectos.');
        }
    });
}