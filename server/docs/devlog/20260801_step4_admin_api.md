# 開発ログ: Step 4 — 管理コンソール API の実装

**日付**: 2026-08-01
**担当**: Claude Code
**関連機能**: 開発ステップ_Phase2.md Step 4、基本設計書_Phase2.md §3.2 §5.2 §6.1、FR-08

---

## 背景・課題

Step 3 で認証基盤（誰がログインできるか）を作ったが、その「許可リスト」は
`db/seed.php` 経由でしか触れず、ロール変更・無効化を行う手段が無かった。
また Step 3 では `security_stamp` の**検証側**（`SessionManager`）だけを実装しており、
再生成する側が存在しないため、NFR-S-10（無効化・降格の即時反映）が実際には成立して
いなかった。本ステップでその欠けを埋める。

## 実装の概要

```
server/src/
├── Admin/
│   └── UserAdminService.php      # ★ロール変更・有効化/無効化（最後の管理者保護）
├── Http/
│   ├── ApiException.php          # エラーコード + HTTPステータスを持つ例外
│   ├── AuthGuard.php             # requireUser / requireAdmin
│   ├── RequestBody.php           # JSONボディの読み取り・サイズ上限・型検証
│   ├── Pagination.php            # page/per_page（上限200）
│   └── Controller/AdminController.php
└── Repository/
    ├── StorageUsageRepository.php  # SUM(LENGTH(data)) による概算
    ├── UserRepository.php          # + paginate / countAll
    ├── AllowListRepository.php     # + list / add / remove
    └── AuditLogRepository.php      # + paginate / count / distinctActions
```

エンドポイントは基本設計書 §5.2 の表のとおり（`/api/admin/users`,
`/api/admin/users/{id}/role`, `/api/admin/users/{id}/status`, `/api/admin/allowed-domains`,
`/api/admin/allowed-emails`, `/api/admin/audit-logs`, `/api/admin/storage-usage`）。

`ApiException` を導入し、フロントコントローラで一括して `Response::error()` に変換する
ようにした。これによりコントローラのメソッドが「正常系だけを書き、異常系は投げる」形に
なり、エラーコードとHTTPステータスの対応が1か所に集まる。

## 設計判断

### 最後の管理者保護は「同時実行への防御」だと気づいた

設計書どおり `SELECT ... FOR UPDATE` + 同一トランザクションで実装したうえで、
「残る有効な管理者の確認」にも `FOR UPDATE` を付けた。2人の管理者が同時に互いを
降格しようとすると双方が相手の行のロックを待ち、MySQL がデッドロック（1213）または
ロック待ちタイムアウト（1205）で一方を中断する。これを 409 `concurrent_modification`
に変換して返す。

**実装後に気づいたこと**: この保護は逐次実行の経路では発火しない。管理APIは
実行者が有効な管理者であることを要求し（`requireAdmin`）、かつ自分自身は変更できない
（`cannot_modify_self`）ため、対象を除いても実行者自身が常に「残る有効な管理者」として
存在するからである。

つまり**この保護の実質的な価値は同時実行への防御と多層防御**にある。無効なチェックでは
ないが、「管理者が0人になるのを日常的に防いでいる」わけではないので、設計書側にも
その旨を追記した（v2.5）。ユニットテストでは、サービスを直接呼んで実行者が有効な管理者
でない状況を作り、保護そのものの動作を検証している。

### 自分自身の無効化も禁止した

設計書は「自分自身のロールを一般ユーザーに変更する操作は禁止する」としか書いていなかったが、
自己無効化も同じ「自分を締め出す」事故であり、しかも無効化された本人には元に戻す手段が無い
（管理APIは `status = 'active'` を要求する）。設計の意図に沿う拡張と判断して禁止し、
設計書にも追記した。

### `security_stamp` は昇格時にも再生成する

`SessionManager::currentUser()` は毎リクエストでDBからロールを読み直すため、
昇格（user → admin）だけなら stamp を回さなくても反映はされる。それでも回す方針にした。
「ロールが変わったら必ず stamp が変わる」という単純な規則のほうが、
将来セッションにロールをキャッシュする最適化を入れたときに壊れにくい。
代償は昇格されたユーザーが一度ログインし直す必要があること。実測で確認済み。

なお、値が実際に変わらない場合（同じロールを指定した等）は stamp を回さない。
無関係なログアウトを起こさないため。

### Content-Type の必須化（§6.1 の積み残し）

Step 3 では未実装だった「JSON API は `Content-Type: application/json` を必須とする」を
`CsrfGuard` に追加した。**ボディを伴うリクエストのみを対象**としている。HTMLフォームから
送信できるのは urlencoded / multipart / text-plain の3種類だけなので、JSONを必須にすると
フォーム経由の単純なCSRFは形式面でも成立しなくなる。一方 `DELETE` はボディを持たず
fetch も Content-Type を付けないため、対象にすると正常なリクエストを弾いてしまう。

