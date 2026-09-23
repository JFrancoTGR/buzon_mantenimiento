import { apiRequest, apiFormRequest } from '../core/api.js';
import {
  handleCoreBoundaryError,
  redirectToCorePasswordChange,
} from '../core/authBoundary.js';
import { applyPermissionVisibility, hasPermission } from '../core/permissions.js';
import { setupSidebar } from '../components/sidebar.js';
import { setupUserMenu } from '../components/userMenu.js';
import { setupNotificationsMenu } from '../components/notificationsMenu.js';

const form = document.querySelector('[data-ticket-form]');
const pageError = document.querySelector('[data-page-error]');
const formMessage = document.querySelector('[data-form-message]');
const locationSelect = document.querySelector('[data-location-select]');
const locationHelp = document.querySelector('[data-location-help]');
const locationContext = document.querySelector('[data-location-context]');
const priorityOptions = document.querySelector('[data-priority-options]');
const fileInput = document.querySelector('[data-file-input]');
const selectFilesButton = document.querySelector('[data-select-files]');
const uploadZone = document.querySelector('[data-upload-zone]');
const uploadMessage = document.querySelector('[data-upload-message]');
const uploadCounter = document.querySelector('[data-upload-counter]');
const uploadHelp = document.querySelector('[data-upload-help]');
const evidenceGrid = document.querySelector('[data-evidence-grid]');
const submitButton = document.querySelector('[data-submit-ticket]');
const submitLabel = document.querySelector('[data-submit-label]');

const locationCode = getLocationCode();
const state = {
  user: null,
  context: null,
  files: [],
  objectUrls: new Map(),
  submitting: false,
};

try {
  const sessionPayload = await apiRequest('./api/auth/me.php');
  state.user = sessionPayload.data.user;

  if (state.user.must_change_password) {
    redirectToCorePasswordChange();
    throw new Error('Redirección requerida.');
  }

  if (!hasPermission(state.user, 'ticket.create')) {
    window.location.replace('./dashboard.html');
    throw new Error('Permiso insuficiente.');
  }

  applyPermissionVisibility(state.user);
  setupSidebar();
  setupUserMenu(state.user);
  setupComingSoonActions();
  renderReporter(state.user);

  const query = locationCode ? `?location=${encodeURIComponent(locationCode)}` : '';
  const contextPayload = await apiRequest(`./api/tickets/context.php${query}`);
  state.context = contextPayload.data;
  renderContext(state.context);
  setupFormInteractions();
  submitButton.disabled = false;

  loadNotifications();
} catch (error) {
  if (
    !handleCoreBoundaryError(error)
    && !String(error?.message || '').includes('Redirección')
  ) {
    showPageError(
      error?.message
      || 'No fue posible preparar el formulario.'
    );
  }
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

function renderReporter(user) {
  const fullName = user.full_name || [user.first_name, user.last_name].filter(Boolean).join(' ');
  const initials = [user.first_name, user.last_name]
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => String(part).charAt(0).toUpperCase())
    .join('') || 'US';

  const name = document.querySelector('[data-reporter-name]');
  const email = document.querySelector('[data-reporter-email]');
  const avatar = document.querySelector('[data-reporter-initials]');
  if (name) name.textContent = fullName || 'Usuario';
  if (email) email.textContent = user.email || '';
  if (avatar) avatar.textContent = initials;
}

function renderContext(context) {
  renderLocations(context.locations || [], context.selected_location || null, context.requested_location || '');
  renderPriorities(context.priorities || [], context.default_priority || 'medium');

  const upload = context.upload || {};
  const maxFiles = Number(upload.max_files || 8);
  const maxSizeMb = Number(upload.max_size_mb || 8);
  uploadHelp.textContent = `Entre 1 y ${maxFiles} imágenes. Máximo ${maxSizeMb} MB por archivo.`;
  updateUploadCounter();
}

