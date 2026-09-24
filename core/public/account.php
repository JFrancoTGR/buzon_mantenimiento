<?php

declare(strict_types=1);

$services = require dirname(__DIR__) . '/bootstrap/app.php';

header('Cache-Control: no-store, private');

$user = $services['webAuth']->requireUser('/login');

function e(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_QUOTES,
        'UTF-8'
    );
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

$initials = strtoupper(
    $firstInitial . $lastInitial
);

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

  <title>Mi cuenta | EU Tools</title>

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
        <span
          class="sidebar__mark"
          aria-hidden="true"
        >
          EU
        </span>

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

          <a
            class="nav-link"
            href="/account"
            aria-current="page"
          >
            <svg aria-hidden="true">
              <use href="#icon-user"></use>
            </svg>

            <span>Mi cuenta</span>
          </a>
        </section>
      </nav>

      <div class="sidebar__footer">
        <strong>Plataforma Corporativa EU Tools</strong>
        <span>v0.0.1</span>
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
            Mi cuenta
          </span>
        </div>

        <div class="topbar__end">
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
              >
                <?php echo e($initials); ?>
              </span>

              <span class="user-trigger__text">
                <strong data-user-name>
                  <?php echo e($fullName); ?>
                </strong>

                <span data-user-email>
                  <?php echo e($email); ?>
                </span>
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
                <strong data-menu-user-name>
                  <?php echo e($fullName); ?>
                </strong>

                <span data-menu-user-email>
                  <?php echo e($email); ?>
                </span>
              </div>

              <div class="dropdown__menu">
                <a
                  class="dropdown__item"
                  href="/account"
                >
                  <svg
                    width="18"
                    height="18"
                    aria-hidden="true"
                  >
                    <use href="#icon-user"></use>
                  </svg>

                  Mi cuenta
                </a>

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

      <main class="main-content">
        <div class="main-content__inner">
          <section
            class="page-heading"
            aria-labelledby="account-title"
          >
            <div class="page-heading__copy">
              <p class="eyebrow">
                Identidad global
              </p>

              <h1 id="account-title">
                Mi cuenta
              </h1>

              <p>
                Administra los datos y la seguridad de tu identidad de EU Tools.
              </p>
            </div>
          </section>

          <div class="account-grid">
            <section class="panel">
              <header class="panel__header">
                <div>
                  <h2>Datos personales</h2>

                  <p>
                    Estos datos se comparten entre las aplicaciones de EU Tools.
                  </p>
                </div>
              </header>

              <div class="panel__body">
                <form
                  class="form"
                  data-profile-form
                  novalidate
                >
                  <div class="form-grid form-grid--two">
                    <label class="form-field">
                      Nombre

                      <input
                        type="text"
                        name="first_name"
                        maxlength="80"
                        autocomplete="given-name"
                        required
                      >
                    </label>

                    <label class="form-field">
                      Apellidos

                      <input
                        type="text"
                        name="last_name"
                        maxlength="120"
                        autocomplete="family-name"
                        required
                      >
                    </label>
                  </div>

                  <label class="form-field">
                    Correo electrónico

                    <input
                      type="email"
                      name="email"
                      readonly
                      disabled
                    >
                  </label>

                  <button
                    class="button button--primary account-submit"
                    type="submit"
                    data-profile-submit
                  >
                    Guardar cambios
                  </button>
                </form>

                <p
                  class="message"
                  data-profile-message
                  role="alert"
                  aria-live="polite"
                ></p>
              </div>
            </section>

            <section class="panel">
              <header class="panel__header">
                <div>
                  <h2>Seguridad</h2>

                  <p>
                    Cambiar la contraseña cerrará todas las sesiones abiertas.
                  </p>
                </div>
              </header>

              <div class="panel__body">
                <form
                  class="form"
                  data-password-form
                  novalidate
                >
                  <label class="form-field">
                    Contraseña actual

                    <input
                      type="password"
                      name="current_password"
                      autocomplete="current-password"
                      required
                    >
                  </label>

                  <label class="form-field">
                    Nueva contraseña

                    <input
                      type="password"
                      name="new_password"
                      autocomplete="new-password"
                      minlength="12"
                      required
                    >

                    <span class="form-help">
                      Al menos 12 caracteres y tres tipos entre minúsculas,
                      mayúsculas, números y símbolos.
                    </span>
                  </label>

                  <label class="form-field">
                    Confirmar nueva contraseña

                    <input
                      type="password"
                      name="new_password_confirmation"
                      autocomplete="new-password"
                      minlength="12"
                      required
                    >
                  </label>

                  <button
                    class="button button--primary account-submit"
                    type="submit"
                    data-password-submit
                  >
                    Cambiar contraseña
                  </button>
                </form>

                <p
                  class="message"
                  data-password-message
                  role="alert"
                  aria-live="polite"
                ></p>
              </div>
            </section>

            <section class="panel account-panel--wide">
              <header class="panel__header">
                <div>
                  <h2>Accesos</h2>

                  <p>
                    Aplicaciones y roles actualmente asignados a tu cuenta.
                  </p>
                </div>
              </header>

              <div class="panel__body">
                <div
                  class="account-access-list"
                  data-account-applications
                >
                  Cargando accesos…
                </div>

                <dl class="account-meta">
                  <div>
                    <dt>Estado</dt>
                    <dd data-profile-status>—</dd>
                  </div>

                  <div>
                    <dt>Correo</dt>
                    <dd data-profile-email-verified>—</dd>
                  </div>

                  <div>
                    <dt>Último acceso</dt>
                    <dd data-profile-last-login>—</dd>
                  </div>

                  <div>
                    <dt>Cuenta creada</dt>
                    <dd data-profile-created>—</dd>
                  </div>
                </dl>
              </div>
            </section>
          </div>
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
    src="/assets/js/modules/account.js"
  ></script>
</body>
</html>