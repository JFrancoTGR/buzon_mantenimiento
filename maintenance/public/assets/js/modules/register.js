import { apiRequest, getCsrfToken } from '../core/api.js';
import { setupPasswordVisibility } from '../components/passwordVisibility.js';

setupPasswordVisibility();

const formSection = document.querySelector('[data-registration-form-section]');
const resultSection = document.querySelector('[data-registration-result]');
const form = document.querySelector('[data-register-form]');
const message = document.querySelector('[data-message]');
const resultMessage = document.querySelector('[data-result-message]');
const resultEmail = document.querySelector('[data-result-email]');
const deliveryMessage = document.querySelector('[data-delivery-message]');
const submitButton = form?.querySelector('button[type="submit"]');
const resendButton = document.querySelector('[data-resend-button]');
const useAnotherEmailButton = document.querySelector('[data-use-another-email]');
const locationContext = document.querySelector('[data-location-context]');
const locationName = document.querySelector('[data-location-name]');
const loginLink = document.querySelector('#login-link');

const locationCode = getLocationCode();
let registeredEmail = '';
let location = null;

await getCsrfToken();
await loadContext();
preserveLocation(loginLink, './login.html');

form?.addEventListener('submit', async (event) => {
  event.preventDefault();
  setMessage('');

  if (!form.checkValidity()) {
    form.reportValidity();
    return;
  }

  setLoading(true);
  const formData = new FormData(form);
  registeredEmail = String(formData.get('email') || '').trim().toLowerCase();

  try {
    const payload = await apiRequest('./api/auth/register.php', {
      method: 'POST',
      body: JSON.stringify({
        first_name: formData.get('first_name'),
        last_name: formData.get('last_name'),
        email: registeredEmail,
        password: formData.get('password'),
        password_confirmation: formData.get('password_confirmation'),
        privacy_accepted: formData.get('privacy_accepted') === '1',
        company_website: formData.get('company_website'),
        location: location?.code || locationCode,
      }),
    });

    showResult(payload.data);
  } catch (error) {
    if (error.code === 'account_exists' && registeredEmail) {
      showExistingAccountResult(error.message);
    } else {
      setMessage(error.message || 'No fue posible crear la cuenta.', 'error');
    }
  } finally {
    setLoading(false);
  }
});

resendButton?.addEventListener('click', async () => {
  if (!registeredEmail) return;

  resendButton.disabled = true;
  resultMessage.textContent = 'Procesando solicitud…';
  resultMessage.dataset.type = '';

  try {
    const payload = await apiRequest('./api/auth/resend-verification.php', {
      method: 'POST',
      body: JSON.stringify({
        email: registeredEmail,
        location: location?.code || locationCode,
      }),
    });
    resultMessage.textContent = payload.data.message;
    resultMessage.dataset.type = 'success';
  } catch (error) {
    resultMessage.textContent = error.message || 'No fue posible procesar el reenvío.';
    resultMessage.dataset.type = 'error';
  } finally {
    resendButton.disabled = false;
  }
});

useAnotherEmailButton?.addEventListener('click', () => {
  resultSection.hidden = true;
  formSection.hidden = false;
  registeredEmail = '';
  form?.reset();
  setMessage('');
  form?.querySelector('input[name="email"]')?.focus();
});

async function loadContext() {
  if (!locationCode) return;

  try {
    const payload = await apiRequest(`./api/auth/registration-context.php?location=${encodeURIComponent(locationCode)}`);
    location = payload.data.location;

    if (location && locationContext && locationName) {
      locationName.textContent = location.name;
      locationContext.hidden = false;
    }
  } catch {
    location = null;
  }
}

function showResult(data) {
  formSection.hidden = true;
  resultSection.hidden = false;
  resultEmail.textContent = data.email_masked || registeredEmail;

  if (data.email_sent === false) {
    deliveryMessage.textContent = 'La cuenta fue creada, pero no pudimos enviar el correo. Utiliza el botón de reenvío.';
    resultMessage.textContent = 'Verifica la configuración de correo si el problema continúa.';
    resultMessage.dataset.type = 'error';
  } else {
    deliveryMessage.textContent = 'Debes confirmar tu correo antes de crear un reporte.';
    resultMessage.textContent = '';
    resultMessage.dataset.type = '';
  }
}

function showExistingAccountResult(text) {
  formSection.hidden = true;
  resultSection.hidden = false;
  resultEmail.textContent = maskEmail(registeredEmail);
  deliveryMessage.textContent = text || 'Ya existe una cuenta asociada con este correo.';
  resultMessage.textContent = 'Puedes iniciar sesión o solicitar un nuevo enlace si la cuenta aún está pendiente.';
  resultMessage.dataset.type = '';
}

function maskEmail(email) {
  const [local = '', domain = ''] = String(email).split('@');
  if (!domain) return email;
  const visible = local.slice(0, Math.min(2, local.length));
  return `${visible}${'*'.repeat(Math.max(3, local.length - visible.length))}@${domain}`;
}

function setLoading(isLoading) {
  if (!submitButton) return;
  submitButton.disabled = isLoading;
  submitButton.classList.toggle('is-loading', isLoading);
  submitButton.textContent = isLoading ? 'Creando cuenta…' : 'Crear cuenta';
}

function setMessage(text, type = '') {
  if (!message) return;
  message.textContent = text;
  message.dataset.type = type;
}

function getLocationCode() {
  const code = new URLSearchParams(window.location.search).get('location') || '';
  return /^[a-z0-9_]{1,50}$/.test(code) ? code : '';
}

function preserveLocation(link, baseHref) {
  if (!link) return;
  link.href = locationCode
    ? `${baseHref}?location=${encodeURIComponent(locationCode)}`
    : baseHref;
}
