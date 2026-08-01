<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Database;
use PDO;

/**
 * 許可ドメイン・許可アドレス（FR-08-3, FR-08-4）。
 *
 * テーブル構造が同一（id / 値 / created_by / created_at）で UNIQUE 制約も同じため、
 * 対象テーブルと列名をパラメータ化して共通実装にしている。テーブル名・列名は
 * SQL に文字列として埋め込むことになるので、**呼び出し元の指定値ではなく本クラス内の
 * 定数からのみ**組み立てる（外部入力が到達しない構造にする）。
 */
final class AllowListRepository
{
    private const DOMAINS = ['table' => 'allowed_domains', 'column' => 'domain'];
    private const EMAILS = ['table' => 'allowed_emails', 'column' => 'email'];

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

    /** @return array<int, array<string, mixed>> */
    public function listDomains(): array
    {
        return $this->listAll(self::DOMAINS);
    }

    /** @return array<int, array<string, mixed>> */
    public function listEmails(): array
    {
        return $this->listAll(self::EMAILS);
    }

    /** @return int|null 追加した行のID。すでに存在する場合は null */
    public function addDomain(string $domain, int $createdBy): ?int
    {
        return $this->add(self::DOMAINS, $domain, $createdBy);
    }

    /** @return int|null 追加した行のID。すでに存在する場合は null */
    public function addEmail(string $email, int $createdBy): ?int
    {
        return $this->add(self::EMAILS, $email, $createdBy);
    }

    /** @return string|null 削除した値。存在しなかった場合は null */
    public function removeDomain(int $id): ?string
    {
        return $this->remove(self::DOMAINS, $id);
    }

    /** @return string|null 削除した値。存在しなかった場合は null */
    public function removeEmail(int $id): ?string
    {
        return $this->remove(self::EMAILS, $id);
    }

    /**
     * @param array{table: string, column: string} $target
     * @return array<int, array<string, mixed>>
     */
    private function listAll(array $target): array
    {
        $stmt = $this->pdo->query(sprintf(
            'SELECT a.id, a.%1$s AS value, a.created_at, u.email AS created_by_email
             FROM %2$s a
             LEFT JOIN users u ON u.id = a.created_by
             ORDER BY a.%1$s ASC',
            $target['column'],
            $target['table']
        ));

        return $stmt === false ? [] : $stmt->fetchAll();
    }

    /** @param array{table: string, column: string} $target */
    private function add(array $target, string $value, int $createdBy): ?int
    {
        $stmt = $this->pdo->prepare(sprintf(
            'INSERT IGNORE INTO %s (%s, created_by) VALUES (:value, :created_by)',
            $target['table'],
            $target['column']
        ));
        $stmt->execute(['value' => strtolower($value), 'created_by' => $createdBy]);

        // INSERT IGNORE は重複時に0行となる。UNIQUE制約に任せることで
        // 「存在確認 → INSERT」の間に別の管理者が追加する競合を避けている。
        return $stmt->rowCount() === 0 ? null : (int) $this->pdo->lastInsertId();
    }

    /** @param array{table: string, column: string} $target */
    private function remove(array $target, int $id): ?string
    {
        $select = $this->pdo->prepare(sprintf(
            'SELECT %s FROM %s WHERE id = :id',
            $target['column'],
            $target['table']
        ));
        $select->execute(['id' => $id]);

        $value = $select->fetchColumn();

        if ($value === false) {
            return null;
        }

        $this->pdo->prepare(sprintf('DELETE FROM %s WHERE id = :id', $target['table']))
            ->execute(['id' => $id]);

        return (string) $value;
    }
}
