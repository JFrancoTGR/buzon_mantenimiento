import { apiRequest } from './api.js';

const APPLICATION_CODE = 'maintenance';

export async function getMaintenanceUser() {
  const payload = await apiRequest('/api/auth/me');
  const user = payload?.data?.user;

  if (!user || typeof user !== 'object') {
    const error = new Error('No fue posible validar tu sesiÃ³n.');
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

  const roleCode =
    typeof application.role?.code === 'string'
      ? application.role.code
      : '';

  return {
    ...user,
    roles: roleCode ? [roleCode] : [],
    permissions: Array.isArray(application.permissions)
      ? [...application.permissions]
      : [],
  };
}
