import { apiRequest } from '../core/api.js';
import { applyPermissionVisibility } from '../core/permissions.js';
import { setupSidebar } from '../components/sidebar.js';
import { setupUserMenu } from '../components/userMenu.js';
import { setupNotificationsMenu } from '../components/notificationsMenu.js';
import { formatDateTime } from '../utils/date.js';

const profileForm = document.querySelector('[data-profile-form]');
const profileMessage = document.querySelector('[data-profile-message]');
const profileSubmit = document.querySelector('[data-profile-submit]');
const passwordForm = document.querySelector('[data-password-form]');
const passwordMessage = document.querySelector('[data-password-message]');
const passwordSubmit = document.querySelector('[data-password-submit]');

let currentUser = null;

try {
  const payload = await apiRequest('./api/auth/me.php');
  currentUser = payload.data.user;

  if (currentUser.must_change_password) {
    window.location.replace('./change-password.html');
    throw new Error('password_change_required');
  }
} catch (error) {
  if (error.message !== 'password_change_required') {
    window.location.replace('./login.html');
  }
}

if (currentUser) {
  applyPermissionVisibility(currentUser);
  setupSidebar();
  setupUserMenu(currentUser);
  setupComingSoonActions();
  bindEvents();
  await loadNotifications();
  await loadProfile();

  if (window.location.hash === '#security') {
    requestAnimationFrame(() => {
      document.querySelector('#security')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }
}

function bindEvents() {
  profileForm?.addEventListener('submit', submitProfile);
  passwordForm?.addEventListener('submit', submitPassword);
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

async function loadProfile() {
  try {
    const payload = await apiRequest('./api/account/profile.php');
    renderProfile(payload.data.profile);
  } catch (error) {
    if (error.status === 401) {
      window.location.replace('./login.html');
      return;
    }
    setMessage(profileMessage, error.message || 'No fue posible cargar tu perfil.', 'error');
    setProfileDisabled(true);
  }
}

async function submitProfile(event) {
  event.preventDefault();
  setMessage(profileMessage, '');
  setProfileLoading(true);

  const formData = new FormData(profileForm);

  try {
    const payload = await apiRequest('./api/account/update-profile.php', {
      method: 'POST',
      body: JSON.stringify({
        first_name: formData.get('first_name'),
        last_name: formData.get('last_name'),
      }),
    });

    const profile = payload.data.profile;
    renderProfile(profile);
    syncHeaderIdentity(profile);

    if (payload.data.changed) {
      await notify('Perfil actualizado', 'Tus datos personales se guardaron correctamente.', 'success');
    } else {
      await notify('Sin cambios', 'Tu perfil ya contiene esos datos.', 'info');
    }
  } catch (error) {
    if (error.status === 401) {
      window.location.replace('./login.html');
      return;
    }
    setMessage(profileMessage, error.message || 'No fue posible actualizar tu perfil.', 'error');
  } finally {
    setProfileLoading(false);
  }
}

async function submitPassword(event) {
  event.preventDefault();
  setMessage(passwordMessage, '');
  setPasswordLoading(true);

  const formData = new FormData(passwordForm);

  try {
    await apiRequest('./api/auth/change-password.php', {
      method: 'POST',
      body: JSON.stringify({
        current_password: formData.get('current_password'),
        new_password: formData.get('new_password'),
        new_password_confirmation: formData.get('new_password_confirmation'),
      }),
    });

    await notify(
      'Contraseña actualizada',
      'Por seguridad cerramos tus sesiones. Inicia sesión nuevamente con tu nueva contraseña.',
      'success',
      false
    );
    window.location.replace('./login.html');
  } catch (error) {
    if (error.status === 401 && error.code !== 'current_password_invalid') {
      window.location.replace('./login.html');
      return;
    }
    setMessage(passwordMessage, error.message || 'No fue posible actualizar la contraseña.', 'error');
  } finally {
    setPasswordLoading(false);
  }
}

function renderProfile(profile) {
  if (!profile || !profileForm) return;

  const firstName = profileForm.elements.namedItem('first_name');
  const lastName = profileForm.elements.namedItem('last_name');
  const email = profileForm.elements.namedItem('email');

  if (firstName) firstName.value = profile.first_name || '';
  if (lastName) lastName.value = profile.last_name || '';
  if (email) email.value = profile.email || '';

  setText('[data-profile-role]', profile.role?.name || profile.role?.code || '—');
  setText('[data-profile-status]', statusLabel(profile.status));
  setText(
    '[data-profile-email-verified]',
    profile.email_verified_at ? `Verificado el ${formatDateTime(profile.email_verified_at)}` : 'Sin verificar'
  );
  setText(
    '[data-profile-last-login]',
    profile.last_login_at ? formatDateTime(profile.last_login_at) : 'Sin accesos registrados'
  );
  setText(
    '[data-profile-created]',
    profile.created_at ? formatDateTime(profile.created_at) : '—'
  );
}

function syncHeaderIdentity(profile) {
  const fullName = profile.full_name || [profile.first_name, profile.last_name].filter(Boolean).join(' ') || 'Usuario';
  const initials = [profile.first_name, profile.last_name]
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => String(part).charAt(0).toUpperCase())
    .join('') || 'US';

  document.querySelectorAll('[data-user-name], [data-menu-user-name]').forEach((element) => {
    element.textContent = fullName;
  });
  document.querySelectorAll('[data-user-email], [data-menu-user-email]').forEach((element) => {
    element.textContent = profile.email || '';
  });

  const initialsElement = document.querySelector('[data-user-initials]');
  if (initialsElement) initialsElement.textContent = initials;
}

function setProfileLoading(isLoading) {
  if (!profileSubmit) return;
  profileSubmit.disabled = isLoading;
  profileSubmit.classList.toggle('is-loading', isLoading);
  profileSubmit.textContent = isLoading ? 'Guardando…' : 'Guardar cambios';
}

function setPasswordLoading(isLoading) {
  if (!passwordSubmit) return;
  passwordSubmit.disabled = isLoading;
  passwordSubmit.classList.toggle('is-loading', isLoading);
  passwordSubmit.textContent = isLoading ? 'Actualizando…' : 'Cambiar contraseña';
}

function setProfileDisabled(disabled) {
  profileForm?.querySelectorAll('input, button').forEach((element) => {
    element.disabled = disabled;
  });
}

function setMessage(element, text, type = '') {
  if (!element) return;
  element.textContent = text;
  element.dataset.type = type;
}

function setText(selector, value) {
  const element = document.querySelector(selector);
  if (element) element.textContent = value;
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

async function notify(title, text, icon = 'success', dismissible = true) {
  if (!window.Swal) {
    window.alert(`${title}\n\n${text}`);
    return;
  }

  await window.Swal.fire({
    icon,
    title,
    text,
    confirmButtonText: 'Aceptar',
    allowOutsideClick: dismissible,
    allowEscapeKey: dismissible,
    customClass: { confirmButton: 'app-alert__confirm' },
  });
}

function setupComingSoonActions() {
  document.querySelectorAll('[data-coming-soon]').forEach((element) => {
    element.addEventListener('click', async (event) => {
      if (element.tagName === 'A') event.preventDefault();
      await notify('Módulo en preparación', 'Esta sección se habilitará en una siguiente etapa.', 'info');
    });
  });
}
