import { useEffect } from 'react';
import { Navigate, useSearchParams } from 'react-router-dom';

import { useAuth } from '../hooks/useAuth';

/**
 * ログイン画面（FR-07）。
 *
 * OIDCのフローはサーバー側で完結するため、ここでは `/api/auth/{provider}/redirect` へ
 * **ページ遷移**するだけ。fetch ではなく location 遷移にする必要がある
 * （プロバイダの認可画面はクロスオリジンのトップレベル遷移で表示されるため）。
 */
const ERROR_MESSAGES: Record<string, string> = {
  not_allowed: 'このアカウントは利用を許可されていません。管理者にお問い合わせください。',
  account_disabled: 'このアカウントは無効化されています。管理者にお問い合わせください。',
  account_conflict:
    'このメールアドレスは別のログイン方法で登録済みです。以前と同じ方法でログインしてください。',
  login_denied: 'ログインに失敗しました。もう一度お試しください。',
  provider_error: 'ログインがキャンセルされました。',
  unsupported_provider: '指定されたログイン方法には対応していません。',
  invalid_id_token: 'ログイン情報の検証に失敗しました。もう一度お試しください。',
  invalid_state: 'ログインの有効期限が切れました。もう一度お試しください。',
  no_email: 'アカウントからメールアドレスを取得できませんでした。管理者にお問い合わせください。',
  server_error: 'サーバーでエラーが発生しました。時間をおいて再度お試しください。',
};

export const LoginPage = () => {
  const { loading, user } = useAuth();
  const [searchParams] = useSearchParams();

  const errorCode = searchParams.get('error');

  useEffect(() => {
    document.title = 'ログイン | マインドマップツール';
  }, []);

  if (loading) {
    return <p className="admin-status">読み込み中…</p>;
  }

  if (user !== null) {
    return <Navigate to="/dashboard" replace />;
  }

  return (
    <main className="login-page">
      <div className="login-card">
        <h1 className="login-title">マインドマップツール</h1>
        <p className="login-lead">お使いのアカウントでログインしてください。</p>

        {errorCode !== null && (
          <p className="login-error" role="alert">
            {ERROR_MESSAGES[errorCode] ?? 'ログインに失敗しました。'}
          </p>
        )}

        <div className="login-actions">
          <a className="login-button" href="/api/auth/google/redirect">
            Google でログイン
          </a>
          <a className="login-button login-button--secondary" href="/api/auth/microsoft/redirect">
            Microsoft でログイン
          </a>
        </div>
      </div>
    </main>
  );
};