function renderLocations(locations, selectedLocation, requestedLocation) {
  locationSelect.replaceChildren();

  const placeholder = document.createElement('option');
  placeholder.value = '';
  placeholder.textContent = 'Selecciona una ubicación';
  locationSelect.append(placeholder);

  locations.forEach((location) => {
    const option = document.createElement('option');
    option.value = location.code;
    option.textContent = location.has_active_supervisor
      ? location.name
      : `${location.name} · Sin supervisor configurado`;
    option.disabled = !location.has_active_supervisor;
    locationSelect.append(option);
  });

  if (selectedLocation?.has_active_supervisor) {
    locationSelect.value = selectedLocation.code;
    locationSelect.disabled = true;
    locationContext.hidden = false;
    locationHelp.classList.remove('form-help--warning');
    locationHelp.textContent = `Ubicación conservada: ${selectedLocation.name}.`;
  } else {
    locationSelect.disabled = false;
    locationContext.hidden = true;
    if (selectedLocation && !selectedLocation.has_active_supervisor) {
      locationHelp.textContent = 'La ubicación identificada no tiene un Supervisor activo configurado. Selecciona otra sede o solicita al administrador que complete la asignación.';
      locationHelp.classList.add('form-help--warning');
    } else if (requestedLocation) {
      locationHelp.textContent = 'El código QR no corresponde a una ubicación activa. Selecciona la sede manualmente.';
      locationHelp.classList.add('form-help--warning');
    } else {
      locationHelp.classList.remove('form-help--warning');
      locationHelp.textContent = 'Selecciona la sede en la que se encuentra el desperfecto.';
    }
  }
}

function renderPriorities(priorities, defaultPriority) {
  priorityOptions.replaceChildren();
  const descriptions = {
    low: 'No afecta la operación y puede programarse.',
    medium: 'Afecta parcialmente, pero permite continuar.',
    high: 'Afecta una función importante o puede empeorar.',
    urgent: 'Existe riesgo para personas, instalaciones o continuidad operativa.',
  };

  priorities.forEach((priority) => {
    const label = document.createElement('label');
    label.className = 'priority-option';
    label.dataset.priority = priority.code;

    const input = document.createElement('input');
    input.type = 'radio';
    input.name = 'priority';
    input.value = priority.code;
    input.required = true;
    input.checked = priority.code === defaultPriority;

    const content = document.createElement('span');
    content.className = 'priority-option__content';

    const name = document.createElement('strong');
    name.textContent = priority.name;

    const description = document.createElement('small');
    description.textContent = descriptions[priority.code] || 'Prioridad estimada por el reportante.';

    content.append(name, description);
    label.append(input, content);
    priorityOptions.append(label);
  });
}

function setupFormInteractions() {
  document.querySelectorAll('[data-counted-input]').forEach((input) => {
    const counter = input.parentElement?.querySelector('[data-character-count]');
    const update = () => {
      if (counter) counter.textContent = `${input.value.length} / ${input.maxLength}`;
    };
    input.addEventListener('input', update);
    update();
  });

  selectFilesButton?.addEventListener('click', (event) => {
    event.stopPropagation();
    fileInput?.click();
  });

  uploadZone?.addEventListener('click', (event) => {
    if (event.target.closest('button')) return;
    fileInput?.click();
  });

  uploadZone?.addEventListener('keydown', (event) => {
    if (['Enter', ' '].includes(event.key)) {
      event.preventDefault();
      fileInput?.click();
    }
  });

  fileInput?.addEventListener('change', () => {
    addFiles([...fileInput.files]);
    fileInput.value = '';
  });

  ['dragenter', 'dragover'].forEach((eventName) => {
    uploadZone?.addEventListener(eventName, (event) => {
      event.preventDefault();
      uploadZone.classList.add('is-dragging');
    });
  });

  ['dragleave', 'drop'].forEach((eventName) => {
    uploadZone?.addEventListener(eventName, (event) => {
      event.preventDefault();
      uploadZone.classList.remove('is-dragging');
    });
  });

  uploadZone?.addEventListener('drop', (event) => {
    addFiles([...event.dataTransfer.files]);
  });

  form?.addEventListener('submit', submitTicket);
  window.addEventListener('beforeunload', releaseObjectUrls);
}

