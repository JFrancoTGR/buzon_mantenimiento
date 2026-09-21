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
    $safeName = $escape($recipientName);

    $subject = 'Tus datos de cuenta fueron actualizados | EU Tools';

    $content = <<<HTML
<p>Hola {$safeName},</p>
<p>El nombre y/o los apellidos asociados con tu cuenta fueron actualizados correctamente.</p>
<p><strong>Nombre actual:</strong> {$safeName}</p>
<p style="font-size:13px;color:#5f6b76;">Si no realizaste este cambio, contacta al administrador de EU Tools.</p>
HTML;

    $html = <<<HTML
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>Datos de cuenta actualizados</title></head>
<body style="margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#17202a;">
  <div style="max-width:640px;margin:0 auto;padding:32px 18px;">
    <div style="background:#ffffff;border:1px solid #d9dee3;padding:28px;">
      <div style="font-size:13px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#c31422;margin-bottom:18px;">Estrategia Urbana</div>
      <h1 style="font-size:24px;margin:0 0 14px;">Datos de cuenta actualizados</h1>
      {$content}
      <p style="margin:28px 0 0;font-size:13px;color:#5f6b76;">EU Tools</p>
    </div>
  </div>
</body>
</html>
HTML;

    $text = "Hola {$recipientName},\n\n"
        . "Los datos personales de tu cuenta fueron actualizados correctamente.\n"
        . "Nombre actual: {$recipientName}\n\n"
        . "Si no realizaste este cambio, contacta al administrador de EU Tools.";

    return [
        'subject' => $subject,
        'html' => $html,
        'text' => $text,
    ];
};