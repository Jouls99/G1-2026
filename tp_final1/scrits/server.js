const express = require('express');
const path = require('path');

const app = express();

app.all(['/api/users', '/api/inventario', '/api/inventario.php', '/api/ventas', '/api/ventas.php'], (req, res) => {
  res.status(503).json({
    error: 'database_required',
    message: 'La persistencia requiere ejecutar la aplicación con PHP y MySQL.'
  });
});

app.use(express.static(path.join(__dirname, '..')));

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`Static server running on http://localhost:${PORT}`));
