const SIDEBAR_STORAGE_KEY = 'euTools.sidebarCollapsed';
const MOBILE_MEDIA = '(max-width: 1024px)';
const DESKTOP_MEDIA = '(min-width: 1025px)';

export function setupSidebar() {
  const toggle = document.querySelector('[data-sidebar-toggle]');
  const backdrop = document.querySelector('[data-sidebar-backdrop]');
  const sidebar = document.querySelector('.sidebar');

  if (!toggle || !backdrop || !sidebar) return;

  const mobileMedia = window.matchMedia(MOBILE_MEDIA);
  const desktopMedia = window.matchMedia(DESKTOP_MEDIA);

  prepareNavigationLabels(sidebar);
  setupPlatformActions();

  const collapseToggle = createCollapseToggle(sidebar);

  const readCollapsedPreference = () => {
    try {
      return window.localStorage.getItem(SIDEBAR_STORAGE_KEY) === '1';
    } catch {
      return false;
    }
  };

  const writeCollapsedPreference = (collapsed) => {
    try {
      window.localStorage.setItem(
        SIDEBAR_STORAGE_KEY,
        collapsed ? '1' : '0',
      );
    } catch {
      // localStorage puede estar deshabilitado sin afectar navegación.
    }
  };

  const syncCollapsedState = () => {
    const collapsed =
      desktopMedia.matches && readCollapsedPreference();

    document.body.classList.toggle(
      'sidebar-collapsed',
      collapsed,
    );

    collapseToggle.setAttribute(
      'aria-expanded',
      collapsed ? 'false' : 'true',
    );

    const label = collapsed
      ? 'Expandir barra lateral'
      : 'Colapsar barra lateral';

    collapseToggle.setAttribute('aria-label', label);
    collapseToggle.title = label;
  };

  const close = () => {
    document.body.classList.remove('sidebar-open');
    backdrop.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
  };

  const open = () => {
    document.body.classList.add('sidebar-open');
    backdrop.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
  };

  toggle.addEventListener('click', () => {
    document.body.classList.contains('sidebar-open')
      ? close()
      : open();
  });

  collapseToggle.addEventListener('click', () => {
    if (!desktopMedia.matches) return;

    const collapsed =
      !document.body.classList.contains('sidebar-collapsed');

    writeCollapsedPreference(collapsed);
    syncCollapsedState();
  });

  backdrop.addEventListener('click', close);

  sidebar.addEventListener('click', (event) => {
    if (
      event.target.closest('a')
      && mobileMedia.matches
    ) {
      close();
    }
  });

  window.addEventListener('resize', () => {
    close();
    syncCollapsedState();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') close();
  });

  syncCollapsedState();
}

function createCollapseToggle(sidebar) {
  const existing = sidebar.querySelector(
    '[data-sidebar-collapse]',
  );

  if (existing) return existing;

  const brand = sidebar.querySelector('.sidebar__brand');
  const button = document.createElement('button');

  button.type = 'button';
  button.className = 'sidebar-collapse-toggle';
  button.dataset.sidebarCollapse = '';
  button.setAttribute('aria-controls', 'app-sidebar');
  button.setAttribute('aria-expanded', 'true');
  button.setAttribute(
    'aria-label',
    'Colapsar barra lateral',
  );

  button.innerHTML = `
    <svg
      viewBox="0 0 24 24"
      aria-hidden="true"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <path d="m15 18-6-6 6-6"></path>
    </svg>
  `;

  brand?.append(button);

  return button;
}

function prepareNavigationLabels(sidebar) {
  sidebar.querySelectorAll('.nav-link').forEach((link) => {
    const label = link
      .querySelector('span')
      ?.textContent
      ?.trim();

    if (!label) return;

    if (!link.hasAttribute('aria-label')) {
      link.setAttribute('aria-label', label);
    }

    if (!link.hasAttribute('title')) {
      link.setAttribute('title', label);
    }
  });
}

function setupPlatformActions() {
  setupAppsHomeAction();
  setupThemePlaceholder();
}

function setupAppsHomeAction() {
  const topbarEnd = document.querySelector('.topbar__end');
  const userDropdown = topbarEnd?.querySelector(
    '[data-user-dropdown]',
  );

  if (!topbarEnd || !userDropdown) return;

  const isHub = Boolean(
    document.querySelector(
      '.sidebar .nav-link[href="/"][aria-current="page"]',
    ),
  );

  if (
    isHub
    || topbarEnd.querySelector('[data-apps-home]')
  ) {
    return;
  }

  const link = document.createElement('a');

  link.href = '/';
  link.className = 'my-apps-link';
  link.dataset.appsHome = '';
  link.setAttribute('aria-label', 'Mis Apps');
  link.title = 'Mis Apps';

  link.innerHTML = `
    <svg
      viewBox="0 0 24 24"
      aria-hidden="true"
      fill="none"
      stroke="currentColor"
      stroke-width="1.9"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <rect x="3" y="3" width="7" height="7"></rect>
      <rect x="14" y="3" width="7" height="7"></rect>
      <rect x="3" y="14" width="7" height="7"></rect>
      <rect x="14" y="14" width="7" height="7"></rect>
    </svg>
    <span class="my-apps-link__label">Mis Apps</span>
  `;

  const notificationsDropdown = topbarEnd.querySelector(
    '[data-notifications-dropdown]',
  );

  const insertionTarget =
    notificationsDropdown || userDropdown;

  insertionTarget.insertAdjacentElement(
    'beforebegin',
    link,
  );
}

function setupThemePlaceholder() {
  const userPanel = document.querySelector(
    '[data-user-panel]',
  );

  if (
    !userPanel
    || userPanel.querySelector('[data-theme-toggle]')
  ) {
    return;
  }

  const preference = document.createElement('div');
  preference.className = 'theme-preference';

  preference.innerHTML = `
    <button
      class="theme-preference__button"
      type="button"
      data-theme-toggle
      disabled
      aria-disabled="true"
      title="Cambio de tema próximamente"
    >
      <span class="theme-preference__copy">
        <strong>Tema oscuro</strong>
        <small>Próximamente</small>
      </span>

      <span
        class="theme-switch"
        aria-hidden="true"
      ></span>
    </button>
  `;

  const header = userPanel.querySelector('.dropdown__header');

  if (header) {
    header.insertAdjacentElement(
      'afterend',
      preference,
    );
  } else {
    userPanel.prepend(preference);
  }
}