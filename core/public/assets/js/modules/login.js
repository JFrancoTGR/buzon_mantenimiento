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
      window.location.replace('/change-password');
      return;
    }

    window.location.replace('/');
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