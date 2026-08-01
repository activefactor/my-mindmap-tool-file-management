import { useCallback, useEffect, useState } from 'react';

import { ApiError, apiRequest } from '../../api/client';
import { useAuth } from '../../hooks/useAuth';
import type {
  AdminUser,
  AdminUserListResponse,
  PaginationMeta,
  UserRole,
  UserStatus,
} from '../../types/admin';
import { formatDateTime } from '../../utils/formatDateTime';

/** サーバーが返すエラーコードを、管理者向けの説明文に変換する。 */
const ERROR_MESSAGES: Record<string, string> = {
  cannot_modify_self: '自分自身のロール・状態は変更できません。',
  last_admin_protected: '最後の有効な管理者を降格・無効化することはできません。',
  concurrent_modification: '他の管理操作と競合しました。画面を更新してからやり直してください。',
  user_not_found: '対象のユーザーが見つかりませんでした。',
  forbidden: '権限がありません。',
};

const describeError = (error: unknown): string => {
  if (error instanceof ApiError) {
    return ERROR_MESSAGES[error.code] ?? `操作に失敗しました（${error.code}）。`;
  }

  return '通信に失敗しました。';
};

export const UserTable = () => {
  const { user: me } = useAuth();

  const [users, setUsers] = useState<AdminUser[]>([]);
  const [pagination, setPagination] = useState<PaginationMeta | null>(null);
  const [page, setPage] = useState(1);
  const [keyword, setKeyword] = useState('');
  const [searchInput, setSearchInput] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  /** 更新中の行。二重送信とチラつきを防ぐ。 */
  const [pendingId, setPendingId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);

    try {
      const response = await apiRequest<AdminUserListResponse>('/api/admin/users', {
        query: { page, q: keyword },
      });

      setUsers(response.users);
      setPagination(response.pagination);
      setError(null);
    } catch (e) {
      setError(describeError(e));
    } finally {
      setLoading(false);
    }
  }, [page, keyword]);

  useEffect(() => {
    void load();
  }, [load]);

  const update = async (id: number, path: string, body: Record<string, string>) => {
    setPendingId(id);

    try {
      await apiRequest(`/api/admin/users/${id}/${path}`, { method: 'PUT', body });
      setError(null);
      await load();
    } catch (e) {
      setError(describeError(e));
    } finally {
      setPendingId(null);
    }
  };

  const handleSearch = (event: React.FormEvent) => {
    event.preventDefault();
    setPage(1);
    setKeyword(searchInput.trim());
  };

  return (
    <section className="admin-section">
      <form className="admin-toolbar" onSubmit={handleSearch}>
        <input
          className="admin-input"
          type="search"
          value={searchInput}
          placeholder="メールアドレス・氏名で検索"
          onChange={(event) => setSearchInput(event.target.value)}
        />
        <button className="admin-button" type="submit">
          検索
        </button>
        {keyword !== '' && (
          <button
            className="admin-button admin-button--ghost"
            type="button"
            onClick={() => {
              setSearchInput('');
              setKeyword('');
              setPage(1);
            }}
          >
            クリア
          </button>
        )}
      </form>

      {error !== null && (
        <p className="admin-error" role="alert">
          {error}
        </p>
      )}

      {loading ? (
        <p className="admin-status">読み込み中…</p>
      ) : (
        <div className="admin-table-wrapper">
          <table className="admin-table">
            <thead>
              <tr>
                <th>メールアドレス</th>
                <th>氏名</th>
                <th>ロール</th>
                <th>状態</th>
                <th>最終ログイン</th>
              </tr>
            </thead>
            <tbody>
              {users.map((user) => {
                // 自分自身は変更できない（サーバー側でも 403 になる）
                const isSelf = me !== null && me.id === user.id;
                const disabled = isSelf || pendingId === user.id;

                return (
                  <tr key={user.id}>
                    <td>
                      {user.email}
                      {isSelf && <span className="admin-badge">自分</span>}
                    </td>
                    <td>{user.display_name}</td>
                    <td>
                      <select
                        className="admin-select"
                        value={user.role}
                        disabled={disabled}
                        onChange={(event) =>
                          void update(user.id, 'role', { role: event.target.value as UserRole })
                        }
                      >
                        <option value="user">一般</option>
                        <option value="admin">管理者</option>
                      </select>
                    </td>
                    <td>
                      <select
                        className="admin-select"
                        value={user.status}
                        disabled={disabled}
                        onChange={(event) =>
                          void update(user.id, 'status', {
                            status: event.target.value as UserStatus,
                          })
                        }
                      >
                        <option value="active">有効</option>
                        <option value="disabled">無効</option>
                      </select>
                    </td>
                    <td>{formatDateTime(user.last_login_at)}</td>
                  </tr>
                );
              })}
              {users.length === 0 && (
                <tr>
                  <td colSpan={5} className="admin-status">
                    該当するユーザーがいません。
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}

      {pagination !== null && pagination.total_pages > 1 && (
        <nav className="admin-pagination">
          <button
            className="admin-button admin-button--ghost"
            type="button"
            disabled={pagination.page <= 1}
            onClick={() => setPage((current) => current - 1)}
          >
            前へ
          </button>
          <span>
            {pagination.page} / {pagination.total_pages}（全 {pagination.total} 件）
          </span>
          <button
            className="admin-button admin-button--ghost"
            type="button"
            disabled={pagination.page >= pagination.total_pages}
            onClick={() => setPage((current) => current + 1)}
          >
            次へ
          </button>
        </nav>
      )}
    </section>
  );
};
