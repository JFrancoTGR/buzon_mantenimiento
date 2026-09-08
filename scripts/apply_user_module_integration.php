<?php

declare(strict_types=1);

/**
 * Integra el método de correo de invitaciones en MailerService.php.
 * Ejecutar desde la raíz del proyecto después de copiar el patch del módulo Usuarios.
 *
 * php scripts/apply_user_module_integration.php
 */

$root = dirname(__DIR__);
$mailerPath = $root . '/app/Services/MailerService.php';

if (!is_file($mailerPath)) {
    fwrite(STDERR, "ERROR: No se encontró {$mailerPath}\n");
    exit(1);
}

$content = file_get_contents($mailerPath);
if (!is_string($content)) {
    fwrite(STDERR, "ERROR: No fue posible leer MailerService.php\n");
    exit(1);
}

if (str_contains($content, 'function sendUserInvitationEmail(')) {
    echo "OK: MailerService ya contiene sendUserInvitationEmail().\n";
    exit(0);
}

$marker = "    public function sendTicketCreatedConfirmationToReporter(\n";
$position = strpos($content, $marker);
if ($position === false) {
    fwrite(STDERR, "ERROR: No se encontró el punto de integración esperado en MailerService.php. No se modificó el archivo.\n");
    exit(1);
}

$method = <<<'PHP_METHOD'
    public function sendUserInvitationEmail(
        int $userId,
        string $recipientEmail,
        string $recipientName,
        string $rawToken,
        string $expiresAtUtc
    ): void {
        $appUrl = rtrim(Env::get('APP_URL'), '/');
        $invitationUrl = $appUrl . '/accept-invitation.html#token=' . rawurlencode($rawToken);
        $ttlHours = Env::int('USER_INVITATION_TTL_HOURS', 72);
        $appTimezone = new \DateTimeZone(Env::get('APP_TIMEZONE', 'America/Mexico_City'));
        $expiresLocal = (new \DateTimeImmutable($expiresAtUtc, new \DateTimeZone('UTC')))
            ->setTimezone($appTimezone)
            ->format('d/m/Y H:i');

        $safeName = self::escape($recipientName);
        $safeUrl = self::escape($invitationUrl);
        $safeExpires = self::escape($expiresLocal);
        $subject = 'Activa tu cuenta | Plataforma de Mantenimiento';

        $html = self::emailShell(
            'Has sido invitado',
            <<<HTML
<p>Hola {$safeName},</p>
<p>Se creó una cuenta para ti en la Plataforma de Gestión de Mantenimiento de Estrategia Urbana.</p>
<p>Utiliza el siguiente enlace para crear personalmente tu contraseña y activar tu cuenta.</p>
<p style="margin:28px 0;"><a href="{$safeUrl}" style="display:inline-block;background:#c31422;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;">Crear mi contraseña</a></p>
<p style="font-size:13px;color:#5f6b76;"><strong>Vigencia:</strong> este enlace estará disponible durante {$ttlHours} horas, hasta {$safeExpires}.</p>
<p style="font-size:13px;color:#5f6b76;word-break:break-all;">Si el botón no funciona, copia esta dirección en tu navegador:<br>{$safeUrl}</p>
<p style="font-size:13px;color:#5f6b76;">El enlace es personal, sólo puede utilizarse una vez y una nueva invitación invalidará cualquier enlace anterior.</p>
HTML
        );

        $text = "Hola {$recipientName},\n\n"
            . "Has sido invitado a la Plataforma de Gestión de Mantenimiento de Estrategia Urbana.\n"
            . "Crea personalmente tu contraseña para activar tu cuenta.\n\n"
            . "Enlace: {$invitationUrl}\n\n"
            . "El enlace estará disponible durante {$ttlHours} horas, hasta {$expiresLocal}.\n"
            . "Sólo puede utilizarse una vez.";

        $this->sendAndLog(
            null,
            $userId,
            $recipientEmail,
            $recipientName,
            'auth.user_invitation.requested',
            $subject,
            $html,
            $text
        );
    }

PHP_METHOD;

$backupPath = $mailerPath . '.before-users-module.bak';
if (!is_file($backupPath) && file_put_contents($backupPath, $content) === false) {
    fwrite(STDERR, "ERROR: No fue posible crear el respaldo {$backupPath}\n");
    exit(1);
}

$updated = substr($content, 0, $position) . $method . substr($content, $position);
if (file_put_contents($mailerPath, $updated) === false) {
    fwrite(STDERR, "ERROR: No fue posible actualizar MailerService.php\n");
    exit(1);
}

$lintCommand = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($mailerPath) . ' 2>&1';
exec($lintCommand, $lintOutput, $lintStatus);
if ($lintStatus !== 0) {
    copy($backupPath, $mailerPath);
    fwrite(STDERR, "ERROR: El archivo resultante no pasó php -l; se restauró el respaldo.\n" . implode("\n", $lintOutput) . "\n");
    exit(1);
}

echo "OK: sendUserInvitationEmail() integrado en MailerService.php.\n";
echo "Backup: {$backupPath}\n";
