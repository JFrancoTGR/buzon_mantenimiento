const STYLE_ID = 'password-visibility-stylesheet';

export function setupPasswordVisibility(root = document) {
  ensureStylesheet();

  root.querySelectorAll('input[type="password"]').forEach((input, index) => {
    if (input.dataset.passwordVisibilityReady === '1') return;

    input.dataset.passwordVisibilityReady = '1';
    if (!input.id) input.id = `password-field-${index + 1}`;

    const wrapper = document.createElement('span');
    wrapper.className = 'password-input';
    input.parentNode?.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'password-input__toggle';
    button.setAttribute('aria-controls', input.id);
    button.setAttribute('aria-pressed', 'false');
    button.setAttribute('aria-label', 'Mostrar contraseña');
    button.title = 'Mostrar contraseña';
    button.innerHTML = eyeIcon(false);

    button.addEventListener('click', () => {
      const willShow = input.type === 'password';
      input.type = willShow ? 'text' : 'password';
      button.setAttribute('aria-pressed', willShow ? 'true' : 'false');
      button.setAttribute('aria-label', willShow ? 'Ocultar contraseña' : 'Mostrar contraseña');
      button.title = willShow ? 'Ocultar contraseña' : 'Mostrar contraseña';
      button.innerHTML = eyeIcon(willShow);
      input.focus({ preventScroll: true });
    });

    wrapper.appendChild(button);
  });
}

function ensureStylesheet() {
  if (document.getElementById(STYLE_ID)) return;
  const link = document.createElement('link');
  link.id = STYLE_ID;
  link.rel = 'stylesheet';
  link.href = new URL('../../css/password-visibility.css', import.meta.url).href;
  document.head.appendChild(link);
}

function eyeIcon(visible) {
  if (visible) {
    return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3 21 21M10.6 10.7a2 2 0 0 0 2.7 2.7M9.9 4.2A10.8 10.8 0 0 1 12 4c5.5 0 9 5.2 9 8a9.8 9.8 0 0 1-2.2 3.8M6.2 6.2C4.1 7.7 3 10.2 3 12c0 2.8 3.5 8 9 8 1.5 0 2.9-.4 4.1-1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  }
  return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12c0-2.8 3.5-8 9-8s9 5.2 9 8-3.5 8-9 8-9-5.2-9-8Zm9 3.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}
