const express = require('express');
const fs = require('fs').promises;
const path = require('path');
const cors = require('cors');

// Configurar el servidor Express para aceptar JSON y solicitudes del navegador.
const app = express();
app.use(cors());
app.use(express.json());

// Mantener archivos JSON en la raíz del proyecto para las rutas de usuarios, inventario y ventas.
const DATA_FILE = path.join(__dirname, '..', 'users.json');
const INVENTARIO_FILE = path.join(__dirname, '..', 'inventario.json');
const VENTAS_FILE = path.join(__dirname, '..', 'ventas.json');

// Consultar usuarios guardados; si el archivo aún no existe, devolver una colección vacía.
app.get('/api/users', async (req, res) => {
  try {
    const content = await fs.readFile(DATA_FILE, 'utf8');
    const users = JSON.parse(content || '[]');
    res.json(users);
  } catch (e) {
    if (e.code === 'ENOENT') return res.json([]);
    console.error(e);
    res.status(500).json({ error: 'read_error' });
  }
});

// Validar y añadir una cuenta si no existe otra con el mismo nombre, informando errores de lectura o escritura.
app.post('/api/users', async (req, res) => {
  const { usuario, password, role } = req.body;
  const roleToSave = role || 'vendedor';
  if (!usuario || !password) return res.status(400).json({ error: 'missing_fields' });
  try {
    let users = [];
    try {
      users = JSON.parse(await fs.readFile(DATA_FILE, 'utf8') || '[]');
    } catch (e) {
      if (e.code !== 'ENOENT') throw e;
    }
    const exists = users.find(u => u.usuario.toLowerCase() === usuario.toLowerCase());
    if (exists) return res.status(409).json({ error: 'user_exists' });
    users.push({ usuario, password, role: roleToSave });
    await fs.writeFile(DATA_FILE, JSON.stringify(users, null, 2), 'utf8');
    res.json({ ok: true });
  } catch (e) {
    console.error(e);
    res.status(500).json({ error: 'save_error' });
  }
});

// Inventario endpoints
// Consultar el inventario y devolver una lista vacía cuando no se haya creado el archivo.
app.get('/api/inventario', async (req, res) => {
  try {
    const content = await fs.readFile(INVENTARIO_FILE, 'utf8');
    const inventario = JSON.parse(content || '[]');
    res.json(inventario);
  } catch (e) {
    if (e.code === 'ENOENT') return res.json([]);
    console.error(e);
    res.status(500).json({ error: 'read_error' });
  }
});

// Reemplazar el inventario completo tras comprobar que el cliente envió una colección.
app.put('/api/inventario', async (req, res) => {
  const payload = req.body;
  if (!Array.isArray(payload)) return res.status(400).json({ error: 'invalid_payload' });
  try {
    await fs.writeFile(INVENTARIO_FILE, JSON.stringify(payload, null, 2), 'utf8');
    res.json({ ok: true });
  } catch (e) {
    console.error(e);
    res.status(500).json({ error: 'save_error' });
  }
});

// Obtener el historial guardado con una respuesta inicial vacía si todavía no hay ventas.
app.get('/api/ventas', async (req, res) => {
  try {
    const content = await fs.readFile(VENTAS_FILE, 'utf8');
    const ventas = JSON.parse(content || '[]');
    res.json(ventas);
  } catch (e) {
    if (e.code === 'ENOENT') return res.json([]);
    console.error(e);
    res.status(500).json({ error: 'read_error' });
  }
});

// Validar una venta nueva, añadirle identificador y fecha, y anexarla al historial persistido.
app.post('/api/ventas', async (req, res) => {
  const payload = req.body;
  if (!payload || !Array.isArray(payload.productos) || payload.productos.length === 0) {
    return res.status(400).json({ error: 'invalid_payload' });
  }

  try {
    let ventas = [];
    try {
      ventas = JSON.parse(await fs.readFile(VENTAS_FILE, 'utf8') || '[]');
    } catch (e) {
      if (e.code !== 'ENOENT') throw e;
    }

    const venta = {
      id: Date.now().toString(),
      ...payload,
      fecha: payload.fecha || new Date().toISOString()
    };

    ventas.push(venta);
    await fs.writeFile(VENTAS_FILE, JSON.stringify(ventas, null, 2), 'utf8');
    res.json({ ok: true, venta });
  } catch (e) {
    console.error(e);
    res.status(500).json({ error: 'save_error' });
  }
});

// Reemplazar el historial completo para que los clientes puedan guardar ediciones en lote.
app.put('/api/ventas', async (req, res) => {
  const payload = req.body;
  if (!Array.isArray(payload)) return res.status(400).json({ error: 'invalid_payload' });
  try {
    await fs.writeFile(VENTAS_FILE, JSON.stringify(payload, null, 2), 'utf8');
    res.json({ ok: true });
  } catch (e) {
    console.error(e);
    res.status(500).json({ error: 'save_error' });
  }
});

// Servir los archivos del proyecto junto a la API para ejecutar la interfaz desde este servidor.
app.use(express.static(path.join(__dirname, '..')));

const PORT = process.env.PORT || 3000;
// Iniciar el servidor en el puerto configurado o en 3000 para desarrollo local.
app.listen(PORT, () => console.log(`Server running on http://localhost:${PORT}`));
