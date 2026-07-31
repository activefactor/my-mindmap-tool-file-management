<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repository\AllowListRepository;

/**
 * 許可ドメイン／許可アドレスによるログイン可否判定（FR-07-3, NFR-Region-1）。
 *
 * 基本設計書_Phase2.md §3.1「許可判定ロジック」に対応。
 * 地理的IPブロックは行わず、ここで許可された組織・利用者のみがログインできる状態を担保する。
 */
final class AccessPolicy
{
    public function __construct(private readonly AllowListRepository $allowList)
    {
    }

    /**
     * @param array<string, mixed> $claims 検証済みのIDトークンのクレーム
     * @throws AuthException 許可されていない場合
     */
    public function assertAllowed(string $provider, string $email, array $claims): void
    {
        $email = strtolower($email);

        // メールアドレス単位の個別許可が最優先（社外のGmail等の例外アカウント）。
        if ($this->allowList->emailExists($email)) {
            return;
        }

        $domain = self::domainOf($email);

        if ($domain === null) {
            throw new AuthException('not_allowed', 'メールアドレスの形式が不正です。');
        }

        if ($provider === ProviderConfig::GOOGLE) {
            $this->assertGoogleDomainAllowed($claims, $domain);

            return;
        }

        if (!$this->allowList->domainExists($domain)) {
            throw new AuthException('not_allowed', "許可されていないドメインです: {$domain}");
        }
    }

    /**
     * Google の場合の追加チェック。
     *
     * - メールが未検証のアカウントは、メールアドレスを根拠にした判定ができないため拒否する。
     * - 組織（Google Workspace）アカウントは hd クレームを持つ。ドメイン単位の許可は
     *   この hd が許可ドメインに含まれる場合にのみ有効とする。
     * - hd を持たないコンシューマアカウント（@gmail.com 等）はドメイン単位では許可せず、
     *   allowList の個別メール許可（この関数に来る前に判定済み）のみで利用可能とする。
     *
     * @param array<string, mixed> $claims
     */
    private function assertGoogleDomainAllowed(array $claims, string $domain): void
    {
        if (($claims['email_verified'] ?? false) !== true) {
            throw new AuthException('not_allowed', 'メールアドレスが未検証のGoogleアカウントです。');
        }

        $hd = $claims['hd'] ?? null;

        if (!is_string($hd) || $hd === '') {
            throw new AuthException(
                'not_allowed',
                'Google の個人アカウントはドメイン単位の許可では利用できません（個別のメール許可が必要です）。'
            );
        }

        $hd = strtolower($hd);

        // hd とメールのドメインが食い違うトークンは想定外なので拒否する。
        if ($hd !== $domain) {
            throw new AuthException('not_allowed', 'hd クレームとメールアドレスのドメインが一致しません。');
        }

        if (!$this->allowList->domainExists($hd)) {
            throw new AuthException('not_allowed', "許可されていないドメインです: {$hd}");
        }
    }

    public static function domainOf(string $email): ?string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === strlen($email) - 1) {
            return null;
        }

        return strtolower(substr($email, $at + 1));
    }
}
