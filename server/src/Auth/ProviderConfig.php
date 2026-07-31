<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\Env;
use InvalidArgumentException;

/**
 * OIDCプロバイダごとの設定（FR-07-1, FR-07-2）。
 */
final class ProviderConfig
{
    public const GOOGLE = 'google';
    public const MICROSOFT = 'microsoft';

    private function __construct(
        public readonly string $name,
        public readonly string $discoveryUrl,
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $redirectUri,
        public readonly string $scope,
    ) {
    }

    public static function isSupported(string $provider): bool
    {
        return in_array($provider, [self::GOOGLE, self::MICROSOFT], true);
    }

    public static function for(string $provider): self
    {
        return match ($provider) {
            self::GOOGLE => new self(
                name: self::GOOGLE,
                discoveryUrl: 'https://accounts.google.com/.well-known/openid-configuration',
                clientId: self::required('GOOGLE_CLIENT_ID'),
                clientSecret: self::required('GOOGLE_CLIENT_SECRET'),
                redirectUri: self::required('GOOGLE_REDIRECT_URI'),
                scope: 'openid email profile',
            ),
            // MS_TENANT は既定で 'common'（職場/学校アカウント＋個人アカウントの両方を許可）。
            // 発行者はテナントごとに異なるため、IdTokenVerifier で iss と tid の整合を確認する。
            self::MICROSOFT => new self(
                name: self::MICROSOFT,
                discoveryUrl: sprintf(
                    'https://login.microsoftonline.com/%s/v2.0/.well-known/openid-configuration',
                    rawurlencode(Env::get('MS_TENANT', 'common'))
                ),
                clientId: self::required('MS_CLIENT_ID'),
                clientSecret: self::required('MS_CLIENT_SECRET'),
                redirectUri: self::required('MS_REDIRECT_URI'),
                scope: 'openid email profile',
            ),
            default => throw new InvalidArgumentException("未対応のプロバイダです: {$provider}"),
        };
    }

    private static function required(string $key): string
    {
        $value = Env::get($key);

        if ($value === null) {
            throw new InvalidArgumentException("環境変数 {$key} が設定されていません。");
        }

        return $value;
    }
}
