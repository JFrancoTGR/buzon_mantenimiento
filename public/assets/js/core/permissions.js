export function hasPermission(user, permission) {
  return Array.isArray(user?.permissions) && user.permissions.includes(permission);
}

export function hasAnyPermission(user, permissions) {
  if (!Array.isArray(permissions) || permissions.length === 0) return true;
  return permissions.some((permission) => hasPermission(user, permission));
}

export function applyPermissionVisibility(user, root = document) {
  root.querySelectorAll('[data-permissions]').forEach((element) => {
    const permissions = String(element.dataset.permissions || '')
      .split(',')
      .map((permission) => permission.trim())
      .filter(Boolean);

    element.hidden = !hasAnyPermission(user, permissions);
  });

  root.querySelectorAll('[data-nav-group]').forEach((group) => {
    const visibleItems = [...group.querySelectorAll('.nav-link')]
      .some((item) => !item.hidden);
    group.hidden = !visibleItems;
  });

  document.documentElement.classList.add('permissions-ready');
}
