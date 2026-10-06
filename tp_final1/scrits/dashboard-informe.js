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
const puedeModificarInforme = window.puedeModificarInforme === true;
const usuarioEsAdminInforme = window.usuarioEsAdminInforme === true;

const currencyFormatter = new Intl.NumberFormat('es-AR', {
  style: 'currency',
  currency: 'ARS',
  maximumFractionDigits: 0
});

function escapeHtml(str) {
  if (typeof str !== 'string') return String(str ?? '');
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function getPeriodLabels(period) {
  switch (period) {
    case 'semanal':
      return ['Sem 1', 'Sem 2', 'Sem 3', 'Sem 4'];
    case 'mensual':
      return ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
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
  if (!container) return;
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
  if (!container) return;
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
  const inventoryValue = state.inventory
    .filter((item) => item.categoria === state.category)
    .reduce((total, item) => {
      const quantity = Number(item.cantidad ?? item.stock ?? 0);
      const price = Number(item.precio ?? 0);
      return total + price * quantity;
    }, 0);

  const elSold = document.getElementById('metricSold');
  const elRev = document.getElementById('metricRevenue');
  const elSum = document.getElementById('metricSummary');
  const elVal = document.getElementById('metricInventoryValue');
  const elTitle = document.getElementById('dashboardTitle');

  if (elSold) elSold.textContent = sold;
  if (elRev) elRev.textContent = currencyFormatter.format(revenue);
  if (elSum) elSum.textContent = currencyFormatter.format(summary);
  if (elVal) elVal.textContent = currencyFormatter.format(inventoryValue);
  if (elTitle) elTitle.textContent = `${state.category} · ${state.period.charAt(0).toUpperCase() + state.period.slice(1)}`;
}

function renderChart(series) {
  const svg = document.getElementById('lineChart');
  if (!svg) return;
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
  if (!list) return;
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
          <span>${escapeHtml(item.nombre)}</span>
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
  if (!tbody) return;
  tbody.innerHTML = state.inventory.map((item) => {
    const sold = soldSummary[String(item.codigo || '').toLowerCase()] || 0;
    const saleInfo = getLastSaleInfo(item.codigo);
    const available = item.cantidad || 0;
    const totalValue = item.total != null ? item.total : (item.precio || 0) * available;

    return `
      <tr>
        <td>${escapeHtml(item.categoria || 'Sin categoría')}</td>
        <td>${escapeHtml(item.nombre)}</td>
        <td>${escapeHtml(item.codigo)}</td>
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
  if (!tbody) return;

  const sales = [...state.sales].sort((a, b) => new Date(b.fecha) - new Date(a.fecha));

  if (count) count.textContent = `${sales.length} ventas`;

  if (!sales.length) {
    tbody.innerHTML = '<tr><td colspan="6">No hay ventas registradas todavía.</td></tr>';
    return;
  }

  tbody.innerHTML = sales.map((sale) => {
    const date = new Date(sale.fecha);
    const productoText = (sale.productos || []).map((producto) => `${escapeHtml(producto.nombre)} × ${producto.cantidad}`).join(', ');
    return `
      <tr>
        <td>${date.toLocaleDateString('es-AR')}</td>
        <td>${date.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })}</td>
        <td>${date.toLocaleDateString('es-AR', { weekday: 'long' })}</td>
        <td>${productoText}</td>
        <td>${currencyFormatter.format(sale.total || 0)}</td>
        <td>
          ${puedeModificarInforme ? `
            <div style="display:flex; gap:6px; justify-content:center; align-items:center;">
              <button type="button" class="btn-edit-action" onclick="abrirModalEdicion('${sale.id}')" title="Editar venta">✏️ Editar</button>
              <button type="button" class="btn-delete-action" onclick="confirmarEliminarVenta('${sale.id}')" title="Eliminar venta">🗑️ Eliminar</button>
            </div>
          ` : '<span style="color:#6b7280; font-size:0.85rem;">Solo lectura</span>'}
        </td>
      </tr>
    `;
  }).join('');
}

function updateClock() {
  const now = new Date();
  const elDate = document.getElementById('liveDate');
  const elTime = document.getElementById('liveTime');
  const elDay = document.getElementById('liveDay');
  if (elDate) elDate.textContent = now.toLocaleDateString('es-AR');
  if (elTime) elTime.textContent = now.toLocaleTimeString('es-AR');
  if (elDay) elDay.textContent = now.toLocaleDateString('es-AR', { weekday: 'long' });
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
  const salesToggle = document.getElementById('btn-despliegueventas');
  const summaryToggle = document.getElementById('btn-despliegueresumen');
  const salesSection = document.getElementById('seccion-ventas');
  const summarySection = document.getElementById('seccion-resumen');

  salesToggle?.addEventListener('click', () => {
    if (!salesSection || !summarySection || !summaryToggle) return;
    salesSection.hidden = !salesSection.hidden;
    summarySection.hidden = true;
    salesToggle.setAttribute('aria-expanded', String(!salesSection.hidden));
    summaryToggle.setAttribute('aria-expanded', 'false');
  });

  summaryToggle?.addEventListener('click', () => {
    if (!salesSection || !summarySection || !salesToggle) return;
    summarySection.hidden = !summarySection.hidden;
    salesSection.hidden = true;
    summaryToggle.setAttribute('aria-expanded', String(!summarySection.hidden));
    salesToggle.setAttribute('aria-expanded', 'false');
  });

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

// Cambiar de cuenta usando la autenticación del servidor y su rol real.
const switchUserModal = document.getElementById('switchUserModal');
const switchUserForm = document.getElementById('switchUserForm');
const switchUserMessage = document.getElementById('switchUserMessage');
const switchUserSubmit = document.getElementById('switchUserSubmit');

function closeSwitchUserModal() {
  if (!switchUserModal) return;
  switchUserModal.style.display = 'none';
  switchUserForm?.reset();
  if (switchUserMessage) switchUserMessage.textContent = '';
}

document.getElementById('openSwitchUserBtn')?.addEventListener('click', () => {
  if (!switchUserModal) return;
  switchUserModal.style.display = 'flex';
  document.getElementById('switchUserName')?.focus();
});
document.getElementById('closeSwitchUserBtn')?.addEventListener('click', closeSwitchUserModal);
document.getElementById('cancelSwitchUserBtn')?.addEventListener('click', closeSwitchUserModal);

switchUserForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const usuario = document.getElementById('switchUserName').value.trim();
  const password = document.getElementById('switchUserPassword').value;
  if (!usuario || !password) return;

  switchUserSubmit.disabled = true;
  switchUserSubmit.textContent = 'Verificando...';
  switchUserMessage.textContent = '';

  try {
    const response = await fetch('api/auth.php?action=login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ usuario, password })
    });
    const result = await response.json();

    if (!response.ok || !result.ok) {
      throw new Error(result.message || 'Usuario o contraseña incorrectos.');
    }

    const role = result.user?.role || 'vendedor';
    const roleLabels = {
      superadmin: 'Super Admin',
      administrador: 'Administrador',
      admin: 'Administrador',
      vendedor: 'Vendedor'
    };
    localStorage.setItem('usuarioActual', result.user.usuario);
    alert(`Sesión cambiada correctamente. Rol detectado: ${roleLabels[role.toLowerCase()] || role}.`);
    window.location.reload();
  } catch (error) {
    switchUserMessage.textContent = error.message;
  } finally {
    switchUserSubmit.disabled = false;
    switchUserSubmit.textContent = 'Ingresar';
  }
});

// === MODAL DE EDICIÓN DE VENTAS ===
function abrirModalEdicion(saleId) {
  if (!puedeModificarInforme) return;
  const sale = state.sales.find(s => String(s.id) === String(saleId));
  if (!sale) return;

  document.getElementById('edit-sale-id').value = sale.id;
  document.getElementById('edit-sale-fecha').value = new Date(sale.fecha).toLocaleString('es-AR');
  
  const container = document.getElementById('edit-sale-products-container');
  container.innerHTML = '';

  (sale.productos || []).forEach((prod) => {
    // Calcular el stock disponible real sumando lo ya vendido de este producto
    const invItem = state.inventory.find(item => String(item.codigo).toLowerCase() === String(prod.codigo).toLowerCase());
    const stockDisponibleRestaurado = (invItem ? Number(invItem.cantidad) : 0) + Number(prod.cantidad);

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
        <span style="font-weight: 600; color: #490633;">${escapeHtml(prod.nombre)}</span>
        <small style="color: #6b4d64;">Precio: ${currencyFormatter.format(prod.precio)}</small>
      </div>
      <div style="flex: 1.5; display: flex; align-items: center; gap: 6px; justify-content: flex-end;">
        <input type="number" class="edit-product-qty" min="0" max="${stockDisponibleRestaurado}" value="${prod.cantidad}" data-codigo="${escapeHtml(prod.codigo)}" data-precio="${prod.precio}" data-nombre="${escapeHtml(prod.nombre)}" style="width: 60px; padding: 6px; border: 1px solid #dabad3; border-radius: 6px; text-align: center; color: #490633; font-weight: bold;">
        <span style="font-size: 0.8em; color: #7a4f6a;">(Máx: ${stockDisponibleRestaurado})</span>
      </div>
      <button type="button" class="btn-remove-prod" style="padding: 6px 10px; background-color: #ffe5e5; color: #cc0000; border: 1px solid #ffcccc; border-radius: 6px; cursor: pointer; font-size: 0.9em; transition: 0.2s;" title="Dejar cantidad en 0">❌</button>
    `;

    // Quitar producto click
    row.querySelector('.btn-remove-prod')?.addEventListener('click', () => {
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
  document.getElementById('editSaleModal').style.display = 'flex';
}

function recalcularTotalModal() {
  const container = document.getElementById('edit-sale-products-container');
  if (!container) return;
  const rows = container.querySelectorAll('.edit-product-row');
  let total = 0;
  
  rows.forEach(row => {
    const qtyInput = row.querySelector('.edit-product-qty');
    const qty = parseInt(qtyInput.value) || 0;
    const price = parseFloat(qtyInput.dataset.precio) || 0;
    total += qty * price;
  });

  const totalEl = document.getElementById('edit-sale-total');
  if (totalEl) totalEl.textContent = currencyFormatter.format(total);
}

// Cerrar modal
document.getElementById('closeModalBtn')?.addEventListener('click', () => {
  const modal = document.getElementById('editSaleModal');
  if (modal) modal.style.display = 'none';
});

window.addEventListener('click', (event) => {
  const modal = document.getElementById('editSaleModal');
  if (event.target === modal) {
    modal.style.display = 'none';
  }
});

// Guardar cambios modal
document.getElementById('edit-sale-form')?.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!puedeModificarInforme) return;

  const saleId = document.getElementById('edit-sale-id').value;
  const originalSale = state.sales.find(s => String(s.id) === String(saleId));
  if (!originalSale) return;

  const container = document.getElementById('edit-sale-products-container');
  const rows = container.querySelectorAll('.edit-product-row');

  const nuevosProductosVenta = [];
  let totalCantidad = 0;

  for (const row of rows) {
    const qtyInput = row.querySelector('.edit-product-qty');
    const qty = parseInt(qtyInput.value) || 0;
    const codigo = qtyInput.dataset.codigo;
    const precio = parseFloat(qtyInput.dataset.precio) || 0;
    const nombre = qtyInput.dataset.nombre || 'Producto';

    totalCantidad += qty;

    if (qty > 0) {
      nuevosProductosVenta.push({
        nombre: nombre,
        codigo: codigo,
        cantidad: qty,
        precio: precio
      });
    }
  }

  // Si la cantidad total es 0, consultar si desea eliminar la venta
  if (totalCantidad === 0 || nuevosProductosVenta.length === 0) {
    if (!confirm('⚠️ La venta quedará vacía (0 productos).\n¿Deseás eliminar esta venta del historial y devolver el stock al inventario?')) {
      return;
    }
    await eliminarVentaDirecto(saleId);
    return;
  }

  try {
    const response = await fetch('api/ventas.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        id: saleId,
        codigo: nuevosProductosVenta[0].codigo,
        cantidad: nuevosProductosVenta[0].cantidad,
        precio: nuevosProductosVenta[0].precio,
        productos: nuevosProductosVenta
      })
    });
    const result = await response.json();

    if (!response.ok || !result.ok) {
      throw new Error(result.message || 'No se pudo modificar la venta.');
    }

    document.getElementById('editSaleModal').style.display = 'none';
    alert(result.message || '✅ Venta modificada con éxito y stock actualizado.');

    await loadInventory();

    localStorage.setItem('inventarioUpdated', Date.now().toString());
    window.dispatchEvent(new Event('inventario-updated'));
  } catch (error) {
    alert(`❌ Error al guardar: ${error.message}`);
  }
});

