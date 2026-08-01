import { useCallback, useEffect, useState } from 'react';

import { ApiError, apiRequest } from '../../api/client';
import type { AuditLogEntry, AuditLogListResponse, PaginationMeta } from '../../types/admin';
import { formatDateTime } from '../../utils/formatDateTime';

/** 監査ログの操作種別を日本語にする。未知の種別はコードのまま表示する。 */
const ACTION_LABELS: Record<string, string> = {
  login: 'ログイン',
  login_first_time: '初回ログイン',
  login_denied: 'ログイン拒否',
  login_denied_conflict: 'ログイン拒否（アカウント競合）',
  login_denied_disabled: 'ログイン拒否（無効化済み）',
  login_failed: 'ログイン失敗',
  logout: 'ログアウト',
  admin_role_changed: 'ロール変更',
  admin_status_changed: '状態変更',
  admin_role_change_denied: 'ロール変更の拒否',
  admin_status_change_denied: '状態変更の拒否',
  admin_allowed_domain_added: '許可ドメイン追加',
  admin_allowed_domain_removed: '許可ドメイン削除',
  admin_allowed_email_added: '許可アドレス追加',
  admin_allowed_email_removed: '許可アドレス削除',
};

/**
 * `detail` は操作種別ごとに構造が異なるJSON。無理に整形せず、
 * 主要な項目だけを読みやすく並べる。
 */
const describeDetail = (detail: AuditLogEntry['detail']): string => {
  if (detail === null) {
    return '—';
  }

  if (typeof detail.before === 'string' && typeof detail.after === 'string') {
    return `${detail.before} → ${detail.after}`;
  }

  return Object.entries(detail)
    .map(([key, value]) => `${key}: ${String(value)}`)
    .join(', ');
};

export const AuditLogTable = () => {
  const [logs, setLogs] = useState<AuditLogEntry[]>([]);
  const [actions, setActions] = useState<string[]>([]);
  const [pagination, setPagination] = useState<PaginationMeta | null>(null);
  const [page, setPage] = useState(1);
  const [action, setAction] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);

    try {
      const response = await apiRequest<AuditLogListResponse>('/api/admin/audit-logs', {
        query: { page, action, from, to },
      });

      setLogs(response.logs);
      setActions(response.actions);
      setPagination(response.pagination);
      setError(null);
    } catch (e) {
      setError(
        e instanceof ApiError ? `取得に失敗しました（${e.code}）。` : '通信に失敗しました。',
      );
    } finally {
      setLoading(false);
    }
  }, [page, action, from, to]);

  useEffect(() => {
    void load();
  }, [load]);

  /** フィルタを変更したら1ページ目に戻す（該当なしのページに留まらないように）。 */
  const changeFilter = (setter: (value: string) => void) => (value: string) => {
    setPage(1);
    setter(value);
  };

  return (
    <section className="admin-section">
      <div className="admin-toolbar">
        <label className="admin-field">
          操作種別
          <select
            className="admin-select"
            value={action}
            onChange={(event) => changeFilter(setAction)(event.target.value)}
          >
            <option value="">すべて</option>
            {actions.map((value) => (
              <option key={value} value={value}>
                {ACTION_LABELS[value] ?? value}
              </option>
            ))}
          </select>
        </label>

        <label className="admin-field">
          開始日
          <input
            className="admin-input"
            type="date"
            value={from}
            onChange={(event) => changeFilter(setFrom)(event.target.value)}
          />
        </label>

        <label className="admin-field">
          終了日
          <input
            className="admin-input"
            type="date"
            value={to}
            onChange={(event) => changeFilter(setTo)(event.target.value)}
          />
        </label>
      </div>

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
                <th>日時</th>
                <th>操作</th>
                <th>実行者</th>
                <th>対象</th>
                <th>内容</th>
              </tr>
            </thead>
            <tbody>
              {logs.map((log) => (
                <tr key={log.id}>
                  <td>{formatDateTime(log.created_at)}</td>
                  <td>{ACTION_LABELS[log.action] ?? log.action}</td>
                  <td>{log.actor_email ?? '—'}</td>
                  <td>{log.target ?? '—'}</td>
                  <td className="admin-detail">{describeDetail(log.detail)}</td>
                </tr>
              ))}
              {logs.length === 0 && (
                <tr>
                  <td colSpan={5} className="admin-status">
                    該当するログがありません。
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
