<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Auth\AccessPolicy;
use App\Auth\AccountResolver;
use App\Auth\AuthException;
use App\Auth\AuthTransaction;
use App\Auth\IdTokenVerifier;
use App\Auth\OidcClient;
use App\Auth\ProviderConfig;
use App\Auth\SessionManager;
use App\Repository\AllowListRepository;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Support\Env;
use App\Support\Response;
use Throwable;

/**
 * 認証エンドポイント（FR-07）。
 */
final class AuthController
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly AuditLogRepository $auditLogs = new AuditLogRepository(),
        private readonly AllowListRepository $allowList = new AllowListRepository(),
    ) {
    }

    /** GET /api/auth/{provider}/redirect */
    public function redirectToProvider(string $provider): void
    {
        if (!ProviderConfig::isSupported($provider)) {
            Response::error('unsupported_provider', 400);

            return;
        }

        try {
            $config = ProviderConfig::for($provider);
            $tx = AuthTransaction::start($provider);
            $client = new OidcClient($config);

            Response::redirect($client->buildAuthorizationUrl($tx));
        } catch (Throwable $e) {
            Response::error('auth_start_failed', 500, $e->getMessage());
        }
    }

    /** GET /api/auth/{provider}/callback */
    public function handleCallback(string $provider): void
    {
        if (!ProviderConfig::isSupported($provider)) {
            $this->failToLogin('unsupported_provider', 'unsupported_provider', null);

            return;
        }

        // プロバイダ側でユーザーが同意をキャンセルした場合など
        if (isset($_GET['error'])) {
            $this->failToLogin('provider_error', 'provider_error', 'プロバイダがエラーを返しました。');

            return;
        }

        $code = $_GET['code'] ?? null;
        $state = $_GET['state'] ?? null;

        if (!is_string($code) || !is_string($state)) {
            $this->failToLogin('invalid_request', 'login_denied', 'code または state がありません。');

            return;
        }

        try {
            $tx = AuthTransaction::consume($provider, $state);
            $config = ProviderConfig::for($provider);
            $client = new OidcClient($config);

            $tokens = $client->exchangeCode($code, $tx);
            $idToken = $tokens['id_token'] ?? null;

            if (!is_string($idToken)) {
                throw new AuthException('invalid_id_token', 'id_token が応答に含まれていません。');
            }

            $claims = (new IdTokenVerifier($config))->verify($idToken, $tx->nonce);

            [$email, $displayName] = $this->resolveProfile($client, $claims, $tokens);

            (new AccessPolicy($this->allowList))->assertAllowed($provider, $email, $claims);

            $resolved = (new AccountResolver($this->users))
                ->resolve($provider, self::subjectOf($claims, $provider), $email, $displayName);

            $user = $resolved['user'];

            if ($user['status'] !== 'active') {
                $this->auditLogs->record('login_denied_disabled', (int) $user['id'], $email);
                $this->redirectToLoginWithError('account_disabled');

                return;
            }

            SessionManager::establish($user);
            $this->users->touchLastLogin((int) $user['id'], $displayName);

            $this->auditLogs->record(
                $resolved['is_new'] ? 'login_first_time' : 'login',
                (int) $user['id'],
                $email,
                ['provider' => $provider]
            );

            Response::redirect(rtrim((string) Env::get('APP_URL', ''), '/') . '/dashboard');
        } catch (AuthException $e) {
            $this->failToLogin($e->errorCode, self::auditActionFor($e->errorCode), $e->getMessage());
        } catch (Throwable $e) {
            $this->failToLogin('server_error', 'login_failed', $e->getMessage());
        }
    }

    /** GET /api/auth/me */
    public function me(): void
    {
        $user = SessionManager::currentUser($this->users);

        if ($user === null) {
            Response::error('unauthenticated', 401);

            return;
        }

        Response::json([
            'user' => [
                'id' => (int) $user['id'],
                'email' => $user['email'],
                'display_name' => $user['display_name'],
                'role' => $user['role'],
            ],
            'csrf_token' => SessionManager::csrfToken() ?? SessionManager::rotateCsrfToken(),
        ]);
    }

    /** POST /api/auth/logout */
    public function logout(): void
    {
        $user = SessionManager::currentUser($this->users);

        if ($user !== null) {
            $this->auditLogs->record('logout', (int) $user['id']);
        }

        SessionManager::destroy();

        Response::json(['status' => 'ok']);
    }

    /**
     * メールアドレスと表示名を決定する。
     *
     * IDトークンに email が含まれていればそれを使う（追加のHTTPリクエストを避ける）。
     * 含まれない場合（Microsoftのアカウント種別によってはあり得る）のみ UserInfo を参照し、
     * その際は sub の一致を必ず確認してトークン差し替えを防ぐ。
     *
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $tokens
     * @return array{0: string, 1: string}
     */
    private function resolveProfile(OidcClient $client, array $claims, array $tokens): array
    {
        $email = self::stringClaim($claims, 'email');
        $displayName = self::stringClaim($claims, 'name');

        if ($email === null) {
            $email = self::stringClaim($claims, 'preferred_username');
        }

        if ($email === null) {
            $accessToken = $tokens['access_token'] ?? null;

            if (!is_string($accessToken)) {
                throw new AuthException('no_email', 'メールアドレスを取得できませんでした。');
            }

            $userInfo = $client->fetchUserInfo($accessToken);

            $userInfoSub = self::stringClaim($userInfo, 'sub');
            $idTokenSub = self::stringClaim($claims, 'sub');

            if ($userInfoSub === null || $idTokenSub === null || !hash_equals($idTokenSub, $userInfoSub)) {
                throw new AuthException('invalid_id_token', 'UserInfo の sub が IDトークンと一致しません。');
            }

            $email = self::stringClaim($userInfo, 'email')
                ?? self::stringClaim($userInfo, 'preferred_username');
            $displayName ??= self::stringClaim($userInfo, 'name');
        }

        if ($email === null || !str_contains($email, '@')) {
            throw new AuthException('no_email', 'メールアドレスを取得できませんでした。');
        }

        return [strtolower($email), $displayName ?? $email];
    }

    /**
     * アカウントを識別する主キー。
     * Microsoft は sub がアプリ・テナントごとに変わるため、テナント内で不変の oid を優先する。
     *
     * @param array<string, mixed> $claims
     */
    private static function subjectOf(array $claims, string $provider): string
    {
        if ($provider === ProviderConfig::MICROSOFT) {
            $oid = self::stringClaim($claims, 'oid');

            if ($oid !== null) {
                return $oid;
            }
        }

        $sub = self::stringClaim($claims, 'sub');

        if ($sub === null) {
            throw new AuthException('invalid_id_token', 'sub クレームが存在しません。');
        }

        return $sub;
    }

    /** @param array<string, mixed> $claims */
    private static function stringClaim(array $claims, string $key): ?string
    {
        $value = $claims[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function failToLogin(string $errorCode, string $auditAction, ?string $logMessage): void
    {
        if ($logMessage !== null) {
            error_log("[auth] {$auditAction}: {$logMessage}");
        }

        // 監査ログにはトークン類を残さない（基本設計書 §6.5）。
        try {
            $this->auditLogs->record($auditAction, null, null, ['error_code' => $errorCode]);
        } catch (Throwable) {
            // 監査ログの失敗でログインエラー画面自体を壊さない
        }

        $this->redirectToLoginWithError($errorCode);
    }

    private function redirectToLoginWithError(string $errorCode): void
    {
        $base = rtrim((string) Env::get('APP_URL', ''), '/');

        Response::redirect($base . '/login?error=' . rawurlencode($errorCode));
    }

    private static function auditActionFor(string $errorCode): string
    {
        return match ($errorCode) {
            'not_allowed' => 'login_denied',
            'account_conflict' => 'login_denied_conflict',
            'invalid_state', 'invalid_id_token' => 'login_denied_invalid_token',
            default => 'login_failed',
        };
    }
}
