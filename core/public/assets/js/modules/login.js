import {
  apiRequest,
  getCsrfToken,
} from '../core/api.js';

import {
  setupPasswordVisibility,
} from '../components/passwordVisibility.js';

setupPasswordVisibility();

const form = document.querySelector('[data-login-form]');
const message = document.querySelector('[data-message]');
const submitButton = form?.querySelector('button[type="submit"]');
const returnPath = getSafeMaintenanceReturnPath();

initialize();

form?.addEventListener('submit', async (event) => {
  event.preventDefault();

  setMessage('');
  setLoading(true);

  const formData = new FormData(form);

  try {
    const payload = await apiRequest('/api/auth/login', {
      method: 'POST',

      body: JSON.stringify({
        email: formData.get('email'),
        password: formData.get('password'),
      }),
    });

    if (payload.data.user.must_change_password) {
      window.location.replace(
        withReturn('/change-password', returnPath)
      );
      return;
    }

    window.location.replace(returnPath || '/');
  } catch (error) {
    setMessage(
      error.message || 'No fue posible iniciar sesión.',
      'error'
    );
  } finally {
    setLoading(false);
  }
});

async function initialize() {
  try {
    await getCsrfToken();
  } catch (error) {
    setMessage(
      error.message || 'No fue posible iniciar el proceso de autenticación.',
      'error'
    );
  }
}

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
    ? 'Validando…'
    : 'Iniciar sesión';
}

function setMessage(text, type = '') {
  if (!message) return;

  message.textContent = text;
  message.dataset.type = type;
}