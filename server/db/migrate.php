<?php

declare(strict_types=1);

// マイグレーションランナ。db/migrations/*.sql を番号順に適用する。
// 適用済みファイル名は schema_migrations テーブルに記録し、再実行時はスキップする（冪等）。
// 各ファイルは1つのDDL文のみを含む前提（PDO::exec の複数文実行に依存しない）。
//
// 注意: MySQLのDDL（CREATE TABLE等）は実行時に暗黙のコミットが発生し、PDOのトランザクション
// 管理下に置けない。そのためDDL自体はトランザクションで囲わず、DDL成功後に
// schema_migrations への記録を別操作として行う（DDLが成功したのに記録だけ失敗するという
// 万一の場合は、再実行時にCREATE TABLEが「table already exists」で失敗するため、その場で
// 気づける＝サイレントに不整合が進行することはない）。

require __DIR__ . '/../vendor/autoload.php';

use App\Support\Database;
use App\Support\Env;

Env::load(__DIR__ . '/../.env');

$pdo = Database::connection();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);

foreach ($files as $file) {
    $filename = basename($file);

    if (in_array($filename, $applied, true)) {
        echo "skip  {$filename}\n";
        continue;
    }

    $sql = file_get_contents($file);

    try {
        $pdo->exec($sql);

        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (:filename)');
        $stmt->execute(['filename' => $filename]);

        echo "apply {$filename}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "FAILED {$filename}: {$e->getMessage()}\n");
        exit(1);
    }
}

echo "done\n";
