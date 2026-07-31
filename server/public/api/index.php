<?php

declare(strict_types=1);

// Step 1 時点では疎通確認用のヘルスチェックのみ。
// 本格的なルーティングはStep 4（開発ステップ_Phase2.md参照）で導入する。

header('Content-Type: application/json; charset=utf-8');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

if ($path === '/api/health') {
    echo json_encode([
        'status' => 'ok',
        'php_version' => PHP_VERSION,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'not_found'], JSON_UNESCAPED_UNICODE);
