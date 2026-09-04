import { apiRequest } from '../core/api.js';
import { setupDropdown } from './dropdown.js';

export function setupUserMenu(user) {
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
