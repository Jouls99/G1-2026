const fallbackInventory = [
  { categoria: 'Maquillaje', nombre: 'Base Matte', codigo: 'MQL-001', precio: 18000, cantidad: 15, total: 270000, fecha: '2026-07-01', hora: '09:30', dia: 'Lunes' },
  { categoria: 'Maquillaje', nombre: 'Rubor Cream', codigo: 'MQL-002', precio: 9500, cantidad: 22, total: 209000, fecha: '2026-07-02', hora: '11:20', dia: 'Martes' },
  { categoria: 'Maquillaje', nombre: 'Base Líquida Nude', codigo: 'BS-001', precio: 16000, cantidad: 8, total: 128000, fecha: '2026-07-03', hora: '16:00', dia: 'Miércoles' },
  { categoria: 'Maquillaje', nombre: 'Sombras Compactas', codigo: 'OJ-001', precio: 12000, cantidad: 10, total: 120000, fecha: '2026-07-04', hora: '18:10', dia: 'Jueves' },
  { categoria: 'Skincare', nombre: 'Serum Vitamina C', codigo: 'SKN-001', precio: 24000, cantidad: 9, total: 216000, fecha: '2026-07-01', hora: '10:15', dia: 'Lunes' },
  { categoria: 'Skincare', nombre: 'Crema Hidratante', codigo: 'SKN-002', precio: 15000, cantidad: 14, total: 210000, fecha: '2026-07-02', hora: '14:40', dia: 'Martes' },
  { categoria: 'Skincare', nombre: 'Ampolla Vitamina C', codigo: 'TRT-001', precio: 9000, cantidad: 7, total: 63000, fecha: '2026-07-05', hora: '12:00', dia: 'Viernes' },
  { categoria: 'Fragancias', nombre: 'Perfume Floral', codigo: 'FRG-001', precio: 32000, cantidad: 6, total: 192000, fecha: '2026-07-06', hora: '15:35', dia: 'Sábado' }
];

const state = {
  inventory: [],
  sales: [],
  category: 'Maquillaje',
  period: 'diario'
};

const currencyFormatter = new Intl.NumberFormat('es-AR', {
  style: 'currency',
  currency: 'ARS',
  maximumFractionDigits: 0
});

function getPeriodLabels(period) {
  switch (period) {
    case 'semanal':
      return ['Sem 1', 'Sem 2', 'Sem 3', 'Sem 4'];
    case 'mensual':
      return ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun'];
    case 'diario':
    default:
      return ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
  }
}

function getSeriesIndex(date, period, length) {
  switch (period) {
    case 'semanal':
      return Math.min(Math.floor(date.getDate() / 7), length - 1);
    case 'mensual':
      return Math.min(date.getMonth(), length - 1);
    case 'diario':
    default:
      return (date.getDay() + 6) % 7;
  }
}

function buildSeries(category, period) {
  const labels = getPeriodLabels(period);
  const series = {
    labels,
    sold: labels.map(() => 0),
    revenue: labels.map(() => 0),
    summary: labels.map(() => 0)
  };

  const categorySales = state.sales.filter((sale) => {
    return (sale.productos || []).some((producto) => {
      const item = state.inventory.find((entry) => String(entry.codigo).toLowerCase() === String(producto.codigo).toLowerCase());
      return item?.categoria === category;
    });
  });

  categorySales.forEach((sale) => {
    const date = new Date(sale.fecha);
    const index = getSeriesIndex(date, period, labels.length);
    if (index < 0 || index >= labels.length) return;

    const productosCategoria = (sale.productos || []).filter((producto) => {
      const item = state.inventory.find((entry) => String(entry.codigo).toLowerCase() === String(producto.codigo).toLowerCase());
      return item?.categoria === category;
    });

    const soldQty = productosCategoria.reduce((sum, producto) => sum + (producto.cantidad || 0), 0);
    const revenueValue = productosCategoria.reduce((sum, producto) => sum + (producto.precio || 0) * (producto.cantidad || 0), 0);

    series.sold[index] += soldQty;
    series.revenue[index] += revenueValue;
    series.summary[index] += revenueValue;
  });

  return series;
}

function renderCategoryButtons() {
  const categories = [...new Set(state.inventory.map((item) => item.categoria))];
  const container = document.getElementById('categoryButtons');
  container.innerHTML = '';

  categories.forEach((category) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `chip ${state.category === category ? 'active' : ''}`;
    button.textContent = category;
    button.addEventListener('click', () => {
      state.category = category;
      render();
    });
    container.appendChild(button);
  });
}

