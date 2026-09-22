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
    public function requireUser(
        string $loginPath = '/login',
        bool $allowPasswordChangeRequired = false
    ): array {
        try {
            $user = $this->auth->currentUser();
        } catch (HttpException $exception) {
            if ($exception->status !== 401) {
                throw $exception;
            }

            header('Location: ' . $loginPath, true, 302);
            exit;
        }

        if (
            !$allowPasswordChangeRequired
            && (bool) ($user['must_change_password'] ?? false)
        ) {
            header('Location: /change-password', true, 302);
            exit;
        }

        return $user;
    }

    public function redirectIfAuthenticated(
        string $destination = '/'
    ): void {
        try {
            $user = $this->auth->currentUser();
        } catch (HttpException $exception) {
            if ($exception->status === 401) {
                return;
            }

            throw $exception;
        }

        $target = (bool) ($user['must_change_password'] ?? false)
            ? '/change-password'
            : $destination;

        header('Location: ' . $target, true, 302);
        exit;
    }
}
