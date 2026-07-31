<?php

declare(strict_types=1);

namespace App\Auth;

use RuntimeException;

/**
 * 認証失敗を表す例外。
 * $code はクライアント／ログイン画面に返す一般化したコード、
 * メッセージは詳細（サーバーログ・監査ログ用。トークン類は含めないこと）。
 */
final class AuthException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
