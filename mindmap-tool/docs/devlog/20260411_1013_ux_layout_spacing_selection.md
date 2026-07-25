# 開発ログ: UX改修 — ノード間隔・テキスト省略・選択表示

**日付**: 2026-04-11  
**担当**: -  
**関連機能**: treeToFlow / MindMapNodeComponent / MindMapCanvas

---

## 報告された問題

1. ノードとノードの間が広すぎる（特に文字が少ない場合）
2. テキストが長いと途中から「…」で省略される
3. ノードが選択されているかわかりにくい

---

## 原因分析

### 問題1: 間隔が広い

前回の修正で `NODE_MAX_WIDTH = 240` に変更し、列間隔 = 300px になっていた。
ノードの実際の幅が 80〜100px でも常に 300px 間隔となり、視覚的に大きな隙間が生じていた。

追加要件「ノードとノードの間の距離は常に一定にしたい」「1ノードなら短く、2ノード以上になったら分岐領域分だけ広げる」を踏まえ、以下の設計変更を決定した：

- **固定幅ノード**: NODE_WIDTH = 160px（非ルート）/ ROOT_NODE_WIDTH = 200px（ルート）
- **列間隔 = NODE_WIDTH + H_GAP = 220px**（ルート列から非ルート列間は ROOT_NODE_WIDTH + H_GAP = 260px）
- 全ノードが同じ列 x に揃い、列間の H_GAP（60px）が「常に一定の距離」を実現

縦方向は V_GAP = 20px で子ノード間が常に 20px の余白。
1子の場合は子が親と同 y に揃い余白なし。2子以上は V_GAP × (n-1) だけ広がる。

### 問題2: テキスト省略

`MindMapNodeComponent` に `whiteSpace: 'nowrap'`, `overflow: 'hidden'`, `textOverflow: 'ellipsis'` が設定されていた。
ノードが固定幅になったため、テキストが収まらない場合に省略が発生していた。

**修正**:
- `whiteSpace: 'normal'`（折り返し許可）
- `wordBreak: 'break-all'`（長い語でも折り返す）
- `overflow`/`textOverflow` を削除

これにより、テキストが折り返して全文表示される。

ノードが固定幅になったことで、テキスト量によってノードの「高さ」が変わる。
レイアウト計算（treeToFlow）で高さを推定して縦方向の位置ズレを防ぐ必要がある。

### 問題3: 選択表示がわかりにくい

`selectedId` が `MindMapCanvas` の props に定義されていたが、**destructure に含まれておらず実際には渡されていなかった**。
`NodeData` にも `isSelected` フィールドが存在せず、ノードコンポーネントに選択状態が伝わっていなかった。

---

## 解決策

### レイアウト設計変更（treeToFlow.ts）

| 定数 | 値 | 意味 |
|------|----|------|
| NODE_WIDTH | 160px | 非ルートノードの固定幅（コンポーネントと一致必須） |
| ROOT_NODE_WIDTH | 200px | ルートノードの固定幅 |
| H_GAP | 60px | 列間の余白（常に一定） |
| V_GAP | 20px | 子ノード間の縦余白 |

列 x 座標: `getColX(depth)` で各深さの x を固定計算。
ノード高さ: `estimateHeight(text, isRoot)` でテキスト長から行数を推定して計算。

### リアルタイムレイアウト更新

テキスト入力時にレイアウトを更新するために `editingDraft` の仕組みを導入。

```
MindMapNodeComponent
  → onDraftChange(id, text) ←---- キー入力のたびに呼ぶ
  
MindMapCanvas
  → setEditingDraft(text)   ←---- useMemo の deps に含める
  
treeToFlow
  → editingId のノードに editingDraft を適用してから高さ推定
  → レイアウト再計算
```

注意点: `MindMapNodeComponent` の `useEffect([isEditing])` から `node.text` を deps 除去。
もし `node.text` を deps に含めると、editingDraft が更新されるたびに `useEffect` が再実行され、
input の `select()` が呼ばれて入力中の文字が全選択されてしまう（UX破壊）。

### 選択表示（isSelected）

`NodeData` に `isSelected: boolean` を追加し、`treeToFlow` で `selectedId` と照合して設定。

選択時のビジュアル:
```css
box-shadow: 0 0 0 2px var(--color-primary-500), var(--shadow-lg);
border-color: var(--color-primary-400);
border-width: 2px;
background: var(--color-bg-node-selected);  /* primary-100 */
```

---

## 変更ファイル

| ファイル | 変更内容 |
|---------|---------|
| `src/types/mindmap.ts` | `NodeData` に `isSelected`, `onDraftChange` を追加 |
| `src/utils/treeToFlow.ts` | 固定幅レイアウト・可変高さ・`editingDraft`/`selectedId` 対応 |
| `src/components/Canvas/MindMapNodeComponent.tsx` | 固定幅・折り返しテキスト・選択リング・`onDraftChange` 呼び出し |
| `src/components/Canvas/MindMapCanvas.tsx` | `selectedId` destructure 修正・`editingDraft` state 追加 |

---

## 今後の課題

- `estimateHeight` は文字数ベースの推定値で、実際の描画高さと多少ズレる場合がある
  - 特に日本語（全角）と英数字（半角）が混在する場合
  - 将来的に `ResizeObserver` で実高さを取得してレイアウトに反映することを検討
- 折りたたみボタン（▶/▼）が固定 `right: -12px` のため、選択リングと重なることがある
