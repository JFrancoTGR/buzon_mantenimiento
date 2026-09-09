import { apiRequest, getCsrfToken } from '../core/api.js';
import { formatDateTime } from '../utils/date.js';
import { setupPasswordVisibility } from '../components/passwordVisibility.js';

setupPasswordVisibility();

const loading = document.querySelector('[data-invitation-loading]');
const formSection = document.querySelector('[data-invitation-form-section]');
const errorSection = document.querySelector('[data-invitation-error]');
const errorMessage = document.querySelector('[data-error-message]');
const form = document.querySelector('[data-invitation-form]');
const message = document.querySelector('[data-message]');
const submitButton = document.querySelector('[data-submit-button]');
const token = new URLSearchParams(window.location.hash.replace(/^#/, '')).get('token') || '';

let invitation = null;

try {
  if (!/^[a-f0-9]{64}$/i.test(token)) throw new Error('La invitación no es válida.');
  await getCsrfToken();
  const payload = await apiRequest('./api/auth/invitation-context.php', {
    method: 'POST',
    body: JSON.stringify({ token }),
  });
  invitation = payload.data;
  document.querySelector('[data-invitation-name]').textContent = invitation.first_name || '';
  document.querySelector('[data-invitation-email]').textContent = invitation.email_masked || '';
  document.querySelector('[data-invitation-expiry]').textContent = formatDateTime(invitation.expires_at);
  loading.hidden = true;
  formSection.hidden = false;
} catch (error) {
  showError(error.message || 'La invitación no está disponible.');
}

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  message.textContent = '';
  if (!form.reportValidity()) return;

  const values = Object.fromEntries(new FormData(form).entries());
  submitButton.disabled = true;
  submitButton.textContent = 'Activando…';
  try {
    const payload = await apiRequest('./api/auth/accept-invitation.php', {
      method: 'POST',
      body: JSON.stringify({ token, ...values }),
    });
    await showSuccess();
    window.location.replace(payload.data.redirect_url || './dashboard.html');
  } catch (error) {
    if (error.status === 410) {
      showError(error.message || 'La invitación ya no está disponible.');
      return;
    }
    message.textContent = error.message || 'No fue posible activar la cuenta.';
  } finally {
    submitButton.disabled = false;
    submitButton.textContent = 'Activar mi cuenta';
  }
});

function showError(text) {
  loading.hidden = true;
  formSection.hidden = true;
  errorSection.hidden = false;
  errorMessage.textContent = text;
}

async function showSuccess() {
  if (!window.Swal) {
    window.alert('Tu cuenta quedó activa.');
    return;
  }
  await window.Swal.fire({
    icon: 'success',
    title: 'Cuenta activada',
    text: 'Tu contraseña fue creada correctamente. Ya puedes utilizar la plataforma.',
    confirmButtonText: 'Entrar a la plataforma',
    allowOutsideClick: false,
    allowEscapeKey: false,
    customClass: { confirmButton: 'app-alert__confirm' },
  });
}
