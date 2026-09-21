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
    $loginUrl = (string) ($context['login_url'] ?? '');

    $safeName = $escape($recipientName);
    $safeLoginUrl = $escape($loginUrl);

    $subject = 'Tu contraseña fue restablecida | EU Tools';

    $content = <<<HTML
<p>Hola {$safeName},</p>
<p>La contraseña de tu cuenta fue restablecida correctamente mediante el flujo de recuperación.</p>
<p>Por seguridad, todas las sesiones que estaban abiertas fueron cerradas y los demás enlaces de recuperación dejaron de ser válidos.</p>
<p style="margin:28px 0;"><a href="{$safeLoginUrl}" style="display:inline-block;background:#c31422;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;">Iniciar sesión</a></p>
<p style="font-size:13px;color:#5f6b76;"><strong>Si tú no realizaste este cambio, contacta de inmediato al administrador de EU Tools.</strong></p>
HTML;

    $html = <<<HTML
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Contraseña restablecida</title></head>
<body style="margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#17202a;">
  <div style="max-width:640px;margin:0 auto;padding:32px 18px;">
    <div style="background:#ffffff;border:1px solid #d9dee3;padding:28px;">
      <div style="font-size:13px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#c31422;margin-bottom:18px;">Estrategia Urbana</div>
      <h1 style="font-size:24px;margin:0 0 14px;">Contraseña restablecida</h1>
      {$content}
      <p style="margin:28px 0 0;font-size:13px;color:#5f6b76;">EU Tools</p>
    </div>
  </div>
</body>
</html>
HTML;

    $text = "Hola {$recipientName},\n\n"
        . "La contraseña de tu cuenta fue restablecida correctamente mediante el flujo de recuperación.\n"
        . "Todas las sesiones abiertas fueron cerradas y los demás enlaces de recuperación dejaron de ser válidos.\n\n"
        . "Inicia sesión en: {$loginUrl}\n\n"
        . "Si tú no realizaste este cambio, contacta de inmediato al administrador de EU Tools.";

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
};