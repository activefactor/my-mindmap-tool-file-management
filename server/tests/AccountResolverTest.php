<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth\AccessPolicy;
use App\Auth\AccountResolver;
use App\Auth\AuthException;
use App\Auth\ProviderConfig;
use App\Repository\AllowListRepository;
use App\Repository\UserRepository;
use App\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * アカウント解決・許可判定の統合テスト（実際のMySQLに対して実行する）。
 *
 * 外部レビュー §7「認証」の以下に対応:
 * - 同じメールアドレスでも異なる主体を自動統合しない
 * - メールアドレス再利用時に旧ユーザーのデータへアクセスできない
 * - Google の hd が許可ドメインと一致しない場合を拒否する
 */
final class AccountResolverTest extends TestCase
{
    private PDO $pdo;
    private UserRepository $users;
    private AccountResolver $resolver;

    protected function setUp(): void
    {
        $this->pdo = Database::connection();
        $this->pdo->exec('DELETE FROM user_identities');
        $this->pdo->exec('DELETE FROM users');
        $this->pdo->exec('DELETE FROM allowed_domains');
        $this->pdo->exec('DELETE FROM allowed_emails');

        $this->users = new UserRepository($this->pdo);
        $this->resolver = new AccountResolver($this->users);
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DELETE FROM user_identities');
        $this->pdo->exec('DELETE FROM users');
        $this->pdo->exec('DELETE FROM allowed_domains');
        $this->pdo->exec('DELETE FROM allowed_emails');
    }

    public function testFirstLoginCreatesUserAndIdentity(): void
    {
        $result = $this->resolver->resolve(
            ProviderConfig::GOOGLE,
            'google-sub-1',
            'user@company.co.jp',
            'ユーザー1'
        );

        $this->assertTrue($result['is_new']);
        $this->assertSame('user@company.co.jp', $result['user']['email']);
        $this->assertSame(1, $this->users->countIdentities((int) $result['user']['id']));
    }

    public function testSecondLoginReusesSameAccount(): void
    {
        $first = $this->resolver->resolve(ProviderConfig::GOOGLE, 'google-sub-1', 'user@company.co.jp', 'ユーザー1');
        $second = $this->resolver->resolve(ProviderConfig::GOOGLE, 'google-sub-1', 'user@company.co.jp', 'ユーザー1');

        $this->assertFalse($second['is_new']);
        $this->assertSame($first['user']['id'], $second['user']['id']);
    }

    /**
     * 初期管理者ブートストラップ（db/seed.php）で作成された、identityを持たないユーザー行への
     * 初回ログイン。基本設計書 v2.2 まではこれが conflict になり、初期管理者が永久にログイン
     * できない欠陥があった（v2.3で修正）。
     */
    public function testSeededAdminWithoutIdentityCanLogInForTheFirstTime(): void
    {
        $this->pdo->prepare(
            'INSERT INTO users (email, display_name, role, status, security_stamp)
             VALUES (:email, :name, :role, :status, :stamp)'
        )->execute([
            'email' => 'admin@company.co.jp',
            'name' => '初期管理者',
            'role' => 'admin',
            'status' => 'active',
            'stamp' => str_repeat('a', 32),
        ]);

        $result = $this->resolver->resolve(
            ProviderConfig::GOOGLE,
            'google-sub-admin',
            'admin@company.co.jp',
            '管理者'
        );

        $this->assertFalse($result['is_new']);
        $this->assertSame('admin', $result['user']['role']);
        $this->assertSame(1, $this->users->countIdentities((int) $result['user']['id']));
    }

    /**
     * 退職者のメールアドレスが新しい社員に再割り当てされたケース。
     * 別の sub でログインしてきても、既存アカウント（＝退職者のマップを持つ）へ
     * 自動的に紐付いてはならない。
     */
    public function testDoesNotAutoMergeDifferentSubjectWithSameEmail(): void
    {
        $this->resolver->resolve(ProviderConfig::GOOGLE, 'google-sub-old', 'shared@company.co.jp', '退職者');

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/別のログイン方法/');

        $this->resolver->resolve(ProviderConfig::GOOGLE, 'google-sub-new', 'shared@company.co.jp', '新入社員');
    }

    /** 同一メールでもプロバイダが違えば自動統合しない（Google→Microsoft）。 */
    public function testDoesNotAutoMergeAcrossProviders(): void
    {
        $this->resolver->resolve(ProviderConfig::GOOGLE, 'google-sub-1', 'user@company.co.jp', 'ユーザー');

        $this->expectException(AuthException::class);

        $this->resolver->resolve(ProviderConfig::MICROSOFT, 'ms-oid-1', 'user@company.co.jp', 'ユーザー');
    }

    // --- 許可判定 ---

    public function testAccessPolicyAllowsListedDomainForWorkspaceAccount(): void
    {
        $this->pdo->exec("INSERT INTO allowed_domains (domain) VALUES ('company.co.jp')");

        $policy = new AccessPolicy(new AllowListRepository($this->pdo));

        $policy->assertAllowed(ProviderConfig::GOOGLE, 'user@company.co.jp', [
            'email_verified' => true,
            'hd' => 'company.co.jp',
        ]);

        $this->addToAssertionCount(1);
    }

    public function testAccessPolicyRejectsUnlistedDomain(): void
    {
        $policy = new AccessPolicy(new AllowListRepository($this->pdo));

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/許可されていないドメイン/');

        $policy->assertAllowed(ProviderConfig::GOOGLE, 'user@evil.example', [
            'email_verified' => true,
            'hd' => 'evil.example',
        ]);
    }

    /** hd を詐称してもメールのドメインと食い違えば拒否される。 */
    public function testAccessPolicyRejectsMismatchedHd(): void
    {
        $this->pdo->exec("INSERT INTO allowed_domains (domain) VALUES ('company.co.jp')");

        $policy = new AccessPolicy(new AllowListRepository($this->pdo));

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/hd クレーム/');

        $policy->assertAllowed(ProviderConfig::GOOGLE, 'attacker@evil.example', [
            'email_verified' => true,
            'hd' => 'company.co.jp',
        ]);
    }

    /** Googleの個人アカウント（hdなし）はドメイン許可では入れない。 */
    public function testAccessPolicyRejectsConsumerGoogleAccountByDomain(): void
    {
        $this->pdo->exec("INSERT INTO allowed_domains (domain) VALUES ('gmail.com')");

        $policy = new AccessPolicy(new AllowListRepository($this->pdo));

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/個人アカウント/');

        $policy->assertAllowed(ProviderConfig::GOOGLE, 'someone@gmail.com', ['email_verified' => true]);
    }

    /** 個別に許可されたGmailアドレスは利用できる（FR-08-4）。 */
    public function testAccessPolicyAllowsIndividuallyListedEmail(): void
    {
        $this->pdo->exec("INSERT INTO allowed_emails (email) VALUES ('partner@gmail.com')");

        $policy = new AccessPolicy(new AllowListRepository($this->pdo));

        $policy->assertAllowed(ProviderConfig::GOOGLE, 'partner@gmail.com', ['email_verified' => true]);

        $this->addToAssertionCount(1);
    }

    public function testAccessPolicyRejectsUnverifiedGoogleEmail(): void
    {
        $this->pdo->exec("INSERT INTO allowed_domains (domain) VALUES ('company.co.jp')");

        $policy = new AccessPolicy(new AllowListRepository($this->pdo));

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/未検証/');

        $policy->assertAllowed(ProviderConfig::GOOGLE, 'user@company.co.jp', [
            'email_verified' => false,
            'hd' => 'company.co.jp',
        ]);
    }
}
