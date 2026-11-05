/**
 * Panel de Ventas e Historial
 * 
 * Manejo del historial de ventas, filtros temporales (diario/semanal),
 * deshabilitación de ventas diarias con restauración de stock.
 */

(() => {
  // Referencia al estado compartido del dashboard
  const state = window.state = window.state || {
    inventory: [],
    sales: [],
    historySales: null,
    category: 'Maquillaje',
    period: 'diario'
  };

  // Permisos del usuario obtenidos del servidor
  const puedeModificarInforme = window.puedeModificarInforme === true;
  const usuarioEsAdminInforme = window.usuarioEsAdminInforme === true;

  // Provee fechas locales de inicio de semana para solicitar históricos dentro del período retenido.
  function getWeekStartDate(date = new Date()) {
    const start = new Date(date.getFullYear(), date.getMonth(), date.getDate());
    start.setDate(start.getDate() - ((start.getDay() + 6) % 7));
    return start;
  }
  window.getWeekStartDate = getWeekStartDate;

  // Serializa una fecha local como AAAA-MM-DD, formato requerido por el filtro de la API.
  function formatLocalDate(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }
  window.formatLocalDate = formatLocalDate;

  // Presenta ventas recientes o el historial filtrado, con acciones solo si el permiso lo permite.
  function renderSalesHistory() {
    const tbody = document.getElementById('salesHistoryBody');
    const count = document.getElementById('salesCount');
    if (!tbody) return;

    const escapeFn = typeof window.escapeHtml === 'function' ? window.escapeHtml : (str => str ?? '');
    const fmt = window.currencyFormatter || new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 });

    let sourceSales = state.historySales;
    if (!sourceSales) {
      const todayStr = formatLocalDate(new Date());
      sourceSales = state.sales.filter((sale) => {
        const saleDate = new Date(sale.fecha);
        return !Number.isNaN(saleDate.getTime()) && formatLocalDate(saleDate) === todayStr;
      });
    }
    const sales = [...sourceSales].sort((a, b) => new Date(b.fecha) - new Date(a.fecha));

    if (count) count.textContent = `${sales.length} venta${sales.length === 1 ? '' : 's'}`;

    if (!sales.length) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 18px; color: #6b7280;">No hay ventas registradas para este período.</td></tr>';
      return;
    }

    const todayStr = formatLocalDate(new Date());
    tbody.innerHTML = sales.map((sale) => {
      const date = new Date(sale.fecha);
      const isToday = !Number.isNaN(date.getTime()) && formatLocalDate(date) === todayStr;
      const canModify = (window.puedeModificarInforme ?? puedeModificarInforme)
        && !sale.archivada
        && isToday
        && sale.estado !== 'deshabilitada';
      const productoText = (sale.productos || []).map((producto) => {
        const metadata = [
          producto.sub_nombre,
          producto.subcategoria,
          producto.categoria,
          producto.marca
        ].filter(Boolean).map(escapeFn).join(' · ');
        return `<div class="sale-product-detail">
          ${producto.nombre ? `<strong>${escapeFn(producto.nombre)}</strong>` : ''}
          ${metadata ? `<small>${metadata}</small>` : ''}
          <span>× ${Number(producto.cantidad) || 0}</span>
        </div>`;
      }).join('');
      return `
        <tr class="sale-row">
          <td data-label="Fecha">${date.toLocaleDateString('es-AR')}</td>
          <td data-label="Hora">${date.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' })}</td>
          <td data-label="Día">${date.toLocaleDateString('es-AR', { weekday: 'long' })}</td>
          <td data-label="Productos" class="sale-products-cell">${productoText}</td>
          <td data-label="Total" class="sale-total-cell">${fmt.format(sale.total || 0)}</td>
          <td data-label="Acciones" class="sale-actions-cell">
            ${canModify ? `
              <button type="button" class="btn-delete-action" onclick="confirmarDeshabilitarVenta('${sale.id}')" title="Deshabilitar venta">🚫 Deshabilitar</button>
            ` : `<span style="color:#6b7280; font-size:0.85rem;" title="${sale.estado === 'deshabilitada' ? 'La venta conserva su registro, pero ya no afecta el stock ni los totales' : (!isToday ? 'Las ventas de días anteriores son de solo lectura' : 'Solo lectura')}">${sale.estado === 'deshabilitada' ? 'Deshabilitada' : (sale.archivada ? 'Archivada' : 'Solo lectura')}</span>`}
          </td>
        </tr>
      `;
    }).join('');
  }
  window.renderSalesHistory = renderSalesHistory;

  // Configura filtros semanales/diarios exclusivos de usuarios con capacidad administrativa.
  function configureHistoryFilters() {
    const isAdmin = window.usuarioEsAdminInforme ?? usuarioEsAdminInforme;
    if (!isAdmin) return;
    const weekSelect = document.getElementById('historyWeekSelect');
    const dateSelect = document.getElementById('historyDateSelect');
    if (!weekSelect || !dateSelect) return;

    const currentWeek = getWeekStartDate();
    const oldestWeek = new Date(currentWeek);
    oldestWeek.setDate(oldestWeek.getDate() - 28);
    weekSelect.innerHTML = '';

    // 1. Opción inicial por defecto: Ventas diarias (Hoy)
    const optToday = document.createElement('option');
    optToday.value = 'hoy';
    optToday.textContent = 'Ventas diarias (Hoy)';
    optToday.selected = true;
    weekSelect.appendChild(optToday);

    // 2. Opciones de semanas: Esta semana, Semana pasada, Hace 2 semanas, Hace 3 semanas, Hace 4 semanas
    const weekLabels = [
      'Esta semana',
      'Semana pasada',
      'Hace 2 semanas',
      'Hace 3 semanas',
      'Hace 4 semanas'
    ];

    for (let offset = 0; offset <= 4; offset += 1) {
      const weekStart = new Date(currentWeek);
      weekStart.setDate(weekStart.getDate() - offset * 7);
      const option = document.createElement('option');
      option.value = 'semana:' + formatLocalDate(weekStart);
      option.textContent = weekLabels[offset];
      weekSelect.appendChild(option);
    }

    dateSelect.min = formatLocalDate(oldestWeek);
    dateSelect.max = formatLocalDate(new Date());

    weekSelect.addEventListener('change', () => {
      if (weekSelect.value === 'hoy') {
        if (dateSelect) dateSelect.value = '';
        loadHistorySales('fecha', formatLocalDate(new Date()));
      } else if (weekSelect.value.startsWith('semana:')) {
        if (dateSelect) dateSelect.value = '';
        loadHistorySales('semana', weekSelect.value.replace('semana:', ''));
      }
    });

    dateSelect.addEventListener('change', () => {
      if (dateSelect.value) {
        weekSelect.value = '';
        loadHistorySales('fecha', dateSelect.value);
      }
    });
  }
  window.configureHistoryFilters = configureHistoryFilters;

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
      if (typeof window.renderLoadErrors === 'function') window.renderLoadErrors([]);
    } catch (error) {
      if (typeof window.renderLoadErrors === 'function') {
        window.renderLoadErrors([error.message || 'No se pudo cargar el historial de ventas.']);
      }
    }
  }
  window.loadHistorySales = loadHistorySales;

  // Recarga el mismo rango histórico seleccionado después de editar o eliminar una venta.
  function refreshSelectedHistory() {
    const isAdmin = window.usuarioEsAdminInforme ?? usuarioEsAdminInforme;
    if (!isAdmin) return;
    const selectedDate = document.getElementById('historyDateSelect')?.value;
    if (selectedDate) {
      loadHistorySales('fecha', selectedDate);
      return;
    }
    const selectedWeek = document.getElementById('historyWeekSelect')?.value;
    if (selectedWeek === 'hoy') {
      loadHistorySales('fecha', formatLocalDate(new Date()));
      return;
    }
    if (selectedWeek && selectedWeek.startsWith('semana:')) {
      loadHistorySales('semana', selectedWeek.replace('semana:', ''));
    }
  }
  window.refreshSelectedHistory = refreshSelectedHistory;

  // === MODAL DE EDICIÓN DE VENTAS ===
  // Pinta la venta elegida y limita cantidades según el stock que volvería al inventario.
  function abrirModalEdicion(saleId) {
    const canModify = window.puedeModificarInforme ?? puedeModificarInforme;
    if (!canModify) return;
    const sourceSales = state.historySales || state.sales;
    const sale = sourceSales.find(s => String(s.id) === String(saleId));
    if (!sale) return;

    const todayStr = formatLocalDate(new Date());
    const saleDateStr = formatLocalDate(new Date(sale.fecha));
    if (saleDateStr !== todayStr) {
      alert('Las ventas de días anteriores son de solo lectura y no se pueden modificar.');
      return;
    }

    document.getElementById('edit-sale-id').value = sale.id;
    document.getElementById('edit-sale-fecha').value = new Date(sale.fecha).toLocaleString('es-AR');

    const container = document.getElementById('edit-sale-products-container');
    container.innerHTML = '';

    const escapeFn = typeof window.escapeHtml === 'function' ? window.escapeHtml : (str => str ?? '');
    const fmt = window.currencyFormatter || new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 });

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
          <span style="font-weight: 600; color: #490633;">${escapeFn(prod.nombre)}</span>
          <small style="color: #6b4d64;">Precio: ${fmt.format(prod.precio)}</small>
        </div>
        <div style="flex: 1.5; display: flex; align-items: center; gap: 6px; justify-content: flex-end;">
          <input type="number" class="edit-product-qty" min="0" max="${stockDisponibleRestaurado}" value="${prod.cantidad}" data-codigo="${escapeFn(prod.codigo)}" data-precio="${prod.precio}" data-nombre="${escapeFn(prod.nombre)}" style="width: 60px; padding: 6px; border: 1px solid #dabad3; border-radius: 6px; text-align: center; color: #490633; font-weight: bold;">
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
  window.abrirModalEdicion = abrirModalEdicion;

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
    const fmt = window.currencyFormatter || new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS', maximumFractionDigits: 0 });
    if (totalEl) totalEl.textContent = fmt.format(total);
  }
  window.recalcularTotalModal = recalcularTotalModal;

  // Deshabilita una venta diaria y revierte su impacto en el stock.
  async function deshabilitarVenta(saleId) {
    const canModify = window.puedeModificarInforme ?? puedeModificarInforme;
    if (!canModify) return;

    try {
      const response = await fetch('api/ventas.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'deshabilitar', id: Number(saleId) })
      });
      const result = await response.json();

      if (!response.ok || !result.ok) {
        throw new Error(result.message || 'No se pudo deshabilitar la venta.');
      }

      alert(result.message || '✅ Venta deshabilitada y stock restaurado.');

      if (typeof window.loadInventory === 'function') await window.loadInventory();
      refreshSelectedHistory();

      localStorage.setItem('inventarioUpdated', Date.now().toString());
      window.dispatchEvent(new Event('inventario-updated'));
    } catch (error) {
      alert(`❌ Error al deshabilitar: ${error.message}`);
    }
  }
  window.deshabilitarVenta = deshabilitarVenta;

  // Confirma la deshabilitación de una venta diaria.
  function confirmarDeshabilitarVenta(saleId) {
    const canModify = window.puedeModificarInforme ?? puedeModificarInforme;
    if (!canModify) return;
    const sourceSales = state.historySales || state.sales;
    const sale = sourceSales.find(s => String(s.id) === String(saleId));
    if (sale) {
      const todayStr = formatLocalDate(new Date());
      const saleDateStr = formatLocalDate(new Date(sale.fecha));
      if (saleDateStr !== todayStr) {
        alert('Solo se pueden deshabilitar ventas del día actual.');
        return;
      }
    }
    if (confirm('⚠️ ¿Deshabilitar esta venta?\nLa venta conservará su registro y el stock volverá al estado anterior.')) {
      deshabilitarVenta(saleId);
    }
  }
  window.confirmarDeshabilitarVenta = confirmarDeshabilitarVenta;

  // Inicializa filtros y carga el historial de ventas.
  function initVentasHistorial() {
    configureHistoryFilters();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initVentasHistorial);
  } else {
    initVentasHistorial();
  }
})();
