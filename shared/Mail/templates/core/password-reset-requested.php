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
    $resetUrl = (string) ($context['reset_url'] ?? '');
    $ttlMinutes = max(
        5,
        (int) ($context['ttl_minutes'] ?? 30)
    );

    $safeName = $escape($recipientName);
    $safeResetUrl = $escape($resetUrl);

    $subject = 'Restablece tu contraseña | EU Tools';

    $content = <<<HTML
<p>Hola {$safeName},</p>
<p>Recibimos una solicitud para restablecer la contraseña de tu cuenta.</p>
<p style="margin:28px 0;"><a href="{$safeResetUrl}" style="display:inline-block;background:#c31422;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;">Restablecer contraseña</a></p>
<p style="font-size:13px;color:#5f6b76;">El enlace vence en {$ttlMinutes} minutos y sólo puede utilizarse una vez.</p>
<p style="font-size:13px;color:#5f6b76;word-break:break-all;">Si el botón no funciona, copia esta dirección en tu navegador:<br>{$safeResetUrl}</p>
<p style="font-size:13px;color:#5f6b76;">Si no solicitaste este cambio, puedes ignorar este mensaje. Tu contraseña actual seguirá funcionando.</p>
HTML;

    $html = <<<HTML
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Recuperación de contraseña</title></head>
<body style="margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#17202a;">
  <div style="max-width:640px;margin:0 auto;padding:32px 18px;">
    <div style="background:#ffffff;border:1px solid #d9dee3;padding:28px;">
      <div style="font-size:13px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#c31422;margin-bottom:18px;">Estrategia Urbana</div>
      <h1 style="font-size:24px;margin:0 0 14px;">Recuperación de contraseña</h1>
      {$content}
      <p style="margin:28px 0 0;font-size:13px;color:#5f6b76;">EU Tools</p>
    </div>
  </div>
</body>
</html>
HTML;

    $text = "Hola {$recipientName},\n\n"
        . "Recibimos una solicitud para restablecer la contraseña de tu cuenta.\n\n"
        . "Enlace: {$resetUrl}\n\n"
        . "El enlace vence en {$ttlMinutes} minutos y sólo puede utilizarse una vez.\n\n"
        . "Si no solicitaste este cambio, ignora este mensaje. Tu contraseña actual seguirá funcionando.";

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
};