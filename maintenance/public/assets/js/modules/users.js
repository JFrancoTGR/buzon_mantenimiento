import { apiRequest } from '../core/api.js';
import { applyPermissionVisibility } from '../core/permissions.js';
import { setupSidebar } from '../components/sidebar.js';
import { setupUserMenu } from '../components/userMenu.js';
import { setupNotificationsMenu } from '../components/notificationsMenu.js';
import { formatDateTime } from '../utils/date.js';

const tableBody = document.querySelector('[data-users-table]');
const summary = document.querySelector('[data-users-summary]');
const errorBox = document.querySelector('[data-users-error]');
const roleFilter = document.querySelector('[data-filter-role]');
const statusFilter = document.querySelector('[data-filter-status]');
const searchFilter = document.querySelector('[data-filter-search]');
const pagination = document.querySelector('[data-pagination]');
const pageLabel = document.querySelector('[data-page-label]');
const prevButton = document.querySelector('[data-page-prev]');
const nextButton = document.querySelector('[data-page-next]');
const inviteModal = document.querySelector('[data-invite-modal]');
const inviteForm = document.querySelector('[data-invite-form]');
const inviteRole = document.querySelector('[data-invite-role]');
const inviteMessage = document.querySelector('[data-invite-message]');
const inviteSubmit = document.querySelector('[data-invite-submit]');

let currentUser = null;
let context = null;
let page = 1;
let lastPagination = null;

try {
  const payload = await apiRequest('./api/auth/me.php');
  currentUser = payload.data.user;
  if (currentUser.must_change_password) {
    window.location.replace('./change-password.html');
    throw new Error('password_change_required');
  }
  if (!currentUser.permissions?.includes('user.manage')) {
    window.location.replace('./dashboard.html');
    throw new Error('permission_denied');
  }
} catch (error) {
  if (!['password_change_required', 'permission_denied'].includes(error.message)) {
    window.location.replace('./login.html');
  }
}

if (currentUser) {
  applyPermissionVisibility(currentUser);
  setupSidebar();
  setupUserMenu(currentUser);
  setupComingSoonActions();
  await loadNotifications();
  await initializeModule();
}

async function initializeModule() {
  try {
    const payload = await apiRequest('./api/admin/users/context.php');
    context = payload.data;
    populateRoles(context.roles || []);
    const ttl = document.querySelector('[data-invite-ttl]');
    if (ttl) ttl.textContent = `${Number(context.invitation_ttl_hours || 72)} horas`;
    bindEvents();
    await loadUsers();
  } catch (error) {
    showPageError(error.message || 'No fue posible inicializar el módulo de usuarios.');
  }
}

function bindEvents() {
  document.querySelector('[data-open-invite]')?.addEventListener('click', openInviteModal);
  document.querySelectorAll('[data-close-invite]').forEach((element) => element.addEventListener('click', closeInviteModal));
  document.querySelector('[data-apply-filters]')?.addEventListener('click', async () => {
    page = 1;
    await loadUsers();
  });
  searchFilter?.addEventListener('keydown', async (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    page = 1;
    await loadUsers();
  });
  prevButton?.addEventListener('click', async () => {
    if (page <= 1) return;
    page -= 1;
    await loadUsers();
  });
  nextButton?.addEventListener('click', async () => {
    if (!lastPagination || page >= Number(lastPagination.pages || 1)) return;
    page += 1;
    await loadUsers();
  });
  inviteForm?.addEventListener('submit', submitInvitation);
  tableBody?.addEventListener('click', handleTableAction);
}

async function loadNotifications() {
  try {
    const payload = await apiRequest('./api/dashboard/summary.php');
    setupNotificationsMenu({
      unreadCount: Number(payload.data.unread_notifications || 0),
      notifications: payload.data.notifications || [],
    });
  } catch {
    setupNotificationsMenu();
  }
}

