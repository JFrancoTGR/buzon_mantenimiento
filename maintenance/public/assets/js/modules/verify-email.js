import { apiRequest, getCsrfToken } from '../core/api.js';

const loadingState = document.querySelector('[data-verification-state]');
const errorState = document.querySelector('[data-verification-error]');
const errorMessage = document.querySelector('[data-verification-message]');
const token = new URLSearchParams(window.location.search).get('token') || '';

try {
  if (!/^[a-f0-9]{64}$/i.test(token)) {
    throw new Error('El enlace de verificación no es válido.');
  }

  await getCsrfToken();
  const payload = await apiRequest('./api/auth/verify-email.php', {
    method: 'POST',
    body: JSON.stringify({ token }),
  });

  const locationText = payload.data.location?.name
    ? ` La ubicación ${payload.data.location.name} quedó asociada con tu primer reporte.`
    : '';

  await showSuccessAlert(locationText);
  window.location.replace(payload.data.redirect_url || './new-ticket.html');
} catch (error) {
  showError(error.message || 'No fue posible verificar el correo.');
}

async function showSuccessAlert(locationText) {
  if (!window.Swal) {
    window.alert(`Tu cuenta está lista. Ya puedes crear tu primer reporte.${locationText}`);
    return;
  }

  await window.Swal.fire({
    icon: 'success',
    title: 'Correo verificado',
    text: `Tu cuenta está lista. Ya puedes crear tu primer reporte de mantenimiento.${locationText}`,
    confirmButtonText: 'Crear reporte',
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
