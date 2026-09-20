/**
 * Lógica del dashboard de informes, gráficos SVG y edición de historial de ventas
 */
const state = {
  inventory: [],
  sales: [],
  activeCategory: 'all',
  activePeriod: 'todo'
};

const periods = [
  { id: 'hoy', label: 'Hoy' },
  { id: 'semana', label: 'Esta Semana' },
  { id: 'mes', label: 'Este Mes' },
  { id: 'todo', label: 'Todo' }
];

function updateClock() {
  const now = new Date();
  const dateEl = document.getElementById('liveDate');
  const timeEl = document.getElementById('liveTime');
  const dayEl = document.getElementById('liveDay');

  if (dateEl) {
    dateEl.textContent = now.toLocaleDateString('es-AR', {
      year: 'numeric',
      month: 'short',
      day: 'numeric'
    });
  }
  if (timeEl) {
    timeEl.textContent = now.toLocaleTimeString('es-AR');
  }
  if (dayEl) {
    dayEl.textContent = now.toLocaleDateString('es-AR', { weekday: 'long' });
  }
}

function getCategories() {
  const cats = new Set();
  state.inventory.forEach(i => {
    if (i.categoria) cats.add(i.categoria);
  });
  return ['all', ...Array.from(cats)];
}

function filterSalesByPeriod(sales, period) {
  const now = new Date();
  return sales.filter(sale => {
    const saleDate = new Date(sale.fecha || sale.timestamp || Date.now());
    if (isNaN(saleDate.getTime())) return false;

    if (period === 'hoy') {
      return saleDate.toDateString() === now.toDateString();
    }
    if (period === 'semana') {
      const oneWeekAgo = new Date();
      oneWeekAgo.setDate(now.getDate() - 7);
      return saleDate >= oneWeekAgo && saleDate <= now;
    }
    if (period === 'mes') {
      return saleDate.getMonth() === now.getMonth() && saleDate.getFullYear() === now.getFullYear();
    }
    return true; // 'todo'
  });
}

function calculateCategoryStats() {
  const filteredSales = filterSalesByPeriod(state.sales, state.activePeriod);
  
  let totalSold = 0;
  let totalRevenue = 0;
  let summaryAmount = 0;

  filteredSales.forEach(sale => {
    (sale.productos || []).forEach(prod => {
      // Si la categoría está filtrada, verificar coincidencia
      const invItem = state.inventory.find(i => String(i.codigo).toLowerCase() === String(prod.codigo).toLowerCase());
      const cat = prod.categoria || (invItem ? invItem.categoria : 'Sin categoría');

      if (state.activeCategory === 'all' || cat === state.activeCategory) {
        const qty = parseInt(prod.cantidad || 0, 10);
        const price = parseFloat(prod.precio || 0);
        totalSold += qty;
        totalRevenue += (price * qty);
      }
    });
  });

  // Resumen: valor total del inventario actual según filtro
  state.inventory.forEach(item => {
    if (state.activeCategory === 'all' || item.categoria === state.activeCategory) {
      summaryAmount += (parseFloat(item.precio || 0) * parseInt(item.cantidad || item.stock || 0, 10));
    }
  });

  return { totalSold, totalRevenue, summaryAmount };
}

function renderControls() {
  const catContainer = document.getElementById('categoryButtons');
  if (catContainer) {
    const categories = getCategories();
    catContainer.innerHTML = categories.map(cat => `
      <button class="chip ${state.activeCategory === cat ? 'active' : ''}" onclick="setCategory('${cat}')">
        ${cat === 'all' ? 'Todas' : cat}
      </button>
    `).join('');
  }

  const periodContainer = document.getElementById('periodButtons');
  if (periodContainer) {
    periodContainer.innerHTML = periods.map(p => `
      <button class="chip ${state.activePeriod === p.id ? 'active' : ''}" onclick="setPeriod('${p.id}')">
        ${p.label}
      </button>
    `).join('');
  }
}

