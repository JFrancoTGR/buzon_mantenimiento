<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;
use PDO;

final class OperatorService
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

    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function summary(array $user): array
    {
        $this->requireTicketView($user);

        [
            $scopeSql,
            $params,
            $scope,
        ] = $this->buildTicketScope(
            $user,
            't',
            'summary'
        );

        $statusStatement = $this->pdo->prepare(
            sprintf(
                'SELECT
                    t.location_id,
                    s.code,
                    COUNT(t.id) AS total
                 FROM tickets t
                 INNER JOIN ticket_statuses s
                    ON s.id = t.current_status_id
                 WHERE (%s)
                 GROUP BY t.location_id, s.code',
                $scopeSql
            )
        );

        $statusStatement->execute($params);

        $counters = array_fill_keys(
            self::SUMMARY_STATUSES,
            0
        );

        foreach ($statusStatement->fetchAll() as $row) {
            $locationId = (int) $row['location_id'];
            $statusCode = (string) $row['code'];
            $total = (int) $row['total'];

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

        $attentionParams = $params;

        $attentionSql = $this->attentionCondition(
            $user,
            't',
            $attentionParams,
            'summary_attention'
        );

        $attentionStatement = $this->pdo->prepare(
            sprintf(
                'SELECT COUNT(*)
                 FROM tickets t
                 INNER JOIN ticket_statuses s
                    ON s.id = t.current_status_id
                 WHERE (%s)
                   AND s.is_terminal = 0
                   AND (%s)',
                $scopeSql,
                $attentionSql
            )
        );

        $attentionStatement->execute(
            $attentionParams
        );

        $counters['requires_attention'] =
            (int) $attentionStatement->fetchColumn();

        $viewLocationIds =
            AuthorizationService::ticketViewLocationIds(
                $user
            );

        if ($viewLocationIds === null) {
            $locations = $this->pdo->query(
                'SELECT id, code, name
                 FROM locations
                 WHERE is_active = 1
                 ORDER BY sort_order, name'
            )->fetchAll();
        } elseif ($viewLocationIds === []) {
            $locations = [];
        } else {
            $locationParams = [];

            $locationCondition =
                $this->locationSetCondition(
                    'l',
                    $viewLocationIds,
                    'summary_location',
                    $locationParams,
                    false,
                    'id'
                );

            $locationStatement = $this->pdo->prepare(
                'SELECT id, code, name
                 FROM locations l
                 WHERE l.is_active = 1
                   AND '
                . $locationCondition
                . '
                 ORDER BY l.sort_order, l.name'
            );

            $locationStatement->execute(
                $locationParams
            );

            $locations =
                $locationStatement->fetchAll();
        }

        $statuses =
            TicketPresentationPolicy::isReporter($user)
                ? TicketPresentationPolicy::publicStatusCatalog()
                : $this->pdo->query(
                    'SELECT
                        code,
                        name,
                        lifecycle_group,
                        is_terminal
                     FROM ticket_statuses
                     WHERE is_active = 1
                     ORDER BY sort_order, id'
                )->fetchAll();

        return [
            'scope' => $scope,
            'counters' => $counters,
            'locations' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'code' => (string) $row['code'],
                    'name' => (string) $row['name'],
                ],
                $locations
            ),
            'statuses' => array_map(
                static fn (array $row): array => [
                    'code' => (string) $row['code'],
                    'name' => (string) $row['name'],
                    'lifecycle_group' =>
                        (string) $row['lifecycle_group'],
                    'is_terminal' =>
                        (bool) $row['is_terminal'],
                ],
                $statuses
            ),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function tickets(
        array $user,
        array $filters
    ): array {
        $this->requireTicketView($user);

        [
            $scopeSql,
            $params,
            $scope,
        ] = $this->buildTicketScope(
            $user,
            't',
            'list'
        );

        $where = [
            '(' . $scopeSql . ')',
        ];

        $status = trim(
            (string) (
                $filters['status']
                ?? ''
            )
        );

        if ($status !== '') {
            $where[] =
                '('
                . $this->statusFilterCondition(
                    $user,
                    $status,
                    't',
                    's',
                    $params,
                    'list_status'
                )
                . ')';
        }

        $route = trim(
            (string) (
                $filters['route']
                ?? ''
            )
        );

        if (
            !TicketPresentationPolicy::isReporter($user)
            && $route !== ''
        ) {
            $operationalLocation =
                $this->nonReporterLocationCondition(
                    $user,
                    't',
                    $params,
                    'list_route_location'
                );

            if ($route === 'not_selected') {
                $routeCondition =
                    't.processing_route IS NULL';
            } else {
                if (
                    !in_array(
                        $route,
                        [
                            'direct',
                            'authorization_required',
                        ],
                        true
                    )
                ) {
                    throw new HttpException(
                        422,
                        'invalid_route_filter',
                        'El filtro de ruta no es válido.'
                    );
                }

                $params['filter_route'] =
                    $route;

                $routeCondition =
                    't.processing_route = :filter_route';
            }

            $where[] = sprintf(
                '(%s AND %s)',
                $operationalLocation,
                $routeCondition
            );
        }

        $locationId = filter_var(
            $filters['location_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (
            is_int($locationId)
            && $locationId > 0
        ) {
            $where[] =
                't.location_id = :filter_location_id';

            $params['filter_location_id'] =
                $locationId;
        }

        $priority = trim(
            (string) (
                $filters['priority']
                ?? ''
            )
        );

        if ($priority !== '') {
            if (
                !in_array(
                    $priority,
                    [
                        'low',
                        'medium',
                        'high',
                        'urgent',
                    ],
                    true
                )
            ) {
                throw new HttpException(
                    422,
                    'invalid_priority_filter',
                    'El filtro de prioridad no es válido.'
                );
            }

            $where[] =
                'p.code = :filter_priority';

            $params['filter_priority'] =
                $priority;
        }

        $attention = filter_var(
            $filters['attention'] ?? false,
            FILTER_VALIDATE_BOOL
        );

        if ($attention) {
            $where[] =
                '('
                . $this->attentionCondition(
                    $user,
                    't',
                    $params,
                    'list_attention'
                )
                . ')';
        }

        $search = trim(
            (string) (
                $filters['search']
                ?? ''
            )
        );

        if ($search !== '') {
            if ($this->textLength($search) > 120) {
                throw new HttpException(
                    422,
                    'search_too_long',
                    'La búsqueda no puede exceder 120 caracteres.'
                );
            }

            $where[] =
                '(t.folio LIKE :search_folio
                  OR t.title LIKE :search_title
                  OR t.specific_location LIKE :search_zone)';

            $params['search_folio'] =
                '%' . $search . '%';

            $params['search_title'] =
                '%' . $search . '%';

            $params['search_zone'] =
                '%' . $search . '%';
        }

        $sql = sprintf(
            'SELECT
                t.id,
                t.folio,
                t.title,
                t.specific_location,
                t.processing_route,
                t.submitted_at,
                t.updated_at,
                t.action_due_at,
                t.row_version,
                l.id AS location_id,
                l.code AS location_code,
                l.name AS location_name,
                p.code AS priority_code,
                p.name AS priority_name,
                p.weight AS priority_weight,
                s.code AS status_code,
                s.name AS status_name,
                s.is_terminal,
                reporter.id AS reporter_id,
                CONCAT_WS(
                    " ",
                    reporter.first_name,
                    reporter.last_name
                ) AS reporter_name,
                owner.id AS action_owner_id,
                CONCAT_WS(
                    " ",
                    owner.first_name,
                    owner.last_name
                ) AS action_owner_name
             FROM tickets t
             INNER JOIN locations l
                ON l.id = t.location_id
             INNER JOIN ticket_priorities p
                ON p.id = t.priority_id
             INNER JOIN ticket_statuses s
                ON s.id = t.current_status_id
             INNER JOIN users reporter
                ON reporter.id = t.reported_by_user_id
             LEFT JOIN users owner
                ON owner.id = t.action_owner_user_id
             WHERE %s
             ORDER BY
                CASE
                    WHEN t.action_owner_user_id =
                         :order_current_user
                    THEN 0
                    ELSE 1
                END,
                s.is_terminal ASC,
                p.weight DESC,
                t.updated_at DESC,
                t.id DESC
             LIMIT 100',
            implode(
                ' AND ',
                $where
            )
        );

        $params['order_current_user'] =
            (int) $user['id'];

        $statement =
            $this->pdo->prepare($sql);

        $statement->execute($params);

        $items = array_map(
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
                        'is_terminal' =>
                            (bool) $row['is_terminal'],
                    ];

                return [
                    'id' =>
                        (int) $row['id'],
                    'folio' =>
                        (string) $row['folio'],
                    'title' =>
                        (string) $row['title'],
                    'specific_location' =>
                        (string) $row['specific_location'],
                    'processing_route' =>
                        $isReporter
                            ? null
                            : (
                                $row['processing_route'] !== null
                                    ? (string) $row['processing_route']
                                    : null
                            ),
                    'public_view' =>
                        $isReporter,
                    'location' => [
                        'id' =>
                            (int) $row['location_id'],
                        'code' =>
                            (string) $row['location_code'],
                        'name' =>
                            (string) $row['location_name'],
                    ],
                    'priority' => [
                        'code' =>
                            (string) $row['priority_code'],
                        'name' =>
                            (string) $row['priority_name'],
                    ],
                    'status' => $status,
                    'reporter' => [
                        'id' =>
                            (int) $row['reporter_id'],
                        'full_name' => trim(
                            (string) $row['reporter_name']
                        ),
                    ],
                    'action_owner' =>
                        $isReporter
                            ? null
                            : (
                                $row['action_owner_id'] !== null
                                    ? [
                                        'id' =>
                                            (int) $row['action_owner_id'],
                                        'full_name' => trim(
                                            (string) $row['action_owner_name']
                                        ),
                                    ]
                                    : null
                            ),
                    'submitted_at' =>
                        (string) $row['submitted_at'],
                    'updated_at' =>
                        (string) $row['updated_at'],
                    'action_due_at' =>
                        $row['action_due_at'] !== null
                            ? (string) $row['action_due_at']
                            : null,
                    'row_version' =>
                        (int) $row['row_version'],
                ];
            },
            $statement->fetchAll()
        );

        return [
            'scope' => $scope,
            'items' => $items,
            'total' => count($items),
            'limit' => 100,
        ];
    }

    /** @param array<string, mixed> $user */
    private function requireTicketView(
        array $user
    ): void {
        AuthorizationService::requireTicketView(
            $user
        );
    }

    /**
     * @param array<string, mixed> $user
     * @return array{
     *   0:string,
     *   1:array<string,int|string>,
     *   2:array<string,string>
     * }
     */
    private function buildTicketScope(
        array $user,
        string $alias,
        string $prefix
    ): array {
        [$scopeSql, $params] =
            AuthorizationService::ticketVisibilityScope(
                $user,
                $alias,
                $prefix
            );

        if (
            AuthorizationService::hasPermission(
                $user,
                'ticket.view.all'
            )
        ) {
            return [
                $scopeSql,
                $params,
                [
                    'code' => 'all',
                    'name' => 'Todos los tickets',
                ],
            ];
        }

        if (
            AuthorizationService::hasAnyPermission(
                $user,
                'ticket.view.location'
            )
        ) {
            return [
                $scopeSql,
                $params,
                [
                    'code' => 'location',
                    'name' =>
                        'Tickets de mis ubicaciones',
                ],
            ];
        }

        $hasAssigned =
            AuthorizationService::hasAnyPermission(
                $user,
                'ticket.view.assigned'
            );

        $hasOwn =
            AuthorizationService::hasAnyPermission(
                $user,
                'ticket.view.own'
            );

        if ($hasAssigned && $hasOwn) {
            $scope = [
                'code' => 'assigned_and_own',
                'name' =>
                    'Tickets asignados y reportados por ti',
            ];
        } elseif ($hasAssigned) {
            $scope = [
                'code' => 'assigned',
                'name' =>
                    'Mis tickets asignados',
            ];
        } elseif ($hasOwn) {
            $scope = [
                'code' => 'own',
                'name' =>
                    'Mis reportes',
            ];
        } else {
            $scope = [
                'code' => 'none',
                'name' => 'Sin alcance',
            ];
        }

        return [
            $scopeSql,
            $params,
            $scope,
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, int|string> $params
     */
    private function attentionCondition(
        array $user,
        string $alias,
        array &$params,
        string $prefix
    ): string {
        $userKey =
            $prefix . '_user';

        $params[$userKey] =
            (int) $user['id'];

        $ownerCondition = sprintf(
            '%s.action_owner_user_id = :%s',
            $alias,
            $userKey
        );

        $nonReporterCondition =
            $this->nonReporterLocationCondition(
                $user,
                $alias,
                $params,
                $prefix . '_role'
            );

        return sprintf(
            '(%s AND %s)',
            $nonReporterCondition,
            $ownerCondition
        );
    }

    /**
     * Mixed-role safe status filter.
     *
     * On Reporter Locations, public status semantics are used. On every
     * other Location, the exact internal status is used. Therefore an
     * internal-only filter can never reveal a Reporter Location's
     * workflow state by membership.
     *
     * @param array<string, mixed> $user
     * @param array<string, int|string> $params
     */
    private function statusFilterCondition(
        array $user,
        string $status,
        string $ticketAlias,
        string $statusAlias,
        array &$params,
        string $prefix
    ): string {
        $publicCodes = array_column(
            TicketPresentationPolicy::publicStatusCatalog(),
            'code'
        );

        $isPublic =
            in_array(
                $status,
                $publicCodes,
                true
            );

        $isInternal =
            $this->isStatusCode($status);

        if (
            TicketPresentationPolicy::isReporter($user)
        ) {
            if (!$isPublic) {
                throw new HttpException(
                    422,
                    'invalid_status_filter',
                    'El filtro de estado no es válido.'
                );
            }

            return $this->statusCodeSetCondition(
                $statusAlias,
                TicketPresentationPolicy::internalCodesForPublicStatus(
                    $status
                ),
                $prefix . '_public',
                $params
            );
        }

        $reporterLocationIds =
            AuthorizationService::locationIdsForRole(
                $user,
                'reporter'
            );

        $clauses = [];

        if (
            $isPublic
            && $reporterLocationIds !== []
        ) {
            $reporterLocationCondition =
                $this->locationSetCondition(
                    $ticketAlias,
                    $reporterLocationIds,
                    $prefix . '_reporter_location',
                    $params
                );

            $publicStatusCondition =
                $this->statusCodeSetCondition(
                    $statusAlias,
                    TicketPresentationPolicy::internalCodesForPublicStatus(
                        $status
                    ),
                    $prefix . '_reporter_status',
                    $params
                );

            $clauses[] = sprintf(
                '(%s AND %s)',
                $reporterLocationCondition,
                $publicStatusCondition
            );
        }

        if ($isInternal) {
            $internalKey =
                $prefix . '_internal';

            $params[$internalKey] =
                $status;

            $internalCondition =
                $statusAlias
                . '.code = :'
                . $internalKey;

            if ($reporterLocationIds !== []) {
                $internalLocationCondition =
                    $this->locationSetCondition(
                        $ticketAlias,
                        $reporterLocationIds,
                        $prefix . '_internal_location',
                        $params,
                        true
                    );

                $clauses[] = sprintf(
                    '(%s AND %s)',
                    $internalLocationCondition,
                    $internalCondition
                );
            } else {
                $clauses[] =
                    $internalCondition;
            }
        }

        if ($clauses === []) {
            throw new HttpException(
                422,
                'invalid_status_filter',
                'El filtro de estado no es válido.'
            );
        }

        return '('
            . implode(
                ' OR ',
                $clauses
            )
            . ')';
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, int|string> $params
     */
    private function nonReporterLocationCondition(
        array $user,
        string $alias,
        array &$params,
        string $prefix
    ): string {
        $reporterLocationIds =
            AuthorizationService::locationIdsForRole(
                $user,
                'reporter'
            );

        if ($reporterLocationIds === []) {
            return '1 = 1';
        }

        return $this->locationSetCondition(
            $alias,
            $reporterLocationIds,
            $prefix,
            $params,
            true
        );
    }

    /**
     * @param array<int, int> $locationIds
     * @param array<string, int|string> $params
     */
    private function locationSetCondition(
        string $alias,
        array $locationIds,
        string $prefix,
        array &$params,
        bool $negate = false,
        string $column = 'location_id'
    ): string {
        $placeholders = [];

        foreach (
            array_values(
                array_unique($locationIds)
            ) as $index => $locationId
        ) {
            $key =
                $prefix
                . '_'
                . $index;

            $placeholders[] =
                ':' . $key;

            $params[$key] =
                (int) $locationId;
        }

        if ($placeholders === []) {
            return $negate
                ? '1 = 1'
                : '0 = 1';
        }

        return sprintf(
            '%s.%s %s IN (%s)',
            $alias,
            $column,
            $negate ? 'NOT' : '',
            implode(
                ', ',
                $placeholders
            )
        );
    }

    /**
     * @param array<int, string> $codes
     * @param array<string, int|string> $params
     */
    private function statusCodeSetCondition(
        string $alias,
        array $codes,
        string $prefix,
        array &$params
    ): string {
        $placeholders = [];

        foreach (
            array_values(
                array_unique($codes)
            ) as $index => $code
        ) {
            $key =
                $prefix
                . '_'
                . $index;

            $placeholders[] =
                ':' . $key;

            $params[$key] =
                $code;
        }

        if ($placeholders === []) {
            return '0 = 1';
        }

        return sprintf(
            '%s.code IN (%s)',
            $alias,
            implode(
                ', ',
                $placeholders
            )
        );
    }

    private function isStatusCode(
        string $status
    ): bool {
        $statement = $this->pdo->prepare(
            'SELECT 1
             FROM ticket_statuses
             WHERE code = :code
               AND is_active = 1
             LIMIT 1'
        );

        $statement->execute([
            'code' => $status,
        ]);

        return $statement->fetchColumn()
            !== false;
    }

    private function textLength(
        string $value
    ): int {
        return function_exists('mb_strlen')
            ? mb_strlen(
                $value,
                'UTF-8'
            )
            : strlen($value);
    }
}
