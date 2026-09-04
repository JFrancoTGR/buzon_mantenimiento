<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;
use PDO;

final class SupervisorService
{
    private const SUMMARY_STATUSES = [
        'new',
        'under_review',
        'quotation_pending',
        'changes_requested',
        'authorized',
        'in_progress',
        'closed',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $user @return array<string, mixed> */
    public function summary(array $user): array
    {
        $this->requireTicketView($user);
        [$scopeSql, $params, $scope] = $this->buildTicketScope($user, 't', 'summary');

        $statusStatement = $this->pdo->prepare(sprintf(
            'SELECT s.code, COUNT(t.id) AS total
             FROM tickets t
             INNER JOIN ticket_statuses s ON s.id = t.current_status_id
             WHERE (%s)
             GROUP BY s.code',
            $scopeSql
        ));
        $statusStatement->execute($params);

        $rawCounters = [];
        foreach ($statusStatement->fetchAll() as $row) {
            $rawCounters[(string) $row['code']] = (int) $row['total'];
        }

        $counters = array_fill_keys(self::SUMMARY_STATUSES, 0);
        if (TicketPresentationPolicy::isReporter($user)) {
            $counters['new'] = (int) ($rawCounters['new'] ?? 0);
            foreach (['under_review', 'quotation_pending', 'authorization_pending', 'changes_requested', 'authorized'] as $code) {
                $counters['under_review'] += (int) ($rawCounters[$code] ?? 0);
            }
            $counters['in_progress'] = (int) ($rawCounters['in_progress'] ?? 0);
            $counters['closed'] = (int) ($rawCounters['completed'] ?? 0) + (int) ($rawCounters['closed'] ?? 0);
        } else {
            foreach (self::SUMMARY_STATUSES as $code) {
                $counters[$code] = (int) ($rawCounters[$code] ?? 0);
            }
        }

        $attentionParams = $params;
        $attentionSql = $this->attentionCondition($user, 't', $attentionParams, 'summary_attention');
        $attentionStatement = $this->pdo->prepare(sprintf(
            'SELECT COUNT(*)
             FROM tickets t
             INNER JOIN ticket_statuses s ON s.id = t.current_status_id
             WHERE (%s) AND s.is_terminal = 0 AND (%s)',
            $scopeSql,
            $attentionSql
        ));
        $attentionStatement->execute($attentionParams);
        $counters['requires_attention'] = (int) $attentionStatement->fetchColumn();

        $locations = $this->pdo->query(
            'SELECT id, code, name
             FROM locations
             WHERE is_active = 1
             ORDER BY sort_order, name'
        )->fetchAll();

        $statuses = TicketPresentationPolicy::isReporter($user)
            ? TicketPresentationPolicy::publicStatusCatalog()
            : $this->pdo->query(
                'SELECT code, name, lifecycle_group, is_terminal
                 FROM ticket_statuses
                 WHERE is_active = 1
                 ORDER BY sort_order, id'
            )->fetchAll();

        return [
            'scope' => $scope,
            'counters' => $counters,
            'locations' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
            ], $locations),
            'statuses' => array_map(static fn (array $row): array => [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'lifecycle_group' => (string) $row['lifecycle_group'],
                'is_terminal' => (bool) $row['is_terminal'],
            ], $statuses),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function tickets(array $user, array $filters): array
    {
        $this->requireTicketView($user);
        [$scopeSql, $params, $scope] = $this->buildTicketScope($user, 't', 'list');
        $where = ['(' . $scopeSql . ')'];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            if (TicketPresentationPolicy::isReporter($user)) {
                $publicCodes = array_column(TicketPresentationPolicy::publicStatusCatalog(), 'code');
                if (!in_array($status, $publicCodes, true)) {
                    throw new HttpException(422, 'invalid_status_filter', 'El filtro de estado no es válido.');
                }
                $internalCodes = TicketPresentationPolicy::internalCodesForPublicStatus($status);
                $placeholders = [];
                foreach ($internalCodes as $index => $code) {
                    $key = 'filter_public_status_' . $index;
                    $placeholders[] = ':' . $key;
                    $params[$key] = $code;
                }
                $where[] = 's.code IN (' . implode(', ', $placeholders) . ')';
            } else {
                $this->assertStatusCode($status);
                $where[] = 's.code = :filter_status';
                $params['filter_status'] = $status;
            }
        }

        $route = trim((string) ($filters['route'] ?? ''));
        if (!TicketPresentationPolicy::isReporter($user)) {
            if ($route === 'not_selected') {
                $where[] = 't.processing_route IS NULL';
            } elseif ($route !== '') {
                if (!in_array($route, ['direct', 'authorization_required'], true)) {
                    throw new HttpException(422, 'invalid_route_filter', 'El filtro de ruta no es válido.');
                }
                $where[] = 't.processing_route = :filter_route';
                $params['filter_route'] = $route;
            }
        }

        $locationId = filter_var($filters['location_id'] ?? null, FILTER_VALIDATE_INT);
        if (is_int($locationId) && $locationId > 0) {
            $where[] = 't.location_id = :filter_location_id';
            $params['filter_location_id'] = $locationId;
        }

        $priority = trim((string) ($filters['priority'] ?? ''));
        if ($priority !== '') {
            if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
                throw new HttpException(422, 'invalid_priority_filter', 'El filtro de prioridad no es válido.');
            }
            $where[] = 'p.code = :filter_priority';
            $params['filter_priority'] = $priority;
        }

