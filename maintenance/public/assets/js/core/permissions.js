function maintenanceGrants(user) {
  return Array.isArray(user?.grants)
    ? user.grants.filter(
      (grant) => grant && typeof grant === 'object',
    )
    : [];
}

function grantPermissions(grant) {
  return Array.isArray(grant?.permissions)
    ? grant.permissions
    : [];
}

export function hasPermission(
  user,
  permission,
  locationId = null,
) {
  if (
    Array.isArray(user?.permissions)
    && user.permissions.includes(permission)
  ) {
    return true;
  }

  const grants = maintenanceGrants(user);

  /*
   * A GLOBAL grant applies everywhere. Core currently promotes GLOBAL
   * permissions to user.permissions as well, but checking it here keeps
   * this helper correct for other Maintenance user payloads.
   */
  const globalGrant = grants.find(
    (grant) =>
      Boolean(grant.is_global)
      && grantPermissions(grant).includes(permission),
  );

  if (globalGrant) return true;

  const normalizedLocationId = Number(locationId);

  if (
    !Number.isInteger(normalizedLocationId)
    || normalizedLocationId < 1
  ) {
    return false;
  }

  return grants.some((grant) => {
    if (Boolean(grant.is_global)) return false;

    const grantLocationId = Number(grant.location?.id);

    return Number.isInteger(grantLocationId)
      && grantLocationId === normalizedLocationId
      && grantPermissions(grant).includes(permission);
  });
}

export function hasAnyPermission(
  user,
  permissions,
) {
  const requested = Array.isArray(permissions)
    ? permissions
    : [permissions];

  const normalized = requested
    .map((permission) => String(permission || '').trim())
    .filter(Boolean);

  if (normalized.length === 0) {
    return true;
  }

  const topLevelPermissions =
    Array.isArray(user?.permissions)
      ? user.permissions
      : [];

  if (
    normalized.some((permission) =>
      topLevelPermissions.includes(permission)
    )
  ) {
    return true;
  }

  return maintenanceGrants(user).some((grant) => {
    const permissionsForGrant =
      grantPermissions(grant);

    return normalized.some((permission) =>
      permissionsForGrant.includes(permission)
    );
  });
}

export function roleCodeAtLocation(
  user,
  locationId,
) {
  const normalizedLocationId = Number(locationId);

  if (
    !Number.isInteger(normalizedLocationId)
    || normalizedLocationId < 1
  ) {
    return null;
  }

  for (const grant of maintenanceGrants(user)) {
    const roleCode =
      typeof grant.role?.code === 'string'
        ? grant.role.code.trim()
        : '';

    if (!roleCode) continue;

    if (Boolean(grant.is_global)) {
      return roleCode;
    }

    if (
      Number(grant.location?.id)
      === normalizedLocationId
    ) {
      return roleCode;
    }
  }

  return null;
}

export function hasRoleAtLocation(
  user,
  roleCode,
  locationId,
) {
  return roleCodeAtLocation(
    user,
    locationId,
  ) === roleCode;
}

export function isReporterOnlyUser(user) {
  const grants = maintenanceGrants(user);

  if (grants.length === 0) {
    return false;
  }

  let foundRole = false;

  for (const grant of grants) {
    const roleCode =
      typeof grant.role?.code === 'string'
        ? grant.role.code.trim()
        : '';

    if (!roleCode) continue;

    foundRole = true;

    if (roleCode !== 'reporter') {
      return false;
    }
  }

  return foundRole;
}

export function applyPermissionVisibility(
  user,
  root = document,
) {
  root
    .querySelectorAll('[data-permissions]')
    .forEach((element) => {
      const permissions =
        String(element.dataset.permissions || '')
          .split(',')
          .map((permission) => permission.trim())
          .filter(Boolean);

      /*
       * data-permissions is a feature-visibility hint only. Concrete
       * resource authorization always remains server-side and
       * Location-aware.
       */
      element.hidden =
        !hasAnyPermission(user, permissions);
    });

  root
    .querySelectorAll('[data-nav-group]')
    .forEach((group) => {
      const visibleItems = [
        ...group.querySelectorAll('.nav-link'),
      ].some((item) => !item.hidden);

      group.hidden = !visibleItems;
    });

  document.documentElement
    .classList.add('permissions-ready');
}
