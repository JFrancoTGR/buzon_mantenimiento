import { apiRequest, getCsrfToken } from '../core/api.js';

const requestSection = document.querySelector('[data-request-section]');
const resultSection = document.querySelector('[data-result-section]');
const form = document.querySelector('[data-forgot-password-form]');
const message = document.querySelector('[data-message]');
const resultMessage = document.querySelector('[data-result-message]');
const submitButton = document.querySelector('[data-submit-button]');
const tryAgainButton = document.querySelector('[data-try-again]');

await getCsrfToken();

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  setMessage('');

  if (!form.reportValidity()) return;

  const formData = new FormData(form);
  setLoading(true);

  try {
    const payload = await apiRequest('./api/auth/forgot-password.php', {
      method: 'POST',
      body: JSON.stringify({
        email: String(formData.get('email') || '').trim().toLowerCase(),
      }),
    });

    if (resultMessage) {
      resultMessage.textContent = payload.data.message
        || 'Si existe una cuenta habilitada asociada con ese correo, recibirás un enlace para restablecer tu contraseña.';
    }

    requestSection.hidden = true;
    resultSection.hidden = false;
  } catch (error) {
    setMessage(error.message || 'No fue posible procesar la solicitud.', 'error');
  } finally {
    setLoading(false);
  }
});

tryAgainButton?.addEventListener('click', () => {
  resultSection.hidden = true;
  requestSection.hidden = false;
  form?.reset();
  setMessage('');
  form?.querySelector('input[name="email"]')?.focus();
});

function setLoading(isLoading) {
  if (!submitButton) return;
  submitButton.disabled = isLoading;
  submitButton.classList.toggle('is-loading', isLoading);
  submitButton.textContent = isLoading
    ? 'Procesando…'
    : 'Enviar enlace de recuperación';
}

function setMessage(text, type = '') {
  if (!message) return;
  message.textContent = text;
  message.dataset.type = type;
}
