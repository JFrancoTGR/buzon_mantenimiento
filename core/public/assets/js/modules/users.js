import { apiRequest } from '../core/api.js';
import { setupDropdown } from '../components/dropdown.js';
import { setupSidebar } from '../components/sidebar.js';

const tableBody = document.querySelector('[data-users-table]');
const summary = document.querySelector('[data-users-summary]');
const errorBox = document.querySelector('[data-users-error]');

const searchFilter = document.querySelector('[data-filter-search]');
const applicationFilter = document.querySelector('[data-filter-application]');
const statusFilter = document.querySelector('[data-filter-status]');

const pagination = document.querySelector('[data-pagination]');
const pageLabel = document.querySelector('[data-page-label]');
const prevButton = document.querySelector('[data-page-prev]');
const nextButton = document.querySelector('[data-page-next]');

const inviteModal = document.querySelector('[data-invite-modal]');
const inviteForm = document.querySelector('[data-invite-form]');
const inviteAccesses = document.querySelector('[data-invite-accesses]');
const inviteMessage = document.querySelector('[data-invite-message]');
const inviteSubmit = document.querySelector('[data-invite-submit]');
const inviteTtl = document.querySelector('[data-invite-ttl]');

const accessModal = document.querySelector('[data-access-modal]');
const accessUserSummary = document.querySelector('[data-access-user-summary]');
const accessApplications = document.querySelector('[data-access-applications]');
const accessMessage = document.querySelector('[data-access-message]');

const currentUserId = Number(document.body.dataset.currentUserId || 0);

let context = null;
let usersById = new Map();
let page = 1;
let lastPagination = null;

setupSidebar();
setupUserMenu();
setupComingSoonActions();
initialize();

async function initialize() {
  try {
    const payload = await apiRequest('/api/admin/users/context');

    context = payload.data || {};

    populateContext(context);
    bindEvents();

    await loadUsers();
  } catch (error) {
    handleRequestError(error);
  }
}

function populateContext(value) {
  const applications = value?.applications || [];

  populateApplications(applications);
  populateStatuses(value?.statuses || []);
  populateInviteAccesses(applications, Boolean(value?.can_manage_access));

  if (inviteTtl) {
    inviteTtl.textContent = `${Number(value?.invitation_ttl_hours || 72)} horas`;
  }
}

function populateApplications(applications) {
  if (!applicationFilter) return;

  applications.forEach((application) => {
    const option = document.createElement('option');

    option.value = application.code;
    option.textContent = application.is_active
      ? application.name
      : `${application.name} · Inactiva`;

    applicationFilter.append(option);
  });
}

function populateStatuses(statuses) {
  if (!statusFilter) return;

  statuses.forEach((status) => {
    const option = document.createElement('option');

    option.value = status;
    option.textContent = statusLabel(status);

    statusFilter.append(option);
  });
}

function populateInviteAccesses(applications, canManageAccess) {
  if (!inviteAccesses) return;

  inviteAccesses.replaceChildren();

  if (!canManageAccess) {
    const message = document.createElement('p');
    message.className = 'users-invite-accesses__empty';
    message.textContent =
      'Tu cuenta puede crear identidades, pero no asignar accesos a aplicaciones.';

    inviteAccesses.append(message);
    return;
  }

  const available = applications.filter(
    (application) =>
      application?.is_active &&
      Array.isArray(application?.roles) &&
      application.roles.length > 0,
  );

  if (!available.length) {
    const message = document.createElement('p');
    message.className = 'users-invite-accesses__empty';
    message.textContent = 'No hay aplicaciones activas con roles disponibles.';

    inviteAccesses.append(message);
    return;
  }

  available.forEach((application) => {
    const field = document.createElement('label');
    field.className = 'users-field users-invite-access';

    const label = document.createElement('span');
    label.textContent = application.name || application.code;

    const select = document.createElement('select');
    select.dataset.inviteApplication = application.code;

    const empty = document.createElement('option');
    empty.value = '';
    empty.textContent = 'Sin acceso';
    select.append(empty);

    application.roles.forEach((role) => {
      const option = document.createElement('option');
      option.value = role.code;
      option.textContent = role.name || role.code;
      select.append(option);
    });

    field.append(label, select);
    inviteAccesses.append(field);
  });
}

