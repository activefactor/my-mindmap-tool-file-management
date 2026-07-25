# 開発ログ: クリップボードカット／ペースト機能の実装とコードレビュー対応

**日付**: 2026-04-14  
**担当**: manabu.komatsu  
**関連機能**: FR-01-10（カット）、FR-01-11（クリップボードペースト）

---

## 背景・課題

マインドマップのノードをテキストとして他のツールやAIと往復させる際、コピーだけでなくカット・ペーストが必要という要求から追加。
特にペーストは、AI生成や外部テキストをそのままマップに取り込む入口として、Phase 3 AI連携の基礎にもなる。

---

## 実装内容

### カット（`Ctrl+X` / `Cmd+X`）

- `handleCut`（App.tsx）: `nodeToText` でクリップボードに書き込み、成功後に `handleDelete` を呼ぶ
- ルートノードへの適用は無視
- 削除は既存の `handleDelete` を経由するため Undo 対象

### ペースト（`Ctrl+V` / `Cmd+V`）

- `handlePaste`（App.tsx）: `navigator.clipboard.readText()` でテキスト取得 → `parseIndentText` でパース → `pasteNode` で選択ノードの子として追加
- `parseIndentText` を `importText.ts` からエクスポートし、クリップボード文字列を直接受け取れるようにした
- ペースト時は `generateId()` で ID を新規採番（クリップボード内の ID は使用しない）
- パース失敗・クリップボード読み取り失敗はいずれも無視（エラー表示なし）

### `useMindMap.ts` への追加

- `pasteNode(parentId, nodeToAdd)`: パース済みノードを parentId の子として末尾追加

---

## コードレビュー対応（Codex レビュー 2026-04-14）

### P1-1: 右クリックメニューの対象ノードずれ（修正済み）

**問題**: `setSelectedId(contextMenu.nodeId)` は非同期のため、直後に呼ばれる `handleAddChild()` 等では古い `selectedId` が使われていた。

**対応**: `handleAddChild(targetId?)` / `handleAddSibling(targetId?)` / `handleDelete(targetId?)` に任意引数を追加。右クリックメニューからは `contextMenu.nodeId` を直接渡すよう変更。

### P1-2: 500文字超ノードで次回起動時にデータ消失（修正済み）

**問題**: `importJSON.ts` の `validateAndNormalizeNode` は 500 文字超で throw するが、編集・テキストインポート・ペーストには上限がなかった。localStorage 復元時に throw → `null` 返却 → 初期状態になりデータが消える。

**対応**:
- `MindMapNodeComponent.tsx` の `<input>` に `maxLength={500}` を追加（編集時に 500 文字超を入力不可にする）
- `importText.ts` の `_parseIndentText` で各行テキストを `.slice(0, 500)` に切り詰める（テキストインポート・ペースト共通）

### P2: クリップボードペーストにサイズ上限なし（修正済み）

**対応**: `handlePaste` に 1MB 超のテキストは処理しないガードを追加（ファイルインポートと同基準）。

### P2: README・基本設計書の古い記載（修正済み）

- README: 「ノードを編集: ダブルクリック、または `Enter`」→ `F2` に修正。カット/ペーストのショートカットを追記
- 基本設計書 3.4: Undo/Redo 表に `Cmd` キーを追記

### P2: CSP `script-src 'unsafe-inline'`（ポリシー側に対応）

コードは変更せず、セキュリティポリシー.md の CSP 例を実装に合わせて更新し、`unsafe-inline` が必要な理由（Vite HMR / ReactFlow）を明記。Phase 2 移行時に本番用 CSP を分離して除去を検討。

### P3: html2canvas が直接依存として見える件（対応不要と判断）

Codex 指摘では「未使用の直接依存」とされたが、調査の結果 `jspdf@4.2.1` の間接依存であることが判明。ソースコードに直接 import はなく、削除不可・対応不要。

---

## 判断・仕様メモ

- ペースト失敗（パース失敗・クリップボード読み取り失敗）はエラー表示なし。ユーザーが意図しない大量テキストをペーストした場合に余計なダイアログが出ないほうが望ましいと判断
- カット時にクリップボード書き込みが失敗した場合は削除しない（データロストを防ぐ）
- 500文字制限の超過はテキストインポート・ペーストで切り詰め（= 拒否ではなく受け入れつつ上限適用）。UIからの編集は `maxLength` で入力自体をブロック

---

## 今後の課題

- ペースト失敗時に控えめな通知（トースト等）を表示するか Phase 2 以降で検討
- CSP の `script-src 'unsafe-inline'` を本番ビルドで外せるか Phase 2 移行時に検証
