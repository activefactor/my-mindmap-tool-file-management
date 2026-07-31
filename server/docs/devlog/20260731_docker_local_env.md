# 開発ログ: Step 1 — ローカルDocker開発環境の構築

**日付**: 2026-07-31
**担当**: Claude Code
**関連機能**: 開発ステップ_Phase2.md Step 1、基本設計書_Phase2.md §7, §8, §10

---

## 背景・課題

Step 0 で heteml の実機仕様（PHP 8.5 CGI版、MySQL 8.4、公開パス
`/web/activefactor.org/mindmap/`）が確定したため、Step 1 としてローカルでこれに近い
環境を Docker で再現する作業に着手した。`server/` ディレクトリはこの時点でまだ存在せず、
本セッションが `server/` への最初のコード投入となる。

## 検討した選択肢

### ローカルDockerのPHP実行方式

- **選択肢A（採用）**: `php:8.5-apache`（Apache + mod_php）
  - メリット: 単一コンテナで完結し、`.htaccess` による `AllowOverride` もそのまま使え、
    本番（heteml、Apache + CGI版PHP）の `.htaccess` ベースのルーティング設計と概念的に
    最も近い。ローカル開発用途では十分
  - デメリット: 本番のCGI実行方式とは厳密には異なる（プロセスモデルが違う）が、
    アプリケーションコードの動作自体に影響する差ではないと判断した
- **選択肢B**: `php:8.5-fpm` + 別コンテナの nginx
  - メリット: 本番相当のパフォーマンス特性に近づけられる
  - デメリット: コンテナ数が増え、ローカル開発用途としては構成が過剰

選択肢Aを採用した。基本設計書_Phase2.md §10 でも「本番はCGI版PHPだが、ローカル開発では
Apache/PHP-FPM等の一般的な構成で差し支えない」とされており、この方針に沿う。

### PHP拡張のインストール方法

公式 `php:8.5-apache` イメージには `curl`, `zip`, `pdo_mysql`, `mbstring` 拡張が
デフォルトで有効になっていないため、`docker-php-ext-install` で明示的にインストールする
設計とした（`openssl` はデフォルトで組み込まれているため対象外）。ビルド時に
`libzip-dev`, `libonig-dev`（mbstring用）, `libcurl4-openssl-dev` の追加も必要。

## 決定した内容とその理由

- `docker-compose.yml`（リポジトリルート）で `php`（`server/Dockerfile` からビルド）と
  `mysql:8.4` の2コンテナを構成。
- `server/public/` をDocumentRootとし、`server/public/api/index.php` を
  フロントコントローラとする構成を、本番（基本設計書_Phase2.md §1.3）と同じ考え方で
  ローカルにも適用した。
- `server/public/.htaccess` で `/api/*` を `api/index.php` にルーティングする
  （本格的なルーターはStep 4で導入予定。現時点ではヘルスチェックのみ）。
- `.env.example` は基本設計書_Phase2.md §11 の内容をベースにしつつ、`DB_HOST` の既定値を
  ローカルDocker用の `mysql`（サービス名）に変更した（本番の `.env` では `localhost` に
  変更する必要がある旨を `.env.example` 内のコメントに明記）。

## 実装の概要

作成したファイル:

```
docker-compose.yml                       # リポジトリルート
server/
├── Dockerfile
├── docker/apache/000-default.conf       # DocumentRoot を public/ に変更するvhost設定
├── composer.json                        # php制約のみ。依存ライブラリはStep 3以降で追加
├── .env.example
├── public/
│   ├── .htaccess
│   └── api/index.php                    # ヘルスチェック（/api/health）のみ実装
├── src/.gitkeep                         # Step 3以降で Auth/Http/Domain/... を追加していく
├── storage/tmp/.gitkeep                 # Zip生成用一時ディレクトリ（中身はgit管理外）
└── README.md                            # ローカル起動手順
```

`.gitignore` に `server/vendor/`, `server/.env`, `server/storage/tmp/*` を追加した。

## 試行錯誤・ハマったこと

本セッションの実行環境には Docker がインストールされておらず（`docker`コマンドが存在しない）、
実際に `docker compose up` を実行して動作確認することができなかった。設定ファイルの構文
（`composer.json` のJSON構文、`docker-compose.yml` のYAML構文）は Python の `json`/`yaml`
モジュールでパース検証を行ったが、実際にコンテナがビルド・起動できるか、ヘルスチェック
エンドポイントに疎通できるかは、Docker が使える環境（開発者のローカルPC）でユーザー自身に
確認してもらう必要がある。この制約は開発ステップ_Phase2.md のStep 1に明記した。

## 今後の課題・TODO

- ユーザーに `server/README.md` の手順で `docker compose up` を実行し、
  `curl http://localhost:8080/api/health` が疎通することを確認してもらう。
- 問題があれば（イメージのビルド失敗、拡張モジュール不足など）、Dockerfileを修正する。
- 次はStep 2（DBスキーマ実装・migrations）に進む。
