import { apiRequest } from '../core/api.js';
import { setupDropdown } from './dropdown.js';
import { setupPasswordVisibility } from './passwordVisibility.js';

export function setupUserMenu(user) {
  setupPasswordVisibility();
  const container = document.querySelector('[data-user-dropdown]');
  const trigger = document.querySelector('[data-user-trigger]');
  const panel = document.querySelector('[data-user-panel]');
  const logoutButton = document.querySelector('[data-logout]');

  const fullName = user.full_name || [user.first_name, user.last_name].filter(Boolean).join(' ') || 'Usuario';
  const initials = [user.first_name, user.last_name]
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => String(part).charAt(0).toUpperCase())
    .join('') || 'US';

  document.querySelectorAll('[data-user-name], [data-menu-user-name]').forEach((element) => {
    element.textContent = fullName;
  });
  document.querySelectorAll('[data-user-email], [data-menu-user-email]').forEach((element) => {
    element.textContent = user.email || '';
  });

  const initialsElement = document.querySelector('[data-user-initials]');
  if (initialsElement) initialsElement.textContent = initials;

  const dropdown = setupDropdown({ container, trigger, panel });
  configureAccountNavigation(panel, dropdown);

  logoutButton?.addEventListener('click', async () => {
    logoutButton.disabled = true;
    try {
      await apiRequest('./api/auth/logout.php', {
        method: 'POST',
        body: JSON.stringify({}),
      });
    } catch {
      // La redirección también limpia el estado visual si el servidor no responde.
    } finally {
      dropdown.close();
      window.location.replace('./login.html');
    }
  });
}

function configureAccountNavigation(panel, dropdown) {
  if (!panel) return;

  const items = Array.from(panel.querySelectorAll('.dropdown__item'));
  const profileItem = panel.querySelector('[data-account-profile]')
    || items.find((item) => ['mi cuenta', 'mi perfil'].includes(normalizeLabel(item.textContent)));
  const securityItem = panel.querySelector('[data-account-security]')
    || items.find((item) => normalizeLabel(item.textContent) === 'cambiar contraseña');

  configureNavigationItem(profileItem, './profile.html', 'Mi perfil', dropdown);
  configureNavigationItem(securityItem, './profile.html#security', 'Cambiar contraseña', dropdown);
}

function configureNavigationItem(element, href, label, dropdown) {
  if (!element) return;

  element.removeAttribute('data-coming-soon');
  replaceTextLabel(element, label);

  if (element.tagName === 'A') {
    element.setAttribute('href', href);
    element.addEventListener('click', () => dropdown.close());
    return;
  }

  element.addEventListener('click', () => {
    dropdown.close();
    window.location.assign(href);
  });
}

function replaceTextLabel(element, label) {
  const textNodes = Array.from(element.childNodes)
    .filter((node) => node.nodeType === Node.TEXT_NODE);
  const labelNode = textNodes.find((node) => String(node.textContent || '').trim() !== '');

  if (labelNode) {
    labelNode.textContent = label;
    return;
  }

  const span = element.querySelector('span');
  if (span) span.textContent = label;
}

function normalizeLabel(value) {
  return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
}
