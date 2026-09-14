/**
 * Lógica de inicio de sesión y registro de usuarios con backend PHP
 */
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

// Manejo de Registro
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

        try {
            const res = await API.registerUser(usuario, password, 'vendedor');
            if (res && res.ok) {
                localStorage.setItem('usuarioActual', usuario);
                alert('¡Usuario registrado con éxito!');
                window.location.href = 'index.php';
            }
        } catch (err) {
            if (err.status === 409 || (err.data && err.data.error === 'user_exists')) {
                alert('Ese usuario ya existe. Por favor elegí otro nombre.');
            } else {
                alert(`Error al registrar: ${err.message || 'Intente nuevamente'}`);
            }
        }
    });
}

// Manejo de Ingreso
if (formIngreso) {
    formIngreso.addEventListener('submit', async (event) => {
        event.preventDefault();

        const usuario = document.getElementById('usernameIngreso').value.trim();
        const password = document.getElementById('passwordIngreso').value;

        if (!usuario || !password) {
            alert('Completá todos los campos.');
            return;
        }

        try {
            const res = await API.loginUser(usuario, password);
            if (res && res.ok) {
                localStorage.setItem('usuarioActual', usuario);
                window.location.href = 'index.php';
            }
        } catch (err) {
            if (err.status === 401 || (err.data && err.data.error === 'invalid_credentials')) {
                alert('Usuario o contraseña incorrectos.');
            } else {
                alert(`Error al iniciar sesión: ${err.message || 'Intente nuevamente'}`);
            }
        }
    });
}
