<?php

declare(strict_types=1);

namespace App\Services;

final class TicketPresentationPolicy
{
    /** @param array<string, mixed> $user */
    public static function isReporter(array $user): bool
    {
        $roles = $user['roles'] ?? [];
        return is_array($roles) && in_array('reporter', $roles, true);
    }

    /** @return array{code:string,name:string,is_terminal:bool} */
    public static function publicStatus(string $code, string $fallbackName = '', bool $isTerminal = false): array
    {
        return match ($code) {
            'new' => ['code' => 'new', 'name' => 'Nuevo', 'is_terminal' => false],
            'under_review', 'quotation_pending', 'authorization_pending', 'changes_requested', 'authorized' => [
                'code' => 'under_review',
                'name' => 'En gestión',
                'is_terminal' => false,
            ],
            'in_progress' => ['code' => 'in_progress', 'name' => 'En proceso', 'is_terminal' => false],
            'completed', 'closed' => ['code' => 'closed', 'name' => 'Trabajo terminado', 'is_terminal' => true],
            default => [
                'code' => $code,
                'name' => $fallbackName !== '' ? $fallbackName : $code,
                'is_terminal' => $isTerminal,
            ],
        };
    }

    /** @return array<int, array{code:string,name:string,lifecycle_group:string,is_terminal:bool}> */
    public static function publicStatusCatalog(): array
    {
        return [
            ['code' => 'new', 'name' => 'Nuevo', 'lifecycle_group' => 'open', 'is_terminal' => false],
            ['code' => 'under_review', 'name' => 'En gestión', 'lifecycle_group' => 'open', 'is_terminal' => false],
            ['code' => 'in_progress', 'name' => 'En proceso', 'lifecycle_group' => 'open', 'is_terminal' => false],
            ['code' => 'closed', 'name' => 'Trabajo terminado', 'lifecycle_group' => 'closed', 'is_terminal' => true],
        ];
    }

    /** @return array<int, string> */
    public static function internalCodesForPublicStatus(string $publicCode): array
    {
        return match ($publicCode) {
            'new' => ['new'],
            'under_review' => ['under_review', 'quotation_pending', 'authorization_pending', 'changes_requested', 'authorized'],
            'in_progress' => ['in_progress'],
            'closed' => ['completed', 'closed'],
            default => [$publicCode],
        };
    }
}
