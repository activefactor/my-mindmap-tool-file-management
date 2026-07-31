<?php

declare(strict_types=1);

// 初期管理者ブートストラップ。.env の INITIAL_ADMIN_EMAIL / INITIAL_ALLOWED_DOMAIN から
// 許可ドメイン・許可アドレス・管理者ユーザーを投入する（基本設計書_Phase2.md §3.1参照）。
// ここで作成する users 行には user_identities を紐付けない。実際のSSOログイン時に
// 「identityが1件も無い既存ユーザー」として初回ログイン扱いで identity が追加される
// （同 §3.1 の解決ロジック参照。ここが conflict 扱いだと初期管理者が永久にログインできない）。
// 複数回実行しても安全（INSERT IGNORE / ON DUPLICATE KEY UPDATE）。

require __DIR__ . '/../vendor/autoload.php';

use App\Support\Database;
use App\Support\Env;

Env::load(__DIR__ . '/../.env');

$adminEmail = Env::get('INITIAL_ADMIN_EMAIL');
$allowedDomain = Env::get('INITIAL_ALLOWED_DOMAIN');

if ($adminEmail === null) {
    fwrite(STDERR, "INITIAL_ADMIN_EMAIL が .env に設定されていません。\n");
    exit(1);
}

$adminEmail = strtolower($adminEmail);

$pdo = Database::connection();
$pdo->beginTransaction();

try {
    if ($allowedDomain !== null) {
        $stmt = $pdo->prepare('INSERT IGNORE INTO allowed_domains (domain) VALUES (:domain)');
        $stmt->execute(['domain' => strtolower($allowedDomain)]);
    }

    $stmt = $pdo->prepare('INSERT IGNORE INTO allowed_emails (email) VALUES (:email)');
    $stmt->execute(['email' => $adminEmail]);

    $stmt = $pdo->prepare(
        'INSERT INTO users (email, display_name, role, status, security_stamp)
         VALUES (:email, :display_name, :role, :status, :security_stamp)
         ON DUPLICATE KEY UPDATE role = VALUES(role), status = VALUES(status)'
    );
    $stmt->execute([
        'email' => $adminEmail,
        'display_name' => '初期管理者',
        'role' => 'admin',
        'status' => 'active',
        'security_stamp' => bin2hex(random_bytes(16)),
    ]);

    $pdo->commit();
    echo "seed完了: 初期管理者 {$adminEmail} を作成/更新しました。\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'seed失敗: ' . $e->getMessage() . "\n");
    exit(1);
}
