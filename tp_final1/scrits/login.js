const formIngreso = document.getElementById('Form_Ingreso');

async function loginUsuario(usuario, password) {
    try {
        const res = await fetch('api/auth.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ usuario, password })
        });
        const data = await res.json();
        if (res.ok && data.ok) {
            return { ok: true };
        }
        return { ok: false, message: data.message || 'Credenciales incorrectas.' };
    } catch (error) {
        console.error('Error en login:', error);
        return { ok: false, message: 'Error de conexión con el servidor PHP.' };
    }
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

        const result = await loginUsuario(usuario, password);
        if (result.ok) {
            localStorage.setItem('usuarioActual', usuario);
            window.location.href = 'venta.php';
        } else {
            alert(result.message);
        }
    });
}
