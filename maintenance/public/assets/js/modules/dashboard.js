import { apiRequest } from '../core/api.js';
import {
  handleCoreBoundaryError,
  redirectToCorePasswordChange,
} from '../core/authBoundary.js';
import { applyPermissionVisibility } from '../core/permissions.js';
import { setupSidebar } from '../components/sidebar.js';
import { setupUserMenu } from '../components/userMenu.js';
import { setupNotificationsMenu } from '../components/notificationsMenu.js';
import { formatDateTime, formatRelativeTime } from '../utils/date.js';

const dashboardError = document.querySelector('[data-dashboard-error]');

let user;
let isReporterView = false;

try {
  const payload = await apiRequest('./api/auth/me.php');
  user = payload.data.user;
  isReporterView =
    Array.isArray(user.roles)
    && user.roles.includes('reporter');

  if (user.must_change_password) {
    user = null;
    redirectToCorePasswordChange();
  }
} catch (error) {
  if (
    !handleCoreBoundaryError(error)
    && dashboardError
  ) {
    dashboardError.hidden = false;
    dashboardError.textContent =
      error?.message
      || 'No fue posible validar tu sesión.';
  }
}

if (user) {
  applyPermissionVisibility(user);
  setupSidebar();
  setupUserMenu(user);
  setupComingSoonActions();
  applyReporterDashboardPresentation();
  await loadDashboard();
}

function applyReporterDashboardPresentation() {
  if (!isReporterView) return;
  const authorizationCard = document.querySelector('[data-status="authorization_pending"]');
  if (authorizationCard) authorizationCard.hidden = true;
  const managementCard = document.querySelector('[data-status="under_review"]');
  managementCard?.querySelector('.metric-card__label')?.replaceChildren('En gestión');
  managementCard?.querySelector('.metric-card__meta')?.replaceChildren('Seguimiento del reporte');
}

async function loadDashboard() {
  const results = await Promise.allSettled([
    apiRequest('./api/dashboard/summary.php'),
    apiRequest('./api/dashboard/pending-actions.php'),
    apiRequest('./api/dashboard/recent-activity.php'),
  ]);

  const [summaryResult, pendingResult, activityResult] = results;
  let partialFailure = false;

  if (summaryResult.status === 'fulfilled') {
    renderCounters(summaryResult.value.data.counters || {});
    setupNotificationsMenu({
      unreadCount: Number(summaryResult.value.data.unread_notifications || 0),
      notifications: summaryResult.value.data.notifications || [],
    });
  } else {
    partialFailure = true;
    renderCounters({});
    setupNotificationsMenu();
    handleAuthenticationError(summaryResult.reason);
  }

  if (pendingResult.status === 'fulfilled') {
    renderPendingActions(pendingResult.value.data.items || []);
  } else {
    partialFailure = true;
    renderPendingError();
    handleAuthenticationError(pendingResult.reason);
  }

  if (activityResult.status === 'fulfilled') {
    renderRecentActivity(activityResult.value.data.items || []);
  } else {
    partialFailure = true;
    renderActivityError();
    handleAuthenticationError(activityResult.reason);
  }

  if (partialFailure && dashboardError) {
    dashboardError.hidden = false;
    dashboardError.textContent = 'Algunos datos no pudieron cargarse. La sesión continúa activa; recarga la página para intentar nuevamente.';
  }
}

function renderCounters(counters) {
  document.querySelectorAll('[data-counter]').forEach((element) => {
    const key = element.dataset.counter;
    element.classList.remove('loading-line');
    element.removeAttribute('aria-label');
    element.textContent = String(Number(counters[key] || 0));
  });
}

function renderPendingActions(items) {
  const tableBody = document.querySelector('[data-pending-table]');
  const content = document.querySelector('[data-pending-content]');
  if (!tableBody || !content) return;

  if (!Array.isArray(items) || items.length === 0) {
    content.replaceChildren(createEmptyState(
      'No hay acciones pendientes',
      'Los tickets que requieran atención dentro de tu alcance aparecerán en esta sección.'
    ));
    return;
  }

  tableBody.replaceChildren();
  items.forEach((item) => tableBody.append(createPendingRow(item)));
}

