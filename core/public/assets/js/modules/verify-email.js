import { apiRequest, getCsrfToken } from '../core/api.js';

const loadingState = document.querySelector('[data-verification-state]');
const errorState = document.querySelector('[data-verification-error]');
const errorMessage = document.querySelector('[data-verification-message]');
const params = new URLSearchParams(window.location.search);
const token = params.get('token') || '';
const next = params.get('next') || '';

try {
  if (!/^[a-f0-9]{64}$/i.test(token)) {
    throw new Error('El enlace de verificación no es válido.');
  }

  await getCsrfToken();

  const payload = await apiRequest('/api/auth/verify-email', {
    method: 'POST',
    body: JSON.stringify({ token, next }),
  });

  await showSuccessAlert();
  window.location.replace(payload.data.redirect_url || '/');
} catch (error) {
  showError(error.message || 'No fue posible verificar el correo.');
}

async function showSuccessAlert() {
  const text = 'Tu cuenta de EU Tools quedó activa. Ya puedes continuar a la Plataforma de Mantenimiento.';

  if (!window.Swal) {
    window.alert(text);
    return;
  }

  await window.Swal.fire({
    icon: 'success',
    title: 'Correo verificado',
    text,
    confirmButtonText: 'Continuar',
    allowOutsideClick: false,
    allowEscapeKey: false,
    customClass: {
      popup: 'auth-alert',
      confirmButton: 'app-alert__confirm',
    },
  });
}

function showError(text) {
  if (loadingState) loadingState.hidden = true;
  if (errorState) errorState.hidden = false;
  if (errorMessage) errorMessage.textContent = text;
}