async function loadUsers() {
  setLoading(true);
  hidePageError();
  const params = new URLSearchParams({ page: String(page), per_page: '25' });
  const search = searchFilter?.value.trim() || '';
  const role = roleFilter?.value || '';
  const status = statusFilter?.value || '';
  if (search) params.set('search', search);
  if (role) params.set('role', role);
  if (status) params.set('status', status);

  try {
    const payload = await apiRequest(`./api/admin/users/list.php?${params.toString()}`);
    renderUsers(payload.data.items || []);
    renderPagination(payload.data.pagination || null);
  } catch (error) {
    tableBody.innerHTML = '<tr><td colspan="6" class="data-table__muted">No fue posible cargar los usuarios.</td></tr>';
    showPageError(error.message || 'No fue posible cargar los usuarios.');
  } finally {
    setLoading(false);
  }
}

function renderUsers(items) {
  tableBody.replaceChildren();
  if (!items.length) {
    tableBody.innerHTML = '<tr><td colspan="6" class="data-table__muted">No se encontraron usuarios con estos filtros.</td></tr>';
    return;
  }
  items.forEach((item) => tableBody.append(createUserRow(item)));
}

function createUserRow(user) {
  const row = document.createElement('tr');

  const identity = document.createElement('td');
  const identityWrap = document.createElement('div');
  identityWrap.className = 'users-user';
  const name = document.createElement('strong');
  name.textContent = user.full_name;
  const email = document.createElement('span');
  email.textContent = user.email;
  identityWrap.append(name, email);
  identity.append(identityWrap);

  const role = document.createElement('td');
  role.textContent = user.role?.name || user.role?.code || '—';

  const status = document.createElement('td');
  const statusBadge = document.createElement('span');
  statusBadge.className = `users-badge users-badge--${user.status}`;
  statusBadge.textContent = statusLabel(user.status);
  status.append(statusBadge);

  const invitation = document.createElement('td');
  invitation.append(renderInvitation(user));

  const lastLogin = document.createElement('td');
  lastLogin.textContent = user.last_login_at ? formatDateTime(user.last_login_at) : 'Sin acceso';

  const actions = document.createElement('td');
  const actionsWrap = document.createElement('div');
  actionsWrap.className = 'users-actions';
  actionsWrap.append(...buildActions(user));
  actions.append(actionsWrap);

  row.append(identity, role, status, invitation, lastLogin, actions);
  return row;
}

function renderInvitation(user) {
  const wrap = document.createElement('div');
  wrap.className = 'users-invitation';
  if (!user.invitation) {
    wrap.textContent = '—';
    return wrap;
  }
  const label = document.createElement('span');
  label.textContent = invitationLabel(user.invitation.status);
  const detail = document.createElement('small');
  detail.textContent = user.invitation.expires_at ? `Vence: ${formatDateTime(user.invitation.expires_at)}` : '';
  wrap.append(label, detail);
  return wrap;
}

function buildActions(user) {
  const actions = [];
  const isSelf = Number(user.id) === Number(currentUser.id);

  if (user.status === 'invited') {
    actions.push(actionButton('Reenviar', 'resend', user.id));
    actions.push(actionButton('Revocar', 'revoke', user.id, true));
  }

  if (!isSelf && currentUser.permissions?.includes('role.manage')) {
    actions.push(actionButton('Cambiar rol', 'role', user.id));
  }

  if (!isSelf && user.status === 'active') {
    actions.push(actionButton('Desactivar', 'deactivate', user.id, true));
  } else if (!isSelf && ['inactive', 'blocked'].includes(user.status)) {
    actions.push(actionButton('Activar', 'activate', user.id));
  }

  if (!actions.length) {
    const empty = document.createElement('span');
    empty.className = 'data-table__muted';
    empty.textContent = 'Sin acciones';
    actions.push(empty);
  }
  return actions;
}

function actionButton(label, action, userId, danger = false) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = `users-action${danger ? ' users-action--danger' : ''}`;
  button.dataset.action = action;
  button.dataset.userId = String(userId);
  button.textContent = label;
  return button;
}

