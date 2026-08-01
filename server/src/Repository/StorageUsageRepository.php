<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Database;
use PDO;

/**
 * ストレージ使用状況の概算（FR-08-7、基本設計書_Phase2.md §3.2）。
 *
 * 専用の集計テーブルは持たず、都度 `SUM(LENGTH(data))` で算出する。
 * これは **JSONデータのバイト数** であって実ディスク使用量ではない（InnoDBのページ・
 * インデックス・JSONのバイナリ表現は含まない）。画面側でもその旨を明示すること。
 */
final class StorageUsageRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /**
     * ユーザーごとの概算使用量。マップを1件も持たないユーザーも 0 件として返す
     * （利用実態の把握が目的のため、LEFT JOIN で全ユーザーを対象にする）。
     *
     * ゴミ箱の中身（`deleted_at IS NOT NULL`）は完全削除まで容量を占めるため、
     * 使用量には含めたうえで内訳として分けて返す。
     *
     * @return array<int, array<string, mixed>>
     */
    public function perUser(): array
    {
        $stmt = $this->pdo->query(
            'SELECT u.id AS user_id,
                    u.email,
                    u.display_name,
                    COUNT(m.id) AS map_count,
                    -- LEFT JOIN でマップが無いユーザーの行は m.* が NULL になる。
                    -- `m.deleted_at IS NULL` だけで判定すると、その NULL 行まで
                    -- 「有効なマップ1件」と数えてしまうため m.id IS NOT NULL を併記する。
                    COALESCE(SUM(CASE WHEN m.id IS NOT NULL AND m.deleted_at IS NULL THEN 1 ELSE 0 END), 0) AS active_map_count,
                    COALESCE(SUM(CASE WHEN m.deleted_at IS NOT NULL THEN 1 ELSE 0 END), 0) AS trashed_map_count,
                    COALESCE(SUM(LENGTH(m.data)), 0) AS approx_bytes
             FROM users u
             LEFT JOIN mindmaps m ON m.user_id = u.id
             GROUP BY u.id, u.email, u.display_name
             ORDER BY approx_bytes DESC, u.id ASC'
        );

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** @return array{map_count: int, approx_bytes: int} */
    public function total(): array
    {
        $stmt = $this->pdo->query(
            'SELECT COUNT(*) AS map_count, COALESCE(SUM(LENGTH(data)), 0) AS approx_bytes FROM mindmaps'
        );

        $row = $stmt === false ? false : $stmt->fetch();

        if ($row === false) {
            return ['map_count' => 0, 'approx_bytes' => 0];
        }

        return [
            'map_count' => (int) $row['map_count'],
            'approx_bytes' => (int) $row['approx_bytes'],
        ];
    }
}
