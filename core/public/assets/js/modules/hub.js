import { apiRequest } from '../core/api.js';
import { setupDropdown } from '../components/dropdown.js';
import { setupSidebar } from '../components/sidebar.js';

const toolsGrid = document.querySelector('[data-tools-grid]');

setupSidebar();
setupUserMenu();
loadApplications();

async function loadApplications() {
  if (!toolsGrid) return;

  try {
    const payload = await apiRequest('/api/hub/apps');
    const applications = payload?.data?.applications || [];

    renderApplications(applications);
  } catch (error) {
    toolsGrid.innerHTML = '';

    const card = document.createElement('article');
    card.className = 'tool-card';

    const body = document.createElement('div');
    body.className = 'tool-card__body';

    const title = document.createElement('h2');
    title.textContent = 'No fue posible cargar las herramientas';

    const message = document.createElement('p');
    message.textContent =
      error.message || 'Intenta nuevamente en unos momentos.';

    body.append(title, message);
    card.append(body);
    toolsGrid.append(card);
  }
}

function renderApplications(applications) {
  toolsGrid.innerHTML = '';

  if (applications.length === 0) {
    const card = document.createElement('article');
    card.className = 'tool-card';

    const body = document.createElement('div');
    body.className = 'tool-card__body';

    const title = document.createElement('h2');
    title.textContent = 'Sin herramientas disponibles';

    const message = document.createElement('p');
    message.textContent =
      'No hay aplicaciones visibles actualmente en EU Tools.';

    body.append(title, message);
    card.append(body);
    toolsGrid.append(card);

    return;
  }

  applications.forEach((application) => {
    toolsGrid.append(createApplicationCard(application));
  });
}

function createApplicationCard(application) {
  const article = document.createElement('article');

  article.className = 'tool-card';
  article.dataset.state = application.status || 'coming-soon';

  const header = document.createElement('div');
  header.className = 'tool-card__header';

  const icon = document.createElement('span');
  icon.className = 'tool-card__icon';

  const svg = document.createElementNS(
    'http://www.w3.org/2000/svg',
    'svg'
  );

  svg.setAttribute('aria-hidden', 'true');

  const use = document.createElementNS(
    'http://www.w3.org/2000/svg',
    'use'
  );

  use.setAttribute(
    'href',
    `#${resolveIcon(application.icon)}`
  );

  svg.append(use);
  icon.append(svg);

  const status = document.createElement('span');
  status.className = 'tool-card__status';
  status.textContent = statusLabel(application);

  header.append(icon, status);

  const body = document.createElement('div');
  body.className = 'tool-card__body';

  const title = document.createElement('h2');
  title.textContent = application.name;

  const description = document.createElement('p');
  description.textContent =
    application.description || 'Herramienta de EU Tools.';

  body.append(title, description);

  const footer = document.createElement('footer');
  footer.className = 'tool-card__footer';

  const meta = document.createElement('span');
  meta.className = 'tool-card__meta';
  meta.textContent = application.category || 'EU Tools';

  footer.append(meta);

  const action = createApplicationAction(application);

  if (action) {
    footer.append(action);
  }

  article.append(header, body, footer);

  return article;
}

function createApplicationAction(application) {
  if (application.status === 'coming_soon') {
    return null;
  }

  const button = document.createElement('button');

  button.type = 'button';
  button.className = 'button button--text';

  if (application.can_launch) {
    button.textContent = 'Abrir';

    button.addEventListener('click', () => {
      window.location.assign(application.base_path);
    });

    return button;
  }

  if (
    application.is_active &&
    application.status === 'available' &&
    !application.can_access
  ) {
    button.textContent = 'Ver herramienta';

    button.addEventListener('click', () => {
      showAccessDenied(application);
    });

    return button;
  }

  button.textContent = 'Ver herramienta';

  button.addEventListener('click', () => {
    showIntegrationNotice(application);
  });

  return button;
}

function statusLabel(application) {
  if (application.status === 'coming_soon') {
    return 'Próximamente';
  }

  if (application.status === 'integration') {
    return 'En integración';
  }

  if (application.can_access) {
    return 'Disponible';
  }

  return 'Acceso restringido';
}

function resolveIcon(icon) {
  const icons = {
    maintenance: 'icon-maintenance',
    events: 'icon-events',
    intelligence: 'icon-intelligence',
    analytics: 'icon-analytics',
  };

  return icons[icon] || 'icon-tools';
}

function showAccessDenied(application) {
  window.Swal?.fire({
    icon: 'info',
    title: 'Acceso restringido',
    text: `Tu cuenta no tiene acceso a ${application.name}.`,
    confirmButtonText: 'Entendido',
    customClass: {
      popup: 'app-alert',
      confirmButton: 'app-alert__confirm',
    },
    buttonsStyling: false,
  });
}

function showIntegrationNotice(application) {
  window.Swal?.fire({
    icon: 'info',
    title: application.name,
    text:
      'Esta herramienta se encuentra en proceso de integración con EU Tools.',
    confirmButtonText: 'Entendido',
    customClass: {
      popup: 'app-alert',
      confirmButton: 'app-alert__confirm',
    },
    buttonsStyling: false,
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