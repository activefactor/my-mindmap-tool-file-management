# Mindmap Tool — バックエンド（Phase 2）

heteml（PHP 8.5 CGI版 + MySQL 8.4）向けの PHP バックエンド。設計の詳細は
[docs/基本設計書_Phase2.md](../docs/基本設計書_Phase2.md) を参照。

現状は Step 3（認証基盤）まで実装済み。ヘルスチェックと SSO 認証（Google / Microsoft）の
エンドポイントが動作する。マインドマップの保存API等は
[docs/開発ステップ_Phase2.md](../docs/開発ステップ_Phase2.md) の Step 4 以降で追加する。

## ローカル環境の起動（Docker）

リポジトリルートで実行する。

```bash
cp server/.env.example server/.env
# server/.env の DB_HOST が mysql になっていることを確認する（ローカルDocker用）

docker compose up -d --build
```

起動後、以下でヘルスチェックエンドポイントに疎通できることを確認する。

```bash
curl http://localhost:8080/api/health
# => {"status":"ok","php_version":"8.5.x"}
```

Composer の依存関係をインストールする場合（Step 3 以降でライブラリを追加した際）:

```bash
docker compose exec php composer install
```

MySQL には `localhost:3306`（ユーザー: `mindmap` / パスワード: `mindmap` / DB: `mindmap`）で
接続できる。

> **注意**: `docker-compose.yml` は `server/.env` を `env_file` として読み込むため、環境変数は
> **コンテナ生成時に注入される**。`.env` を書き換えたときはファイルを保存するだけでは反映されず、
> コンテナの作り直しが必要になる。
>
> ```bash
> docker compose up -d --force-recreate php
> ```

## データベースの初期化

```bash
# マイグレーション適用（何度実行しても安全）
docker compose exec php php db/migrate.php

# 初期管理者の投入（.env の INITIAL_ADMIN_EMAIL / INITIAL_ALLOWED_DOMAIN を使用）
docker compose exec php php db/seed.php
```

`db/seed.php` は `users` 行を作るだけで `user_identities` は作らない。実際に SSO ログイン
した時点で identity が紐付く（基本設計書 §3.1）。

## SSO ログインの設定（ローカル）

1. Google Cloud Console で「ウェブ アプリケーション」の OAuth クライアントを作成し、
   承認済みのリダイレクトURIに `http://localhost:8080/api/auth/google/callback` を登録する
   （JavaScript 生成元はサーバーサイド完結のフローのため不要）。
2. `server/.env` の `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` を設定する。
3. `INITIAL_ADMIN_EMAIL` にログインに使うメールアドレスを設定して `db/seed.php` を実行する。
   **個人の Gmail アカウントはドメイン許可では通らない**（`hd` クレームを持たないため）。
   その場合 `INITIAL_ALLOWED_DOMAIN` は空にし、個別のメール許可で運用する。
4. `docker compose up -d --force-recreate php` で反映し、ブラウザで
   `http://localhost:8080/api/auth/google/redirect` を開く。

ログインに成功すると `APP_URL/dashboard` にリダイレクトされる。ダッシュボード画面は Step 8 で
実装するため、現時点では 404 になるのが正常。セッションの確認は
`http://localhost:8080/api/auth/me`（同じブラウザで開く）で行う。

Microsoft も同じ構造で `MS_CLIENT_ID` / `MS_CLIENT_SECRET` / `MS_REDIRECT_URI` を設定すれば
有効になる。設定しなくても Google のフローには影響しない。

## テスト

```bash
docker compose exec php ./vendor/bin/phpunit
```

テストは Docker 上の MySQL を直接操作するため、`tests/bootstrap.php` が `DB_HOST` を
ローカル向けの値に限定している（本番DBを誤って破壊しないための安全装置）。

> **注意**: テストは `users` / `user_identities` を全削除する。テスト実行後はローカルの
> ログイン状態が失効するため、`db/seed.php` を再実行してログインし直すこと。

フロントエンド（`mindmap-tool/`）は従来どおりホスト側で `npm run dev` を実行する
（[README.md](../README.md) 参照）。

## 停止

```bash
docker compose down
```

データベースの中身を含めて完全に削除する場合:

```bash
docker compose down -v
```
