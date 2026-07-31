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

$router->add('GET', '/api/health', static function (): void {
    Response::json(['status' => 'ok', 'php_version' => PHP_VERSION]);
});

$router->add('GET', '/api/auth/{provider}/redirect', static fn (string $p) => $auth->redirectToProvider($p));
$router->add('GET', '/api/auth/{provider}/callback', static fn (string $p) => $auth->handleCallback($p));
$router->add('GET', '/api/auth/me', static fn () => $auth->me());
$router->add('POST', '/api/auth/logout', static fn () => $auth->logout());

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
        Response::error($csrfError, 403);
        exit;
    }
}

try {
    ($matched['handler'])(...$matched['params']);
} catch (Throwable $e) {
    Response::error('server_error', 500, $e->getMessage());
}