function renderPeriodButtons() {
  const periods = ['diario', 'semanal', 'mensual'];
  const container = document.getElementById('periodButtons');
  container.innerHTML = '';

  periods.forEach((period) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = `chip ${state.period === period ? 'active' : ''}`;
    button.textContent = period.charAt(0).toUpperCase() + period.slice(1);
    button.addEventListener('click', () => {
      state.period = period;
      render();
    });
    container.appendChild(button);
  });
}

function renderMetrics(series) {
  const sold = series.sold.at(-1) || 0;
  const revenue = series.revenue.at(-1) || 0;
  const summary = series.summary.at(-1) || 0;

  document.getElementById('metricSold').textContent = sold;
  document.getElementById('metricRevenue').textContent = currencyFormatter.format(revenue);
  document.getElementById('metricSummary').textContent = currencyFormatter.format(summary);
  document.getElementById('dashboardTitle').textContent = `${state.category} · ${state.period.charAt(0).toUpperCase() + state.period.slice(1)}`;
}

function renderChart(series) {
  const svg = document.getElementById('lineChart');
  const width = 640;
  const height = 280;
  const padding = 36;
  const maxValue = Math.max(...series.sold, ...series.revenue, 1);
  const stepX = (width - padding * 2) / (series.labels.length - 1 || 1);

  const y = (value) => padding + ((maxValue - value) / maxValue) * (height - padding * 2);
  const soldPoints = series.sold.map((value, index) => `${padding + index * stepX},${y(value)}`).join(' ');
  const revenuePoints = series.revenue.map((value, index) => `${padding + index * stepX},${y(value)}`).join(' ');

  svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
  svg.innerHTML = `
    <rect x="0" y="0" width="${width}" height="${height}" rx="18" fill="#fff5fb"></rect>
    <line x1="${padding}" y1="${height - padding}" x2="${width - padding}" y2="${height - padding}" stroke="#dabad3" stroke-width="1"></line>
    <line x1="${padding}" y1="${padding}" x2="${padding}" y2="${height - padding}" stroke="#dabad3" stroke-width="1"></line>
    ${[0, 0.25, 0.5, 0.75, 1].map((ratio) => {
      const yPos = padding + (height - padding * 2) * ratio;
      const value = Math.round(maxValue * (1 - ratio));
      return `<line x1="${padding}" y1="${yPos}" x2="${width - padding}" y2="${yPos}" stroke="#f2dbe9" stroke-width="1"></line><text x="10" y="${yPos + 4}" fill="#6f4b63" font-size="11">${value}</text>`;
    }).join('')}
    ${series.labels.map((label, index) => `<text x="${padding + index * stepX}" y="${height - 10}" text-anchor="middle" fill="#6f4b63" font-size="11">${label}</text>`).join('')}
    <polyline points="${soldPoints}" fill="none" stroke="#7b2cbf" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"></polyline>
    <polyline points="${revenuePoints}" fill="none" stroke="#e0558d" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"></polyline>
    ${series.sold.map((value, index) => `<circle cx="${padding + index * stepX}" cy="${y(value)}" r="4.5" fill="#7b2cbf"></circle>`).join('')}
    ${series.revenue.map((value, index) => `<circle cx="${padding + index * stepX}" cy="${y(value)}" r="4.5" fill="#e0558d"></circle>`).join('')}
  `;
}

function buildSoldSummary() {
  const soldCounts = {};

  state.sales.forEach((sale) => {
    (sale.productos || []).forEach((producto) => {
      const key = String(producto.codigo || '').toLowerCase();
      soldCounts[key] = (soldCounts[key] || 0) + (producto.cantidad || 0);
    });
  });

  return soldCounts;
}

function getLastSaleInfo(codigo) {
  const codigoKey = String(codigo || '').toLowerCase();
  const salesForProduct = state.sales.filter((sale) =>
    (sale.productos || []).some((producto) => String(producto.codigo || '').toLowerCase() === codigoKey)
  );

  if (!salesForProduct.length) {
    return { fecha: '-', hora: '-', dia: '-' };
  }

  const lastSale = salesForProduct.reduce((latest, sale) => {
    return new Date(sale.fecha) > new Date(latest.fecha) ? sale : latest;
  }, salesForProduct[0]);

  const date = new Date(lastSale.fecha);
  return {
    fecha: date.toLocaleDateString('es-AR'),
    hora: date.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' }),
    dia: date.toLocaleDateString('es-AR', { weekday: 'long' })
  };
}

