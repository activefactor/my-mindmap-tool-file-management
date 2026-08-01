<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\SessionManager;
use App\Support\Env;

/**
 * CSRF対策（NFR-S-9、基本設計書_Phase2.md §6.1）。
 *
 * SameSite=Lax だけに依存せず、状態を変更する全APIでトークンを検証する。
 * 補助的に Origin を検証し、Origin が無い場合のみ Referer を確認する。
 */
final class CsrfGuard
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public static function requiresCheck(string $method): bool
    {
        return !in_array(strtoupper($method), self::SAFE_METHODS, true);
    }

    /** @return string|null エラーコード。null なら検証通過 */
    public static function check(): ?string
    {
        if (!self::originIsAllowed()) {
            return 'invalid_origin';
        }

        if (!self::contentTypeIsAllowed()) {
            return 'unsupported_media_type';
        }

        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (!SessionManager::verifyCsrfToken(is_string($token) ? $token : null)) {
            return 'invalid_csrf_token';
        }

        return null;
    }

    /**
     * ボディを伴うリクエストは `application/json` のみ許可する（基本設計書_Phase2.md §6.1）。
     *
     * HTMLフォームから送信できるのは `application/x-www-form-urlencoded` /
     * `multipart/form-data` / `text/plain` の3種類だけなので、JSONを必須にすると
     * フォーム経由の単純なCSRFは形式面でも成立しなくなる。
     *
     * ボディが無い場合（DELETE 等）は fetch が Content-Type を付けないため対象外とする。
     */
    private static function contentTypeIsAllowed(): bool
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) === 0) {
            return true;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        // `application/json; charset=utf-8` のようなパラメータ付きも許可する
        return is_string($contentType)
            && preg_match('#^application/json\s*(;.*)?$#i', trim($contentType)) === 1;
    }

    private static function originIsAllowed(): bool
    {
        $appUrl = rtrim((string) Env::get('APP_URL', ''), '/');

        if ($appUrl === '') {
            return false;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;

        if (is_string($origin) && $origin !== '') {
            return rtrim($origin, '/') === $appUrl;
        }

        // Origin が送られない場合のみ Referer で補助的に判定する
        $referer = $_SERVER['HTTP_REFERER'] ?? null;

        if (is_string($referer) && $referer !== '') {
            return str_starts_with($referer, $appUrl . '/') || rtrim($referer, '/') === $appUrl;
        }

        // どちらも無い場合は拒否（ブラウザからの通常のリクエストならいずれかは付く）
        return false;
    }
}
