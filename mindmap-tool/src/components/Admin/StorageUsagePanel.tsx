import { useEffect, useState } from 'react';

import { ApiError, apiRequest } from '../../api/client';
import type { StorageUsageResponse } from '../../types/admin';
import { formatBytes } from '../../utils/formatDateTime';

/** ストレージ使用状況（FR-08-7）。 */
export const StorageUsagePanel = () => {
  const [usage, setUsage] = useState<StorageUsageResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const load = async () => {
      try {
        setUsage(await apiRequest<StorageUsageResponse>('/api/admin/storage-usage'));
        setError(null);
      } catch (e) {
        setError(
          e instanceof ApiError ? `取得に失敗しました（${e.code}）。` : '通信に失敗しました。',
        );
      } finally {
        setLoading(false);
      }
    };

    void load();
  }, []);

  if (loading) {
    return <p className="admin-status">読み込み中…</p>;
  }

  if (error !== null || usage === null) {
    return (
      <p className="admin-error" role="alert">
        {error ?? '取得に失敗しました。'}
      </p>
    );
  }

  return (
    <section className="admin-section">
      {/* 実ディスク使用量ではないことを画面上で明示する（基本設計書 §3.2） */}
      <p className="admin-description">{usage.note}</p>

      <p className="admin-summary">
        全体: {usage.total.map_count} 件 / 約 {formatBytes(usage.total.approx_bytes)}
      </p>

      <div className="admin-table-wrapper">
        <table className="admin-table">
          <thead>
            <tr>
              <th>ユーザー</th>
              <th>マップ数</th>
              <th>ゴミ箱</th>
              <th>概算容量</th>
            </tr>
          </thead>
          <tbody>
            {usage.per_user.map((row) => (
              <tr key={row.user_id}>
                <td>{row.email}</td>
                <td>{row.active_map_count}</td>
                <td>{row.trashed_map_count}</td>
                <td>{formatBytes(row.approx_bytes)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
};
