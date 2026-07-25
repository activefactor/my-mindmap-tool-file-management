# マインドマップツール 基本設計書
## Phase 2 — レンタルサーバー（heteml）移行版

**バージョン**: 2.0
**作成日**: 2026-07-25
**更新日**: 2026-07-25
**対象フェーズ**: Phase 2（SSO認証・サーバー保存・ダッシュボード・フォルダ管理）
**対応要件**: 要件定義書.md v2.1 FR-07〜FR-14, NFR-H, NFR-Region, NFR-S-5〜10, NFR-A-4〜6, NFR-P-5

> v2.0 は、外部レビュー（`mindmap-tool/docs/基本設計書_Phase2_レビュー報告書_20260725.md`）の
> 指摘を受けて v1.0 を全面改訂したもの。実装開始前に必須と判断された修正はすべて反映済み。
> 未反映・保留とした項目は §12「未決定事項」に理由とともに明記する。

---

## 1. システム概要

### 1.1 目的

Phase 1 で確立したマインドマップ編集機能（FR-01〜FR-06）を維持したまま、レンタルサーバー
（heteml）上に認証・保存基盤を追加する。ユーザーは Google または Microsoft アカウントで
ログインし、マインドマップをサーバーに保存・フォルダ整理・ダウンロードできるようになる。

### 1.2 動作環境

| 項目 | 内容 |
|------|------|
| ホスティング | heteml（契約済み・SSH接続可・Composer利用可能な上位プラン） |
| フロントエンド | Vite でビルドした静的ファイル（React SPA、Phase 1 と同一コードベースを拡張） |
| バックエンド | PHP（heteml が提供する最新の安定バージョンに固定する。本書作成時点では PHP 8.3 以降を
  想定するが、構築フェーズ開始時に heteml 管理画面で実際に選択可能なバージョンを確認し、
  具体的なバージョンを `composer.json` の `"php"` 制約と `.htaccess`/コントロールパネル設定で
  固定する。「8.x」という広い範囲のまま運用しない） |
| データベース | MySQL（heteml提供。バージョン・文字コード・照合順序は §5.1 参照） |
| Webサーバー | Apache（heteml標準、`.htaccess` による mod_rewrite が前提） |
| 認証 | Google OAuth 2.0 / OIDC、Microsoft OAuth 2.0 / OIDC（`common` エンドポイント） |
| 対応ブラウザ | Chrome / Edge / Firefox / Safari 最新版（Phase 1 と同様） |

### 1.3 全体構成（フロント／バックエンドの配置）

> **v1.0 からの変更**: v1.0 では Web 公開領域を `public_html/` としていたが、heteml では
> ドメインごとに `web/<ドメイン名>/` を公開フォルダとして割り当てる構成が一般的である
> （参考: heteml FTP・ディレクトリ構造 サポートページ）。**実際の公開フォルダ名は、構築フェーズ
> 開始時に heteml 管理画面で対象ドメインの設定を確認して確定する**（§12 未決定事項 No.4）。
> 以下は現時点で判明している情報を基にした暫定構成であり、確定ではない。

```
[ホームディレクトリ]（Web非公開）
├── mindmap-app/                # PHPアプリ本体（本リポジトリの server/ を deploy）
│   ├── vendor/                 # Composer 依存
│   ├── src/
│   ├── storage/tmp/            # Zip生成等の一時ファイル（ユーザーごとのサブディレクトリに分離）
│   ├── .env                    # 本番用機密情報（Gitにコミットしない）
│   └── db/migrations/
│
[web/<対象ドメイン>/]（Web公開。実際のパスは heteml 管理画面で確認して確定する）
├── index.html, assets/         # フロントエンド ビルド成果物（dist/ をそのまま配置）
├── api/
│   └── index.php               # フロントコントローラ（`realpath()` で解決した絶対パスから
│                                # ../../mindmap-app/src を require し、解決先が非公開ディレクトリ
│                                # 配下であることを起動時にアサートする）
└── .htaccess                   # /api/* は api/index.php へ、それ以外は SPA フォールバック
```

- `.env` や `vendor/` を公開領域の外に置くことで、設定ミスでソースや機密情報が
  直接ブラウザから閲覧される事故を防ぐ（NFR-S-5）。
- デプロイスクリプト（構築フェーズで作成）は、公開フォルダに `.env` / `vendor/` /
  `storage/` が含まれていないことを確認するチェックを含める。
- フロントエンドとAPIを同一オリジン（同一ドメイン）で配信することで、CORS設定の問題を避ける。
  ただし Cookie の `SameSite` 属性は `Strict` ではなく `Lax` を使用する（理由は 3.1 参照）。

---

## 2. 機能一覧（Phase 2 スコープ）

| # | 機能カテゴリ | 機能名 | 対応要件 |
|---|-------------|--------|---------|
| 1 | 認証 | Google SSO ログイン | FR-07-1 |
| 2 | 認証 | Microsoft SSO ログイン（職場/学校＋個人） | FR-07-2 |
| 3 | 認証 | 許可ドメイン／許可アドレスによるアクセス制御 | FR-07-3 |
| 4 | 認証 | セッション管理・自動ログアウト | FR-07-4 |
| 5 | 認証 | ログイン後ダッシュボード遷移 | FR-07-5 |
| 6 | 認証 | ログアウト | FR-07-6 |
| 7 | 管理コンソール | ユーザー一覧・ロール管理 | FR-08-1, 08-2 |
| 8 | 管理コンソール | 許可ドメイン管理 | FR-08-3 |
| 9 | 管理コンソール | 許可アドレス管理 | FR-08-4 |
| 10 | 管理コンソール | ユーザー無効化／再有効化 | FR-08-5 |
| 11 | 管理コンソール | 監査ログ閲覧 | FR-08-6 |
| 12 | 管理コンソール | ストレージ使用状況閲覧 | FR-08-7 |
| 13 | ダッシュボード | マップ一覧表示 | FR-09-1 |
| 14 | ダッシュボード | マップを開く | FR-09-2 |
| 15 | ダッシュボード | 一覧に戻る導線 | FR-09-3 |
| 16 | フォルダ | 作成・名称変更・削除 | FR-10-1, 10-3 |
| 17 | フォルダ | ドラッグ＆ドロップでの移動 | FR-10-2 |
| 18 | ダウンロード | ファイル個別ダウンロード | FR-11-1 |
| 19 | ダウンロード | フォルダZipダウンロード | FR-11-2 |
| 20 | ダウンロード | Zip自動削除 | FR-11-3 |
| 21 | 保存 | オートセーブ（サーバー／ローカル選択） | FR-12-1, 12-2 |
| 22 | 保存 | 既存の手動保存機能維持 | FR-12-3 |
| 23 | オープン | サーバーマップはダッシュボード経由 | FR-13-1 |
| 24 | オープン | ローカルファイルは新規マップとして開く | FR-13-2 |
| 25 | 削除 | マップのソフトデリート・ゴミ箱・復元・完全削除 | FR-14-1〜14-5 |

