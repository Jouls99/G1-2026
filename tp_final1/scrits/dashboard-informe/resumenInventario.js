/**
 * Resumen de Inventario y Reportes
 * 
 * Cálculo de ventas por producto, stock disponible, tabla resumen de inventario,
 * listado de existencias por categoría y exportación/impresión de informe PDF.
 */

// Referencia al estado compartido del dashboard
var state = window.state = window.state || {
  inventory: [],
  sales: [],
  historySales: null,
  category: 'Maquillaje',
  period: 'diario'
};

// Formateador de moneda compartido
var currencyFormatter = window.currencyFormatter || new Intl.NumberFormat('es-AR', {
  style: 'currency',
  currency: 'ARS',
  maximumFractionDigits: 0
});

// Suma unidades vendidas por código y opcionalmente restringe el conteo al período activo.
function buildSoldSummary(period = null) {
  const soldCounts = {};
  const range = (period && typeof window.getPeriodDateRange === 'function')
    ? window.getPeriodDateRange(period)
    : null;

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
window.buildSoldSummary = buildSoldSummary;

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
window.getLastSaleInfo = getLastSaleInfo;

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

  const escapeFn = typeof window.escapeHtml === 'function' ? window.escapeHtml : (str => str ?? '');

  list.innerHTML = items
    .map((item) => {
      const sold = soldSummary[String(item.codigo || '').toLowerCase()] || 0;
      return `
        <li>
          <span>${escapeFn(item.nombre)}</span>
          <strong>${sold} vend.</strong>
          <small>${item.cantidad} disponibles</small>
        </li>
      `;
    })
    .join('');
}
window.renderProducts = renderProducts;

// Completa el resumen tabular con existencias, valorización y fecha de última venta por artículo.
function renderInventoryTable() {
  const soldSummary = buildSoldSummary();
  const tbody = document.getElementById('stockTableBody');
  if (!tbody) return;

  if (!state.inventory.length) {
    tbody.innerHTML = '<tr><td colspan="10">No hay productos cargados en el inventario.</td></tr>';
    return;
  }

  const escapeFn = typeof window.escapeHtml === 'function' ? window.escapeHtml : (str => str ?? '');
  const fmt = window.currencyFormatter || currencyFormatter;

  let totalVendidas = 0;
  let totalDisponibles = 0;
  let totalGanancias = 0;

  const rows = state.inventory.map((item) => {
    const sold = soldSummary[String(item.codigo || '').toLowerCase()] || 0;
    const saleInfo = getLastSaleInfo(item.codigo);
    const available = Number(item.cantidad) || 0;
    const totalValue = item.total != null ? Number(item.total) : (Number(item.precio) || 0) * available;

    totalVendidas += sold;
    totalDisponibles += available;
    totalGanancias += totalValue;

    return `
      <tr>
        <td>${escapeFn(item.categoria)}</td>
        <td>${escapeFn(item.nombre)}</td>
        <td>${escapeFn(item.codigo)}</td>
        <td>${sold}</td>
        <td>${available}</td>
        <td>${fmt.format(item.precio || 0)}</td>
        <td>${fmt.format(totalValue)}</td>
        <td>${saleInfo.fecha}</td>
        <td>${saleInfo.hora}</td>
        <td>${saleInfo.dia}</td>
      </tr>
    `;
  }).join('');

  const totalRow = `
    <tr class="summary-total-row" style="font-weight: bold; background-color: #fdf2f8; border-top: 2px solid #e0558d;">
      <td colspan="3" style="text-align: right; letter-spacing: 1px;">TOTAL:</td>
      <td>${totalVendidas}</td>
      <td>${totalDisponibles}</td>
      <td>-</td>
      <td>${fmt.format(totalGanancias)}</td>
      <td>-</td>
      <td>-</td>
      <td>-</td>
    </tr>
  `;

  tbody.innerHTML = rows + totalRow;
}
window.renderInventoryTable = renderInventoryTable;

