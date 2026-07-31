<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * 認証開始時にセッションへ保存する一時情報（state / PKCE / nonce）。
 *
 * 外部レビュー §2.6 の要求により、state は「一回限り使用・有効期限つき・providerと紐付け」
 * とする（基本設計書_Phase2.md §3.1）。
 */
final class AuthTransaction
{
    private const SESSION_KEY = 'oidc_tx';
    private const TTL_SECONDS = 600; // 10分

    private function __construct(
        public readonly string $provider,
        public readonly string $state,
        public readonly string $codeVerifier,
        public readonly string $nonce,
        public readonly int $createdAt,
    ) {
    }

    public static function start(string $provider): self
    {
        $tx = new self(
            provider: $provider,
            state: self::randomToken(),
            codeVerifier: self::randomToken(),
            nonce: self::randomToken(),
            createdAt: time(),
        );

        $_SESSION[self::SESSION_KEY] = [
            'provider' => $tx->provider,
            'state' => $tx->state,
            'code_verifier' => $tx->codeVerifier,
            'nonce' => $tx->nonce,
            'created_at' => $tx->createdAt,
        ];

        return $tx;
    }

    /**
     * コールバックで受け取った state を検証し、対応するトランザクションを取り出す。
     * 取り出しと同時にセッションから削除するため、同じ state は二度使えない。
     *
     * @throws AuthException 不一致・期限切れ・provider不一致の場合
     */
    public static function consume(string $provider, string $state): self
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;

        // 成否にかかわらず必ず破棄する（リプレイ防止）
        unset($_SESSION[self::SESSION_KEY]);

        if (!is_array($stored)) {
            throw new AuthException('invalid_state', '認証トランザクションがセッションに存在しません。');
        }

        if (!is_string($stored['state'] ?? null) || !hash_equals($stored['state'], $state)) {
            throw new AuthException('invalid_state', 'state が一致しません。');
        }

        if (($stored['provider'] ?? null) !== $provider) {
            throw new AuthException('invalid_state', 'state が別のプロバイダ向けに発行されたものです。');
        }

        $createdAt = (int) ($stored['created_at'] ?? 0);

        if ($createdAt <= 0 || time() - $createdAt > self::TTL_SECONDS) {
            throw new AuthException('invalid_state', 'state の有効期限が切れています。');
        }

        return new self(
            provider: $provider,
            state: $stored['state'],
            codeVerifier: (string) ($stored['code_verifier'] ?? ''),
            nonce: (string) ($stored['nonce'] ?? ''),
            createdAt: $createdAt,
        );
    }

    /** PKCE S256 の code_challenge。 */
    public function codeChallenge(): string
    {
        return self::base64UrlEncode(hash('sha256', $this->codeVerifier, true));
    }

    private static function randomToken(): string
    {
        return self::base64UrlEncode(random_bytes(32));
    }

    private static function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
