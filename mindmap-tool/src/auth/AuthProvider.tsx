import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';

import { apiRequest, setCsrfToken } from '../api/client';
import { AuthContext, type AuthState, type AuthUser } from './AuthContext';

interface MeResponse {
  user: AuthUser;
  csrf_token: string;
}

/**
 * ログイン状態とCSRFトークンを一元管理する（基本設計書_Phase2.md §7 `useAuth`）。
 *
 * `/api/auth/me` は 401 を「未ログイン」という正常な状態として返すため、
 * 通信エラーとは区別せず user = null として扱う。
 */
export const AuthProvider = ({ children }: { children: ReactNode }) => {
  const [loading, setLoading] = useState(true);
  const [user, setUser] = useState<AuthUser | null>(null);

  const reload = useCallback(async () => {
    setLoading(true);

    try {
      const me = await apiRequest<MeResponse>('/api/auth/me');

      setCsrfToken(me.csrf_token);
      setUser(me.user);
    } catch {
      // 401（未ログイン）もネットワークエラーも、画面としては「未ログイン」で足りる
      setCsrfToken(null);
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await apiRequest('/api/auth/logout', { method: 'POST' });
    } finally {
      // 失敗しても手元の状態は落とす（サーバー側セッションはタイムアウトで失効する）
      setCsrfToken(null);
      setUser(null);
    }
  }, []);

  useEffect(() => {
    void reload();
  }, [reload]);

  const value = useMemo<AuthState>(
    () => ({ loading, user, reload, logout }),
    [loading, user, reload, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
};
