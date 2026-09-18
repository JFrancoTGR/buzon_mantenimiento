export function setupSidebar() {
  const toggle = document.querySelector('[data-sidebar-toggle]');
  const backdrop = document.querySelector('[data-sidebar-backdrop]');
  const sidebar = document.querySelector('.sidebar');

  if (!toggle || !backdrop || !sidebar) return;

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
    document.body.classList.contains('sidebar-open') ? close() : open();
  });

  backdrop.addEventListener('click', close);
  sidebar.addEventListener('click', (event) => {
    if (event.target.closest('a') && window.matchMedia('(max-width: 1024px)').matches) {
      close();
    }
  });

  window.addEventListener('resize', () => {
    if (!window.matchMedia('(max-width: 1024px)').matches) close();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') close();
  });
}
