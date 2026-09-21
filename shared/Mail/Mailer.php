<?php

declare(strict_types=1);

namespace EUTools\Shared\Mail;

use PDO;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Throwable;

final class Mailer
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var array<string, int> */
    private array $applicationIds = [];

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly PDO $pdo,
        private readonly TemplateRegistry $templates,
        array $config
    ) {
        $this->config = $config;
    }

    public function assertReady(): void
    {
        $transport = $this->stringConfig('transport', 'smtp');

        if (!in_array($transport, ['smtp', 'native', 'log'], true)) {
            throw new RuntimeException(
                'El transporte de correo debe ser smtp, native o log.'
            );
        }

        if ($transport === 'smtp') {
            if (!class_exists(PHPMailer::class)) {
                throw new RuntimeException('PHPMailer no está disponible.');
            }

            $this->requiredStringConfig('smtp_host');
            $this->requiredStringConfig('from_address');

            if ($this->boolConfig('smtp_auth', true)) {
                $this->requiredStringConfig('smtp_username');
                $this->requiredStringConfig('smtp_password');
            }
        }

        if ($transport === 'native') {
            $this->requiredStringConfig('from_address');
        }
    }

    public function send(MailMessage $message): void
    {
        $applicationId = $this->resolveApplicationId(
            $message->applicationCode
        );

        $subject = $message->eventCode;

        try {
            $rendered = $this->templates->render($message);
            $subject = $rendered['subject'];

            $this->assertReady();

            $transport = $this->stringConfig('transport', 'smtp');

            match ($transport) {
                'smtp' => $this->sendWithSmtp(
                    $message->recipientEmail,
                    $message->recipientName,
                    $subject,
                    $rendered['html'],
                    $rendered['text']
                ),

                'native' => $this->sendWithNativeMail(
                    $message->recipientEmail,
                    $subject,
                    $rendered['html']
                ),

                'log' => $this->writeToLog(
                    $message->recipientEmail,
                    $subject,
                    $rendered['html'],
                    $rendered['text']
                ),
            };

            $this->recordEmailLog(
                $applicationId,
                $message,
                $subject,
                'sent',
                null
            );
        } catch (Throwable $exception) {
            $this->recordEmailLog(
                $applicationId,
                $message,
                $subject,
                'failed',
                substr($exception->getMessage(), 0, 2000)
            );

            throw new RuntimeException(
                'No fue posible enviar el correo electrónico.',
                0,
                $exception
            );
        }
    }

    private function sendWithSmtp(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $htmlBody,
        string $textBody
    ): void {
        $mailer = new PHPMailer(true);

        $mailer->CharSet = 'UTF-8';
        $mailer->isSMTP();

        $mailer->Host = $this->requiredStringConfig('smtp_host');
        $mailer->Port = $this->intConfig('smtp_port', 587);
        $mailer->SMTPAuth = $this->boolConfig('smtp_auth', true);

        if ($mailer->SMTPAuth) {
            $mailer->Username =
                $this->requiredStringConfig('smtp_username');

            $mailer->Password =
                $this->requiredStringConfig('smtp_password');
        }

        $encryption = $this->stringConfig(
            'smtp_encryption',
            'tls'
        );

        if (in_array($encryption, ['tls', 'starttls'], true)) {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (in_array($encryption, ['ssl', 'smtps'], true)) {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (in_array($encryption, ['none', ''], true)) {
            $mailer->SMTPSecure = '';
            $mailer->SMTPAutoTLS = false;
        } else {
            throw new RuntimeException(
                'smtp_encryption debe ser tls, ssl o none.'
            );
        }

        $mailer->Timeout =
            $this->intConfig('smtp_timeout_seconds', 15);

        $mailer->setFrom(
            $this->requiredStringConfig('from_address'),
            $this->stringConfig(
                'from_name',
                'Estrategia Urbana Tools'
            )
        );

        $mailer->addAddress(
            $recipientEmail,
            $recipientName
        );

        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $textBody;

        $mailer->send();
    }

    private function sendWithNativeMail(
        string $recipientEmail,
        string $subject,
        string $htmlBody
    ): void {
        $fromAddress =
            $this->requiredStringConfig('from_address');

        $fromName = $this->stringConfig(
            'from_name',
            'Estrategia Urbana Tools'
        );

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'Reply-To: ' . $fromAddress,
        ];

        $encodedSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8')
            : $subject;

        if (!mail(
            $recipientEmail,
            $encodedSubject,
            $htmlBody,
            implode("\r\n", $headers)
        )) {
            throw new RuntimeException(
                'La función mail() devolvió un resultado fallido.'
            );
        }
    }

    private function writeToLog(
        string $recipientEmail,
        string $subject,
        string $htmlBody,
        string $textBody
    ): void {
        $logPath = $this->stringConfig(
            'log_path',
            dirname(__DIR__) . '/storage/logs/mail.log'
        );

        $directory = dirname($logPath);

        if (
            !is_dir($directory)
            && !mkdir($directory, 0770, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                'No fue posible crear el directorio de logs de correo.'
            );
        }

        $record = [
            'timestamp_utc' => gmdate('c'),
            'recipient' => $recipientEmail,
            'subject' => $subject,
            'text' => $textBody,
            'html' => $htmlBody,
        ];

        $written = file_put_contents(
            $logPath,
            json_encode(
                $record,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR
            ) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        if ($written === false) {
            throw new RuntimeException(
                'No fue posible escribir el correo en el log.'
            );
        }
    }

    private function resolveApplicationId(
        string $applicationCode
    ): int {
        if (isset($this->applicationIds[$applicationCode])) {
            return $this->applicationIds[$applicationCode];
        }

        $statement = $this->pdo->prepare(
            'SELECT id
             FROM applications
             WHERE code = :code
             LIMIT 1'
        );

        $statement->execute([
            'code' => $applicationCode,
        ]);

        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new RuntimeException(
                "No existe la aplicación {$applicationCode} "
                . 'en el catálogo global.'
            );
        }

        $this->applicationIds[$applicationCode] = (int) $id;

        return $this->applicationIds[$applicationCode];
    }

    private function recordEmailLog(
        int $applicationId,
        MailMessage $message,
        string $subject,
        string $status,
        ?string $errorMessage
    ): void {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO email_log (
                    application_id,
                    recipient_user_id,
                    recipient_email,
                    event_code,
                    entity_type,
                    entity_id,
                    subject,
                    status,
                    error_message,
                    sent_at
                 ) VALUES (
                    :application_id,
                    :recipient_user_id,
                    :recipient_email,
                    :event_code,
                    :entity_type,
                    :entity_id,
                    :subject,
                    :status,
                    :error_message,
                    CASE
                        WHEN :status_for_date = \'sent\'
                        THEN UTC_TIMESTAMP()
                        ELSE NULL
                    END
                 )'
            );

            $statement->execute([
                'application_id' => $applicationId,
                'recipient_user_id' => $message->recipientUserId,
                'recipient_email' => $message->recipientEmail,
                'event_code' => $message->eventCode,
                'entity_type' => $message->entityType,
                'entity_id' => $message->entityId,
                'subject' => $subject,
                'status' => $status,
                'error_message' => $errorMessage,
                'status_for_date' => $status,
            ]);
        } catch (Throwable $exception) {
            error_log(
                'No fue posible registrar email_log global: '
                . $exception->getMessage()
            );
        }
    }

    private function requiredStringConfig(
        string $key
    ): string {
        $value = $this->config[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(
                "Falta la configuración requerida de correo: {$key}."
            );
        }

        return trim($value);
    }

    private function stringConfig(
        string $key,
        string $default
    ): string {
        $value = $this->config[$key] ?? $default;

        return is_string($value)
            ? trim($value)
            : $default;
    }

    private function intConfig(
        string $key,
        int $default
    ): int {
        $value = $this->config[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        if (
            is_string($value)
            && filter_var($value, FILTER_VALIDATE_INT) !== false
        ) {
            return (int) $value;
        }

        throw new RuntimeException(
            "La configuración {$key} debe ser un entero."
        );
    }

    private function boolConfig(
        string $key,
        bool $default
    ): bool {
        $value = $this->config[$key] ?? $default;

        if (is_bool($value)) {
            return $value;
        }

        if (
            is_int($value)
            && in_array($value, [0, 1], true)
        ) {
            return (bool) $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return match ($normalized) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off' => false,

                default => throw new RuntimeException(
                    "La configuración {$key} debe ser booleana."
                ),
            };
        }

        throw new RuntimeException(
            "La configuración {$key} debe ser booleana."
        );
    }
}