function renderProducts() {
  const list = document.getElementById('productList');
  const soldSummary = buildSoldSummary();
  const items = state.inventory.filter((item) => item.categoria === state.category);

  if (!items.length) {
    list.innerHTML = '<li class="empty">No hay productos cargados para esta categoría.</li>';
    return;
  }

  list.innerHTML = items
    .map((item) => {
      const sold = soldSummary[String(item.codigo || '').toLowerCase()] || 0;
      return `
        <li>
          <span>${item.nombre}</span>
          <strong>${sold} vend.</strong>
          <small>${item.cantidad} disponibles</small>
        </li>
      `;
    })
    .join('');
}

function renderInventoryTable() {
  const soldSummary = buildSoldSummary();
  const tbody = document.getElementById('stockTableBody');
  tbody.innerHTML = state.inventory.map((item) => {
    const sold = soldSummary[String(item.codigo || '').toLowerCase()] || 0;
    const saleInfo = getLastSaleInfo(item.codigo);
    const available = item.cantidad || 0;
    const totalValue = item.total != null ? item.total : (item.precio || 0) * available;

    return `
      <tr>
        <td>${item.categoria || 'Sin categoría'}</td>
        <td>${item.nombre}</td>
        <td>${item.codigo}</td>
        <td>${sold}</td>
        <td>${available}</td>
        <td>${currencyFormatter.format(item.precio || 0)}</td>
        <td>${currencyFormatter.format(totalValue)}</td>
        <td>${saleInfo.fecha}</td>
        <td>${saleInfo.hora}</td>
        <td>${saleInfo.dia}</td>
      </tr>
    `;
  }).join('');
}

function renderSalesHistory() {
  const tbody = document.getElementById('salesHistoryBody');
  const count = document.getElementById('salesCount');
  const sales = [...state.sales].sort((a, b) => new Date(b.fecha) - new Date(a.fecha));

  count.textContent = `${sales.length} ventas`;

  if (!sales.length) {
    tbody.innerHTML = '<tr><td colspan="6">No hay ventas registradas todavía.</td></tr>';
    return;
  }

  tbody.innerHTML = sales.map((sale) => {
    const date = new Date(sale.fecha);
    const productoText = (sale.productos || []).map((producto) => `${producto.nombre} × ${producto.cantidad}`).join(', ');
    return `
      <tr>
        <td>${date.toLocaleDateString('es-AR')}</td>
        <td>${date.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })}</td>
        <td>${date.toLocaleDateString('es-AR', { weekday: 'long' })}</td>
        <td>${productoText}</td>
        <td>${currencyFormatter.format(sale.total || 0)}</td>
        <td>
          <button class="btn-edit-action" onclick="abrirModalEdicion('${sale.id}')">✏️ Editar</button>
        </td>
      </tr>
    `;
  }).join('');
}

function updateClock() {
  const now = new Date();
  document.getElementById('liveDate').textContent = now.toLocaleDateString('es-AR');
  document.getElementById('liveTime').textContent = now.toLocaleTimeString('es-AR');
  document.getElementById('liveDay').textContent = now.toLocaleDateString('es-AR', { weekday: 'long' });
}

function render() {
  renderCategoryButtons();
  renderPeriodButtons();
  const series = buildSeries(state.category, state.period);
  renderMetrics(series);
  renderChart(series);
  renderProducts();
  renderInventoryTable();
  renderSalesHistory();
  updateClock();
}

async function loadInventory() {
  try {
    const response = await fetch('api/inventario.php', { cache: 'no-store' });
    if (!response.ok) throw new Error('No se pudo cargar el inventario');
    const data = await response.json();
    if (Array.isArray(data) && data.length) {
      state.inventory = data;
    } else {
      state.inventory = fallbackInventory;
    }
  } catch (error) {
    state.inventory = fallbackInventory;
  }

  try {
    const salesResponse = await fetch('api/ventas.php', { cache: 'no-store' });
    if (salesResponse.ok) {
      const salesData = await salesResponse.json();
      state.sales = Array.isArray(salesData) ? salesData : [];
    }
  } catch (error) {
    state.sales = [];
  }

  render();
}

window.addEventListener('DOMContentLoaded', () => {
  loadInventory();
  setInterval(updateClock, 1000);
});
window.addEventListener('storage', (event) => {
  if (event.key === 'inventarioUpdated') {
    loadInventory();
  }
});
window.addEventListener('inventario-updated', () => {
  loadInventory();
});

