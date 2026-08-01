import { useContext } from 'react';

import { AuthContext, type AuthState } from '../auth/AuthContext';

/** ログイン状態を取得する（基本設計書_Phase2.md §7）。 */
export const useAuth = (): AuthState => {
  const context = useContext(AuthContext);

  if (context === null) {
    throw new Error('useAuth は AuthProvider の内側でのみ使用できます。');
  }

  return context;
};