function addFiles(files) {
  clearUploadMessage();
  const upload = state.context?.upload || {};
  const maxFiles = Number(upload.max_files || 8);
  const maxSizeBytes = Number(upload.max_size_mb || 8) * 1024 * 1024;
  const allowedTypes = new Set(upload.allowed_mime_types || ['image/jpeg', 'image/png']);
  const accepted = [];
  const errors = [];

  files.forEach((file) => {
    if (!(file instanceof File)) return;
    const extension = file.name.split('.').pop()?.toLowerCase() || '';
    const allowedByExtension = ['jpg', 'jpeg', 'png'].includes(extension);
    if (!allowedTypes.has(file.type) && !allowedByExtension) {
      errors.push(`${file.name}: formato no permitido.`);
      return;
    }
    if (file.size <= 0 || file.size > maxSizeBytes) {
      errors.push(`${file.name}: supera ${upload.max_size_mb || 8} MB.`);
      return;
    }
    const duplicate = [...state.files, ...accepted].some((current) => fileKey(current) === fileKey(file));
    if (duplicate) {
      errors.push(`${file.name}: ya fue agregado.`);
      return;
    }
    accepted.push(file);
  });

  const availableSlots = Math.max(0, maxFiles - state.files.length);
  if (accepted.length > availableSlots) {
    errors.push(`Solo puedes adjuntar ${maxFiles} fotografías.`);
    accepted.splice(availableSlots);
  }

  state.files.push(...accepted);
  renderEvidenceFiles();

  if (errors.length > 0) {
    showUploadMessage(errors.join(' '), 'error');
  } else if (accepted.length > 0) {
    showUploadMessage(`${accepted.length} ${accepted.length === 1 ? 'fotografía agregada' : 'fotografías agregadas'}.`, 'success');
  }
}

function renderEvidenceFiles() {
  evidenceGrid.replaceChildren();
  evidenceGrid.hidden = state.files.length === 0;

  state.files.forEach((file, index) => {
    const item = document.createElement('article');
    item.className = 'evidence-card';

    const image = document.createElement('img');
    image.alt = `Vista previa de ${file.name}`;
    image.src = getObjectUrl(file);

    const details = document.createElement('div');
    details.className = 'evidence-card__details';

    const name = document.createElement('strong');
    name.textContent = file.name;
    name.title = file.name;

    const size = document.createElement('span');
    size.textContent = formatFileSize(file.size);

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'evidence-card__remove';
    remove.setAttribute('aria-label', `Eliminar ${file.name}`);
    remove.innerHTML = '<svg aria-hidden="true"><use href="#icon-trash"></use></svg>';
    remove.addEventListener('click', () => removeFile(index));

    details.append(name, size);
    item.append(image, details, remove);
    evidenceGrid.append(item);
  });

  updateUploadCounter();
}

function removeFile(index) {
  const [removed] = state.files.splice(index, 1);
  const url = state.objectUrls.get(removed);
  if (url) {
    URL.revokeObjectURL(url);
    state.objectUrls.delete(removed);
  }
  renderEvidenceFiles();
  clearUploadMessage();
}

function getObjectUrl(file) {
  if (!state.objectUrls.has(file)) {
    state.objectUrls.set(file, URL.createObjectURL(file));
  }
  return state.objectUrls.get(file);
}

function releaseObjectUrls() {
  state.objectUrls.forEach((url) => URL.revokeObjectURL(url));
  state.objectUrls.clear();
}

function updateUploadCounter() {
  const total = state.files.length;
  const max = Number(state.context?.upload?.max_files || 8);
  uploadCounter.textContent = `${total} de ${max} ${max === 1 ? 'archivo' : 'archivos'}`;
}

