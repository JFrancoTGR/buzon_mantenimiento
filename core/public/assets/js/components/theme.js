const THEME_STORAGE_KEY = 'euTools.theme';

export function setupThemeControl() {
  const userPanel = document.querySelector(
    '[data-user-panel]',
  );

  if (!userPanel) return;

  let toggle = userPanel.querySelector(
    '[data-theme-toggle]',
  );

  if (!toggle) {
    const preference = createThemePreference();

    const header = userPanel.querySelector(
      '.dropdown__header',
    );

    if (header) {
      header.insertAdjacentElement(
        'afterend',
        preference,
      );
    } else {
      userPanel.prepend(preference);
    }

    toggle = preference.querySelector(
      '[data-theme-toggle]',
    );
  }

  if (!toggle) return;

  const syncTheme = (theme) => {
    const normalized =
      theme === 'dark'
        ? 'dark'
        : 'light';

    document.documentElement.dataset.theme =
      normalized;

    const isDark = normalized === 'dark';

    toggle.setAttribute(
      'aria-checked',
      isDark ? 'true' : 'false',
    );

    const label = isDark
      ? 'Cambiar a tema claro'
      : 'Cambiar a tema oscuro';

    toggle.setAttribute('aria-label', label);
    toggle.title = label;
  };

  const readTheme = () => {
    try {
      return window.localStorage.getItem(
        THEME_STORAGE_KEY,
      ) === 'dark'
        ? 'dark'
        : 'light';
    } catch {
      return 'light';
    }
  };

  const writeTheme = (theme) => {
    try {
      window.localStorage.setItem(
        THEME_STORAGE_KEY,
        theme,
      );
    } catch {
      // La preferencia continúa funcionando
      // durante la sesión aunque localStorage falle.
    }
  };

  syncTheme(readTheme());

  toggle.addEventListener('click', () => {
    const current =
      document.documentElement.dataset.theme;

    const next =
      current === 'dark'
        ? 'light'
        : 'dark';

    writeTheme(next);
    syncTheme(next);
  });

  window.addEventListener('storage', (event) => {
    if (event.key !== THEME_STORAGE_KEY) return;

    syncTheme(
      event.newValue === 'dark'
        ? 'dark'
        : 'light',
    );
  });
}

function createThemePreference() {
  const preference =
    document.createElement('div');

  preference.className = 'theme-preference';

  preference.innerHTML = `
    <span class="theme-preference__label">
      Tema
    </span>

    <div class="theme-control">
      <svg
        class="theme-icon theme-icon--sun"
        viewBox="0 0 24 24"
        aria-hidden="true"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
      >
        <circle cx="12" cy="12" r="4"></circle>
        <path d="M12 2v2"></path>
        <path d="M12 20v2"></path>
        <path d="m4.93 4.93 1.42 1.42"></path>
        <path d="m17.65 17.65 1.42 1.42"></path>
        <path d="M2 12h2"></path>
        <path d="M20 12h2"></path>
        <path d="m6.35 17.65-1.42 1.42"></path>
        <path d="m19.07 4.93-1.42 1.42"></path>
      </svg>

      <button
        class="theme-switch"
        type="button"
        role="switch"
        aria-checked="false"
        data-theme-toggle
        aria-label="Cambiar a tema oscuro"
        title="Cambiar a tema oscuro"
      >
        <span
          class="theme-switch__thumb"
          aria-hidden="true"
        ></span>
      </button>

      <svg
        class="theme-icon theme-icon--moon"
        viewBox="0 0 24 24"
        aria-hidden="true"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
      >
        <path
          d="M21 12.8A8.5 8.5 0 1 1 11.2 3
             6.5 6.5 0 0 0 21 12.8Z"
        ></path>
      </svg>
    </div>
  `;

  return preference;
}