function setCategory(cat) {
  state.activeCategory = cat;
  render();
}

function setPeriod(period) {
  state.activePeriod = period;
  render();
}

function renderMetrics() {
  const { totalSold, totalRevenue, summaryAmount } = calculateCategoryStats();
  
  const metricSold = document.getElementById('metricSold');
  const metricRevenue = document.getElementById('metricRevenue');
  const metricSummary = document.getElementById('metricSummary');

  if (metricSold) metricSold.textContent = totalSold.toLocaleString('es-AR');
  if (metricRevenue) metricRevenue.textContent = `$${totalRevenue.toLocaleString('es-AR', { minimumFractionDigits: 2 })}`;
  if (metricSummary) metricSummary.textContent = `$${summaryAmount.toLocaleString('es-AR', { minimumFractionDigits: 2 })}`;
}

function renderProducts() {
  const listEl = document.getElementById('productList');
  if (!listEl) return;

  const filtered = state.inventory.filter(i => state.activeCategory === 'all' || i.categoria === state.activeCategory);

  if (filtered.length === 0) {
    listEl.innerHTML = '<li class="text-empty">No hay productos en esta categoría.</li>';
    return;
  }

  listEl.innerHTML = filtered.map(item => `
    <li class="product-item">
      <div class="product-info">
        <strong>${item.nombre}</strong>
        <small>Cód: ${item.codigo} &bull; $${parseFloat(item.precio || 0).toLocaleString('es-AR', { minimumFractionDigits: 2 })}</small>
      </div>
      <div class="product-stock-tag ${parseInt(item.cantidad || item.stock || 0, 10) <= 5 ? 'low' : ''}">
        Stock: ${item.cantidad || item.stock || 0}
      </div>
    </li>
  `).join('');
}

function renderInventoryTable() {
  const tbody = document.getElementById('stockTableBody');
  if (!tbody) return;

  const filteredSales = filterSalesByPeriod(state.sales, state.activePeriod);
  
  // Agrupar ventas por producto
  const productSalesMap = {};
  filteredSales.forEach(sale => {
    (sale.productos || []).forEach(p => {
      const code = String(p.codigo).toLowerCase();
      if (!productSalesMap[code]) {
        productSalesMap[code] = {
          cantidadVendida: 0,
          totalGanancia: 0,
          ultimaFecha: sale.fecha
        };
      }
      const q = parseInt(p.cantidad || 0, 10);
      productSalesMap[code].cantidadVendida += q;
      productSalesMap[code].totalGanancia += (q * parseFloat(p.precio || 0));
      productSalesMap[code].ultimaFecha = sale.fecha;
    });
  });

  const filteredInventory = state.inventory.filter(i => state.activeCategory === 'all' || i.categoria === state.activeCategory);

  if (filteredInventory.length === 0) {
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;">No hay registros disponibles.</td></tr>';
    return;
  }

  tbody.innerHTML = filteredInventory.map(item => {
    const code = String(item.codigo).toLowerCase();
    const saleInfo = productSalesMap[code] || { cantidadVendida: 0, totalGanancia: 0, ultimaFecha: null };
    
    let fechaStr = '--', horaStr = '--', diaStr = '--';
    if (saleInfo.ultimaFecha) {
      const d = new Date(saleInfo.ultimaFecha);
      if (!isNaN(d.getTime())) {
        fechaStr = d.toLocaleDateString('es-AR');
        horaStr = d.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
        diaStr = d.toLocaleDateString('es-AR', { weekday: 'short' });
      }
    }

    return `
      <tr>
        <td>${item.categoria || 'Sin categoría'}</td>
        <td><strong>${item.nombre}</strong></td>
        <td>${item.codigo}</td>
        <td style="text-align:center;">${saleInfo.cantidadVendida}</td>
        <td style="text-align:center;">${item.cantidad || item.stock || 0}</td>
        <td style="text-align:right;">$${parseFloat(item.precio || 0).toLocaleString('es-AR', { minimumFractionDigits: 2 })}</td>
        <td style="text-align:right; font-weight:bold; color:#2e7d32;">$${saleInfo.totalGanancia.toLocaleString('es-AR', { minimumFractionDigits: 2 })}</td>
        <td>${fechaStr}</td>
        <td>${horaStr}</td>
        <td>${diaStr}</td>
      </tr>
    `;
  }).join('');
}

