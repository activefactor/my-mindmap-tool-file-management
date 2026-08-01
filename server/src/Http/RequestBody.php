<?php

declare(strict_types=1);

namespace App\Http;

/**
 * リクエストボディ（JSON）の読み取りとバリデーション（基本設計書_Phase2.md §6.2）。
 *
 * 管理APIが受け取るのは小さなJSONのみのため既定の上限は控えめにしている。
 * マインドマップ保存API（Step 5）は §6.2 の 5MB を明示的に指定して使う想定。
 */
final class RequestBody
{
    /** 管理APIの既定上限（64KB）。 */
    public const MAX_BYTES_ADMIN = 65536;

    /**
     * JSONボディを連想配列として取得する。
     *
     * @return array<string, mixed>
     */
    public static function json(int $maxBytes = self::MAX_BYTES_ADMIN): array
    {
        $raw = file_get_contents('php://input');

        if ($raw === false) {
            throw new ApiException('invalid_request', 400, 'リクエストボディを読み取れませんでした。');
        }

        if (strlen($raw) > $maxBytes) {
            throw new ApiException('payload_too_large', 413, 'リクエストボディが上限を超えています。');
        }

        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ApiException('invalid_request', 400, 'ボディはJSONオブジェクトである必要があります。');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * 必須の文字列フィールドを取り出す。
     *
     * @param array<string, mixed> $body
     */
    public static function requireString(array $body, string $key, int $maxLength): string
    {
        $value = $body[$key] ?? null;

        if (!is_string($value)) {
            throw new ApiException('invalid_request', 422, "{$key} は文字列である必要があります。");
        }

        $value = trim($value);

        if ($value === '') {
            throw new ApiException('invalid_request', 422, "{$key} は必須です。");
        }

        // マルチバイト前提の長さ検証（DB側は VARCHAR(255) = 255文字）
        if (mb_strlen($value) > $maxLength) {
            throw new ApiException('invalid_request', 422, "{$key} が長すぎます。");
        }

        return $value;
    }

    /**
     * 列挙値のいずれかであることを検証して取り出す。
     *
     * @param array<string, mixed> $body
     * @param array<int, string> $allowed
     */
    public static function requireEnum(array $body, string $key, array $allowed, string $errorCode): string
    {
        $value = $body[$key] ?? null;

        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new ApiException($errorCode, 422, "{$key} の値が不正です。");
        }

        return $value;
    }
}
