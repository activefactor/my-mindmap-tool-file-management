# Mindmap Tool（サーバー版・Phase 2 開発中）

ブラウザで動くマインドマップ作成ツールです。

社内情報共有向けに、まずサーバーレス版（Phase 1）として開発し、現在はレンタルサーバー
（[heteml](https://www.heteml.jp/)）上で動作する **サーバー版（Phase 2）** への移行を進めています。
Phase 2 では SSO ログイン（Google / Microsoft）・ダッシュボード・フォルダ管理・サーバー保存
などを追加します。

---

## フロントエンドの操作方法・仕様

ノード操作・キーボードショートカット・ファイル形式（JSON／インデントテキスト）など、
マインドマップ編集そのものの操作方法はサーバーレス版と共通です。詳細な操作方法は
サーバーレス版リポジトリの README を参照してください。

- サーバーレス版リポジトリ: https://github.com/activefactor/my-mindmap-tool
- 公開デモ（サーバーレス版）: https://activefactor.github.io/my-mindmap-tool/

> 本リポジトリ（サーバー版）は GitHub Pages では公開しません（認証・DB を伴うため、
> レンタルサーバー上にデプロイして運用します）。

---

## このリポジトリの構成

```
my-mindmap-tool-file-management/
├── mindmap-tool/   # フロントエンド（React + Vite。サーバーレス版と同一コードベースを拡張）
├── server/         # バックエンド（PHP + MySQL、Phase 2 で追加。設計は docs/基本設計書_Phase2.md）
└── docs/           # ドキュメント一式（要件定義書・基本設計書・開発標準ルール・セキュリティポリシー・デザイン定義）
```

---

## セットアップ・起動方法

### フロントエンド（`mindmap-tool/`）

```bash
cd mindmap-tool
npm install
npm run dev
```

`http://localhost:5173` を開きます。ビルドする場合は `npm run build`（`dist/` に出力）。

### バックエンド（`server/`）— ローカル環境構築中（Step 1）

Phase 2 のバックエンド（PHP 8.5 + MySQL 8.4、SSO認証）は、Docker によるローカル開発環境の
構築に着手した段階です（認証・保存API等は未実装。疎通確認用のヘルスチェックのみ）。
詳細な構成・DBスキーマ・APIは [docs/基本設計書_Phase2.md](docs/基本設計書_Phase2.md) を、
起動手順は [server/README.md](server/README.md) を参照してください。

```bash
cp server/.env.example server/.env
docker compose up -d --build
curl http://localhost:8080/api/health
```

- 本番環境はレンタルサーバー（heteml、`mindmap.activefactor.org`）上にデプロイします。
- DB接続情報・SSOクライアントシークレット等の機密情報は `.env` で管理し、Git にはコミットしません
  （`server/.env.example` にダミー値のサンプルを用意）。

---

## ドキュメント

| ドキュメント | 内容 |
|------------|------|
| [docs/要件定義書.md](docs/要件定義書.md) | 全フェーズの機能要件・非機能要件 |
| [docs/基本設計書_Phase1.md](docs/基本設計書_Phase1.md) | Phase 1（サーバーレス版）の設計 |
| [docs/基本設計書_Phase2.md](docs/基本設計書_Phase2.md) | Phase 2（heteml サーバー版）の設計 |
| [docs/開発ステップ_Phase2.md](docs/開発ステップ_Phase2.md) | Phase 2 の実装ステップ・チェックリスト |
| [docs/開発標準ルール.md](docs/開発標準ルール.md) | コーディング規約・Git運用・devlog/ADRフォーマット |
| [docs/セキュリティポリシー.md](docs/セキュリティポリシー.md) | 脅威モデル・XSS/CSP・認証等のセキュリティ規約 |
| [docs/DESIGN.md](docs/DESIGN.md) | UIデザイントークン（Single Source of Truth） |

開発ログ・プロンプトログ・アーキテクチャ決定記録は以下にあります。

- `docs/devlog/` / `docs/promptlog/` — 要件・設計・レビュー対応など、コードに紐付かない
  プロジェクト全体の記録
- `mindmap-tool/docs/devlog/` / `mindmap-tool/docs/adr/` — フロントエンドのコード変更に
  関する記録

---

## 技術スタック

### フロントエンド

- [React 19](https://react.dev/) / [TypeScript](https://www.typescriptlang.org/) / [Vite](https://vite.dev/)
- [React Flow v11](https://reactflow.dev/)（マインドマップ描画）
- [html-to-image](https://github.com/bubkoo/html-to-image) / [jsPDF](https://github.com/parallax/jsPDF)（PNG/PDF出力）

### バックエンド（Phase 2、構築中）

- PHP 8.5（CGI版、Composer）/ MySQL 8.4
- OAuth 2.0 / OIDC（Google, Microsoft）※ Step 3 で実装予定
- ホスティング: heteml（`mindmap.activefactor.org`）

詳細は [docs/基本設計書_Phase2.md](docs/基本設計書_Phase2.md) §8 を参照してください。
