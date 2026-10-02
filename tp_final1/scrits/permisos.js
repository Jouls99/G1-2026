const permissionsBody = document.getElementById('seller-permissions-body');
const permissionsMessage = document.getElementById('permissions-message');

async function loadSellerPermissions() {
  try {
    const response = await fetch('api/permisos.php', { cache: 'no-store' });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudieron cargar los vendedores.');

    if (!result.usuarios.length) {
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
        <td><label class="permission-toggle"><input type="checkbox" data-permission="puede_registrar_stock" aria-label="Permitir registrar stock a ${escapeHtml(user.usuario)}" ${user.puede_registrar_stock ? 'checked' : ''}><span class="permission-toggle-label">${user.puede_registrar_stock ? 'Habilitado' : 'Deshabilitado'}</span></label></td>
        <td><label class="permission-toggle"><input type="checkbox" data-permission="puede_modificar_informes" aria-label="Permitir modificar informes a ${escapeHtml(user.usuario)}" ${user.puede_modificar_informes ? 'checked' : ''}><span class="permission-toggle-label">${user.puede_modificar_informes ? 'Habilitado' : 'Deshabilitado'}</span></label></td>
        <td><button type="button" class="btn-action-sm btn-edit" data-save-permissions>💾 Guardar</button></td>
      </tr>
    `).join('');
  } catch (error) {
    permissionsBody.innerHTML = `<tr><td colspan="4" class="permissions-empty permissions-error">${escapeHtml(error.message)}</td></tr>`;
  }
}

function escapeHtml(value) {
  return String(value).replace(/[&<>"']/g, (character) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  })[character]);
}

permissionsBody.addEventListener('change', (event) => {
  const checkbox = event.target.closest('[data-permission]');
  if (!checkbox) return;
  checkbox.nextElementSibling.textContent = checkbox.checked ? 'Habilitado' : 'Deshabilitado';
});

permissionsBody.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-save-permissions]');
  if (!button) return;

  const row = button.closest('tr');
  const payload = { id: Number(row.dataset.userId) };
  row.querySelectorAll('[data-permission]').forEach((input) => {
    payload[input.dataset.permission] = input.checked;
  });

  button.disabled = true;
  permissionsMessage.textContent = '';
  try {
    const response = await fetch('api/permisos.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudieron guardar los permisos.');
    permissionsMessage.textContent = `Permisos de ${row.querySelector('th').textContent} actualizados.`;
    permissionsMessage.className = 'permissions-message success';
  } catch (error) {
    permissionsMessage.textContent = error.message;
    permissionsMessage.className = 'permissions-message error';
  } finally {
    button.disabled = false;
  }
});

loadSellerPermissions();