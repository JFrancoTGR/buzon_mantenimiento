<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function readFileStrict(string $path): string
{
    $content = @file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("No fue posible leer {$path}");
    }
    return $content;
}

function backupOnce(string $path): void
{
    $backup = $path . '.before-account-resilience.bak';
    if (!is_file($backup) && !copy($path, $backup)) {
        throw new RuntimeException("No fue posible crear backup de {$path}");
    }
}

function writeStrict(string $path, string $content): void
{
    backupOnce($path);
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException("No fue posible escribir {$path}");
    }
}

function replaceOnce(string $content, string $needle, string $replacement, string $label): string
{
    $count = substr_count($content, $needle);
    if ($count !== 1) {
        throw new RuntimeException("{$label}: se esperaba 1 coincidencia y se encontraron {$count}");
    }
    return str_replace($needle, $replacement, $content);
}


function replaceCount(string $content, string $needle, string $replacement, int $expected, string $label): string
{
    $count = substr_count($content, $needle);
    if ($count !== $expected) {
        throw new RuntimeException("{$label}: se esperaban {$expected} coincidencias y se encontraron {$count}");
    }
    return str_replace($needle, $replacement, $content);
}

function replaceRegexOnce(string $content, string $pattern, string $replacement, string $label): string
{
    $result = preg_replace($pattern, $replacement, $content, 1, $count);
    if (!is_string($result) || $count !== 1) {
        throw new RuntimeException("{$label}: no se pudo aplicar el reemplazo esperado");
    }
    return $result;
}

