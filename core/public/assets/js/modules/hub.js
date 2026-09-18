import { apiRequest } from '../core/api.js';
import { setupDropdown } from '../components/dropdown.js';
import { setupSidebar } from '../components/sidebar.js';

setupSidebar();
setupUserMenu();
setupPreviewCards();

function setupUserMenu() {
  const container = document.querySelector('[data-user-dropdown]');
  const trigger = document.querySelector('[data-user-trigger]');
  const panel = document.querySelector('[data-user-panel]');
  const logoutButton = document.querySelector('[data-logout]');

  const dropdown = setupDropdown({
    container,
    trigger,
    panel,
  });

  logoutButton?.addEventListener('click', async () => {
    logoutButton.disabled = true;

    try {
      await apiRequest('/api/auth/logout', {
        method: 'POST',
        body: JSON.stringify({}),
      });

      window.location.replace('/login');
    } catch (error) {
      logoutButton.disabled = false;

      window.alert(
        error.message || 'No fue posible cerrar la sesión.'
      );
    } finally {
      dropdown.close();
    }
  });
}

function setupPreviewCards() {
  document.querySelectorAll('[data-preview-tool]').forEach((element) => {
    element.addEventListener('click', (event) => {
      event.preventDefault();

      window.alert(
        'Esta herramienta todavía se encuentra en proceso de integración con EU Tools.'
      );
    });
  });

  document.querySelectorAll('[data-coming-soon]').forEach((element) => {
    element.addEventListener('click', (event) => {
      event.preventDefault();

      window.alert(
        'Esta herramienta forma parte del roadmap de EU Tools.'
      );
    });
  });
}