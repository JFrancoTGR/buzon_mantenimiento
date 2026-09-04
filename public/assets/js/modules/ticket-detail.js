import { apiRequest, apiFormRequest } from '../core/api.js';
import { applyPermissionVisibility } from '../core/permissions.js';
import { setupSidebar } from '../components/sidebar.js';
import { setupUserMenu } from '../components/userMenu.js';
import { setupNotificationsMenu } from '../components/notificationsMenu.js';
import { formatDateTime, formatRelativeTime } from '../utils/date.js';

const ticketId = Number.parseInt(new URLSearchParams(window.location.search).get('id') || '', 10);
const pageError = document.querySelector('[data-page-error]');
const loading = document.querySelector('[data-ticket-loading]');
const content = document.querySelector('[data-ticket-content]');
const commentForm = document.querySelector('[data-comment-form]');
const quotationForm = document.querySelector('[data-quotation-form]');
const approvalForm = document.querySelector('[data-approval-form]');
const completionForm = document.querySelector('[data-completion-form]');
let user;
let detail;

try {
  const payload = await apiRequest('./api/auth/me.php');
  user = payload.data.user;
  if (user.must_change_password) {
    user = null;
    window.location.replace('./change-password.html');
  }
} catch {
  const returnPath = Number.isInteger(ticketId) && ticketId > 0
    ? `./ticket.html?id=${ticketId}`
    : './dashboard.html';
  window.location.replace(`./login.html?return=${encodeURIComponent(returnPath)}`);
}