function patchMailer(string $path): void
{
    $content = readFileStrict($path);
    $changed = false;

    $methodsReady = str_contains($content, 'sendProfileChangedNotification(')
        && str_contains($content, 'sendPasswordChangedNotification(');

    if (!$methodsReady) {
        $marker = "    public function sendTestEmail(string \$recipientEmail): void\n";
        if (!str_contains($content, $marker)) {
            throw new RuntimeException('MailerService: no se encontró sendTestEmail() como punto de integración.');
        }

        $methods = <<<'PHP'
    public function sendProfileChangedNotification(
        int $userId,
        string $recipientEmail,
        string $recipientName
    ): void {
        $safeName = self::escape($recipientName);
        $subject = 'Tus datos de cuenta fueron actualizados | Plataforma de Mantenimiento';
        $html = self::emailShell(
            'Datos de cuenta actualizados',
            <<<HTML
<p>Hola {$safeName},</p>
<p>El nombre y/o los apellidos asociados con tu cuenta fueron actualizados correctamente.</p>
<p><strong>Nombre actual:</strong> {$safeName}</p>
<p style="font-size:13px;color:#5f6b76;">Si no realizaste este cambio, contacta al administrador de la Plataforma de Mantenimiento.</p>
HTML
        );
        $text = "Hola {$recipientName},\n\n"
            . "Los datos personales de tu cuenta fueron actualizados correctamente.\n"
            . "Nombre actual: {$recipientName}\n\n"
            . "Si no realizaste este cambio, contacta al administrador de la Plataforma de Mantenimiento.";

        $this->sendAndLog(
            null,
            $userId,
            $recipientEmail,
            $recipientName,
            'account.profile.changed',
            $subject,
            $html,
            $text
        );
    }

    public function sendPasswordChangedNotification(
        int $userId,
        string $recipientEmail,
        string $recipientName
    ): void {
        $appUrl = rtrim(Env::get('APP_URL'), '/');
        $loginUrl = $appUrl . '/login.html';
        $safeLoginUrl = self::escape($loginUrl);
        $safeName = self::escape($recipientName);
        $subject = 'Tu contraseña fue actualizada | Plataforma de Mantenimiento';
        $html = self::emailShell(
            'Contraseña actualizada',
            <<<HTML
<p>Hola {$safeName},</p>
<p>La contraseña de tu cuenta fue actualizada correctamente.</p>
<p>Por seguridad, todas las sesiones que estaban abiertas fueron cerradas.</p>
<p style="margin:28px 0;"><a href="{$safeLoginUrl}" style="display:inline-block;background:#c31422;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;">Iniciar sesión</a></p>
<p style="font-size:13px;color:#5f6b76;"><strong>Si tú no realizaste este cambio, contacta de inmediato al administrador de la Plataforma de Mantenimiento.</strong></p>
HTML
        );
        $text = "Hola {$recipientName},\n\n"
            . "La contraseña de tu cuenta fue actualizada correctamente.\n"
            . "Por seguridad, todas las sesiones abiertas fueron cerradas.\n\n"
            . "Inicia sesión en: {$loginUrl}\n\n"
            . "Si tú no realizaste este cambio, contacta de inmediato al administrador de la Plataforma de Mantenimiento.";

        $this->sendAndLog(
            null,
            $userId,
            $recipientEmail,
            $recipientName,
            'auth.password.changed',
            $subject,
            $html,
            $text
        );
    }

PHP;
        $content = str_replace($marker, $methods . $marker, $content);
        $changed = true;
    }

    $oldSendStart = <<<'PHP'
    ): void {
        $this->assertReady();

        try {
            $transport = strtolower(Env::get('MAIL_TRANSPORT', 'smtp'));
PHP;
    $newSendStart = <<<'PHP'
    ): void {
        try {
            $this->assertReady();
            $transport = strtolower(Env::get('MAIL_TRANSPORT', 'smtp'));
PHP;

    if (str_contains($content, $oldSendStart)) {
        $content = replaceOnce($content, $oldSendStart, $newSendStart, 'MailerService sendAndLog assertReady');
        $changed = true;
    } elseif (!str_contains($content, "try {\n            \$this->assertReady();\n            \$transport = strtolower")) {
        throw new RuntimeException('MailerService: no se pudo verificar la posición de assertReady() en sendAndLog().');
    }

    if (!$changed) {
        echo "SKIP MailerService: ya integrado.\n";
        return;
    }

    writeStrict($path, $content);
    echo "OK MailerService.\n";
}

function patchAuthService(string $path): void
{
    $content = readFileStrict($path);
    if (str_contains($content, "public function changePassword(string \$currentPassword, string \$newPassword, string \$confirmation): array")) {
        echo "SKIP AuthService: ya integrado.\n";
        return;
    }

    $replacement = <<<'PHP'
    /** @return array{user_id:int,email:string,full_name:string} */
    public function changePassword(string $currentPassword, string $newPassword, string $confirmation): array
    {
        $user = $this->currentUser();

        if ($newPassword !== $confirmation) {
            throw new HttpException(422, 'password_confirmation_mismatch', 'La confirmación no coincide.');
        }

        self::validatePasswordStrength($newPassword);

        $statement = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $user['id']]);
        $storedHash = $statement->fetchColumn();

        if (!is_string($storedHash) || !password_verify($currentPassword, $storedHash)) {
            throw new HttpException(401, 'current_password_invalid', 'La contraseña actual no es correcta.');
        }

        if (password_verify($newPassword, $storedHash)) {
            throw new HttpException(422, 'password_reused', 'La nueva contraseña debe ser diferente.');
        }

        $this->pdo->beginTransaction();
        try {
            $update = $this->pdo->prepare(
                'UPDATE users
                 SET password_hash = :hash,
                     must_change_password = 0,
                     failed_login_attempts = 0,
                     locked_until = NULL
                 WHERE id = :id'
            );
            $update->execute([
                'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $user['id'],
            ]);

            $revokeSessions = $this->pdo->prepare(
                'UPDATE user_sessions
                 SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
                 WHERE user_id = :user_id'
            );
            $revokeSessions->execute(['user_id' => $user['id']]);

            $this->audit->record('auth.password.change', 'user', (int) $user['id'], (int) $user['id']);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        SessionManager::destroyLocal();

        return [
            'user_id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'full_name' => (string) $user['full_name'],
        ];
    }
PHP;

    $pattern = '~    public function changePassword\(string \\$currentPassword, string \\$newPassword, string \\$confirmation\): void\n    \{.*?\n    \}\n\n    public static function validatePasswordStrength~s';
    $content = replaceRegexOnce($content, $pattern, $replacement . "\n\n    public static function validatePasswordStrength", 'AuthService::changePassword');
    writeStrict($path, $content);
    echo "OK AuthService.\n";
}

function patchRegistrationService(string $path): void
{
    $content = readFileStrict($path);
    if (str_contains($content, 'finalizeVerificationTokenDelivery(')) {
        echo "SKIP RegistrationService: ya integrado.\n";
        return;
    }

    $content = replaceOnce(
        $content,
        "        AuthService::validatePasswordStrength(\$password);\n        \$this->assertRegistrationRateLimit(Http::clientIp());\n        \$this->mailer->assertReady();\n",
        "        AuthService::validatePasswordStrength(\$password);\n        \$this->assertRegistrationRateLimit(Http::clientIp());\n",
        'RegistrationService assertReady register'
    );

    $content = replaceCount(
        $content,
        <<<'PHP'
            $token = $this->issueToken($userId, $location['id'] ?? null);
PHP,
        <<<'PHP'
            $issuedToken = $this->issueToken($userId, $location['id'] ?? null);
PHP,
        2,
        'RegistrationService issue tokens'
    );

    $oldInitialDelivery = <<<'PHP'
        $emailSent = true;
        try {
            $this->mailer->sendVerificationEmail(
                $userId,
                $email,
                trim($firstName . ' ' . $lastName),
                $token,
                $location
            );
        } catch (Throwable $exception) {
            $emailSent = false;
            $this->audit->safeRecord('auth.email_verification.delivery_failed', 'user', $userId, null, [
                'email' => $email,
            ]);
        }
PHP;
    $newInitialDelivery = <<<'PHP'
        $emailSent = true;
        try {
            $this->mailer->sendVerificationEmail(
                $userId,
                $email,
                trim($firstName . ' ' . $lastName),
                (string) $issuedToken['raw_token'],
                $location
            );
            $this->finalizeVerificationTokenDelivery($userId, (int) $issuedToken['token_id'], true);
        } catch (Throwable $exception) {
            $emailSent = false;
            $this->finalizeVerificationTokenDelivery($userId, (int) $issuedToken['token_id'], false);
            $this->audit->safeRecord('auth.email_verification.delivery_failed', 'user', $userId, null, [
                'email' => $email,
            ]);
        }
PHP;
    $content = replaceOnce($content, $oldInitialDelivery, $newInitialDelivery, 'RegistrationService initial delivery');

    $content = replaceOnce(
        $content,
        "            'SELECT evt.created_at, evt.location_id, l.code AS location_code, l.name AS location_name\n",
        "            'SELECT evt.created_at, evt.location_id, evt.used_at, evt.revoked_at, l.code AS location_code, l.name AS location_name\n",
        'RegistrationService latest token state columns'
    );

    $content = replaceOnce(
        $content,
        "             ORDER BY evt.created_at DESC\n             LIMIT 1'",
        "             ORDER BY\n                 CASE WHEN evt.used_at IS NULL AND evt.revoked_at IS NULL THEN 0 ELSE 1 END,\n                 evt.created_at DESC\n             LIMIT 1'",
        'RegistrationService latest token preference'
    );

    $content = replaceOnce(
        $content,
        "        if (is_array(\$latest)) {\n            \$latestAt = new DateTimeImmutable((string) \$latest['created_at'], new DateTimeZone('UTC'));",
        "        if (is_array(\$latest) && \$latest['used_at'] === null && \$latest['revoked_at'] === null) {\n            \$latestAt = new DateTimeImmutable((string) \$latest['created_at'], new DateTimeZone('UTC'));",
        'RegistrationService cooldown only usable token'
    );

    $oldResendDelivery = <<<'PHP'
        try {
            $this->mailer->sendVerificationEmail(
                $userId,
                (string) $user['email'],
                trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
                $token,
                $location
            );
        } catch (Throwable $exception) {
            $this->audit->safeRecord('auth.email_verification.delivery_failed', 'user', $userId, null, [
                'email' => $email,
                'resend' => true,
            ]);
        }
PHP;
    $newResendDelivery = <<<'PHP'
        try {
            $this->mailer->sendVerificationEmail(
                $userId,
                (string) $user['email'],
                trim((string) $user['first_name'] . ' ' . (string) $user['last_name']),
                (string) $issuedToken['raw_token'],
                $location
            );
            $this->finalizeVerificationTokenDelivery($userId, (int) $issuedToken['token_id'], true);
        } catch (Throwable $exception) {
            $this->finalizeVerificationTokenDelivery($userId, (int) $issuedToken['token_id'], false);
            $this->audit->safeRecord('auth.email_verification.delivery_failed', 'user', $userId, null, [
                'email' => $email,
                'resend' => true,
            ]);
        }
PHP;
    $content = replaceOnce($content, $oldResendDelivery, $newResendDelivery, 'RegistrationService resend delivery');

    $newIssueMethod = <<<'PHP'
    /** @return array{token_id:int,raw_token:string} */
    private function issueToken(int $userId, ?int $locationId): array
    {
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add(new DateInterval('PT' . Env::int('EMAIL_VERIFICATION_TTL_MINUTES', 60) . 'M'))
            ->format('Y-m-d H:i:s');

        $insert = $this->pdo->prepare(
            'INSERT INTO email_verification_tokens (
                user_id, location_id, token_hash, expires_at,
                requested_ip, user_agent
             ) VALUES (
                :user_id, :location_id, :token_hash, :expires_at,
                :requested_ip, :user_agent
             )'
        );
        $insert->execute([
            'user_id' => $userId,
            'location_id' => $locationId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'requested_ip' => Http::clientIp(),
            'user_agent' => Http::userAgent(),
        ]);

        return [
            'token_id' => (int) $this->pdo->lastInsertId(),
            'raw_token' => $rawToken,
        ];
    }

    private function finalizeVerificationTokenDelivery(int $userId, int $tokenId, bool $delivered): void
    {
        try {
            if ($delivered) {
                $statement = $this->pdo->prepare(
                    'UPDATE email_verification_tokens
                     SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
                     WHERE user_id = :user_id
                       AND id <> :token_id
                       AND used_at IS NULL
                       AND revoked_at IS NULL'
                );
                $statement->execute(['user_id' => $userId, 'token_id' => $tokenId]);
                return;
            }

            $statement = $this->pdo->prepare(
                'UPDATE email_verification_tokens
                 SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
                 WHERE id = :token_id
                   AND user_id = :user_id
                   AND used_at IS NULL
                   AND revoked_at IS NULL'
            );
            $statement->execute(['token_id' => $tokenId, 'user_id' => $userId]);
        } catch (Throwable $exception) {
            $this->audit->safeRecord(
                'auth.email_verification.delivery_finalize_failed',
                'email_verification_token',
                $tokenId,
                $userId,
                ['delivered' => $delivered]
            );
        }
    }
PHP;

    $pattern = '~    private function issueToken\(int \\$userId, \?int \\$locationId\): string\n    \{.*?\n    \}\n\n    private function assertRegistrationRateLimit~s';
    $content = replaceRegexOnce($content, $pattern, $newIssueMethod . "\n\n    private function assertRegistrationRateLimit", 'RegistrationService issueToken method');

    writeStrict($path, $content);
    echo "OK RegistrationService.\n";
}

