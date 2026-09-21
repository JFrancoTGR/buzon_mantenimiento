<?php

declare(strict_types=1);

return static function (array $context): array {
    $escape = static fn (string $value): string =>
        htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

    $recipientName = (string) ($context['recipient_name'] ?? '');
    $folio = (string) ($context['folio'] ?? '');
    $title = (string) ($context['title'] ?? '');
    $locationName = (string) ($context['location_name'] ?? '');
    $specificLocation = (string) ($context['specific_location'] ?? '');
    $priorityName = (string) ($context['priority_name'] ?? '');
    $ticketUrl = (string) ($context['ticket_url'] ?? '');

    $safeName = $escape($recipientName);
    $safeFolio = $escape($folio);
    $safeTitle = $escape($title);
    $safeLocation = $escape($locationName);
    $safeSpecificLocation = $escape($specificLocation);
    $safePriority = $escape($priorityName);
    $safeTicketUrl = $escape($ticketUrl);

    $subject =
        "Reporte {$folio} recibido | Plataforma de Mantenimiento";

    $content = <<<HTML
<p>Hola {$safeName},</p>
<p>Tu reporte de mantenimiento fue registrado correctamente.</p>
<table role="presentation" style="width:100%;border-collapse:collapse;margin:22px 0;">
  <tr><td style="padding:8px 0;color:#5f6b76;width:160px;">Folio</td><td style="padding:8px 0;font-weight:700;">{$safeFolio}</td></tr>
  <tr><td style="padding:8px 0;color:#5f6b76;">Reporte</td><td style="padding:8px 0;">{$safeTitle}</td></tr>
  <tr><td style="padding:8px 0;color:#5f6b76;">Ubicación</td><td style="padding:8px 0;">{$safeLocation}</td></tr>
  <tr><td style="padding:8px 0;color:#5f6b76;">Zona</td><td style="padding:8px 0;">{$safeSpecificLocation}</td></tr>
  <tr><td style="padding:8px 0;color:#5f6b76;">Prioridad</td><td style="padding:8px 0;">{$safePriority}</td></tr>
</table>
<p>El supervisor responsable recibió una notificación para revisar el caso.</p>
<p style="margin:28px 0;"><a href="{$safeTicketUrl}" style="display:inline-block;background:#c31422;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;">Ir a la plataforma</a></p>
HTML;

    $html = <<<HTML
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Reporte recibido</title></head>
<body style="margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#17202a;">
  <div style="max-width:640px;margin:0 auto;padding:32px 18px;">
    <div style="background:#ffffff;border:1px solid #d9dee3;padding:28px;">
      <div style="font-size:13px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#c31422;margin-bottom:18px;">Estrategia Urbana</div>
      <h1 style="font-size:24px;margin:0 0 14px;">Reporte recibido</h1>
      {$content}
      <p style="margin:28px 0 0;font-size:13px;color:#5f6b76;">Plataforma de Gestión de Mantenimiento</p>
    </div>
  </div>
</body>
</html>
HTML;

    $text = "Hola {$recipientName},\n\n"
        . "Tu reporte fue registrado correctamente.\n"
        . "Folio: {$folio}\n"
        . "Reporte: {$title}\n"
        . "Ubicación: {$locationName}\n"
        . "Zona: {$specificLocation}\n"
        . "Prioridad: {$priorityName}\n\n"
        . "Consulta la plataforma en: {$ticketUrl}";

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
};