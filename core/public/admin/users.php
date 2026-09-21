<?php

declare(strict_types=1);

use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 2) . '/bootstrap/app.php';

header('Cache-Control: no-store, private');

$user = $services['webAuth']->requireUser('/login');

if (!AuthorizationService::hasPermission(
    $user,
    'core',
    'user.manage'
)) {
    header('Location: /', true, 302);
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$firstName = (string) ($user['first_name'] ?? '');
$lastName = (string) ($user['last_name'] ?? '');
$fullName = trim((string) ($user['full_name'] ?? ''));

if ($fullName === '') {
    $fullName = trim($firstName . ' ' . $lastName);
}

if ($fullName === '') {
    $fullName = 'Usuario';
}

$email = (string) ($user['email'] ?? '');

$firstInitial = $firstName !== ''
    ? (
        function_exists('mb_substr')
            ? mb_substr($firstName, 0, 1)
            : substr($firstName, 0, 1)
    )
    : '';

$lastInitial = $lastName !== ''
    ? (
        function_exists('mb_substr')
            ? mb_substr($lastName, 0, 1)
            : substr($lastName, 0, 1)
    )
    : '';

$initials = strtoupper($firstInitial . $lastInitial);

if ($initials === '') {
    $initials = 'EU';
}
?>
<!doctype html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">

  <title>Usuarios | EU Tools</title>

  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.26.25/sweetalert2.min.css"
  >

  <link rel="stylesheet" href="/assets/css/app.css">
</head>

<body class="app-page">
  <div class="app-shell">

    <aside
      class="sidebar"
      id="app-sidebar"
      aria-label="Navegación principal"
    >
      <div class="sidebar__brand">
        <span class="sidebar__mark" aria-hidden="true">EU</span>

        <span class="sidebar__brand-text">
          <strong>EU Tools</strong>
          <span>Estrategia Urbana</span>
        </span>
      </div>

      <nav class="sidebar__nav">

        <section class="nav-group">
          <p class="nav-group__label">Inicio</p>

          <a class="nav-link" href="/">
            <svg aria-hidden="true">
              <use href="#icon-tools"></use>
            </svg>

            <span>Herramientas</span>
          </a>
        </section>

        <section class="nav-group">
          <p class="nav-group__label">Administración</p>

          <a
            class="nav-link"
            href="/admin/users"
            aria-current="page"
          >
            <svg aria-hidden="true">
              <use href="#icon-users"></use>
            </svg>

            <span>Usuarios</span>
          </a>

          <a class="nav-link" href="#" data-coming-soon>
            <svg aria-hidden="true">
              <use href="#icon-apps"></use>
            </svg>

            <span>Aplicaciones</span>
          </a>

          <a class="nav-link" href="#" data-coming-soon>
            <svg aria-hidden="true">
              <use href="#icon-audit"></use>
            </svg>

            <span>Auditoría</span>
          </a>
        </section>

      </nav>

      <div class="sidebar__footer">
        Plataforma corporativa EU Tools
      </div>
    </aside>

    <div
      class="sidebar-backdrop"
      data-sidebar-backdrop
      hidden
    ></div>

    <div class="app-workspace">

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
            EU Tools
          </span>
        </div>

        <div class="topbar__end">

          <div class="dropdown" data-user-dropdown>
            <button
              class="user-trigger"
              type="button"
              data-user-trigger
              aria-expanded="false"
            >
              <span class="user-avatar">
                <?php echo e($initials) ?>
              </span>

              <span class="user-trigger__text">
                <strong><?php echo e($fullName) ?></strong>
                <span><?php echo e($email) ?></span>
              </span>

              <svg width="16" height="16" aria-hidden="true">
                <use href="#icon-chevron-down"></use>
              </svg>
            </button>

            <div
              class="dropdown__panel"
              data-user-panel
              hidden
            >
              <div class="dropdown__header">
                <strong><?php echo e($fullName) ?></strong>
                <span><?php echo e($email) ?></span>
              </div>

              <div class="dropdown__menu">

                <button
                  class="dropdown__item"
                  type="button"
                  data-coming-soon
                >
                  <svg width="18" height="18" aria-hidden="true">
                    <use href="#icon-user"></use>
                  </svg>

                  Mi cuenta
                </button>

                <button
                  class="dropdown__item dropdown__item--danger"
                  type="button"
                  data-logout
                >
                  <svg width="18" height="18" aria-hidden="true">
                    <use href="#icon-logout"></use>
                  </svg>

                  Cerrar sesión
                </button>

              </div>
            </div>
          </div>

        </div>
      </header>

      <main class="main-content">
        <div class="main-content__inner">

          <section
            class="page-heading"
            aria-labelledby="users-title"
          >
            <div class="page-heading__copy">
              <p class="eyebrow">
                Administración
              </p>

              <h1 id="users-title">
                Usuarios
              </h1>

              <p>
                Consulta las identidades globales y sus accesos
                a las aplicaciones de EU Tools.
              </p>
            </div>
          </section>

          <section
            class="panel users-toolbar"
            aria-label="Filtros de usuarios"
          >
            <div class="users-filters">

              <label class="users-field users-field--search">
                <span>Buscar</span>

                <input
                  type="search"
                  data-filter-search
                  placeholder="Nombre o correo"
                  maxlength="190"
                >
              </label>

              <label class="users-field">
                <span>Aplicación</span>

                <select data-filter-application>
                  <option value="">Todas</option>
                </select>
              </label>

              <label class="users-field">
                <span>Estado</span>

                <select data-filter-status>
                  <option value="">Todos</option>
                </select>
              </label>

              <button
                class="button button--secondary users-filter-button"
                type="button"
                data-apply-filters
              >
                Aplicar
              </button>

            </div>
          </section>

          <section class="panel">

            <header class="panel__header users-panel-header">
              <div>
                <h2>Directorio de usuarios</h2>

                <p data-users-summary>
                  Cargando usuarios…
                </p>
              </div>
            </header>

            <p
              class="users-error"
              data-users-error
              role="alert"
              hidden
            ></p>

            <div class="users-table-scroll">
              <table class="users-table">

                <thead>
                  <tr>
                    <th>Usuario</th>
                    <th>Estado</th>
                    <th>Aplicaciones y roles</th>
                    <th>Último acceso</th>
                    <th>Creado</th>
                  </tr>
                </thead>

                <tbody data-users-table>
                  <tr>
                    <td colspan="5" class="users-table__muted">
                      Cargando usuarios…
                    </td>
                  </tr>
                </tbody>

              </table>
            </div>

            <footer
              class="users-pagination"
              data-pagination
              hidden
            >
              <button
                class="button button--secondary"
                type="button"
                data-page-prev
              >
                Anterior
              </button>

              <span data-page-label></span>

              <button
                class="button button--secondary"
                type="button"
                data-page-next
              >
                Siguiente
              </button>
            </footer>

          </section>

        </div>
      </main>

    </div>
  </div>

  <svg
    width="0"
    height="0"
    aria-hidden="true"
    focusable="false"
    style="position:absolute"
  >

    <symbol id="icon-tools" viewBox="0 0 24 24">
      <path
        d="M4 4h7v7H4V4Zm2 2v3h3V6H6Zm7-2h7v7h-7V4Zm2 2v3h3V6h-3ZM4 13h7v7H4v-7Zm2 2v3h3v-3H6Zm7-2h7v7h-7v-7Zm2 2v3h3v-3h-3Z"
        fill="currentColor"
      />
    </symbol>

    <symbol id="icon-users" viewBox="0 0 24 24">
      <path
        d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm6.5-1a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM2 20v-2c0-3.3 2.7-6 6-6h2c3.3 0 6 2.7 6 6v2H2Zm15.5 0v-2c0-2-.7-3.9-2-5.3.6-.4 1.3-.7 2-.7 2.5 0 4.5 2 4.5 4.5V20h-4.5Z"
        fill="currentColor"
      />
    </symbol>

    <symbol id="icon-apps" viewBox="0 0 24 24">
      <path
        d="M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm9 0h7v7h-7v-7Z"
        fill="currentColor"
      />
    </symbol>

    <symbol id="icon-audit" viewBox="0 0 24 24">
      <path
        d="M5 3h14v18H5V3Zm2 2v14h10V5H7Zm2 3h6v2H9V8Zm0 4h6v2H9v-2Zm0 4h4v2H9v-2Z"
        fill="currentColor"
      />
    </symbol>

    <symbol id="icon-menu" viewBox="0 0 24 24">
      <path
        d="M3 6h18v2H3V6Zm0 5h18v2H3v-2Zm0 5h18v2H3v-2Z"
        fill="currentColor"
      />
    </symbol>

    <symbol id="icon-chevron-down" viewBox="0 0 24 24">
      <path
        d="m7 9 5 5 5-5H7Z"
        fill="currentColor"
      />
    </symbol>

    <symbol id="icon-user" viewBox="0 0 24 24">
      <path
        d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-5 0-9 2.5-9 6v2h18v-2c0-3.5-4-6-9-6Z"
        fill="currentColor"
      />
    </symbol>

    <symbol id="icon-logout" viewBox="0 0 24 24">
      <path
        d="M4 3h9v2H6v14h7v2H4V3Zm13.6 4.6L22 12l-4.4 4.4-1.4-1.4 2-2H10v-2h8.2l-2-2 1.4-1.4Z"
        fill="currentColor"
      />
    </symbol>

  </svg>

  <script
    src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.26.25/sweetalert2.all.min.js"
  ></script>

  <script
    type="module"
    src="/assets/js/modules/users.js"
  ></script>
</body>

</html>