const dateTimeFormatter = new Intl.DateTimeFormat('es-MX', {
  dateStyle: 'medium',
  timeStyle: 'short',
});

const relativeFormatter = new Intl.RelativeTimeFormat('es-MX', {
  numeric: 'auto',
});

export function parseUtcDate(value) {
  if (!value) return null;
  const normalized = String(value).includes('T')
    ? String(value)
    : String(value).replace(' ', 'T');
  const date = new Date(normalized.endsWith('Z') ? normalized : `${normalized}Z`);
  return Number.isNaN(date.getTime()) ? null : date;
}

export function formatDateTime(value) {
  const date = parseUtcDate(value);
  return date ? dateTimeFormatter.format(date) : '—';
}

export function formatRelativeTime(value) {
  const date = parseUtcDate(value);
  if (!date) return 'Fecha no disponible';

  const differenceSeconds = Math.round((date.getTime() - Date.now()) / 1000);
  const absoluteSeconds = Math.abs(differenceSeconds);

  if (absoluteSeconds < 60) return relativeFormatter.format(differenceSeconds, 'second');

  const minutes = Math.round(differenceSeconds / 60);
  if (Math.abs(minutes) < 60) return relativeFormatter.format(minutes, 'minute');

  const hours = Math.round(minutes / 60);
  if (Math.abs(hours) < 24) return relativeFormatter.format(hours, 'hour');

  const days = Math.round(hours / 24);
  if (Math.abs(days) < 30) return relativeFormatter.format(days, 'day');

  return formatDateTime(value);
}