### 2.1 スコープ外（Phase 2）

- リアルタイム共同編集（Phase 4）
- フォルダのネスト（2階層目以降）
- マップの共有URL発行・閲覧専用リンク（Phase 2 要件には含まれないため対象外。将来検討）
- 管理者による他ユーザーのマップ内容の閲覧・編集（管理コンソールはアカウント／許可リスト管理のみが対象）
- 同一ユーザーが複数の SSO プロバイダで作成したアカウントの明示的な統合（アカウント連携）UI
  （3.1 の理由により、Phase 2 では同一メールでも別プロバイダの初回ログインは別アカウントとして
  扱う。連携 UI は将来検討）

---

## 3. 機能詳細

### 3.1 認証・SSO（FR-07）

#### ログインフロー（概要）

```
1. ユーザーがログイン画面で「Googleでログイン」または「Microsoftでログイン」を選択
2. フロントエンドが /api/auth/{provider}/redirect へ遷移
3. サーバーが以下をセッションに保存し、Google/Microsoft の認可エンドポイントへリダイレクトする
   - state（暗号論的に安全な乱数、一回限り使用、有効期限 10 分、provider と紐付け）
   - PKCE の code_verifier（S256）
   - nonce（ID トークンのリプレイ防止）
4. ユーザーがプロバイダ側で認証・同意
5. プロバイダが /api/auth/{provider}/callback?code=...&state=... へリダイレクト
6. サーバーが state を検証（一致・未使用・期限内・provider一致）し、直後に state を消費済みとする
7. code を PKCE の code_verifier とともにアクセストークン・IDトークンに交換
8. ID トークンを検証する（詳細は下記「IDトークン検証」）
9. 検証済みの ID トークン・UserInfo からメールアドレス・氏名・sub（Microsoftは oid）・
   tid（Microsoftのみ）を取得
10. 許可判定（下記「許可判定ロジック」）
    - 許可 → アカウント解決（下記「アカウントとプロバイダの紐付け」）
              → users.status を確認（'active' でなければ拒否・監査ログに login_denied_disabled）
              → session_regenerate_id(true) 実施
              → セッションに user_id・role・security_stamp を保存
              → 新しい CSRF トークンを発行
              → /dashboard へリダイレクト
    - 不許可 → セッションを発行せず、ログイン画面にエラーメッセージ付きでリダイレクト
               （監査ログに login_denied を記録。個人情報はメールドメインまで、詳細な理由は
               記録するがトークン類は記録しない）
```

#### Cookie・CSRF 設計（重大な修正: `SameSite=Strict` → `Lax`）

v1.0 ではセッション Cookie に `SameSite=Strict` を設定していたが、これは Google/Microsoft
からアプリへ戻る OAuth コールバック（クロスサイトのトップレベル遷移）でセッション Cookie が
送信されず、認証開始時に保存した `state` を復元できない、というログイン不能の不具合を
引き起こす。したがって以下のとおり修正する。

- セッション Cookie: `HttpOnly`, `Secure`, `Path=/`, `SameSite=Lax`。可能であれば
  `__Host-` 接頭辞を付与する（NFR-S-6）。
- `SameSite=Lax` のみに CSRF 対策を依存しない。状態を変更する全 API（`POST`/`PUT`/`DELETE`）は
  セッションに紐付く CSRF トークンを `X-CSRF-Token` ヘッダーで検証する（NFR-S-9。詳細は §6.1）。

#### IDトークン検証（v1.0 では未確定だった項目を確定）

OpenID Connect の Discovery Document・JWKS を用いて、以下をすべて検証する。

- 署名検証: Discovery Document から取得した JWKS を用いて署名を検証する。許可する署名
  アルゴリズムを `RS256` 等の固定リストに限定する（`alg=none` を拒否）。`kid` により鍵を選択し、
  JWKS のキャッシュ・ローテーションに対応する。
- `iss`（発行者）: Google は固定値、Microsoft（`common`）はテナントごとに異なるため、
  `https://login.microsoftonline.com/{tid}/v2.0` の形式であることをパターンで検証し、
  ID トークン内の `tid` クレームと `iss` のテナント部分が一致することを確認する。
- `aud` / `azp`: 自アプリのクライアントIDと一致することを確認する。
- `exp` / `iat`: 有効期限内であること、発行時刻が妥当な範囲であることを確認する。
- `nonce`: 認証開始時にセッションへ保存した値と一致することを確認する。
- UserInfo エンドポイントから取得した `sub` と ID トークンの `sub` が一致することを確認する。
- 認可コード・アクセストークン・ID トークンはログ・監査ログへ出力しない（§6.5）。

#### 許可判定ロジック（FR-07-3）

```
email のドメイン部分を抽出（例: user@company.co.jp → company.co.jp）
IF ドメインが allowed_domains に存在 OR email（大文字小文字を無視して比較）が
   allowed_emails に存在
THEN 許可
ELSE 拒否
```

- ドメイン・メールアドレスの比較は小文字化してから行う（大文字小文字ゆらぎ対策）。
- Google の場合、許可ドメイン判定に加えて ID トークンの `email_verified=true` を必須とする。
  Google Workspace（組織）アカウントであれば `hd` クレームが許可ドメインと一致することを
  追加チェックとして行う（`hd` が存在しないコンシューマ Google アカウントは許可アドレス側の
  個別許可でのみ利用可能とする）。
- Microsoft の場合も UserInfo/ID トークンの `email` または `preferred_username` の
  `email_verified` 相当の検証可否をプロバイダ仕様に応じて確認する（構築フェーズで詳細確認）。
- 初回デプロイ時、許可リストが空の状態ではどのユーザーもログインできない「鶏と卵」問題が
  発生するため、デプロイ時に `.env` の `INITIAL_ADMIN_EMAIL` / `INITIAL_ALLOWED_DOMAIN` を
  シードスクリプト（`db/seed.php`）で `allowed_emails` / `allowed_domains` と `users`
  （role=admin）に投入する（10章 環境変数設計を参照）。

#### アカウントとプロバイダの紐付け（重大な修正: メールによる自動統合を廃止）

v1.0 では「同一メールアドレスなら Google/Microsoft のログインを自動的に同一アカウントへ統合する」
設計としていたが、メールアドレスは変更・再利用され得るため、恒久的な同一性の根拠にはできない
（例: 退職者のメールアドレスが新しい社員に再割当てされた場合、新しい社員のログインが
退職者の既存アカウント・既存マップへ自動的に紐付いてしまう）。そのため以下のとおり改める。

