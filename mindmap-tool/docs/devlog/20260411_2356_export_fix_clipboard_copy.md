# 開発ログ: PNG/PDF エクスポート修正 & クリップボードコピー

**日付**: 2026-04-11  
**担当**: -  
**関連機能**: exportPNG / exportPDF / useKeyboard / App

---

## 報告された問題

1. PNG・PDF で書き出すとノード内のテキストのベースラインがズレる
2. 選択ノードを Cmd+C（Mac）/ Ctrl+C（Windows）でクリップボードにコピーしたい

---

## 問題1: PNG/PDF テキストベースラインのズレ

### 原因分析

html2canvas が DOM を複製して描画する際に 2 点の問題が起きていた。

**① フォント未ロード時のフォールバック**  
`document.fonts.ready` を待たずにキャプチャすると、`Noto Sans JP` がまだ読み込まれておらず、
フォールバックフォント（sans-serif）でレンダリングされる。フォントメトリクスが異なるためベースラインが乱れる。

**② `transform: scale()` の干渉**  
`MindMapNodeComponent` は `isDragTarget` に応じて `transform: scale(1.04)` または `transform: scale(1)` を設定している。
html2canvas は transform が設定されたボックスをコンテナー外に描画することがあり、
テキスト座標が本来の位置からズレて見える。

### 解決策

`exportPNG.ts` / `exportPDF.ts` の両方に以下を適用:

```typescript
// フォント読み込み完了を待つ
await document.fonts.ready;

// onclone でノードの transform をリセット
onclone: (_doc, cloned) => {
  cloned.querySelectorAll<HTMLElement>('.react-flow__node > div').forEach((node) => {
    node.style.transform = 'none';
    node.style.transition = 'none';
  });
},
```

また `allowTaint: true` を追加して、クロスオリジンリソースが含まれる場合でも描画を継続するようにした。

---

## 問題2: クリップボードコピー（Cmd/Ctrl+C）

### 設計

- **対象**: 選択中のノード + 配下の全子孫ノード
- **フォーマット**: テキスト保存（`.txt`）と同じ 4 スペースインデント形式
- **キー**: Mac = Cmd+C、Windows = Ctrl+C（`e.metaKey || e.ctrlKey` で統一判定済み）

### 実装

| ファイル | 変更内容 |
|---------|---------|
| `src/utils/exportText.ts` | `nodeToText(node)` ヘルパーを追加・export（ファイル書き出しとロジックを共有） |
| `src/hooks/useKeyboard.ts` | `onCopy: () => void` を追加。`ctrl+c` でコール |
| `src/App.tsx` | `handleCopy` を実装。選択ノードをツリーから検索し `navigator.clipboard.writeText()` でコピー |

### 実装詳細（App.tsx）

```typescript
const handleCopy = useCallback(() => {
  if (!selectedId) return;
  const find = (node: MindMapNode): MindMapNode | null =>
    node.id === selectedId ? node : node.children.map(find).find(Boolean) ?? null;
  const target = find(current);
  if (!target) return;
  navigator.clipboard.writeText(nodeToText(target)).catch(() => {});
}, [selectedId, current]);
```

注意点:
- 編集中（`isEditing`）は `useKeyboard` 全体がスキップされるため、テキスト入力中の Cmd+C はブラウザ標準の文字コピーが動く
- `navigator.clipboard.writeText` は HTTPS または localhost でのみ動作する（開発環境は localhost のため問題なし）
- エラーは無視（コピー失敗時にアラートは出さない）

---

## 変更ファイル

| ファイル | 変更内容 |
|---------|---------|
| `src/utils/exportPNG.ts` | `document.fonts.ready` 待機・`onclone` で transform リセット・`allowTaint: true` 追加 |
| `src/utils/exportPDF.ts` | 同上 |
| `src/utils/exportText.ts` | `nodeToText()` ヘルパーを export |
| `src/hooks/useKeyboard.ts` | `onCopy` ハンドラ追加、`ctrl+c` に対応 |
| `src/App.tsx` | `handleCopy` 実装、`useKeyboard` に渡す |