        $attention = filter_var($filters['attention'] ?? false, FILTER_VALIDATE_BOOL);
        if ($attention) {
            $where[] = '(' . $this->attentionCondition($user, 't', $params, 'list_attention') . ')';
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            if ($this->textLength($search) > 120) {
                throw new HttpException(422, 'search_too_long', 'La búsqueda no puede exceder 120 caracteres.');
            }
            $where[] = '(t.folio LIKE :search_folio OR t.title LIKE :search_title OR t.specific_location LIKE :search_zone)';
            $params['search_folio'] = '%' . $search . '%';
            $params['search_title'] = '%' . $search . '%';
            $params['search_zone'] = '%' . $search . '%';
        }

        $sql = sprintf(
            'SELECT
                t.id, t.folio, t.title, t.specific_location, t.processing_route,
                t.submitted_at, t.updated_at, t.action_due_at, t.row_version,
                l.id AS location_id, l.code AS location_code, l.name AS location_name,
                p.code AS priority_code, p.name AS priority_name, p.weight AS priority_weight,
                s.code AS status_code, s.name AS status_name, s.is_terminal,
                reporter.id AS reporter_id,
                CONCAT_WS(" ", reporter.first_name, reporter.last_name) AS reporter_name,
                owner.id AS action_owner_id,
                CONCAT_WS(" ", owner.first_name, owner.last_name) AS action_owner_name
             FROM tickets t
             INNER JOIN locations l ON l.id = t.location_id
             INNER JOIN ticket_priorities p ON p.id = t.priority_id
             INNER JOIN ticket_statuses s ON s.id = t.current_status_id
             INNER JOIN users reporter ON reporter.id = t.reported_by_user_id
             LEFT JOIN users owner ON owner.id = t.action_owner_user_id
             WHERE %s
             ORDER BY
                CASE WHEN t.action_owner_user_id = :order_current_user THEN 0 ELSE 1 END,
                s.is_terminal ASC,
                p.weight DESC,
                t.updated_at DESC,
                t.id DESC
             LIMIT 100',
            implode(' AND ', $where)
        );
        $params['order_current_user'] = (int) $user['id'];

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $isReporter = TicketPresentationPolicy::isReporter($user);
        $items = array_map(static function (array $row) use ($isReporter): array {
            $status = $isReporter
                ? TicketPresentationPolicy::publicStatus((string) $row['status_code'], (string) $row['status_name'], (bool) $row['is_terminal'])
                : [
                    'code' => (string) $row['status_code'],
                    'name' => (string) $row['status_name'],
                    'is_terminal' => (bool) $row['is_terminal'],
                ];

            return [
                'id' => (int) $row['id'],
                'folio' => (string) $row['folio'],
                'title' => (string) $row['title'],
                'specific_location' => (string) $row['specific_location'],
                'processing_route' => $isReporter ? null : ($row['processing_route'] !== null ? (string) $row['processing_route'] : null),
                'public_view' => $isReporter,
                'location' => [
                    'id' => (int) $row['location_id'],
                    'code' => (string) $row['location_code'],
                    'name' => (string) $row['location_name'],
                ],
                'priority' => [
                    'code' => (string) $row['priority_code'],
                    'name' => (string) $row['priority_name'],
                ],
                'status' => $status,
                'reporter' => [
                    'id' => (int) $row['reporter_id'],
                    'full_name' => trim((string) $row['reporter_name']),
                ],
                'action_owner' => $isReporter ? null : ($row['action_owner_id'] !== null ? [
                    'id' => (int) $row['action_owner_id'],
                    'full_name' => trim((string) $row['action_owner_name']),
                ] : null),
                'submitted_at' => (string) $row['submitted_at'],
                'updated_at' => (string) $row['updated_at'],
                'action_due_at' => $row['action_due_at'] !== null ? (string) $row['action_due_at'] : null,
                'row_version' => (int) $row['row_version'],
            ];
        }, $statement->fetchAll());

