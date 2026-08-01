import { Navigate } from 'react-router-dom';

import { useAuth } from '../hooks/useAuth';

/** ログイン必須ルートのガード（FR-07）。未ログインならログイン画面へ送る。 */
export const RequireAuth = ({ children }: { children: React.ReactNode }) => {
  const { loading, user } = useAuth();

  if (loading) {
    return <p className="admin-status">読み込み中…</p>;
  }

  if (user === null) {
    return <Navigate to="/login" replace />;
  }

  return <>{children}</>;
};
