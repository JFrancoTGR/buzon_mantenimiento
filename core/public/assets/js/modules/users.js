import { apiRequest } from '../core/api.js';
import { setupDropdown } from '../components/dropdown.js';
import { setupSidebar } from '../components/sidebar.js';

const tableBody = document.querySelector('[data-users-table]');
const summary = document.querySelector('[data-users-summary]');
const errorBox = document.querySelector('[data-users-error]');

const searchFilter = document.querySelector('[data-filter-search]');
const applicationFilter = document.querySelector(
  '[data-filter-application]'
);
const statusFilter = document.querySelector('[data-filter-status]');

const pagination = document.querySelector('[data-pagination]');
const pageLabel = document.querySelector('[data-page-label]');
const prevButton = document.querySelector('[data-page-prev]');
const nextButton = document.querySelector('[data-page-next]');

let page = 1;
let lastPagination = null;

setupSidebar();
setupUserMenu();
setupComingSoonActions();
initialize();

async function initialize() {
  try {
    const payload = await apiRequest(
      '/api/admin/users/context'
    );

    populateContext(payload.data);
    bindEvents();

    await loadUsers();
  } catch (error) {
    handleRequestError(error);
  }
}

function populateContext(context) {
  populateApplications(context?.applications || []);
  populateStatuses(context?.statuses || []);
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
    if (
      !lastPagination
      || page >= Number(lastPagination.pages || 1)
    ) {
      return;
    }

    page += 1;

    await loadUsers();
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
      `/api/admin/users/list?${params.toString()}`
    );

    renderUsers(payload.data?.items || []);
    renderPagination(payload.data?.pagination || null);
  } catch (error) {
    handleRequestError(error);
  }
}

function renderUsers(items) {
  tableBody?.replaceChildren();

  if (!items.length) {
    if (tableBody) {
      tableBody.innerHTML = `
        <tr>
          <td colspan="5" class="users-table__muted">
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

  row.append(
    identity,
    status,
    applications,
    lastLogin,
    created
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
    applicationName.textContent =
      application.name || application.code;

    const role = document.createElement('span');
    role.className = 'users-access__role';
    role.textContent =
      application.role?.name
      || application.role?.code
      || 'Sin rol';

    item.append(applicationName, role);
    wrapper.append(item);
  });

  return wrapper;
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
    summary.textContent =
      `${total} usuario${total === 1 ? '' : 's'}`;
  }

  if (pagination) {
    pagination.hidden = false;
  }

  if (pageLabel) {
    pageLabel.textContent =
      `Página ${page} de ${pages}`;
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
      <td colspan="5" class="users-table__muted">
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
        <td colspan="5" class="users-table__muted">
          No fue posible cargar los usuarios.
        </td>
      </tr>
    `;
  }

  showError(
    error?.message
    || 'No fue posible cargar el módulo de usuarios.'
  );
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

function formatDateTime(value) {
  if (!value) return '—';

  const normalized = value.includes('T')
    ? value
    : value.replace(' ', 'T');

  const date = new Date(
    normalized.endsWith('Z')
      ? normalized
      : `${normalized}Z`
  );

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat('es-MX', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
}

function setupComingSoonActions() {
  document
    .querySelectorAll('[data-coming-soon]')
    .forEach((element) => {
      element.addEventListener('click', (event) => {
        event.preventDefault();

        window.Swal?.fire({
          icon: 'info',
          title: 'Próximamente',
          text:
            'Esta función todavía se encuentra en desarrollo dentro de EU Tools.',
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
  const container = document.querySelector(
    '[data-user-dropdown]'
  );

  const trigger = document.querySelector(
    '[data-user-trigger]'
  );

  const panel = document.querySelector(
    '[data-user-panel]'
  );

  const logoutButton = document.querySelector(
    '[data-logout]'
  );

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