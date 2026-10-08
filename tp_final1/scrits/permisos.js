const permissionsBody = document.getElementById('seller-permissions-body');
const permissionsMessage = document.getElementById('permissions-message');

const modalConfirmar = document.getElementById('modal-confirmar-permisos');
const formConfirmar = document.getElementById('form-confirmar-permisos');
const modalTargetName = document.getElementById('modal-target-user-name');
const modalSummary = document.getElementById('modal-permissions-summary');
const inputPassword = document.getElementById('admin-confirm-password');
const modalError = document.getElementById('modal-error-message');
const btnConfirmSave = document.getElementById('btn-confirm-save');
const btnTogglePassword = document.getElementById('btn-toggle-password');

let pendingChange = null;

// Consulta vendedores autorizables y construye sus controles de permisos en la tabla.
async function loadSellerPermissions() {
  try {
    const response = await fetch('api/permisos.php', { cache: 'no-store' });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudieron cargar los vendedores.');

    if (!result.usuarios || !result.usuarios.length) {
      permissionsBody.innerHTML = '<tr><td colspan="4" class="permissions-empty">No hay vendedores registrados.</td></tr>';
      return;
    }

    permissionsBody.innerHTML = result.usuarios.map((user) => `
      <tr class="permissions-row" data-user-id="${user.id}">
        <th scope="row">
          <div class="user-profile-cell">
            <span class="avatar-bubble vendedor">${escapeHtml(user.usuario.charAt(0).toUpperCase())}</span>
            <span class="user-details-title">${escapeHtml(user.usuario)}</span>
          </div>
        </th>
        <td>
          <label class="permission-toggle">
            <input type="checkbox" data-permission="puede_registrar_stock" aria-label="Permitir registrar stock a ${escapeHtml(user.usuario)}" ${user.puede_registrar_stock ? 'checked' : ''}>
            <span class="permission-toggle-label">${user.puede_registrar_stock ? 'Habilitado' : 'Deshabilitado'}</span>
          </label>
        </td>
        <td>
          <label class="permission-toggle">
            <input type="checkbox" data-permission="puede_modificar_informes" aria-label="Permitir modificar informes a ${escapeHtml(user.usuario)}" ${user.puede_modificar_informes ? 'checked' : ''}>
            <span class="permission-toggle-label">${user.puede_modificar_informes ? 'Habilitado' : 'Deshabilitado'}</span>
          </label>
        </td>
        <td>
          <button type="button" class="btn-action-sm btn-edit" data-save-permissions>💾 Guardar</button>
        </td>
      </tr>
    `).join('');
  } catch (error) {
    permissionsBody.innerHTML = `<tr><td colspan="4" class="permissions-empty permissions-error">${escapeHtml(error.message)}</td></tr>`;
  }
}

// Escapa los caracteres reservados antes de insertar nombres procedentes de la API en HTML.
function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[character]);
}

// Actualiza la etiqueta de estado en el acto al cambiar un checkbox.
permissionsBody.addEventListener('change', (event) => {
  const checkbox = event.target.closest('[data-permission]');
  if (!checkbox) return;
  checkbox.nextElementSibling.textContent = checkbox.checked ? 'Habilitado' : 'Deshabilitado';
});

