<?php

declare(strict_types=1);

namespace App\Tests;

use App\Repository\AllowListRepository;
use App\Repository\AuditLogRepository;
use App\Repository\StorageUsageRepository;
use App\Repository\UserRepository;
use App\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * 管理コンソールが使う参照・更新系リポジトリのテスト（FR-08-1/3/4/6/7）。
 */
final class AdminRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
    }

    public function testAllowListAddIsIdempotentAndCaseInsensitive(): void
    {
        $admin = $this->givenUser('admin@company.co.jp');
        $repo = new AllowListRepository($this->pdo);

        $id = $repo->addDomain('Company.CO.JP', $admin);

        $this->assertNotNull($id);
        $this->assertTrue($repo->domainExists('company.co.jp'));

        // UNIQUE制約に任せているため、重複追加は null が返る（例外にしない）
        $this->assertNull($repo->addDomain('company.co.jp', $admin));
        $this->assertNull($repo->addDomain('COMPANY.CO.JP', $admin));
        $this->assertCount(1, $repo->listDomains());
    }

    public function testAllowListRemoveReturnsRemovedValue(): void
    {
        $admin = $this->givenUser('admin@company.co.jp');
        $repo = new AllowListRepository($this->pdo);

        $id = $repo->addEmail('someone@gmail.com', $admin);

        $this->assertSame('someone@gmail.com', $repo->removeEmail((int) $id));
        $this->assertFalse($repo->emailExists('someone@gmail.com'));

        // 既に削除済み／存在しないIDは null（コントローラ側で404にする）
        $this->assertNull($repo->removeEmail((int) $id));
    }

    public function testAllowListShowsWhoAdded(): void
    {
        $admin = $this->givenUser('admin@company.co.jp');
        $repo = new AllowListRepository($this->pdo);

        $repo->addDomain('company.co.jp', $admin);

        $rows = $repo->listDomains();

        $this->assertSame('company.co.jp', $rows[0]['value']);
        $this->assertSame('admin@company.co.jp', $rows[0]['created_by_email']);
    }

    public function testUserSearchEscapesLikeWildcards(): void
    {
        $this->givenUser('alice@company.co.jp');
        $this->givenUser('bob@company.co.jp');

        $repo = new UserRepository($this->pdo);

        $this->assertSame(2, $repo->countAll());
        $this->assertSame(1, $repo->countAll('alice'));

        // `%` がワイルドカードとして解釈されると全件ヒットしてしまう
        $this->assertSame(0, $repo->countAll('%'));
        $this->assertSame(0, $repo->countAll('_'));
    }

    public function testUserPaginationIsStable(): void
    {
        foreach (['a', 'b', 'c'] as $name) {
            $this->givenUser("{$name}@company.co.jp");
        }

        $repo = new UserRepository($this->pdo);

        $firstPage = $repo->paginate(2, 0);
        $secondPage = $repo->paginate(2, 2);

        $this->assertCount(2, $firstPage);
        $this->assertCount(1, $secondPage);

        // 一覧に security_stamp を含めない（機微情報をクライアントへ出さない）
        $this->assertArrayNotHasKey('security_stamp', $firstPage[0]);
    }

    public function testAuditLogFilterByActionAndPeriod(): void
    {
        $actor = $this->givenUser('admin@company.co.jp');
        $repo = new AuditLogRepository($this->pdo);

        $repo->record('login', $actor, 'admin@company.co.jp');
        $repo->record('admin_role_changed', $actor, 'user@company.co.jp', ['before' => 'user']);

        $this->assertSame(2, $repo->count());
        $this->assertSame(1, $repo->count(['action' => 'login']));
        $this->assertSame(['admin_role_changed', 'login'], $repo->distinctActions());

        // 未来の日付以降で絞れば0件
        $this->assertSame(0, $repo->count(['from' => '2999-01-01 00:00:00']));
        $this->assertSame(2, $repo->count(['from' => '2000-01-01 00:00:00']));
    }

    public function testAuditLogListJoinsActorEmail(): void
    {
        $actor = $this->givenUser('admin@company.co.jp');
        $repo = new AuditLogRepository($this->pdo);

        $repo->record('admin_role_changed', $actor, 'user@company.co.jp', ['after' => 'admin']);

        $rows = $repo->paginate(10, 0);

        $this->assertCount(1, $rows);
        $this->assertSame('admin@company.co.jp', $rows[0]['actor_email']);
        $this->assertSame('user@company.co.jp', $rows[0]['target']);
    }

    public function testStorageUsageIncludesUsersWithoutMaps(): void
    {
        $user = $this->givenUser('user@company.co.jp');

        $repo = new StorageUsageRepository($this->pdo);
        $rows = $repo->perUser();

        $this->assertCount(1, $rows);
        $this->assertSame($user, (int) $rows[0]['user_id']);
        $this->assertSame(0, (int) $rows[0]['map_count']);
        $this->assertSame(0, (int) $rows[0]['approx_bytes']);

        // LEFT JOIN の NULL 行を「有効なマップ1件」と数えてしまわないこと
        $this->assertSame(0, (int) $rows[0]['active_map_count']);
        $this->assertSame(0, (int) $rows[0]['trashed_map_count']);
    }

    public function testStorageUsageSeparatesTrashedMaps(): void
    {
        $user = $this->givenUser('user@company.co.jp');

        $this->givenMindmap($user, '{"nodes":[]}', null);
        $this->givenMindmap($user, '{"nodes":[{"id":"1"}]}', '2026-08-01 00:00:00');

        $rows = (new StorageUsageRepository($this->pdo))->perUser();

        $this->assertSame(2, (int) $rows[0]['map_count']);
        $this->assertSame(1, (int) $rows[0]['active_map_count']);
        // ゴミ箱の中身も容量は消費しているので合計には含める
        $this->assertSame(1, (int) $rows[0]['trashed_map_count']);
        $this->assertGreaterThan(0, (int) $rows[0]['approx_bytes']);
    }

    private function givenUser(string $email): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, display_name, security_stamp) VALUES (:email, :name, :stamp)'
        );
        $stmt->execute(['email' => $email, 'name' => $email, 'stamp' => bin2hex(random_bytes(16))]);

        return (int) $this->pdo->lastInsertId();
    }

    private function givenMindmap(int $userId, string $data, ?string $deletedAt): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mindmaps (user_id, title, data, deleted_at)
             VALUES (:user_id, :title, :data, :deleted_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'title' => 'テスト',
            'data' => $data,
            'deleted_at' => $deletedAt,
        ]);
    }

    private function cleanUp(): void
    {
        $this->pdo->exec('DELETE FROM mindmaps');
        $this->pdo->exec('DELETE FROM allowed_domains');
        $this->pdo->exec('DELETE FROM allowed_emails');
        $this->pdo->exec('DELETE FROM audit_logs');
        $this->pdo->exec('DELETE FROM user_identities');
        $this->pdo->exec('DELETE FROM users');
    }
}
