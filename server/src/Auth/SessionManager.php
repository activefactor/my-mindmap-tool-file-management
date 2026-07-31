<?php

declare(strict_types=1);

namespace App\Auth;

use App\Repository\UserRepository;
use App\Support\Env;

/**
 * セッション・CSRFトークン・セッション失効の管理（FR-07-4, NFR-S-6, NFR-S-9, NFR-S-10）。
 *
 * Cookie は SameSite=Lax とする。Strict は OAuth コールバック（クロスサイトの
 * トップレベル遷移）で Cookie が送信されずログイン不能になるため使用しない
 * （基本設計書_Phase2.md §3.1「Cookie・CSRF 設計」）。
 */
final class SessionManager
{
    private const KEY_USER_ID = 'user_id';
    private const KEY_ROLE = 'role';
    private const KEY_SECURITY_STAMP = 'security_stamp';
    private const KEY_CSRF = 'csrf_token';
    private const KEY_LAST_ACTIVITY = 'last_activity';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = self::isHttps();

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // __Host- 接頭辞は Secure かつ Path=/ かつ Domain指定なしが条件のため、
        // HTTPSのときのみ使用する（ローカル開発のHTTPでは通常のセッション名にフォールバック）。
        session_name($secure ? '__Host-mmsess' : 'mmsess');

        session_start();
    }

    /**
     * ログイン成功時の確立処理。
     *
     * @param array<string, mixed> $user
     */
    public static function establish(array $user): void
    {
        // セッション固定化攻撃の防止
        session_regenerate_id(true);

        $_SESSION[self::KEY_USER_ID] = (int) $user['id'];
        $_SESSION[self::KEY_ROLE] = (string) $user['role'];
        $_SESSION[self::KEY_SECURITY_STAMP] = (string) $user['security_stamp'];
        $_SESSION[self::KEY_LAST_ACTIVITY] = time();

        // ログイン時にCSRFトークンを再発行する（基本設計書 §6.1）
        self::rotateCsrfToken();
    }

    public static function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        session_destroy();
    }

    public static function rotateCsrfToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::KEY_CSRF] = $token;

        return $token;
    }

    public static function csrfToken(): ?string
    {
        $token = $_SESSION[self::KEY_CSRF] ?? null;

        return is_string($token) ? $token : null;
    }

    public static function verifyCsrfToken(?string $provided): bool
    {
        $expected = self::csrfToken();

        return is_string($expected) && is_string($provided) && hash_equals($expected, $provided);
    }

    /**
     * 現在ログイン中のユーザーを返す。以下のいずれかに該当する場合は null を返し、
     * セッションを破棄する。
     *
     * - 未ログイン
     * - アイドルタイムアウト超過（FR-07-4）
     * - ユーザーが無効化された（NFR-S-10）
     * - security_stamp が変化した = ロール変更・無効化・再有効化が行われた（NFR-S-10）
     *
     * @return array<string, mixed>|null
     */
    public static function currentUser(?UserRepository $users = null): ?array
    {
        $userId = $_SESSION[self::KEY_USER_ID] ?? null;

        if (!is_int($userId)) {
            return null;
        }

        $lastActivity = (int) ($_SESSION[self::KEY_LAST_ACTIVITY] ?? 0);
        $timeoutSeconds = ((int) Env::get('SESSION_IDLE_TIMEOUT_MINUTES', '60')) * 60;

        if ($lastActivity <= 0 || time() - $lastActivity > $timeoutSeconds) {
            self::destroy();

            return null;
        }

        $users ??= new UserRepository();
        $user = $users->findById($userId);

        if ($user === null || $user['status'] !== 'active') {
            self::destroy();

            return null;
        }

        $sessionStamp = $_SESSION[self::KEY_SECURITY_STAMP] ?? null;

        if (!is_string($sessionStamp) || !hash_equals((string) $user['security_stamp'], $sessionStamp)) {
            self::destroy();

            return null;
        }

        // アクティビティを更新（スライディングタイムアウト）
        $_SESSION[self::KEY_LAST_ACTIVITY] = time();

        // ロール変更が security_stamp 経由で反映されるので、セッション側も最新化しておく
        $_SESSION[self::KEY_ROLE] = (string) $user['role'];

        return $user;
    }

    private static function isHttps(): bool
    {
        $appUrl = Env::get('APP_URL', '');

        return str_starts_with((string) $appUrl, 'https://');
    }
}