// Abre el modal de confirmación solicitando la contraseña del administrador.
permissionsBody.addEventListener('click', (event) => {
  const button = event.target.closest('[data-save-permissions]');
  if (!button) return;

  const row = button.closest('tr');
  const userId = Number(row.dataset.userId);
  const userName = row.querySelector('.user-details-title')?.textContent?.trim() || 'vendedor';

  const stockInput = row.querySelector('[data-permission="puede_registrar_stock"]');
  const informesInput = row.querySelector('[data-permission="puede_modificar_informes"]');

  const stockChecked = Boolean(stockInput?.checked);
  const informesChecked = Boolean(informesInput?.checked);

  pendingChange = {
    id: userId,
    userName: userName,
    puede_registrar_stock: stockChecked,
    puede_modificar_informes: informesChecked,
    button: button
  };

  if (modalTargetName) modalTargetName.textContent = userName;
  if (modalSummary) {
    modalSummary.innerHTML = `
      <div>📦 <strong>Registrar productos en stock:</strong> <span style="color: ${stockChecked ? '#059669' : '#dc2626'}; font-weight: 700;">${stockChecked ? 'Habilitado' : 'Deshabilitado'}</span></div>
      <div style="margin-top: 6px;">📊 <strong>Modificar ventas en informes:</strong> <span style="color: ${informesChecked ? '#059669' : '#dc2626'}; font-weight: 700;">${informesChecked ? 'Habilitado' : 'Deshabilitado'}</span></div>
    `;
  }

  if (inputPassword) {
    inputPassword.value = '';
    inputPassword.type = 'password';
  }
  if (btnTogglePassword) {
    btnTogglePassword.textContent = '👁️';
  }
  if (modalError) {
    modalError.style.display = 'none';
    modalError.textContent = '';
  }

  abrirModalConfirmacion();
});

function abrirModalConfirmacion() {
  if (!modalConfirmar) return;
  modalConfirmar.classList.add('active');
  modalConfirmar.setAttribute('aria-hidden', 'false');
  setTimeout(() => inputPassword?.focus(), 50);
}

function cerrarModalConfirmacion() {
  if (!modalConfirmar) return;
  modalConfirmar.classList.remove('active');
  modalConfirmar.setAttribute('aria-hidden', 'true');
  if (inputPassword) inputPassword.value = '';
  if (modalError) {
    modalError.style.display = 'none';
    modalError.textContent = '';
  }
  pendingChange = null;
}

// Botones de cierre del modal y clic en el fondo
document.querySelectorAll('[data-modal-close]').forEach((btn) => {
  btn.addEventListener('click', () => cerrarModalConfirmacion());
});

if (modalConfirmar) {
  modalConfirmar.addEventListener('click', (e) => {
    if (e.target === modalConfirmar) {
      cerrarModalConfirmacion();
    }
  });
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && modalConfirmar?.classList.contains('active')) {
    cerrarModalConfirmacion();
  }
});

// Alternar visibilidad de contraseña
if (btnTogglePassword && inputPassword) {
  btnTogglePassword.addEventListener('click', () => {
    const isPassword = inputPassword.type === 'password';
    inputPassword.type = isPassword ? 'text' : 'password';
    btnTogglePassword.textContent = isPassword ? '🔒' : '👁️';
  });
}

// Envío del formulario de confirmación con la contraseña ingresada
if (formConfirmar) {
  formConfirmar.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!pendingChange) return;

    const password = inputPassword ? inputPassword.value : '';
    if (!password) {
      if (modalError) {
        modalError.textContent = 'Por favor, ingresá tu contraseña de administrador.';
        modalError.style.display = 'block';
      }
      inputPassword?.focus();
      return;
    }

    if (btnConfirmSave) {
      btnConfirmSave.disabled = true;
      btnConfirmSave.textContent = '⏳ Verificando...';
    }
    if (modalError) {
      modalError.style.display = 'none';
    }

    try {
      const response = await fetch('api/permisos.php', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: pendingChange.id,
          puede_registrar_stock: pendingChange.puede_registrar_stock,
          puede_modificar_informes: pendingChange.puede_modificar_informes,
          password: password
        })
      });

      const result = await response.json();
      if (!response.ok || !result.ok) {
        throw new Error(result.message || 'No se pudieron actualizar los permisos.');
      }

      const savedUserName = pendingChange.userName;
      cerrarModalConfirmacion();

      if (permissionsMessage) {
        permissionsMessage.textContent = result.message || `Permisos de ${savedUserName} actualizados correctamente.`;
        permissionsMessage.className = 'permissions-message success';
      }
    } catch (error) {
      if (modalError) {
        modalError.textContent = error.message;
        modalError.style.display = 'block';
      }
      inputPassword?.select();
      inputPassword?.focus();
    } finally {
      if (btnConfirmSave) {
        btnConfirmSave.disabled = false;
        btnConfirmSave.textContent = '💾 Confirmar y Guardar';
      }
    }
  });
}

loadSellerPermissions();