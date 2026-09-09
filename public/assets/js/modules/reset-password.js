import { apiRequest, getCsrfToken } from '../core/api.js';
import { setupPasswordVisibility } from '../components/passwordVisibility.js';
import { formatDateTime } from '../utils/date.js';

setupPasswordVisibility();

const loadingSection = document.querySelector('[data-reset-loading]');
const formSection = document.querySelector('[data-reset-form-section]');
const errorSection = document.querySelector('[data-reset-error]');
const errorMessage = document.querySelector('[data-error-message]');
const form = document.querySelector('[data-reset-form]');
const message = document.querySelector('[data-message]');
const submitButton = document.querySelector('[data-submit-button]');
const token = new URLSearchParams(window.location.hash.replace(/^#/, '')).get('token') || '';

try {
  if (!/^[a-f0-9]{64}$/i.test(token)) {
    throw new Error('El enlace de recuperación no es válido.');
  }

  await getCsrfToken();

  const payload = await apiRequest('./api/auth/password-reset-context.php', {
    method: 'POST',
    body: JSON.stringify({ token }),
  });

  document.querySelector('[data-reset-name]').textContent = payload.data.first_name || '';
  document.querySelector('[data-reset-email]').textContent = payload.data.email_masked || '';
  document.querySelector('[data-reset-expiry]').textContent = formatDateTime(payload.data.expires_at);

  loadingSection.hidden = true;
  formSection.hidden = false;
} catch (error) {
  showError(error.message || 'El enlace de recuperación no está disponible.');
}

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  setMessage('');

  if (!form.reportValidity()) return;

  const values = Object.fromEntries(new FormData(form).entries());
  setLoading(true);

  try {
    const payload = await apiRequest('./api/auth/reset-password.php', {
      method: 'POST',
      body: JSON.stringify({
        token,
        password: values.password || '',
        password_confirmation: values.password_confirmation || '',
      }),
    });

    await showSuccess();
    window.location.replace(payload.data.redirect_url || './login.html');
  } catch (error) {
    if (error.status === 410) {
      showError(error.message || 'El enlace de recuperación dejó de ser válido.');
      return;
    }

    setMessage(error.message || 'No fue posible restablecer la contraseña.', 'error');
  } finally {
    setLoading(false);
  }
});

function showError(text) {
  loadingSection.hidden = true;
  formSection.hidden = true;
  errorSection.hidden = false;

  if (errorMessage) {
    errorMessage.textContent = text;
  }
}

function setLoading(isLoading) {
  if (!submitButton) return;
  submitButton.disabled = isLoading;
  submitButton.classList.toggle('is-loading', isLoading);
  submitButton.textContent = isLoading
    ? 'Actualizando…'
    : 'Restablecer contraseña';
}

function setMessage(text, type = '') {
  if (!message) return;
  message.textContent = text;
  message.dataset.type = type;
}

async function showSuccess() {
  if (!window.Swal) {
    window.alert('Tu contraseña fue restablecida correctamente. Inicia sesión con tu nueva contraseña.');
    return;
  }

  await window.Swal.fire({
    icon: 'success',
    title: 'Contraseña restablecida',
    text: 'Tu contraseña fue actualizada correctamente. Inicia sesión nuevamente con tus nuevas credenciales.',
    confirmButtonText: 'Ir al inicio de sesión',
    allowOutsideClick: false,
    allowEscapeKey: false,
    customClass: {
      popup: 'auth-alert',
      confirmButton: 'app-alert__confirm',
    },
  });
}
