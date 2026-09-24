import { apiRequest } from '../core/api.js';
import {
  handleCoreBoundaryError,
  redirectToCorePasswordChange,
} from '../core/authBoundary.js';
import { applyPermissionVisibility } from '../core/permissions.js';
import { getMaintenanceUser } from '../core/session.js';
import { setupSidebar } from '../components/sidebar.js';
import { setupUserMenu } from '../components/userMenu.js';
import { setupNotificationsMenu } from '../components/notificationsMenu.js';
import { formatDateTime, formatRelativeTime } from '../utils/date.js';

const form = document.querySelector('[data-filter-form]');
const list = document.querySelector('[data-ticket-list]');
const listContent = document.querySelector('[data-ticket-list-content]');
const resultCount = document.querySelector('[data-result-count]');
const pageError = document.querySelector('[data-page-error]');
let user;
let summary;
let isReporterView = false;
let suppressResetLoad = false;

try {
  user = await getMaintenanceUser();
  isReporterView =
    Array.isArray(user.roles)
    && user.roles.includes('reporter');

  if (user.must_change_password) {
    user = null;
    redirectToCorePasswordChange();
  }
} catch (error) {
  if (!handleCoreBoundaryError(error)) {
    showPageError(
      error?.message
      || 'No fue posible validar tu sesión.'
    );
  }
}

if (user) {
  applyPermissionVisibility(user);
  setupSidebar();
  setupUserMenu(user);
  setupComingSoonActions();
  await setupNotifications();
  await initializePage();
}

async function initializePage() {
  try {
    const response = await apiRequest('./api/supervisor/summary.php');
    summary = response.data;
    renderScope(summary.scope);
    renderCounters(summary.counters || {});
    applyReporterTicketPresentation();
    populateCatalogs(summary);
    bindFilters();
    await loadTickets();
  } catch (error) {
    if (handleAuthError(error)) {
      return;
    }

    showPageError(
      error.message
      || 'No fue posible cargar la bandeja de tickets.'
    );
  }
}

async function setupNotifications() {
  try {
    const response = await apiRequest('./api/dashboard/summary.php');
    setupNotificationsMenu({
      unreadCount: Number(response.data.unread_notifications || 0),
      notifications: response.data.notifications || [],
    });
  } catch {
    setupNotificationsMenu();
  }
}

function renderScope(scope) {
  const title = document.querySelector('[data-scope-title]');
  const description = document.querySelector('[data-scope-description]');
  if (title) title.textContent = scope?.name || 'Tickets';
  if (description) {
    description.textContent = scope?.code === 'assigned'
      ? 'Gestiona los reportes que tienes asignados y las acciones que requieren tu atención.'
      : 'Consulta reportes, prioridades y acciones pendientes dentro de tu alcance.';
  }
}

function renderCounters(counters) {
  document.querySelectorAll('[data-counter]').forEach((element) => {
    const key = element.dataset.counter;
    element.classList.remove('loading-line');
    element.textContent = String(Number(counters[key] || 0));
  });
}

function applyReporterTicketPresentation() {
  if (!isReporterView) return;

  ['quotation_pending', 'changes_requested', 'authorized'].forEach((status) => {
    document.querySelector(`[data-counter-filter="${status}"]`)?.setAttribute('hidden', '');
  });

  const managementCard = document.querySelector('[data-counter-filter="under_review"]');
  managementCard?.querySelector('.metric-card__label')?.replaceChildren('En gestión');
  managementCard?.querySelector('.metric-card__meta')?.replaceChildren('Seguimiento del reporte');

  const routeField = form?.elements.route?.closest('.form-field');
  if (routeField) routeField.hidden = true;

  const attentionField = form?.elements.attention?.closest('.checkbox-field');
  if (attentionField) attentionField.hidden = true;
}

function populateCatalogs(data) {
  const statusSelect = form?.elements.status;
  const locationSelect = form?.elements.location_id;
  if (statusSelect) {
    (data.statuses || []).forEach((status) => {
      const option = document.createElement('option');
      option.value = status.code;
      option.textContent = status.name;
      statusSelect.append(option);
    });
  }
  if (locationSelect) {
    (data.locations || []).forEach((location) => {
      const option = document.createElement('option');
      option.value = String(location.id);
      option.textContent = location.name;
      locationSelect.append(option);
    });
  }
}

