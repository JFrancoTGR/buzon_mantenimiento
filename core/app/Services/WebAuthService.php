<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;

final class WebAuthService
{
    public function __construct(
        private readonly AuthService $auth
    ) {
    }

    /** @return array<string, mixed> */
    public function requireUser(string $loginPath = '/login'): array
    {
        try {
            return $this->auth->currentUser();
        } catch (HttpException $exception) {
            if ($exception->status !== 401) {
                throw $exception;
            }

            header('Location: ' . $loginPath, true, 302);
            exit;
        }
    }

    public function redirectIfAuthenticated(string $destination = '/'): void
    {
        try {
            $this->auth->currentUser();
        } catch (HttpException $exception) {
            if ($exception->status === 401) {
                return;
            }

            throw $exception;
        }

        header('Location: ' . $destination, true, 302);
        exit;
    }
}