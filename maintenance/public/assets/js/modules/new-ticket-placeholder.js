import { apiRequest } from '../core/api.js';

const message = document.querySelector('[data-message]');
const userSummary = document.querySelector('[data-user-summary]');
const userName = document.querySelector('[data-user-name]');
const userEmail = document.querySelector('[data-user-email]');
const locationContext = document.querySelector('[data-location-context]');
const locationName = document.querySelector('[data-location-name]');
const locationCode = getLocationCode();

try {
  const sessionPayload = await apiRequest('./api/auth/me.php');
  const user = sessionPayload.data.user;

  if (user.must_change_password) {
    window.location.replace(withLocation('./change-password.html'));
  }

  if (userSummary && userName && userEmail) {
    userName.textContent = user.full_name;
    userEmail.textContent = user.email;
    userSummary.hidden = false;
  }

  if (locationCode) {
    const contextPayload = await apiRequest(`./api/auth/registration-context.php?location=${encodeURIComponent(locationCode)}`);
    const location = contextPayload.data.location;
    if (location && locationContext && locationName) {
      locationName.textContent = location.name;
      locationContext.hidden = false;
    }
  }
} catch (error) {
  if (error.status === 401) {
    window.location.replace(withLocation('./login.html'));
  } else if (message) {
    message.textContent = error.message || 'No fue posible cargar la información.';
    message.dataset.type = 'error';
  }
}

function getLocationCode() {
  const code = new URLSearchParams(window.location.search).get('location') || '';
  return /^[a-z0-9_]{1,50}$/.test(code) ? code : '';
}

function withLocation(path) {
  return locationCode ? `${path}?location=${encodeURIComponent(locationCode)}` : path;
}
