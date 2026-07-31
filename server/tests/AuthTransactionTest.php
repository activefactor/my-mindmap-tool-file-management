<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth\AuthException;
use App\Auth\AuthTransaction;
use App\Auth\ProviderConfig;
use PHPUnit\Framework\TestCase;

/**
 * state / PKCE / nonce の取り扱いのテスト。
 * 外部レビュー §7「認証」の「state 不一致、期限切れ、再利用を拒否する」に対応。
 *
 * @runTestsInSeparateProcesses は使わず、$_SESSION を直接操作して検証する。
 */
final class AuthTransactionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testConsumeAcceptsMatchingState(): void
    {
        $tx = AuthTransaction::start(ProviderConfig::GOOGLE);

        $restored = AuthTransaction::consume(ProviderConfig::GOOGLE, $tx->state);

        $this->assertSame($tx->state, $restored->state);
        $this->assertSame($tx->codeVerifier, $restored->codeVerifier);
        $this->assertSame($tx->nonce, $restored->nonce);
    }

    public function testRejectsMismatchedState(): void
    {
        AuthTransaction::start(ProviderConfig::GOOGLE);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/state が一致しません/');

        AuthTransaction::consume(ProviderConfig::GOOGLE, 'tampered-state');
    }

    public function testStateCannotBeReused(): void
    {
        $tx = AuthTransaction::start(ProviderConfig::GOOGLE);

        // 1回目は成功
        AuthTransaction::consume(ProviderConfig::GOOGLE, $tx->state);

        // 2回目はリプレイとして拒否されなければならない
        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/セッションに存在しません/');

        AuthTransaction::consume(ProviderConfig::GOOGLE, $tx->state);
    }

    public function testRejectsStateReusedAfterFailedAttempt(): void
    {
        $tx = AuthTransaction::start(ProviderConfig::GOOGLE);

        // 失敗した試行でもトランザクションは破棄される
        try {
            AuthTransaction::consume(ProviderConfig::GOOGLE, 'wrong');
        } catch (AuthException) {
            // 期待どおり
        }

        $this->expectException(AuthException::class);

        AuthTransaction::consume(ProviderConfig::GOOGLE, $tx->state);
    }

    public function testRejectsExpiredState(): void
    {
        $tx = AuthTransaction::start(ProviderConfig::GOOGLE);

        // 有効期限(10分)を超過させる
        $_SESSION['oidc_tx']['created_at'] = time() - 601;

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/有効期限/');

        AuthTransaction::consume(ProviderConfig::GOOGLE, $tx->state);
    }

    public function testRejectsStateIssuedForDifferentProvider(): void
    {
        $tx = AuthTransaction::start(ProviderConfig::GOOGLE);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessageMatches('/別のプロバイダ/');

        AuthTransaction::consume(ProviderConfig::MICROSOFT, $tx->state);
    }

    public function testCodeChallengeIsS256OfVerifier(): void
    {
        $tx = AuthTransaction::start(ProviderConfig::GOOGLE);

        $expected = rtrim(strtr(base64_encode(hash('sha256', $tx->codeVerifier, true)), '+/', '-_'), '=');

        $this->assertSame($expected, $tx->codeChallenge());
        // base64url なので + / = を含まないこと
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $tx->codeChallenge());
    }
}
