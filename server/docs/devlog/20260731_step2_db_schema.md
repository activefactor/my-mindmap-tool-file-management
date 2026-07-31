# 開発ログ: Step 2 — DBスキーマ実装（migrations・seed）

**日付**: 2026-07-31
**担当**: Claude Code
**関連機能**: 開発ステップ_Phase2.md Step 2、基本設計書_Phase2.md §5.1, §3.1

---

## 背景・課題

Step 1（Docker環境）の完了を受け、基本設計書_Phase2.md §5.1 のDBスキーマを実際のマイグレーション
ファイルとして実装した。マイグレーション管理の仕組み自体は基本設計書で確定していなかったため、
このステップで方式を決定する必要があった。

## 検討した選択肢

### マイグレーション管理方式

- 選択肢A: `doctrine/migrations` や `phinx` 等のライブラリを導入
- 選択肢B（採用）: 番号付きSQLファイル（`0001_xxx.sql`）+ 自前の小さなPHPランナー
  （`db/migrate.php`）。適用済みファイル名を `schema_migrations` テーブルで管理
  - 理由: 現時点でComposerの依存はゼロ（`composer.json` に `require` なし）。
    DBアクセスもPDO標準のみを使う設計方針（基本設計書_Phase2.md §9）と一貫させ、
    マイグレーション管理のためだけに新しい依存を増やさない判断をした。要件（順序どおり
    適用・再実行時にスキップ）を満たすには自前の数十行のスクリプトで十分だった

## 決定した内容とその理由

7つのテーブルを依存関係順（`users` → `user_identities`/`allowed_domains`/`allowed_emails`/
`folders` → `mindmaps` → `audit_logs`）にファイル分割した。`db/migrate.php` は
`schema_migrations` テーブルに適用済みファイル名を記録し、再実行時は未適用分のみ実行する。

## 実装の概要

```
server/
├── src/Support/
│   ├── Env.php        # .env の簡易パーサ（$_ENV/putenvへ反映）。既存のComposer依存を増やさない
│   └── Database.php   # PDO接続のシングルトン的ヘルパー
└── db/
    ├── migrations/
    │   ├── 0001_create_users.sql
    │   ├── 0002_create_user_identities.sql
    │   ├── 0003_create_allowed_domains.sql
    │   ├── 0004_create_allowed_emails.sql
    │   ├── 0005_create_folders.sql
    │   ├── 0006_create_mindmaps.sql
    │   └── 0007_create_audit_logs.sql
    ├── migrate.php     # マイグレーションランナ
    └── seed.php        # 初期管理者ブートストラップ
```

Dockerの `mysql`（8.4）コンテナに対して実際に `docker compose exec php php db/migrate.php` を
実行し、以下を検証した。

- 7テーブルすべてが作成されること
- 複合FK（`mindmaps(folder_id, user_id)` → `folders(id, user_id)`）が設計どおり機能すること:
  他ユーザー（Bob）が別ユーザー（Alice）のフォルダを指定してマップを作成しようとすると
  `ERROR 1452 (23000)` で拒否される。自分のフォルダ、および `folder_id = NULL`（ルート直下）
  は正常に作成できる
- `migrate.php` を複数回実行しても、2回目以降はすべて `skip` され冪等であること
- `db/seed.php` を複数回実行しても、`users`/`allowed_domains`/`allowed_emails` に重複が
  生じないこと

## 試行錯誤・ハマったこと

### DDLをトランザクションで囲うと `PDOException: There is no active transaction` が発生する

当初 `migrate.php` は各マイグレーションの実行を `beginTransaction()` / `commit()` /
`rollBack()` で囲っていたが、実際に実行すると
`Fatal error: Uncaught PDOException: There is no active transaction` で異常終了した。

原因: MySQLの `CREATE TABLE` 等のDDLは実行時に**暗黙のコミット**を引き起こす。PDO（特に
mysqlnd経由）はこれを検知して内部のトランザクション状態をリセットするため、その後の
`commit()`（またはエラー時の`rollBack()`）が「アクティブなトランザクションが無い」で
失敗する。1つ目のファイル（`0001_create_users.sql`）自体は実際にはCREATE TABLE・
`schema_migrations`へのINSERTともに成功していた（暗黙コミットのおかげで自動的に確定
していた）が、その後の明示的な`commit()`呼び出しが失敗し、catch節の`rollBack()`も
失敗して未捕捉例外でスクリプトが落ちる、という流れだった。

対応: DDL実行をトランザクションで囲うのをやめ、`exec()`と`schema_migrations`への
INSERTを素直に順番に実行する方式に変更した。MySQLではDDLをまたぐトランザクションの
原子性はそもそも保証されないため、無理にラップしないほうが実態に即している。

### 基本設計書のアカウント解決ロジックの欠陥

`db/seed.php` で「`user_identities` を持たない `users` 行」をあらかじめ作成する設計に
なっていることに気づき、基本設計書_Phase2.md §3.1 の解決ロジック（「既存のメールと一致
したら無条件でconflict」）のままだと、この初期管理者が実際にSSOでログインしようとした
瞬間に「別のログイン方法で登録されています」と拒否されてしまう欠陥があることに
気づいた。設計書側を修正し、「`user_identities` が1件も無い既存ユーザー」は
初回ログインとして扱い通常どおりidentityを追加する、という例外を明記した
（v2.2→v2.3、詳細は `docs/基本設計書_Phase2.md` 変更履歴参照）。

## 今後の課題・TODO

- 次はStep 3（認証基盤）。今回発見したアカウント解決ロジックの修正を実装に正しく反映する。
- `server/tests/` にマイグレーション・シードの自動テスト（本ログで手動確認した内容）を
  追加することを検討する（現時点ではDocker上での手動検証のみ）。