if (user) {
  applyPermissionVisibility(user);
  setupSidebar();
  setupUserMenu(user);
  setupComingSoonActions();
  await setupNotifications();

  if (!Number.isInteger(ticketId) || ticketId < 1) {
    showFatalError('El enlace del ticket no es válido.');
  } else {
    await loadTicket();
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

async function loadTicket() {
  setLoading(true);
  try {
    const response = await apiRequest(`./api/tickets/detail.php?id=${encodeURIComponent(ticketId)}`);
    detail = response.data;
    renderTicket(detail);
    setLoading(false);
  } catch (error) {
    handleAuthError(error);
    showFatalError(error.message || 'No fue posible cargar el ticket.');
  }
}

function renderTicket(data) {
  const ticket = data.ticket;
  document.title = `${ticket.folio} | Plataforma de Mantenimiento`;
  setText('[data-breadcrumb-folio]', ticket.folio);
  setText('[data-ticket-folio]', ticket.folio);
  setText('[data-ticket-title]', ticket.title);
  setText('[data-ticket-subtitle]', `${ticket.location.name} · ${ticket.specific_location}`);
  setText('[data-ticket-description]', ticket.description);
  setText('[data-location-name]', ticket.location.name);
  setText('[data-specific-location]', ticket.specific_location);
  setText('[data-submitted-at]', formatDateTime(ticket.submitted_at));
  setText('[data-updated-at]', formatDateTime(ticket.updated_at));
  setText('[data-processing-route]', ticket.processing_route_label || routeLabel(ticket.processing_route));

  const status = document.querySelector('[data-ticket-status]');
  if (status) {
    status.dataset.status = ticket.status.code;
    status.textContent = ticket.status.name;
  }

  const priority = document.querySelector('[data-ticket-priority]');
  if (priority) {
    priority.dataset.priority = ticket.priority.code;
    priority.textContent = ticket.priority.name;
  }

  renderActions(data.capabilities?.allowed_transitions || []);
  renderGallery(data.attachments || []);
  renderQuotations(data.quotations || [], data.capabilities || {}, ticket);
  renderApprovals(data.approval_requests || [], data.eligible_directors || [], data.capabilities || {}, ticket);
  renderCompletion(data.completion_evidence || [], data.capabilities || {}, ticket);
  renderComments(data.comments || [], Boolean(data.capabilities?.can_comment));
  renderTimeline(data.status_history || [], data.assignment_history || []);
  renderPeople(ticket, data.capabilities || {});
  renderNextAction(ticket, data.capabilities || {});
}

function renderActions(transitions) {
  const container = document.querySelector('[data-ticket-actions]');
  if (!container) return;
  container.replaceChildren();

  const back = document.createElement('a');
  back.className = 'button button--secondary';
  back.href = './tickets.html';
  back.textContent = 'Volver a tickets';
  container.append(back);

  const reviewTransition = transitions.find((item) => item.to_status?.code === 'under_review');
  if (reviewTransition) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'button button--primary';
    button.innerHTML = '<svg aria-hidden="true"><use href="#icon-review"></use></svg><span>Iniciar revisión</span>';
    button.addEventListener('click', () => startReview(reviewTransition, button));
    container.append(button);
  }
}

async function startReview(transition, button) {
  if (!window.Swal || !detail) return;

  const result = await window.Swal.fire({
    icon: 'question',
    title: 'Iniciar revisión',
    text: 'El ticket cambiará a En revisión y quedará registrado en el historial.',
    input: 'textarea',
    inputLabel: 'Comentario opcional',
    inputPlaceholder: 'Agrega una nota sobre la revisión inicial…',
    inputAttributes: { maxlength: '3000' },
    showCancelButton: true,
    confirmButtonText: 'Iniciar revisión',
    cancelButtonText: 'Cancelar',
    reverseButtons: true,
    customClass: {
      popup: 'app-alert',
      confirmButton: 'app-alert__confirm',
    },
  });

  if (!result.isConfirmed) return;
  button.disabled = true;
  button.classList.add('is-loading');

  try {
    const response = await apiRequest('./api/tickets/transition.php', {
      method: 'POST',
      body: JSON.stringify({
        ticket_id: detail.ticket.id,
        to_status: transition.to_status.code,
        comment: String(result.value || '').trim(),
        row_version: detail.ticket.row_version,
      }),
    });
    detail = response.data;
    renderTicket(detail);
    await window.Swal.fire({
      icon: 'success',
      title: 'Revisión iniciada',
      text: 'El estado del ticket se actualizó correctamente.',
      confirmButtonText: 'Continuar',
      customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
    });
  } catch (error) {
    handleAuthError(error);
    await showErrorAlert(error.message || 'No fue posible actualizar el ticket.');
    if (Number(error.status) === 409) await loadTicket();
  } finally {
    button.disabled = false;
    button.classList.remove('is-loading');
  }
}

function renderGallery(attachments) {
  const gallery = document.querySelector('[data-ticket-gallery]');
  const count = document.querySelector('[data-attachments-count]');
  if (!gallery) return;

  count.textContent = `${attachments.length} ${attachments.length === 1 ? 'archivo' : 'archivos'}`;
  gallery.replaceChildren();

  if (attachments.length === 0) {
    gallery.append(createEmptyMessage('No hay evidencias adjuntas.'));
    return;
  }

  attachments.forEach((attachment) => {
    const item = document.createElement('article');
    item.className = 'ticket-gallery__item';

    if (String(attachment.mime_type).startsWith('image/')) {
      const preview = document.createElement('button');
      preview.type = 'button';
      preview.className = 'ticket-gallery__button';
      preview.setAttribute('aria-label', `Ampliar ${attachment.original_name}`);
      const image = document.createElement('img');
      image.src = attachment.url;
      image.alt = attachment.original_name;
      image.loading = 'lazy';
      preview.append(image);
      preview.addEventListener('click', () => openAttachment(attachment));
      item.append(preview);
    }

    const meta = document.createElement('div');
    meta.className = 'ticket-gallery__meta';
    const copy = document.createElement('span');
    const name = document.createElement('strong');
    name.textContent = attachment.original_name;
    name.title = attachment.original_name;
    const size = document.createElement('small');
    size.textContent = formatBytes(attachment.size_bytes);
    copy.append(name, size);

    const download = document.createElement('a');
    download.className = 'ticket-gallery__download';
    download.href = attachment.download_url;
    download.setAttribute('aria-label', `Descargar ${attachment.original_name}`);
    download.innerHTML = '<svg aria-hidden="true"><use href="#icon-download"></use></svg>';
    meta.append(copy, download);
    item.append(meta);
    gallery.append(item);
  });
}

function renderCompletion(evidence, capabilities, ticket) {
  const panel = document.querySelector('[data-completion-panel]');
  const gallery = document.querySelector('[data-completion-gallery]');
  const count = document.querySelector('[data-completion-count]');
  const summary = document.querySelector('[data-completion-summary]');
  if (!panel || !gallery || !summary) return;

  const relevant = ticket.status.code === 'in_progress'
    || ticket.status.code === 'completed'
    || ticket.status.code === 'closed'
    || Boolean(ticket.completed_at)
    || evidence.length > 0;
  panel.hidden = !relevant;
  if (!relevant) return;

  count.textContent = `${evidence.length} ${evidence.length === 1 ? 'evidencia' : 'evidencias'}`;
  gallery.replaceChildren();
  evidence.forEach((attachment) => gallery.append(createGalleryItem(attachment)));
  gallery.hidden = evidence.length === 0;

  const isCompleted = Boolean(ticket.completed_at) || ['completed', 'closed'].includes(ticket.status.code);
  summary.hidden = !isCompleted;
  summary.replaceChildren();
  if (isCompleted) {
    const heading = document.createElement('strong');
    heading.textContent = 'Trabajo registrado como terminado';
    const date = document.createElement('span');
    date.textContent = `Fecha de terminación: ${formatDateTime(ticket.completed_at)}`;
    const copy = document.createElement('p');
    copy.textContent = ticket.resolution_summary || 'Sin resumen registrado.';
    summary.append(heading, date, copy);
  }

  if (completionForm) {
    completionForm.hidden = !Boolean(capabilities.can_complete_work);
    if (capabilities.can_complete_work && !completionForm.dataset.initialized) {
      completionForm.dataset.initialized = 'true';
      completionForm.addEventListener('submit', submitCompletion);
    }
  }
}

function createGalleryItem(attachment) {
  const item = document.createElement('article');
  item.className = 'ticket-gallery__item';
  if (String(attachment.mime_type).startsWith('image/')) {
    const preview = document.createElement('button');
    preview.type = 'button';
    preview.className = 'ticket-gallery__button';
    preview.setAttribute('aria-label', `Ampliar ${attachment.original_name}`);
    const image = document.createElement('img');
    image.src = attachment.url;
    image.alt = attachment.original_name;
    image.loading = 'lazy';
    preview.append(image);
    preview.addEventListener('click', () => openAttachment(attachment));
    item.append(preview);
  }
  const meta = document.createElement('div');
  meta.className = 'ticket-gallery__meta';
  const copy = document.createElement('span');
  const name = document.createElement('strong');
  name.textContent = attachment.original_name;
  name.title = attachment.original_name;
  const size = document.createElement('small');
  size.textContent = formatBytes(attachment.size_bytes);
  copy.append(name, size);
  const download = document.createElement('a');
  download.className = 'ticket-gallery__download';
  download.href = attachment.download_url;
  download.setAttribute('aria-label', `Descargar ${attachment.original_name}`);
  download.innerHTML = '<svg aria-hidden="true"><use href="#icon-download"></use></svg>';
  meta.append(copy, download);
  item.append(meta);
  return item;
}

function renderQuotations(quotations, capabilities, ticket) {
  const panel = document.querySelector('[data-quotation-panel]');
  const count = document.querySelector('[data-quotations-count]');
  const currentContainer = document.querySelector('[data-current-quotation]');
  const history = document.querySelector('[data-quotation-history]');
  const uploadContainer = document.querySelector('[data-quotation-upload-container]');
  const summary = document.querySelector('[data-quotation-form-summary]');
  const submitLabel = document.querySelector('[data-quotation-submit-label]');
  if (!panel || !currentContainer || !history || !uploadContainer) return;

  const canView = Boolean(capabilities.can_view_quotations);
  panel.hidden = !canView;
  if (!canView) return;

  if (count) count.textContent = `${quotations.length} ${quotations.length === 1 ? 'versión' : 'versiones'}`;
  const current = quotations.find((quotation) => quotation.is_current) || null;

  currentContainer.replaceChildren();
  if (current) {
    currentContainer.append(createCurrentQuotation(current));
  } else {
    currentContainer.append(createEmptyMessage('Todavía no hay una cotización cargada para este ticket.'));
  }

  const canUpload = Boolean(capabilities.can_upload_quotation)
    && ['quotation_pending', 'changes_requested'].includes(ticket.status.code)
    && ticket.processing_route === 'authorization_required';
  uploadContainer.hidden = !canUpload;
  if (canUpload) {
    const isCorrection = ticket.status.code === 'changes_requested';
    if (summary) {
      summary.textContent = isCorrection
        ? 'Cargar nueva versión para atender correcciones'
        : (current ? 'Cargar nueva versión' : 'Cargar cotización');
    }
    if (submitLabel) {
      submitLabel.textContent = isCorrection
        ? 'Guardar cotización corregida'
        : (current ? 'Guardar nueva versión' : 'Guardar cotización');
    }
    if (quotationForm && !quotationForm.dataset.initialized) {
      quotationForm.dataset.initialized = 'true';
      quotationForm.addEventListener('submit', submitQuotation);
    }
  }

  history.replaceChildren();
  const previous = quotations.filter((quotation) => !quotation.is_current);
  if (previous.length > 0) {
    const heading = document.createElement('div');
    heading.className = 'quotation-history__heading';
    const title = document.createElement('h3');
    title.textContent = 'Versiones anteriores';
    const copy = document.createElement('p');
    copy.textContent = 'Las cotizaciones reemplazadas permanecen disponibles como parte del expediente.';
    heading.append(title, copy);
    history.append(heading);
    previous.forEach((quotation) => history.append(createQuotationHistoryItem(quotation)));
  }
}

function createCurrentQuotation(quotation) {
  const card = document.createElement('article');
  card.className = 'quotation-card quotation-card--current';

  const header = document.createElement('header');
  header.className = 'quotation-card__header';
  const identity = document.createElement('div');
  const eyebrow = document.createElement('span');
  eyebrow.className = 'quotation-card__eyebrow';
  eyebrow.textContent = `Cotización vigente · Versión ${quotation.version_number}`;
  const supplier = document.createElement('h3');
  supplier.textContent = quotation.supplier_name;
  identity.append(eyebrow, supplier);

  const amount = document.createElement('strong');
  amount.className = 'quotation-card__amount';
  amount.textContent = formatMoney(quotation.amount, quotation.currency);
  header.append(identity, amount);

  const facts = document.createElement('dl');
  facts.className = 'quotation-card__facts';
  facts.append(
    createQuotationFact('Referencia', quotation.reference_number || 'Sin referencia'),
    createQuotationFact('Vigencia', quotation.valid_until ? formatDateOnly(quotation.valid_until) : 'No indicada'),
    createQuotationFact('Cargada por', quotation.uploaded_by?.full_name || 'Usuario'),
    createQuotationFact('Fecha de carga', formatDateTime(quotation.created_at)),
  );

  const description = document.createElement('p');
  description.className = 'quotation-card__description';
  description.textContent = quotation.description || 'Sin descripción adicional.';

  const file = createQuotationFileActions(quotation);
  card.append(header, facts, description, file);
  return card;
}

function createQuotationHistoryItem(quotation) {
  const item = document.createElement('article');
  item.className = 'quotation-history__item';

  const top = document.createElement('div');
  top.className = 'quotation-history__top';
  const title = document.createElement('div');
  const version = document.createElement('strong');
  version.textContent = `Versión ${quotation.version_number} · ${quotation.supplier_name}`;
  const meta = document.createElement('span');
  meta.textContent = `${quotationStatusLabel(quotation.status)} · ${formatDateTime(quotation.created_at)}`;
  title.append(version, meta);

  const amount = document.createElement('strong');
  amount.className = 'quotation-history__amount';
  amount.textContent = formatMoney(quotation.amount, quotation.currency);
  top.append(title, amount);

  const file = createQuotationFileActions(quotation, true);
  item.append(top, file);
  return item;
}

function createQuotationFact(label, value) {
  const wrapper = document.createElement('div');
  const term = document.createElement('dt');
  term.textContent = label;
  const description = document.createElement('dd');
  description.textContent = value;
  wrapper.append(term, description);
  return wrapper;
}

function createQuotationFileActions(quotation, compact = false) {
  const wrapper = document.createElement('div');
  wrapper.className = compact ? 'quotation-file quotation-file--compact' : 'quotation-file';

  const copy = document.createElement('div');
  copy.className = 'quotation-file__copy';
  const name = document.createElement('strong');
  name.textContent = quotation.file?.original_name || 'cotizacion.pdf';
  const size = document.createElement('small');
  size.textContent = formatBytes(quotation.file?.size_bytes || 0);
  copy.append(name, size);

  const actions = document.createElement('div');
  actions.className = 'quotation-file__actions';
  const view = document.createElement('a');
  view.className = 'button button--secondary';
  view.href = quotation.file?.url || '#';
  view.target = '_blank';
  view.rel = 'noopener';
  view.textContent = 'Ver PDF';

  const download = document.createElement('a');
  download.className = 'button button--secondary';
  download.href = quotation.file?.download_url || '#';
  download.textContent = 'Descargar';
  actions.append(view, download);
  wrapper.append(copy, actions);
  return wrapper;
}

function renderApprovals(requests, directors, capabilities, ticket) {
  const panel = document.querySelector('[data-approval-panel]');
  const count = document.querySelector('[data-approvals-count]');
  const currentContainer = document.querySelector('[data-current-approval]');
  const requestContainer = document.querySelector('[data-approval-request-container]');
  const history = document.querySelector('[data-approval-history]');
  if (!panel || !currentContainer || !requestContainer || !history) return;

  const canView = Boolean(capabilities.can_view_approvals);
  panel.hidden = !canView;
  if (!canView) return;

  if (count) count.textContent = `${requests.length} ${requests.length === 1 ? 'solicitud' : 'solicitudes'}`;
  const current = requests[0] || null;
  currentContainer.replaceChildren();

  if (current) {
    currentContainer.append(createApprovalCard(current, capabilities, ticket));
  } else {
    currentContainer.append(createEmptyMessage('Todavía no se ha enviado ninguna solicitud de autorización.'));
  }

  const currentQuotation = (detail?.quotations || []).find((quotation) => quotation.is_current) || null;
  const canRequest = Boolean(capabilities.can_request_authorization)
    && ticket.status.code === 'quotation_pending'
    && ticket.processing_route === 'authorization_required'
    && Boolean(currentQuotation);

  requestContainer.hidden = !canRequest;
  if (canRequest && approvalForm) {
    configureApprovalForm(currentQuotation, directors);
    if (!approvalForm.dataset.initialized) {
      approvalForm.dataset.initialized = 'true';
      approvalForm.addEventListener('submit', submitAuthorizationRequest);
    }
  }

  history.replaceChildren();
  const previous = requests.slice(1);
  if (previous.length > 0) {
    const heading = document.createElement('div');
    heading.className = 'approval-history__heading';
    const title = document.createElement('h3');
    title.textContent = 'Solicitudes anteriores';
    const copy = document.createElement('p');
    copy.textContent = 'Las decisiones y reenvíos anteriores permanecen como parte del expediente.';
    heading.append(title, copy);
    history.append(heading);
    previous.forEach((request) => history.append(createApprovalHistoryItem(request)));
  }
}

function configureApprovalForm(quotation, directors) {
  const select = document.querySelector('[data-approval-director-select]');
  const amount = document.querySelector('[data-approval-amount]');
  const help = document.querySelector('[data-approval-director-help]');
  const reference = document.querySelector('[data-approval-quotation-reference]');
  const submit = document.querySelector('[data-approval-submit]');
  if (!select || !amount || !reference || !submit) return;

  const previousSelection = select.value;
  select.replaceChildren();
  const placeholder = document.createElement('option');
  placeholder.value = '';
  placeholder.textContent = directors.length > 0 ? 'Selecciona una persona' : 'No hay responsables disponibles';
  select.append(placeholder);

  directors.forEach((director) => {
    const option = document.createElement('option');
    option.value = String(director.id);
    option.textContent = `${director.full_name} · ${director.email}`;
    select.append(option);
  });
  if (directors.some((director) => String(director.id) === previousSelection)) {
    select.value = previousSelection;
  }

  amount.value = Number(quotation.amount).toFixed(2);
  amount.max = Number(quotation.amount).toFixed(2);

  reference.replaceChildren();
  const label = document.createElement('span');
  label.textContent = `Cotización vigente · Versión ${quotation.version_number}`;
  const supplier = document.createElement('strong');
  supplier.textContent = quotation.supplier_name;
  const total = document.createElement('strong');
  total.textContent = formatMoney(quotation.amount, quotation.currency);
  reference.append(label, supplier, total);

  const hasDirectors = directors.length > 0;
  select.disabled = !hasDirectors;
  submit.disabled = !hasDirectors;
  if (help) {
    help.textContent = hasDirectors
      ? 'Solo usuarios activos con rol Dirección.'
      : 'No hay usuarios activos con rol Dirección. Asigna ese rol antes de enviar la solicitud.';
  }
}

function createApprovalCard(request, capabilities, ticket) {
  const card = document.createElement('article');
  card.className = `approval-card approval-card--${request.status.code}`;

  const header = document.createElement('header');
  header.className = 'approval-card__header';
  const identity = document.createElement('div');
  const eyebrow = document.createElement('span');
  eyebrow.className = 'approval-card__eyebrow';
  eyebrow.textContent = `Solicitud ${request.display_number || request.id} · ${request.status.name}`;
  const title = document.createElement('h3');
  title.textContent = `Cotización versión ${request.quotation.version_number} · ${request.quotation.supplier_name}`;
  identity.append(eyebrow, title);
  const amount = document.createElement('strong');
  amount.className = 'approval-card__amount';
  amount.textContent = formatMoney(request.requested_amount, request.quotation.currency);
  header.append(identity, amount);

  const facts = document.createElement('dl');
  facts.className = 'approval-card__facts';
  facts.append(
    createApprovalFact('Dirección', request.approver?.full_name || 'Sin asignar'),
    createApprovalFact('Solicitada por', request.requested_by?.full_name || 'Usuario'),
    createApprovalFact('Fecha de solicitud', formatDateTime(request.requested_at)),
    createApprovalFact('Cotización', `Versión ${request.quotation.version_number}`),
  );
  if (request.approved_amount !== null && request.approved_amount !== undefined) {
    facts.append(createApprovalFact('Importe autorizado', formatMoney(request.approved_amount, request.quotation.currency)));
  }

  const comment = document.createElement('div');
  comment.className = 'approval-card__comment';
  const commentTitle = document.createElement('strong');
  commentTitle.textContent = 'Justificación';
  const commentBody = document.createElement('p');
  commentBody.textContent = request.request_comment;
  comment.append(commentTitle, commentBody);

  card.append(header, facts, comment);

  if (request.status.code !== 'pending' && request.decision_comment) {
    const decision = document.createElement('div');
    decision.className = 'approval-card__comment approval-card__comment--decision';
    const decisionTitle = document.createElement('strong');
    decisionTitle.textContent = 'Decisión de Dirección';
    const decisionBody = document.createElement('p');
    decisionBody.textContent = request.decision_comment;
    decision.append(decisionTitle, decisionBody);
    card.append(decision);
  }

  const canDecide = request.status.code === 'pending'
    && ticket.status.code === 'authorization_pending'
    && (Boolean(capabilities.can_authorize)
      || Boolean(capabilities.can_request_changes)
      || Boolean(capabilities.can_reject));

  if (canDecide) {
    card.append(createApprovalDecisionActions(request, capabilities));
  }

  return card;
}

function createApprovalDecisionActions(request, capabilities) {
  const wrapper = document.createElement('div');
  wrapper.className = 'approval-decision';

  const intro = document.createElement('div');
  intro.className = 'approval-decision__intro';
  const title = document.createElement('strong');
  title.textContent = 'Resolver solicitud';
  const copy = document.createElement('span');
  copy.textContent = 'La decisión quedará registrada en el expediente y cambiará el responsable de la siguiente acción.';
  intro.append(title, copy);

  const actions = document.createElement('div');
  actions.className = 'approval-decision__actions';

  if (capabilities.can_authorize) {
    const approve = document.createElement('button');
    approve.type = 'button';
    approve.className = 'button button--primary';
    approve.textContent = 'Autorizar';
    approve.addEventListener('click', () => decideAuthorization(request, 'approved', approve));
    actions.append(approve);
  }

  if (capabilities.can_request_changes) {
    const changes = document.createElement('button');
    changes.type = 'button';
    changes.className = 'button button--secondary';
    changes.textContent = 'Solicitar cambios';
    changes.addEventListener('click', () => decideAuthorization(request, 'changes_requested', changes));
    actions.append(changes);
  }

  if (capabilities.can_reject) {
    const reject = document.createElement('button');
    reject.type = 'button';
    reject.className = 'button button--secondary approval-decision__reject';
    reject.textContent = 'Rechazar';
    reject.addEventListener('click', () => decideAuthorization(request, 'rejected', reject));
    actions.append(reject);
  }

  wrapper.append(intro, actions);
  return wrapper;
}

async function decideAuthorization(request, decision, button) {
  if (!window.Swal || !detail) return;

  const labels = {
    approved: {
      title: 'Autorizar solicitud',
      confirm: 'Autorizar',
      success: 'Solicitud autorizada',
      message: 'El supervisor recuperó la responsabilidad para iniciar la ejecución.',
    },
    changes_requested: {
      title: 'Solicitar cambios',
      confirm: 'Solicitar cambios',
      success: 'Cambios solicitados',
      message: 'El supervisor recuperó la responsabilidad para preparar una nueva propuesta.',
    },
    rejected: {
      title: 'Rechazar solicitud',
      confirm: 'Rechazar',
      success: 'Solicitud rechazada',
      message: 'El ticket quedó rechazado y sin una acción operativa pendiente.',
    },
  };
  const config = labels[decision];
  if (!config) return;

  let approvedAmount = null;
  let decisionComment = '';

  if (decision === 'approved') {
    const requestedAmount = Number(request.requested_amount || 0);
    const result = await window.Swal.fire({
      icon: 'question',
      title: config.title,
      html: `
        <div class="director-decision-modal">
          <label for="director-approved-amount">Importe autorizado</label>
          <input id="director-approved-amount" class="swal2-input" type="number" min="0.01" max="${requestedAmount.toFixed(2)}" step="0.01" value="${requestedAmount.toFixed(2)}">
          <label for="director-decision-comment">Comentario de Dirección</label>
          <textarea id="director-decision-comment" class="swal2-textarea" maxlength="5000" placeholder="Explica la decisión y cualquier condición relevante."></textarea>
        </div>`,
      showCancelButton: true,
      confirmButtonText: config.confirm,
      cancelButtonText: 'Cancelar',
      reverseButtons: true,
      customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
      preConfirm: () => {
        const amount = Number(document.getElementById('director-approved-amount')?.value || 0);
        const comment = String(document.getElementById('director-decision-comment')?.value || '').trim();
        if (!Number.isFinite(amount) || amount <= 0 || amount > requestedAmount) {
          window.Swal.showValidationMessage('El importe autorizado debe ser mayor a cero y no puede exceder el solicitado.');
          return false;
        }
        if (comment.length < 5) {
          window.Swal.showValidationMessage('Agrega un comentario de al menos 5 caracteres.');
          return false;
        }
        return { amount, comment };
      },
    });
    if (!result.isConfirmed || !result.value) return;
    approvedAmount = Number(result.value.amount).toFixed(2);
    decisionComment = result.value.comment;
  } else {
    const result = await window.Swal.fire({
      icon: decision === 'rejected' ? 'warning' : 'question',
      title: config.title,
      input: 'textarea',
      inputLabel: 'Comentario de Dirección',
      inputPlaceholder: decision === 'changes_requested'
        ? 'Indica claramente qué debe corregirse antes de volver a solicitar autorización…'
        : 'Explica el motivo del rechazo…',
      inputAttributes: { maxlength: '5000' },
      inputValidator: (value) => String(value || '').trim().length < 5
        ? 'Agrega un comentario de al menos 5 caracteres.'
        : undefined,
      showCancelButton: true,
      confirmButtonText: config.confirm,
      cancelButtonText: 'Cancelar',
      reverseButtons: true,
      customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
    });
    if (!result.isConfirmed) return;
    decisionComment = String(result.value || '').trim();
  }

  button.disabled = true;
  button.classList.add('is-loading');

  try {
    const response = await apiRequest('./api/tickets/authorization-decision.php', {
      method: 'POST',
      body: JSON.stringify({
        ticket_id: detail.ticket.id,
        approval_request_id: request.id,
        decision,
        approved_amount: approvedAmount,
        decision_comment: decisionComment,
        row_version: detail.ticket.row_version,
      }),
    });
    detail = response.data;
    renderTicket(detail);
    await window.Swal.fire({
      icon: 'success',
      title: config.success,
      text: config.message,
      confirmButtonText: 'Continuar',
      customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
    });
  } catch (error) {
    handleAuthError(error);
    await showErrorAlert(error.message || 'No fue posible registrar la decisión.');
    if ([409, 422].includes(Number(error.status))) await loadTicket();
  } finally {
    button.disabled = false;
    button.classList.remove('is-loading');
  }
}

function createApprovalHistoryItem(request) {
  const item = document.createElement('article');
  item.className = 'approval-history__item';
  const top = document.createElement('div');
  top.className = 'approval-history__top';
  const copy = document.createElement('div');
  const title = document.createElement('strong');
  title.textContent = `Solicitud ${request.display_number || request.id} · ${request.status.name}`;
  const meta = document.createElement('span');
  meta.textContent = `Cotización V${request.quotation.version_number} · ${formatDateTime(request.requested_at)}`;
  copy.append(title, meta);
  const amount = document.createElement('strong');
  amount.textContent = formatMoney(request.requested_amount, request.quotation.currency);
  top.append(copy, amount);
  item.append(top);

  const comment = document.createElement('p');
  comment.textContent = request.decision_comment || request.request_comment;
  item.append(comment);
  return item;
}

function createApprovalFact(label, value) {
  const wrapper = document.createElement('div');
  const term = document.createElement('dt');
  term.textContent = label;
  const description = document.createElement('dd');
  description.textContent = value;
  wrapper.append(term, description);
  return wrapper;
}

async function submitAuthorizationRequest(event) {
  event.preventDefault();
  if (!detail || !approvalForm) return;

  const currentQuotation = (detail.quotations || []).find((quotation) => quotation.is_current) || null;
  if (!currentQuotation) return;

  const directorSelect = approvalForm.querySelector('[name="approver_user_id"]');
  const selectedDirector = (detail.eligible_directors || []).find(
    (director) => String(director.id) === String(directorSelect?.value || ''),
  );
  if (!selectedDirector) {
    setMessage(document.querySelector('[data-approval-message]'), 'Selecciona al responsable de Dirección.', 'error');
    return;
  }

  if (window.Swal) {
    const confirmation = await window.Swal.fire({
      icon: 'question',
      title: 'Enviar a Dirección',
      html: `La cotización <strong>versión ${currentQuotation.version_number}</strong> será enviada a <strong>${escapeHtml(selectedDirector.full_name)}</strong>. El ticket cambiará a <strong>Pendiente de autorización</strong>.`,
      showCancelButton: true,
      confirmButtonText: 'Enviar solicitud',
      cancelButtonText: 'Cancelar',
      reverseButtons: true,
      customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
    });
    if (!confirmation.isConfirmed) return;
  }

  const button = approvalForm.querySelector('button[type="submit"]');
  const message = document.querySelector('[data-approval-message]');
  const formData = new FormData(approvalForm);
  button.disabled = true;
  button.classList.add('is-loading');
  setMessage(message, 'Enviando solicitud a Dirección…');

  try {
    const response = await apiRequest('./api/tickets/request-authorization.php', {
      method: 'POST',
      body: JSON.stringify({
        ticket_id: detail.ticket.id,
        quotation_id: currentQuotation.id,
        approver_user_id: Number(formData.get('approver_user_id')),
        requested_amount: String(formData.get('requested_amount') || ''),
        request_comment: String(formData.get('request_comment') || '').trim(),
        row_version: detail.ticket.row_version,
      }),
    });
    detail = response.data;
    approvalForm.reset();
    renderTicket(detail);
    await window.Swal?.fire({
      icon: 'success',
      title: 'Solicitud enviada',
      text: 'El ticket quedó pendiente de autorización y Dirección es ahora responsable de la siguiente acción.',
      confirmButtonText: 'Continuar',
      customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
    });
  } catch (error) {
    handleAuthError(error);
    setMessage(message, error.message || 'No fue posible enviar la solicitud.', 'error');
    if ([409, 422].includes(Number(error.status)) && Number(error.status) === 409) {
      await loadTicket();
    }
  } finally {
    button.disabled = false;
    button.classList.remove('is-loading');
  }
}

function escapeHtml(value) {
  return String(value || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

async function submitQuotation(event) {
  event.preventDefault();
  if (!detail || !quotationForm) return;

  const current = (detail.quotations || []).find((quotation) => quotation.is_current) || null;
  if (current && window.Swal) {
    const confirmation = await window.Swal.fire({
      icon: 'warning',
      title: 'Crear nueva versión',
      text: `La versión ${current.version_number} quedará marcada como reemplazada y la nueva cotización será la vigente.`,
      showCancelButton: true,
      confirmButtonText: 'Continuar',
      cancelButtonText: 'Cancelar',
      reverseButtons: true,
      customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
    });
    if (!confirmation.isConfirmed) return;
  }

  const button = quotationForm.querySelector('button[type="submit"]');
  const message = document.querySelector('[data-quotation-message]');
  const formData = new FormData(quotationForm);
  formData.set('ticket_id', String(detail.ticket.id));
  formData.set('row_version', String(detail.ticket.row_version));

  button.disabled = true;
  button.classList.add('is-loading');
  setMessage(message, current ? 'Guardando nueva versión…' : 'Guardando cotización…');

  try {
    const response = await apiFormRequest('./api/tickets/quotations/create.php', {
      method: 'POST',
      body: formData,
    });
    detail = response.data;
    quotationForm.reset();
    renderTicket(detail);
    const uploadContainer = document.querySelector('[data-quotation-upload-container]');
    if (uploadContainer) uploadContainer.open = false;
    await window.Swal?.fire({
      icon: 'success',
      title: current ? 'Nueva versión registrada' : 'Cotización registrada',
      text: `La versión ${response.meta?.version_number || ''} quedó como cotización vigente.`.trim(),
      confirmButtonText: 'Continuar',
      customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
    });
  } catch (error) {
    handleAuthError(error);
    setMessage(message, error.message || 'No fue posible guardar la cotización.', 'error');
    if ([409, 422].includes(Number(error.status))) {
      if (Number(error.status) === 409) await loadTicket();
    }
  } finally {
    button.disabled = false;
    button.classList.remove('is-loading');
  }
}

async function submitCompletion(event) {
  event.preventDefault();
  if (!detail || !completionForm) return;

  const formData = new FormData(completionForm);
  const summary = String(formData.get('resolution_summary') || '').trim();
  const files = Array.from(completionForm.querySelector('[name="completion_evidence[]"]')?.files || []);
  if (summary.length < 10) {
    setMessage(document.querySelector('[data-completion-message]'), 'Describe con mayor detalle la solución realizada.', 'error');
    return;
  }
  if (files.length < 1) {
    setMessage(document.querySelector('[data-completion-message]'), 'Adjunta al menos una fotografía final.', 'error');
    return;
  }

  if (window.Swal) {
    const confirmation = await window.Swal.fire({
      icon: 'question',
      title: 'Finalizar trabajo',
      text: 'Se registrará la terminación y el sistema cerrará automáticamente el ticket. Las evidencias finales quedarán incorporadas permanentemente al expediente.',
      showCancelButton: true,
      confirmButtonText: 'Confirmar terminación',
      cancelButtonText: 'Cancelar',
      reverseButtons: true,
      customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
    });
    if (!confirmation.isConfirmed) return;
  }

  formData.set('ticket_id', String(detail.ticket.id));
  formData.set('row_version', String(detail.ticket.row_version));
  const button = completionForm.querySelector('button[type="submit"]');
  const message = document.querySelector('[data-completion-message]');
  button.disabled = true;
  button.classList.add('is-loading');
  setMessage(message, 'Guardando terminación y evidencias…');

  try {
    const response = await apiFormRequest('./api/tickets/complete-work.php', {
      method: 'POST',
      body: formData,
    });
    detail = response.data;
    completionForm.reset();
    renderTicket(detail);
    await window.Swal?.fire({
      icon: 'success',
      title: 'Trabajo terminado',
      text: 'La terminación quedó registrada y el ticket fue cerrado automáticamente.',
      confirmButtonText: 'Continuar',
      customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
    });
  } catch (error) {
    handleAuthError(error);
    setMessage(message, error.message || 'No fue posible registrar la terminación.', 'error');
    if (Number(error.status) === 409) await loadTicket();
  } finally {
    button.disabled = false;
    button.classList.remove('is-loading');
  }
}


function formatMoney(amount, currency = 'MXN') {
  const value = Number(amount || 0);
  try {
    return new Intl.NumberFormat('es-MX', {
      style: 'currency',
      currency,
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(value);
  } catch {
    return `${value.toFixed(2)} ${currency}`;
  }
}

function formatDateOnly(value) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
  if (!match) return String(value || '—');
  return `${match[3]}/${match[2]}/${match[1]}`;
}

function quotationStatusLabel(status) {
  return ({
    current: 'Vigente',
    replaced: 'Reemplazada',
    withdrawn: 'Retirada',
    approved: 'Autorizada',
  })[status] || status || '—';
}

async function openAttachment(attachment) {
  if (!window.Swal) {
    window.open(attachment.url, '_blank', 'noopener');
    return;
  }
  await window.Swal.fire({
    title: attachment.original_name,
    imageUrl: attachment.url,
    imageAlt: attachment.original_name,
    showCloseButton: true,
    showConfirmButton: false,
    width: 'min(94vw, 1100px)',
    customClass: { popup: 'app-alert', image: 'ticket-lightbox-image' },
  });
}

function renderComments(comments, canComment) {
  const list = document.querySelector('[data-comment-list]');
  if (!list) return;
  list.replaceChildren();
  commentForm.hidden = !canComment;

  if (canComment && !commentForm.dataset.initialized) {
    commentForm.dataset.initialized = 'true';
    commentForm.addEventListener('submit', submitComment);
  }

  if (comments.length === 0) {
    list.append(createEmptyMessage('Todavía no hay comentarios en este ticket.'));
    return;
  }

  comments.forEach((comment) => list.append(createComment(comment)));
}

function createComment(comment) {
  const item = document.createElement('article');
  item.className = 'comment-item';
  const avatar = document.createElement('span');
  avatar.className = 'comment-item__avatar';
  avatar.textContent = initials(comment.author?.full_name);

  const body = document.createElement('div');
  body.className = 'comment-item__body';
  const header = document.createElement('header');
  header.className = 'comment-item__header';
  const identity = document.createElement('span');
  const name = document.createElement('strong');
  name.textContent = comment.author?.full_name || 'Usuario';
  identity.append(name);
  if (comment.author?.roles) {
    const role = document.createElement('small');
    role.className = 'comment-item__role';
    role.textContent = comment.author.roles;
    identity.append(role);
  }
  const time = document.createElement('time');
  time.textContent = formatRelativeTime(comment.created_at);
  time.title = formatDateTime(comment.created_at);
  header.append(identity, time);
  const text = document.createElement('p');
  text.textContent = comment.body;
  body.append(header, text);
  item.append(avatar, body);
  return item;
}

async function submitComment(event) {
  event.preventDefault();
  if (!detail) return;
  const textarea = commentForm.elements.body;
  const button = commentForm.querySelector('button[type="submit"]');
  const message = document.querySelector('[data-comment-message]');
  const body = String(textarea.value || '').trim();
  if (body.length < 2) {
    setMessage(message, 'Escribe un comentario válido.', 'error');
    textarea.focus();
    return;
  }

  button.disabled = true;
  button.classList.add('is-loading');
  setMessage(message, 'Publicando comentario…');
  try {
    const response = await apiRequest('./api/tickets/comment.php', {
      method: 'POST',
      body: JSON.stringify({ ticket_id: detail.ticket.id, body }),
    });
    textarea.value = '';
    detail.comments.push(response.data.comment);
    renderComments(detail.comments, true);
    setMessage(message, 'Comentario publicado.', 'success');
  } catch (error) {
    handleAuthError(error);
    setMessage(message, error.message || 'No fue posible publicar el comentario.', 'error');
  } finally {
    button.disabled = false;
    button.classList.remove('is-loading');
  }
}

function renderTimeline(statusHistory, assignmentHistory) {
  const timeline = document.querySelector('[data-ticket-timeline]');
  if (!timeline) return;
  const items = [
    ...statusHistory.map((item) => ({ ...item, kind: 'status' })),
    ...assignmentHistory.map((item) => ({ ...item, kind: 'assignment' })),
  ].sort((a, b) => dateValue(b.created_at) - dateValue(a.created_at) || b.id - a.id);

  timeline.replaceChildren();
  if (items.length === 0) {
    timeline.append(createEmptyMessage('No hay movimientos registrados.'));
    return;
  }

  let visibleCount = Math.min(5, items.length);
  const list = document.createElement('div');
  list.className = 'ticket-timeline__items';
  const controls = document.createElement('div');
  controls.className = 'ticket-timeline__controls';
  const summary = document.createElement('span');
  summary.className = 'ticket-timeline__summary';
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'button button--secondary ticket-timeline__load-more';
  button.textContent = 'Cargar 5 más';

  const renderBatch = () => {
    list.replaceChildren(...items.slice(0, visibleCount).map(createTimelineItem));
    summary.textContent = `Mostrando ${visibleCount} de ${items.length} eventos`;
    button.hidden = visibleCount >= items.length;
  };

  button.addEventListener('click', () => {
    visibleCount = Math.min(visibleCount + 5, items.length);
    renderBatch();
  });

  controls.append(summary, button);
  timeline.append(list, controls);
  renderBatch();
}

function createTimelineItem(item) {
  const article = document.createElement('article');
  article.className = 'timeline-item';
  article.dataset.kind = item.kind;
  const dot = document.createElement('span');
  dot.className = 'timeline-item__dot';
  dot.setAttribute('aria-hidden', 'true');
  const content = document.createElement('div');
  content.className = 'timeline-item__content';
  const description = document.createElement('p');

  if (item.kind === 'status') {
    const actor = ['system', 'migration'].includes(item.source)
      ? 'El sistema'
      : (item.actor?.full_name || 'El sistema');
    description.textContent = item.from_status
      ? `${actor} cambió el estado de ${item.from_status.name} a ${item.to_status.name}.`
      : `${actor} creó el reporte con estado ${item.to_status.name}.`;
  } else {
    const role = assignmentLabel(item.type);
    const assigned = item.new_user?.full_name || 'Sin asignar';
    const actor = item.assigned_by?.full_name || 'El sistema';
    description.textContent = `${actor} asignó ${role} a ${assigned}.`;
  }

  const time = document.createElement('time');
  time.textContent = `${formatDateTime(item.created_at)} · ${formatRelativeTime(item.created_at)}`;
  content.append(description, time);
  if (item.comment) {
    const comment = document.createElement('p');
    comment.className = 'timeline-item__comment';
    comment.textContent = item.comment;
    content.append(comment);
  }
  article.append(dot, content);
  return article;
}

function renderPeople(ticket, capabilities = {}) {
  const list = document.querySelector('[data-people-list]');
  if (!list) return;
  const people = [
    createPerson('Reportante', ticket.reporter),
    createPerson('Supervisor', ticket.supervisor),
  ];
  if (!capabilities.is_reporter_view) {
    people.push(createPerson('Dirección', ticket.director));
  }
  list.replaceChildren(...people);
}

function createPerson(label, person) {
  const card = document.createElement('article');
  card.className = 'person-card';
  const avatar = document.createElement('span');
  avatar.className = 'person-card__avatar';
  avatar.textContent = person ? initials(person.full_name) : '—';
  const copy = document.createElement('span');
  copy.className = 'person-card__copy';
  const role = document.createElement('span');
  role.textContent = label;
  const name = document.createElement('strong');
  name.textContent = person?.full_name || 'Sin asignar';
  const email = document.createElement('small');
  email.textContent = person?.email || 'Pendiente';
  copy.append(role, name, email);
  card.append(avatar, copy);
  return card;
}

function renderNextAction(ticket, capabilities) {
  const container = document.querySelector('[data-next-action]');
  if (!container) return;
  container.replaceChildren();

  const status = document.createElement('div');
  status.className = 'next-action__status';
  const title = document.createElement('strong');
  const copy = document.createElement('span');

  if (capabilities.is_reporter_view) {
    const publicActions = {
      new: ['Reporte recibido', 'El reporte fue registrado y será revisado por el equipo responsable.'],
      under_review: ['En gestión', 'El equipo responsable se encuentra gestionando la atención del reporte.'],
      in_progress: ['En proceso', 'El trabajo reportado se encuentra actualmente en ejecución.'],
      closed: ['Flujo finalizado', 'El trabajo fue registrado como terminado.'],
    };
    const publicAction = publicActions[ticket.status.code] || ['Seguimiento del reporte', 'Consulta el estado actual y las actualizaciones del reporte.'];
    title.textContent = publicAction[0];
    copy.textContent = publicAction[1];
  } else if (ticket.status.is_terminal) {
    title.textContent = 'Flujo finalizado';
    copy.textContent = `El ticket se encuentra en estado ${ticket.status.name}.`;
  } else if (ticket.status.code === 'under_review' && capabilities.can_select_route) {
    title.textContent = 'Definir ruta de atención';
    copy.textContent = 'Selecciona si el trabajo puede atenderse directamente o requiere cotización y autorización.';
  } else if (ticket.status.code === 'quotation_pending') {
    const currentQuotation = (detail?.quotations || []).find((quotation) => quotation.is_current);
    if (currentQuotation) {
      title.textContent = 'Cotización vigente cargada';
      copy.textContent = capabilities.can_request_authorization
        ? `La versión ${currentQuotation.version_number} está lista para enviarse a Dirección.`
        : `La versión ${currentQuotation.version_number} está registrada como cotización vigente.`;
    } else {
      title.textContent = 'Preparar cotización';
      copy.textContent = 'El supervisor debe obtener y cargar una cotización antes de solicitar autorización.';
    }
  } else if (ticket.status.code === 'authorization_pending') {
    const currentRequest = (detail?.approval_requests || [])[0];
    const canDecide = Boolean(capabilities.can_authorize)
      || Boolean(capabilities.can_request_changes)
      || Boolean(capabilities.can_reject);
    title.textContent = canDecide ? 'Revisar solicitud' : (ticket.director?.full_name || 'Dirección');
    copy.textContent = currentRequest
      ? (canDecide
        ? `La solicitud ${currentRequest.display_number || currentRequest.id} requiere tu decisión.`
        : `La solicitud ${currentRequest.display_number || currentRequest.id} está pendiente de revisión por Dirección.`)
      : 'El ticket está pendiente de autorización por Dirección.';
  } else if (ticket.status.code === 'authorized') {
    title.textContent = ticket.action_owner?.full_name || ticket.supervisor?.full_name || 'Supervisor';
    copy.textContent = 'La reparación fue autorizada. El supervisor debe iniciar la ejecución.';
  } else if (ticket.status.code === 'changes_requested') {
    title.textContent = ticket.action_owner?.full_name || ticket.supervisor?.full_name || 'Supervisor';
    copy.textContent = 'Dirección solicitó cambios. El supervisor debe preparar una nueva propuesta.';
  } else if (ticket.status.code === 'in_progress') {
    title.textContent = ticket.action_owner?.full_name || 'Supervisor responsable';
    copy.textContent = capabilities.can_complete_work
      ? 'El trabajo está en ejecución. Registra la solución y las evidencias finales cuando haya terminado.'
      : (ticket.processing_route === 'direct'
        ? 'La reparación se encuentra en ejecución mediante atención directa.'
        : 'La reparación autorizada se encuentra en ejecución.');
  } else if (ticket.status.code === 'completed') {
    title.textContent = ticket.action_owner?.full_name || ticket.supervisor?.full_name || 'Supervisor';
    copy.textContent = 'El trabajo fue terminado y documentado. El ticket queda pendiente de cierre.';
  } else if (ticket.action_owner) {
    title.textContent = ticket.action_owner.full_name;
    copy.textContent = `Responsable actual de continuar el flujo desde ${ticket.status.name}.`;
  } else {
    title.textContent = 'Sin responsable asignado';
    copy.textContent = 'Un administrador debe revisar la asignación del ticket.';
  }

  status.append(title, copy);
  container.append(status);

  if (ticket.status.code === 'under_review' && capabilities.can_select_route) {
    const actions = document.createElement('div');
    actions.className = 'next-action__actions';

    const direct = document.createElement('button');
    direct.type = 'button';
    direct.className = 'button button--primary next-action__button';
    direct.textContent = 'Atender directamente';
    direct.addEventListener('click', () => selectProcessingRoute('direct', direct));

    const authorization = document.createElement('button');
    authorization.type = 'button';
    authorization.className = 'button button--secondary next-action__button';
    authorization.textContent = 'Requiere cotización';
    authorization.addEventListener('click', () => selectProcessingRoute('authorization_required', authorization));

    actions.append(direct, authorization);
    container.append(actions);
  }

  if (ticket.status.code === 'quotation_pending' && capabilities.can_request_authorization) {
    const currentQuotation = (detail?.quotations || []).find((quotation) => quotation.is_current);
    if (currentQuotation) {
      const actions = document.createElement('div');
      actions.className = 'next-action__actions';
      const request = document.createElement('button');
      request.type = 'button';
      request.className = 'button button--primary next-action__button';
      request.textContent = 'Solicitar autorización';
      request.addEventListener('click', () => {
        const panel = document.querySelector('[data-approval-panel]');
        panel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        document.querySelector('[data-approval-director-select]')?.focus({ preventScroll: true });
      });
      actions.append(request);
      container.append(actions);
    }
  }

  if (ticket.status.code === 'changes_requested' && capabilities.can_upload_quotation) {
    const actions = document.createElement('div');
    actions.className = 'next-action__actions';
    const revise = document.createElement('button');
    revise.type = 'button';
    revise.className = 'button button--primary next-action__button';
    revise.textContent = 'Cargar nueva cotización';
    revise.addEventListener('click', () => {
      const upload = document.querySelector('[data-quotation-upload-container]');
      if (upload) upload.open = true;
      upload?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      document.querySelector('[name="supplier_name"]')?.focus({ preventScroll: true });
    });
    actions.append(revise);
    container.append(actions);
  }


  if (ticket.status.code === 'authorized' && capabilities.can_start_execution) {
    const actions = document.createElement('div');
    actions.className = 'next-action__actions';
    const start = document.createElement('button');
    start.type = 'button';
    start.className = 'button button--primary next-action__button';
    start.textContent = 'Iniciar ejecución';
    start.addEventListener('click', () => startAuthorizedExecution(start));
    actions.append(start);
    container.append(actions);
  }

  if (ticket.status.code === 'in_progress' && capabilities.can_complete_work) {
    const actions = document.createElement('div');
    actions.className = 'next-action__actions';
    const complete = document.createElement('button');
    complete.type = 'button';
    complete.className = 'button button--primary next-action__button';
    complete.textContent = 'Finalizar trabajo';
    complete.addEventListener('click', () => {
      document.querySelector('[data-completion-panel]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      document.querySelector('[data-completion-form] [name="resolution_summary"]')?.focus({ preventScroll: true });
    });
    actions.append(complete);
    container.append(actions);
  }

  if (ticket.status.code === 'authorization_pending'
    && (capabilities.can_authorize || capabilities.can_request_changes || capabilities.can_reject)) {
    const actions = document.createElement('div');
    actions.className = 'next-action__actions';
    const review = document.createElement('button');
    review.type = 'button';
    review.className = 'button button--primary next-action__button';
    review.textContent = 'Revisar autorización';
    review.addEventListener('click', () => {
      document.querySelector('[data-approval-panel]')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    actions.append(review);
    container.append(actions);
  }
}

async function startAuthorizedExecution(button) {
  if (!window.Swal || !detail) return;

  const result = await window.Swal.fire({
    icon: 'question',
    title: 'Iniciar ejecución',
    text: 'El trabajo autorizado pasará a En proceso y se registrará la fecha de inicio.',
    input: 'textarea',
    inputLabel: 'Nota de inicio (opcional)',
    inputPlaceholder: 'Agrega alguna indicación relevante para el inicio de los trabajos…',
    inputAttributes: { maxlength: '3000' },
    showCancelButton: true,
    confirmButtonText: 'Iniciar ejecución',
    cancelButtonText: 'Cancelar',
    reverseButtons: true,
    customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
  });

  if (!result.isConfirmed) return;
  button.disabled = true;
  button.classList.add('is-loading');

  try {
    const response = await apiRequest('./api/tickets/start-execution.php', {
      method: 'POST',
      body: JSON.stringify({
        ticket_id: detail.ticket.id,
        comment: String(result.value || '').trim(),
        row_version: detail.ticket.row_version,
      }),
    });
    detail = response.data;
    renderTicket(detail);
    await window.Swal.fire({
      icon: 'success',
      title: 'Ejecución iniciada',
      text: 'El ticket pasó a En proceso y quedó registrada la fecha de inicio.',
      confirmButtonText: 'Continuar',
      customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
    });
  } catch (error) {
    handleAuthError(error);
    await showErrorAlert(error.message || 'No fue posible iniciar la ejecución.');
    if ([409, 422].includes(Number(error.status))) await loadTicket();
  } finally {
    button.disabled = false;
    button.classList.remove('is-loading');
  }
}

async function selectProcessingRoute(processingRoute, button) {
  if (!window.Swal || !detail) return;
  const isDirect = processingRoute === 'direct';
  const result = await window.Swal.fire({
    icon: 'question',
    title: isDirect ? 'Atender directamente' : 'Solicitar cotización',
    text: isDirect
      ? 'El ticket pasará a En proceso sin requerir autorización de Dirección.'
      : 'El ticket pasará a Cotización pendiente. Todavía no se enviará a Dirección.',
    input: 'textarea',
    inputLabel: isDirect ? 'Plan de atención *' : 'Motivo o alcance',
    inputPlaceholder: isDirect
      ? 'Describe quién atenderá el trabajo y cómo se resolverá…'
      : 'Agrega una nota sobre el trabajo que debe cotizarse…',
    inputAttributes: { maxlength: '3000' },
    inputValidator: (value) => {
      if (isDirect && String(value || '').trim().length < 2) {
        return 'Describe brevemente cómo se atenderá el trabajo.';
      }
      return undefined;
    },
    showCancelButton: true,
    confirmButtonText: isDirect ? 'Iniciar atención' : 'Confirmar ruta',
    cancelButtonText: 'Cancelar',
    reverseButtons: true,
    customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
  });

  if (!result.isConfirmed) return;
  button.disabled = true;
  button.classList.add('is-loading');

  try {
    const response = await apiRequest('./api/tickets/select-route.php', {
      method: 'POST',
      body: JSON.stringify({
        ticket_id: detail.ticket.id,
        processing_route: processingRoute,
        comment: String(result.value || '').trim(),
        row_version: detail.ticket.row_version,
      }),
    });
    detail = response.data;
    renderTicket(detail);
    await window.Swal.fire({
      icon: 'success',
      title: 'Ruta seleccionada',
      text: isDirect
        ? 'El ticket pasó a En proceso mediante atención directa.'
        : 'El ticket quedó en Cotización pendiente.',
      confirmButtonText: 'Continuar',
      customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
    });
  } catch (error) {
    handleAuthError(error);
    await showErrorAlert(error.message || 'No fue posible seleccionar la ruta de atención.');
    if ([409, 422].includes(Number(error.status))) await loadTicket();
  } finally {
    button.disabled = false;
    button.classList.remove('is-loading');
  }
}

function routeLabel(route) {
  return ({
    direct: 'Atención directa',
    authorization_required: 'Cotización y autorización',
  })[route] || 'Pendiente de selección';
}

function createEmptyMessage(text) {
  const message = document.createElement('p');
  message.className = 'ticket-empty';
  message.textContent = text;
  return message;
}

function setLoading(isLoading) {
  if (loading) loading.hidden = !isLoading;
  if (content) content.hidden = isLoading;
}

function showFatalError(message) {
  setLoading(false);
  if (content) content.hidden = true;
  if (pageError) {
    pageError.hidden = false;
    pageError.textContent = message;
  }
}

function setText(selector, value) {
  const element = document.querySelector(selector);
  if (element) element.textContent = value || '—';
}

function setMessage(element, text, type = '') {
  if (!element) return;
  element.textContent = text;
  if (type) element.dataset.type = type;
  else delete element.dataset.type;
}

function initials(name) {
  return String(name || '')
    .trim()
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join('') || 'US';
}

function formatBytes(bytes) {
  const value = Number(bytes || 0);
  if (value < 1024) return `${value} B`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
  return `${(value / 1024 / 1024).toFixed(1)} MB`;
}

function dateValue(value) {
  const normalized = String(value || '').replace(' ', 'T');
  return new Date(normalized.endsWith('Z') ? normalized : `${normalized}Z`).getTime() || 0;
}

function assignmentLabel(type) {
  return ({ supervisor: 'el supervisor', director: 'el responsable de Dirección', action_owner: 'el responsable de la acción' })[type] || 'un responsable';
}

function handleAuthError(error) {
  if ([401, 428].includes(Number(error?.status))) {
    window.location.replace(error.status === 428 ? './change-password.html' : './login.html');
  }
}

async function showErrorAlert(message) {
  if (!window.Swal) return;
  await window.Swal.fire({
    icon: 'error',
    title: 'No fue posible completar la acción',
    text: message,
    confirmButtonText: 'Entendido',
    customClass: { popup: 'app-alert', confirmButton: 'app-alert__confirm' },
  });
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
