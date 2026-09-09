<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function readStrict(string $path): string
{
    $content = @file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("No fue posible leer {$path}");
    }
    return $content;
}

function writeStrict(string $path, string $content): void
{
    if (@file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException("No fue posible escribir {$path}");
    }
}

function backupOnce(string $path): void
{
    $backup = $path . '.before-password-recovery.bak';
    if (is_file($backup)) {
        return;
    }

    if (!@copy($path, $backup)) {
        throw new RuntimeException("No fue posible crear backup de {$path}");
    }
}

function replaceOnce(string $content, string $search, string $replace, string $label): string
{
    $position = strpos($content, $search);
    if ($position === false) {
        throw new RuntimeException("{$label}: no se encontró el punto de integración");
    }

    return substr_replace($content, $replace, $position, strlen($search));
}

function patchMailer(string $path): void
{
    $content = readStrict($path);

    if (
        str_contains($content, 'sendPasswordResetEmail(')
        && str_contains($content, 'sendPasswordResetCompletedNotification(')
    ) {
        echo "SKIP MailerService: recuperación ya integrada.\n";
        return;
    }

    if (!str_contains($content, 'sendPasswordChangedNotification(')) {
        throw new RuntimeException(
            'MailerService no contiene el parche de resiliencia previo. Aplica primero Account Resilience Patch V1.'
        );
    }

    if (!str_contains($content, "try {\n            \$this->assertReady();")) {
        throw new RuntimeException(
            'MailerService no tiene sendAndLog() endurecido. Aplica primero Account Resilience Patch V1.'
        );
    }

    $marker = "    public function sendTestEmail(string \$recipientEmail): void\n";
    if (!str_contains($content, $marker)) {
        throw new RuntimeException('MailerService: no se encontró sendTestEmail() como punto de integración.');
    }

    $methods = <<<'PHP'
    public function sendPasswordResetEmail(
        int $userId,
        string $recipientEmail,
        string $recipientName,
        string $rawToken
    ): void {
        $appUrl = rtrim(Env::get('APP_URL'), '/');
        $resetUrl = $appUrl . '/reset-password.html#token=' . rawurlencode($rawToken);
        $ttlMinutes = max(5, Env::int('PASSWORD_RESET_TTL_MINUTES', 30));
        $safeName = self::escape($recipientName);
        $safeResetUrl = self::escape($resetUrl);
        $subject = 'Restablece tu contraseña | Plataforma de Mantenimiento';

        $html = self::emailShell(
            'Recuperación de contraseña',
            <<<HTML
<p>Hola {$safeName},</p>
<p>Recibimos una solicitud para restablecer la contraseña de tu cuenta.</p>
<p style="margin:28px 0;"><a href="{$safeResetUrl}" style="display:inline-block;background:#c31422;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;">Restablecer contraseña</a></p>
<p style="font-size:13px;color:#5f6b76;">El enlace vence en {$ttlMinutes} minutos y sólo puede utilizarse una vez.</p>
<p style="font-size:13px;color:#5f6b76;word-break:break-all;">Si el botón no funciona, copia esta dirección en tu navegador:<br>{$safeResetUrl}</p>
<p style="font-size:13px;color:#5f6b76;">Si no solicitaste este cambio, puedes ignorar este mensaje. Tu contraseña actual seguirá funcionando.</p>
HTML
        );

        $text = "Hola {$recipientName},\n\n"
            . "Recibimos una solicitud para restablecer la contraseña de tu cuenta.\n\n"
            . "Enlace: {$resetUrl}\n\n"
            . "El enlace vence en {$ttlMinutes} minutos y sólo puede utilizarse una vez.\n\n"
            . "Si no solicitaste este cambio, ignora este mensaje. Tu contraseña actual seguirá funcionando.";

        $this->sendAndLog(
            null,
            $userId,
            $recipientEmail,
            $recipientName,
            'auth.password_reset.requested',
            $subject,
            $html,
            $text
        );
    }

    public function sendPasswordResetCompletedNotification(
        int $userId,
        string $recipientEmail,
        string $recipientName
    ): void {
        $appUrl = rtrim(Env::get('APP_URL'), '/');
        $loginUrl = $appUrl . '/login.html';
        $safeName = self::escape($recipientName);
        $safeLoginUrl = self::escape($loginUrl);
        $subject = 'Tu contraseña fue restablecida | Plataforma de Mantenimiento';

        $html = self::emailShell(
            'Contraseña restablecida',
            <<<HTML
<p>Hola {$safeName},</p>
<p>La contraseña de tu cuenta fue restablecida correctamente mediante el flujo de recuperación.</p>
<p>Por seguridad, todas las sesiones que estaban abiertas fueron cerradas y los demás enlaces de recuperación dejaron de ser válidos.</p>
<p style="margin:28px 0;"><a href="{$safeLoginUrl}" style="display:inline-block;background:#c31422;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;">Iniciar sesión</a></p>
<p style="font-size:13px;color:#5f6b76;"><strong>Si tú no realizaste este cambio, contacta de inmediato al administrador de la Plataforma de Mantenimiento.</strong></p>
HTML
        );

        $text = "Hola {$recipientName},\n\n"
            . "La contraseña de tu cuenta fue restablecida correctamente mediante el flujo de recuperación.\n"
            . "Todas las sesiones abiertas fueron cerradas y los demás enlaces de recuperación dejaron de ser válidos.\n\n"
            . "Inicia sesión en: {$loginUrl}\n\n"
            . "Si tú no realizaste este cambio, contacta de inmediato al administrador de la Plataforma de Mantenimiento.";

        $this->sendAndLog(
            null,
            $userId,
            $recipientEmail,
            $recipientName,
            'auth.password_reset.completed',
            $subject,
            $html,
            $text
        );
    }

PHP;

    backupOnce($path);
    $content = replaceOnce($content, $marker, $methods . $marker, 'MailerService');
    writeStrict($path, $content);
    echo "OK MailerService.\n";
}