- Identity の一意キーは **`provider` + `provider_user_id`（= 検証済み ID トークンの `sub`、
  Microsoft は `oid` を優先）** とし、メールアドレスは表示・許可判定用の属性として扱う。
  `users.email` は一意制約を維持する（1メール = 1アカウント）。
- ログイン時の解決ロジック:
  1. `user_identities` を `(provider, provider_user_id)` で検索し、一致すればそのユーザーとして
     ログインする。
  2. 一致しない場合、許可判定を通過していれば `users.email` を検索する。
     - 一致するユーザーが **存在しない** 場合: 新規に `users` と `user_identities` を作成する
       （初回ログイン＝新規登録）。
     - 一致するユーザーが **既に存在する**（＝そのメールは別プロバイダの identity で
       登録済み）場合: **自動では統合しない**。ログインを拒否し、「このメールアドレスは
       別のログイン方法で登録されています。そちらでログインするか、ログイン後の設定画面から
       アカウント連携を行ってください」という趣旨のエラーを表示する（監査ログに
       `login_denied_conflict` を記録）。
- 明示的な「アカウント連携」（既にログイン中のセッションから追加のプロバイダを認証し、
  同一 `user_id` に `user_identities` を追加する機能）は Phase 2 の実装スコープには含めない
  （2.1 スコープ外）。UI・API は用意せず、必要であれば管理者が個別に対応する運用とする。

#### セッション管理（FR-07-4, NFR-S-10）

- PHP 標準セッション機構を使用（heteml は単一サーバー構成のため、ファイルベースセッションで
  十分。将来的な水平スケールが必要になった場合に DB セッションへの切替を検討）。
- セッションCookie属性: `HttpOnly`, `Secure`, `Path=/`, `SameSite=Lax`（上記参照）。
- アイドルタイムアウト: 最終アクセスから一定時間（既定 60 分、`.env` で設定可能）操作がない
  場合はセッションを破棄し、次回アクセス時にログイン画面へ誘導する。
- **既存セッションの即時失効**: `users` テーブルに `security_stamp`（ランダム文字列）を持たせ、
  ログイン時にセッションへ保存する。認証を要する全リクエストで、セッションの
  `security_stamp` と DB の現在値が一致すること、および `users.status = 'active'` であることを
  ミドルウェアで確認する。ロール変更・無効化・再有効化のいずれの操作でも `security_stamp` を
  再生成する。これにより、無効化・降格されたユーザーの既存セッションは次のリクエストで
  即座に失効する（NFR-S-10）。

### 3.2 管理コンソール（FR-08）

管理者ロール（`users.role = 'admin'`）のユーザーのみアクセス可能な `/admin` 画面。
一般ユーザーがアクセスした場合は 403 を返しダッシュボードへリダイレクトする。

| 画面セクション | 機能 |
|--------------|------|
| ユーザー管理 | 一覧表示（メール・氏名・ロール・ステータス・最終ログイン）、ロール変更、無効化／再有効化 |
| 許可ドメイン管理 | 一覧・追加・削除 |
| 許可アドレス管理 | 一覧・追加・削除 |
| 監査ログ | ログイン・ログイン拒否・ロール変更・許可リスト変更・ユーザー無効化・削除操作などの一覧（日時・実行者・対象・内容、ページネーション付き。§6.7 参照） |
| ストレージ使用状況 | ユーザーごとのマップ件数・保存データ量の概算（`mindmaps.data` の合計バイト数を集計。専用の集計テーブルは持たず都度 `SUM(LENGTH(data))` で算出する。画面上は「概算容量（JSONデータ量ベース、実ディスク使用量とは異なる）」と明示する） |

- 自分自身のロールを一般ユーザーに変更する操作は禁止する。
- **最後の管理者保護（重大な修正: 競合条件対策）**: 管理者を無効化・降格する操作は、
  対象ユーザー行を `SELECT ... FOR UPDATE` で排他ロックしたうえで、同一トランザクション内で
  「更新後に有効な管理者が1人以上残るか」を再確認してから `UPDATE` する。事前チェックと
  更新を別トランザクションにしない（複数の管理者が同時に別の管理者を降格し、管理者が
  ゼロになる事故を防ぐ）。
- ロール変更・無効化操作は、対象の `security_stamp` 再生成・監査ログ記録（変更前後の値を含む）
  を同一トランザクションで行う。

### 3.3 ダッシュボード（FR-09）

- ログイン成功後、または編集画面から「一覧に戻る」を選択した際に表示する画面。
- マインドマップの一覧（ファイル名・最終更新日時・所属フォルダ）とフォルダの一覧を表示する。
- 一覧の項目をクリックするとそのマップをサーバーから読み込み、編集画面
  （`binding: server(id, revision)`。3.6/3.7 参照）を開く。
- 編集画面のツールバーに「一覧に戻る」ボタンを追加する（FR-09-3）。**「一覧に戻る」操作は、
  未送信のオートセーブ内容がある場合はそれを送信・確定させてから遷移する**（3.6 の
  「画面遷移時の flush」を参照。v1.0 の「保存待ちは不要」という記述は撤回する）。
- ゴミ箱（FR-14）への導線をダッシュボードのメニューに追加する。

### 3.4 フォルダ管理（FR-10）

- フォルダは Phase 2 では 1 階層のみ（`folders` テーブルに親フォルダ列を持たない）。
- ダッシュボード上でマインドマップのアイテムをドラッグし、フォルダのアイテムにドロップすると
  そのフォルダに移動する（`mindmaps.folder_id` を更新）。フォルダ外（ルート）へ戻すための
  「ルートに戻す」ドロップ領域も用意する。
- **フォルダ移動時の所有者検証（重大な修正）**: マップの移動先フォルダについても、現在の
  ユーザーが所有するフォルダであることをアプリケーション層で検証してから更新する
  （マップの所有者チェックだけでは、他人の `folder_id` を指定されても検出できないため）。
  加えて DB レベルでも複合外部キーにより保証する（5.1 参照）。
- フォルダ名の変更・削除が可能。フォルダを削除する際、フォルダ内にマップが存在する場合は
  確認ダイアログを表示し、マップ自体は削除せずルート直下（`folder_id = NULL`）に戻す
  （データ消失を避けるための挙動。Phase 1 の「削除前に確認」という考え方を踏襲）。

### 3.5 ダウンロード（FR-11）

#### ファイル個別ダウンロード（FR-11-1）

- `GET /api/mindmaps/{id}/download` で、Phase 1 と同じ JSON スキーマ（`MindMapFile`）の
  ファイルとしてダウンロードする。ファイル名は `mindmaps.title` を基に生成し
  （制御文字・パス区切り文字等ファイルシステム上の禁止文字は除去・置換する）、
  `<サニタイズ済みtitle>_YYYYMMDD_HHMM.json` とする。

