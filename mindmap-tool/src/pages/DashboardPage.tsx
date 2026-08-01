import { useEffect } from 'react';
import { Link } from 'react-router-dom';

import { useAuth } from '../hooks/useAuth';

/**
 * ダッシュボード（FR-09）。
 *
 * **本実装は Step 7 で行う。** ここでは Step 4（管理コンソール）を動かすために必要な
 * 最小限の受け皿だけを用意している。ログイン成功後のリダイレクト先であり、
 * 一般ユーザーが `/admin` にアクセスしたときの戻り先でもあるため、
 * マップ一覧より先に存在している必要がある。
 */
export const DashboardPage = () => {
  const { user, logout } = useAuth();

  useEffect(() => {
    document.title = 'ダッシュボード | マインドマップツール';
  }, []);

  return (
    <div className="admin-page">
      <header className="admin-header">
        <h1 className="admin-title">ダッシュボード</h1>
        <div className="admin-header-actions">
          {user?.role === 'admin' && (
            <Link className="admin-button admin-button--ghost" to="/admin">
              管理コンソール
            </Link>
          )}
          <span className="admin-user">{user?.email}</span>
          <button className="admin-button admin-button--ghost" type="button" onClick={() => void logout()}>
            ログアウト
          </button>
        </div>
      </header>

      <main className="admin-content">
        <section className="admin-section">
          <p className="admin-description">
            マップ一覧・フォルダ管理は Step 7（ダッシュボード画面）で実装します。
          </p>
          <Link className="admin-button" to="/editor">
            マインドマップを開く
          </Link>
        </section>
      </main>
    </div>
  );
};
