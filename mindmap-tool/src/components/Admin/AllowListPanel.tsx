import { useCallback, useEffect, useState } from 'react';

import { ApiError, apiRequest } from '../../api/client';
import type { AllowListEntry } from '../../types/admin';
import { formatDateTime } from '../../utils/formatDateTime';

const ERROR_MESSAGES: Record<string, string> = {
  already_exists: 'すでに登録されています。',
  invalid_domain: 'ドメインの形式が正しくありません（例: company.co.jp）。',
  invalid_email: 'メールアドレスの形式が正しくありません。',
  not_found: '対象が見つかりませんでした。すでに削除されている可能性があります。',
  invalid_request: '入力内容を確認してください。',
};

interface Props {
  /** APIのパス（例: `/api/admin/allowed-domains`）。 */
  endpoint: string;
  /** リクエストボディのキー（`domain` または `email`）。 */
  bodyKey: 'domain' | 'email';
  /** レスポンスの配列を取り出すキー。 */
  responseKey: 'domains' | 'emails';
  label: string;
  placeholder: string;
  description: string;
}

/**
 * 許可ドメイン／許可アドレスの一覧・追加・削除（FR-08-3, FR-08-4）。
 *
 * 両者はAPIの形も操作も同じなので、差分をpropsで受け取る共通コンポーネントにしている。
 */
export const AllowListPanel = ({
  endpoint,
  bodyKey,
  responseKey,
  label,
  placeholder,
  description,
}: Props) => {
  const [entries, setEntries] = useState<AllowListEntry[]>([]);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const describeError = (e: unknown): string =>
    e instanceof ApiError ? (ERROR_MESSAGES[e.code] ?? `操作に失敗しました（${e.code}）。`) : '通信に失敗しました。';

  const load = useCallback(async () => {
    setLoading(true);

    try {
      const response = await apiRequest<Record<string, AllowListEntry[]>>(endpoint);

      setEntries(response[responseKey] ?? []);
      setError(null);
    } catch (e) {
      setError(describeError(e));
    } finally {
      setLoading(false);
    }
  }, [endpoint, responseKey]);

  useEffect(() => {
    void load();
  }, [load]);

  const handleAdd = async (event: React.FormEvent) => {
    event.preventDefault();

    const value = input.trim();

    if (value === '') {
      return;
    }

    setSubmitting(true);

    try {
      await apiRequest(endpoint, { method: 'POST', body: { [bodyKey]: value } });
      setInput('');
      setError(null);
      await load();
    } catch (e) {
      setError(describeError(e));
    } finally {
      setSubmitting(false);
    }
  };

  const handleRemove = async (entry: AllowListEntry) => {
    // 許可リストからの削除は、そのユーザーが次回ログインできなくなる操作なので確認する
    if (!window.confirm(`${entry.value} を${label}から削除しますか？`)) {
      return;
    }

    try {
      await apiRequest(`${endpoint}/${entry.id}`, { method: 'DELETE' });
      setError(null);
      await load();
    } catch (e) {
      setError(describeError(e));
    }
  };

  return (
    <section className="admin-section">
      <h3 className="admin-subtitle">{label}</h3>
      <p className="admin-description">{description}</p>

      <form className="admin-toolbar" onSubmit={handleAdd}>
        <input
          className="admin-input"
          type="text"
          value={input}
          placeholder={placeholder}
          onChange={(event) => setInput(event.target.value)}
        />
        <button className="admin-button" type="submit" disabled={submitting || input.trim() === ''}>
          追加
        </button>
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
                <th>値</th>
                <th>登録者</th>
                <th>登録日時</th>
                <th aria-label="操作" />
              </tr>
            </thead>
            <tbody>
              {entries.map((entry) => (
                <tr key={entry.id}>
                  <td>{entry.value}</td>
                  <td>{entry.created_by_email ?? '—'}</td>
                  <td>{formatDateTime(entry.created_at)}</td>
                  <td>
                    <button
                      className="admin-button admin-button--danger"
                      type="button"
                      onClick={() => void handleRemove(entry)}
                    >
                      削除
                    </button>
                  </td>
                </tr>
              ))}
              {entries.length === 0 && (
                <tr>
                  <td colSpan={4} className="admin-status">
                    まだ登録されていません。
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
};
