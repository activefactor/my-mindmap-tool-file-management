# プロンプトログ: Step 1（Dockerローカル環境構築）の実施

**日付**: 2026-07-31

## ユーザーの指示（要約）

「進めてください」。直前に提示したStep 0完了報告・Step 1着手の確認に対する承認。

## 主な決定・確認事項

特に追加の確認質問はせず、開発ステップ_Phase2.md Step 1のチェックリストに沿って
`server/` の初期スキャフォールドとDocker構成一式を作成した。

作業中、本セッションの実行環境にはDockerがインストールされておらず、
実際に `docker compose up` して動作確認することができないことが判明した。
設定ファイルの構文（JSON/YAML）は静的に検証したが、実際の起動確認はユーザー側の
Docker環境で行ってもらう必要がある旨をStep 1のステータスに明記した。

## 対応内容

- `server/` ディレクトリを新規作成（本リポジトリで初めてのバックエンドコード）:
  - `composer.json`（PHP `~8.5.0` 制約、`App\` → `src/` のpsr-4オートロード）
  - `.env.example`（ローカルDocker用に `DB_HOST=mysql` を既定値化）
  - `Dockerfile`（`php:8.5-apache` ベース、`pdo_mysql`/`zip`/`mbstring`/`curl`拡張を追加）
  - `docker/apache/000-default.conf`（DocumentRootを`public/`に変更）
  - `public/.htaccess` + `public/api/index.php`（`/api/health` のみ実装した疎通確認用
    フロントコントローラ）
  - `README.md`（ローカル起動手順）
- リポジトリルートに `docker-compose.yml`（`php` + `mysql:8.4` の2サービス）を作成。
- `.gitignore` に `server/vendor/`, `server/.env`, `server/storage/tmp/*` を追加。
- ルート `README.md` のセットアップ手順・技術スタック欄を、Step 1完了後の状態（構築中）に
  合わせて更新。
- `docs/開発ステップ_Phase2.md` の Step 1 チェックリストを実施内容で更新
  （バージョン1.1→1.2）。Docker未検証である旨を明記。
- `server/docs/devlog/` `server/docs/adr/` を新設（開発標準ルール.md §2.1で定義済みの
  コンポーネント別devlog置き場所。server/ への初めてのコード投入に伴い作成）。
  Docker実行方式の選定理由（Apache+mod_php採用、拡張モジュールのインストール方法）を
  devlogに記録。

## 今後の進め方（未着手）

- ユーザーに `server/README.md` の手順でDocker環境を起動し、ヘルスチェックへの疎通を
  確認してもらう。
- 確認が取れ次第、Step 2（DBスキーマ実装）に進む。