// === MODAL DE EDICIÓN DE VENTAS ===
function abrirModalEdicion(saleId) {
  const sale = state.sales.find(s => s.id === saleId);
  if (!sale) return;

  document.getElementById('edit-sale-id').value = sale.id;
  document.getElementById('edit-sale-fecha').value = new Date(sale.fecha).toLocaleString('es-AR');
  
  const container = document.getElementById('edit-sale-products-container');
  container.innerHTML = '';

  sale.productos.forEach((prod) => {
    // Calcular el stock disponible real sumando lo ya vendido de este producto
    const invItem = state.inventory.find(item => String(item.codigo).toLowerCase() === String(prod.codigo).toLowerCase());
    const stockDisponibleRestaurado = (invItem ? invItem.cantidad : 0) + prod.cantidad;

    const row = document.createElement('div');
    row.className = 'edit-product-row';
    row.style.display = 'flex';
    row.style.alignItems = 'center';
    row.style.justifyContent = 'space-between';
    row.style.gap = '10px';
    row.style.marginBottom = '12px';
    row.style.padding = '8px';
    row.style.backgroundColor = '#fff5fb';
    row.style.borderRadius = '8px';
    row.style.boxShadow = 'inset 0 0 0 1px #f0d5e4';

    row.innerHTML = `
      <div style="flex: 2; display: flex; flex-direction: column;">
        <span style="font-weight: 600; color: #490633;">${prod.nombre}</span>
        <small style="color: #6b4d64;">Precio: ${currencyFormatter.format(prod.precio)}</small>
      </div>
      <div style="flex: 1.5; display: flex; align-items: center; gap: 6px; justify-content: flex-end;">
        <input type="number" class="edit-product-qty" min="0" max="${stockDisponibleRestaurado}" value="${prod.cantidad}" data-codigo="${prod.codigo}" data-precio="${prod.precio}" style="width: 60px; padding: 6px; border: 1px solid #dabad3; border-radius: 6px; text-align: center; color: #490633; font-weight: bold;">
        <span style="font-size: 0.8em; color: #7a4f6a;">(Máx: ${stockDisponibleRestaurado})</span>
      </div>
      <button type="button" class="btn-remove-prod" style="padding: 6px 10px; background-color: #ffe5e5; color: #cc0000; border: 1px solid #ffcccc; border-radius: 6px; cursor: pointer; font-size: 0.9em; transition: 0.2s;">❌</button>
    `;

    // Quitar producto click
    row.querySelector('.btn-remove-prod').addEventListener('click', () => {
      row.querySelector('.edit-product-qty').value = 0;
      recalcularTotalModal();
      row.style.opacity = '0.4';
    });

    row.querySelector('.edit-product-qty').addEventListener('input', () => {
      const input = row.querySelector('.edit-product-qty');
      let val = parseInt(input.value) || 0;
      if (val < 0) val = 0;
      if (val > stockDisponibleRestaurado) val = stockDisponibleRestaurado;
      input.value = val;
      
      if (val === 0) {
        row.style.opacity = '0.4';
      } else {
        row.style.opacity = '1';
      }
      recalcularTotalModal();
    });

    container.appendChild(row);
  });

  recalcularTotalModal();
  document.getElementById('editSaleModal').style.display = 'block';
}

function recalcularTotalModal() {
  const container = document.getElementById('edit-sale-products-container');
  const rows = container.querySelectorAll('.edit-product-row');
  let total = 0;
  
  rows.forEach(row => {
    const qtyInput = row.querySelector('.edit-product-qty');
    const qty = parseInt(qtyInput.value) || 0;
    const price = parseFloat(qtyInput.dataset.precio) || 0;
    total += qty * price;
  });

  document.getElementById('edit-sale-total').textContent = currencyFormatter.format(total);
}

// Cerrar modal
document.getElementById('closeModalBtn').addEventListener('click', () => {
  document.getElementById('editSaleModal').style.display = 'none';
});

window.addEventListener('click', (event) => {
  const modal = document.getElementById('editSaleModal');
  if (event.target === modal) {
    modal.style.display = 'none';
  }
});

