<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * 外部（OIDCプロバイダ）への発信用の最小限のcURLラッパー。
 * レスポンスは常にJSONとしてデコードして返す。
 */
final class HttpClient
{
    private const TIMEOUT_SECONDS = 10;

    /**
     * @param array<int, string> $extraHeaders
     * @return array<string, mixed>
     */
    public static function getJson(string $url, array $extraHeaders = []): array
    {
        return self::request($url, null, $extraHeaders);
    }

    /**
     * @param array<string, string> $formFields
     * @return array<string, mixed>
     */
    public static function postForm(string $url, array $formFields): array
    {
        return self::request($url, http_build_query($formFields, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * @param array<int, string> $extraHeaders
     * @return array<string, mixed>
     */
    private static function request(string $url, ?string $body, array $extraHeaders = []): array
    {
        $ch = curl_init($url);

        $headers = array_merge(['Accept: application/json'], $extraHeaders);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
            // 中間者攻撃を防ぐため証明書検証は必ず有効にする
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // リダイレクトは追わない（トークンエンドポイント等は直接応答するはず）
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_HTTPHEADER] = array_merge(
                $headers,
                ['Content-Type: application/x-www-form-urlencoded']
            );
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        // PHP 8.0以降 curl_init() は CurlHandle オブジェクトを返し、スコープを抜ければ
        // 自動的に解放される。curl_close() は 8.0 で無効化され 8.5 で非推奨になったため呼ばない
        // （本番の heteml も PHP 8.5 のため、呼ぶと Deprecated 通知が出力されヘッダ送信が壊れる）。
        unset($ch);

        if ($errno !== 0 || $response === false) {
            // エラーメッセージにURLを含めない（トークン等がクエリに乗る可能性を避ける）
            throw new RuntimeException("HTTPリクエストに失敗しました (curl errno {$errno}): {$error}");
        }

        $decoded = json_decode((string) $response, true);

        if (!is_array($decoded)) {
            throw new RuntimeException("HTTPレスポンスをJSONとして解釈できませんでした (status {$status})");
        }

        if ($status < 200 || $status >= 300) {
            // プロバイダのエラー応答は error / error_description を含むが、
            // トークン類が含まれる可能性は低い。詳細はサーバーログにのみ出す想定。
            $reason = is_string($decoded['error'] ?? null) ? $decoded['error'] : 'unknown_error';
            throw new RuntimeException("HTTPエラー応答 (status {$status}): {$reason}");
        }

        return $decoded;
    }
}