function patchBootstrap(string $path): void
{
    $content = readStrict($path);
    $changed = false;

    if (!str_contains($content, 'use App\Services\PasswordResetService;')) {
        $marker = "use App\\Services\\NotificationService;\n";
        if (!str_contains($content, $marker)) {
            $marker = "use App\\Services\\MailerService;\n";
        }
        $content = replaceOnce(
            $content,
            $marker,
            $marker . "use App\\Services\\PasswordResetService;\n",
            'bootstrap import PasswordResetService'
        );
        $changed = true;
    }

    if (!str_contains($content, '$passwordResetService = new PasswordResetService(')) {
        $marker = "\$mailerService = new MailerService(\$pdo);\n";
        $replacement = $marker
            . "\$passwordResetService = new PasswordResetService(\$pdo, \$auditService, \$mailerService);\n";
        $content = replaceOnce(
            $content,
            $marker,
            $replacement,
            'bootstrap instancia PasswordResetService'
        );
        $changed = true;
    }

    if (!str_contains($content, "'password_reset' => \$passwordResetService")) {
        $marker = "    'mailer' => \$mailerService,\n";
        $content = replaceOnce(
            $content,
            $marker,
            $marker . "    'password_reset' => \$passwordResetService,\n",
            'bootstrap service registry PasswordResetService'
        );
        $changed = true;
    }

    if (!$changed) {
        echo "SKIP bootstrap: recuperación ya integrada.\n";
        return;
    }

    backupOnce($path);
    writeStrict($path, $content);
    echo "OK bootstrap.\n";
}

function patchHtaccess(string $path): void
{
    $content = readStrict($path);

    if (
        str_contains($content, 'forgot-password')
        && str_contains($content, 'reset-password')
    ) {
        echo "SKIP .htaccess: rutas ya integradas.\n";
        return;
    }

    $lines = preg_split('/\R/', $content);
    if (!is_array($lines)) {
        throw new RuntimeException('.htaccess: no fue posible separar líneas.');
    }

    $patched = false;

    foreach ($lines as &$line) {
        if (
            str_contains($line, 'RewriteRule ^(')
            && str_contains($line, ')\.html$ public/$1.html')
        ) {
            $start = strpos($line, '^(');
            $end = strpos($line, ')\.html$');

            if ($start === false || $end === false || $end <= $start + 2) {
                continue;
            }

            $prefix = substr($line, 0, $start + 2);
            $pages = substr($line, $start + 2, $end - ($start + 2));
            $suffix = substr($line, $end);

            $items = array_values(array_filter(explode('|', $pages), static fn(string $value): bool => $value !== ''));

            foreach (['forgot-password', 'reset-password'] as $page) {
                if (!in_array($page, $items, true)) {
                    $items[] = $page;
                }
            }

            $line = $prefix . implode('|', $items) . $suffix;
            $patched = true;
            break;
        }
    }
    unset($line);

    if (!$patched) {
        throw new RuntimeException('.htaccess: no se encontró la allowlist de páginas HTML.');
    }

    backupOnce($path);
    writeStrict($path, implode(PHP_EOL, $lines));
    echo "OK .htaccess.\n";
}

function patchLogin(string $path): void
{
    $content = readStrict($path);

    if (str_contains($content, 'href="./forgot-password.html"')) {
        echo "SKIP login.html: enlace ya integrado.\n";
        return;
    }

    $marker = <<<'HTML'
      </form>

      <p class="auth-switch">
        ¿No tienes una cuenta?
HTML;

    $replacement = <<<'HTML'
      </form>

      <p class="auth-switch">
        <a href="./forgot-password.html">¿Olvidaste tu contraseña?</a>
      </p>

      <p class="auth-switch">
        ¿No tienes una cuenta?
HTML;

    if (!str_contains($content, $marker)) {
        throw new RuntimeException('login.html: no se encontró el punto de integración.');
    }

    backupOnce($path);
    $content = replaceOnce($content, $marker, $replacement, 'login recovery link');
    writeStrict($path, $content);
    echo "OK login.html.\n";
}

try {
    $requiredNewFiles = [
        $root . '/app/Services/PasswordResetService.php',
        $root . '/public/forgot-password.html',
        $root . '/public/reset-password.html',
        $root . '/public/api/auth/forgot-password.php',
        $root . '/public/api/auth/password-reset-context.php',
        $root . '/public/api/auth/reset-password.php',
        $root . '/public/assets/js/modules/forgot-password.js',
        $root . '/public/assets/js/modules/reset-password.js',
        $root . '/public/assets/js/components/passwordVisibility.js',
    ];

    foreach ($requiredNewFiles as $file) {
        if (!is_file($file)) {
            throw new RuntimeException("Falta archivo requerido: {$file}");
        }
    }

    patchMailer($root . '/app/Services/MailerService.php');
    patchBootstrap($root . '/bootstrap/app.php');
    patchHtaccess($root . '/.htaccess');
    patchLogin($root . '/public/login.html');

    echo "OK: Password Recovery V1 integrado.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "ERROR: " . $exception->getMessage() . PHP_EOL);
    exit(1);
}
