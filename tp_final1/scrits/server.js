const express = require('express');
const fs = require('fs').promises;
const path = require('path');
const cors = require('cors');

const app = express();
app.use(cors());
app.use(express.json());

const DATA_DIR = path.join(__dirname, '..', 'data');
const DATA_FILE = path.join(DATA_DIR, 'users.json');
const INVENTARIO_FILE = path.join(DATA_DIR, 'inventario.json');
const VENTAS_FILE = path.join(DATA_DIR, 'ventas.json');

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

app.use(express.static(path.join(__dirname, '..')));

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`Server running on http://localhost:${PORT}`));
