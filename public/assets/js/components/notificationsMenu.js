import { setupDropdown } from './dropdown.js';
import { apiRequest } from '../core/api.js';
import { formatRelativeTime } from '../utils/date.js';

export function setupNotificationsMenu({ unreadCount = 0, notifications = [] } = {}) {
  const container = document.querySelector('[data-notifications-dropdown]');
  const trigger = document.querySelector('[data-notifications-trigger]');
  const panel = document.querySelector('[data-notifications-panel]');
  const count = document.querySelector('[data-notification-count]');
  const summary = document.querySelector('[data-notification-summary]');
  const list = document.querySelector('[data-notification-list]');

  setupDropdown({ container, trigger, panel });

  let currentUnread = Math.max(0, Number(unreadCount || 0));
  const items = Array.isArray(notifications) ? notifications.map((item) => ({ ...item })) : [];

  const updateBadge = () => {
    if (count) {
      count.textContent = currentUnread > 99 ? '99+' : String(currentUnread);
      count.hidden = currentUnread < 1;
    }
    if (summary) {
      summary.textContent = currentUnread === 1
        ? '1 notificación pendiente'
        : `${currentUnread} notificaciones pendientes`;
    }
  };

  updateBadge();

  if (!list) return;
  list.replaceChildren();

  if (items.length === 0) {
    list.append(createEmptyNotifications());
  } else {
    items.forEach((notification) => {
      list.append(createNotificationItem(notification, async () => {
        if (notification.is_read) return true;
        try {
          await apiRequest('./api/notifications/read.php', {
            method: 'POST',
            body: JSON.stringify({ notification_id: notification.id }),
          });
          notification.is_read = true;
          currentUnread = Math.max(0, currentUnread - 1);
          updateBadge();
          return true;
        } catch {
          return false;
        }
      }));
    });
  }

  panel?.querySelector('[data-mark-all-notifications]')?.remove();
  if (panel && currentUnread > 0) {
    const footer = document.createElement('div');
    footer.className = 'notification-actions';
    footer.dataset.markAllNotifications = 'true';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'notification-actions__button';
    button.textContent = 'Marcar todas como leídas';
    button.addEventListener('click', async () => {
      button.disabled = true;
      try {
        await apiRequest('./api/notifications/read-all.php', {
          method: 'POST',
          body: JSON.stringify({}),
        });
        currentUnread = 0;
        items.forEach((item) => { item.is_read = true; });
        list.querySelectorAll('.notification-item').forEach((item) => item.classList.remove('is-unread'));
        updateBadge();
        footer.remove();
      } finally {
        button.disabled = false;
      }
    });
    footer.append(button);
    panel.append(footer);
  }
}

function createNotificationItem(notification, markRead) {
  const item = document.createElement(notification.action_url ? 'a' : 'button');
  item.className = 'notification-item';
  if (!notification.is_read) item.classList.add('is-unread');

  if (notification.action_url) {
    item.href = notification.action_url;
  } else {
    item.type = 'button';
  }

  const title = document.createElement('strong');
  title.textContent = notification.title || 'Notificación';

  const message = document.createElement('span');
  message.textContent = notification.message || '';

  const time = document.createElement('time');
  time.textContent = formatRelativeTime(notification.created_at);
  if (notification.created_at) time.dateTime = notification.created_at;

  item.append(title, message, time);

  item.addEventListener('click', async (event) => {
    if (notification.is_read) return;
    event.preventDefault();
    const marked = await markRead();
    if (marked) item.classList.remove('is-unread');
    if (notification.action_url) window.location.href = notification.action_url;
  });

  return item;
}

function createEmptyNotifications() {
  const wrapper = document.createElement('div');
  wrapper.className = 'empty-state';

  const content = document.createElement('div');
  const icon = document.createElement('span');
  icon.className = 'empty-state__icon';
  icon.textContent = '✓';

  const title = document.createElement('h3');
  title.textContent = 'Sin notificaciones';

  const description = document.createElement('p');
  description.textContent = 'No tienes avisos pendientes por revisar.';

  content.append(icon, title, description);
  wrapper.append(content);
  return wrapper;
}
