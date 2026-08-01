<?php

declare(strict_types=1);

namespace App\Tests;

use App\Admin\UserAdminService;
use App\Http\ApiException;
use App\Support\Database;
use App\Support\Env;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * ロール変更・有効化／無効化のテスト（FR-08-2, FR-08-5、基本設計書_Phase2.md §3.2）。
 *
 * 開発ステップ_Phase2.md Step 4 のテスト項目:
 * - 複数の管理者が同時に別の管理者を降格しても管理者が0人にならない（並行テスト）
 * - 自分自身のロール変更が拒否される
 */
final class UserAdminServiceTest extends TestCase
{
    private PDO $pdo;
    private UserAdminService $service;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->cleanUp();

        $this->service = new UserAdminService($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    public function testPromoteUserToAdmin(): void
    {
        $admin = $this->givenUser('admin1@company.co.jp', 'admin');
        $user = $this->givenUser('user1@company.co.jp', 'user');

        $before = $this->securityStampOf($user);

        $result = $this->service->changeRole($admin, $user, 'admin');

        $this->assertTrue($result['changed']);
        $this->assertSame('user', $result['before']);
        $this->assertSame('admin', $result['after']);
        $this->assertSame('admin', $this->roleOf($user));

        // 昇格も security_stamp を再生成する（既存セッションのロールを更新させるため）
        $this->assertNotSame($before, $this->securityStampOf($user));
    }

    public function testDemoteAdminRegeneratesSecurityStamp(): void
    {
        $actor = $this->givenUser('admin1@company.co.jp', 'admin');
        $target = $this->givenUser('admin2@company.co.jp', 'admin');

        $before = $this->securityStampOf($target);

        $this->service->changeRole($actor, $target, 'user');

        $this->assertSame('user', $this->roleOf($target));
        $this->assertNotSame(
            $before,
            $this->securityStampOf($target),
            'security_stamp が再生成されないと、降格された管理者の既存セッションが管理APIを使い続けられてしまう'
        );
    }

    public function testCannotChangeOwnRole(): void
    {
        $admin = $this->givenUser('admin1@company.co.jp', 'admin');
        $this->givenUser('admin2@company.co.jp', 'admin');

        try {
            $this->service->changeRole($admin, $admin, 'user');
            $this->fail('自分自身のロール変更が拒否されていない');
        } catch (ApiException $e) {
            $this->assertSame('cannot_modify_self', $e->errorCode);
            $this->assertSame(403, $e->status);
        }

        $this->assertSame('admin', $this->roleOf($admin));
    }

    public function testCannotDisableSelf(): void
    {
        $admin = $this->givenUser('admin1@company.co.jp', 'admin');
        $this->givenUser('admin2@company.co.jp', 'admin');

        try {
            $this->service->changeStatus($admin, $admin, 'disabled');
            $this->fail('自分自身の無効化が拒否されていない');
        } catch (ApiException $e) {
            $this->assertSame('cannot_modify_self', $e->errorCode);
        }

        $this->assertSame('active', $this->statusOf($admin));
    }

    public function testCannotDemoteLastAdmin(): void
    {
        $actor = $this->givenUser('admin1@company.co.jp', 'admin');
        $target = $this->givenUser('admin2@company.co.jp', 'admin');

        // admin2 を降格 → 残る管理者は admin1 のみ（成功する）
        $this->service->changeRole($actor, $target, 'user');

        // 続けて admin1 を降格しようとすると、管理者が0人になるため拒否される。
        // （自分自身は変更できないので、降格された admin2 が実行者という想定）
        try {
            $this->service->changeRole($target, $actor, 'user');
            $this->fail('最後の管理者が降格できてしまった');
        } catch (ApiException $e) {
            $this->assertSame('last_admin_protected', $e->errorCode);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(1, $this->activeAdminCount());
    }

    public function testCannotDisableLastAdmin(): void
    {
        $actor = $this->givenUser('user1@company.co.jp', 'user');
        $target = $this->givenUser('admin1@company.co.jp', 'admin');

        try {
            $this->service->changeStatus($actor, $target, 'disabled');
            $this->fail('最後の管理者が無効化できてしまった');
        } catch (ApiException $e) {
            $this->assertSame('last_admin_protected', $e->errorCode);
        }

        $this->assertSame(1, $this->activeAdminCount());
    }

    /**
     * 無効化された管理者は「有効な管理者」に数えない。
     * したがって、有効な管理者が1人しか残っていなければ降格は拒否される。
     */
    public function testDisabledAdminDoesNotCountAsRemainingAdmin(): void
    {
        $actor = $this->givenUser('admin1@company.co.jp', 'admin');
        $target = $this->givenUser('admin2@company.co.jp', 'admin');
        $disabled = $this->givenUser('admin3@company.co.jp', 'admin');

        $this->service->changeStatus($actor, $disabled, 'disabled');

        // 有効な管理者は admin1 と admin2 の2人。admin2 を降格すると admin1 だけになるので成功する
        $this->service->changeRole($actor, $target, 'user');

        // ここで admin1 を降格しようとすると、無効化済みの admin3 は数に入らないため拒否される
        try {
            $this->service->changeRole($target, $actor, 'user');
            $this->fail('無効化済みの管理者が「残る管理者」として数えられている');
        } catch (ApiException $e) {
            $this->assertSame('last_admin_protected', $e->errorCode);
        }
    }

    public function testNoOpChangeIsNotRecorded(): void
    {
        $actor = $this->givenUser('admin1@company.co.jp', 'admin');
        $target = $this->givenUser('user1@company.co.jp', 'user');

        $stampBefore = $this->securityStampOf($target);

        $result = $this->service->changeRole($actor, $target, 'user');

        $this->assertFalse($result['changed']);
        // 変化がないなら security_stamp を回さない（無関係なログアウトを起こさない）
        $this->assertSame($stampBefore, $this->securityStampOf($target));
        $this->assertSame(0, $this->auditCount('admin_role_changed'));
    }

    public function testRoleChangeIsAudited(): void
    {
        $actor = $this->givenUser('admin1@company.co.jp', 'admin');
        $target = $this->givenUser('user1@company.co.jp', 'user');

        $this->service->changeRole($actor, $target, 'admin');

        $stmt = $this->pdo->query(
            "SELECT actor_user_id, target, detail FROM audit_logs WHERE action = 'admin_role_changed'"
        );
        $row = $stmt === false ? false : $stmt->fetch();

        $this->assertNotFalse($row);
        $this->assertSame($actor, (int) $row['actor_user_id']);
        $this->assertSame('user1@company.co.jp', $row['target']);

        $detail = json_decode((string) $row['detail'], true);

        $this->assertSame('user', $detail['before']);
        $this->assertSame('admin', $detail['after']);
        $this->assertSame($target, $detail['target_user_id']);
    }

    public function testUnknownUserIsRejected(): void
    {
        $actor = $this->givenUser('admin1@company.co.jp', 'admin');

        try {
            $this->service->changeRole($actor, 999999, 'admin');
            $this->fail('存在しないユーザーへの操作が拒否されていない');
        } catch (ApiException $e) {
            $this->assertSame('user_not_found', $e->errorCode);
            $this->assertSame(404, $e->status);
        }
    }

    /**
     * 並行テスト（Step 4 のテスト項目）。
     *
     * 2人の管理者が同時に「相手ではないもう1人の管理者」を降格しようとする状況を、
     * 別々のDB接続で再現する。最後の管理者チェックが `SELECT ... FOR UPDATE` を
     * 使っているため、後続の接続は先行接続が保持する行ロックを待つことになり、
     * ロック待ちタイムアウト（またはデッドロック）で中断される。
     *
     * 重要なのは「両方が成功して管理者が0人になる」ことが起きない点。
     * サービスはこれを 409 concurrent_modification に変換して返す。
     */
    public function testConcurrentDemotionCannotLeaveZeroAdmins(): void
    {
        $adminA = $this->givenUser('admin-a@company.co.jp', 'admin');
        $adminB = $this->givenUser('admin-b@company.co.jp', 'admin');

        // 接続1: adminB を降格する処理の途中（行ロック取得済み・未コミット）を再現する
        $conn1 = $this->newConnection();
        $conn1->beginTransaction();
        $conn1->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE')->execute(['id' => $adminB]);
        $conn1->prepare(
            "SELECT id FROM users WHERE role = 'admin' AND status = 'active' AND id <> :excluded LIMIT 1 FOR UPDATE"
        )->execute(['excluded' => $adminB]);

        // 接続2: 同時に adminA を降格しようとする。
        // 接続1 が adminA の行をロックしているため待たされ、タイムアウトする。
        $conn2 = $this->newConnection();
        $conn2->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $service2 = new UserAdminService($conn2);

        try {
            $service2->changeRole($adminB, $adminA, 'user');
            $this->fail('競合しているはずの降格が成功してしまった');
        } catch (ApiException $e) {
            $this->assertSame('concurrent_modification', $e->errorCode);
            $this->assertSame(409, $e->status);
        }

        // 接続1 の側の降格だけを確定させる
        $conn1->prepare("UPDATE users SET role = 'user' WHERE id = :id")->execute(['id' => $adminB]);
        $conn1->commit();

        $this->assertSame(
            1,
            $this->activeAdminCount(),
            '同時降格の結果、有効な管理者が残っていない'
        );
    }

    private function newConnection(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            Env::get('DB_HOST', 'mysql'),
            Env::get('DB_NAME', 'mindmap')
        );

        return new PDO($dsn, (string) Env::get('DB_USER', ''), (string) Env::get('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function givenUser(string $email, string $role, string $status = 'active'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, display_name, role, status, security_stamp)
             VALUES (:email, :name, :role, :status, :stamp)'
        );
        $stmt->execute([
            'email' => $email,
            'name' => $email,
            'role' => $role,
            'status' => $status,
            'stamp' => bin2hex(random_bytes(16)),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function roleOf(int $userId): string
    {
        return (string) $this->columnOf($userId, 'role');
    }

    private function statusOf(int $userId): string
    {
        return (string) $this->columnOf($userId, 'status');
    }

    private function securityStampOf(int $userId): string
    {
        return (string) $this->columnOf($userId, 'security_stamp');
    }

    private function columnOf(int $userId, string $column): mixed
    {
        // $column はテスト内の固定値のみ
        $stmt = $this->pdo->prepare("SELECT {$column} FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);

        return $stmt->fetchColumn();
    }

    private function activeAdminCount(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    private function auditCount(string $action): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = :action');
        $stmt->execute(['action' => $action]);

        return (int) $stmt->fetchColumn();
    }

    private function cleanUp(): void
    {
        $this->pdo->exec('DELETE FROM audit_logs');
        $this->pdo->exec('DELETE FROM user_identities');
        $this->pdo->exec('DELETE FROM users');
    }
}
