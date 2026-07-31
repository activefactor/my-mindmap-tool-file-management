<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Database;
use PDO;

/**
 * 許可ドメイン・許可アドレス（FR-08-3, FR-08-4）の参照。
 * 追加・削除は Step 4（管理コンソール）で実装する。
 */
final class AllowListRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function domainExists(string $domain): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM allowed_domains WHERE domain = :domain');
        $stmt->execute(['domain' => strtolower($domain)]);

        return $stmt->fetchColumn() !== false;
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM allowed_emails WHERE email = :email');
        $stmt->execute(['email' => strtolower($email)]);

        return $stmt->fetchColumn() !== false;
    }
}