function renderSalesHistory() {
  const tbody = document.getElementById('salesHistoryBody');
  const countEl = document.getElementById('salesCount');
  if (!tbody) return;

  const salesList = [...state.sales].reverse();
  if (countEl) countEl.textContent = `${salesList.length} venta${salesList.length === 1 ? '' : 's'}`;

  if (salesList.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;">No hay ventas registradas.</td></tr>';
    return;
  }

  tbody.innerHTML = salesList.map(sale => {
    const d = new Date(sale.fecha || Date.now());
    const fecha = !isNaN(d.getTime()) ? d.toLocaleDateString('es-AR') : '--';
    const hora = !isNaN(d.getTime()) ? d.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' }) : '--';
    const dia = !isNaN(d.getTime()) ? d.toLocaleDateString('es-AR', { weekday: 'long' }) : '--';

    const prodsSummary = (sale.productos || []).map(p => `${p.nombre} (${p.cantidad}u)`).join(', ');
    const total = parseFloat(sale.total || sale.dinero || 0);

    return `
      <tr>
        <td>${fecha}</td>
        <td>${hora}</td>
        <td>${dia}</td>
        <td><small>${prodsSummary}</small></td>
        <td style="font-weight:bold; text-align:right;">$${total.toLocaleString('es-AR', { minimumFractionDigits: 2 })}</td>
        <td style="text-align:center;">
          <button class="btn-action edit" onclick="abrirModalEdicion('${sale.id}')" title="Editar venta">✏️</button>
          <button class="btn-action delete" onclick="eliminarVentaDirecto('${sale.id}')" title="Eliminar y devolver stock">🗑</button>
        </td>
      </tr>
    `;
  }).join('');
}

function renderChart() {
  const svg = document.getElementById('lineChart');
  if (!svg) return;

  const filteredSales = filterSalesByPeriod(state.sales, state.activePeriod);
  
  // Agrupar ventas por intervalos (últimos 7 puntos de datos)
  const pointsCount = 7;
  const soldData = new Array(pointsCount).fill(0);
  const revenueData = new Array(pointsCount).fill(0);

  filteredSales.forEach((sale, idx) => {
    const slot = idx % pointsCount;
    const total = parseFloat(sale.total || sale.dinero || 0);
    const qty = (sale.productos || []).reduce((acc, p) => acc + parseInt(p.cantidad || 0, 10), 0);
    soldData[slot] += qty;
    revenueData[slot] += total;
  });

  const maxSold = Math.max(...soldData, 5);
  const maxRev = Math.max(...revenueData, 1000);

  const width = 640;
  const height = 240;
  const padding = 30;

  const getPoints = (arr, maxVal) => {
    return arr.map((val, i) => {
      const x = padding + (i / (pointsCount - 1)) * (width - 2 * padding);
      const y = height - padding - (val / maxVal) * (height - 2 * padding);
      return `${x},${y}`;
    }).join(' ');
  };

  const soldPoints = getPoints(soldData, maxSold);
  const revPoints = getPoints(revenueData, maxRev);

  svg.innerHTML = `
    <!-- Grid -->
    <line x1="${padding}" y1="${height - padding}" x2="${width - padding}" y2="${height - padding}" stroke="#e2e8f0" stroke-width="1.5" />
    <line x1="${padding}" y1="${padding}" x2="${width - padding}" y2="${padding}" stroke="#f1f5f9" stroke-dasharray="4" />
    <line x1="${padding}" y1="${height / 2}" x2="${width - padding}" y2="${height / 2}" stroke="#f1f5f9" stroke-dasharray="4" />
    
    <!-- Línea de Vendidos -->
    <polyline fill="none" stroke="#2563eb" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" points="${soldPoints}" />
    
    <!-- Línea de Ganancia -->
    <polyline fill="none" stroke="#e0316d" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" points="${revPoints}" />
  `;
}

