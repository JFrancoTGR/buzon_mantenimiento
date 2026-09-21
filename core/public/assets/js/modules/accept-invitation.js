import {
  apiRequest,
  getCsrfToken,
} from '../core/api.js';

import {
  formatDateTime,
} from '../utils/date.js';

import {
  setupPasswordVisibility,
} from '../components/passwordVisibility.js';

setupPasswordVisibility();

const loading =
  document.querySelector('[data-invitation-loading]');

const formSection =
  document.querySelector('[data-invitation-form-section]');

const errorSection =
  document.querySelector('[data-invitation-error]');

const errorMessage =
  document.querySelector('[data-error-message]');

const form =
  document.querySelector('[data-invitation-form]');

const message =
  document.querySelector('[data-message]');

const submitButton =
  document.querySelector('[data-submit-button]');

const token =
  new URLSearchParams(
    window.location.hash.replace(/^#/, '')
  ).get('token') || '';

initialize();

form?.addEventListener(
  'submit',
  async (event) => {
    event.preventDefault();

    setMessage('');

    if (! form.reportValidity()) {
      return;
    }

    setLoading(true);

    const values = Object.fromEntries(
      new FormData(form).entries()
    );

    try {
      const payload = await apiRequest(
        '/api/auth/accept-invitation',
        {
          method: 'POST',

          body: JSON.stringify({
            token,
            ...values,
          }),
        }
      );

      await showSuccess();

      window.location.replace(
        payload.data.redirect_url || '/'
      );
    } catch (error) {
      if (error.status === 410) {
        showError(
          error.message
          || 'La invitación ya no está disponible.'
        );

        return;
      }

      setMessage(
        error.message
        || 'No fue posible activar la cuenta.',
        'error'
      );
    } finally {
      setLoading(false);
    }
  }
);

async function initialize() {
  try {
    if (! /^[a-f0-9]{64}$/i.test(token)) {
      throw new Error(
        'La invitación no es válida.'
      );
    }

    await getCsrfToken();

    const payload = await apiRequest(
      '/api/auth/invitation-context',
      {
        method: 'POST',

        body: JSON.stringify({
          token,
        }),
      }
    );

    const invitation = payload.data;

    const name =
      document.querySelector(
        '[data-invitation-name]'
      );

    const email =
      document.querySelector(
        '[data-invitation-email]'
      );

    const expiry =
      document.querySelector(
        '[data-invitation-expiry]'
      );

    if (name) {
      name.textContent =
        invitation.first_name || '';
    }

    if (email) {
      email.textContent =
        invitation.email_masked || '';
    }

    if (expiry) {
      expiry.textContent =
        formatDateTime(
          invitation.expires_at
        );
    }

    loading.hidden = true;
    formSection.hidden = false;
  } catch (error) {
    showError(
      error.message
      || 'La invitación no está disponible.'
    );
  }
}

function showError(text) {
  loading.hidden = true;
  formSection.hidden = true;
  errorSection.hidden = false;

  errorMessage.textContent = text;
}

function setLoading(isLoading) {
  if (! submitButton) {
    return;
  }

  submitButton.disabled = isLoading;

  submitButton.textContent = isLoading
    ? 'Activando…'
    : 'Activar mi cuenta';
}

function setMessage(text, type = '') {
  if (! message) {
    return;
  }

  message.textContent = text;
  message.dataset.type = type;
}

async function showSuccess() {
  if (! window.Swal) {
    window.alert(
      'Tu cuenta quedó activa.'
    );

    return;
  }

  await window.Swal.fire({
    icon: 'success',
    title: 'Cuenta activada',
    text:
      'Tu contraseña fue creada correctamente. Ya puedes utilizar EU Tools.',
    confirmButtonText:
      'Entrar a EU Tools',
    allowOutsideClick: false,
    allowEscapeKey: false,
    customClass: {
      confirmButton:
        'app-alert__confirm',
    },
  });
}