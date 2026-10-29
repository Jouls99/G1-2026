/**
 * Panel General y Búsqueda por Categorías
 * 
 * Contiene el estado central, formateadores, filtros de categorías y períodos,
 * métricas clave, gráfico SVG de tendencias, reloj y carga general de datos.
 */

// Estado central compartido del dashboard
const state = window.state = window.state || {
  inventory: [],
  sales: [],
  historySales: null,
  category: 'Maquillaje',
  period: 'diario',
  showTotalGeneral: false
};

// Permisos del usuario obtenidos del servidor
const puedeModificarInforme = window.puedeModificarInforme === true;
const usuarioEsAdminInforme = window.usuarioEsAdminInforme === true;

// Formateador de moneda en pesos argentinos
const currencyFormatter = window.currencyFormatter || new Intl.NumberFormat('es-AR', {
  style: 'currency',
  currency: 'ARS',
  maximumFractionDigits: 0
});
window.currencyFormatter = currencyFormatter;

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
window.escapeHtml = escapeHtml;

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
window.getPeriodLabels = getPeriodLabels;

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
window.getPeriodDateRange = getPeriodDateRange;

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
window.getSeriesIndex = getSeriesIndex;

// Resuelve la categoría histórica desde la venta o, si falta, mediante el código del producto.
function getProductCategory(product) {
  const productCode = String(product.codigo || '').trim().toLowerCase();
  if (!productCode) return product.categoria || null;
  const inventoryItem = state.inventory.find((entry) =>
    String(entry.codigo || '').trim().toLowerCase() === productCode
  );
  return product.categoria || inventoryItem?.categoria || null;
}
window.getProductCategory = getProductCategory;

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
window.buildSeries = buildSeries;
window.getSeries = buildSeries;

// Construye los controles de categoría a partir del inventario y las ventas existentes.
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
window.renderCategoryButtons = renderCategoryButtons;

// Permite cambiar el rango de agregación (diario, semanal, mensual) y vuelve a dibujar el dashboard.
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
window.renderPeriodButtons = renderPeriodButtons;

// Actualiza totales de venta y valorización de stock para la categoría/período seleccionado.
function renderMetrics(series) {
  let dashboardMetricsHeader = document.getElementById('dashboardMetricsHeader');
  if (!dashboardMetricsHeader) {
    const metricsGrid = document.querySelector('.metrics-grid');
    if (metricsGrid) {
      dashboardMetricsHeader = document.createElement('div');
      dashboardMetricsHeader.id = 'dashboardMetricsHeader';
      dashboardMetricsHeader.style.marginBottom = '10px';
      metricsGrid.parentNode.insertBefore(dashboardMetricsHeader, metricsGrid);
    }
  }

  // Checkbox para activar/desactivar el total general
  let showTotalGeneral = state.showTotalGeneral ?? false;
  let totalGeneralEl = document.getElementById('showTotalGeneral');
  if (!totalGeneralEl) {
    const metricsContainer = document.getElementById('dashboardMetricsHeader');
    if (metricsContainer) {
      const label = document.createElement('label');
      label.style.display = 'inline-flex';
      label.style.alignItems = 'center';
      label.style.gap = '0.5em';
      totalGeneralEl = document.createElement('input');
      totalGeneralEl.type = 'checkbox';
      totalGeneralEl.id = 'showTotalGeneral';
      totalGeneralEl.checked = !!showTotalGeneral;
      totalGeneralEl.addEventListener('change', (e) => {
        state.showTotalGeneral = e.target.checked;
        render();
      });
      label.appendChild(totalGeneralEl);
      label.appendChild(document.createTextNode('Mostrar total general del período'));
      metricsContainer.appendChild(label);
    }
  } else {
    totalGeneralEl.checked = !!state.showTotalGeneral;
  }

  // Si está activada la opción, muestra el total general del período
  let totalGeneralSummary = null;
  if (state.showTotalGeneral) {
    const categories = [...new Set([
      ...state.inventory.map((item) => item.categoria),
      ...state.sales.flatMap((sale) => (sale.productos || []).map((product) => product.categoria))
    ].filter(Boolean))];

    totalGeneralSummary = 0;
    categories.forEach(cat => {
      let ser = buildSeries(cat, state.period);
      if (ser && ser.summary) {
        totalGeneralSummary += ser.summary.reduce((t, v) => t + v, 0);
      }
    });

    let totalGeneralMetric = document.getElementById('metricTotalGeneral');
    if (!totalGeneralMetric) {
      const elSum = document.getElementById('metricSummary');
      if (elSum && elSum.parentElement) {
        totalGeneralMetric = document.createElement('div');
        totalGeneralMetric.id = 'metricTotalGeneral';
        totalGeneralMetric.style.marginTop = '0.5em';
        totalGeneralMetric.style.fontWeight = 'bold';
        elSum.parentElement.appendChild(totalGeneralMetric);
      }
    }
    if (totalGeneralMetric)
      totalGeneralMetric.textContent = 'Total general ganancia: ' + currencyFormatter.format(totalGeneralSummary);
  } else {
    const totalGeneralMetric = document.getElementById('metricTotalGeneral');
    if (totalGeneralMetric) totalGeneralMetric.textContent = '';
  }

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
window.renderMetrics = renderMetrics;

// Muestra u oculta mensajes de error al consultar la API
function renderLoadErrors(errors) {
  const notice = document.getElementById('dashboardLoadError');
  if (!notice) return;
  notice.textContent = errors.join(' ');
  notice.hidden = errors.length === 0;
}
window.renderLoadErrors = renderLoadErrors;

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
window.buildChartMarkup = buildChartMarkup;

// Inserta el SVG recién calculado en el espacio gráfico de la vista.
function renderChart(series) {
  const svg = document.getElementById('lineChart');
  if (!svg) return;
  const width = 640;
  const height = 280;
  svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
  svg.innerHTML = buildChartMarkup(series);
}
window.renderChart = renderChart;

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
window.updateClock = updateClock;

// Recalcula y actualiza todas las regiones visuales dependientes del estado de la página.
function render() {
  renderCategoryButtons();
  renderPeriodButtons();
  const series = buildSeries(state.category, state.period);
  renderMetrics(series);
  renderChart(series);
  if (typeof window.renderProducts === 'function') window.renderProducts();
  if (typeof window.renderInventoryTable === 'function') window.renderInventoryTable();
  if (typeof window.renderSalesHistory === 'function') window.renderSalesHistory();
  updateClock();
}
window.render = render;

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
window.loadInventory = loadInventory;

// Engancha controles de la vista y carga inventario/ventas al iniciar o al recibir cambios externos.
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
  if (usuarioEsAdminInforme && typeof window.loadHistorySales === 'function' && typeof window.formatLocalDate === 'function') {
    const today = window.formatLocalDate(new Date());
    window.loadHistorySales('fecha', today);
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
