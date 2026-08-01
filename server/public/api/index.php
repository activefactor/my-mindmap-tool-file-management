<?php

declare(strict_types=1);

// フロントコントローラ。
// 本番（heteml）では公開ディレクトリ web/activefactor.org/mindmap/api/ に配置され、
// アプリ本体は公開領域の外に置く（基本設計書_Phase2.md §1.3）。
// 開発環境では server/ をそのままマウントするため、両方のレイアウトを解決できるようにする。

$autoloadCandidates = [
    // ローカル開発（server/ を丸ごとマウント）
    __DIR__ . '/../../vendor/autoload.php',
    // 本番（公開ディレクトリからホームディレクトリ直下の mindmap-app/ を参照）
    __DIR__ . '/../../../../mindmap-app/vendor/autoload.php',
];

$autoload = null;

foreach ($autoloadCandidates as $candidate) {
    $resolved = realpath($candidate);

    if ($resolved !== false && is_file($resolved)) {
        $autoload = $resolved;
        break;
    }
}

if ($autoload === null) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'server_misconfigured'], JSON_UNESCAPED_UNICODE);
    exit;
}

require $autoload;

use App\Auth\SessionManager;
use App\Http\ApiException;
use App\Http\Controller\AdminController;
use App\Http\Controller\AuthController;
use App\Http\CsrfGuard;
use App\Http\Router;
use App\Support\Env;
use App\Support\Response;

Env::load(dirname($autoload, 2) . '/.env');

// 本番ではエラー詳細をクライアントに返さない（基本設計書_Phase2.md §6.5）
$isProduction = Env::get('APP_ENV', 'production') === 'production';
ini_set('display_errors', $isProduction ? '0' : '1');
error_reporting(E_ALL);

SessionManager::start();

$router = new Router();
$auth = new AuthController();
$admin = new AdminController();

$router->add('GET', '/api/health', static function (): void {
    Response::json(['status' => 'ok', 'php_version' => PHP_VERSION]);
});

$router->add('GET', '/api/auth/{provider}/redirect', static fn (string $p) => $auth->redirectToProvider($p));
$router->add('GET', '/api/auth/{provider}/callback', static fn (string $p) => $auth->handleCallback($p));
$router->add('GET', '/api/auth/me', static fn () => $auth->me());
$router->add('POST', '/api/auth/logout', static fn () => $auth->logout());

// 管理コンソール（FR-08）。認可は各ハンドラ内の AuthGuard::requireAdmin() で行う。
$router->add('GET', '/api/admin/users', static fn () => $admin->listUsers());
$router->add('PUT', '/api/admin/users/{id}/role', static fn (string $id) => $admin->changeRole($id));
$router->add('PUT', '/api/admin/users/{id}/status', static fn (string $id) => $admin->changeStatus($id));
$router->add('GET', '/api/admin/allowed-domains', static fn () => $admin->listAllowedDomains());
$router->add('POST', '/api/admin/allowed-domains', static fn () => $admin->addAllowedDomain());
$router->add('DELETE', '/api/admin/allowed-domains/{id}', static fn (string $id) => $admin->removeAllowedDomain($id));
$router->add('GET', '/api/admin/allowed-emails', static fn () => $admin->listAllowedEmails());
$router->add('POST', '/api/admin/allowed-emails', static fn () => $admin->addAllowedEmail());
$router->add('DELETE', '/api/admin/allowed-emails/{id}', static fn (string $id) => $admin->removeAllowedEmail($id));
$router->add('GET', '/api/admin/audit-logs', static fn () => $admin->listAuditLogs());
$router->add('GET', '/api/admin/storage-usage', static fn () => $admin->storageUsage());

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

$matched = $router->match($method, $path);

if ($matched === null) {
    Response::error($router->pathExists($path) ? 'method_not_allowed' : 'not_found', $router->pathExists($path) ? 405 : 404);
    exit;
}

// 状態を変更するリクエストはCSRFトークンを検証する（NFR-S-9）。
// OAuthコールバックはGETなので対象外（stateとPKCEで保護される）。
if (CsrfGuard::requiresCheck($method)) {
    $csrfError = CsrfGuard::check();

    if ($csrfError !== null) {
        Response::error($csrfError, $csrfError === 'unsupported_media_type' ? 415 : 403);
        exit;
    }
}

try {
    ($matched['handler'])(...$matched['params']);
} catch (ApiException $e) {
    // 想定内のエラー（未認証・権限不足・バリデーション等）。コードと状態のみ返す
    Response::error($e->errorCode, $e->status, $e->getMessage());
} catch (Throwable $e) {
    Response::error('server_error', 500, $e->getMessage());
}
