<?php

declare(strict_types=1);

namespace App\Services;

final class TicketPresentationPolicy
{
    /**
     * Reporter presentation is Location-aware.
     *
     * With an explicit Location, the effective grant at that Location
     * decides the presentation.
     *
     * Without a Location, the user is considered a reporter-only viewer
     * only when every active territorial grant is Reporter. This keeps a
     * mixed-profile user from being forced into Reporter presentation
     * globally.
     *
     * @param array<string, mixed> $user
     */
    public static function isReporter(
        array $user,
        ?int $locationId = null
    ): bool {
        if ($locationId !== null && $locationId > 0) {
            return AuthorizationService::hasRoleAtLocation(
                $user,
                'reporter',
                $locationId
            );
        }

        $grants = $user['grants'] ?? [];

        if (! is_array($grants) || $grants === []) {
            return false;
        }

        $found = false;

        foreach ($grants as $grant) {
            if (! is_array($grant)) {
                continue;
            }

            $role = $grant['role'] ?? null;

            if (! is_array($role)) {
                continue;
            }

            $roleCode = (string) ($role['code'] ?? '');

            if ($roleCode === '') {
                continue;
            }

            $found = true;

            if ($roleCode !== 'reporter') {
                return false;
            }
        }

        return $found;
    }

    /** @return array{code:string,name:string,is_terminal:bool} */
    public static function publicStatus(
        string $code,
        string $fallbackName = '',
        bool $isTerminal = false
    ): array {
        return match ($code) {
            'new' => [
                'code' => 'new',
                'name' => 'Nuevo',
                'is_terminal' => false,
            ],
            'under_review',
            'quotation_pending',
            'authorization_pending',
            'changes_requested',
            'authorized' => [
                'code' => 'under_review',
                'name' => 'En gestión',
                'is_terminal' => false,
            ],
            'in_progress' => [
                'code' => 'in_progress',
                'name' => 'En proceso',
                'is_terminal' => false,
            ],
            'completed',
            'closed' => [
                'code' => 'closed',
                'name' => 'Trabajo terminado',
                'is_terminal' => true,
            ],
            default => [
                'code' => $code,
                'name' => $fallbackName !== ''
                    ? $fallbackName
                    : $code,
                'is_terminal' => $isTerminal,
            ],
        };
    }

    /**
     * @return array<int, array{
     *   code:string,
     *   name:string,
     *   lifecycle_group:string,
     *   is_terminal:bool
     * }>
     */
    public static function publicStatusCatalog(): array
    {
        return [
            [
                'code' => 'new',
                'name' => 'Nuevo',
                'lifecycle_group' => 'open',
                'is_terminal' => false,
            ],
            [
                'code' => 'under_review',
                'name' => 'En gestión',
                'lifecycle_group' => 'open',
                'is_terminal' => false,
            ],
            [
                'code' => 'in_progress',
                'name' => 'En proceso',
                'lifecycle_group' => 'open',
                'is_terminal' => false,
            ],
            [
                'code' => 'closed',
                'name' => 'Trabajo terminado',
                'lifecycle_group' => 'closed',
                'is_terminal' => true,
            ],
        ];
    }

    /** @return array<int, string> */
    public static function internalCodesForPublicStatus(
        string $publicCode
    ): array {
        return match ($publicCode) {
            'new' => ['new'],
            'under_review' => [
                'under_review',
                'quotation_pending',
                'authorization_pending',
                'changes_requested',
                'authorized',
            ],
            'in_progress' => ['in_progress'],
            'closed' => ['completed', 'closed'],
            default => [$publicCode],
        };
    }
}