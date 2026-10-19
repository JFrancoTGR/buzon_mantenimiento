import { apiRequest } from './api.js';

const APPLICATION_CODE = 'maintenance';

export async function getMaintenanceUser() {
  const payload = await apiRequest('/api/auth/me');
  const user = payload?.data?.user;

  if (!user || typeof user !== 'object') {
    const error = new Error(
      'No fue posible validar tu sesión.'
    );
    error.code = 'authentication_required';
    error.status = 401;
    throw error;
  }

  const applications = Array.isArray(user.applications)
    ? user.applications
    : [];

  const application = applications.find(
    (item) => item?.code === APPLICATION_CODE,
  );

  if (!application) {
    const error = new Error(
      'No tienes acceso a la Plataforma de Mantenimiento.',
    );
    error.code = 'application_access_denied';
    error.status = 403;
    throw error;
  }

  const grants = Array.isArray(application.grants)
    ? application.grants
      .filter((grant) => grant && typeof grant === 'object')
      .map((grant) => ({
        ...grant,
        role: grant.role && typeof grant.role === 'object'
          ? { ...grant.role }
          : null,
        location: grant.location && typeof grant.location === 'object'
          ? { ...grant.location }
          : null,
        permissions: Array.isArray(grant.permissions)
          ? [...grant.permissions]
          : [],
        is_global: Boolean(grant.is_global),
      }))
    : [];

  const roleCodes = new Set();

  grants.forEach((grant) => {
    const code = typeof grant.role?.code === 'string'
      ? grant.role.code.trim()
      : '';

    if (code) roleCodes.add(code);
  });

  /*
   * Compatibility for a single-role application payload. Maintenance
   * normally reaches this branch only if grants are absent, but keeping
   * it makes the adapter safe for fixtures and transitional responses.
   */
  const compatibilityRole =
    typeof application.role?.code === 'string'
      ? application.role.code.trim()
      : '';

  if (compatibilityRole) {
    roleCodes.add(compatibilityRole);
  }

  return {
    ...user,
    access_model:
      typeof application.access_model === 'string'
        ? application.access_model
        : 'single_role',
    application_role:
      application.role && typeof application.role === 'object'
        ? { ...application.role }
        : null,
    roles: [...roleCodes],
    permissions: Array.isArray(application.permissions)
      ? [...application.permissions]
      : [],
    grants,
  };
}
