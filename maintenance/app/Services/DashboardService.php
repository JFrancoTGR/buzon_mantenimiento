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

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @return array{
     *   counters:array<string,int>,
     *   unread_notifications:int,
     *   notifications:array<int,array<string,mixed>>
     * }
     */
    public function summary(array $user): array
    {
        [$scopeSql, $scopeParams] =
            $this->buildTicketScope(
                $user,
                't',
                'summary'
            );

        $statusStatement = $this->pdo->prepare(
            sprintf(
                'SELECT
                    t.location_id,
                    ts.code,
                    COUNT(t.id) AS total
                 FROM tickets t
                 INNER JOIN ticket_statuses ts
                    ON ts.id = t.current_status_id
                 WHERE (%s)
                 GROUP BY t.location_id, ts.code',
                $scopeSql
            )
        );

        $statusStatement->execute(
            $scopeParams
        );

        $counters = array_fill_keys(
            self::SUMMARY_STATUSES,
            0
        );

        foreach ($statusStatement->fetchAll() as $row) {
            $locationId =
                (int) $row['location_id'];

            $statusCode =
                (string) $row['code'];

            $total =
                (int) $row['total'];

            if (
                TicketPresentationPolicy::isReporter(
                    $user,
                    $locationId
                )
            ) {
                $publicStatus =
                    TicketPresentationPolicy::publicStatus(
                        $statusCode
                    );

                $publicCode =
                    (string) $publicStatus['code'];

                if (
                    array_key_exists(
                        $publicCode,
                        $counters
                    )
                ) {
                    $counters[$publicCode] += $total;
                }

                continue;
            }

            if (
                array_key_exists(
                    $statusCode,
                    $counters
                )
            ) {
                $counters[$statusCode] += $total;
            }
        }

        [
            $visibilitySql,
            $visibilityParams,
        ] = $this->notificationVisibility(
            $user,
            'n'
        );

        $notificationParams =
            array_merge(
                [
                    'user_id' =>
                        (int) $user['id'],
                ],
                $visibilityParams
            );

        $notificationStatement =
            $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM notifications n
                 WHERE n.user_id = :user_id
                   AND n.read_at IS NULL
                   AND (' . $visibilitySql . ')'
            );

        $notificationStatement->execute(
            $notificationParams
        );

        $unreadNotifications =
            (int) $notificationStatement
                ->fetchColumn();

        $recentNotifications =
            $this->pdo->prepare(
                'SELECT
                    n.id,
                    n.title,
                    n.message,
                    n.action_url,
                    n.read_at,
                    n.created_at
                 FROM notifications n
                 WHERE n.user_id = :user_id
                   AND (' . $visibilitySql . ')
                 ORDER BY
                    n.created_at DESC,
                    n.id DESC
                 LIMIT 8'
            );

        $recentNotifications->execute(
            $notificationParams
        );

        return [
            'counters' => $counters,
            'unread_notifications' =>
                $unreadNotifications,
            'notifications' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'title' =>
                        (string) $row['title'],
                    'message' =>
                        (string) $row['message'],
                    'action_url' =>
                        $row['action_url'] !== null
                            ? (string) $row['action_url']
                            : null,
                    'is_read' =>
                        $row['read_at'] !== null,
                    'created_at' =>
                        (string) $row['created_at'],
                ],
                $recentNotifications->fetchAll()
            ),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function pendingActions(
        array $user,
        int $limit = 8
    ): array {
        [$scopeSql, $params] =
            $this->buildTicketScope(
                $user,
                't',
                'pending'
            );

        $limit = max(
            1,
            min($limit, 20)
        );

        $sql = sprintf(
            'SELECT
                t.id,
                t.folio,
                t.title,
                t.location_id,
                l.name AS location_name,
                p.code AS priority_code,
                p.name AS priority_name,
                ts.code AS status_code,
                ts.name AS status_name,
                ts.is_terminal,
                t.action_due_at,
                t.updated_at
             FROM tickets t
             INNER JOIN locations l
                ON l.id = t.location_id
             INNER JOIN ticket_priorities p
                ON p.id = t.priority_id
             INNER JOIN ticket_statuses ts
                ON ts.id = t.current_status_id
             WHERE (%s)
               AND ts.is_terminal = 0
               AND t.action_owner_user_id =
                   :pending_current_user
             ORDER BY
                p.weight DESC,
                CASE
                    WHEN t.action_due_at IS NULL
                    THEN 1
                    ELSE 0
                END,
                t.action_due_at ASC,
                t.updated_at DESC
             LIMIT %d',
            $scopeSql,
            $limit
        );

        $params['pending_current_user'] =
            (int) $user['id'];

        $statement =
            $this->pdo->prepare($sql);

        $statement->execute($params);

        return array_map(
            static function (
                array $row
            ) use ($user): array {
                $isReporter =
                    TicketPresentationPolicy::isReporter(
                        $user,
                        (int) $row['location_id']
                    );

                $status = $isReporter
                    ? TicketPresentationPolicy::publicStatus(
                        (string) $row['status_code'],
                        (string) $row['status_name'],
                        (bool) $row['is_terminal']
                    )
                    : [
                        'code' =>
                            (string) $row['status_code'],
                        'name' =>
                            (string) $row['status_name'],
                    ];

                return [
                    'id' =>
                        (int) $row['id'],
                    'folio' =>
                        (string) $row['folio'],
                    'title' =>
                        (string) $row['title'],
                    'location' =>
                        (string) $row['location_name'],
                    'priority' => [
                        'code' =>
                            (string) $row['priority_code'],
                        'name' =>
                            (string) $row['priority_name'],
                    ],
                    'status' => $status,
                    'action_due_at' =>
                        $row['action_due_at'] !== null
                            ? (string) $row['action_due_at']
                            : null,
                    'updated_at' =>
                        (string) $row['updated_at'],
                ];
            },
            $statement->fetchAll()
        );
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function recentActivity(
        array $user,
        int $limit = 6
    ): array {
        [$scopeSql, $params] =
            $this->buildTicketScope(
                $user,
                't',
                'activity'
            );

        $limit = max(
            1,
            min($limit, 20)
        );

        /*
         * A mixed-role user may have Reporter presentation only in some
         * Locations. Fetch enough history to collapse internal Reporter
         * transitions without starving non-Reporter rows.
         */
        $fetchLimit = min(
            100,
            $limit * 12
        );

        $sql = sprintf(
            'SELECT
                h.id,
                t.id AS ticket_id,
                t.location_id,
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
                CONCAT_WS(
                    " ",
                    u.first_name,
                    u.last_name
                ) AS actor_name
             FROM ticket_status_history h
             INNER JOIN tickets t
                ON t.id = h.ticket_id
             LEFT JOIN ticket_statuses fs
                ON fs.id = h.from_status_id
             INNER JOIN ticket_statuses ts
                ON ts.id = h.to_status_id
             LEFT JOIN users u
                ON u.id = h.changed_by_user_id
             WHERE (%s)
             ORDER BY
                h.created_at DESC,
                h.id DESC
             LIMIT %d',
            $scopeSql,
            $fetchLimit
        );

        $statement =
            $this->pdo->prepare($sql);

        $statement->execute($params);

        $rows = $statement->fetchAll();
        $items = [];

        foreach ($rows as $row) {
            $isReporter =
                TicketPresentationPolicy::isReporter(
                    $user,
                    (int) $row['location_id']
                );

            if (!$isReporter) {
                $items[] = [
                    'id' =>
                        (int) $row['id'],
                    'ticket_id' =>
                        (int) $row['ticket_id'],
                    'folio' =>
                        (string) $row['folio'],
                    'title' =>
                        (string) $row['title'],
                    'status' => [
                        'code' =>
                            (string) $row['status_code'],
                        'name' =>
                            (string) $row['status_name'],
                    ],
                    'actor_name' => trim(
                        (string) (
                            $row['actor_name']
                            ?? ''
                        )
                    ),
                    'comment' =>
                        $row['comment'] !== null
                            ? (string) $row['comment']
                            : null,
                    'source' =>
                        (string) $row['change_source'],
                    'created_at' =>
                        (string) $row['created_at'],
                ];
            } else {
                $to =
                    TicketPresentationPolicy::publicStatus(
                        (string) $row['status_code'],
                        (string) $row['status_name'],
                        (bool) $row['status_terminal']
                    );

                $from =
                    $row['from_status_code'] !== null
                        ? TicketPresentationPolicy::publicStatus(
                            (string) $row['from_status_code'],
                            (string) $row['from_status_name'],
                            (bool) $row['from_status_terminal']
                        )
                        : null;

                if (
                    $from !== null
                    && $from['code'] === $to['code']
                ) {
                    continue;
                }

                $items[] = [
                    'id' =>
                        (int) $row['id'],
                    'ticket_id' =>
                        (int) $row['ticket_id'],
                    'folio' =>
                        (string) $row['folio'],
                    'title' =>
                        (string) $row['title'],
                    'status' => [
                        'code' => $to['code'],
                        'name' => $to['name'],
                    ],
                    'actor_name' => trim(
                        (string) (
                            $row['actor_name']
                            ?? ''
                        )
                    ),
                    'comment' => null,
                    'source' =>
                        (string) $row['change_source'],
                    'created_at' =>
                        (string) $row['created_at'],
                ];
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $user
     * @return array{0:string,1:array<string,int|string>}
     */
    private function buildTicketScope(
        array $user,
        string $alias,
        string $prefix
    ): array {
        return AuthorizationService::ticketVisibilityScope(
            $user,
            $alias,
            $prefix
        );
    }

    /**
     * A notification is visible only while its linked ticket is still
     * inside the user's current ticket scope.
     *
     * Reporter Locations additionally receive only public-safe
     * notification types. Notifications without ticket_id are kept as
     * general account/application notices.
     *
     * @param array<string, mixed> $user
     * @return array{0:string,1:array<string,int|string>}
     */
    private function notificationVisibility(
        array $user,
        string $alias
    ): array {
        [
            $ticketScopeSql,
            $ticketScopeParams,
        ] = AuthorizationService::ticketVisibilityScope(
            $user,
            'notification_ticket',
            'notification_scope'
        );

        $params = $ticketScopeParams;

        $reporterLocationIds =
            AuthorizationService::locationIdsForRole(
                $user,
                'reporter'
            );

        $ticketConditions = [
            '(' . $ticketScopeSql . ')',
        ];

        if ($reporterLocationIds !== []) {
            $reporterPlaceholders = [];

            foreach (
                $reporterLocationIds
                as $index => $locationId
            ) {
                $key =
                    'notification_reporter_location_'
                    . $index;

                $reporterPlaceholders[] =
                    ':' . $key;

                $params[$key] =
                    $locationId;
            }

            $params['public_management'] =
                '%En gestión%';

            $params['legacy_public_review'] =
                '%En revisión%';

            $params['public_progress'] =
                '%En proceso%';

            $params['public_completed'] =
                '%Trabajo terminado%';

            $publicSafe = sprintf(
                "(%1\$s.type IN (
                    'ticket.comment_added',
                    'ticket.work.completed'
                  )
                  OR (
                    %1\$s.type = 'ticket.status_changed'
                    AND (
                        %1\$s.title LIKE :public_management
                        OR %1\$s.title LIKE :legacy_public_review
                        OR %1\$s.title LIKE :public_progress
                        OR %1\$s.title LIKE :public_completed
                    )
                  ))",
                $alias
            );

            $ticketConditions[] = sprintf(
                '(notification_ticket.location_id NOT IN (%s) OR %s)',
                implode(
                    ', ',
                    $reporterPlaceholders
                ),
                $publicSafe
            );
        }

        $linkedTicketVisibility =
            'EXISTS (
                SELECT 1
                FROM tickets notification_ticket
                WHERE notification_ticket.id = '
            . $alias
            . '.ticket_id
                  AND '
            . implode(
                ' AND ',
                $ticketConditions
            )
            . '
            )';

        return [
            '('
            . $alias
            . '.ticket_id IS NULL OR '
            . $linkedTicketVisibility
            . ')',
            $params,
        ];
    }
}
