(() => {
  try {
    const storedTheme =
      window.localStorage.getItem('euTools.theme');

    document.documentElement.dataset.theme =
      storedTheme === 'dark'
        ? 'dark'
        : 'light';
  } catch {
    document.documentElement.dataset.theme = 'light';
  }
})();
