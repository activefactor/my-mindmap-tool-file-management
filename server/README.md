# Mindmap Tool — バックエンド（Phase 2）

heteml（PHP 8.5 CGI版 + MySQL 8.4）向けの PHP バックエンド。設計の詳細は
[docs/基本設計書_Phase2.md](../docs/基本設計書_Phase2.md) を参照。

現状は Step 1（ローカル開発環境構築）の段階で、疎通確認用のヘルスチェックエンドポイントのみ
実装済み。認証・保存API等は [docs/開発ステップ_Phase2.md](../docs/開発ステップ_Phase2.md)
の Step 3 以降で追加する。

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
