import { Navigate } from 'react-router-dom';

import { useAuth } from '../hooks/useAuth';

/**
 * 管理者専用ルートのガード（FR-08、基本設計書_Phase2.md §3.2
 * 「一般ユーザーがアクセスした場合は 403 を返しダッシュボードへリダイレクトする」）。
 *
 * これはあくまで画面遷移の制御であり、権限の実体はサーバー側にある
 * （`AuthGuard::requireAdmin()`）。このガードを迂回して直接APIを叩いても 403 になる。
 */
export const RequireAdmin = ({ children }: { children: React.ReactNode }) => {
  const { loading, user } = useAuth();

  if (loading) {
    return <p className="admin-status">読み込み中…</p>;
  }

  if (user === null) {
    return <Navigate to="/login" replace />;
  }

  if (user.role !== 'admin') {
    return <Navigate to="/dashboard" replace />;
  }

  return <>{children}</>;
};
