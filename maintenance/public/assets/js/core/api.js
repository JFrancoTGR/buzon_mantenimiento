let csrfToken = null;

export async function getCsrfToken({ force = false } = {}) {
  if (csrfToken && !force) return csrfToken;

  const response = await fetch('./api/auth/csrf.php', {
    method: 'GET',
    credentials: 'same-origin',
    headers: { Accept: 'application/json' },
  });

  const payload = await parseResponse(response);
  csrfToken = payload.data.csrf_token;
  return csrfToken;
}

export async function apiRequest(url, options = {}) {
  const { __retried = false, ...requestOptions } = options;
  const method = (requestOptions.method || 'GET').toUpperCase();
  const headers = new Headers(requestOptions.headers || {});
  headers.set('Accept', 'application/json');

  if (!['GET', 'HEAD'].includes(method)) {
    headers.set('Content-Type', 'application/json');
    headers.set('X-CSRF-Token', await getCsrfToken());
  }

  const response = await fetch(url, {
    ...requestOptions,
    method,
    headers,
    credentials: 'same-origin',
  });

  if (response.status === 419 && !__retried) {
    await getCsrfToken({ force: true });
    return apiRequest(url, { ...requestOptions, __retried: true });
  }

  const payload = await parseResponse(response);
  if (payload?.data?.csrf_token) csrfToken = payload.data.csrf_token;
  return payload;
}

export async function apiFormRequest(url, options = {}) {
  const { __retried = false, ...requestOptions } = options;
  const method = (requestOptions.method || 'POST').toUpperCase();
  const headers = new Headers(requestOptions.headers || {});
  headers.set('Accept', 'application/json');
  headers.set('X-CSRF-Token', await getCsrfToken());

  const response = await fetch(url, {
    ...requestOptions,
    method,
    headers,
    credentials: 'same-origin',
  });

  if (response.status === 419 && !__retried) {
    await getCsrfToken({ force: true });
    return apiFormRequest(url, { ...requestOptions, __retried: true });
  }

  const payload = await parseResponse(response);
  if (payload?.data?.csrf_token) csrfToken = payload.data.csrf_token;
  return payload;
}

async function parseResponse(response) {
  let payload;
  try {
    payload = await response.json();
  } catch {
    throw new Error('El servidor devolvió una respuesta inválida.');
  }

  if (!response.ok || payload.ok === false) {
    const error = new Error(payload?.error?.message || 'No fue posible completar la solicitud.');
    error.code = payload?.error?.code || 'request_failed';
    error.status = response.status;
    throw error;
  }

  return payload;
}