function bindEvents() {
  document
    .querySelector('[data-apply-filters]')
    ?.addEventListener('click', async () => {
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
    if (!lastPagination || page >= Number(lastPagination.pages || 1)) {
      return;
    }

    page += 1;

    await loadUsers();
  });

  document
    .querySelector('[data-open-invite]')
    ?.addEventListener('click', openInviteModal);

  document.querySelectorAll('[data-close-invite]').forEach((element) => {
    element.addEventListener('click', closeInviteModal);
  });

  document.querySelectorAll('[data-close-access]').forEach((element) => {
    element.addEventListener('click', closeAccessModal);
  });

  inviteForm?.addEventListener('submit', submitInvitation);
  tableBody?.addEventListener('click', handleTableAction);
  accessApplications?.addEventListener('change', handleAccessEditorChange);
  accessApplications?.addEventListener('click', handleAccessEditorClick);

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;

    if (inviteModal && !inviteModal.hidden) {
      closeInviteModal();
      return;
    }

    if (accessModal && !accessModal.hidden) {
      closeAccessModal();
    }
  });
}

async function loadUsers() {
  setLoading();
  hideError();

  const params = new URLSearchParams({
    page: String(page),
    per_page: '25',
  });

  const search = searchFilter?.value.trim() || '';
  const application = applicationFilter?.value || '';
  const status = statusFilter?.value || '';

  if (search) {
    params.set('search', search);
  }

  if (application) {
    params.set('application', application);
  }

  if (status) {
    params.set('status', status);
  }

  try {
    const payload = await apiRequest(
      `/api/admin/users/list?${params.toString()}`,
    );

    renderUsers(payload.data?.items || []);
    renderPagination(payload.data?.pagination || null);
  } catch (error) {
    handleRequestError(error);
  }
}

function renderUsers(items) {
  tableBody?.replaceChildren();
  usersById = new Map(
    items.map((user) => [Number(user.id), user]),
  );

  if (!items.length) {
    if (tableBody) {
      tableBody.innerHTML = `
        <tr>
          <td colspan="8" class="users-table__muted">
            No se encontraron usuarios con estos filtros.
          </td>
        </tr>
      `;
    }

    return;
  }

  items.forEach((user) => {
    tableBody?.append(createUserRow(user));
  });
}

function createUserRow(user) {
  const row = document.createElement('tr');

  const identity = document.createElement('td');
  identity.append(createIdentity(user));

  const status = document.createElement('td');
  status.append(createStatus(user.status));

  const invitation = document.createElement('td');
  invitation.append(createInvitation(user.invitation));

  const invitationExpires = document.createElement('td');
  invitationExpires.className = 'users-date';
  invitationExpires.textContent = user.invitation?.expires_at
    ? formatInvitationExpiration(user.invitation.expires_at)
    : '—';

  const applications = document.createElement('td');
  applications.append(createAccessList(user.applications || []));

  const lastLogin = document.createElement('td');
  lastLogin.className = 'users-date';
  lastLogin.textContent = user.last_login_at
    ? formatDateTime(user.last_login_at)
    : 'Sin acceso';

  const created = document.createElement('td');
  created.className = 'users-date';
  created.textContent = formatDateTime(user.created_at);

  const actions = document.createElement('td');
  actions.append(createUserActions(user));

  row.append(
    identity,
    status,
    invitation,
    invitationExpires,
    applications,
    lastLogin,
    created,
    actions,
  );

  return row;
}

function createIdentity(user) {
  const wrapper = document.createElement('div');
  wrapper.className = 'users-user';

  const name = document.createElement('strong');
  name.textContent = user.full_name || 'Usuario';

  const email = document.createElement('span');
  email.textContent = user.email || '—';

  wrapper.append(name, email);

  return wrapper;
}

function createStatus(status) {
  const badge = document.createElement('span');

  badge.className = 'users-status';
  badge.dataset.status = status || '';
  badge.textContent = statusLabel(status);

  return badge;
}