// Guardar cambios modal
document.getElementById('edit-sale-form').addEventListener('submit', async (e) => {
  e.preventDefault();

  const saleId = document.getElementById('edit-sale-id').value;
  const originalSale = state.sales.find(s => s.id === saleId);
  if (!originalSale) return;

  const container = document.getElementById('edit-sale-products-container');
  const rows = container.querySelectorAll('.edit-product-row');

  // Clonar el inventario para simular stock
  const tempInventory = JSON.parse(JSON.stringify(state.inventory));

  // 1. Restaurar stock original de la venta
  originalSale.productos.forEach(prod => {
    const invItem = tempInventory.find(item => String(item.codigo).toLowerCase() === String(prod.codigo).toLowerCase());
    if (invItem) {
      invItem.cantidad += prod.cantidad;
      invItem.total = invItem.precio * invItem.cantidad;
    }
  });

  const nuevosProductosVenta = [];
  let totalVenta = 0;

  // 2. Validar y descontar el nuevo stock
  for (const row of rows) {
    const qtyInput = row.querySelector('.edit-product-qty');
    const qty = parseInt(qtyInput.value) || 0;
    const codigo = qtyInput.dataset.codigo;
    const precio = parseFloat(qtyInput.dataset.precio) || 0;

    if (qty > 0) {
      const invItem = tempInventory.find(item => String(item.codigo).toLowerCase() === String(codigo).toLowerCase());
      if (!invItem) {
        alert(`Error: El producto con código ${codigo} ya no existe en el inventario.`);
        return;
      }
      if (invItem.cantidad < qty) {
        alert(`Stock insuficiente para ${invItem.nombre}. Disponible: ${invItem.cantidad}`);
        return;
      }

      invItem.cantidad -= qty;
      invItem.total = invItem.precio * invItem.cantidad;

      nuevosProductosVenta.push({
        nombre: invItem.nombre,
        codigo: invItem.codigo,
        cantidad: qty,
        precio: invItem.precio
      });

      totalVenta += qty * invItem.precio;
    }
  }

  if (nuevosProductosVenta.length === 0) {
    if (!confirm('La venta quedará vacía (0 productos). ¿Querés eliminar esta venta del historial?')) {
      return;
    }
    await eliminarVentaDirecto(saleId);
    return;
  }

  // 3. Confirmar cambios en el state
  state.inventory = tempInventory;
  
  // Actualizar la venta
  originalSale.productos = nuevosProductosVenta;
  originalSale.total = totalVenta;
  originalSale.dinero = totalVenta;

  // Guardar en la base de datos
  try {
    const saveInvRes = await fetch('api/inventario.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(state.inventory)
    });
    if (!saveInvRes.ok) throw new Error('No se pudo actualizar el inventario.');

    const saveSalesRes = await fetch('api/ventas.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(state.sales)
    });
    if (!saveSalesRes.ok) throw new Error('No se pudo actualizar las ventas.');

    localStorage.setItem('inventarioUpdated', Date.now().toString());
    window.dispatchEvent(new Event('inventario-updated'));

    document.getElementById('editSaleModal').style.display = 'none';
    alert('✅ Venta modificada con éxito.');
    render();
  } catch (error) {
    alert(`❌ Error al guardar: ${error.message}`);
  }
});

// Eliminar venta
async function eliminarVentaDirecto(saleId) {
  const sale = state.sales.find(s => s.id === saleId);
  if (!sale) return;

  // Devolver el stock
  sale.productos.forEach(prod => {
    const invItem = state.inventory.find(item => String(item.codigo).toLowerCase() === String(prod.codigo).toLowerCase());
    if (invItem) {
      invItem.cantidad += prod.cantidad;
      invItem.total = invItem.precio * invItem.cantidad;
    }
  });

  // Filtrar
  state.sales = state.sales.filter(s => s.id !== saleId);

  try {
    const saveInvRes = await fetch('api/inventario.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(state.inventory)
    });
    if (!saveInvRes.ok) throw new Error('No se pudo actualizar el inventario.');

    const saveSalesRes = await fetch('api/ventas.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(state.sales)
    });
    if (!saveSalesRes.ok) throw new Error('No se pudo actualizar las ventas.');

    localStorage.setItem('inventarioUpdated', Date.now().toString());
    window.dispatchEvent(new Event('inventario-updated'));

    document.getElementById('editSaleModal').style.display = 'none';
    alert('✅ Venta eliminada con éxito y stock restaurado.');
    render();
  } catch (error) {
    alert(`❌ Error al eliminar: ${error.message}`);
  }
}

document.getElementById('btnDeleteSale').addEventListener('click', async () => {
  const saleId = document.getElementById('edit-sale-id').value;
  if (!saleId) return;

  if (confirm('¿Estás seguro de que querés eliminar esta venta? El stock se devolverá al inventario.')) {
    await eliminarVentaDirecto(saleId);
  }
});

window.abrirModalEdicion = abrirModalEdicion;
