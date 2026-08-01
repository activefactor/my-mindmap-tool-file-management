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

    /**
     * 監査ログ一覧（FR-08-6）。実行者のメールは結合して返す
     * （`actor_user_id` は ON DELETE SET NULL のため NULL になりうる）。
     *
     * @param array{action?: string, from?: string, to?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function paginate(int $limit, int $offset, array $filters = []): array
    {
        [$where, $params] = self::buildWhere($filters);

        $sql = 'SELECT l.id, l.action, l.actor_user_id, u.email AS actor_email,
                       l.target, l.detail, l.created_at
                FROM audit_logs l
                LEFT JOIN users u ON u.id = l.actor_user_id'
            . $where
            // 同一秒に複数件記録されうるため id を第2キーにして順序を安定させる
            . sprintf(' ORDER BY l.created_at DESC, l.id DESC LIMIT %d OFFSET %d', $limit, $offset);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /** @param array{action?: string, from?: string, to?: string} $filters */
    public function count(array $filters = []): int
    {
        [$where, $params] = self::buildWhere($filters);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs l' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * 監査ログに記録されている操作種別の一覧（フィルタUIの選択肢用）。
     *
     * @return array<int, string>
     */
    public function distinctActions(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action ASC');

        return $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param array{action?: string, from?: string, to?: string} $filters
     * @return array{0: string, 1: array<string, string>}
     */
    private static function buildWhere(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (isset($filters['action']) && $filters['action'] !== '') {
            $conditions[] = 'l.action = :action';
            $params['action'] = $filters['action'];
        }

        if (isset($filters['from']) && $filters['from'] !== '') {
            $conditions[] = 'l.created_at >= :from';
            $params['from'] = $filters['from'];
        }

        if (isset($filters['to']) && $filters['to'] !== '') {
            $conditions[] = 'l.created_at <= :to';
            $params['to'] = $filters['to'];
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }
}