// Eliminar venta directo vía API
async function eliminarVentaDirecto(saleId) {
  if (!puedeModificarInforme) return;

  try {
    const response = await fetch(`api/ventas.php?id=${encodeURIComponent(saleId)}`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' }
    });
    const result = await response.json();

    if (!response.ok || !result.ok) {
      throw new Error(result.message || 'No se pudo eliminar la venta.');
    }

    const modal = document.getElementById('editSaleModal');
    if (modal) modal.style.display = 'none';

    alert(result.message || '✅ Venta eliminada con éxito y stock restaurado.');

    await loadInventory();

    localStorage.setItem('inventarioUpdated', Date.now().toString());
    window.dispatchEvent(new Event('inventario-updated'));
  } catch (error) {
    alert(`❌ Error al eliminar: ${error.message}`);
  }
}

// Confirmar y eliminar venta desde tabla o botón
function confirmarEliminarVenta(saleId) {
  if (!puedeModificarInforme) return;
  if (confirm('⚠️ ¿Estás seguro de que querés eliminar esta venta del historial?\nEl stock de los productos se devolverá automáticamente al inventario.')) {
    eliminarVentaDirecto(saleId);
  }
}

document.getElementById('btnDeleteSale')?.addEventListener('click', async () => {
  if (!puedeModificarInforme) return;
  const saleId = document.getElementById('edit-sale-id').value;
  if (!saleId) return;

  confirmarEliminarVenta(saleId);
});

window.abrirModalEdicion = abrirModalEdicion;
window.confirmarEliminarVenta = confirmarEliminarVenta;
window.eliminarVentaDirecto = eliminarVentaDirecto;
