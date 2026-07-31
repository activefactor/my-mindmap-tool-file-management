<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth\SessionManager;
use App\Repository\UserRepository;
use App\Support\Database;
use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * セッション失効のテスト（NFR-S-10）。
 *
 * 外部レビュー §7「認可」の以下に対応:
 * - 無効化されたユーザーの既存セッションが使用できない
 * - 降格された管理者の既存セッションで管理APIを利用できない
 *
 * session_* 関数を扱うため各テストを別プロセスで実行する。
 */
final class SessionManagerTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $users;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->pdo->exec('DELETE FROM user_identities');
        $this->pdo->exec('DELETE FROM users');

        $this->users = new UserRepository($this->pdo);

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, display_name, role, status, security_stamp)
             VALUES (:email, :name, :role, :status, :stamp)'
        );
        $stmt->execute([
            'email' => 'session-test@company.co.jp',
            'name' => 'セッションテスト',
            'role' => 'admin',
            'status' => 'active',
            'stamp' => str_repeat('a', 32),
        ]);

        $this->userId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM user_identities');
        $this->pdo->exec('DELETE FROM users');
    }

    #[RunInSeparateProcess]
    public function testActiveUserSessionIsValid(): void
    {
        $this->givenLoggedInSession(str_repeat('a', 32));

        $user = SessionManager::currentUser($this->users);

        $this->assertNotNull($user);
        $this->assertSame($this->userId, (int) $user['id']);
    }

    #[RunInSeparateProcess]
    public function testDisabledUserSessionIsRejected(): void
    {
        $this->givenLoggedInSession(str_repeat('a', 32));

        // 管理者がユーザーを無効化した
        $this->pdo->prepare("UPDATE users SET status = 'disabled' WHERE id = :id")
            ->execute(['id' => $this->userId]);

        $this->assertNull(SessionManager::currentUser($this->users));
    }

    /**
     * ロール変更（降格）時は security_stamp を再生成する運用のため、
     * 既存セッションは次のリクエストで失効しなければならない。
     */
    #[RunInSeparateProcess]
    public function testSessionIsRejectedAfterSecurityStampRotation(): void
    {
        $this->givenLoggedInSession(str_repeat('a', 32));

        $this->pdo->prepare('UPDATE users SET role = :role, security_stamp = :stamp WHERE id = :id')
            ->execute(['role' => 'user', 'stamp' => str_repeat('b', 32), 'id' => $this->userId]);

        $this->assertNull(SessionManager::currentUser($this->users));
    }

    #[RunInSeparateProcess]
    public function testIdleTimeoutExpiresSession(): void
    {
        $this->givenLoggedInSession(str_repeat('a', 32));

        // 既定のアイドルタイムアウト(60分)を超過させる
        $_SESSION['last_activity'] = time() - (61 * 60);

        $this->assertNull(SessionManager::currentUser($this->users));
    }

    #[RunInSeparateProcess]
    public function testCsrfTokenVerification(): void
    {
        session_start();

        $token = SessionManager::rotateCsrfToken();

        $this->assertTrue(SessionManager::verifyCsrfToken($token));
        $this->assertFalse(SessionManager::verifyCsrfToken('wrong-token'));
        $this->assertFalse(SessionManager::verifyCsrfToken(null));

        // ローテーションすると古いトークンは無効になる
        $newToken = SessionManager::rotateCsrfToken();

        $this->assertNotSame($token, $newToken);
        $this->assertFalse(SessionManager::verifyCsrfToken($token));
    }

    private function givenLoggedInSession(string $securityStamp): void
    {
        session_start();

        $_SESSION['user_id'] = $this->userId;
        $_SESSION['role'] = 'admin';
        $_SESSION['security_stamp'] = $securityStamp;
        $_SESSION['last_activity'] = time();
    }
}
