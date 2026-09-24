<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <script src="./assets/js/components/sidebar-preload.js"></script>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <title>Dashboard | Plataforma de Mantenimiento</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.26.25/sweetalert2.min.css">
  <link rel="stylesheet" href="./assets/css/app.css">
</head>
<body class="app-page">
  <div class="app-shell">
        <?php require dirname(__DIR__) . '/partials/sidebar.php'; ?>

    <div class="sidebar-backdrop" data-sidebar-backdrop hidden></div>

    <div class="app-workspace">
            <?php require dirname(__DIR__) . '/partials/topbar.php'; ?>

      <main class="main-content">
        <div class="main-content__inner">
          <section class="page-heading" aria-labelledby="dashboard-title">
            <div class="page-heading__copy">
              <p class="eyebrow">Resumen operativo</p>
              <h1 id="dashboard-title">Dashboard</h1>
              <p>Consulta el estado general de los reportes y las acciones que requieren tu atención.</p>
            </div>

            <?php if ($canCreateTicket): ?>
              <a
                class="button button--primary"
                href="/maintenance/new-ticket.html"
              >
                <svg aria-hidden="true">
                  <use href="#icon-plus"></use>
                </svg>
                Nuevo reporte
              </a>
            <?php endif; ?>
          </section>

          <p class="dashboard-error" data-dashboard-error hidden></p>

          <section class="metrics-grid" aria-label="Resumen de tickets">
            <article class="metric-card" data-status="new">
              <p class="metric-card__label">Tickets nuevos</p>
              <p class="metric-card__value loading-line" data-counter="new" aria-label="Cargando"></p>
              <p class="metric-card__meta">Pendientes de revisión</p>
            </article>

            <article class="metric-card" data-status="under_review">
              <p class="metric-card__label">En revisión</p>
              <p class="metric-card__value loading-line" data-counter="under_review" aria-label="Cargando"></p>
              <p class="metric-card__meta">Seguimiento del supervisor</p>
            </article>

            <article class="metric-card" data-status="authorization_pending">
              <p class="metric-card__label">Por autorizar</p>
              <p class="metric-card__value loading-line" data-counter="authorization_pending" aria-label="Cargando"></p>
              <p class="metric-card__meta">Pendientes de Dirección</p>
            </article>

            <article class="metric-card" data-status="in_progress">
              <p class="metric-card__label">En proceso</p>
              <p class="metric-card__value loading-line" data-counter="in_progress" aria-label="Cargando"></p>
              <p class="metric-card__meta">Trabajos en ejecución</p>
            </article>

            <article class="metric-card" data-status="closed">
              <p class="metric-card__label">Terminados</p>
              <p class="metric-card__value loading-line" data-counter="closed" aria-label="Cargando"></p>
              <p class="metric-card__meta">Tickets cerrados</p>
            </article>
          </section>

          <section class="dashboard-grid">
            <article class="panel">
              <header class="panel__header">
                <div>
                  <h2>Acciones pendientes</h2>
                  <p>Reportes activos dentro de tu alcance.</p>
                </div>
                <?php if ($canViewTickets): ?>
                  <a
                    class="button button--text"
                    href="/maintenance/tickets.html"
                  >Ver todos</a>
                <?php endif; ?>
              </header>

              <div data-pending-content>
                <div class="table-scroll">
                  <table class="data-table">
                    <thead>
                      <tr>
                        <th>Folio</th>
                        <th>Reporte</th>
                        <th>Ubicación</th>
                        <th>Prioridad</th>
                        <th>Estado</th>
                        <th>Actualización</th>
                        <th>Acción</th>
                      </tr>
                    </thead>
                    <tbody data-pending-table>
                      <tr>
                        <td colspan="7" class="data-table__muted">Cargando reportes…</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </article>

            <article class="panel">
              <header class="panel__header">
                <div>
                  <h2>Actividad reciente</h2>
                  <p>Últimos cambios registrados.</p>
                </div>
              </header>
              <div class="panel__body" data-activity-content>
                <p class="text-secondary">Cargando actividad…</p>
              </div>
            </article>
          </section>
        </div>
      </main>
    </div>
  </div>

  <svg width="0" height="0" aria-hidden="true" focusable="false" style="position:absolute">
    <symbol id="icon-dashboard" viewBox="0 0 24 24"><path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6v-9h-6v9Zm0-16v5h6V4h-6Z" fill="currentColor"/></symbol>
    <symbol id="icon-tickets" viewBox="0 0 24 24"><path d="M5 3h11l3 3v15H5V3Zm10 1.8V7h2.2L15 4.8ZM7 9v2h10V9H7Zm0 4v2h10v-2H7Zm0 4v2h7v-2H7Z" fill="currentColor"/></symbol>
    <symbol id="icon-plus-square" viewBox="0 0 24 24"><path d="M4 4h16v16H4V4Zm2 2v12h12V6H6Zm5 2h2v3h3v2h-3v3h-2v-3H8v-2h3V8Z" fill="currentColor"/></symbol>
    <symbol id="icon-users" viewBox="0 0 24 24"><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6.5-1a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM2 20v-2c0-3.3 2.7-6 6-6h2c3.3 0 6 2.7 6 6v2H2Zm15.5 0v-2c0-2-.7-3.9-2-5.3.6-.4 1.3-.7 2-.7 2.5 0 4.5 2 4.5 4.5V20h-4.5Z" fill="currentColor"/></symbol>
    <symbol id="icon-catalog" viewBox="0 0 24 24"><path d="M4 4h7v7H4V4Zm2 2v3h3V6H6Zm7-2h7v7h-7V4Zm2 2v3h3V6h-3ZM4 13h7v7H4v-7Zm2 2v3h3v-3H6Zm7-2h7v7h-7v-7Zm2 2v3h3v-3h-3Z" fill="currentColor"/></symbol>
    <symbol id="icon-audit" viewBox="0 0 24 24"><path d="M5 3h14v18H5V3Zm2 2v14h10V5H7Zm2 3h6v2H9V8Zm0 4h6v2H9v-2Zm0 4h4v2H9v-2Z" fill="currentColor"/></symbol>
    <symbol id="icon-menu" viewBox="0 0 24 24"><path d="M3 6h18v2H3V6Zm0 5h18v2H3v-2Zm0 5h18v2H3v-2Z" fill="currentColor"/></symbol>
    <symbol id="icon-bell" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 0 0 2.45-2h-4.9A2.5 2.5 0 0 0 12 22Zm7-5-2-2v-4a5 5 0 0 0-4-4.9V5a1 1 0 0 0-2 0v1.1A5 5 0 0 0 7 11v4l-2 2v1h14v-1Z" fill="currentColor"/></symbol>
    <symbol id="icon-chevron-down" viewBox="0 0 24 24"><path d="m7 9 5 5 5-5H7Z" fill="currentColor"/></symbol>
    <symbol id="icon-user" viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-5 0-9 2.5-9 6v2h18v-2c0-3.5-4-6-9-6Z" fill="currentColor"/></symbol>
    <symbol id="icon-lock" viewBox="0 0 24 24"><path d="M6 10V7a6 6 0 0 1 12 0v3h2v12H4V10h2Zm2 0h8V7a4 4 0 0 0-8 0v3Zm4 3a2 2 0 0 0-1 3.73V19h2v-2.27A2 2 0 0 0 12 13Z" fill="currentColor"/></symbol>
    <symbol id="icon-logout" viewBox="0 0 24 24"><path d="M4 3h9v2H6v14h7v2H4V3Zm13.6 4.6L22 12l-4.4 4.4-1.4-1.4 2-2H10v-2h8.2l-2-2 1.4-1.4Z" fill="currentColor"/></symbol>
    <symbol id="icon-plus" viewBox="0 0 24 24"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z" fill="currentColor"/></symbol>
    <symbol id="icon-empty" viewBox="0 0 24 24"><path d="M5 4h14v16H5V4Zm2 2v12h10V6H7Zm2 3h6v2H9V9Zm0 4h6v2H9v-2Z" fill="currentColor"/></symbol>
  </svg>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.26.25/sweetalert2.all.min.js"></script>
  <script type="module" src="./assets/js/modules/dashboard.js"></script>
</body>
</html>