function createPendingRow(item) {
  const row = document.createElement('tr');

  row.append(
    createCell(item.folio, 'data-table__folio'),
    createCell(item.title, 'data-table__title'),
    createCell(item.location, 'data-table__muted')
  );

  const priorityCell = document.createElement('td');
  const priority = document.createElement('span');
  priority.className = 'priority-badge';
  priority.dataset.priority = item.priority?.code || '';
  priority.textContent = item.priority?.name || 'Sin prioridad';
  priorityCell.append(priority);
  row.append(priorityCell);

  const statusCell = document.createElement('td');
  const status = document.createElement('span');
  status.className = 'status-badge';
  status.dataset.status = item.status?.code || '';
  status.textContent = item.status?.name || 'Sin estado';
  statusCell.append(status);
  row.append(statusCell);

  const updatedCell = createCell(formatDateTime(item.updated_at), 'data-table__muted');
  row.append(updatedCell);

  const actionCell = document.createElement('td');
  const action = document.createElement('a');
  action.className = 'table-action';
  action.href = `./ticket.html?id=${encodeURIComponent(item.id)}`;
  action.textContent = 'Ver ticket';
  actionCell.append(action);
  row.append(actionCell);

  return row;
}

function createCell(text, className = '') {
  const cell = document.createElement('td');
  cell.textContent = text || '—';
  if (className) cell.className = className;
  return cell;
}

function renderRecentActivity(items) {
  const content = document.querySelector('[data-activity-content]');
  if (!content) return;

  if (!Array.isArray(items) || items.length === 0) {
    content.replaceChildren(createEmptyState(
      'Sin actividad reciente',
      'Los cambios de estado y movimientos relevantes se mostrarán aquí.'
    ));
    return;
  }

  const list = document.createElement('div');
  list.className = 'activity-list';

  items.forEach((item) => {
    const article = document.createElement('article');
    article.className = 'activity-item';

    const dot = document.createElement('span');
    dot.className = 'activity-item__dot';
    dot.setAttribute('aria-hidden', 'true');

    const body = document.createElement('div');
    body.className = 'activity-item__content';

    const description = document.createElement('p');
    const actor = ['system', 'migration'].includes(item.source)
      ? 'El sistema'
      : (item.actor_name || 'El sistema');
    description.append(document.createTextNode(`${actor} cambió `));
    const folio = document.createElement('strong');
    folio.textContent = item.folio || 'un ticket';
    description.append(folio, document.createTextNode(` a ${item.status?.name || 'otro estado'}.`));

    const time = document.createElement('time');
    time.textContent = formatRelativeTime(item.created_at);
    time.title = formatDateTime(item.created_at);
    if (item.created_at) time.dateTime = item.created_at;

    body.append(description, time);
    article.append(dot, body);
    list.append(article);
  });

  content.replaceChildren(list);
}

function createEmptyState(titleText, descriptionText) {
  const wrapper = document.createElement('div');
  wrapper.className = 'empty-state';

  const content = document.createElement('div');
  const icon = document.createElement('span');
  icon.className = 'empty-state__icon';
  icon.textContent = '✓';

  const title = document.createElement('h3');
  title.textContent = titleText;

  const description = document.createElement('p');
  description.textContent = descriptionText;

  content.append(icon, title, description);
  wrapper.append(content);
  return wrapper;
}

function renderPendingError() {
  const content = document.querySelector('[data-pending-content]');
  if (content) content.replaceChildren(createEmptyState('No fue posible cargar los tickets', 'Recarga la página para volver a intentarlo.'));
}

function renderActivityError() {
  const content = document.querySelector('[data-activity-content]');
  if (content) content.replaceChildren(createEmptyState('No fue posible cargar la actividad', 'Recarga la página para volver a intentarlo.'));
}

function handleAuthenticationError(error) {
  return handleCoreBoundaryError(error);
}

function setupComingSoonActions() {
  document.querySelectorAll('[data-coming-soon]').forEach((element) => {
    element.addEventListener('click', (event) => {
      event.preventDefault();
      showComingSoonAlert();
    });
  });
}

async function showComingSoonAlert() {
  if (!window.Swal) return;

  await window.Swal.fire({
    icon: 'info',
    title: 'Módulo en preparación',
    text: 'Esta funcionalidad se incorporará en la siguiente fase del proyecto.',
    confirmButtonText: 'Entendido',
    customClass: {
      popup: 'app-alert',
      confirmButton: 'app-alert__confirm',
    },
  });
}
