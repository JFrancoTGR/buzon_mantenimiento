<header class="topbar">
  <div class="topbar__start">
    <button
      class="icon-button topbar__menu-button"
      type="button"
      data-sidebar-toggle
      aria-controls="app-sidebar"
      aria-expanded="false"
      aria-label="Abrir menú"
    >
      <svg aria-hidden="true">
        <use href="#icon-menu"></use>
      </svg>
    </button>

    <span class="topbar__title">
      Plataforma de Mantenimiento
    </span>
  </div>

  <div class="topbar__end">
    <a
      class="my-apps-link"
      href="/"
      data-apps-home
      aria-label="Mis Apps"
      title="Mis Apps"
    >
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

      <span class="my-apps-link__label">
        Mis Apps
      </span>
    </a>

    <div
      class="dropdown"
      data-notifications-dropdown
    >
      <button
        class="icon-button"
        type="button"
        data-notifications-trigger
        aria-expanded="false"
        aria-label="Notificaciones"
      >
        <svg aria-hidden="true">
          <use href="#icon-bell"></use>
        </svg>

        <span
          class="notification-count"
          data-notification-count
          hidden
        >0</span>
      </button>

      <div
        class="dropdown__panel"
        data-notifications-panel
        hidden
      >
        <div class="dropdown__header">
          <strong>Notificaciones</strong>
          <span data-notification-summary>
            Sin notificaciones pendientes
          </span>
        </div>

        <div
          class="notification-list"
          data-notification-list
        ></div>
      </div>
    </div>

    <div
      class="dropdown"
      data-user-dropdown
    >
      <button
        class="user-trigger"
        type="button"
        data-user-trigger
        aria-expanded="false"
      >
        <span
          class="user-avatar"
          data-user-initials
        ><?php echo maintenanceE($initials); ?></span>

        <span class="user-trigger__text">
          <strong data-user-name><?php
            echo maintenanceE($fullName);
          ?></strong>

          <span data-user-email><?php
            echo maintenanceE($email);
          ?></span>
        </span>

        <svg
          width="16"
          height="16"
          aria-hidden="true"
        >
          <use href="#icon-chevron-down"></use>
        </svg>
      </button>

      <div
        class="dropdown__panel"
        data-user-panel
        hidden
      >
        <div class="dropdown__header">
          <strong data-menu-user-name><?php
            echo maintenanceE($fullName);
          ?></strong>

          <span data-menu-user-email><?php
            echo maintenanceE($email);
          ?></span>
        </div>

        <div class="theme-preference">
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
        </div>

        <div class="dropdown__menu">
          <button
            class="dropdown__item"
            type="button"
            data-account-profile
          >
            <svg
              width="18"
              height="18"
              aria-hidden="true"
            >
              <use href="#icon-user"></use>
            </svg>
            Mi perfil
          </button>

          <button
            class="dropdown__item"
            type="button"
            data-account-security
          >
            <svg
              width="18"
              height="18"
              aria-hidden="true"
            >
              <use href="#icon-lock"></use>
            </svg>
            Cambiar contraseña
          </button>

          <button
            class="dropdown__item dropdown__item--danger"
            type="button"
            data-logout
          >
            <svg
              width="18"
              height="18"
              aria-hidden="true"
            >
              <use href="#icon-logout"></use>
            </svg>
            Cerrar sesión
          </button>
        </div>
      </div>
    </div>
  </div>
</header>
