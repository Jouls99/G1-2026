const express = require('express');
const path = require('path');

const app = express();

// El servidor estático no persiste datos: informa que las rutas de negocio requieren PHP/MySQL.
app.all(['/api/users', '/api/inventario', '/api/inventario.php', '/api/ventas', '/api/ventas.php'], (req, res) => {
  res.status(503).json({
    error: 'database_required',
    message: 'La persistencia requiere ejecutar la aplicación con PHP y MySQL.'
  });
});

// Publica los recursos de la aplicación para pruebas locales sin sustituir sus endpoints de PHP.
app.use(express.static(path.join(__dirname, '..')));

// Arranca el servidor local con el puerto configurado o el valor de desarrollo predeterminado.
const PORT = process.env.PORT || 3000;
app.listen(PORT, () => console.log(`Static server running on http://localhost:${PORT}`));
