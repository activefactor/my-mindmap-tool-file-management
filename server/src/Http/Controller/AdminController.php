<?php

declare(strict_types=1);

namespace App\Http\Controller;

use App\Admin\UserAdminService;
use App\Http\ApiException;
use App\Http\AuthGuard;
use App\Http\Pagination;
use App\Http\RequestBody;
use App\Repository\AllowListRepository;
use App\Repository\AuditLogRepository;
use App\Repository\StorageUsageRepository;
use App\Repository\UserRepository;
use App\Support\Response;

/**
 * 管理コンソール API（FR-08、基本設計書_Phase2.md §3.2, §5.2）。
 *
 * すべてのエンドポイントで `AuthGuard::requireAdmin()` を通す。状態変更系は
 * フロントコントローラ側で CSRF 検証済み（§6.1）。
 */
final class AdminController
{
    private const MAX_VALUE_LENGTH = 255;

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly AllowListRepository $allowList = new AllowListRepository(),
        private readonly AuditLogRepository $auditLogs = new AuditLogRepository(),
        private readonly StorageUsageRepository $storage = new StorageUsageRepository(),
        private readonly UserAdminService $userAdmin = new UserAdminService(),
    ) {
    }

    /** GET /api/admin/users */
    public function listUsers(): void
    {
        AuthGuard::requireAdmin($this->users);

        $page = Pagination::fromQuery($_GET);
        $keyword = self::optionalQueryString('q');

        $rows = $this->users->paginate($page->perPage, $page->offset(), $keyword);
        $total = $this->users->countAll($keyword);

        Response::json([
            'users' => array_map(self::presentUser(...), $rows),
            'pagination' => $page->meta($total),
        ]);
    }

    /** PUT /api/admin/users/{id}/role */
    public function changeRole(string $id): void
    {
        $admin = AuthGuard::requireAdmin($this->users);
        $targetId = self::parseId($id);

        $role = RequestBody::requireEnum(
            RequestBody::json(),
            'role',
            UserAdminService::ROLES,
            'invalid_role'
        );

        $result = $this->guardAdminOperation(
            fn (): array => $this->userAdmin->changeRole((int) $admin['id'], $targetId, $role),
            'admin_role_change_denied',
            (int) $admin['id'],
            $targetId,
            ['requested_role' => $role]
        );

        Response::json(['status' => 'ok'] + $result);
    }

    /** PUT /api/admin/users/{id}/status */
    public function changeStatus(string $id): void
    {
        $admin = AuthGuard::requireAdmin($this->users);
        $targetId = self::parseId($id);

        $status = RequestBody::requireEnum(
            RequestBody::json(),
            'status',
            UserAdminService::STATUSES,
            'invalid_status'
        );

        $result = $this->guardAdminOperation(
            fn (): array => $this->userAdmin->changeStatus((int) $admin['id'], $targetId, $status),
            'admin_status_change_denied',
            (int) $admin['id'],
            $targetId,
            ['requested_status' => $status]
        );

        Response::json(['status' => 'ok'] + $result);
    }

    /** GET /api/admin/allowed-domains */
    public function listAllowedDomains(): void
    {
        AuthGuard::requireAdmin($this->users);

        Response::json(['domains' => $this->allowList->listDomains()]);
    }

    /** POST /api/admin/allowed-domains */
    public function addAllowedDomain(): void
    {
        $admin = AuthGuard::requireAdmin($this->users);

        $domain = self::normalizeDomain(
            RequestBody::requireString(RequestBody::json(), 'domain', self::MAX_VALUE_LENGTH)
        );

        $id = $this->allowList->addDomain($domain, (int) $admin['id']);

        if ($id === null) {
            throw new ApiException('already_exists', 409, "既に登録済みのドメインです: {$domain}");
        }

        $this->auditLogs->record('admin_allowed_domain_added', (int) $admin['id'], $domain);

        Response::json(['id' => $id, 'value' => $domain], 201);
    }

    /** DELETE /api/admin/allowed-domains/{id} */
    public function removeAllowedDomain(string $id): void
    {
        $admin = AuthGuard::requireAdmin($this->users);

        $removed = $this->allowList->removeDomain(self::parseId($id));

        if ($removed === null) {
            throw new ApiException('not_found', 404, "許可ドメインが存在しません: {$id}");
        }

        $this->auditLogs->record('admin_allowed_domain_removed', (int) $admin['id'], $removed);

        Response::json(['status' => 'ok', 'value' => $removed]);
    }

    /** GET /api/admin/allowed-emails */
    public function listAllowedEmails(): void
    {
        AuthGuard::requireAdmin($this->users);

        Response::json(['emails' => $this->allowList->listEmails()]);
    }

    /** POST /api/admin/allowed-emails */
    public function addAllowedEmail(): void
    {
        $admin = AuthGuard::requireAdmin($this->users);

        $email = self::normalizeEmail(
            RequestBody::requireString(RequestBody::json(), 'email', self::MAX_VALUE_LENGTH)
        );

        $id = $this->allowList->addEmail($email, (int) $admin['id']);

        if ($id === null) {
            throw new ApiException('already_exists', 409, "既に登録済みのアドレスです: {$email}");
        }

        $this->auditLogs->record('admin_allowed_email_added', (int) $admin['id'], $email);

        Response::json(['id' => $id, 'value' => $email], 201);
    }

    /** DELETE /api/admin/allowed-emails/{id} */
    public function removeAllowedEmail(string $id): void
    {
        $admin = AuthGuard::requireAdmin($this->users);

        $removed = $this->allowList->removeEmail(self::parseId($id));

        if ($removed === null) {
            throw new ApiException('not_found', 404, "許可アドレスが存在しません: {$id}");
        }

        $this->auditLogs->record('admin_allowed_email_removed', (int) $admin['id'], $removed);

        Response::json(['status' => 'ok', 'value' => $removed]);
    }

    /** GET /api/admin/audit-logs */
    public function listAuditLogs(): void
    {
        AuthGuard::requireAdmin($this->users);

        $page = Pagination::fromQuery($_GET);

        $filters = array_filter([
            'action' => self::optionalQueryString('action'),
            'from' => self::optionalDateTime('from'),
            'to' => self::optionalDateTime('to'),
        ], static fn (?string $v): bool => $v !== null);

        $rows = $this->auditLogs->paginate($page->perPage, $page->offset(), $filters);

        Response::json([
            'logs' => array_map(self::presentAuditLog(...), $rows),
            'actions' => $this->auditLogs->distinctActions(),
            'pagination' => $page->meta($this->auditLogs->count($filters)),
        ]);
    }

    /** GET /api/admin/storage-usage */
    public function storageUsage(): void
    {
        AuthGuard::requireAdmin($this->users);

        Response::json([
            'per_user' => array_map(
                static fn (array $row): array => [
                    'user_id' => (int) $row['user_id'],
                    'email' => $row['email'],
                    'display_name' => $row['display_name'],
                    'map_count' => (int) $row['map_count'],
                    'active_map_count' => (int) $row['active_map_count'],
                    'trashed_map_count' => (int) $row['trashed_map_count'],
                    'approx_bytes' => (int) $row['approx_bytes'],
                ],
                $this->storage->perUser()
            ),
            'total' => $this->storage->total(),
            // 画面に「概算」であることを明示させるための注記（§3.2）
            'note' => 'JSONデータ量ベースの概算です。実ディスク使用量とは異なります。',
        ]);
    }

    /**
     * 管理操作を実行し、拒否された場合も監査ログに残す（§6.7「管理者自身の操作も含め
     * すべての管理操作を記録する」）。拒否はトランザクションのロールバック後に記録する
     * 必要があるため、ここで捕捉して記録し直す。
     *
     * @param callable(): array{changed: bool, before: string, after: string} $operation
     * @param array<string, mixed> $detail
     * @return array{changed: bool, before: string, after: string}
     */
    private function guardAdminOperation(
        callable $operation,
        string $deniedAction,
        int $actorUserId,
        int $targetUserId,
        array $detail
    ): array {
        try {
            return $operation();
        } catch (ApiException $e) {
            $this->auditLogs->record(
                $deniedAction,
                $actorUserId,
                (string) $targetUserId,
                $detail + ['reason' => $e->errorCode]
            );

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentUser(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'email' => $row['email'],
            'display_name' => $row['display_name'],
            'role' => $row['role'],
            'status' => $row['status'],
            'last_login_at' => $row['last_login_at'],
            'created_at' => $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentAuditLog(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'action' => $row['action'],
            'actor_user_id' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            'actor_email' => $row['actor_email'],
            'target' => $row['target'],
            // detail は JSON カラム。文字列のまま返さずデコードして構造として渡す
            'detail' => $row['detail'] === null ? null : json_decode((string) $row['detail'], true),
            'created_at' => $row['created_at'],
        ];
    }

    private static function parseId(string $raw): int
    {
        $id = filter_var($raw, FILTER_VALIDATE_INT);

        if ($id === false || $id < 1) {
            throw new ApiException('invalid_request', 400, "IDの形式が不正です: {$raw}");
        }

        return $id;
    }

    private static function optionalQueryString(string $key): ?string
    {
        $value = $_GET[$key] ?? null;

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            return null;
        }

        return $value;
    }

    /**
     * 日時フィルタ。`YYYY-MM-DD` または `YYYY-MM-DD HH:MM:SS` のみを受け付け、
     * それ以外は無視する（不正な値でSQLエラーにしない）。
     */
    private static function optionalDateTime(string $key): ?string
    {
        $value = self::optionalQueryString($key);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value) !== 1) {
            throw new ApiException('invalid_request', 422, "日時フィルタの形式が不正です: {$key}");
        }

        // 日付のみ指定された場合、to はその日の終わりまでを含める
        if ($key === 'to' && strlen($value) === 10) {
            return $value . ' 23:59:59';
        }

        return $value;
    }

    private static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(ltrim($domain, '@'));

        // ラベルは英数字とハイフン、先頭末尾はハイフン不可。TLDは2文字以上。
        // 国際化ドメインは punycode（xn--）で登録してもらう前提とする。
        $pattern = '/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))*\.[a-z]{2,63}$/';

        if (preg_match($pattern, $domain) !== 1) {
            throw new ApiException('invalid_domain', 422, "ドメインの形式が不正です: {$domain}");
        }

        return $domain;
    }

    private static function normalizeEmail(string $email): string
    {
        $email = strtolower($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException('invalid_email', 422, "メールアドレスの形式が不正です: {$email}");
        }

        return $email;
    }
}
