<?php

declare(strict_types=1);

namespace EUTools\Shared\Security;

use RuntimeException;

final class CsrfException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }
}
