<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Env;
use PHPMailer\PHPMailer\PHPMailer;
use PDO;
use RuntimeException;
use Throwable;

final class MailerService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function assertReady(): void
    {
        $transport = strtolower(Env::get('MAIL_TRANSPORT', 'smtp'));

        if (!in_array($transport, ['smtp', 'native', 'log'], true)) {
            throw new RuntimeException('MAIL_TRANSPORT debe ser smtp, native o log.');
        }

        if ($transport === 'smtp') {
            if (!class_exists(PHPMailer::class)) {
                throw new RuntimeException(
                    'PHPMailer no está instalado. Ejecuta composer install --no-dev --optimize-autoloader.'
                );
            }

            Env::get('SMTP_HOST');
            Env::get('SMTP_FROM_ADDRESS');

            if (Env::bool('SMTP_AUTH', true)) {
                Env::get('SMTP_USERNAME');
                Env::get('SMTP_PASSWORD');
            }
        }

        if ($transport === 'native') {
            Env::get('SMTP_FROM_ADDRESS');
        }
    }

    /** @param array{code?:string,name?:string}|null $location */
    public function sendVerificationEmail(
        int $userId,
        string $recipientEmail,
        string $recipientName,
        string $rawToken,
        ?array $location = null
    ): void {
        $appUrl = rtrim(Env::get('APP_URL'), '/');
        $verificationUrl = $appUrl . '/verify-email.html?token=' . rawurlencode($rawToken);
        $expiresMinutes = Env::int('EMAIL_VERIFICATION_TTL_MINUTES', 60);
        $safeName = self::escape($recipientName);
        $safeUrl = self::escape($verificationUrl);
        $locationText = '';

        if (is_array($location) && ($location['name'] ?? '') !== '') {
            $safeLocation = self::escape((string) $location['name']);
            $locationText = "<p><strong>Ubicación del reporte:</strong> {$safeLocation}</p>";
        }

        $subject = 'Verifica tu cuenta | Plataforma de Mantenimiento';
        $html = <<<HTML
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>{$subject}</title></head>
<body style="margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#17202a;">
  <div style="max-width:620px;margin:0 auto;padding:32px 18px;">
    <div style="background:#ffffff;border:1px solid #d9dee3;padding:28px;">
      <div style="font-size:13px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#c31422;margin-bottom:18px;">Estrategia Urbana</div>
      <h1 style="font-size:24px;margin:0 0 14px;">Verifica tu correo electrónico</h1>
      <p>Hola {$safeName},</p>
      <p>Confirma tu correo para activar tu cuenta y generar reportes de mantenimiento.</p>
      {$locationText}
      <p style="margin:28px 0;">
        <a href="{$safeUrl}" style="display:inline-block;background:#c31422;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;">Verificar mi cuenta</a>
      </p>
      <p style="font-size:13px;color:#5f6b76;">El enlace vence en {$expiresMinutes} minutos y solo puede utilizarse una vez.</p>
      <p style="font-size:13px;color:#5f6b76;word-break:break-all;">Si el botón no funciona, copia esta dirección en tu navegador:<br>{$safeUrl}</p>
      <p style="font-size:13px;color:#5f6b76;">Si no solicitaste esta cuenta, puedes ignorar este mensaje.</p>
    </div>
  </div>
</body>
</html>
HTML;

        $text = "Hola {$recipientName},\n\n"
            . "Verifica tu correo para activar tu cuenta de la Plataforma de Mantenimiento.\n\n"
            . ($location !== null && ($location['name'] ?? '') !== ''
                ? 'Ubicación del reporte: ' . (string) $location['name'] . "\n\n"
                : '')
            . "Enlace: {$verificationUrl}\n\n"
            . "El enlace vence en {$expiresMinutes} minutos y solo puede utilizarse una vez.";

        $this->sendAndLog(
            null,
            $userId,
            $recipientEmail,
            $recipientName,
            'auth.email_verification.requested',
            $subject,
            $html,
            $text
        );
    }

    public function sendTicketCreatedConfirmationToReporter(
        int $ticketId,
        int $recipientUserId,
        string $recipientEmail,
        string $recipientName,
        string $folio,
        string $title,
        string $locationName,
        string $specificLocation,
        string $priorityName
    ): void {
        $appUrl = rtrim(Env::get('APP_URL'), '/');
        $ticketUrl = $appUrl . '/ticket.html?id=' . $ticketId;
        $safeTicketUrl = self::escape($ticketUrl);
        $subject = "Reporte {$folio} recibido | Plataforma de Mantenimiento";
        $safeName = self::escape($recipientName);
        $safeFolio = self::escape($folio);
        $safeTitle = self::escape($title);
        $safeLocation = self::escape($locationName);
        $safeSpecificLocation = self::escape($specificLocation);
        $safePriority = self::escape($priorityName);

        $html = self::emailShell(
            'Reporte recibido',
            <<<HTML
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
HTML
        );

        $text = "Hola {$recipientName},\n\n"
            . "Tu reporte fue registrado correctamente.\n"
            . "Folio: {$folio}\n"
            . "Reporte: {$title}\n"
            . "Ubicación: {$locationName}\n"
            . "Zona: {$specificLocation}\n"
            . "Prioridad: {$priorityName}\n\n"
            . "Consulta la plataforma en: {$ticketUrl}";

        $this->sendAndLog(
            $ticketId,
            $recipientUserId,
            $recipientEmail,
            $recipientName,
            'ticket.created.reporter_confirmation',
            $subject,
            $html,
            $text
        );
    }

    public function sendTicketCreatedToSupervisor(
        int $ticketId,
        int $recipientUserId,
        string $recipientEmail,
        string $recipientName,
        string $reporterName,
        string $folio,
        string $title,
        string $locationName,
        string $specificLocation,
        string $priorityName,
        int $attachmentsCount
    ): void {
        $appUrl = rtrim(Env::get('APP_URL'), '/');
        $ticketUrl = $appUrl . '/ticket.html?id=' . $ticketId;
        $safeTicketUrl = self::escape($ticketUrl);
        $subject = "Nuevo reporte asignado: {$folio}";
        $safeName = self::escape($recipientName);
        $safeReporter = self::escape($reporterName);
        $safeFolio = self::escape($folio);
        $safeTitle = self::escape($title);
        $safeLocation = self::escape($locationName);
        $safeSpecificLocation = self::escape($specificLocation);
        $safePriority = self::escape($priorityName);

        $html = self::emailShell(
            'Nuevo reporte asignado',
            <<<HTML
<p>Hola {$safeName},</p>
<p>Se te asignó un nuevo reporte de mantenimiento que requiere revisión.</p>
<table role="presentation" style="width:100%;border-collapse:collapse;margin:22px 0;">
  <tr><td style="padding:8px 0;color:#5f6b76;width:160px;">Folio</td><td style="padding:8px 0;font-weight:700;">{$safeFolio}</td></tr>
  <tr><td style="padding:8px 0;color:#5f6b76;">Reportado por</td><td style="padding:8px 0;">{$safeReporter}</td></tr>
  <tr><td style="padding:8px 0;color:#5f6b76;">Reporte</td><td style="padding:8px 0;">{$safeTitle}</td></tr>
  <tr><td style="padding:8px 0;color:#5f6b76;">Ubicación</td><td style="padding:8px 0;">{$safeLocation}</td></tr>
  <tr><td style="padding:8px 0;color:#5f6b76;">Zona</td><td style="padding:8px 0;">{$safeSpecificLocation}</td></tr>
  <tr><td style="padding:8px 0;color:#5f6b76;">Prioridad</td><td style="padding:8px 0;">{$safePriority}</td></tr>
  <tr><td style="padding:8px 0;color:#5f6b76;">Evidencias</td><td style="padding:8px 0;">{$attachmentsCount}</td></tr>
</table>
<p style="margin:28px 0;"><a href="{$safeTicketUrl}" style="display:inline-block;background:#c31422;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;">Revisar reporte</a></p>
HTML
        );

        $text = "Hola {$recipientName},\n\n"
            . "Se te asignó un nuevo reporte de mantenimiento.\n"
            . "Folio: {$folio}\n"
            . "Reportado por: {$reporterName}\n"
            . "Reporte: {$title}\n"
            . "Ubicación: {$locationName}\n"
            . "Zona: {$specificLocation}\n"
            . "Prioridad: {$priorityName}\n"
            . "Evidencias: {$attachmentsCount}\n\n"
            . "Revisa la plataforma en: {$ticketUrl}";

        $this->sendAndLog(
            $ticketId,
            $recipientUserId,
            $recipientEmail,
            $recipientName,
            'ticket.created.supervisor_notification',
            $subject,
            $html,
            $text
        );
    }

    public function sendTicketCommentNotification(
        int $ticketId,
        int $recipientUserId,
        string $recipientEmail,
        string $recipientName,
        string $actorName,
        string $folio,
        string $title,
        string $comment
    ): void {
        $appUrl = rtrim(Env::get('APP_URL'), '/');
        $ticketUrl = $appUrl . '/ticket.html?id=' . $ticketId;
        $safeTicketUrl = self::escape($ticketUrl);
        $safeName = self::escape($recipientName);
        $safeActor = self::escape($actorName);
        $safeFolio = self::escape($folio);
        $safeTitle = self::escape($title);
        $safeComment = nl2br(self::escape($comment));
        $subject = "Nuevo comentario en {$folio}";

        $html = self::emailShell(
            'Nuevo comentario',
            <<<HTML
<p>Hola {$safeName},</p>
<p><strong>{$safeActor}</strong> agregó un comentario al reporte <strong>{$safeFolio}</strong>.</p>
<p style="margin:18px 0 8px;color:#5f6b76;">{$safeTitle}</p>
<div style="padding:16px;border-left:4px solid #c31422;background:#f6f8fa;line-height:1.55;">{$safeComment}</div>
<p style="margin:28px 0;"><a href="{$safeTicketUrl}" style="display:inline-block;background:#c31422;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;">Ver ticket</a></p>
HTML
        );

        $text = "Hola {$recipientName},\n\n"
            . "{$actorName} agregó un comentario al reporte {$folio}.\n"
            . "Reporte: {$title}\n\n"
            . "{$comment}\n\n"
            . "Consulta el ticket en: {$ticketUrl}";

        $this->sendAndLog(
            $ticketId,
            $recipientUserId,
            $recipientEmail,
            $recipientName,
            'ticket.comment.notification',
            $subject,
            $html,
            $text
        );
    }

    public function sendTicketStatusChangedNotification(
        int $ticketId,
        int $recipientUserId,
        string $recipientEmail,
        string $recipientName,
        string $actorName,
        string $folio,
        string $title,
        string $statusName,
        ?string $comment = null
    ): void {
        $appUrl = rtrim(Env::get('APP_URL'), '/');
        $ticketUrl = $appUrl . '/ticket.html?id=' . $ticketId;
        $safeTicketUrl = self::escape($ticketUrl);
        $safeName = self::escape($recipientName);
        $safeActor = self::escape($actorName);
        $safeFolio = self::escape($folio);
        $safeTitle = self::escape($title);
        $safeStatus = self::escape($statusName);
        $commentHtml = $comment !== null && trim($comment) !== ''
            ? '<div style="padding:16px;border-left:4px solid #c31422;background:#f6f8fa;line-height:1.55;">' . nl2br(self::escape($comment)) . '</div>'
            : '';
        $subject = "Actualización de {$folio}: {$statusName}";

        $html = self::emailShell(
            'Estado del reporte actualizado',
            <<<HTML
<p>Hola {$safeName},</p>
<p><strong>{$safeActor}</strong> actualizó el reporte <strong>{$safeFolio}</strong>.</p>
<table role="presentation" style="width:100%;border-collapse:collapse;margin:22px 0;">
  <tr><td style="padding:8px 0;color:#5f6b76;width:160px;">Reporte</td><td style="padding:8px 0;">{$safeTitle}</td></tr>
  <tr><td style="padding:8px 0;color:#5f6b76;">Nuevo estado</td><td style="padding:8px 0;font-weight:700;">{$safeStatus}</td></tr>
</table>
{$commentHtml}
<p style="margin:28px 0;"><a href="{$safeTicketUrl}" style="display:inline-block;background:#c31422;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;">Ver ticket</a></p>
HTML
        );

        $text = "Hola {$recipientName},\n\n"
            . "{$actorName} actualizó el reporte {$folio}.\n"
            . "Reporte: {$title}\n"
            . "Nuevo estado: {$statusName}\n"
            . ($comment !== null && trim($comment) !== '' ? "Comentario: {$comment}\n" : '')
            . "\nConsulta el ticket en: {$ticketUrl}";

        $this->sendAndLog(
            $ticketId,
            $recipientUserId,
            $recipientEmail,
            $recipientName,
            'ticket.status.notification',
            $subject,
            $html,
            $text
        );
    }

    public function sendAuthorizationRequestToDirector(
        int $ticketId,
        int $recipientUserId,
        string $recipientEmail,
        string $recipientName,
        string $requesterName,
        string $folio,
        string $title,
        int $quotationVersion,
        string $supplierName,
        string $requestedAmount,
        string $currency,
        string $requestComment
    ): void {
        $appUrl = rtrim(Env::get('APP_URL'), '/');
        $ticketUrl = $appUrl . '/ticket.html?id=' . $ticketId;
        $safeTicketUrl = self::escape($ticketUrl);
        $safeName = self::escape($recipientName);
        $safeRequester = self::escape($requesterName);
        $safeFolio = self::escape($folio);
        $safeTitle = self::escape($title);
        $safeSupplier = self::escape($supplierName);
        $safeCurrency = self::escape($currency);
        $safeComment = nl2br(self::escape($requestComment));
        $amount = number_format((float) $requestedAmount, 2, '.', ',');
        $safeAmount = self::escape('$' . $amount . ' ' . $currency);
        $subject = "Autorización requerida: {$folio}";

        $html = self::emailShell(
            'Solicitud de autorización',
            <<<HTML
<p>Hola {$safeName},</p>
<p><strong>{$safeRequester}</strong> envió el reporte <strong>{$safeFolio}</strong> para tu autorización.</p>
<table role="presentation" style="width:100%;border-collapse:collapse;margin:22px 0;">
  <tr><td style="padding:8px 0;color:#5f6b76;width:180px;">Reporte</td><td style="padding:8px 0;">{$safeTitle}</td></tr>
  <tr><td style="padding:8px 0;color:#5f6b76;">Cotización</td><td style="padding:8px 0;font-weight:700;">Versión {$quotationVersion} · {$safeSupplier}</td></tr>
  <tr><td style="padding:8px 0;color:#5f6b76;">Importe solicitado</td><td style="padding:8px 0;font-weight:700;">{$safeAmount}</td></tr>
</table>
<div style="padding:16px;border-left:4px solid #c31422;background:#f6f8fa;line-height:1.55;">{$safeComment}</div>
<p style="margin:28px 0;"><a href="{$safeTicketUrl}" style="display:inline-block;background:#c31422;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;">Revisar solicitud</a></p>
HTML
        );

        $text = "Hola {$recipientName},\n\n"
            . "{$requesterName} envió el reporte {$folio} para tu autorización.\n"
            . "Reporte: {$title}\n"
            . "Cotización: versión {$quotationVersion} · {$supplierName}\n"
            . "Importe solicitado: $" . $amount . " {$currency}\n"
            . "Justificación: {$requestComment}\n\n"
            . "Revisa la solicitud en: {$ticketUrl}";

        $this->sendAndLog(
            $ticketId,
            $recipientUserId,
            $recipientEmail,
            $recipientName,
            'ticket.authorization.requested',
            $subject,
            $html,
            $text
        );
    }

    public function sendAuthorizationDecisionNotification(
        int $ticketId,
        int $recipientUserId,
        string $recipientEmail,
        string $recipientName,
        string $directorName,
        string $folio,
        string $title,
        string $decision,
        string $requestedAmount,
        ?string $approvedAmount,
        string $currency,
        string $decisionComment
    ): void {
        $appUrl = rtrim(Env::get('APP_URL'), '/');
        $ticketUrl = $appUrl . '/ticket.html?id=' . $ticketId;
        $safeTicketUrl = self::escape($ticketUrl);
        $safeName = self::escape($recipientName);
        $safeDirector = self::escape($directorName);
        $safeFolio = self::escape($folio);
        $safeTitle = self::escape($title);
        $safeCurrency = self::escape($currency);
        $safeComment = nl2br(self::escape($decisionComment));
        $requested = number_format((float) $requestedAmount, 2, '.', ',');
        $approved = $approvedAmount !== null ? number_format((float) $approvedAmount, 2, '.', ',') : null;

        $decisionData = match ($decision) {
            'approved' => [
                'heading' => 'Solicitud autorizada',
                'subject' => "Autorización aprobada: {$folio}",
                'summary' => "{$safeDirector} autorizó la solicitud de mantenimiento.",
                'event' => 'ticket.authorization.approved',
            ],
            'changes_requested' => [
                'heading' => 'Cambios solicitados',
                'subject' => "Cambios solicitados: {$folio}",
                'summary' => "{$safeDirector} solicitó cambios a la propuesta antes de autorizarla.",
                'event' => 'ticket.authorization.changes_requested',
            ],
            default => [
                'heading' => 'Solicitud rechazada',
                'subject' => "Autorización rechazada: {$folio}",
                'summary' => "{$safeDirector} rechazó la solicitud de autorización.",
                'event' => 'ticket.authorization.rejected',
            ],
        };

        $amountRows = '<tr><td style="padding:8px 0;color:#5f6b76;width:180px;">Importe solicitado</td><td style="padding:8px 0;font-weight:700;">$'
            . self::escape($requested) . ' ' . $safeCurrency . '</td></tr>';
        if ($approved !== null) {
            $amountRows .= '<tr><td style="padding:8px 0;color:#5f6b76;">Importe autorizado</td><td style="padding:8px 0;font-weight:700;">$'
                . self::escape($approved) . ' ' . $safeCurrency . '</td></tr>';
        }

        $html = self::emailShell(
            $decisionData['heading'],
            <<<HTML
<p>Hola {$safeName},</p>
<p>{$decisionData['summary']}</p>
<table role="presentation" style="width:100%;border-collapse:collapse;margin:22px 0;">
  <tr><td style="padding:8px 0;color:#5f6b76;width:180px;">Reporte</td><td style="padding:8px 0;font-weight:700;">{$safeFolio}</td></tr>
  <tr><td style="padding:8px 0;color:#5f6b76;">Descripción</td><td style="padding:8px 0;">{$safeTitle}</td></tr>
  {$amountRows}
</table>
<div style="padding:16px;border-left:4px solid #c31422;background:#f6f8fa;line-height:1.55;">{$safeComment}</div>
<p style="margin:28px 0;"><a href="{$safeTicketUrl}" style="display:inline-block;background:#c31422;color:#ffffff;text-decoration:none;font-weight:700;padding:13px 18px;">Revisar ticket</a></p>
HTML
        );

        $decisionText = match ($decision) {
            'approved' => 'Autorizada',
            'changes_requested' => 'Cambios solicitados',
            default => 'Rechazada',
        };
        $text = "Hola {$recipientName},\n\n"
            . "Decisión: {$decisionText}\n"
            . "Dirección: {$directorName}\n"
            . "Reporte: {$folio} · {$title}\n"
            . "Importe solicitado: $" . $requested . " {$currency}\n"
            . ($approved !== null ? "Importe autorizado: $" . $approved . " {$currency}\n" : '')
            . "Comentario: {$decisionComment}\n\n"
            . "Consulta el ticket en: {$ticketUrl}";

        $this->sendAndLog(
            $ticketId,
            $recipientUserId,
            $recipientEmail,
            $recipientName,
            $decisionData['event'],
            $decisionData['subject'],
            $html,
            $text
        );
    }

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
    public function sendTestEmail(string $recipientEmail): void
    {
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo de prueba no es válido.');
        }

        $this->sendAndLog(
            null,
            null,
            $recipientEmail,
            'Prueba de correo',
            'system.email.test',
            'Prueba de correo | Plataforma de Mantenimiento',
            '<p>La configuración de correo de la Plataforma de Mantenimiento funciona correctamente.</p>',
            'La configuración de correo de la Plataforma de Mantenimiento funciona correctamente.'
        );
    }

    private function sendAndLog(
        ?int $ticketId,
        ?int $recipientUserId,
        string $recipientEmail,
        string $recipientName,
        string $eventCode,
        string $subject,
        string $htmlBody,
        string $textBody
    ): void {
        try {
            $this->assertReady();
            $transport = strtolower(Env::get('MAIL_TRANSPORT', 'smtp'));

            match ($transport) {
                'smtp' => $this->sendWithSmtp($recipientEmail, $recipientName, $subject, $htmlBody, $textBody),
                'native' => $this->sendWithNativeMail($recipientEmail, $subject, $htmlBody),
                'log' => $this->writeToLog($recipientEmail, $subject, $htmlBody, $textBody),
                default => throw new RuntimeException('Transporte de correo no soportado.'),
            };

            $this->recordEmailLog(
                $ticketId,
                $recipientUserId,
                $recipientEmail,
                $eventCode,
                $subject,
                'sent',
                null
            );
        } catch (Throwable $exception) {
            $this->recordEmailLog(
                $ticketId,
                $recipientUserId,
                $recipientEmail,
                $eventCode,
                $subject,
                'failed',
                substr($exception->getMessage(), 0, 2000)
            );
            throw new RuntimeException('No fue posible enviar el correo electrónico.', 0, $exception);
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
        $mailer->Host = Env::get('SMTP_HOST');
        $mailer->Port = Env::int('SMTP_PORT', 587);
        $mailer->SMTPAuth = Env::bool('SMTP_AUTH', true);

        if ($mailer->SMTPAuth) {
            $mailer->Username = Env::get('SMTP_USERNAME');
            $mailer->Password = Env::get('SMTP_PASSWORD');
        }

        $encryption = strtolower(Env::get('SMTP_ENCRYPTION', 'tls'));
        if (in_array($encryption, ['tls', 'starttls'], true)) {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } elseif (in_array($encryption, ['ssl', 'smtps'], true)) {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (in_array($encryption, ['none', ''], true)) {
            $mailer->SMTPSecure = '';
            $mailer->SMTPAutoTLS = false;
        } else {
            throw new RuntimeException('SMTP_ENCRYPTION debe ser tls, ssl o none.');
        }

        $mailer->Timeout = Env::int('SMTP_TIMEOUT_SECONDS', 15);
        $mailer->setFrom(
            Env::get('SMTP_FROM_ADDRESS'),
            Env::get('SMTP_FROM_NAME', 'Plataforma de Mantenimiento')
        );
        $mailer->addAddress($recipientEmail, $recipientName);
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $textBody;
        $mailer->send();
    }

    private function sendWithNativeMail(string $recipientEmail, string $subject, string $htmlBody): void
    {
        $fromAddress = Env::get('SMTP_FROM_ADDRESS');
        $fromName = Env::get('SMTP_FROM_NAME', 'Plataforma de Mantenimiento');
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'Reply-To: ' . $fromAddress,
        ];

        $encodedSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8')
            : $subject;

        if (!mail($recipientEmail, $encodedSubject, $htmlBody, implode("\r\n", $headers))) {
            throw new RuntimeException('La función mail() devolvió un resultado fallido.');
        }
    }

    private function writeToLog(
        string $recipientEmail,
        string $subject,
        string $htmlBody,
        string $textBody
    ): void {
        $logPath = dirname(__DIR__, 2) . '/storage/logs/mail.log';
        $directory = dirname($logPath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('No fue posible crear el directorio de logs de correo.');
        }

        $record = [
            'timestamp_utc' => gmdate('c'),
            'recipient' => $recipientEmail,
            'subject' => $subject,
            'text' => $textBody,
            'html' => $htmlBody,
        ];

        if (file_put_contents(
            $logPath,
            json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        ) === false) {
            throw new RuntimeException('No fue posible escribir el correo en el log.');
        }
    }

    private function recordEmailLog(
        ?int $ticketId,
        ?int $recipientUserId,
        string $recipientEmail,
        string $eventCode,
        string $subject,
        string $status,
        ?string $errorMessage
    ): void {
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO email_log (
                    ticket_id, recipient_user_id, recipient_email, event_code,
                    subject, status, error_message, sent_at
                 ) VALUES (
                    :ticket_id, :recipient_user_id, :recipient_email, :event_code,
                    :subject, :status, :error_message,
                    CASE WHEN :status_for_date = \'sent\' THEN UTC_TIMESTAMP() ELSE NULL END
                 )'
            );
            $statement->execute([
                'ticket_id' => $ticketId,
                'recipient_user_id' => $recipientUserId,
                'recipient_email' => $recipientEmail,
                'event_code' => $eventCode,
                'subject' => $subject,
                'status' => $status,
                'error_message' => $errorMessage,
                'status_for_date' => $status,
            ]);
        } catch (Throwable $exception) {
            error_log('No fue posible registrar email_log: ' . $exception->getMessage());
        }
    }

    private static function emailShell(string $title, string $content): string
    {
        $safeTitle = self::escape($title);
        return <<<HTML
<!doctype html>
<html lang="es">
<head><meta charset="utf-8"><title>{$safeTitle}</title></head>
<body style="margin:0;background:#f4f6f8;font-family:Arial,sans-serif;color:#17202a;">
  <div style="max-width:640px;margin:0 auto;padding:32px 18px;">
    <div style="background:#ffffff;border:1px solid #d9dee3;padding:28px;">
      <div style="font-size:13px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#c31422;margin-bottom:18px;">Estrategia Urbana</div>
      <h1 style="font-size:24px;margin:0 0 14px;">{$safeTitle}</h1>
      {$content}
      <p style="margin:28px 0 0;font-size:13px;color:#5f6b76;">Plataforma de Gestión de Mantenimiento</p>
    </div>
  </div>
</body>
</html>
HTML;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
