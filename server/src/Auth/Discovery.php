<?php

declare(strict_types=1);

namespace App\Auth;

use App\Support\HttpClient;
use RuntimeException;

/**
 * OIDC Discovery Document の取得。
 * エンドポイントURLをハードコードせず毎回Discoveryを参照することで、
 * プロバイダ側のエンドポイント変更に自動的に追随する（ADR 20260731 参照）。
 *
 * 同一リクエスト内での重複取得のみメモリキャッシュする（プロセス跨ぎのキャッシュはしない）。
 */
final class Discovery
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /** @return array<string, mixed> */
    public static function fetch(string $discoveryUrl): array
    {
        if (isset(self::$cache[$discoveryUrl])) {
            return self::$cache[$discoveryUrl];
        }

        $document = HttpClient::getJson($discoveryUrl);

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'issuer'] as $key) {
            if (!is_string($document[$key] ?? null)) {
                throw new RuntimeException("Discovery Document に {$key} が含まれていません。");
            }
        }

        self::$cache[$discoveryUrl] = $document;

        return $document;
    }
}
