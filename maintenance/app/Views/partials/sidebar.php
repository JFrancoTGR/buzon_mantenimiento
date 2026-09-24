<aside class="sidebar" id="app-sidebar" aria-label="Navegación principal">
  <div class="sidebar__brand">
    <span class="sidebar__mark" aria-hidden="true">M</span>

    <span class="sidebar__brand-text">
      <strong>Mantenimiento</strong>
      <span>Estrategia Urbana</span>
    </span>

    <button
      class="sidebar-collapse-toggle"
      type="button"
      data-sidebar-collapse
      aria-controls="app-sidebar"
      aria-expanded="true"
      aria-label="Colapsar barra lateral"
      title="Colapsar barra lateral"
    >
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
    </button>
  </div>

  <nav class="sidebar__nav">
    <section class="nav-group">
      <p class="nav-group__label">Inicio</p>

      <a
        class="nav-link"
        href="/maintenance/dashboard.html"
        <?php if ($activeNav === 'dashboard'): ?>
          aria-current="page"
        <?php endif; ?>
      >
        <svg aria-hidden="true">
          <use href="#icon-dashboard"></use>
        </svg>
        <span>Dashboard</span>
      </a>
    </section>

    <?php if ($showManagement): ?>
      <section class="nav-group" data-nav-group>
        <p class="nav-group__label">Gestión</p>

        <?php if ($canViewTickets): ?>
          <a
            class="nav-link"
            href="/maintenance/tickets.html"
            <?php if ($activeNav === 'tickets'): ?>
              aria-current="page"
            <?php endif; ?>
          >
            <svg aria-hidden="true">
              <use href="#icon-tickets"></use>
            </svg>
            <span>Tickets</span>
          </a>
        <?php endif; ?>

        <?php if ($canCreateTicket): ?>
          <a
            class="nav-link"
            href="/maintenance/new-ticket.html"
            <?php if ($activeNav === 'new-ticket'): ?>
              aria-current="page"
            <?php endif; ?>
          >
            <svg aria-hidden="true">
              <use href="#icon-plus-square"></use>
            </svg>
            <span>Nuevo reporte</span>
          </a>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($showAdministration): ?>
      <section class="nav-group" data-nav-group>
        <p class="nav-group__label">Administración</p>

        <?php if ($canManageCatalogs): ?>
          <a
            class="nav-link"
            href="#"
            data-coming-soon
          >
            <svg aria-hidden="true">
              <use href="#icon-catalog"></use>
            </svg>
            <span>Catálogos</span>
          </a>
        <?php endif; ?>

        <?php if ($canViewAudit): ?>
          <a
            class="nav-link"
            href="#"
            data-coming-soon
          >
            <svg aria-hidden="true">
              <use href="#icon-audit"></use>
            </svg>
            <span>Auditoría</span>
          </a>
        <?php endif; ?>
      </section>
    <?php endif; ?>
  </nav>

  <div class="sidebar__footer">
    <strong>Plataforma Corporativa EU Tools</strong>
    <span>v0.0.1</span>
  </div>
</aside>
