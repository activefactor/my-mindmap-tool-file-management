<?php

declare(strict_types=1);

namespace App\Support;

/**
 * APIレスポンスのヘルパー。
 * 認証・マップ・管理APIはいずれも機微情報を含みうるため、既定で no-store を付与する
 * （基本設計書_Phase2.md §6.4）。
 */
final class Response
{
    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * エラー応答。クライアントには一般化したコードのみ返し、詳細はサーバーログに残す
     * （基本設計書_Phase2.md §6.5）。
     */
    public static function error(string $code, int $status, ?string $logMessage = null): void
    {
        $correlationId = bin2hex(random_bytes(8));

        if ($logMessage !== null) {
            error_log("[{$correlationId}] {$code}: {$logMessage}");
        }

        self::json(['error' => $code, 'correlation_id' => $correlationId], $status);
    }

    public static function redirect(string $url, int $status = 302): void
    {
        http_response_code($status);
        header('Cache-Control: no-store');
        header("Location: {$url}");
    }
}
