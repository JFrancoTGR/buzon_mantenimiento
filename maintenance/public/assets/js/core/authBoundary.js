export function currentMaintenanceReturnPath() {
  const { pathname, search, hash } = window.location;

  const isMaintenancePath =
    pathname === '/maintenance'
    || pathname === '/maintenance/'
    || pathname.startsWith('/maintenance/');

  if (!isMaintenancePath) {
    return '/maintenance/';
  }

  return `${pathname}${search}${hash}`;
}

export function redirectToCoreLogin() {
  window.location.replace(
    withReturn('/login', currentMaintenanceReturnPath()),
  );
}

export function redirectToCorePasswordChange() {
  window.location.replace(
    withReturn('/change-password', currentMaintenanceReturnPath()),
  );
}

export function handleCoreBoundaryError(error) {
  const status = Number(error?.status || 0);
  const code = String(error?.code || '');

  if (status === 401) {
    redirectToCoreLogin();
    return true;
  }

  if (
    status === 428
    || code === 'password_change_required'
  ) {
    redirectToCorePasswordChange();
    return true;
  }

  if (
    status === 403
    && code === 'application_access_denied'
  ) {
    window.location.replace('/');
    return true;
  }

  return false;
}

function withReturn(path, returnPath) {
  return `${path}?return=${encodeURIComponent(returnPath)}`;
}