function patchUserInvitationService(string $path): void
{
    $content = readFileStrict($path);
    if (str_contains($content, 'user.invitation.delivery_finalize_failed')) {
        echo "SKIP UserInvitationService: ya integrado.\n";
        return;
    }

    $revokeBlock = <<<'PHP'
        $revoke = $this->pdo->prepare(
            'UPDATE user_invitation_tokens
             SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
             WHERE user_id = :user_id
               AND used_at IS NULL
               AND revoked_at IS NULL'
        );
        $revoke->execute(['user_id' => $userId]);

PHP;
    $content = replaceOnce($content, $revokeBlock, '', 'UserInvitationService pre-revoke');

    $content = replaceOnce(
        $content,
        "        return [\n            'user_id' => \$userId,",
        "        return [\n            'token_id' => \$tokenId,\n            'user_id' => \$userId,",
        'UserInvitationService token id return'
    );

    $newDeliver = <<<'PHP'
    /** @param array<string, mixed> $invitation */
    public function deliver(array $invitation): bool
    {
        $userId = (int) $invitation['user_id'];
        $tokenId = (int) $invitation['token_id'];

        try {
            $this->mailer->sendUserInvitationEmail(
                $userId,
                (string) $invitation['recipient_email'],
                (string) $invitation['recipient_name'],
                (string) $invitation['raw_token'],
                (string) $invitation['expires_at']
            );
            $this->finalizeDelivery($userId, $tokenId, true);
            return true;
        } catch (Throwable $exception) {
            $this->finalizeDelivery($userId, $tokenId, false);
            $this->audit->safeRecord(
                'user.invitation.delivery_failed',
                'user',
                $userId,
                null,
                ['email' => (string) $invitation['recipient_email']]
            );
            return false;
        }
    }

    private function finalizeDelivery(int $userId, int $tokenId, bool $delivered): void
    {
        try {
            if ($delivered) {
                $statement = $this->pdo->prepare(
                    'UPDATE user_invitation_tokens
                     SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
                     WHERE user_id = :user_id
                       AND id <> :token_id
                       AND used_at IS NULL
                       AND revoked_at IS NULL'
                );
                $statement->execute(['user_id' => $userId, 'token_id' => $tokenId]);
                return;
            }

            $statement = $this->pdo->prepare(
                'UPDATE user_invitation_tokens
                 SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP())
                 WHERE id = :token_id
                   AND user_id = :user_id
                   AND used_at IS NULL
                   AND revoked_at IS NULL'
            );
            $statement->execute(['token_id' => $tokenId, 'user_id' => $userId]);
        } catch (Throwable $exception) {
            $this->audit->safeRecord(
                'user.invitation.delivery_finalize_failed',
                'user_invitation_token',
                $tokenId,
                null,
                ['user_id' => $userId, 'delivered' => $delivered]
            );
        }
    }
