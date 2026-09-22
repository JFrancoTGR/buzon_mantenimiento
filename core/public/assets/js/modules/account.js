import {
  apiRequest,
} from '../core/api.js';

import {
  setupDropdown,
} from '../components/dropdown.js';

import {
  setupSidebar,
} from '../components/sidebar.js';

import {
  setupPasswordVisibility,
} from '../components/passwordVisibility.js';

import {
  formatDateTime,
} from '../utils/date.js';

setupSidebar();
setupPasswordVisibility();
setupUserMenu();

const profileForm =
  document.querySelector('[data-profile-form]');

const profileMessage =
  document.querySelector('[data-profile-message]');

const profileSubmit =
  document.querySelector('[data-profile-submit]');

const passwordForm =
  document.querySelector('[data-password-form]');

const passwordMessage =
  document.querySelector('[data-password-message]');

const passwordSubmit =
  document.querySelector('[data-password-submit]');

initialize();

profileForm?.addEventListener(
  'submit',
  submitProfile
);

passwordForm?.addEventListener(
  'submit',
  submitPassword
);

async function initialize() {
  try {
    await loadProfile();
  } catch (error) {
    if (Number(error?.status) === 401) {
      window.location.replace('/login');
      return;
    }

    setMessage(
      profileMessage,
      error?.message
      || 'No fue posible cargar tu cuenta.',
      'error'
    );
  }
}

async function loadProfile() {
  const payload = await apiRequest(
    '/api/account/profile'
  );

  renderProfile(payload.data.profile);
}

async function submitProfile(event) {
  event.preventDefault();

  setMessage(profileMessage, '');

  if (!profileForm.reportValidity()) {
    return;
  }

  setProfileLoading(true);

  const values = Object.fromEntries(
    new FormData(profileForm).entries()
  );

  try {
    const payload = await apiRequest(
      '/api/account/update-profile',
      {
        method: 'POST',

        body: JSON.stringify({
          first_name: values.first_name || '',
          last_name: values.last_name || '',
        }),
      }
    );

    const profile = payload.data.profile;

    renderProfile(profile);
    syncHeaderIdentity(profile);

    await notify(
      payload.data.changed
        ? 'Perfil actualizado'
        : 'Sin cambios',

      payload.data.changed
        ? 'Tus datos globales de EU Tools se guardaron correctamente.'
        : 'Tu cuenta ya contiene esos datos.',

      payload.data.changed
        ? 'success'
        : 'info'
    );
  } catch (error) {
    if (Number(error?.status) === 401) {
      window.location.replace('/login');
      return;
    }

    setMessage(
      profileMessage,
      error?.message
      || 'No fue posible actualizar el perfil.',
      'error'
    );
  } finally {
    setProfileLoading(false);
  }
}

async function submitPassword(event) {
  event.preventDefault();

  setMessage(passwordMessage, '');

  if (!passwordForm.reportValidity()) {
    return;
  }

  setPasswordLoading(true);

  const values = Object.fromEntries(
    new FormData(passwordForm).entries()
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

    await notify(
      'Contraseña actualizada',
      'Por seguridad cerramos todas tus sesiones. Inicia sesión nuevamente.',
      'success',
      false
    );

    window.location.replace('/login');
  } catch (error) {
    if (
      Number(error?.status) === 401
      && error?.code !== 'current_password_invalid'
    ) {
      window.location.replace('/login');
      return;
    }

    setMessage(
      passwordMessage,
      error?.message
      || 'No fue posible actualizar la contraseña.',
      'error'
    );
  } finally {
    setPasswordLoading(false);
  }
}

function renderProfile(profile) {
  if (!profile || !profileForm) return;

  const firstName =
    profileForm.elements.namedItem('first_name');

  const lastName =
    profileForm.elements.namedItem('last_name');

  const email =
    profileForm.elements.namedItem('email');

  if (firstName) {
    firstName.value = profile.first_name || '';
  }

  if (lastName) {
    lastName.value = profile.last_name || '';
  }

  if (email) {
    email.value = profile.email || '';
  }

  setText(
    '[data-profile-status]',
    statusLabel(profile.status)
  );

  setText(
    '[data-profile-email-verified]',
    profile.email_verified_at
      ? `Verificado el ${formatDateTime(profile.email_verified_at)}`
      : 'Sin verificar'
  );

  setText(
    '[data-profile-last-login]',
    profile.last_login_at
      ? formatDateTime(profile.last_login_at)
      : 'Sin accesos registrados'
  );

  setText(
    '[data-profile-created]',
    profile.created_at
      ? formatDateTime(profile.created_at)
      : '—'
  );

  renderApplications(
    profile.applications || []
  );
}

