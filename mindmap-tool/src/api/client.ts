/**
 * バックエンドAPIの fetch ラッパー（基本設計書_Phase2.md §7, §6.1）。
 *
 * - セッションは Cookie ベースなので `credentials: 'same-origin'` を必須にする
 * - 状態変更リクエストには `X-CSRF-Token` を付与する（トークンは `/api/auth/me` から取得）
 * - ボディを伴うリクエストは `Content-Type: application/json`
 *   （サーバー側がフォーム形式を 415 で拒否するため）
 */

/** サーバーが返すエラーコードをそのまま保持する例外。画面側で分岐できるようにする。 */
export class ApiError extends Error {
  // tsconfig の erasableSyntaxOnly が有効なため、コンストラクタ引数プロパティは使わない
  readonly status: number;
  readonly code: string;
  readonly correlationId?: string;

  constructor(status: number, code: string, correlationId?: string) {
    super(`${code} (HTTP ${status})`);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.correlationId = correlationId;
  }
}

let csrfToken: string | null = null;

export const setCsrfToken = (token: string | null): void => {
  csrfToken = token;
};

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  body?: unknown;
  /** クエリパラメータ。undefined / 空文字の項目は送らない。 */
  query?: Record<string, string | number | undefined>;
}

const buildUrl = (path: string, query?: RequestOptions['query']): string => {
  if (!query) return path;

  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== '') {
      params.set(key, String(value));
    }
  }

  const queryString = params.toString();

  return queryString === '' ? path : `${path}?${queryString}`;
};

export const apiRequest = async <T>(path: string, options: RequestOptions = {}): Promise<T> => {
  const method = options.method ?? 'GET';
  const headers: Record<string, string> = {};

  if (method !== 'GET') {
    // トークンが無い状態で送ると必ず403になるので、その場合もサーバーに判断させる
    if (csrfToken !== null) {
      headers['X-CSRF-Token'] = csrfToken;
    }

    if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }
  }

  const response = await fetch(buildUrl(path, options.query), {
    method,
    headers,
    credentials: 'same-origin',
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
  });

  if (!response.ok) {
    // エラー応答もJSONだが、502等でHTMLが返る可能性があるので防御的に扱う
    const payload = await response.json().catch(() => null);

    throw new ApiError(
      response.status,
      typeof payload?.error === 'string' ? payload.error : 'unknown_error',
      typeof payload?.correlation_id === 'string' ? payload.correlation_id : undefined,
    );
  }

  return (await response.json()) as T;
};
