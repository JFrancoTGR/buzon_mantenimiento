<?php

declare(strict_types=1);

namespace EUTools\Shared\Mail;

use InvalidArgumentException;

final class MailMessage
{
    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly string $applicationCode,
        public readonly string $eventCode,
        public readonly string $template,
        public readonly ?int $recipientUserId,
        public readonly string $recipientEmail,
        public readonly string $recipientName,
        public readonly array $context = [],
        public readonly ?string $entityType = null,
        public readonly ?int $entityId = null
    ) {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $this->applicationCode)) {
            throw new InvalidArgumentException('applicationCode contiene un formato inválido.');
        }

        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $this->eventCode)) {
            throw new InvalidArgumentException('eventCode contiene un formato inválido.');
        }

        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $this->template)) {
            throw new InvalidArgumentException('template contiene un formato inválido.');
        }

        if (!filter_var($this->recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('recipientEmail no es válido.');
        }

        if ($this->recipientUserId !== null && $this->recipientUserId < 1) {
            throw new InvalidArgumentException('recipientUserId debe ser positivo o null.');
        }

        if (
            $this->entityType !== null
            && !preg_match('/^[a-z0-9][a-z0-9._-]*$/', $this->entityType)
        ) {
            throw new InvalidArgumentException('entityType contiene un formato inválido.');
        }

        if ($this->entityId !== null && $this->entityId < 1) {
            throw new InvalidArgumentException('entityId debe ser positivo o null.');
        }
    }
}