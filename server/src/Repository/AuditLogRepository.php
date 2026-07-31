<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Database;
use PDO;

/**
 * 監査ログ（基本設計書_Phase2.md §6.7）。
 * OAuthトークン類・マインドマップ本文は絶対に記録しない（同 §6.5）。
 */
final class AuditLogRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /** @param array<string, mixed>|null $detail */
    public function record(string $action, ?int $actorUserId = null, ?string $target = null, ?array $detail = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, target, detail)
             VALUES (:actor_user_id, :action, :target, :detail)'
        );
        $stmt->execute([
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'target' => $target,
            'detail' => $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
