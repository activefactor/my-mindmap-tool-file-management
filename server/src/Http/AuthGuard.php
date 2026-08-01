<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\SessionManager;
use App\Repository\UserRepository;

/**
 * 認証・認可ガード（基本設計書_Phase2.md §5.2「権限」）。
 *
 * `SessionManager::currentUser()` がリクエストごとに `status` と `security_stamp` を
 * DBと突き合わせるため、無効化・降格は再ログインを待たずに反映される（NFR-S-10）。
 */
final class AuthGuard
{
    /**
     * ログイン済みユーザーを返す。未ログイン・失効時は 401 の ApiException を投げる。
     *
     * @return array<string, mixed>
     */
    public static function requireUser(?UserRepository $users = null): array
    {
        $user = SessionManager::currentUser($users);

        if ($user === null) {
            throw new ApiException('unauthenticated', 401);
        }

        return $user;
    }

    /**
     * 管理者を返す。一般ユーザーの場合は 403 を投げる（FR-08、§3.2）。
     *
     * 一般ユーザーであることを 404 ではなく 403 で返すのは、管理APIの存在自体は
     * 秘密ではなく、フロントエンドが 403 を受けてダッシュボードへ誘導する設計のため
     * （§3.2）。
     *
     * @return array<string, mixed>
     */
    public static function requireAdmin(?UserRepository $users = null): array
    {
        $user = self::requireUser($users);

        if (($user['role'] ?? null) !== 'admin') {
            throw new ApiException('forbidden', 403);
        }

        return $user;
    }
}
