/**
 * Helper unificado para llamadas a la API PHP
 * Detecta automáticamente la ruta base de la aplicación (raíz o subdirectorio /proyecto/)
 */
const API = (function() {
    // Determinar la ruta base hacia la carpeta api/
    function getApiBaseUrl() {
        const path = window.location.pathname;
        const dir = path.substring(0, path.lastIndexOf('/'));
        // Si estamos en un subdirectorio (ej. /proyecto/), dir será '/proyecto'
        // Si estamos en raíz, dir será ''
        return (dir ? dir : '') + '/api';
    }

    const API_BASE = getApiBaseUrl();

    async function request(endpoint, options = {}) {
        const url = `${API_BASE}/${endpoint}`;
        const defaultHeaders = {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        };

        const config = {
            ...options,
            headers: {
                ...defaultHeaders,
                ...(options.headers || {})
            }
        };

        try {
            const response = await fetch(url, config);
            let data = null;
            try {
                data = await response.json();
            } catch (e) {
                // Respuesta vacía o no JSON
            }

            if (!response.ok) {
                if (response.status === 401 && !endpoint.includes('action=login') && !endpoint.includes('action=check')) {
                    if (!window.location.pathname.endsWith('login.php')) {
                        window.location.href = 'login.php';
                    }
                }
                const errorMsg = (data && data.error) ? data.error : `Error HTTP ${response.status}`;
                const err = new Error(errorMsg);
                err.status = response.status;
                err.data = data;
                throw err;
            }

            return data;
        } catch (error) {
            console.error(`[API Error] en ${endpoint}:`, error);
            throw error;
        }
    }

    return {
        baseUrl: API_BASE,

        // Inventario
        async getInventario() {
            return await request('inventario.php', { method: 'GET', cache: 'no-store' });
        },

        async saveInventario(data) {
            return await request('inventario.php', {
                method: 'PUT',
                body: JSON.stringify(data)
            });
        },

        // Ventas
        async getVentas() {
            return await request('ventas.php', { method: 'GET', cache: 'no-store' });
        },

        async addVenta(venta) {
            return await request('ventas.php', {
                method: 'POST',
                body: JSON.stringify(venta)
            });
        },

        async saveVentas(ventas) {
            return await request('ventas.php', {
                method: 'PUT',
                body: JSON.stringify(ventas)
            });
        },

        async deleteVenta(id) {
            return await request(`ventas.php?id=${encodeURIComponent(id)}`, {
                method: 'DELETE'
            });
        },

        // Usuarios y Autenticación
        async getUsers() {
            return await request('users.php', { method: 'GET' });
        },

        async registerUser(usuario, password, role = 'vendedor') {
            return await request('users.php', {
                method: 'POST',
                body: JSON.stringify({ usuario, password, role })
            });
        },

        async loginUser(usuario, password) {
            return await request('users.php', {
                method: 'POST',
                body: JSON.stringify({ action: 'login', usuario, password })
            });
        },

        async checkAuth() {
            return await request('auth.php?action=check', { method: 'GET' });
        },

        async logout() {
            return await request('auth.php?action=logout', { method: 'POST' });
        }
    };
})();
