import { apiRequest } from '../core/api.js';
import { setupPasswordVisibility } from '../components/passwordVisibility.js';

setupPasswordVisibility();

const form = document.querySelector('[data-password-form]');
const message = document.querySelector('[data-message]');
const submitButton = form?.querySelector('button[type="submit"]');
const locationCode = getLocationCode();
const returnPath = getReturnPath();

try {
  const payload = await apiRequest('./api/auth/me.php');
  if (!payload.data.user.must_change_password) {
    window.location.replace(returnPath || (locationCode ? withLocation('./new-ticket.html') : './dashboard.html'));
  }
} catch {
  window.location.replace(withContext('./login.html'));
}

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  setMessage('');
  setLoading(true);

  const formData = new FormData(form);

  try {
    await apiRequest('./api/auth/change-password.php', {
      method: 'POST',
      body: JSON.stringify({
        current_password: formData.get('current_password'),
        new_password: formData.get('new_password'),
        new_password_confirmation: formData.get('new_password_confirmation'),
      }),
    });

    await showSuccessAlert();
    window.location.replace(withContext('./login.html'));
  } catch (error) {
    setMessage(error.message || 'No fue posible actualizar la contraseña.', 'error');
  } finally {
    setLoading(false);
  }
});

function getLocationCode() {
  const code = new URLSearchParams(window.location.search).get('location') || '';
  return /^[a-z0-9_]{1,50}$/.test(code) ? code : '';
}

function getReturnPath() {
  const value = new URLSearchParams(window.location.search).get('return') || '';
  return /^\.\/ticket\.html\?id=\d+$/.test(value) ? value : '';
}

function withLocation(path) {
  return locationCode ? `${path}?location=${encodeURIComponent(locationCode)}` : path;
}

function withContext(path) {
  const params = new URLSearchParams();
  if (locationCode) params.set('location', locationCode);
  if (returnPath) params.set('return', returnPath);
  const query = params.toString();
  return query ? `${path}?${query}` : path;
}

function setLoading(isLoading) {
  if (!submitButton) return;
  submitButton.disabled = isLoading;
  submitButton.classList.toggle('is-loading', isLoading);
  submitButton.textContent = isLoading ? 'Actualizando…' : 'Guardar contraseña';
}

function setMessage(text, type = '') {
  if (!message) return;
  message.textContent = text;
  message.dataset.type = type;
}

async function showSuccessAlert() {
  if (!window.Swal) {
    window.alert('Tu contraseña se cambió correctamente. Inicia sesión nuevamente con tus nuevas credenciales.');
    return;
  }

  await window.Swal.fire({
    icon: 'success',
    title: 'Contraseña actualizada',
    text: 'Tu contraseña se cambió correctamente. Inicia sesión nuevamente con tus nuevas credenciales.',
    confirmButtonText: 'Ir al inicio de sesión',
    allowOutsideClick: false,
    allowEscapeKey: false,
    buttonsStyling: true,
    customClass: {
      popup: 'auth-alert',
      confirmButton: 'app-alert__confirm',
    },
  });
}
