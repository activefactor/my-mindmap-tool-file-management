<?php

declare(strict_types=1);

namespace App\Admin;

use App\Http\ApiException;
use App\Repository\UserRepository;
use App\Support\Database;
use PDO;
use PDOException;
use Throwable;

/**
 * ユーザーのロール変更・有効化／無効化（FR-08-2, FR-08-5、基本設計書_Phase2.md §3.2）。
 *
 * このクラスの存在理由は「最後の管理者保護」を競合状態に対して安全に行うこと。
 * 対象行を `SELECT ... FOR UPDATE` でロックし、同一トランザクション内で
 * 「更新後に有効な管理者が1人以上残るか」を再確認してから UPDATE する。
 * 事前チェックと更新を別トランザクションに分けない。
 *
 * `security_stamp` の再生成と監査ログの記録も同一トランザクションで行うため、
 * 「ロールは変わったが監査ログが残っていない」「降格したのに旧セッションが生き残る」
 * といった中途半端な状態が発生しない。
 */
final class UserAdminService
{
    public const ROLES = ['user', 'admin'];
    public const STATUSES = ['active', 'disabled'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /**
     * ロールを変更する。
     *
     * @return array{changed: bool, before: string, after: string}
     */
    public function changeRole(int $actorUserId, int $targetUserId, string $newRole): array
    {
        // 自分自身のロール変更は禁止する（§3.2）。昇格は無意味（すでに管理者）であり、
        // 降格は自らを締め出す事故にしかならないため、方向によらず一律で拒否する。
        if ($actorUserId === $targetUserId) {
            throw new ApiException('cannot_modify_self', 403, '自分自身のロールは変更できません。');
        }

        return $this->transactional(function () use ($actorUserId, $targetUserId, $newRole): array {
            $target = $this->lockUser($targetUserId);
            $before = (string) $target['role'];

            if ($before === $newRole) {
                return ['changed' => false, 'before' => $before, 'after' => $newRole];
            }

            // 管理者からの降格のみ、最後の管理者でないことを確認する
            if ($before === 'admin' && $newRole === 'user') {
                $this->assertAnotherActiveAdminExists($targetUserId);
            }

            $this->pdo->prepare(
                'UPDATE users SET role = :role, security_stamp = :stamp WHERE id = :id'
            )->execute([
                'role' => $newRole,
                'stamp' => UserRepository::newSecurityStamp(),
                'id' => $targetUserId,
            ]);

            $this->recordAudit('admin_role_changed', $actorUserId, (string) $target['email'], [
                'target_user_id' => $targetUserId,
                'before' => $before,
                'after' => $newRole,
            ]);

            return ['changed' => true, 'before' => $before, 'after' => $newRole];
        });
    }

    /**
     * 有効化／無効化する。
     *
     * @return array{changed: bool, before: string, after: string}
     */
    public function changeStatus(int $actorUserId, int $targetUserId, string $newStatus): array
    {
        // 自分自身の無効化も禁止する。設計書は「自分自身のロール変更」のみを明示しているが、
        // 自己無効化は同じ「自分を締め出す」事故であり、無効化した本人には元に戻す手段が
        // 無い（管理APIは status=active を要求する）ため同様に拒否する。
        if ($actorUserId === $targetUserId) {
            throw new ApiException('cannot_modify_self', 403, '自分自身の状態は変更できません。');
        }

        return $this->transactional(function () use ($actorUserId, $targetUserId, $newStatus): array {
            $target = $this->lockUser($targetUserId);
            $before = (string) $target['status'];

            if ($before === $newStatus) {
                return ['changed' => false, 'before' => $before, 'after' => $newStatus];
            }

            // 有効な管理者を無効化する場合のみ、最後の管理者でないことを確認する
            if ($target['role'] === 'admin' && $newStatus === 'disabled') {
                $this->assertAnotherActiveAdminExists($targetUserId);
            }

            // status を変えたら security_stamp も再生成する。無効化を即座に反映するのは
            // もちろん、再有効化時も旧セッションを引き継がせないため（NFR-S-10）。
            $this->pdo->prepare(
                'UPDATE users SET status = :status, security_stamp = :stamp WHERE id = :id'
            )->execute([
                'status' => $newStatus,
                'stamp' => UserRepository::newSecurityStamp(),
                'id' => $targetUserId,
            ]);

            $this->recordAudit('admin_status_changed', $actorUserId, (string) $target['email'], [
                'target_user_id' => $targetUserId,
                'before' => $before,
                'after' => $newStatus,
            ]);

            return ['changed' => true, 'before' => $before, 'after' => $newStatus];
        });
    }

    /**
     * 対象ユーザー行を排他ロックして取得する。
     *
     * @return array<string, mixed>
     */
    private function lockUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, role, status FROM users WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $userId]);

        $row = $stmt->fetch();

        if ($row === false) {
            throw new ApiException('user_not_found', 404, "ユーザーが存在しません: {$userId}");
        }

        return $row;
    }

    /**
     * 対象を除いて有効な管理者が残るかを、行ロックを取りながら確認する。
     *
     * `FOR UPDATE` を付けているのが要点。2人の管理者が同時に互いを降格しようとすると、
     * 双方が相手の行のロックを待つためデッドロック（またはロック待ちタイムアウト）となり、
     * MySQL 側でどちらか一方が中断される。結果として「両方が事前チェックを通過して
     * 管理者がゼロになる」事故が構造的に起きない。中断された側には 409 を返す。
     */
    private function assertAnotherActiveAdminExists(int $excludedUserId): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM users
             WHERE role = 'admin' AND status = 'active' AND id <> :excluded
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['excluded' => $excludedUserId]);

        if ($stmt->fetchColumn() === false) {
            throw new ApiException(
                'last_admin_protected',
                409,
                '最後の有効な管理者を降格・無効化することはできません。'
            );
        }
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function recordAudit(string $action, int $actorUserId, string $target, array $detail): void
    {
        $this->pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, target, detail)
             VALUES (:actor_user_id, :action, :target, :detail)'
        )->execute([
            'actor_user_id' => $actorUserId,
            'action' => $action,
            'target' => $target,
            'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (PDOException $e) {
            $this->rollBackQuietly();

            // 1213: デッドロック / 1205: ロック待ちタイムアウト。
            // いずれも同時実行による競合であり、クライアントは再試行すればよい。
            $driverCode = $e->errorInfo[1] ?? null;

            if ($driverCode === 1213 || $driverCode === 1205) {
                throw new ApiException(
                    'concurrent_modification',
                    409,
                    '他の管理操作と競合したため中断しました: ' . $e->getMessage()
                );
            }

            throw $e;
        } catch (Throwable $e) {
            $this->rollBackQuietly();

            throw $e;
        }
    }

    private function rollBackQuietly(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
