<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\HttpException;
use PDO;

final class NotificationService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $user @return array{notification_id:int,unread_notifications:int} */
    public function markRead(array $user, int $notificationId): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1 || $notificationId < 1) {
            throw new HttpException(404, 'notification_not_found', 'La notificación no existe.');
        }

        $statement = $this->pdo->prepare(
            'UPDATE notifications
             SET read_at = COALESCE(read_at, UTC_TIMESTAMP())
             WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $notificationId, 'user_id' => $userId]);

        $exists = $this->pdo->prepare('SELECT 1 FROM notifications WHERE id = :id AND user_id = :user_id LIMIT 1');
        $exists->execute(['id' => $notificationId, 'user_id' => $userId]);
        if ($exists->fetchColumn() === false) {
            throw new HttpException(404, 'notification_not_found', 'La notificación no existe.');
        }

        return [
            'notification_id' => $notificationId,
            'unread_notifications' => $this->unreadCount($userId),
        ];
    }

    /** @param array<string, mixed> $user @return array{updated:int,unread_notifications:int} */
    public function markAllRead(array $user): array
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1) {
            throw new HttpException(401, 'unauthenticated', 'Debes iniciar sesión.');
        }

        $statement = $this->pdo->prepare(
            'UPDATE notifications
             SET read_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);

        return [
            'updated' => $statement->rowCount(),
            'unread_notifications' => 0,
        ];
    }

    private function unreadCount(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND read_at IS NULL'
        );
        $statement->execute(['user_id' => $userId]);
        return (int) $statement->fetchColumn();
    }
}