function render() {
  renderControls();
  renderMetrics();
  renderProducts();
  renderInventoryTable();
  renderSalesHistory();
  renderChart();
  updateClock();
}

async function loadData() {
  try {
    const invData = await API.getInventario();
    state.inventory = Array.isArray(invData) ? invData : [];
  } catch (e) {
    console.error('Error cargando inventario:', e);
  }

  try {
    const salesData = await API.getVentas();
    state.sales = Array.isArray(salesData) ? salesData : [];
  } catch (e) {
    console.error('Error cargando ventas:', e);
  }

  render();
}

// Modal de edición de ventas
function abrirModalEdicion(saleId) {
  const sale = state.sales.find(s => String(s.id) === String(saleId));
  if (!sale) return;

  const modal = document.getElementById('editSaleModal');
  const saleIdInput = document.getElementById('edit-sale-id');
  const fechaInput = document.getElementById('edit-sale-fecha');
  const container = document.getElementById('edit-sale-products-container');

  if (saleIdInput) saleIdInput.value = sale.id;
  if (fechaInput) {
    const d = new Date(sale.fecha || Date.now());
    fechaInput.value = !isNaN(d.getTime()) ? d.toLocaleString('es-AR') : sale.fecha;
  }

  if (container) {
    container.innerHTML = '';
    (sale.productos || []).forEach((prod, idx) => {
      const invItem = state.inventory.find(i => String(i.codigo).toLowerCase() === String(prod.codigo).toLowerCase());
      const stockDisponible = (invItem ? parseInt(invItem.cantidad ?? invItem.stock ?? 0, 10) : 0) + parseInt(prod.cantidad || 0, 10);

      const row = document.createElement('div');
      row.className = 'edit-product-row';
      row.innerHTML = `
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
          <strong>${prod.nombre}</strong>
          <small>Cód: ${prod.codigo} &bull; Max disp: ${stockDisponible}</small>
        </div>
        <div style="display:flex; gap:10px; align-items:center;">
          <label>Cantidad:</label>
          <input type="number" min="1" max="${stockDisponible}" value="${prod.cantidad}" class="edit-qty-input" data-index="${idx}" data-price="${prod.precio}" style="width:80px; padding:6px; border:1px solid #ccc; border-radius:4px;">
          <span>Precio unit: $${parseFloat(prod.precio || 0).toLocaleString('es-AR', { minimumFractionDigits: 2 })}</span>
        </div>
      `;
      container.appendChild(row);
    });

    recalculateEditTotal();
  }

  if (modal) modal.style.display = 'flex';
}

function recalculateEditTotal() {
  const totalEl = document.getElementById('edit-sale-total');
  const qtyInputs = document.querySelectorAll('.edit-qty-input');
  let sum = 0;

  qtyInputs.forEach(input => {
    const qty = parseInt(input.value || 0, 10);
    const price = parseFloat(input.dataset.price || 0);
    sum += (qty * price);
  });

  if (totalEl) totalEl.textContent = `$${sum.toLocaleString('es-AR', { minimumFractionDigits: 2 })}`;
}

