<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * APIがクライアントに返すエラーを表す例外。
 *
 * $errorCode はクライアントに返す一般化したコード、メッセージはサーバーログ用の詳細
 * （基本設計書_Phase2.md §6.5）。フロントコントローラがこれを捕捉して
 * `Response::error()` に変換する。
 */
final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message = '',
    ) {
        parent::__construct($message);
    }
}