// Prepara un informe imprimible agrupando los renglones de venta del período por categoría.
function renderPdfReport() {
  const container = document.getElementById('reportPdfContent');
  if (!container) return;

  const dateRangeFn = typeof window.getPeriodDateRange === 'function'
    ? window.getPeriodDateRange
    : (() => ({ start: new Date(), end: new Date() }));
  const escapeFn = typeof window.escapeHtml === 'function' ? window.escapeHtml : (str => str ?? '');
  const fmt = window.currencyFormatter || currencyFormatter;

  const { start, end } = dateRangeFn(state.period);
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

  // Tabla mensual de productos más vendidos y ganancia general
  let monthlySummaryMarkup = '';
  if (state.period === 'mensual') {
    const productsMap = new Map();
    let grandTotalSold = 0;
    let grandTotalRevenue = 0;

    salesInRange.forEach((sale) => {
      (sale.productos || []).forEach((product) => {
        const code = String(product.codigo || '').trim().toLowerCase();
        const key = code || String(product.nombre || '').trim().toLowerCase();
        const quantity = Number(product.cantidad) || 0;
        const price = Number(product.precio) || 0;
        const total = quantity * price;

        grandTotalSold += quantity;
        grandTotalRevenue += total;

        const inventoryItem = state.inventory.find((item) =>
          String(item.codigo || '').trim().toLowerCase() === code
        );

        if (!productsMap.has(key)) {
          productsMap.set(key, {
            nombre: product.nombre || inventoryItem?.nombre || 'Producto sin nombre',
            codigo: product.codigo || inventoryItem?.codigo || '-',
            categoria: pdfCategoryName(product.categoria || inventoryItem?.categoria),
            marca: product.marca || inventoryItem?.marca || '-',
            cantidad: 0,
            ganancia: 0
          });
        }

        const entry = productsMap.get(key);
        entry.cantidad += quantity;
        entry.ganancia += total;
      });
    });

    // Ordenar del más vendido al menos vendido (ranking de mayor a menor ventas)
    const sortedRanking = Array.from(productsMap.values())
      .sort((a, b) => b.cantidad - a.cantidad || b.ganancia - a.ganancia);

    monthlySummaryMarkup = `
      <article class="pdf-category-report pdf-monthly-summary">
        <h3 style="color: #581c87; border-bottom: 2px solid #e0558d; padding-bottom: 6px; margin-bottom: 12px;">🏆 Resumen mensual: Productos más vendidos y ganancia general</h3>
        <table class="pdf-sales-table">
          <thead>
            <tr style="background-color: #f4d9e7;">
              <th style="width: 35px; text-align: center;">#</th>
              <th>Producto</th>
              <th>Código</th>
              <th>Categoría</th>
              <th>Marca</th>
              <th style="text-align: center;">Cantidad vendida</th>
              <th style="text-align: right;">Total ganancia</th>
            </tr>
          </thead>
          <tbody>
            ${sortedRanking.length ? sortedRanking.map((p, idx) => `
              <tr>
                <td style="font-weight: bold; text-align: center;">${idx + 1}</td>
                <td><strong>${escapeFn(p.nombre)}</strong></td>
                <td>${escapeFn(p.codigo)}</td>
                <td>${escapeFn(p.categoria)}</td>
                <td>${escapeFn(p.marca)}</td>
                <td style="text-align: center; font-weight: bold;">${p.cantidad}</td>
                <td style="text-align: right; font-weight: bold;">${fmt.format(p.ganancia)}</td>
              </tr>
            `).join('') : '<tr><td colspan="7" style="text-align: center;">No hay ventas registradas en este período mensual.</td></tr>'}
          </tbody>
          <tfoot>
            <tr style="font-weight: bold; background-color: #fdf2f8; border-top: 2px solid #e0558d;">
              <td colspan="5" style="text-align: right; letter-spacing: 0.5px;">TOTAL GENERAL DEL MES:</td>
              <td style="text-align: center;">${grandTotalSold}</td>
              <td style="text-align: right;">${fmt.format(grandTotalRevenue)}</td>
            </tr>
          </tfoot>
        </table>
      </article>
    `;
  }

  container.innerHTML = `
    <header class="pdf-report-heading">
      <h2>Historial de ventas · ${escapeFn(period)}</h2>
      <p>${escapeFn(rangeText)}</p>
    </header>
    ${monthlySummaryMarkup}
    ${sortedCategories.length ? sortedCategories.map((category) => {
    const entries = productsByCategory.get(category)
      .sort((a, b) => new Date(b.sale.fecha) - new Date(a.sale.fecha));
    return `
        <article class="pdf-category-report">
          <h3>${escapeFn(category)}</h3>
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
                <td>${escapeFn(product.nombre || 'Nombre no disponible')}</td>
                <td>${escapeFn(product.sub_nombre || '-')}</td>
                <td>${escapeFn(product.subcategoria || '-')}</td>
                <td>${escapeFn(product.marca || '-')}</td>
                <td>${quantity}</td>
                <td>${fmt.format(itemTotal)}</td>
              </tr>`;
    }).join('') : '<tr><td colspan="9">No hay ventas registradas para esta categoría en el período.</td></tr>'}</tbody>
          </table>
        </article>
      `;
  }).join('') : '<p>No hay categorías para mostrar en el informe.</p>'}
  `;
}
window.renderPdfReport = renderPdfReport;

// Ejecuta la impresión del reporte filtrado por período
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
window.exportReportPdf = exportReportPdf;

// Inicializa el botón de exportación a PDF al cargar el documento
document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('exportReportPdfBtn')?.addEventListener('click', exportReportPdf);
});