async function handleTableAction(event) {
  const button = event.target.closest('[data-action][data-user-id]');
  if (!button) return;
  const userId = Number(button.dataset.userId);
  const action = button.dataset.action;
  if (!userId || !action) return;

  try {
    button.disabled = true;
    if (action === 'resend') await resendInvitation(userId);
    if (action === 'revoke') await revokeInvitation(userId);
    if (action === 'role') await changeRole(userId);
    if (action === 'deactivate') await changeStatus(userId, 'inactive');
    if (action === 'activate') await changeStatus(userId, 'active');
  } catch (error) {
    await handleTableActionError(error, action);
  } finally {
    button.disabled = false;
  }
}

async function handleTableActionError(error, action) {
  const message = error?.message || 'No fue posible completar la operación.';

  if (action === 'resend' && Number(error?.status) === 429) {
    await notify('Aún no puedes reenviar la invitación', message, 'warning');
    return;
  }

  const titles = {
    resend: 'No fue posible reenviar la invitación',
    revoke: 'No fue posible revocar la invitación',
    role: 'No fue posible cambiar el rol',
    deactivate: 'No fue posible desactivar al usuario',
    activate: 'No fue posible activar al usuario',
  };

  await notify(titles[action] || 'No fue posible completar la operación', message, 'error');
}

async function resendInvitation(userId) {
  const accepted = await confirmAction('Reenviar invitación', 'El enlace anterior quedará revocado y se generará uno nuevo.');
  if (!accepted) return;
  const payload = await apiRequest('./api/admin/users/resend-invitation.php', {
    method: 'POST', body: JSON.stringify({ user_id: userId }),
  });
  await notify(payload.data.email_sent ? 'Invitación reenviada' : 'Invitación generada', payload.data.email_sent ? 'El nuevo correo fue enviado.' : 'La invitación quedó vigente, pero el correo no pudo enviarse.', payload.data.email_sent ? 'success' : 'warning');
  await loadUsers();
}

async function revokeInvitation(userId) {
  const accepted = await confirmAction('Revocar invitación', 'El enlace vigente dejará de funcionar. La cuenta permanecerá en estado Invitado.');
  if (!accepted) return;
  await apiRequest('./api/admin/users/revoke-invitation.php', {
    method: 'POST', body: JSON.stringify({ user_id: userId }),
  });
  await notify('Invitación revocada', 'El enlace ya no puede utilizarse.', 'success');
  await loadUsers();
}

async function changeRole(userId) {
  const options = Object.fromEntries((context.roles || []).map((role) => [role.code, role.name]));
  let role;
  if (window.Swal) {
    const result = await window.Swal.fire({
      title: 'Cambiar rol',
      input: 'select',
      inputOptions: options,
      inputPlaceholder: 'Selecciona el nuevo rol',
      showCancelButton: true,
      confirmButtonText: 'Guardar',
      cancelButtonText: 'Cancelar',
      customClass: { confirmButton: 'app-alert__confirm' },
    });
    if (!result.isConfirmed || !result.value) return;
    role = result.value;
  } else {
    role = window.prompt(`Nuevo rol: ${Object.keys(options).join(', ')}`) || '';
    if (!role) return;
  }

  await apiRequest('./api/admin/users/change-role.php', {
    method: 'POST', body: JSON.stringify({ user_id: userId, role }),
  });
  await notify('Rol actualizado', 'Las sesiones previas de la cuenta fueron revocadas.', 'success');
  await loadUsers();
}

async function changeStatus(userId, status) {
  const deactivate = status === 'inactive';
  const accepted = await confirmAction(
    deactivate ? 'Desactivar usuario' : 'Activar usuario',
    deactivate ? 'La cuenta dejará de poder iniciar sesión y sus sesiones activas serán revocadas.' : 'La cuenta volverá a poder iniciar sesión con sus credenciales existentes.'
  );
  if (!accepted) return;

  await apiRequest('./api/admin/users/change-status.php', {
    method: 'POST', body: JSON.stringify({ user_id: userId, status }),
  });
  await notify(deactivate ? 'Usuario desactivado' : 'Usuario activado', 'El estado de la cuenta fue actualizado.', 'success');
  await loadUsers();
}