function createInvitation(invitation) {
  const wrapper = document.createElement('div');
  wrapper.className = 'users-invitation';

  if (!invitation) {
    const empty = document.createElement('span');
    empty.className = 'users-invitation__empty';
    empty.textContent = '—';
    wrapper.append(empty);

    return wrapper;
  }

  const status = document.createElement('span');
  status.className = 'users-invitation__status';
  status.dataset.status = invitation.status || '';
  status.textContent = invitationLabel(invitation.status);

  wrapper.append(status);

  return wrapper;
}

function createUserActions(user) {
  const wrapper = document.createElement('div');
  wrapper.className = 'users-actions';

  if (user.status === 'invited') {
    const invitationStatus = user.invitation?.status || null;

    if (invitationStatus !== 'used') {
      wrapper.append(actionButton('Reenviar', 'resend', user.id));
    }

    if (invitationStatus === 'pending') {
      wrapper.append(actionButton('Revocar', 'revoke', user.id, true));
    }
  }

  if (Boolean(context?.can_manage_access)) {
    wrapper.append(actionButton('Accesos', 'access', user.id));
  }

  if (!wrapper.children.length) {
    wrapper.append(createActionsEmpty());
  }

  return wrapper;
}

function createActionsEmpty() {
  const empty = document.createElement('span');
  empty.className = 'users-actions__empty';
  empty.textContent = '—';
  return empty;
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

function openAccessModal(userId) {
  if (!accessModal || !accessApplications) return;

  const user = usersById.get(Number(userId));

  if (!user || !Boolean(context?.can_manage_access)) {
    return;
  }

  clearAccessMessage();

  if (accessUserSummary) {
    const name = user.full_name || user.email || 'Usuario';
    accessUserSummary.textContent =
      `${name} · ${user.email || 'Sin correo'}`;
  }

  renderAccessEditor(user);

  accessModal.hidden = false;
  document.body.style.overflow = 'hidden';
}

function closeAccessModal() {
  if (!accessModal) return;

  accessModal.hidden = true;
  document.body.style.overflow = '';
  clearAccessMessage();

  if (accessApplications) {
    accessApplications.replaceChildren();
  }
}

function renderAccessEditor(user) {
  if (!accessApplications) return;

  accessApplications.replaceChildren();

  const applications = Array.isArray(context?.applications)
    ? context.applications
    : [];

  let rendered = 0;

  applications.forEach((application) => {
    const currentAccess = findUserApplicationAccess(user, application.code);

    if (!application.is_active && !currentAccess) {
      return;
    }

    accessApplications.append(
      createAccessEditorRow(user, application, currentAccess),
    );

    rendered += 1;
  });

  if (rendered === 0) {
    const empty = document.createElement('p');
    empty.className = 'users-access-editor__empty';
    empty.textContent =
      'No hay aplicaciones disponibles para administrar.';
    accessApplications.append(empty);
  }
}

function createAccessEditorRow(user, application, currentAccess) {
  const row = document.createElement('div');
  row.className = 'users-access-editor__row';

  const applicationInfo = document.createElement('div');
  applicationInfo.className = 'users-access-editor__application';

  const applicationName = document.createElement('strong');
  applicationName.textContent = application.name || application.code;

  const currentRoleName =
    currentAccess?.role?.name
    || currentAccess?.role?.code
    || 'Sin acceso';

  const current = document.createElement('span');
  current.textContent = `Actual: ${currentRoleName}`;

  applicationInfo.append(applicationName, current);

  const isSelfCore =
    Number(user.id) === currentUserId
    && application.code === 'core';

  const currentRoleCode = currentAccess?.role?.code || '';

  const field = document.createElement('label');
  field.className = 'users-field';

  const fieldLabel = document.createElement('span');
  fieldLabel.textContent = 'Rol';

  const select = document.createElement('select');
  select.dataset.accessRole = '';
  select.dataset.applicationCode = application.code;
  select.dataset.originalRoleCode = currentRoleCode;
  select.dataset.userId = String(user.id);

  const empty = document.createElement('option');
  empty.value = '';
  empty.textContent = 'Sin acceso';
  select.append(empty);

  const roles = Array.isArray(application.roles)
    ? [...application.roles]
    : [];

  if (
    currentAccess?.role?.code
    && !roles.some((role) => role.code === currentAccess.role.code)
  ) {
    roles.unshift({
      code: currentAccess.role.code,
      name: currentAccess.role.name || currentAccess.role.code,
    });
  }

  roles.forEach((role) => {
    const option = document.createElement('option');
    option.value = role.code;
    option.textContent = role.name || role.code;
    select.append(option);
  });

  select.value = currentRoleCode;

  if (isSelfCore) {
    select.disabled = true;
  }

  if (!application.is_active && currentRoleCode) {
    Array.from(select.options).forEach((option) => {
      if (option.value !== '' && option.value !== currentRoleCode) {
        option.disabled = true;
      }
    });
  }

  field.append(fieldLabel, select);

  const action = document.createElement('button');
  action.type = 'button';
  action.className =
    'button button--primary users-access-editor__apply';
  action.dataset.accessApply = '';
  action.textContent = 'Aplicar';
  action.disabled = true;

  row.append(applicationInfo, field, action);

  if (isSelfCore || !application.is_active) {
    const note = document.createElement('p');
    note.className = 'users-access-editor__restriction';

    note.textContent = isSelfCore
      ? 'Tu propio acceso administrativo a Core no puede modificarse desde aquí.'
      : 'La aplicación está inactiva. Solo puedes conservar o revocar el acceso actual.';

    row.append(note);
  }

  return row;
}

function findUserApplicationAccess(user, applicationCode) {
  return (user.applications || []).find(
    (application) => application.code === applicationCode,
  ) || null;
}

function handleAccessEditorChange(event) {
  const select = event.target.closest('[data-access-role]');

  if (!select) return;

  const row = select.closest('.users-access-editor__row');
  const button = row?.querySelector('[data-access-apply]');

  if (!button) return;

  button.disabled =
    select.disabled
    || select.value === select.dataset.originalRoleCode;
}

async function handleAccessEditorClick(event) {
  const button = event.target.closest('[data-access-apply]');

  if (!button || button.disabled) return;

  const row = button.closest('.users-access-editor__row');
  const select = row?.querySelector('[data-access-role]');

  if (!select) return;

  await applyAccessChange(select, button);
}

async function applyAccessChange(select, button) {
  const userId = Number(select.dataset.userId);
  const applicationCode = select.dataset.applicationCode || '';
  const originalRoleCode = select.dataset.originalRoleCode || '';
  const nextRoleCode = select.value || '';

  if (!userId || !applicationCode || originalRoleCode === nextRoleCode) {
    return;
  }

  const user = usersById.get(userId);
  const application = (context?.applications || []).find(
    (item) => item.code === applicationCode,
  );

  const userName =
    user?.full_name
    || user?.email
    || 'este usuario';

  const applicationName = application?.name || applicationCode;

  let title = 'Cambiar rol';
  let message =
    `Se actualizará el acceso de ${userName} en ${applicationName}.`;

  if (!originalRoleCode && nextRoleCode) {
    title = 'Asignar acceso';
    message =
      `Se asignará acceso a ${applicationName} para ${userName}.`;
  }

  if (originalRoleCode && !nextRoleCode) {
    title = 'Revocar acceso';
    message =
      `Se revocará el acceso de ${userName} a ${applicationName}.`;
  }

  const accepted = await confirmAction(
    title,
    `${message} Las sesiones activas de la cuenta se cerrarán.`,
  );

  if (!accepted) return;

  clearAccessMessage();

  button.disabled = true;
  select.disabled = true;
  button.textContent = 'Aplicando…';

  try {
    const payload = await apiRequest('/api/admin/users/update-access', {
      method: 'POST',
      body: JSON.stringify({
        user_id: userId,
        application_code: applicationCode,
        role_code: nextRoleCode || null,
      }),
    });

    const changed = payload.data?.changed !== false;

    closeAccessModal();

    await notify(
      changed ? 'Acceso actualizado' : 'Sin cambios',
      changed
        ? 'La asignación de acceso se actualizó correctamente.'
        : 'La cuenta ya tenía esa configuración de acceso.',
      changed ? 'success' : 'info',
    );

    await loadUsers();
  } catch (error) {
    if (Number(error?.status) === 401) {
      window.location.replace('/login');
      return;
    }

    showAccessMessage(
      error?.message || 'No fue posible actualizar el acceso.',
    );

    select.disabled = false;
    button.disabled =
      select.value === select.dataset.originalRoleCode;
    button.textContent = 'Aplicar';
  }
}

function showAccessMessage(message) {
  if (!accessMessage) return;

  accessMessage.textContent = message;
  accessMessage.hidden = false;
}

function clearAccessMessage() {
  if (!accessMessage) return;

  accessMessage.textContent = '';
  accessMessage.hidden = true;
}
function createAccessList(applications) {
  const wrapper = document.createElement('div');
  wrapper.className = 'users-access-list';

  if (!applications.length) {
    const empty = document.createElement('span');

    empty.className = 'users-access-empty';
    empty.textContent = 'Sin aplicaciones asignadas';

    wrapper.append(empty);

    return wrapper;
  }

  applications.forEach((application) => {
    const item = document.createElement('div');
    item.className = 'users-access';

    const applicationName = document.createElement('span');
    applicationName.className = 'users-access__application';
    applicationName.textContent = application.name || application.code;

    const role = document.createElement('span');
    role.className = 'users-access__role';
    role.textContent =
      application.role?.name || application.role?.code || 'Sin rol';

    item.append(applicationName, role);
    wrapper.append(item);
  });

  return wrapper;
}

async function handleTableAction(event) {
  const button = event.target.closest('[data-action][data-user-id]');

  if (!button) return;

  const userId = Number(button.dataset.userId);
  const action = button.dataset.action;

  if (!userId || !action) return;

  button.disabled = true;

  try {
    if (action === 'resend') {
      await resendInvitation(userId);
    }

    if (action === 'revoke') {
      await revokeInvitation(userId);
    }

    if (action === 'access') {
      openAccessModal(userId);
    }
  } catch (error) {
    await handleTableActionError(error, action);
  } finally {
    button.disabled = false;
  }
}

async function handleTableActionError(error, action) {
  if (Number(error?.status) === 401) {
    window.location.replace('/login');
    return;
  }

  const message = error?.message || 'No fue posible completar la operación.';

  if (action === 'resend' && Number(error?.status) === 429) {
    await notify('Aún no puedes reenviar la invitación', message, 'warning');
    return;
  }

  const titles = {
    resend: 'No fue posible reenviar la invitación',
    revoke: 'No fue posible revocar la invitación',
    access: 'No fue posible abrir la administración de accesos',
  };

  await notify(
    titles[action] || 'No fue posible completar la operación',
    message,
    'error',
  );
}

async function resendInvitation(userId) {
  const accepted = await confirmAction(
    'Reenviar invitación',
    'Se generará un nuevo enlace personal para esta cuenta.',
  );

  if (!accepted) return;

  const payload = await apiRequest('/api/admin/users/resend-invitation', {
    method: 'POST',
    body: JSON.stringify({ user_id: userId }),
  });

  const delivered = Boolean(payload.data?.invitation_delivered);

  await notify(
    delivered ? 'Invitación reenviada' : 'Invitación generada',
    delivered
      ? 'El nuevo correo fue enviado.'
      : 'No fue posible entregar el nuevo correo. Revisa el estado de la invitación en la tabla antes de intentarlo nuevamente.',
    delivered ? 'success' : 'warning',
  );

  await loadUsers();
}

async function revokeInvitation(userId) {
  const accepted = await confirmAction(
    'Revocar invitación',
    'El enlace vigente dejará de funcionar. La cuenta permanecerá en estado Invitado.',
  );

  if (!accepted) return;

  await apiRequest('/api/admin/users/revoke-invitation', {
    method: 'POST',
    body: JSON.stringify({ user_id: userId }),
  });

  await notify(
    'Invitación revocada',
    'El enlace ya no puede utilizarse.',
    'success',
  );

  await loadUsers();
}

async function submitInvitation(event) {
  event.preventDefault();

  if (!inviteForm) return;

  clearInviteMessage();

  if (!inviteForm.reportValidity()) return;

  const formData = new FormData(inviteForm);

  const applications = Array.from(
    inviteAccesses?.querySelectorAll('[data-invite-application]') || [],
  )
    .filter((select) => select.value)
    .map((select) => ({
      application_code: select.dataset.inviteApplication,
      role_code: select.value,
    }));

  const data = {
    first_name: String(formData.get('first_name') || '').trim(),
    last_name: String(formData.get('last_name') || '').trim(),
    email: String(formData.get('email') || '').trim(),
    applications,
  };

  if (inviteSubmit) {
    inviteSubmit.disabled = true;
    inviteSubmit.textContent = 'Enviando…';
  }

  try {
    const payload = await apiRequest('/api/admin/users/create', {
      method: 'POST',
      body: JSON.stringify(data),
    });

    const createdUser = payload.data?.user || {};
    const delivered = Boolean(createdUser.invitation_delivered);

    closeInviteModal();
    inviteForm.reset();

    await notify(
      delivered ? 'Usuario invitado' : 'Usuario creado',
      delivered
        ? 'La invitación fue enviada por correo.'
        : 'La cuenta quedó creada, pero el correo no pudo enviarse. Puedes reenviarlo desde la tabla.',
      delivered ? 'success' : 'warning',
    );

    page = 1;
    await loadUsers();
  } catch (error) {
    showInviteMessage(error?.message || 'No fue posible crear la invitación.');
  } finally {
    if (inviteSubmit) {
      inviteSubmit.disabled = false;
      inviteSubmit.textContent = 'Enviar invitación';
    }
  }
}

function openInviteModal() {
  if (!inviteModal || !inviteForm) return;

  clearInviteMessage();
  inviteModal.hidden = false;
  document.body.style.overflow = 'hidden';

  inviteForm.querySelector('input')?.focus();
}

function closeInviteModal() {
  if (!inviteModal) return;

  inviteModal.hidden = true;
  document.body.style.overflow = '';
  clearInviteMessage();
}

function showInviteMessage(message) {
  if (!inviteMessage) return;

  inviteMessage.textContent = message;
  inviteMessage.hidden = false;
}

function clearInviteMessage() {
  if (!inviteMessage) return;

  inviteMessage.textContent = '';
  inviteMessage.hidden = true;
}

function renderPagination(value) {
  lastPagination = value;

  if (!value || Number(value.total || 0) === 0) {
    if (pagination) {
      pagination.hidden = true;
    }

    if (summary) {
      summary.textContent = '0 usuarios';
    }

    return;
  }

  const total = Number(value.total || 0);
  const pages = Number(value.pages || 1);

  page = Number(value.page || 1);

  if (summary) {
    summary.textContent = `${total} usuario${total === 1 ? '' : 's'}`;
  }

  if (pagination) {
    pagination.hidden = false;
  }

  if (pageLabel) {
    pageLabel.textContent = `Página ${page} de ${pages}`;
  }

  if (prevButton) {
    prevButton.disabled = page <= 1;
  }

  if (nextButton) {
    nextButton.disabled = page >= pages;
  }
}

function setLoading() {
  if (!tableBody) return;

  tableBody.innerHTML = `
    <tr>
      <td colspan="8" class="users-table__muted">
        Cargando usuarios…
      </td>
    </tr>
  `;
}

function showError(message) {
  if (!errorBox) return;

  errorBox.hidden = false;
  errorBox.textContent = message;
}

function hideError() {
  if (!errorBox) return;

  errorBox.hidden = true;
  errorBox.textContent = '';
}

function handleRequestError(error) {
  if (Number(error?.status) === 401) {
    window.location.replace('/login');
    return;
  }

  if (Number(error?.status) === 403) {
    window.location.replace('/');
    return;
  }

  if (tableBody) {
    tableBody.innerHTML = `
      <tr>
        <td colspan="8" class="users-table__muted">
          No fue posible cargar los usuarios.
        </td>
      </tr>
    `;
  }

  showError(error?.message || 'No fue posible cargar el módulo de usuarios.');
}

function statusLabel(status) {
  const labels = {
    active: 'Activo',
    inactive: 'Inactivo',
    blocked: 'Bloqueado',
    pending: 'Verificación pendiente',
    invited: 'Invitado',
  };

  return labels[status] || status || '—';
}

function invitationLabel(status) {
  const labels = {
    pending: 'Vigente',
    expired: 'Expirada',
    revoked: 'Revocada',
    used: 'Utilizada',
  };

  return labels[status] || status || '—';
}

function formatDateTime(value) {
  if (!value) return '—';

  const normalized = value.includes('T') ? value : value.replace(' ', 'T');

  const date = new Date(
    normalized.endsWith('Z') ? normalized : `${normalized}Z`,
  );

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat('es-MX', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
}

function formatInvitationExpiration(value) {
  if (!value) return '—';

  const normalized = value.includes('T') ? value : value.replace(' ', 'T');

  const date = new Date(
    normalized.endsWith('Z') ? normalized : `${normalized}Z`,
  );

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  const dateText = new Intl.DateTimeFormat('es-MX', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date);

  const timeText = new Intl.DateTimeFormat('es-MX', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: true,
  }).format(date);

  return `${dateText} · ${timeText}`;
}

async function confirmAction(title, text) {
  if (!window.Swal) {
    return window.confirm(`${title}\n\n${text}`);
  }

  const result = await window.Swal.fire({
    icon: 'question',
    title,
    text,
    showCancelButton: true,
    confirmButtonText: 'Continuar',
    cancelButtonText: 'Cancelar',
    customClass: {
      popup: 'app-alert',
      confirmButton: 'app-alert__confirm',
      cancelButton: 'app-alert__cancel',
      actions: 'app-alert__actions',
    },
    buttonsStyling: false,
  });

  return result.isConfirmed;
}

async function notify(title, text, icon = 'success') {
  if (!window.Swal) {
    window.alert(`${title}\n\n${text}`);
    return;
  }

  await window.Swal.fire({
    icon,
    title,
    text,
    confirmButtonText: 'Aceptar',
    customClass: {
      popup: 'app-alert',
      confirmButton: 'app-alert__confirm',
    },
    buttonsStyling: false,
  });
}

function setupComingSoonActions() {
  document.querySelectorAll('[data-coming-soon]').forEach((element) => {
    element.addEventListener('click', (event) => {
      event.preventDefault();

      window.Swal?.fire({
        icon: 'info',
        title: 'Próximamente',
        text: 'Esta función todavía se encuentra en desarrollo dentro de EU Tools.',
        confirmButtonText: 'Entendido',
        customClass: {
          popup: 'app-alert',
          confirmButton: 'app-alert__confirm',
        },
        buttonsStyling: false,
      });
    });
  });
}

function setupUserMenu() {
  const container = document.querySelector('[data-user-dropdown]');

  const trigger = document.querySelector('[data-user-trigger]');

  const panel = document.querySelector('[data-user-panel]');

  const logoutButton = document.querySelector('[data-logout]');

  const dropdown = setupDropdown({
    container,
    trigger,
    panel,
  });

  logoutButton?.addEventListener('click', async () => {
    logoutButton.disabled = true;

    try {
      await apiRequest('/api/auth/logout', {
        method: 'POST',
        body: JSON.stringify({}),
      });

      window.location.replace('/login');
    } catch (error) {
      logoutButton.disabled = false;

      window.Swal?.fire({
        icon: 'error',
        title: 'No fue posible cerrar sesión',
        text: error.message || 'Intenta nuevamente.',
        confirmButtonText: 'Entendido',
        customClass: {
          popup: 'app-alert',
          confirmButton: 'app-alert__confirm',
        },
        buttonsStyling: false,
      });
    } finally {
      dropdown.close();
    }
  });
}