        return [
            'scope' => $scope,
            'items' => $items,
            'total' => count($items),
            'limit' => 100,
        ];
    }

    /** @param array<string, mixed> $user */
    private function requireTicketView(array $user): void
    {
        foreach (['ticket.view.all', 'ticket.view.assigned', 'ticket.view.own'] as $permission) {
            if (AuthorizationService::hasPermission($user, $permission)) {
                return;
            }
        }
        throw new HttpException(403, 'permission_denied', 'No tienes permiso para consultar tickets.');
    }

    /**
     * @param array<string, mixed> $user
     * @return array{0:string,1:array<string,int>,2:array<string,string>}
     */
    private function buildTicketScope(array $user, string $alias, string $prefix): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if (AuthorizationService::hasPermission($user, 'ticket.view.all')) {
            return ['1 = 1', [], ['code' => 'all', 'name' => 'Todos los tickets']];
        }

        $clauses = [];
        $params = [];
        $hasAssigned = AuthorizationService::hasPermission($user, 'ticket.view.assigned');
        $hasOwn = AuthorizationService::hasPermission($user, 'ticket.view.own');

        if ($hasAssigned) {
            $supervisor = $prefix . '_supervisor';
            $director = $prefix . '_director';
            $owner = $prefix . '_owner';
            $clauses[] = sprintf(
                '(%1$s.supervisor_user_id = :%2$s OR %1$s.director_user_id = :%3$s OR %1$s.action_owner_user_id = :%4$s)',
                $alias,
                $supervisor,
                $director,
                $owner
            );
            $params[$supervisor] = $userId;
            $params[$director] = $userId;
            $params[$owner] = $userId;
        }

        if ($hasOwn) {
            $reporter = $prefix . '_reporter';
            $clauses[] = sprintf('%s.reported_by_user_id = :%s', $alias, $reporter);
            $params[$reporter] = $userId;
        }

        if ($clauses === []) {
            return ['0 = 1', [], ['code' => 'none', 'name' => 'Sin alcance']];
        }

        $scopeCode = $hasAssigned && $hasOwn ? 'assigned_and_own' : ($hasAssigned ? 'assigned' : 'own');
        $scopeName = $hasAssigned && $hasOwn
            ? 'Tickets asignados y reportados por ti'
            : ($hasAssigned ? 'Mis tickets asignados' : 'Mis reportes');

        return ['(' . implode(' OR ', $clauses) . ')', $params, ['code' => $scopeCode, 'name' => $scopeName]];
    }

    /** @param array<string, mixed> $user @param array<string, int|string> $params */
    private function attentionCondition(array $user, string $alias, array &$params, string $prefix): string
    {
        $key = $prefix . '_user';
        $params[$key] = (int) $user['id'];
        return sprintf('%s.action_owner_user_id = :%s', $alias, $key);
    }

    private function assertStatusCode(string $status): void
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM ticket_statuses WHERE code = :code AND is_active = 1 LIMIT 1'
        );
        $statement->execute(['code' => $status]);
        if ($statement->fetchColumn() === false) {
            throw new HttpException(422, 'invalid_status_filter', 'El filtro de estado no es válido.');
        }
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
