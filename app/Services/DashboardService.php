<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class DashboardService
{
    private const SUMMARY_STATUSES = [
        'new',
        'under_review',
        'authorization_pending',
        'in_progress',
        'closed',
    ];

    private const REPORTER_MANAGEMENT_STATUSES = [
        'under_review',
        'quotation_pending',
        'authorization_pending',
        'changes_requested',
        'authorized',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $user
     * @return array{counters: array<string, int>, unread_notifications: int, notifications: array<int, array<string, mixed>>}
     */
    public function summary(array $user): array
    {
        [$scopeSql, $scopeParams] = $this->buildTicketScope($user, 't', 'summary');

        $statusStatement = $this->pdo->prepare(sprintf(
            'SELECT ts.code, COUNT(t.id) AS total
             FROM tickets t
             INNER JOIN ticket_statuses ts ON ts.id = t.current_status_id
             WHERE (%s)
             GROUP BY ts.code',
            $scopeSql
        ));
        $statusStatement->execute($scopeParams);

        $rawCounters = [];
        foreach ($statusStatement->fetchAll() as $row) {
            $rawCounters[(string) $row['code']] = (int) $row['total'];
        }

        $counters = array_fill_keys(self::SUMMARY_STATUSES, 0);
        if (TicketPresentationPolicy::isReporter($user)) {
            $counters['new'] = (int) ($rawCounters['new'] ?? 0);
            foreach (self::REPORTER_MANAGEMENT_STATUSES as $code) {
                $counters['under_review'] += (int) ($rawCounters[$code] ?? 0);
            }
            $counters['authorization_pending'] = 0;
            $counters['in_progress'] = (int) ($rawCounters['in_progress'] ?? 0);
            $counters['closed'] = (int) ($rawCounters['completed'] ?? 0) + (int) ($rawCounters['closed'] ?? 0);
        } else {
            foreach (self::SUMMARY_STATUSES as $code) {
                $counters[$code] = (int) ($rawCounters[$code] ?? 0);
            }
        }

        [$visibilitySql, $visibilityParams] = $this->notificationVisibility($user, 'n');
        $notificationParams = array_merge(['user_id' => (int) $user['id']], $visibilityParams);

        $notificationStatement = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM notifications n
             WHERE n.user_id = :user_id
               AND n.read_at IS NULL
               AND (' . $visibilitySql . ')'
        );
        $notificationStatement->execute($notificationParams);
        $unreadNotifications = (int) $notificationStatement->fetchColumn();

        $recentNotifications = $this->pdo->prepare(
            'SELECT n.id, n.title, n.message, n.action_url, n.read_at, n.created_at
             FROM notifications n
             WHERE n.user_id = :user_id
               AND (' . $visibilitySql . ')
             ORDER BY n.created_at DESC, n.id DESC
             LIMIT 8'
        );
        $recentNotifications->execute($notificationParams);

        return [
            'counters' => $counters,
            'unread_notifications' => $unreadNotifications,
            'notifications' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'title' => (string) $row['title'],
                    'message' => (string) $row['message'],
                    'action_url' => $row['action_url'] !== null ? (string) $row['action_url'] : null,
                    'is_read' => $row['read_at'] !== null,
                    'created_at' => (string) $row['created_at'],
                ],
                $recentNotifications->fetchAll()
            ),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function pendingActions(array $user, int $limit = 8): array
    {
        [$scopeSql, $params] = $this->buildTicketScope($user, 't', 'pending');
        $limit = max(1, min($limit, 20));

        $sql = sprintf(
            'SELECT
                t.id,
                t.folio,
                t.title,
                l.name AS location_name,
                p.code AS priority_code,
                p.name AS priority_name,
                ts.code AS status_code,
                ts.name AS status_name,
                ts.is_terminal,
                t.action_due_at,
                t.updated_at
             FROM tickets t
             INNER JOIN locations l ON l.id = t.location_id
             INNER JOIN ticket_priorities p ON p.id = t.priority_id
             INNER JOIN ticket_statuses ts ON ts.id = t.current_status_id
             WHERE (%s)
               AND ts.is_terminal = 0
               AND t.action_owner_user_id = :pending_current_user
             ORDER BY
                p.weight DESC,
                CASE WHEN t.action_due_at IS NULL THEN 1 ELSE 0 END,
                t.action_due_at ASC,
                t.updated_at DESC
             LIMIT %d',
            $scopeSql,
            $limit
        );

        $params['pending_current_user'] = (int) $user['id'];
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $isReporter = TicketPresentationPolicy::isReporter($user);

        return array_map(
            static function (array $row) use ($isReporter): array {
                $status = $isReporter
                    ? TicketPresentationPolicy::publicStatus((string) $row['status_code'], (string) $row['status_name'], (bool) $row['is_terminal'])
                    : ['code' => (string) $row['status_code'], 'name' => (string) $row['status_name']];

                return [
                    'id' => (int) $row['id'],
                    'folio' => (string) $row['folio'],
                    'title' => (string) $row['title'],
                    'location' => (string) $row['location_name'],
                    'priority' => [
                        'code' => (string) $row['priority_code'],
                        'name' => (string) $row['priority_name'],
                    ],
                    'status' => $status,
                    'action_due_at' => $row['action_due_at'] !== null ? (string) $row['action_due_at'] : null,
                    'updated_at' => (string) $row['updated_at'],
                ];
            },
            $statement->fetchAll()
        );
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function recentActivity(array $user, int $limit = 6): array
    {
        [$scopeSql, $params] = $this->buildTicketScope($user, 't', 'activity');
        $limit = max(1, min($limit, 20));
        $fetchLimit = TicketPresentationPolicy::isReporter($user) ? min(100, $limit * 12) : $limit;

        $sql = sprintf(
            'SELECT
                h.id,
                t.id AS ticket_id,
                t.folio,
                t.title,
                fs.code AS from_status_code,
                fs.name AS from_status_name,
                fs.is_terminal AS from_status_terminal,
                ts.code AS status_code,
                ts.name AS status_name,
                ts.is_terminal AS status_terminal,
                h.comment,
                h.change_source,
                h.created_at,
                CONCAT_WS(" ", u.first_name, u.last_name) AS actor_name
             FROM ticket_status_history h
             INNER JOIN tickets t ON t.id = h.ticket_id
             LEFT JOIN ticket_statuses fs ON fs.id = h.from_status_id
             INNER JOIN ticket_statuses ts ON ts.id = h.to_status_id
             LEFT JOIN users u ON u.id = h.changed_by_user_id
             WHERE (%s)
             ORDER BY h.created_at DESC, h.id DESC
             LIMIT %d',
            $scopeSql,
            $fetchLimit
        );

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $rows = $statement->fetchAll();

        if (!TicketPresentationPolicy::isReporter($user)) {
            return array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'ticket_id' => (int) $row['ticket_id'],
                'folio' => (string) $row['folio'],
                'title' => (string) $row['title'],
                'status' => [
                    'code' => (string) $row['status_code'],
                    'name' => (string) $row['status_name'],
                ],
                'actor_name' => trim((string) ($row['actor_name'] ?? '')),
                'comment' => $row['comment'] !== null ? (string) $row['comment'] : null,
                'source' => (string) $row['change_source'],
                'created_at' => (string) $row['created_at'],
            ], $rows);
        }

        $items = [];
        foreach ($rows as $row) {
            $to = TicketPresentationPolicy::publicStatus(
                (string) $row['status_code'],
                (string) $row['status_name'],
                (bool) $row['status_terminal']
            );
            $from = $row['from_status_code'] !== null
                ? TicketPresentationPolicy::publicStatus(
                    (string) $row['from_status_code'],
                    (string) $row['from_status_name'],
                    (bool) $row['from_status_terminal']
                )
                : null;

            if ($from !== null && $from['code'] === $to['code']) {
                continue;
            }

            $items[] = [
                'id' => (int) $row['id'],
                'ticket_id' => (int) $row['ticket_id'],
                'folio' => (string) $row['folio'],
                'title' => (string) $row['title'],
                'status' => ['code' => $to['code'], 'name' => $to['name']],
                'actor_name' => trim((string) ($row['actor_name'] ?? '')),
                'comment' => null,
                'source' => (string) $row['change_source'],
                'created_at' => (string) $row['created_at'],
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $user
     * @return array{0:string,1:array<string, int|string>}
     */
    private function buildTicketScope(array $user, string $alias, string $prefix): array
    {
        $permissions = is_array($user['permissions'] ?? null) ? $user['permissions'] : [];
        $userId = (int) ($user['id'] ?? 0);

        if (in_array('ticket.view.all', $permissions, true)) {
            return ['1 = 1', []];
        }

        $clauses = [];
        $params = [];

        if (in_array('ticket.view.own', $permissions, true)) {
            $placeholder = ':' . $prefix . '_reporter';
            $clauses[] = sprintf('%s.reported_by_user_id = %s', $alias, $placeholder);
            $params[ltrim($placeholder, ':')] = $userId;
        }

        if (in_array('ticket.view.assigned', $permissions, true)) {
            $supervisor = ':' . $prefix . '_supervisor';
            $director = ':' . $prefix . '_director';
            $owner = ':' . $prefix . '_owner';
            $clauses[] = sprintf(
                '(%1$s.supervisor_user_id = %2$s OR %1$s.director_user_id = %3$s OR %1$s.action_owner_user_id = %4$s)',
                $alias,
                $supervisor,
                $director,
                $owner
            );
            $params[ltrim($supervisor, ':')] = $userId;
            $params[ltrim($director, ':')] = $userId;
            $params[ltrim($owner, ':')] = $userId;
        }

        if ($clauses === []) {
            return ['0 = 1', []];
        }

        return ['(' . implode(' OR ', $clauses) . ')', $params];
    }

    /** @param array<string, mixed> $user @return array{0:string,1:array<string,string>} */
    private function notificationVisibility(array $user, string $alias): array
    {
        if (!TicketPresentationPolicy::isReporter($user)) {
            return ['1 = 1', []];
        }

        return [
            sprintf(
                "(%1\$s.type IN ('ticket.comment_added', 'ticket.work.completed')
                  OR (%1\$s.type = 'ticket.status_changed'
                      AND (%1\$s.title LIKE :public_management OR %1\$s.title LIKE :legacy_public_review
                           OR %1\$s.title LIKE :public_progress OR %1\$s.title LIKE :public_completed)))",
                $alias
            ),
            [
                'public_management' => '%En gestión%',
                'legacy_public_review' => '%En revisión%',
                'public_progress' => '%En proceso%',
                'public_completed' => '%Trabajo terminado%',
            ],
        ];
    }
}
