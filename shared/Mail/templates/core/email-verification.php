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
    $verificationUrl = (string) ($context['verification_url'] ?? '');
    $expiresMinutes = max(
        1,
        (int) ($context['expires_minutes'] ?? 60)
    );

    $safeName = $escape($recipientName);
    $safeUrl = $escape($verificationUrl);

    $subject = 'Verifica tu cuenta | EU Tools';

    $content = <<<HTML
<p>Hola {$safeName},</p>
<p>Confirma tu correo electrónico para activar tu cuenta de EU Tools.</p>
<p style="margin:28px 0;">
  <a href="{$safeUrl}" style="display:inline-block;background:#c31422;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;">Verificar mi cuenta</a>
</p>
<p style="font-size:13px;color:#5f6b76;">El enlace vence en {$expiresMinutes} minutos y solo puede utilizarse una vez.</p>
<p style="font-size:13px;color:#5f6b76;word-break:break-all;">Si el botón no funciona, copia esta dirección en tu navegador:<br>{$safeUrl}</p>
<p style="font-size:13px;color:#5f6b76;">Si no solicitaste esta cuenta, puedes ignorar este mensaje.</p>
HTML;

    $html = <<<HTML
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Verifica tu cuenta</title></head>
<body style="margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#17202a;">
  <div style="max-width:640px;margin:0 auto;padding:32px 18px;">
    <div style="background:#ffffff;border:1px solid #d9dee3;padding:28px;">
      <div style="font-size:13px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#c31422;margin-bottom:18px;">Estrategia Urbana</div>
      <h1 style="font-size:24px;margin:0 0 14px;">Verifica tu correo electrónico</h1>
      {$content}
      <p style="margin:28px 0 0;font-size:13px;color:#5f6b76;">EU Tools</p>
    </div>
  </div>
</body>
</html>
HTML;

    $text = "Hola {$recipientName},\n\n"
        . "Confirma tu correo electrónico para activar tu cuenta de EU Tools.\n\n"
        . "Enlace: {$verificationUrl}\n\n"
        . "El enlace vence en {$expiresMinutes} minutos y solo puede utilizarse una vez.";

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
};