#### フォルダZipダウンロード（FR-11-2）とZip自動削除（FR-11-3）

```
1. GET /api/folders/{id}/download を呼び出す
2. 事前チェック: フォルダ内マップ件数・合計データ量が上限（§6.2）以内であることを確認する。
   超過する場合は 413 相当のエラーを返す
3. ユーザーごとの同時Zip生成数・アプリ全体の同時生成数が上限を超えていないか確認する
4. サーバーは対象フォルダ内の全マップを JSON ファイルとして一時ディレクトリに書き出し、
   ZipArchive で 1つの一時Zipファイルに圧縮する
   - 一時ディレクトリは Web 非公開領域内の、ユーザーごとに分離したサブディレクトリ
     （例: mindmap-app/storage/tmp/{user_id}/）に置く
   - ファイル名は推測困難なランダム値（例: zip_{random_bytes を hex 化した値}.zip）とする
5. Content-Disposition: attachment ヘッダーを付与し、readfile() でレスポンスとしてストリーミング
6. レスポンス送信完了直後、同一リクエスト内で unlink() により一時Zipファイルを削除する。
   例外発生時・異常終了時にも確実に削除されるよう、`finally` ブロックおよび
   `register_shutdown_function()` によるクリーンアップを併用する
7. 【保険】cron（heteml のスケジュールタスク機能を利用）で1時間ごとに
   storage/tmp/ 配下の一定時間（例: 30分）以上前に作成された残留ファイルを削除する
   クリーンアップジョブを実行する（NFR-A-5）。
   heteml では高負荷時に cron が実行されない可能性があるため、cron のみを正常系の削除手段と
   しない（手順6の即時削除を主、cron はあくまで保険とする）
```

### 3.6 保存方式（オートセーブ・FR-12）

- 編集画面のツールバーに保存先トグル（「サーバーに保存」／「ローカルに保存」）を追加する。
  これは 3.7 で定義する `autosaveTarget`（オートセーブの送信先）の切り替えであり、
  「今どのマップを編集しているか」を表す `binding` とは独立した状態である（3.7 参照）。
- **サーバー保存モード（`autosaveTarget: server`）**:
  - ノード操作のたびにデバウンス（500ms）を挟んで `PUT /api/mindmaps/{id}` を呼び出す。
    初回（未保存の新規マップ）は `POST /api/mindmaps` で作成し、以後は返却された `id` を
    使って更新する。
  - **リクエストの直列化**: 保存リクエストは常に1件のみ in-flight とする（送信中に新しい
    変更が発生した場合はキューに積み、送信完了後に最新の内容で次の保存を行う）。複数の
    PUT が並行して送信され、古いリクエストが後から完了して新しい内容を上書きする事態を防ぐ。
  - **revision による楽観チェック**: `mindmaps.revision` を保存のたびにインクリメントする。
    クライアントは直前に受け取った `revision` を送信し、サーバー側の現在値と異なる場合は
    `409 Conflict` を返す（Phase 2 は単一ユーザー・単一アカウントの利用が前提のため、
    複数タブ・複数デバイスからの同時編集による衝突検知が主目的であり、自動マージは行わない。
    409 を受け取ったクライアントはユーザーに「他のタブ/デバイスでの変更を検知しました」等を
    表示し、再読み込みを促す）。
  - **画面遷移時の flush**: 「一覧に戻る」等の画面遷移操作は、保留中のデバウンスタイマーを
    即座に確定させ（flush）、対応する保存リクエストの完了（または失敗）を待ってから遷移する。
    保存中は「保存中…」を表示し、遷移をブロックする。
  - 保存失敗時はトースト通知を表示し、直近の内容を IndexedDB のフォールバック領域
    （下記参照）に保持する（NFR-A-4）。
  - **ローカルフォールバックのユーザー分離**: サーバー保存失敗時のフォールバックは
    IndexedDB を使用し、キーを `${user_id}_${mindmap_id}` とすることでユーザーごとに分離する。
    共有端末で別ユーザーがログインした際に前ユーザーのフォールバックデータを復元候補として
    表示しないようにする。認証トークンはいかなる場合も localStorage / IndexedDB に保存しない
    （PHP セッション Cookie のみを使用する設計のため、そもそもクライアント側に保持すべき
    トークンは存在しない）。
- **ローカル保存モード（`autosaveTarget: local`）**: Phase 1 と同じ挙動（ブラウザの
  localStorage への自動保存、単一キー）。サーバーへは一切送信しない。この単一キーの
  ローカル保存は、認証状態に関わらず利用できる Phase 1 由来の機能であり、上記の
  「ユーザーごとに分離したフォールバック」とは別物として扱う。
- 既存の「保存」ボタン（JSON／テキストの手動ダウンロード）は保存先モードに関わらず維持する
  （FR-12-3）。

### 3.7 「開く」動作と状態管理（FR-13）

編集画面は内部的に、互いに独立した2つの状態を持つ（v1.0 では1つの状態に混在させていたが、
「サーバー保存中のマップをローカル保存モードに切り替える」操作と「別のローカルファイルを
開く」操作の扱いが曖昧になっていたため分離する）。

```
binding（今どのマップを編集しているか）:
  - server(mindmapId, revision) : サーバー上の特定マップに紐付いている
  - local                        : サーバーとは無関係の編集セッション（新規 or ローカルファイル由来）

autosaveTarget（オートセーブの送信先。3.6 のトグル）:
  - server
  - local
```

- ダッシュボードの一覧からマップを開く（FR-09-2） → `binding = server(id, revision)`、
  `autosaveTarget = server`。
- ツールバーの「開く」でローカルファイル（JSON／テキスト）を読み込む → **`binding` を必ず
  `local`（新規セッション）に切り替える**。現在 `server` 状態で開いていたマップの
  `mindmapId` は破棄され、ローカルファイルの内容を保持する新しい編集セッションとして扱う。
  これにより、ローカルファイルの読み込みによってサーバー上のマップが意図せず上書きされることを
  防ぐ（FR-13-2）。
- `binding = server` のまま `autosaveTarget` を `local` に切り替えた場合（＝一時的にサーバーへの
  自動保存を止めたいだけの操作）は、`mindmapId` を破棄しない（サーバー同期の一時停止であり、
  ローカルファイルを開く操作とは異なる）。画面上には「このマップのサーバーへの自動保存は
  一時停止中です」等を表示し、既存のサーバー保存コピーが削除されるわけではないことを示す。
  `autosaveTarget` を再び `server` に戻す際は、保存前に現在の `revision` を確認し、
  サーバー側で更新が進んでいた場合はユーザーに選択させる（上書き確認、または再読み込み）。