async function submitTicket(event) {
  event.preventDefault();
  if (state.submitting || !form) return;

  clearFormMessage();
  clearUploadMessage();

  if (!form.reportValidity()) {
    showFormMessage('Revisa los campos obligatorios antes de enviar.', 'error');
    focusFirstInvalidField();
    return;
  }

  if (state.files.length < 1) {
    showUploadMessage('Agrega al menos una fotografía como evidencia.', 'error');
    uploadZone?.focus();
    return;
  }

  const selectedPriority = form.querySelector('input[name="priority"]:checked');
  const payload = new FormData();
  payload.append('location', locationSelect.value);
  payload.append('specific_location', form.elements.specific_location.value.trim());
  payload.append('title', form.elements.title.value.trim());
  payload.append('description', form.elements.description.value.trim());
  payload.append('priority', selectedPriority?.value || '');
  state.files.forEach((file) => payload.append('evidence[]', file, file.name));

  setSubmitting(true);

  try {
    const response = await apiFormRequest('./api/tickets/create.php', {
      method: 'POST',
      body: payload,
    });
    const result = response.data;
    const ticket = result.ticket;
    const deliveryWarning = !result.email_delivery?.reporter || !result.email_delivery?.supervisor;

    const additionalText = result.warning
      ? `<p class="swal-ticket-warning">${escapeHtml(result.warning)}</p>`
      : deliveryWarning
        ? '<p class="swal-ticket-warning">El reporte fue creado, pero alguna notificación por correo no pudo entregarse.</p>'
        : '<p>Enviamos las notificaciones correspondientes.</p>';

    await window.Swal.fire({
      icon: 'success',
      title: 'Reporte creado',
      html: `
        <p>Tu reporte fue registrado con el folio:</p>
        <p class="swal-ticket-folio">${escapeHtml(ticket.folio)}</p>
        <p>${escapeHtml(ticket.location.name)} · ${escapeHtml(ticket.location.specific_location)}</p>
        ${additionalText}
      `,
      confirmButtonText: 'Ver ticket',
      allowOutsideClick: false,
      allowEscapeKey: false,
      customClass: {
        popup: 'app-alert',
        confirmButton: 'app-alert__confirm',
      },
    });

    releaseObjectUrls();
    window.location.replace(`./ticket.html?id=${encodeURIComponent(ticket.id)}`);
  } catch (error) {
    if (handleCoreBoundaryError(error)) {
      return;
    }

    showFormMessage(
      error.message || 'No fue posible crear el reporte.',
      'error'
    );
    window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
  } finally {
    setSubmitting(false);
  }
}

function setSubmitting(isSubmitting) {
  state.submitting = isSubmitting;
  submitButton.disabled = isSubmitting;
  submitButton.classList.toggle('is-loading', isSubmitting);
  submitLabel.textContent = isSubmitting ? 'Enviando reporte…' : 'Enviar reporte';
  form?.querySelectorAll('input, select, textarea, button').forEach((element) => {
    if (element === submitButton) return;
    if (element === locationSelect && state.context?.selected_location) return;
    element.disabled = isSubmitting;
  });
  if (!isSubmitting && state.context?.selected_location) locationSelect.disabled = true;
}

function focusFirstInvalidField() {
  form?.querySelector(':invalid')?.focus();
}

function showPageError(message) {
  if (!pageError) return;
  pageError.hidden = false;
  pageError.textContent = message;
}

function showFormMessage(message, type = 'error') {
  if (!formMessage) return;
  formMessage.textContent = message;
  formMessage.dataset.type = type;
}

function clearFormMessage() {
  if (!formMessage) return;
  formMessage.textContent = '';
  delete formMessage.dataset.type;
}

function showUploadMessage(message, type = 'error') {
  if (!uploadMessage) return;
  uploadMessage.textContent = message;
  uploadMessage.dataset.type = type;
}

function clearUploadMessage() {
  if (!uploadMessage) return;
  uploadMessage.textContent = '';
  delete uploadMessage.dataset.type;
}

function setupComingSoonActions() {
  document.querySelectorAll('[data-coming-soon]').forEach((element) => {
    element.addEventListener('click', async (event) => {
      event.preventDefault();
      await window.Swal?.fire({
        icon: 'info',
        title: 'Módulo en preparación',
        text: 'Esta funcionalidad se incorporará en una siguiente fase del proyecto.',
        confirmButtonText: 'Entendido',
        customClass: {
          popup: 'app-alert',
          confirmButton: 'app-alert__confirm',
        },
      });
    });
  });
}

function getLocationCode() {
  const code = new URLSearchParams(window.location.search).get('location') || '';
  return /^[a-z0-9_]{1,50}$/.test(code) ? code : '';
}


function fileKey(file) {
  return `${file.name}:${file.size}:${file.lastModified}`;
}

function formatFileSize(bytes) {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function escapeHtml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}
