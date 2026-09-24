<?php

declare(strict_types=1);

namespace EUTools\Core\Security;

use RuntimeException;

final class SessionException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}