async function submitInvitation(event) {
  event.preventDefault();
  inviteMessage.textContent = '';
  if (!inviteForm.reportValidity()) return;

  const data = Object.fromEntries(new FormData(inviteForm).entries());
  inviteSubmit.disabled = true;
  inviteSubmit.textContent = 'Enviando…';
  try {
    const payload = await apiRequest('./api/admin/users/create.php', {
      method: 'POST', body: JSON.stringify(data),
    });
    closeInviteModal();
    inviteForm.reset();
    await notify(
      payload.data.email_sent ? 'Usuario invitado' : 'Usuario creado',
      payload.data.email_sent ? 'La invitación fue enviada por correo.' : 'La cuenta quedó creada, pero el correo no pudo enviarse. Puedes reenviarlo desde la tabla.',
      payload.data.email_sent ? 'success' : 'warning'
    );
    page = 1;
    await loadUsers();
  } catch (error) {
    inviteMessage.textContent = error.message || 'No fue posible crear la invitación.';
  } finally {
    inviteSubmit.disabled = false;
    inviteSubmit.textContent = 'Enviar invitación';
  }
}

function populateRoles(roles) {
  [roleFilter, inviteRole].forEach((select) => {
    if (!select) return;
    if (select === inviteRole) select.replaceChildren();
    roles.forEach((role) => {
      const option = document.createElement('option');
      option.value = role.code;
      option.textContent = role.name;
      select.append(option);
    });
  });
}

function renderPagination(value) {
  lastPagination = value;
  if (!value || Number(value.total || 0) === 0) {
    pagination.hidden = true;
    summary.textContent = '0 usuarios';
    return;
  }
  pagination.hidden = false;
  const total = Number(value.total || 0);
  const pages = Number(value.pages || 1);
  page = Number(value.page || 1);
  summary.textContent = `${total} usuario${total === 1 ? '' : 's'}`;
  pageLabel.textContent = `Página ${page} de ${pages}`;
  prevButton.disabled = page <= 1;
  nextButton.disabled = page >= pages;
}

function openInviteModal() {
  inviteMessage.textContent = '';
  inviteModal.hidden = false;
  document.body.style.overflow = 'hidden';
  inviteForm?.querySelector('input')?.focus();
}

function closeInviteModal() {
  inviteModal.hidden = true;
  document.body.style.overflow = '';
}

function setLoading(loading) {
  if (loading && tableBody) {
    tableBody.innerHTML = '<tr><td colspan="6" class="data-table__muted">Cargando usuarios…</td></tr>';
  }
}

function showPageError(text) {
  if (!errorBox) return;
  errorBox.hidden = false;
  errorBox.textContent = text;
}

function hidePageError() {
  if (errorBox) errorBox.hidden = true;
}

function statusLabel(status) {
  return ({ active: 'Activo', inactive: 'Inactivo', blocked: 'Bloqueado', pending: 'Verificación pendiente', invited: 'Invitado' })[status] || status;
}

function invitationLabel(status) {
  return ({ pending: 'Vigente', expired: 'Expirada', revoked: 'Revocada', used: 'Utilizada' })[status] || status;
}

async function confirmAction(title, text) {
  if (!window.Swal) return window.confirm(`${title}\n\n${text}`);
  const result = await window.Swal.fire({
    icon: 'question', title, text, showCancelButton: true,
    confirmButtonText: 'Continuar', cancelButtonText: 'Cancelar',
    customClass: { confirmButton: 'app-alert__confirm' },
  });
  return result.isConfirmed;
}

async function notify(title, text, icon = 'success') {
  if (!window.Swal) {
    window.alert(`${title}\n\n${text}`);
    return;
  }
  await window.Swal.fire({ icon, title, text, confirmButtonText: 'Aceptar', customClass: { confirmButton: 'app-alert__confirm' } });
}

function setupComingSoonActions() {
  document.querySelectorAll('[data-coming-soon]').forEach((element) => {
    element.addEventListener('click', async (event) => {
      if (element.tagName === 'A') event.preventDefault();
      await notify('Módulo en preparación', 'Esta sección se habilitará en una siguiente etapa.', 'info');
    });
  });
}