// Guardar cambios en la venta editada
const editSaleForm = document.getElementById('edit-sale-form');
if (editSaleForm) {
  editSaleForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const saleId = document.getElementById('edit-sale-id').value;
    const sale = state.sales.find(s => String(s.id) === String(saleId));
    if (!sale) return;

    const qtyInputs = document.querySelectorAll('.edit-qty-input');
    const tempInventory = JSON.parse(JSON.stringify(state.inventory));
    const nuevosProductos = [];
    let nuevoTotal = 0;

    for (const input of qtyInputs) {
      const idx = parseInt(input.dataset.index, 10);
      const prodOriginal = sale.productos[idx];
      const nuevaCant = parseInt(input.value, 10);
      const diff = nuevaCant - parseInt(prodOriginal.cantidad, 10);

      const invItem = tempInventory.find(i => String(i.codigo).toLowerCase() === String(prodOriginal.codigo).toLowerCase());
      if (invItem) {
        const stockActual = parseInt(invItem.cantidad ?? invItem.stock ?? 0, 10);
        if (stockActual - diff < 0) {
          alert(`❌ Stock insuficiente para "${prodOriginal.nombre}".`);
          return;
        }
        invItem.cantidad = stockActual - diff;
        invItem.total = invItem.precio * invItem.cantidad;
      }

      nuevosProductos.push({
        ...prodOriginal,
        cantidad: nuevaCant
      });
      nuevoTotal += (nuevaCant * parseFloat(prodOriginal.precio || 0));
    }

    // Actualizar datos
    state.inventory = tempInventory;
    sale.productos = nuevosProductos;
    sale.total = nuevoTotal;
    sale.dinero = nuevoTotal;

    try {
      await API.saveInventario(state.inventory);
      await API.saveVentas(state.sales);

      localStorage.setItem('inventarioUpdated', Date.now().toString());
      window.dispatchEvent(new Event('inventario-updated'));

      document.getElementById('editSaleModal').style.display = 'none';
      alert('✅ Venta modificada correctamente.');
      render();
    } catch (err) {
      alert(`❌ Error al guardar cambios: ${err.message}`);
    }
  });
}

// Eliminar venta directa
async function eliminarVentaDirecto(saleId) {
  const sale = state.sales.find(s => String(s.id) === String(saleId));
  if (!sale) return;

  if (!confirm('¿Estás seguro de que querés eliminar esta venta? El stock se devolverá automáticamente al inventario.')) {
    return;
  }

  // Devolver el stock
  (sale.productos || []).forEach(prod => {
    const invItem = state.inventory.find(i => String(i.codigo).toLowerCase() === String(prod.codigo).toLowerCase());
    if (invItem) {
      const cantActual = parseInt(invItem.cantidad ?? invItem.stock ?? 0, 10);
      invItem.cantidad = cantActual + parseInt(prod.cantidad || 0, 10);
      invItem.total = invItem.precio * invItem.cantidad;
    }
  });

  state.sales = state.sales.filter(s => String(s.id) !== String(saleId));

  try {
    await API.saveInventario(state.inventory);
    await API.saveVentas(state.sales);

    localStorage.setItem('inventarioUpdated', Date.now().toString());
    window.dispatchEvent(new Event('inventario-updated'));

    const modal = document.getElementById('editSaleModal');
    if (modal) modal.style.display = 'none';

    alert('✅ Venta eliminada y stock restablecido.');
    render();
  } catch (err) {
    alert(`❌ Error al eliminar venta: ${err.message}`);
  }
}

// Cerrar modal
const closeModalBtn = document.getElementById('closeModalBtn');
if (closeModalBtn) {
  closeModalBtn.addEventListener('click', () => {
    const modal = document.getElementById('editSaleModal');
    if (modal) modal.style.display = 'none';
  });
}

// Escuchar cambios de cantidad en el modal para actualizar total
document.addEventListener('input', (e) => {
  if (e.target && e.target.classList.contains('edit-qty-input')) {
    recalculateEditTotal();
  }
});

// Exportar global
window.setCategory = setCategory;
window.setPeriod = setPeriod;
window.abrirModalEdicion = abrirModalEdicion;
window.eliminarVentaDirecto = eliminarVentaDirecto;

document.addEventListener('DOMContentLoaded', () => {
  loadData();
  setInterval(updateClock, 1000);
});

window.addEventListener('storage', (e) => {
  if (e.key === 'inventarioUpdated') {
    loadData();
  }
});

window.addEventListener('inventario-updated', () => {
  loadData();
});
