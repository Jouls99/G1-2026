const state = {
  inventory: [],
  sales: [],
  historySales: null,
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

// Escapa datos variables antes de interpolarlos en las tablas y tarjetas del informe.
function escapeHtml(str) {
  if (typeof str !== 'string') return String(str ?? '');
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// Define rótulos y límites temporales comunes para agrupar ventas por día, semana o mes.
function getPeriodLabels(period) {
  switch (period) {
    case 'semanal':
      return ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
    case 'mensual':
      return ['Sem 1', 'Sem 2', 'Sem 3', 'Sem 4', 'Sem 5'];
    case 'diario':
    default:
      return ['Hoy'];
  }
}

// Devuelve los límites semiabiertos del período para que todos los agregados usen el mismo rango.
function getPeriodDateRange(period, now = new Date()) {
  const start = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  let end;

  if (period === 'diario') {
    end = new Date(start);
    end.setDate(end.getDate() + 1);
  } else if (period === 'semanal') {
    start.setDate(start.getDate() - ((start.getDay() + 6) % 7));
    end = new Date(start);
    end.setDate(end.getDate() + 7);
  } else {
    start.setDate(1);
    end = new Date(start.getFullYear(), start.getMonth() + 1, 1);
  }

  return { start, end };
}

// Relaciona cada fecha con la posición de su serie y cada venta con la categoría del catálogo.
function getSeriesIndex(date, period) {
  switch (period) {
    case 'semanal':
      return (date.getDay() + 6) % 7;
    case 'mensual':
      return Math.min(Math.floor((date.getDate() - 1) / 7), 4);
    case 'diario':
    default:
      return 0;
  }
}

// Resuelve la categoría histórica desde la venta o, si falta, mediante el código del producto.
function getProductCategory(product) {
  const productCode = String(product.codigo || '').trim().toLowerCase();
  if (!productCode) return product.categoria || null;
  const inventoryItem = state.inventory.find((entry) =>
    String(entry.codigo || '').trim().toLowerCase() === productCode
  );
  return product.categoria || inventoryItem?.categoria || null;
}

// Agrega unidades e importes vendidos por categoría y período para alimentar KPIs y gráfico.
function buildSeries(category, period) {
  const labels = getPeriodLabels(period);
  const { start, end } = getPeriodDateRange(period);
  const series = {
    labels,
    sold: labels.map(() => 0),
    revenue: labels.map(() => 0),
    summary: labels.map(() => 0)
  };

  state.sales.forEach((sale) => {
    const date = new Date(sale.fecha);
    if (Number.isNaN(date.getTime()) || date < start || date >= end) return;

    const index = getSeriesIndex(date, period);
    (sale.productos || []).filter((product) => getProductCategory(product) === category)
      .forEach((product) => {
        const quantity = Number(product.cantidad) || 0;
        const revenue = (Number(product.precio) || 0) * quantity;
        series.sold[index] += quantity;
        series.revenue[index] += revenue;
        series.summary[index] += revenue;
      });
  });

  return series;
}

// Construye los controles de categoría y período a partir del inventario y las ventas existentes.
function renderCategoryButtons() {
  const categories = [...new Set([
    ...state.inventory.map((item) => item.categoria),
    ...state.sales.flatMap((sale) => (sale.productos || []).map((product) => product.categoria))
  ].filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es'));
  const container = document.getElementById('categoryButtons');
  if (!container) return;
  container.innerHTML = '';

  if (categories.length && !categories.includes(state.category)) {
    state.category = categories[0];
  }

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

// Permite cambiar el rango de agregación y vuelve a dibujar el dashboard al seleccionarlo.
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

// Actualiza totales de venta y valorización de stock para la categoría/período seleccionado.
function renderMetrics(series) {
  const sold = series.sold.reduce((total, value) => total + value, 0);
  const revenue = series.revenue.reduce((total, value) => total + value, 0);
  const summary = series.summary.reduce((total, value) => total + value, 0);
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

function renderLoadErrors(errors) {
  const notice = document.getElementById('dashboardLoadError');
  if (!notice) return;
  notice.textContent = errors.join(' ');
  notice.hidden = errors.length === 0;
}

// Genera el SVG de las series de ventas e importes, usando las etiquetas de cada período.
// Construye los ejes y puntos SVG usando los valores agregados de ventas e ingresos.
function buildChartMarkup(series) {
  const width = 640;
  const height = 280;
  const padding = 36;
  const maxValue = Math.max(...series.sold, ...series.revenue, 1);
  const stepX = (width - padding * 2) / (series.labels.length - 1 || 1);

  const y = (value) => padding + ((maxValue - value) / maxValue) * (height - padding * 2);
  const soldPoints = series.sold.map((value, index) => `${padding + index * stepX},${y(value)}`).join(' ');
  const revenuePoints = series.revenue.map((value, index) => `${padding + index * stepX},${y(value)}`).join(' ');

  return `
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

// Inserta el SVG recién calculado en el espacio gráfico de la vista.
function renderChart(series) {
  const svg = document.getElementById('lineChart');
  if (!svg) return;
  const width = 640;
  const height = 280;
  svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
  svg.innerHTML = buildChartMarkup(series);
}

// Suma unidades vendidas por código y opcionalmente restringe el conteo al período activo.
function buildSoldSummary(period = null) {
  const soldCounts = {};
  const range = period ? getPeriodDateRange(period) : null;

  state.sales.forEach((sale) => {
    const saleDate = new Date(sale.fecha);
    if (range && (Number.isNaN(saleDate.getTime()) || saleDate < range.start || saleDate >= range.end)) return;
    (sale.productos || []).forEach((producto) => {
      const key = String(producto.codigo || '').toLowerCase();
      soldCounts[key] = (soldCounts[key] || 0) + (producto.cantidad || 0);
    });
  });

  return soldCounts;
}

// Busca la última venta por código para completar la fecha/hora mostrada en el resumen.
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

// Renderiza productos de la categoría y muestra sus ventas y existencias disponibles.
function renderProducts() {
  const list = document.getElementById('productList');
  if (!list) return;
  const soldSummary = buildSoldSummary(state.period);
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

// Completa el resumen tabular con existencias, valorización y fecha de última venta por artículo.
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
        <td>${escapeHtml(item.categoria)}</td>
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

// Presenta ventas recientes o el historial filtrado, con acciones solo si el permiso lo permite.
function renderSalesHistory() {
  const tbody = document.getElementById('salesHistoryBody');
  const count = document.getElementById('salesCount');
  if (!tbody) return;

  const sourceSales = state.historySales || state.sales;
  const sales = [...sourceSales].sort((a, b) => new Date(b.fecha) - new Date(a.fecha));

  if (count) count.textContent = `${sales.length} ventas`;

  if (!sales.length) {
    tbody.innerHTML = '<tr><td colspan="6">No hay ventas registradas todavía.</td></tr>';
    return;
  }

  tbody.innerHTML = sales.map((sale) => {
    const date = new Date(sale.fecha);
    const productoText = (sale.productos || []).map((producto) => {
      const metadata = [
        producto.sub_nombre,
        producto.subcategoria,
        producto.categoria,
        producto.marca
      ].filter(Boolean).map(escapeHtml).join(' · ');
      return `<div class="sale-product-detail">
        ${producto.nombre ? `<strong>${escapeHtml(producto.nombre)}</strong>` : ''}
        ${metadata ? `<small>${metadata}</small>` : ''}
        <span>× ${Number(producto.cantidad) || 0}</span>
      </div>`;
    }).join('');
    return `
      <tr>
        <td>${date.toLocaleDateString('es-AR')}</td>
        <td>${date.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })}</td>
        <td>${date.toLocaleDateString('es-AR', { weekday: 'long' })}</td>
        <td>${productoText}</td>
        <td>${currencyFormatter.format(sale.total || 0)}</td>
        <td>
          ${puedeModificarInforme && !sale.archivada ? `
            <div style="display:flex; gap:6px; justify-content:center; align-items:center;">
              <button type="button" class="btn-edit-action" onclick="abrirModalEdicion('${sale.id}')" title="Editar venta">✏️ Editar</button>
              <button type="button" class="btn-delete-action" onclick="confirmarEliminarVenta('${sale.id}')" title="Eliminar venta">🗑️ Eliminar</button>
            </div>
          ` : `<span style="color:#6b7280; font-size:0.85rem;">${sale.archivada ? 'Archivada' : 'Solo lectura'}</span>`}
        </td>
      </tr>
    `;
  }).join('');
}

// Provee fechas locales de inicio de semana para solicitar históricos dentro del período retenido.
function getWeekStartDate(date = new Date()) {
  const start = new Date(date.getFullYear(), date.getMonth(), date.getDate());
  start.setDate(start.getDate() - ((start.getDay() + 6) % 7));
  return start;
}

// Serializa una fecha local como AAAA-MM-DD, formato requerido por el filtro de la API.
function formatLocalDate(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

// Configura filtros semanales/diarios exclusivos de usuarios con capacidad administrativa.
function configureHistoryFilters() {
  if (!usuarioEsAdminInforme) return;
  const weekSelect = document.getElementById('historyWeekSelect');
  const dateSelect = document.getElementById('historyDateSelect');
  if (!weekSelect || !dateSelect) return;

  const currentWeek = getWeekStartDate();
  const oldestWeek = new Date(currentWeek);
  oldestWeek.setDate(oldestWeek.getDate() - 21);
  weekSelect.innerHTML = '';
  for (let offset = 0; offset < 4; offset += 1) {
    const weekStart = new Date(currentWeek);
    weekStart.setDate(weekStart.getDate() - offset * 7);
    const option = document.createElement('option');
    option.value = formatLocalDate(weekStart);
    option.textContent = offset === 0 ? 'Esta semana' : offset === 1 ? 'Semana pasada' : `Hace ${offset} semanas`;
    weekSelect.appendChild(option);
  }
  dateSelect.min = formatLocalDate(oldestWeek);
  dateSelect.max = formatLocalDate(new Date());

  weekSelect.addEventListener('change', () => loadHistorySales('semana', weekSelect.value));
  dateSelect.addEventListener('change', () => {
    if (dateSelect.value) loadHistorySales('fecha', dateSelect.value);
  });
}

async function loadHistorySales(filter, value) {
  const query = filter === 'fecha' ? `fecha=${encodeURIComponent(value)}` : `semana=${encodeURIComponent(value)}`;
  try {
    const response = await fetch(`api/ventas.php?${query}`, { cache: 'no-store' });
    const result = await response.json();
    if (!response.ok) {
      throw new Error(result.message || `No se pudo cargar el historial (HTTP ${response.status}).`);
    }
    if (!Array.isArray(result)) throw new Error('La respuesta del historial no tiene un formato válido.');
    state.historySales = result;
    renderSalesHistory();
    renderLoadErrors([]);
  } catch (error) {
    renderLoadErrors([error.message || 'No se pudo cargar el historial de ventas.']);
  }
}

// Recarga el mismo rango histórico seleccionado después de editar o eliminar una venta.
function refreshSelectedHistory() {
  if (!usuarioEsAdminInforme) return;
  const selectedDate = document.getElementById('historyDateSelect')?.value;
  if (selectedDate) {
    loadHistorySales('fecha', selectedDate);
    return;
  }
  const selectedWeek = document.getElementById('historyWeekSelect')?.value;
  if (selectedWeek) loadHistorySales('semana', selectedWeek);
}

// Actualiza la fecha/hora/día visibles sin requerir una nueva consulta al servidor.
function updateClock() {
  const now = new Date();
  const elDate = document.getElementById('liveDate');
  const elTime = document.getElementById('liveTime');
  const elDay = document.getElementById('liveDay');
  if (elDate) elDate.textContent = now.toLocaleDateString('es-AR');
  if (elTime) elTime.textContent = now.toLocaleTimeString('es-AR');
  if (elDay) elDay.textContent = now.toLocaleDateString('es-AR', { weekday: 'long' });
}

// Prepara un informe imprimible agrupando los renglones de venta del período por categoría.
function renderPdfReport() {
  const container = document.getElementById('reportPdfContent');
  if (!container) return;

  const { start, end } = getPeriodDateRange(state.period);
  const period = state.period.charAt(0).toUpperCase() + state.period.slice(1);
  const rangeText = state.period === 'diario'
    ? start.toLocaleDateString('es-AR')
    : `${start.toLocaleDateString('es-AR')} - ${new Date(end.getTime() - 1).toLocaleDateString('es-AR')}`;
  const salesInRange = state.sales.filter((sale) => {
    const saleDate = new Date(sale.fecha);
    return !Number.isNaN(saleDate.getTime()) && saleDate >= start && saleDate < end;
  });
  const pdfCategoryName = (category) => {
    const normalized = String(category || '').trim().toLocaleLowerCase('es');
    return !normalized || normalized === 'general' ? 'General' : category;
  };
  const categories = new Set(state.inventory.map((item) => item.categoria).filter(Boolean));
  const productsByCategory = new Map();
  categories.forEach((category) => productsByCategory.set(category, []));

  salesInRange.forEach((sale) => {
    (sale.productos || []).forEach((product) => {
      const inventoryItem = state.inventory.find((item) =>
        String(item.codigo || '').trim().toLowerCase() === String(product.codigo || '').trim().toLowerCase()
      );
      const category = pdfCategoryName(product.categoria || inventoryItem?.categoria);
      categories.add(category);
      if (!productsByCategory.has(category)) productsByCategory.set(category, []);
      productsByCategory.get(category).push({ sale, product });
    });
  });
  const sortedCategories = [...categories].sort((a, b) => a.localeCompare(b, 'es'));

  container.innerHTML = `
    <header class="pdf-report-heading">
      <h2>Historial de ventas · ${escapeHtml(period)}</h2>
      <p>${escapeHtml(rangeText)}</p>
    </header>
    ${sortedCategories.length ? sortedCategories.map((category) => {
      const entries = productsByCategory.get(category)
        .sort((a, b) => new Date(b.sale.fecha) - new Date(a.sale.fecha));
      return `
        <article class="pdf-category-report">
          <h3>${escapeHtml(category)}</h3>
          <table class="pdf-sales-table">
            <thead><tr>
              <th>Fecha</th><th>Hora</th><th>Día</th><th>Producto</th>
              <th>Nombre secundario</th><th>Subcategoría</th><th>Marca</th>
              <th>Cantidad</th><th>Total</th>
            </tr></thead>
            <tbody>${entries.length ? entries.map(({ sale, product }) => {
              const date = new Date(sale.fecha);
              const quantity = Number(product.cantidad) || 0;
              const itemTotal = quantity * (Number(product.precio) || 0);
              return `<tr>
                <td>${date.toLocaleDateString('es-AR')}</td>
                <td>${date.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })}</td>
                <td>${date.toLocaleDateString('es-AR', { weekday: 'long' })}</td>
                <td>${escapeHtml(product.nombre || 'Nombre no disponible')}</td>
                <td>${escapeHtml(product.sub_nombre || '-')}</td>
                <td>${escapeHtml(product.subcategoria || '-')}</td>
                <td>${escapeHtml(product.marca || '-')}</td>
                <td>${quantity}</td>
                <td>${currencyFormatter.format(itemTotal)}</td>
              </tr>`;
            }).join('') : '<tr><td colspan="9">No hay ventas registradas para esta categoría en el período.</td></tr>'}</tbody>
          </table>
        </article>
      `;
    }).join('') : '<p>No hay categorías para mostrar en el informe.</p>'}
  `;
}

function exportReportPdf() {
  const originalTitle = document.title;
  const period = state.period.charAt(0).toUpperCase() + state.period.slice(1);
  renderPdfReport();
  document.title = `Historial de ventas ${period}`;
  window.addEventListener('afterprint', () => {
    document.title = originalTitle;
  }, { once: true });
  window.print();
}

// Recalcula y actualiza todas las regiones visuales dependientes del estado de la página.
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

// Carga inventario y ventas en paralelo, conserva errores parciales y representa el estado combinado.
async function loadInventory() {
  const errors = [];
  try {
    const response = await fetch('api/inventario.php', { cache: 'no-store' });
    if (!response.ok) throw new Error(`No se pudo cargar el inventario (HTTP ${response.status}).`);
    const data = await response.json();
    if (!Array.isArray(data)) throw new Error('La respuesta del inventario no tiene un formato válido.');
    state.inventory = data;
  } catch (error) {
    state.inventory = [];
    errors.push(error.message || 'No se pudo cargar el inventario.');
  }

  try {
    const salesResponse = await fetch('api/ventas.php', { cache: 'no-store' });
    if (!salesResponse.ok) throw new Error(`No se pudieron cargar las ventas (HTTP ${salesResponse.status}).`);
    const salesData = await salesResponse.json();
    if (!Array.isArray(salesData)) throw new Error('La respuesta de ventas no tiene un formato válido.');
    state.sales = salesData;
  } catch (error) {
    state.sales = [];
    errors.push(error.message || 'No se pudieron cargar las ventas.');
  }

  render();
  renderLoadErrors(errors);
}

// Engancha controles de la vista y carga inventario/ventas al iniciar o al recibir cambios externos.
window.addEventListener('DOMContentLoaded', () => {
  document.getElementById('exportReportPdfBtn')?.addEventListener('click', exportReportPdf);
  configureHistoryFilters();
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
  if (usuarioEsAdminInforme) {
    const currentWeek = formatLocalDate(getWeekStartDate());
    loadHistorySales('semana', currentWeek);
  }
  setInterval(updateClock, 1000);
});
// Mantiene el dashboard al día cuando otra pestaña cambia inventario o se registra una venta.
window.addEventListener('storage', (event) => {
  if (event.key === 'inventarioUpdated') {
    loadInventory();
  }
});
// Sincroniza la vista con actualizaciones publicadas por los otros módulos de la misma aplicación.
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

// Gestiona apertura/cierre y autentica en el servidor el cambio de cuenta del Super Admin.
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
// Pinta la venta elegida y limita cantidades según el stock que volvería al inventario.
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
// Los controles y el clic fuera del cuadro cierran el editor sin enviar cambios.
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
// Valida las cantidades editadas y envía la composición al endpoint de actualización de ventas.
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
    refreshSelectedHistory();

    localStorage.setItem('inventarioUpdated', Date.now().toString());
    window.dispatchEvent(new Event('inventario-updated'));
  } catch (error) {
    alert(`❌ Error al guardar: ${error.message}`);
  }
});

// Eliminar venta directo vía API
// Elimina la factura persistida y actualiza vistas e inventario tras la respuesta de MySQL.
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
    refreshSelectedHistory();

    localStorage.setItem('inventarioUpdated', Date.now().toString());
    window.dispatchEvent(new Event('inventario-updated'));
  } catch (error) {
    alert(`❌ Error al eliminar: ${error.message}`);
  }
}

// Confirmar y eliminar venta desde tabla o botón
// Pide confirmación antes de delegar la baja en el mismo flujo de eliminación de la API.
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

// Publica estas acciones para los botones dinámicos creados al renderizar el historial.
window.abrirModalEdicion = abrirModalEdicion;
window.confirmarEliminarVenta = confirmarEliminarVenta;
window.eliminarVentaDirecto = eliminarVentaDirecto;
