# 開発ログ: Step 4 — 管理コンソール画面とルーティングの導入

**日付**: 2026-08-01
**担当**: Claude Code
**関連機能**: 開発ステップ_Phase2.md Step 4、基本設計書_Phase2.md §3.2 §4.5 §7、FR-08

---

## 背景・課題

Phase 1 のフロントエンドは編集画面1枚だけの SPA で、ルーティングも認証も持っていなかった。
一方 Step 3 で実装したサーバー側の認証フローは、ログイン成功時に `APP_URL/dashboard`、
失敗時に `APP_URL/login?error=...` へリダイレクトする。**受け皿となる画面が存在しない**
状態だったため、管理コンソールを作る前にアプリの土台（ルーティング・ログイン状態管理・
APIクライアント）を用意する必要があった。

## 検討した選択肢

ルーティングライブラリの選定は ADR
`docs/adr/20260801_フロントエンドルーティングライブラリ選定.md` に記録した。
要約すると、サーバー側がパスベースのリダイレクトを前提に実装済みである以上、
URLと画面が対応していることが必須要件であり、自前の画面切り替えでは
History API を自前で扱うことになるため `react-router-dom` v7 を採用した。

## 実装の概要

```
mindmap-tool/src/
├── api/client.ts                 # fetchラッパー（Cookie・CSRFヘッダー・エラーコード保持）
├── auth/
│   ├── AuthContext.ts            # Context定義（Fast Refresh のため分離）
│   └── AuthProvider.tsx          # /api/auth/me の取得・CSRFトークン保持・ログアウト
├── hooks/useAuth.ts
├── routes/
│   ├── RequireAuth.tsx           # 未ログイン → /login
│   └── RequireAdmin.tsx          # 一般ユーザー → /dashboard
├── pages/
│   ├── LoginPage.tsx             # プロバイダへのページ遷移・?error= の日本語化
│   ├── DashboardPage.tsx         # ★Step 7 で本実装。今は受け皿のみ
│   └── AdminConsolePage.tsx      # タブ切り替え
├── components/Admin/
│   ├── UserTable.tsx
│   ├── AllowListPanel.tsx        # 許可ドメイン／アドレスの共通実装
│   ├── AllowedDomainList.tsx
│   ├── AllowedEmailList.tsx
│   ├── AuditLogTable.tsx
│   └── StorageUsagePanel.tsx
├── utils/formatDateTime.ts       # UTC→ローカル変換、バイト数の整形
└── styles/admin.css              # tokens.css の変数のみを使用
```

ルートは `/login` `/dashboard` `/admin` `/editor`、未知のパスは `/dashboard` へ寄せる。
Phase 1 の編集画面は `/editor` に移し、ログイン必須にした。

## 設計判断

### 認可ガードは「画面遷移の制御」でしかない

`RequireAdmin` はあくまでルーティングの制御であり、権限の実体はサーバー側の
`AuthGuard::requireAdmin()` にある。ガードを迂回して直接APIを叩いても 403 になるため、
フロント側の実装ミスが権限昇格につながらない。コード上のコメントにも明記した。

### ログインは fetch ではなくページ遷移

`/api/auth/{provider}/redirect` へは `<a href>` で遷移する。プロバイダの認可画面は
クロスオリジンのトップレベル遷移で表示されるため、fetch では成立しない。

### `/api/auth/me` の 401 はエラーではない

未ログインを表す正常な応答なので、`AuthProvider` では通信エラーと区別せず
`user = null` として扱う。ここを例外扱いにすると、初回訪問で毎回エラー表示が出てしまう。

### 許可ドメインと許可アドレスは共通コンポーネント

APIの形も操作も同じなので `AllowListPanel` に共通化し、`AllowedDomainList` /
`AllowedEmailList` は説明文とエンドポイントを与えるだけの薄いラッパーにした
（設計書 §7 のコンポーネント名は維持している）。

説明文には「Google の個人アカウントはドメイン指定では許可されない」ことを書いた。
これは実装上の制約ではなく意図的な設計（`hd` クレームを持たないため）だが、
画面に出ていないと管理者が `gmail.com` を許可ドメインに登録して混乱する。

### 日時は UTC として解釈してから表示する

DBは UTC で保存している（§5.1）。MySQL の `DATETIME` は `2026-08-01 01:23:45` の形式で
タイムゾーン情報を持たないため、そのまま `new Date()` に渡すとブラウザのローカル時刻として
解釈されてずれる。`Z` を補って UTC として解釈させたうえで `Intl.DateTimeFormat` で表示する。

### DESIGN.md 準拠

`admin.css` では色・余白・フォントサイズをすべて `tokens.css` のカスタムプロパティで
指定し、値を直接書いていない（CLAUDE.md の「絶対に守ること」）。

## 試行錯誤・ハマったこと

### `erasableSyntaxOnly` でコンストラクタ引数プロパティが使えない

`ApiError` を `constructor(readonly status: number, ...)` と書いたところ
`TS1294: This syntax is not allowed when 'erasableSyntaxOnly' is enabled` になった。
このオプションが有効だと、型を消すだけでは JS にならない構文（引数プロパティ、enum 等）が
禁止される。フィールドを明示的に宣言して代入する形に直した。

### Context とコンポーネントを同じファイルに置くと Fast Refresh が壊れる

`AuthProvider.tsx` に `createContext` を同居させたら
`react-refresh/only-export-components` で lint エラー。`AuthContext.ts` に分離した。

### 開発サーバー（Vite）ではログインを通せない

`vite.config.ts` に `/api` の proxy を設定して 5173 から 8080 のAPIへ到達できるようにしたが、
**ログインは通らない**。`.env` の `GOOGLE_REDIRECT_URI` と `APP_URL` が
`http://localhost:8080` を指しているため、Google からのコールバックが 8080 に返り、
その後のリダイレクト先も `http://localhost:8080/dashboard`（＝Apache。React の画面は無い）に
なるため。

実際に `curl http://localhost:5173/api/auth/google/redirect` を叩いて、認可URLの
`redirect_uri` が 8080 のままであることを確認した。対処は2案あり、開発ステップ書に
残項目として記載した。

- 案A（推奨）: Google Console に `http://localhost:5173/api/auth/google/callback` を追加し、
  `.env` の `APP_URL` と `GOOGLE_REDIRECT_URI` を 5173 に変更する。全てが同一オリジンになり、
  Cookie と CSRF の Origin 検証も本番と同条件になる。
- 案B: `npm run build` した `dist/` を Apache から配信する。本番構成に近いが HMR が使えない。

### `base` を `/` に変更した

`vite.config.ts` の `base` は GitHub Pages 用に `/my-mindmap-tool/` になっていた。
Phase 2 は独自ドメインのルートに配置するため `/` に変更した。

## テスト

- `npx tsc -b`（型チェック）: エラーなし
- `npm run lint`: エラーなし
- `npm run build`: 成功
- Vite dev サーバー経由で `/admin` が 200 を返し、`/api/auth/me` が proxy 経由で
  バックエンドに到達することを確認

**ブラウザでの操作確認は未実施**（上記のリダイレクトURI対応が前提のため）。

## 今後の課題・TODO

- `DashboardPage` は Step 7 で本実装する。現状はログイン後の受け皿と `/editor` への
  リンクのみ。
- 本番デプロイ時、`BrowserRouter` のために `.htaccess` で `index.html` へのフォールバックが
  必要（Step 11）。これが漏れるとログイン後のリダイレクト先が 404 になる。
- ビルド後のバンドルが 500kB を超えている旨の警告が出る（Phase 1 から継続）。
  コード分割は Step 10 以降で検討する。