function bindFilters() {
  if (!form) return;
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    clearMetricSelection();
    await loadTickets();
  });

  form.addEventListener('reset', () => {
    if (suppressResetLoad) return;
    window.setTimeout(async () => {
      clearMetricSelection();
      await loadTickets();
    }, 0);
  });

  document.querySelectorAll('[data-counter-filter]').forEach((button) => {
    button.addEventListener('click', async () => {
      const filter = button.dataset.counterFilter;
      clearMetricSelection();
      button.classList.add('is-active');
      suppressResetLoad = true;
      form.reset();
      suppressResetLoad = false;
      if (filter === 'attention') {
        form.elements.attention.checked = true;
      } else {
        form.elements.status.value = filter;
      }
      await loadTickets();
    });
  });
}

function clearMetricSelection() {
  document.querySelectorAll('[data-counter-filter]').forEach((button) => button.classList.remove('is-active'));
}

async function loadTickets() {
  setListLoading();
  hidePageError();
  try {
    const params = new URLSearchParams();
    if (form) {
      const data = new FormData(form);
      for (const [key, value] of data.entries()) {
        const normalized = String(value).trim();
        if (normalized !== '') params.set(key, normalized);
      }
    }
    const url = `./api/supervisor/tickets.php${params.size ? `?${params.toString()}` : ''}`;
    const response = await apiRequest(url);
    renderTickets(response.data.items || []);
    if (resultCount) {
      const total = Number(response.data.total || 0);
      resultCount.textContent = `${total} ${total === 1 ? 'ticket' : 'tickets'}`;
    }
  } catch (error) {
    handleAuthError(error);
    renderListError(error.message || 'No fue posible cargar los tickets.');
  }
}

function setListLoading() {
  if (!list) return;
  list.innerHTML = `<tr><td colspan="${isReporterView ? 9 : 10}" class="data-table__muted">Cargando tickets…</td></tr>`;
  if (resultCount) resultCount.textContent = 'Cargando…';
}

function renderTickets(items) {
  if (!list || !listContent) return;
  if (!Array.isArray(items) || items.length === 0) {
    listContent.replaceChildren(createEmptyState(
      'No hay tickets con estos filtros',
      'Modifica los criterios para ampliar la búsqueda.'
    ));
    return;
  }

  const tableScroll = document.createElement('div');
  tableScroll.className = 'table-scroll';
  const table = document.createElement('table');
  table.className = 'data-table supervisor-table';
  table.innerHTML = isReporterView
    ? '<thead><tr><th>Folio</th><th>Reporte</th><th>Ubicación</th><th>Prioridad</th><th>Estado</th><th>Reportante</th><th>Seguimiento</th><th>Actualización</th><th>Acción</th></tr></thead>'
    : '<thead><tr><th>Folio</th><th>Reporte</th><th>Ubicación</th><th>Prioridad</th><th>Estado</th><th>Ruta</th><th>Reportante</th><th>Siguiente acción</th><th>Actualización</th><th>Acción</th></tr></thead>';
  const body = document.createElement('tbody');
  items.forEach((item) => body.append(createTicketRow(item)));
  table.append(body);
  tableScroll.append(table);
  listContent.replaceChildren(tableScroll);
}

function createTicketRow(item) {
  const row = document.createElement('tr');
  const cells = [
    createCell(item.folio, 'data-table__folio'),
    createReportCell(item),
    createLocationCell(item),
    createBadgeCell('priority-badge', 'priority', item.priority?.code, item.priority?.name),
    createBadgeCell('status-badge', 'status', item.status?.code, item.status?.name),
  ];
  if (!isReporterView) cells.push(createRouteCell(item.processing_route));
  cells.push(
    createCell(item.reporter?.full_name, 'data-table__muted'),
    createActionCopyCell(item),
    createUpdatedCell(item.updated_at),
    createLinkCell(item.id)
  );
  row.append(...cells);
  return row;
}

function createReportCell(item) {
  const cell = document.createElement('td');
  cell.className = 'data-table__title';
  const title = document.createElement('strong');
  title.textContent = item.title || 'Sin título';
  cell.append(title);
  return cell;
}

