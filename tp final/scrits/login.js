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

// Iniciar sesión contra la API de autenticación en PHP
async function loginUsuario(usuario, password) {
    try {
        const res = await fetch('api/auth.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ usuario, password })
        });
        const data = await res.json();
        if (res.ok && data.ok) {
            return { ok: true, user: data.user };
        }
        return { ok: false, message: data.message || 'Credenciales incorrectas.' };
    } catch (e) {
        console.error('Error en login:', e);
        return { ok: false, message: 'Error de conexión con el servidor PHP.' };
    }
}

// Registrar usuario contra la API de autenticación en PHP
async function registrarUsuario(usuario, password, role = 'vendedor') {
    try {
        const res = await fetch('api/auth.php?action=register', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ usuario, password, role })
        });
        const data = await res.json();
        if (res.ok && data.ok) {
            return { ok: true, user: data.user };
        }
        return { ok: false, message: data.message || 'No se pudo crear el usuario.' };
    } catch (e) {
        console.error('Error en registro:', e);
        return { ok: false, message: 'Error de conexión con el servidor PHP.' };
    }
}

// Manejador del formulario de Registro
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

        const result = await registrarUsuario(usuario, password);
        if (result.ok) {
            localStorage.setItem('usuarioActual', usuario);
            window.location.href = 'prueba2.php';
        } else {
            alert(result.message);
        }
    });
}

// Manejador del formulario de Ingreso
if (formIngreso) {
    formIngreso.addEventListener('submit', async (event) => {
        event.preventDefault();

        const usuario = document.getElementById('usernameIngreso').value.trim();
        const password = document.getElementById('passwordIngreso').value;
        if (!usuario || !password) {
            alert('Completá todos los campos.');
            return;
        }

        const result = await loginUsuario(usuario, password);
        if (result.ok) {
            localStorage.setItem('usuarioActual', usuario);
            window.location.href = 'prueba2.php';
        } else {
            alert(result.message);
        }
    });
}