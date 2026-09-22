<?php

declare(strict_types=1);

use App\Core\Http;
use App\Services\AuthorizationService;

$services = require dirname(__DIR__, 3) . '/bootstrap/app.php';
Http::requireMethod('GET');
$user = $services['maintenance_context']->currentUser();
AuthorizationService::requirePasswordChanged($user);
$attachmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!is_int($attachmentId) || $attachmentId < 1) {
    throw new App\Exceptions\HttpException(404, 'attachment_not_found', 'El archivo no existe.');
}
$file = $services['ticket_detail']->attachment($user, $attachmentId);
$download = filter_input(INPUT_GET, 'download', FILTER_VALIDATE_BOOL);
$disposition = $download ? 'attachment' : 'inline';
$safeName = str_replace(["\r", "\n", '"'], ['', '', "'"], (string) $file['original_name']);
header('Content-Type: ' . (string) $file['mime_type']);
header('Content-Length: ' . (string) $file['size_bytes']);
header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($safeName));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile((string) $file['absolute_path']);
exit;
