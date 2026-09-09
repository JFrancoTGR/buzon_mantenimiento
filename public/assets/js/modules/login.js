import { apiRequest, getCsrfToken } from '../core/api.js';
import { setupPasswordVisibility } from '../components/passwordVisibility.js';

setupPasswordVisibility();

const form = document.querySelector('[data-login-form]');
const message = document.querySelector('[data-message]');
const submitButton = form?.querySelector('button[type="submit"]');
const registerLink = document.querySelector('#register-link');
const locationCode = getLocationCode();
const returnPath = getReturnPath();

await getCsrfToken();
preserveRegistrationLocation();

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  setMessage('');
  setLoading(true);

  const formData = new FormData(form);

  try {
    const payload = await apiRequest('./api/auth/login.php', {
      method: 'POST',
      body: JSON.stringify({
        email: formData.get('email'),
        password: formData.get('password'),
      }),
    });

    if (payload.data.user.must_change_password) {
      window.location.href = withContext('./change-password.html');
      return;
    }

    window.location.href = returnPath || (locationCode
      ? withLocation('./new-ticket.html')
      : './dashboard.html');
  } catch (error) {
    setMessage(error.message || 'No fue posible iniciar sesión.', 'error');
  } finally {
    setLoading(false);
  }
});

function preserveRegistrationLocation() {
  if (!registerLink) return;
  registerLink.href = locationCode
    ? `./register.html?location=${encodeURIComponent(locationCode)}`
    : './register.html';
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

function getLocationCode() {
  const code = new URLSearchParams(window.location.search).get('location') || '';
  return /^[a-z0-9_]{1,50}$/.test(code) ? code : '';
}

function getReturnPath() {
  const value = new URLSearchParams(window.location.search).get('return') || '';
  return /^\.\/ticket\.html\?id=\d+$/.test(value) ? value : '';
}

function setLoading(isLoading) {
  if (!submitButton) return;
  submitButton.disabled = isLoading;
  submitButton.textContent = isLoading ? 'Validando…' : 'Iniciar sesión';
}

function setMessage(text, type = '') {
  if (!message) return;
  message.textContent = text;
  message.dataset.type = type;
}
