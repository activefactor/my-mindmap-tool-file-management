<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Support\Env;

Env::load(__DIR__ . '/../.env');

// テストはDocker上のMySQLに対して実行する（実際のSQL・制約も検証するため）。
// 誤って本番DBを対象にしないよう、ローカル用のホスト名以外は拒否する。
$dbHost = Env::get('DB_HOST', '');

if (!in_array($dbHost, ['mysql', 'localhost', '127.0.0.1'], true)) {
    fwrite(STDERR, "テストはローカルDBに対してのみ実行できます (DB_HOST={$dbHost})\n");
    exit(1);
}
