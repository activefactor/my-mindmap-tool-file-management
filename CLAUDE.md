# CLAUDE.md

このリポジトリで作業する Claude Code 向けのプロジェクト規約サマリー。
毎回の会話で口頭説明しなくて済むよう、既存ドキュメントへのポインタと「絶対に守ること」だけをここに集約する。
詳細な理由・背景は各ドキュメント本体を参照すること。

## プロジェクト構成

```
my-mindmap-tool-file-management/
├── README.md
├── CLAUDE.md
├── docs/                      # プロジェクト全体のドキュメント（正）
│   ├── 要件定義書.md           # 全フェーズの機能要件・非機能要件
│   ├── 基本設計書_Phase1.md     # Phase 1（サーバーレス版）の設計
│   ├── 基本設計書_Phase2.md     # Phase 2（heteml サーバー版）の設計
│   ├── 開発ステップ_Phase2.md   # Phase 2 の実装ステップ・チェックリスト
│   ├── 開発標準ルール.md         # コーディング規約・Git運用・devlog/ADRフォーマット
│   ├── セキュリティポリシー.md    # 脅威モデル・XSS/CSP/認証等のセキュリティ規約
│   └── DESIGN.md               # UIデザイントークン（Single Source of Truth）
└── mindmap-tool/               # アプリ本体（React + Vite、Phase 1 コードベース）
    └── docs/
        ├── adr/                # アーキテクチャ決定記録
        ├── devlog/             # 開発ログ
        └── promptlog/          # プロンプトログ（ユーザー指示の記録）
```

Phase 2（レンタルサーバー heteml への移行）のバックエンドコード（PHP）は `server/` ディレクトリに
配置する（`docs/基本設計書_Phase2.md` §7 参照）。「開発ステップ作成」フェーズで実際に着手する。

## 進め方（このプロジェクトの開発フロー）

要件確認 → 基本設計確認 → 開発ステップ作成 → 構築 → 開発ルール/ポリシーのテスト、の順で進める。
フェーズを飛ばして実装に着手しない。要件・設計に無い機能を先回りして作らない。

## 絶対に守ること

- **ドキュメント同期必須**: 機能追加・仕様変更・技術選定を行った場合、同じコミット/PRで
  `docs/要件定義書.md` / `docs/基本設計書_PhaseX.md` / devlog を更新する（`docs/開発標準ルール.md` §5）。
- **devlog 必須**: 新機能実装・技術選定・バグ修正・仕様解釈の判断を行ったら
  `mindmap-tool/docs/devlog/YYYYMMDD_タイトル.md` に記録する（フォーマットは `docs/開発標準ルール.md` §6）。
- **ADR**: アーキテクチャレベルの意思決定（ライブラリ選定、認証方式、DB設計等）は
  `mindmap-tool/docs/adr/YYYYMMDD_タイトル.md` に記録する（`docs/開発標準ルール.md` §7）。
- **プロンプトログ**: ユーザーからの主要な指示・要求は `mindmap-tool/docs/promptlog/YYYYMMDD_内容.md` に
  記録する（フォーマットは後述）。
- **DESIGN.md 準拠**: 色・spacing・font-size をコンポーネントにハードコードしない。必ず
  `docs/DESIGN.md` 定義の CSS カスタムプロパティを使う。
- **XSS対策**: `dangerouslySetInnerHTML` / `innerHTML` への文字列代入禁止（`docs/セキュリティポリシー.md` §3.1）。
- **機密情報**: DB接続情報・SSOクライアントシークレット等は `.env` で管理し、Git にコミットしない。
  リポジトリには `.env.example` にサンプル値（ダミー）を用意する。
- **`main` へ直接コミットしない**。ブランチ運用は `docs/開発標準ルール.md` §4.1 に従う。
- **`any` 型禁止**（やむを得ない場合は `// TODO: 型定義` を付す）。
- コミットメッセージは Conventional Commits（`docs/開発標準ルール.md` §4.2）。

## Phase 状況（2026-07-25 時点）

- **Phase 1**（サーバーレス・認証なし）: 承認済み・リリース済み（GitHub Pages）。
- **Phase 2**（heteml サーバー移行・SSO・ダッシュボード・フォルダ管理・保存方式変更）:
  `docs/要件定義書.md` v2.1 承認済み。`docs/基本設計書_Phase2.md` v2.0 作成済み（外部レビュー
  `mindmap-tool/docs/基本設計書_Phase2_レビュー報告書_20260725.md` の指摘を反映済み）。
  `docs/開発ステップ_Phase2.md` v1.0 作成済み（Step 0〜12）。次は「構築」（Step 0 の
  heteml実機確認から着手）。
  - ホスティング: heteml（契約済み、SSH接続・Composer利用可能な上位プラン）
  - バックエンド: PHP + MySQL（DBスキーマ・APIエンドポイントは `docs/基本設計書_Phase2.md` §5 参照）
  - 認証: Google / Microsoft SSO（Microsoft は職場・学校・個人アカウント両方、`common`エンドポイント）+ 許可ドメイン／許可アドレスによるアクセス制御。プロバイダ間のメール自動統合は行わない（`docs/基本設計書_Phase2.md` §3.1）
  - セッションCookie: `SameSite=Lax` + CSRFトークン検証（`SameSite=Strict` は OAuth コールバックと矛盾するため不採用）
  - マップ削除: ソフトデリート（ゴミ箱・復元・保持期間後の完全削除、FR-14）
  - ローカル開発: Docker等の仮想環境で heteml 相当の PHP/MySQL 環境を再現する想定（構築フェーズで整備）
  - 未決定事項: `docs/基本設計書_Phase2.md` §12 参照（Microsoft OAuthライブラリ、ルーティングライブラリ、heteml実機のディレクトリ構成・PHP/MySQLバージョン確認、容量上限の具体値、バックアップRPO/RTO等）
- **Phase 3**（AI連携）: 未着手。
- **Phase 4**（共同編集）: 未着手・将来検討。

## プロンプトログのフォーマット

`mindmap-tool/docs/promptlog/YYYYMMDD_内容.md`

```markdown
# プロンプトログ: [タイトル]

**日付**: YYYY-MM-DD

## ユーザーの指示（要約）

[何を依頼されたか]

## 主な決定・確認事項

[Claude から確認した質問とユーザーの回答、決定事項]

## 対応内容

[更新したファイル・作成した成果物]
```

機微情報（社内の実ドメイン名、実メールアドレス等）が含まれる場合は抽象化して記録する。