- `binding = local` の状態から `autosaveTarget` を `server` に切り替えた場合は、新規マップとして
  `POST /api/mindmaps` が呼ばれ、新しい `id` が採番される（既存のどのサーバーマップへの上書きも
  発生しない）。

---

## 4. 画面設計

### 4.1 画面遷移

```
[ログイン画面] --SSO成功--> [ダッシュボード] --マップ選択--> [マインドマップ編集画面]
                                  |  ^                              |
                                  |  └────────「一覧に戻る」─────────┘
                                  |
                                  ├--(管理者のみ)--> [管理コンソール]
                                  └--------------> [ゴミ箱]
```

### 4.2 ログイン画面

```
┌──────────────────────────────┐
│      マインドマップツール       │
│                              │
│   [ G Googleでログイン ]      │
│   [ ⊞ Microsoftでログイン ]   │
│                              │
│  (許可されていない場合はエラー表示) │
└──────────────────────────────┘
```

### 4.3 ダッシュボード画面

```
┌────────────────────────────────────────────────────┐
│ マインドマップツール      [ユーザー名 ▼](設定/管理/ゴミ箱/ログアウト) │
├────────────────────────────────────────────────────┤
│ [＋新規フォルダ]                         [検索］      │
│                                                      │
│ 📁 プロジェクトA          📁 プロジェクトB             │
│                                                      │
│ 📄 議事録_0701.json   更新: 2026-07-01  [開く][DL]    │
│ 📄 企画メモ.json       更新: 2026-06-28  [開く][DL]    │
│  （ドラッグしてフォルダへ移動可能）                       │
└────────────────────────────────────────────────────┘
```

- フォルダ行の右クリック／メニューから「Zipダウンロード」「名称変更」「削除」を選択できる。
- ファイル行の右クリック／メニューから「開く」「ダウンロード」「フォルダへ移動」「削除
  （ゴミ箱へ移動）」を選択できる。

### 4.4 ゴミ箱画面（FR-14）

```
┌────────────────────────────────────────────────────┐
│ ゴミ箱                                  [一覧に戻る]  │
├────────────────────────────────────────────────────┤
│ 📄 議事録_0620.json   削除日: 2026-07-20  [復元][完全削除] │
│  （削除日から30日後に自動的に完全削除されます）             │
└────────────────────────────────────────────────────┘
```

### 4.5 管理コンソール画面

```
┌────────────────────────────────────────────────────┐
│ 管理コンソール          [タブ] ユーザー | 許可リスト | 監査ログ │
├────────────────────────────────────────────────────┤
│ メール              氏名     ロール    状態    最終ログイン │
│ user@company.co.jp  山田太郎  一般 ▼   有効 ▼   07/20 10:00 │
│ ...                                                  │
└────────────────────────────────────────────────────┘
```

### 4.6 マインドマップ編集画面の変更点

Phase 1 のツールバーに以下を追加する。

| 追加ボタン／表示 | 内容 |
|----------------|------|
| 「一覧に戻る」 | 未送信の変更を確定してからダッシュボードへ遷移（FR-09-3, 3.6） |
| 保存先トグル | 「サーバーに保存」／「ローカルに保存」の切り替え（`autosaveTarget`。FR-12-2） |
| 保存状態インジケータ | 「保存済み」「保存中…」「保存失敗」「他の変更を検知（要再読み込み）」を表示 |

既存の 新規／開く／保存／エクスポート／Undo/Redo／全体表示 の各ボタンはそのまま維持する。

---

## 5. データ設計

### 5.1 DBスキーマ（MySQL）

共通方針: 全テーブル `ENGINE=InnoDB`、文字コード `utf8mb4`（照合順序 `utf8mb4_0900_ai_ci` を
基本とし、実際の MySQL バージョンに応じて構築フェーズで確定する）。日時は全て UTC で保存し、
表示時にクライアント側でタイムゾーン変換する。