function renderApplications(applications) {
  const container =
    document.querySelector(
      '[data-account-applications]'
    );

  if (!container) return;

  container.replaceChildren();

  if (!applications.length) {
    const empty = document.createElement('p');

    empty.className = 'account-access-empty';

    empty.textContent =
      'Tu cuenta no tiene aplicaciones asignadas actualmente.';

    container.append(empty);

    return;
  }

  applications.forEach((application) => {
    const item = document.createElement('div');

    item.className = 'account-access-item';

    const app = document.createElement('div');

    const name = document.createElement('strong');
    name.textContent =
      application.name
      || application.code
      || 'Aplicación';

    const code = document.createElement('span');
    code.textContent =
      application.code || '';

    app.append(name, code);

    const role = document.createElement('span');
    role.className = 'account-access-role';

    role.textContent =
      application.role?.name
      || application.role?.code
      || 'Sin rol';

    item.append(app, role);
    container.append(item);
  });
}

function syncHeaderIdentity(profile) {
  const fullName =
    profile.full_name
    || [
      profile.first_name,
      profile.last_name,
    ].filter(Boolean).join(' ')
    || 'Usuario';

  const initials =
    [
      profile.first_name,
      profile.last_name,
    ]
      .filter(Boolean)
      .slice(0, 2)
      .map((part) =>
        String(part)
          .charAt(0)
          .toUpperCase()
      )
      .join('')
    || 'EU';

  document
    .querySelectorAll(
      '[data-user-name], [data-menu-user-name]'
    )
    .forEach((element) => {
      element.textContent = fullName;
    });

  document
    .querySelectorAll(
      '[data-user-email], [data-menu-user-email]'
    )
    .forEach((element) => {
      element.textContent = profile.email || '';
    });

  const initialsElement =
    document.querySelector(
      '[data-user-initials]'
    );

  if (initialsElement) {
    initialsElement.textContent = initials;
  }
}

function setupUserMenu() {
  const container =
    document.querySelector('[data-user-dropdown]');

  const trigger =
    document.querySelector('[data-user-trigger]');

  const panel =
    document.querySelector('[data-user-panel]');

  const logoutButton =
    document.querySelector('[data-logout]');

  const dropdown = setupDropdown({
    container,
    trigger,
    panel,
  });

  logoutButton?.addEventListener(
    'click',
    async () => {
      logoutButton.disabled = true;

      try {
        await apiRequest(
          '/api/auth/logout',
          {
            method: 'POST',
            body: JSON.stringify({}),
          }
        );

        window.location.replace('/login');
      } catch (error) {
        logoutButton.disabled = false;

        await notify(
          'No fue posible cerrar sesión',
          error?.message
          || 'Intenta nuevamente.',
          'error'
        );
      } finally {
        dropdown.close();
      }
    }
  );
}

function setProfileLoading(isLoading) {
  if (!profileSubmit) return;

  profileSubmit.disabled = isLoading;

  profileSubmit.textContent = isLoading
    ? 'Guardando…'
    : 'Guardar cambios';
}

function setPasswordLoading(isLoading) {
  if (!passwordSubmit) return;

  passwordSubmit.disabled = isLoading;

  passwordSubmit.textContent = isLoading
    ? 'Actualizando…'
    : 'Cambiar contraseña';
}

function setMessage(
  element,
  text,
  type = ''
) {
  if (!element) return;

  element.textContent = text;
  element.dataset.type = type;
}

function setText(selector, value) {
  const element =
    document.querySelector(selector);

  if (element) {
    element.textContent = value;
  }
}

function statusLabel(status) {
  const labels = {
    active: 'Activo',
    inactive: 'Inactivo',
    blocked: 'Bloqueado',
    pending: 'Verificación pendiente',
    invited: 'Invitado',
  };

  return labels[status]
    || status
    || '—';
}

async function notify(
  title,
  text,
  icon = 'success',
  dismissible = true
) {
  if (!window.Swal) {
    window.alert(
      `${title}\n\n${text}`
    );

    return;
  }

  await window.Swal.fire({
    icon,
    title,
    text,
    confirmButtonText: 'Aceptar',
    allowOutsideClick: dismissible,
    allowEscapeKey: dismissible,
    customClass: {
      popup: 'app-alert',
      confirmButton: 'app-alert__confirm',
    },
    buttonsStyling: false,
  });
}