function createLocationCell(item) {
  const cell = document.createElement('td');
  const wrapper = document.createElement('span');
  wrapper.className = 'ticket-action-copy';
  const location = document.createElement('strong');
  location.textContent = item.location?.name || '—';
  const zone = document.createElement('span');
  zone.textContent = item.specific_location || '—';
  wrapper.append(location, zone);
  cell.append(wrapper);
  return cell;
}

function createBadgeCell(className, dataName, code, name) {
  const cell = document.createElement('td');
  const badge = document.createElement('span');
  badge.className = className;
  badge.dataset[dataName] = code || '';
  badge.textContent = name || '—';
  cell.append(badge);
  return cell;
}

function createRouteCell(route) {
  const cell = document.createElement('td');
  const badge = document.createElement('span');
  badge.className = 'route-badge';
  badge.dataset.route = route || '';
  badge.textContent = routeLabel(route);
  cell.append(badge);
  return cell;
}

function createActionCopyCell(item) {
  const cell = document.createElement('td');
  const wrapper = document.createElement('span');
  wrapper.className = 'ticket-action-copy';
  const title = document.createElement('strong');
  const owner = document.createElement('span');
  if (isReporterView || item.public_view) {
    const publicLabels = {
      new: 'Pendiente de revisión',
      under_review: 'Seguimiento interno',
      in_progress: 'Trabajo en ejecución',
      closed: 'Flujo finalizado',
    };
    title.textContent = publicLabels[item.status?.code] || 'Seguimiento del reporte';
    owner.textContent = item.status?.is_terminal ? 'Sin acción pendiente' : 'Equipo de mantenimiento';
  } else {
    title.textContent = nextActionLabel(item.status?.code, item.processing_route);
    owner.textContent = item.action_owner?.full_name || 'Sin responsable actual';
  }
  wrapper.append(title, owner);
  cell.append(wrapper);
  return cell;
}

function createUpdatedCell(value) {
  const cell = document.createElement('td');
  cell.className = 'data-table__muted';
  const time = document.createElement('time');
  time.textContent = formatRelativeTime(value);
  time.title = formatDateTime(value);
  cell.append(time);
  return cell;
}

function createLinkCell(id) {
  const cell = document.createElement('td');
  const link = document.createElement('a');
  link.className = 'table-action';
  link.href = `./ticket.html?id=${encodeURIComponent(id)}`;
  link.textContent = 'Ver ticket';
  cell.append(link);
  return cell;
}

function createCell(text, className = '') {
  const cell = document.createElement('td');
  cell.textContent = text || '—';
  if (className) cell.className = className;
  return cell;
}

function routeLabel(route) {
  return ({
    direct: 'Atención directa',
    authorization_required: 'Con autorización',
  })[route] || 'Sin definir';
}

function nextActionLabel(status, route) {
  const labels = {
    new: 'Iniciar revisión',
    under_review: route ? 'Continuar seguimiento' : 'Definir ruta',
    quotation_pending: 'Preparar cotización',
    authorization_pending: 'Esperar decisión de Dirección',
    changes_requested: 'Atender correcciones',
    authorized: 'Iniciar ejecución',
    in_progress: 'Ejecutar reparación',
    completed: 'Cierre automático',
    closed: 'Flujo finalizado',
    rejected: 'Solicitud rechazada',
    cancelled: 'Ticket cancelado',
  };
  return labels[status] || 'Revisar expediente';
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

function renderListError(message) {
  if (listContent) listContent.replaceChildren(createEmptyState('No fue posible cargar la bandeja', message));
  if (resultCount) resultCount.textContent = 'Error';
}

function showPageError(message) {
  if (!pageError) return;
  pageError.hidden = false;
  pageError.textContent = message;
}

function hidePageError() {
  if (pageError) pageError.hidden = true;
}

function handleAuthError(error) {
  return handleCoreBoundaryError(error);
}

function setupComingSoonActions() {
  document.querySelectorAll('[data-coming-soon]').forEach((element) => {
    element.addEventListener('click', async (event) => {
      event.preventDefault();
      if (!window.Swal) return;
      await window.Swal.fire({
        icon: 'info',
        title: 'Módulo en preparación',
        text: 'Esta funcionalidad se incorporará en una fase posterior.',
        confirmButtonText: 'Entendido',
        customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
      });
    });
  });
}