PHP;

    $pattern = '~    /\*\* @param array<string, mixed> \\$invitation \*/\n    public function deliver\(array \\$invitation\): bool\n    \{.*?\n    \}\n\n    /\*\* @return array<string, mixed> \*/\n    public function inspect~s';
    $content = replaceRegexOnce($content, $pattern, $newDeliver . "\n\n    /** @return array<string, mixed> */\n    public function inspect", 'UserInvitationService deliver');

    writeStrict($path, $content);
    echo "OK UserInvitationService.\n";
}

function patchUserAdminService(string $path): void
{
    $content = readFileStrict($path);
    if (str_contains($content, 'uit2.expires_at > UTC_TIMESTAMP()')) {
        echo "SKIP UserAdminService: ya integrado.\n";
        return;
    }

    $oldJoin = <<<'PHP'
                LEFT JOIN user_invitation_tokens uit
                  ON uit.id = (
                      SELECT MAX(uit2.id)
                      FROM user_invitation_tokens uit2
                      WHERE uit2.user_id = u.id
                  )
PHP;
    $newJoin = <<<'PHP'
                LEFT JOIN user_invitation_tokens uit
                  ON uit.id = COALESCE(
                      (
                          SELECT MAX(uit2.id)
                          FROM user_invitation_tokens uit2
                          WHERE uit2.user_id = u.id
                            AND uit2.used_at IS NULL
                            AND uit2.revoked_at IS NULL
                            AND uit2.expires_at > UTC_TIMESTAMP()
                      ),
                      (
                          SELECT MAX(uit3.id)
                          FROM user_invitation_tokens uit3
                          WHERE uit3.user_id = u.id
                      )
                  )
PHP;
    $content = replaceOnce($content, $oldJoin, $newJoin, 'UserAdminService invitation presentation');

    $content = replaceOnce(
        $content,
        "             WHERE user_id = :user_id\n             ORDER BY created_at DESC\n             LIMIT 1'",
        "             WHERE user_id = :user_id\n               AND used_at IS NULL\n               AND revoked_at IS NULL\n             ORDER BY created_at DESC\n             LIMIT 1'",
        'UserAdminService resend cooldown active token'
    );

    writeStrict($path, $content);
    echo "OK UserAdminService.\n";
}

