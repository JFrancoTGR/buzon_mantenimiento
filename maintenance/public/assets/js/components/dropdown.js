export function setupDropdown({ container, trigger, panel }) {
  if (!container || !trigger || !panel) return { close() {} };

  const close = () => {
    panel.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
  };

  const open = () => {
    document.querySelectorAll('.dropdown__panel:not([hidden])').forEach((otherPanel) => {
      if (otherPanel !== panel) {
        otherPanel.hidden = true;
        otherPanel.closest('.dropdown')?.querySelector('[aria-expanded="true"]')?.setAttribute('aria-expanded', 'false');
      }
    });

    panel.hidden = false;
    trigger.setAttribute('aria-expanded', 'true');
  };

  trigger.addEventListener('click', (event) => {
    event.stopPropagation();
    panel.hidden ? open() : close();
  });

  document.addEventListener('click', (event) => {
    if (!container.contains(event.target)) close();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      close();
      trigger.focus();
    }
  });

  return { close };
}
