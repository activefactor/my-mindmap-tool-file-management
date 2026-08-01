<?php

declare(strict_types=1);

namespace App\Repository;

use App\Support\Database;
use PDO;

final class UserRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /** @return array<string, mixed>|null */
    public function findByIdentity(string $provider, string $providerUserId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.* FROM users u
             INNER JOIN user_identities i ON i.user_id = u.id
             WHERE i.provider = :provider AND i.provider_user_id = :provider_user_id'
        );
        $stmt->execute(['provider' => $provider, 'provider_user_id' => $providerUserId]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute(['email' => strtolower($email)]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function countIdentities(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_identities WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * 新規ユーザーとそのidentityを同一トランザクションで作成する。
     *
     * @return int 作成したユーザーのID
     */
    public function createWithIdentity(
        string $email,
        string $displayName,
        string $provider,
        string $providerUserId
    ): int {
        $this->pdo->beginTransaction();

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (email, display_name, security_stamp)
                 VALUES (:email, :display_name, :security_stamp)'
            );
            $stmt->execute([
                'email' => strtolower($email),
                'display_name' => $displayName,
                'security_stamp' => self::newSecurityStamp(),
            ]);

            $userId = (int) $this->pdo->lastInsertId();

            $this->insertIdentity($userId, $provider, $providerUserId);

            $this->pdo->commit();

            return $userId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function addIdentity(int $userId, string $provider, string $providerUserId): void
    {
        $this->insertIdentity($userId, $provider, $providerUserId);
    }

    private function insertIdentity(int $userId, string $provider, string $providerUserId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_identities (user_id, provider, provider_user_id)
             VALUES (:user_id, :provider, :provider_user_id)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
        ]);
    }

    public function touchLastLogin(int $userId, string $displayName): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET last_login_at = NOW(), display_name = :display_name WHERE id = :id'
        );
        $stmt->execute(['id' => $userId, 'display_name' => $displayName]);
    }

    public static function newSecurityStamp(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * 管理コンソールのユーザー一覧（FR-08-1）。
     * `security_stamp` は機微なためクライアントに渡す行には含めない。
     *
     * @return array<int, array<string, mixed>>
     */
    public function paginate(int $limit, int $offset, ?string $keyword = null): array
    {
        $sql = 'SELECT id, email, display_name, role, status, last_login_at, created_at
                FROM users';
        $params = [];

        if ($keyword !== null) {
            // ネイティブプリペアド（EMULATE_PREPARES=false）では同じ名前付き
            // プレースホルダを複数箇所に置けないため、別名にして同じ値を渡す
            $sql .= ' WHERE email LIKE :keyword_email OR display_name LIKE :keyword_name';
            $params['keyword_email'] = '%' . self::escapeLike($keyword) . '%';
            $params['keyword_name'] = $params['keyword_email'];
        }

        // LIMIT/OFFSET はプレースホルダにすると文字列として渡り構文エラーになるため、
        // int にキャストしてから埋め込む（呼び出し元で範囲は検証済み）。
        $sql .= sprintf(' ORDER BY id ASC LIMIT %d OFFSET %d', $limit, $offset);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    public function countAll(?string $keyword = null): int
    {
        $sql = 'SELECT COUNT(*) FROM users';
        $params = [];

        if ($keyword !== null) {
            // ネイティブプリペアド（EMULATE_PREPARES=false）では同じ名前付き
            // プレースホルダを複数箇所に置けないため、別名にして同じ値を渡す
            $sql .= ' WHERE email LIKE :keyword_email OR display_name LIKE :keyword_name';
            $params['keyword_email'] = '%' . self::escapeLike($keyword) . '%';
            $params['keyword_name'] = $params['keyword_email'];
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** LIKE のワイルドカード文字を無効化する（検索語の `%` を全件一致にしない）。 */
    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