function patchJsModule(string $path, string $kind): void
{
    $content = readFileStrict($path);
    if (str_contains($content, 'setupPasswordVisibility')) {
        echo "SKIP {$kind}: visibilidad ya integrada.\n";
        return;
    }

    $import = "import { setupPasswordVisibility } from '../components/passwordVisibility.js';\n";
    if ($kind === 'accept-invitation') {
        $needle = "import { apiRequest, getCsrfToken } from '../core/api.js';\nimport { formatDateTime } from '../utils/date.js';\n";
        $content = replaceOnce($content, $needle, $needle . $import, "{$kind} import");
    } else {
        $needle = $kind === 'userMenu'
            ? "import { apiRequest } from '../core/api.js';\nimport { setupDropdown } from './dropdown.js';\n"
            : ($kind === 'change-password'
                ? "import { apiRequest } from '../core/api.js';\n"
                : "import { apiRequest, getCsrfToken } from '../core/api.js';\n");
        $relativeImport = $kind === 'userMenu'
            ? "import { setupPasswordVisibility } from './passwordVisibility.js';\n"
            : $import;
        $content = replaceOnce($content, $needle, $needle . $relativeImport, "{$kind} import");
    }

    if ($kind === 'userMenu') {
        $content = replaceOnce(
            $content,
            "export function setupUserMenu(user) {\n",
            "export function setupUserMenu(user) {\n  setupPasswordVisibility();\n",
            'userMenu setup call'
        );
    } else {
        $firstConst = strpos($content, "\nconst ");
        if ($firstConst === false) throw new RuntimeException("{$kind}: no se encontró primer const");
        $content = substr($content, 0, $firstConst + 1) . "setupPasswordVisibility();\n\n" . substr($content, $firstConst + 1);
    }

    writeStrict($path, $content);
    echo "OK {$kind}.\n";
}

try {
    patchMailer($root . '/app/Services/MailerService.php');
    patchAuthService($root . '/app/Services/AuthService.php');
    patchRegistrationService($root . '/app/Services/RegistrationService.php');
    patchUserInvitationService($root . '/app/Services/UserInvitationService.php');
    patchUserAdminService($root . '/app/Services/UserAdminService.php');

    patchJsModule($root . '/public/assets/js/modules/login.js', 'login');
    patchJsModule($root . '/public/assets/js/modules/register.js', 'register');
    patchJsModule($root . '/public/assets/js/modules/accept-invitation.js', 'accept-invitation');
    patchJsModule($root . '/public/assets/js/modules/change-password.js', 'change-password');
    patchJsModule($root . '/public/assets/js/components/userMenu.js', 'userMenu');

    echo "OK: Account resilience patch aplicado.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "ERROR: " . $exception->getMessage() . PHP_EOL);
    exit(1);
}
