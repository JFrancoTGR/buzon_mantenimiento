import {
  apiRequest,
} from '../core/api.js';

import {
  setupPasswordVisibility,
} from '../components/passwordVisibility.js';

setupPasswordVisibility();

const form =
  document.querySelector('[data-password-form]');

const message =
  document.querySelector('[data-message]');

const submitButton =
  document.querySelector('[data-submit-button]');

const returnPath = getSafeMaintenanceReturnPath();

form?.addEventListener('submit', async (event) => {
  event.preventDefault();

  setMessage('');

  if (!form.reportValidity()) {
    return;
  }

  setLoading(true);

  const values = Object.fromEntries(
    new FormData(form).entries()
  );

  try {
    await apiRequest(
      '/api/auth/change-password',
      {
        method: 'POST',

        body: JSON.stringify({
          current_password:
            values.current_password || '',

          new_password:
            values.new_password || '',

          new_password_confirmation:
            values.new_password_confirmation || '',
        }),
      }
    );

    await showSuccess();

    window.location.replace(
      withReturn('/login', returnPath)
    );
  } catch (error) {
    if (
      error.status === 401
      && error.code !== 'current_password_invalid'
    ) {
      window.location.replace(
        withReturn('/login', returnPath)
      );
      return;
    }

    setMessage(
      error.message
      || 'No fue posible actualizar la contraseña.',
      'error'
    );
  } finally {
    setLoading(false);
  }
});

function getSafeMaintenanceReturnPath() {
  const value =
    new URLSearchParams(window.location.search)
      .get('return')
    || '';

  if (!value) {
    return '';
  }

  try {
    const url = new URL(value, window.location.origin);

    const isMaintenancePath =
      url.pathname === '/maintenance'
      || url.pathname === '/maintenance/'
      || url.pathname.startsWith('/maintenance/');

    if (
      url.origin !== window.location.origin
      || !isMaintenancePath
    ) {
      return '';
    }

    return `${url.pathname}${url.search}${url.hash}`;
  } catch {
    return '';
  }
}

function withReturn(path, returnPathValue) {
  return returnPathValue
    ? `${path}?return=${encodeURIComponent(returnPathValue)}`
    : path;
}

function setLoading(isLoading) {
  if (!submitButton) return;

  submitButton.disabled = isLoading;

  submitButton.textContent = isLoading
    ? 'Actualizando…'
    : 'Guardar contraseña';
}

function setMessage(text, type = '') {
  if (!message) return;

  message.textContent = text;
  message.dataset.type = type;
}

async function showSuccess() {
  if (!window.Swal) {
    window.alert(
      'Tu contraseña se cambió correctamente.'
    );

    return;
  }

  await window.Swal.fire({
    icon: 'success',
    title: 'Contraseña actualizada',
    text:
      'Por seguridad cerramos todas tus sesiones. Inicia sesión nuevamente.',
    confirmButtonText: 'Ir al inicio de sesión',
    allowOutsideClick: false,
    allowEscapeKey: false,
    customClass: {
      popup: 'auth-alert',
      confirmButton: 'app-alert__confirm',
    },
  });
}