### 許可リストの追加は `INSERT IGNORE`

「存在確認 → INSERT」にすると、その間に別の管理者が同じ値を追加する競合が入る。
UNIQUE 制約に任せ、影響行数0を「既に存在」として 409 に変換する形にした。

### テーブル名を文字列で組み立てる箇所

`AllowListRepository` は許可ドメインと許可アドレスでテーブル構造が同一なため、
テーブル名・列名をパラメータ化して共通実装にしている。これらは SQL に文字列として
埋め込まれるため、**呼び出し元の引数ではなくクラス内の private 定数からのみ**
組み立てるようにした（外部入力が到達しない構造にする）。

## 試行錯誤・ハマったこと

### 名前付きプレースホルダを2箇所に置けない

ユーザー検索で `WHERE email LIKE :keyword OR display_name LIKE :keyword` と書いたところ
`SQLSTATE[HY093]: Invalid parameter number` になった。`PDO::ATTR_EMULATE_PREPARES => false`
（ネイティブプリペアド）では、同じ名前のプレースホルダを複数箇所に置けない。
別名（`:keyword_email` / `:keyword_name`）にして同じ値を渡して解決。

### LEFT JOIN の NULL 行を1件と数えていた

ストレージ使用状況で、マップを1件も持たないユーザーの `active_map_count` が 1 になっていた。
`LEFT JOIN` でマップが無いユーザーの行は `m.*` がすべて NULL になり、
`CASE WHEN m.deleted_at IS NULL THEN 1 ELSE 0 END` がその NULL 行にも当たってしまうため
（`NULL IS NULL` は真）。`m.id IS NOT NULL` を併記して解決。

**この不具合はユニットテストをすり抜けており、HTTP経由のスモークテストで
`map_count: 0` と `active_map_count: 1` が並んでいるのを見て気づいた**。
テストの assertion が `map_count` と `approx_bytes` しか見ていなかったのが原因で、
テスト側にも `active_map_count` / `trashed_map_count` の検証を追加した。
集計クエリは「0件のとき」を必ずテストすべきという教訓。

## テスト

**PHPUnit 54テスト・133アサーションがすべてパス**（Step 3 の34件 + 本ステップの20件）。

- `UserAdminServiceTest`（11件）: 昇格・降格・stamp再生成・自己変更の拒否（ロール／状態）・
  最後の管理者の降格／無効化の拒否・無効化済み管理者を「残る管理者」に数えないこと・
  変化なしの操作を記録しないこと・監査ログの内容・存在しないユーザー・**並行降格**
- `AdminRepositoryTest`（9件）: 許可リストの冪等な追加と大文字小文字の正規化・削除・
  登録者の表示・LIKEワイルドカードのエスケープ・ページネーション・
  一覧に `security_stamp` を含めないこと・監査ログのフィルタと結合・ストレージ集計

並行テストは、別のDB接続で行ロックを保持した状態からもう一方の降格を試み、
409 になることと、最終的に有効な管理者が残っていることを確認している
（`innodb_lock_wait_timeout = 1` を使ってテストが待ち続けないようにした）。

### HTTP 実測

セッションを作って curl で確認した結果:

| 条件 | 結果 |
| --- | --- |
| 未ログイン | 401 `unauthenticated` |
| 一般ユーザー | 403 `forbidden` |
| CSRFトークン欠落 | 403 `invalid_csrf_token` |
| `Content-Type: application/x-www-form-urlencoded` | 415 `unsupported_media_type` |
| 不正なロール値 | 422 `invalid_role` |
| 自分自身のロール変更 | 403 `cannot_modify_self` |
| 存在しないユーザー | 404 `user_not_found` |
| 許可ドメインの重複追加 | 409 `already_exists` |
| 不正なドメイン／メール形式 | 422 |
| 検索語 `%` | 0件（ワイルドカードとして解釈されない） |
| ロール変更された利用者の既存セッション | 401（即座に失効） |

## 今後の課題・TODO

- 監査ログの `action` が、初回SSOログインでも `login_first_time` にならないことがある
  （`is_new` が「users 行を新規作成したか」を意味するため。Step 3 の devlog 参照）。
  identity 追加を別イベントとして記録するかを検討する。
- レート制限（§6.3）は未実装。`/api/admin/*` を含めて Step 10（セキュリティ強化）で対応する。
- 管理者による他ユーザーのマップ閲覧は Phase 2 のスコープ外（§2.1）。
  ストレージ使用状況で件数と概算容量のみを見せている。