```sql
CREATE TABLE users (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(255) NOT NULL UNIQUE,
  display_name    VARCHAR(255) NOT NULL,
  role            ENUM('user','admin') NOT NULL DEFAULT 'user',
  status          ENUM('active','disabled') NOT NULL DEFAULT 'active',
  security_stamp  CHAR(32) NOT NULL,      -- ロール変更・無効化のたびに再生成し、既存セッションを失効させる
  last_login_at   DATETIME NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_identities (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           BIGINT UNSIGNED NOT NULL,
  provider          ENUM('google','microsoft') NOT NULL,
  provider_user_id  VARCHAR(255) NOT NULL,   -- 検証済みID トークンの sub（Microsoftは oid 優先）
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_provider_identity (provider, provider_user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE allowed_domains (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  domain      VARCHAR(255) NOT NULL UNIQUE,
  created_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE allowed_emails (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email       VARCHAR(255) NOT NULL UNIQUE,
  created_by  BIGINT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE folders (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(255) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uq_folders_id_user (id, user_id)   -- mindmaps 側の複合FKで所有者一致を保証するために必要
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE mindmaps (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  folder_id   BIGINT UNSIGNED NULL,
  title       VARCHAR(255) NOT NULL DEFAULT '無題のマインドマップ',  -- ファイル名・一覧表示の正本。root.text とは独立して変更可能
  data        JSON NOT NULL,          -- Phase 1 の MindMapFile スキーマをそのまま保存
  revision    INT UNSIGNED NOT NULL DEFAULT 1,  -- 更新のたびにインクリメント。楽観チェックに使用
  deleted_at  DATETIME NULL,          -- ソフトデリート（FR-14）。NULL = 通常、NOT NULL = ゴミ箱
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (folder_id, user_id) REFERENCES folders(id, user_id),  -- folder_id が NULL の行は複合FK評価対象外（ルート直下）
  KEY idx_mindmaps_list (user_id, folder_id, updated_at),
  KEY idx_mindmaps_trash (user_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_logs (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_user_id BIGINT UNSIGNED NULL,
  action        VARCHAR(64) NOT NULL,   -- 例: login, login_denied, login_denied_conflict, role_change, domain_add, mindmap_delete, mindmap_restore ...
  target        VARCHAR(255) NULL,
  detail        JSON NULL,              -- ロール変更等は変更前後の値を含める。トークン・マップ本文は含めない
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_audit_logs_created (created_at, id)   -- ページネーション用
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

補足:

- `mindmaps.data` は MySQL の `JSON` 型を使用する（heteml の MySQL バージョンが JSON型未対応
  だった場合は `LONGTEXT` にフォールバックする。実際のバージョンは構築フェーズで確認する）。
- 所有者チェック（NFR-S-7）は全ての `mindmaps` / `folders` の取得・更新・削除処理で
  `WHERE user_id = :current_user_id` を必須条件とすることで実現する。他ユーザーの ID を
  指定された場合は、存在の有無に関わらず `404 Not Found` を返す（`403` は対象の存在を
  示唆してしまうため使用しない）。
- **マップタイトルの正本**: `mindmaps.title` を表示・ファイル名の正本とする。JSON内部の
  `root.text`（ルートノードのテキスト）とは独立しており、両者を同期する処理は行わない
  （ユーザーがルートノードの文言を変えても、ファイル名として使う `title` は自動追随しない）。
- **作成日時・更新日時の正本**: サーバー保存されたマップは DB の `created_at`/`updated_at`
  を正本とする。クライアントが送信した日時をそのまま保存・信用しない。JSON としてダウンロード
  する際は、DB の値を `MindMapFile.createdAt`/`updatedAt` に変換して埋め込む。
- 通常の一覧・取得系クエリは `WHERE deleted_at IS NULL` を必須条件とする。ゴミ箱一覧は
  `WHERE deleted_at IS NOT NULL` を用いる。

### 5.2 API エンドポイント一覧

| メソッド | パス | 内容 | 権限 |
|---------|------|------|------|
| GET | /api/auth/{provider}/redirect | OAuth認可エンドポイントへリダイレクト | 未ログイン |
| GET | /api/auth/{provider}/callback | OAuthコールバック処理 | 未ログイン |
| POST | /api/auth/logout | ログアウト | ログイン済み |
| GET | /api/auth/me | ログイン中ユーザー情報・CSRFトークン取得 | ログイン済み |
| GET | /api/mindmaps | マップ一覧取得（`?folder_id=`、既定で `deleted_at IS NULL`） | 所有者 |
| POST | /api/mindmaps | マップ新規作成（オートセーブ初回） | 所有者 |
| GET | /api/mindmaps/{id} | マップ取得 | 所有者 |
| PUT | /api/mindmaps/{id} | マップ更新（オートセーブ。`revision` 必須、不一致は409） | 所有者 |
| DELETE | /api/mindmaps/{id} | ソフトデリート（`deleted_at` を設定。ゴミ箱へ） | 所有者 |
| PUT | /api/mindmaps/{id}/move | フォルダ移動（移動先フォルダの所有者検証を含む） | 所有者 |
| GET | /api/mindmaps/{id}/download | JSONダウンロード | 所有者 |
| GET | /api/mindmaps/trash | ゴミ箱一覧取得（`deleted_at IS NOT NULL`） | 所有者 |
| POST | /api/mindmaps/{id}/restore | ゴミ箱から復元（`deleted_at` を NULL に戻す） | 所有者 |
| DELETE | /api/mindmaps/{id}/purge | 完全削除（物理削除） | 所有者 |
| GET | /api/folders | フォルダ一覧取得 | 所有者 |
| POST | /api/folders | フォルダ作成 | 所有者 |
| PUT | /api/folders/{id} | フォルダ名変更 | 所有者 |
| DELETE | /api/folders/{id} | フォルダ削除（内包マップはルート直下へ） | 所有者 |
| GET | /api/folders/{id}/download | Zipダウンロード | 所有者 |
| GET | /api/admin/users | ユーザー一覧（ページネーション） | 管理者 |
| PUT | /api/admin/users/{id}/role | ロール変更（security_stamp再生成・監査ログ記録） | 管理者 |
| PUT | /api/admin/users/{id}/status | 有効化／無効化（同上） | 管理者 |
| GET・POST・DELETE | /api/admin/allowed-domains(/{id}) | 許可ドメイン管理 | 管理者 |
| GET・POST・DELETE | /api/admin/allowed-emails(/{id}) | 許可アドレス管理 | 管理者 |
| GET | /api/admin/audit-logs | 監査ログ一覧（ページネーション・期間/種別フィルタ） | 管理者 |
| GET | /api/admin/storage-usage | ストレージ使用状況（概算） | 管理者 |

- すべての `所有者` 権限のエンドポイントは、セッションの `user_id` と対象レコードの
  `user_id` の一致を確認する。`管理者` 権限のエンドポイントは `role = 'admin'` と
  `status = 'active'` を確認する。
- `GET` 以外の全エンドポイントは CSRF トークン検証（§6.1）とリクエストサイズ上限
  （§6.2）の対象とする。
- `/api/mindmaps` `/api/folders` `/api/admin/*` の一覧系・監査ログ系エンドポイントは
  ページネーション（`limit`/`cursor` またはページ番号）を必須とする。

---

## 6. セキュリティ実装方針

外部レビューで不足を指摘された、CSRF・サーバー側バリデーション・レート制限・HTTPセキュリティ
ヘッダー・ログの取り扱い・監査ログ運用を本章にまとめる。

### 6.1 CSRF対策

- ログイン成功時（`/api/auth/{provider}/callback` 完了時）、セッションに紐付く CSRF トークンを
  発行する。フロントエンドは `/api/auth/me` 応答からトークンを取得し、以後の
  `POST`/`PUT`/`DELETE` リクエストで `X-CSRF-Token` ヘッダーに付与する。
- サーバーは全ての状態変更 API でヘッダーの値とセッション内の値が一致することを検証する。
  不一致・欠落時は `403` を返す。
- 補助的に `Origin` ヘッダーを検証し（自ドメインのみ許可）、`Origin` が送信されない場合に
  限り `Referer` を確認する。
- JSON API は `Content-Type: application/json` を必須とする（フォーム経由の単純な CSRF 攻撃を
  形式面でも防ぐ）。
- ログイン成功時・ログアウト時に CSRF トークンを再発行する。ログアウト API 自体にも
  CSRF 対策を適用する。

### 6.2 サーバー側バリデーション・容量制限

クライアント側のバリデーション（セキュリティポリシー.md 3.2 の JSON インポート検証）と
同等以上のチェックをサーバー側の保存処理（`POST`/`PUT /api/mindmaps/{id}`）でも行う。

| 項目 | 上限（初期値・運用調整可） |
|------|--------------------------|
| リクエストボディサイズ | 5MB |
| ノードのテキスト長 | 500文字 |
| ネスト深さ | 50階層 |
| ノード数 | 5,000ノード／マップ |
| ノードIDの形式・重複 | 形式検証、重複拒否 |
| スキーマバージョン | 対応範囲内のバージョンのみ許可 |
| ユーザーごとのマップ件数上限 | 500件 |
| ユーザーごとの合計データ量上限 | 50MB |
| ユーザーごとのフォルダ数上限 | 100件 |
| Zip内マップ件数上限 | 200件 |
| Zip合計非圧縮サイズ上限 | 50MB |
| ユーザーごとの同時Zip生成数 | 1 |
| アプリ全体の同時Zip生成数 | 5 |

- 上限超過時は `413`（サイズ超過）または `422`（件数・構造超過）を返し、具体的な理由を
  返却する。
- 上記の初期値は要件定義書・セキュリティポリシーの数値を踏襲・拡張したものであり、
  実際の利用状況を見て構築後に調整することを前提とする（§12 未決定事項）。

### 6.3 レート制限

以下のエンドポイント群にレート制限を設ける（具体的な閾値は構築フェーズで確定）。

- 認証系（`/api/auth/*`）: ブルートフォース・アカウント列挙対策
- 保存系（`PUT /api/mindmaps/{id}`）: 異常な高頻度リクエスト対策
- ダウンロード系（個別・Zip）: 大量ダウンロードによる負荷対策
- 管理API（`/api/admin/*`）: 総当たり・スクリプトによる大量操作対策

### 6.4 HTTPセキュリティヘッダー・キャッシュ制御

`.htaccess` に最低限以下を定義する。

- HTTP → HTTPS への強制リダイレクト
- `Strict-Transport-Security`
- 本番用 `Content-Security-Policy`（セキュリティポリシー.md の `script-src 'unsafe-inline'` は
  Phase 2 移行に伴い必要性を再確認し、不要であれば除去する）
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY` または `frame-ancestors 'none'`
- `Referrer-Policy`
- 必要最小限の `Permissions-Policy`

キャッシュ制御:

- `/api/auth/*`、`/api/mindmaps/*`、`/api/admin/*`、ダウンロード系レスポンスは
  `Cache-Control: no-store` を基本とする。
- 静的アセット（ビルド済みJS/CSS）はファイル名ハッシュを前提に長期キャッシュする。

### 6.5 エラーハンドリング・ログ

- 本番環境では `display_errors=Off` とする。
- クライアントへ返すエラーメッセージは一般化し、詳細は相関ID（リクエストID）とともに
  サーバー側ログにのみ記録する。
- 以下はいかなるログ（アプリログ・監査ログ）にも出力しない: OAuth 認可コード、アクセストークン、
  ID トークン、マインドマップ本文、パスワード相当の情報。
- ログイン拒否ログには、判定に必要な範囲（メールドメイン等）を超える個人情報を保存しない。

### 6.6 バックアップ・復元（運用方針）

- バックアップ対象は Web ファイル（`mindmap-app/`）と MySQL データベースの双方とする。
- RPO（目標復旧時点）・RTO（目標復旧時間）・世代数は、heteml の提供するバックアップ機能の
  仕様を構築フェーズで確認したうえで具体的な数値を定める（§12 未決定事項）。
- 定期的な復元テストを構築フェーズ以降の運用に組み込む。
- ソフトデリート（FR-14）は誤操作からの復旧手段としてバックアップを補完するものであり、
  バックアップの代替にはならない。

### 6.7 監査ログ運用仕様

- 保存期間: 既定 1 年（`.env` で設定可能。構築フェーズで確定）。
- 閲覧権限: 管理者のみ（§3.2）。
- 一覧はページネーション・日時範囲/操作種別によるフィルタを提供する。
- 個人情報のマスキング方針: メールアドレスは表示するが、トークン・マップ本文は記録しない
  （6.5 と同様）。
- 管理者自身の操作（自分のロール変更試行の拒否等）も含め、すべての管理操作を記録する。
- ロール変更・ステータス変更は変更前・変更後の値を `detail` に記録する。

---

## 7. コンポーネント設計（フロントエンド追加分）

```
mindmap-tool/src/
├── pages/                      # 新規: 画面単位のルーティング
│   ├── LoginPage.tsx
│   ├── DashboardPage.tsx
│   ├── TrashPage.tsx            # ゴミ箱（FR-14）
│   └── AdminConsolePage.tsx
├── components/
│   ├── Dashboard/
│   │   ├── MindMapListItem.tsx
│   │   ├── FolderListItem.tsx
│   │   └── DashboardToolbar.tsx
│   └── Admin/
│       ├── UserTable.tsx
│       ├── AllowedDomainList.tsx
│       ├── AllowedEmailList.tsx
│       └── AuditLogTable.tsx
├── hooks/
│   ├── useAuth.ts               # /api/auth/me 取得・ログイン状態・CSRFトークン管理
│   ├── useMindMapList.ts        # ダッシュボード一覧取得・フォルダ移動
│   └── useServerAutosave.ts     # サーバー保存モードの直列化デバウンス保存・revision管理・flush
└── api/
    └── client.ts                 # fetch ラッパー（Cookieベースセッション、X-CSRF-Tokenヘッダー付与）
```

- ルーティングライブラリは Phase 1 では未導入のため、Phase 2 で `react-router` 等の追加を
  検討する（ADR で選定理由を記録する）。

---

## 8. ディレクトリ構成（リポジトリ全体・PHPバックエンド追加分）

```
my-mindmap-tool-file-management/
├── mindmap-tool/                # フロントエンド（既存 + 7章の追加分）
└── server/                      # 新規: PHPバックエンド
    ├── composer.json
    ├── .env.example              # サンプル値（ダミー）。実際の.envはGit管理外
    ├── public/                   # heteml の web/<ドメイン>/ 直下にデプロイする部分（1.3参照）
    │   └── api/index.php         # フロントコントローラ
    ├── src/
    │   ├── Auth/                 # OAuthクライアント（Google/Microsoft）・IDトークン検証・許可判定
    │   ├── Http/                 # ルーティング・コントローラ・ミドルウェア（要認証／要管理者／CSRF）
    │   ├── Domain/                # User, Folder, Mindmap, AuditLog
    │   ├── Repository/            # PDOベースのDBアクセス
    │   └── Support/               # 環境変数読み込み、レスポンス／エラーハンドリング
    ├── db/
    │   ├── migrations/            # スキーマ定義（5.1のSQL）
    │   └── seed.php               # 初期管理者・初期許可リストの投入
    └── tests/
```

---

## 9. 技術スタック（バックエンド追加分）

| 役割 | ライブラリ | 選定理由 |
|------|-----------|---------|
| OAuthクライアント | `league/oauth2-client` + `league/oauth2-google` | Composerで導入可能。デファクトスタンダードで週間DL数も多い |
| Microsoft OAuth | `league/oauth2-client` の汎用プロバイダ、またはOIDC検証を含め自前実装するかを評価しADRで選定 | Entra ID の `common` エンドポイント（マルチテナント＋個人アカウント）でのIDトークン検証（3.1参照）に対応できるかを構築フェーズで実装検証のうえ確定する |
| JWT/JWKS検証 | `firebase/php-jwt` 等、実績のあるライブラリを評価 | IDトークンの署名検証・`kid`によるキーローテーション対応を自前実装しない |
| DBアクセス | PDO（MySQL） | PHP標準。追加ライブラリ不要で依存を最小化 |
| Zip生成 | `ZipArchive`（PHP標準拡張） | 追加ライブラリ不要。heteml でのモジュール有効性は構築フェーズで確認 |
| ルーティング | 軽量な自前ルーター、または `nikic/fast-route` | 依存を最小限にしつつ可読性を確保。構築フェーズで確定しADRに記録 |

> Microsoft 側のOAuth・IDトークン検証方式の確定は §12 未決定事項 No.1 を参照。

---

## 10. ローカル開発環境（Docker）方針

- `docker-compose` で以下のコンテナを構成する想定（詳細は「開発ステップ作成」フェーズで確定）。
  - `php`: heteml と同等の PHP バージョンに合わせたイメージ（1.2 でバージョンを固定した後に決定）
  - `mysql`: heteml と同等のMySQLバージョンに合わせたイメージ
  - フロントエンドは Vite の開発サーバーをホスト側でそのまま起動（Phase 1 と同じ）
- OAuthのリダイレクトURIはローカル開発用に別途 Google/Microsoft の開発者コンソールへ登録する
  （例: `http://localhost:8080/api/auth/google/callback`）。本番用と開発用でクライアントID／
  シークレットを分け、`.env` で切り替える。

---

## 11. 環境変数設計（`.env`）

`.env.example` としてリポジトリに雛形を含め、実値は本番サーバー／各開発者のローカルでのみ設定する。

```
# アプリ
APP_ENV=production
APP_URL=https://example.com

# データベース
DB_HOST=localhost
DB_NAME=mindmap
DB_USER=dummy_user
DB_PASSWORD=dummy_password

# Google OAuth
GOOGLE_CLIENT_ID=dummy-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=dummy-secret
GOOGLE_REDIRECT_URI=https://example.com/api/auth/google/callback

# Microsoft OAuth
MS_CLIENT_ID=00000000-0000-0000-0000-000000000000
MS_CLIENT_SECRET=dummy-secret
MS_REDIRECT_URI=https://example.com/api/auth/microsoft/callback
MS_TENANT=common

# セッション
SESSION_IDLE_TIMEOUT_MINUTES=60

# ゴミ箱（FR-14）
TRASH_RETENTION_DAYS=30

# 監査ログ
AUDIT_LOG_RETENTION_DAYS=365

# 初期管理者ブートストラップ（db/seed.php が使用。投入後は空にしてよい）
INITIAL_ADMIN_EMAIL=admin@example.co.jp
INITIAL_ALLOWED_DOMAIN=example.co.jp
```

---

## 12. 未決定事項・今後の検討

| # | 項目 | 備考 |
|---|------|------|
| 1 | Microsoft OAuth・IDトークン検証方式の確定 | `common` エンドポイントでの `iss`/`tid` 整合確認・署名検証ライブラリを含め構築フェーズで検証・ADR化 |
| 2 | ルーティングライブラリの確定 | 自前ルーター vs `nikic/fast-route` |
| 3 | フロントエンドルーティングライブラリ | `react-router` 等の導入要否 |
| 4 | heteml の実際の公開ディレクトリ構成（`web/<ドメイン>/` の実名） | 構築フェーズ開始時に heteml 管理画面で確認し、1.3 の構成図を確定させる |
| 5 | heteml の PHP/MySQLバージョン・有効拡張モジュール（`zip`, `curl`, `openssl`等）の実機確認 | 構築フェーズ開始時に確認し、1.2 のPHPバージョンを固定する |
| 6 | heteml cron（スケジュールタスク）の利用可否・高負荷時の実行保証 | Zipクリーンアップは cron に依存しすぎない設計（3.5）を既に採用。契約プランのマニュアルで詳細確認 |
| 7 | Docker ローカル環境の具体的な `docker-compose.yml` | 「開発ステップ作成」フェーズで具体化 |
| 8 | §6.2 の容量・件数上限の具体値 | 初期値は暫定。実運用のログイン組織規模・利用状況を見て調整 |
| 9 | バックアップの RPO/RTO/世代数 | heteml のバックアップ機能仕様を確認のうえ確定 |
| 10 | 「利用リージョンを日本のみに限定」の担保手段の追加検討 | 現在は許可ドメイン／許可アドレス制御のみで、アクセス元地域そのものは保証しない。heteml には契約プラン側の機能として「海外アタックガード設定」（海外IPからのアクセスを制限する機能）があるとの情報があり、これを有効化することで実態としての地域制限を補強できる可能性がある。**今回の設計・要件確認の範囲では対応せず、実際に有効化するかどうかは構築・デプロイ時に判断する**（要件定義書の文言は現状維持） |
| 11 | 明示的なアカウント連携（同一ユーザーが複数SSOプロバイダを1アカウントに統合する機能） | Phase 2 スコープ外（2.1参照）。必要性が生じた場合に将来検討 |

---

## 13. Phase 1 との接続ポイント

- `mindmaps.data` は Phase 1 の `MindMapFile` JSONスキーマをそのまま保存する。フロントエンドの
  エクスポート／インポートロジック（`exportJSON.ts` / `importJSON.ts`）は変更不要。
- Phase 1 のローカル保存（localStorage自動保存・手動JSON/テキスト保存）はそのまま維持し、
  「ローカル保存モード」として位置づける（3.6, 3.7）。

---

## 14. 変更履歴

| バージョン | 日付 | 変更内容 |
|-----------|------|---------|
| 1.0 | 2026-07-25 | 初版作成（要件定義書.md v2.0 承認を受けて Phase 2 基本設計を新規作成） |
| 2.0 | 2026-07-25 | 外部レビュー（`mindmap-tool/docs/基本設計書_Phase2_レビュー報告書_20260725.md`）の指摘を反映。主な変更: セッションCookieを `SameSite=Strict` から `Lax` に変更しCSRFトークン検証を追加、heteml公開ディレクトリ構成を `public_html/` から `web/<ドメイン>/` 前提に修正、プロバイダアカウントの自動統合を廃止し `provider+sub` を identity の主キーに変更、オートセーブに revision・リクエスト直列化・画面遷移時flushを追加、ユーザー無効化/ロール変更時に `security_stamp` で既存セッションを即時失効、フォルダ移動時の所有者検証とDB複合FKを追加、サーバー側バリデーション/容量制限/レート制限/CSRF/HTTPセキュリティヘッダー/エラーログ取り扱い/監査ログ運用（新設 §6章）を追加、マップ削除をソフトデリート（ゴミ箱・復元・完全削除、FR-14）として設計、マップタイトル/日時の正本をDB側に統一。要件定義書.md v2.1 の FR-14, NFR-S-9/10, NFR-A-6 に対応 |
