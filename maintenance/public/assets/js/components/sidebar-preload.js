(() => {
  try {
    const desktop = window.matchMedia(
      '(min-width: 1025px)',
    ).matches;

    const collapsed =
      window.localStorage.getItem(
        'euTools.sidebarCollapsed',
      ) === '1';

    if (desktop && collapsed) {
      document.documentElement.classList.add(
        'sidebar-collapsed',
      );
    }
  } catch {
  }
})();