# 開発ログ: FloatingEditor による IME 入力改善

**日付**: 2026-04-18  
**担当**: Claude Sonnet 4.6  
**対象ファイル**:
- `src/components/Canvas/FloatingEditor.tsx` （新規）
- `src/components/Canvas/MindMapNodeComponent.tsx`
- `src/components/Canvas/MindMapCanvas.tsx`
- `src/types/mindmap.ts`

---

## 背景・問題

### 前フェーズ（20260418_ime_width_fix.md）で解決できなかった問題

1. **IME 変換候補ウィンドウが左上に出る**  
   ReactFlow は canvas 全体に `transform: translate(x,y) scale(zoom)` を適用している。  
   この CSS transform コンテキスト内にある `<textarea>` は、ブラウザが IME 候補ウィンドウの  
   表示位置を計算する際に座標を正しく解釈できず、画面左上 (0, 0) 付近に表示されてしまう。

2. **高速入力時に IME が誤確定する**  
   テキスト幅の更新（`onDraftChange` → React state → ReactFlow ノード再レンダリング）が  
   composing 中に DOM 変更を引き起こし、一部のブラウザ/OS では IME が誤確定する。

### 根本原因

```
textarea が transform コンテキスト内にある
  → IME システムが getBoundingClientRect() の値を誤解釈
  → 候補ウィンドウが (0,0) に表示される

canvas 再レンダリング
  → ReactFlow が transform を再計算
  → textarea の DOM 親が変化
  → composing 中の IME がリセットされる
```

---

## 解決アプローチ: FloatingEditor

`<textarea>` を ReactFlow の `transform` コンテキスト外（`position: fixed`）に移動する。

```
MindMapCanvas (div, position: relative)
  ├── ReactFlow (transform コンテキスト)
  │     └── MindMapNodeComponent （span のみ、textarea なし）
  │           opacity: 0 (編集中)
  └── FloatingEditor (position: fixed, transform の外)
        └── <textarea> ← IME がここで動作
```

### 座標変換

ReactFlow の `viewport` を使い、ノードの canvas 座標をスクリーン座標に変換:

```
screenX = canvasRect.left + nodeX * viewport.zoom + viewport.x
screenY = canvasRect.top  + nodeY * viewport.zoom + viewport.y
```

`useViewport()` フックで `{ x, y, zoom }` をリアルタイムで取得。

---

## 実装詳細

### FloatingEditor.tsx （新規）

- `position: fixed` で ReactFlow の transform 外に配置
- `canvas.measureText()` で実フォントを使って正確な文字幅を測定
- テキスト幅が増えるたびに `el.style.width` を直接更新（React state 不使用）
- 高さも `el.style.height = el.scrollHeight` で直接更新
- `onDraftChange` はキャンバス側のレイアウト再計算にのみ使用（FloatingEditor の表示には影響しない）
- 編集中は ReactFlow の pan/zoom を無効化: `panOnDrag={!editingId}`, `zoomOnScroll={!editingId}`

### MindMapNodeComponent.tsx

- `<textarea>` 関連のコードをすべて削除（`draftRef`, `isComposingRef`, `handleInputChange` 等）
- `<span>` のみ表示
- 編集中は `opacity: 0` にして FloatingEditor を重ねて見せる
- `NodeData` から `onCommitEdit`, `onCancelEdit`, `onDraftChange` を削除

### MindMapCanvas.tsx

- `canvasWrapRef` を追加（canvasRect 取得用）
- `useViewport()` でリアルタイム viewport を取得
- `CanvasInner`（ReactFlow コンテキスト内）と外側ラッパーを分離
- `FloatingEditor` を `CanvasInner` の末尾に IIFE でレンダリング
- `callbacks` から `onCommitEdit`, `onCancelEdit`, `onDraftChange` を除去

### types/mindmap.ts

```typescript
// 削除されたフィールド
onCommitEdit: (id: string, text: string) => void;  // FloatingEditor に移動
onCancelEdit: () => void;                            // FloatingEditor に移動
onDraftChange: (id: string, text: string) => void;  // FloatingEditor に移動

// 残ったフィールド
onStartEdit: (id: string) => void;
onContextMenu: (e: React.MouseEvent, id: string) => void;
onToggleCollapse: (id: string) => void;
```

---

## 期待される改善

| 問題 | Before | After |
|------|--------|-------|
| IME 候補ウィンドウの位置 | 左上 (0,0) | テキスト入力位置の直下 |
| 高速入力での誤確定 | 発生 | 発生しない（transform 外） |
| 入力中の縦伸び | 発生 | 発生しない（測定してから幅更新） |
| canvas 再レンダリング影響 | IME 中断の可能性 | なし（textarea が別コンテキスト） |

---

## 既知の制限・残課題

1. **zoom 中の FloatingEditor 位置**: 編集中は zoom を無効化しているため問題なし。  
   zoom/pan 後に編集を開始すれば正しい位置に表示される。

2. **`canvas.measureText` のフォント**: `getComputedStyle(el).font` を使用。  
   ブラウザのフォント読み込み前にマウントされた場合、初期幅が若干ずれる可能性あり。  
   RAF で初期化しているため実用上は問題ない。

3. **MindMapNodeComponent の opacity: 0**: 編集中にノード枠が消える。  
   FloatingEditor が枠の代わりに表示されるため視覚的には問題ない。

---

## テスト確認項目

- [ ] 日本語入力時、変換候補ウィンドウが入力位置の近くに出ること
- [ ] 高速入力（連続打鍵）時に誤確定が発生しないこと
- [ ] 入力中にノードの横幅が広がり、縦に伸びないこと
- [ ] Enter で確定・Esc でキャンセルが動作すること
- [ ] Blur（フォーカス外れ）で確定されること
- [ ] Shift+Enter で改行が入ること
- [ ] ルートノードのスタイル（大きいフォント・角丸）が正しく表示されること
- [ ] undo/redo が正常に動作すること
- [ ] ドラッグ操作が編集中に無効化されていること

---

## Codex 引き継ぎ対応（2026-04-18）

ClaudeCode の作業途中状態を引き継ぎ、以下の未完了箇所を修正した。

- `treeToFlow` の引数定義と `MindMapCanvas` 側の呼び出しを整合
- 編集中ドラフトをレイアウト計算に反映し、FloatingEditor の入力幅に追従してキャンバス側のノード幅も更新されるように復元
- `canvasWrapRef` の null 許容型を修正
- `handleCut` の `useCallback` 依存配列を修正
- 子ノードが1件だけの場合も折りたたみボタンが表示されるよう修正

確認結果:

- `npm run build`: 成功
- `npm run lint`: 成功
- `curl -I http://127.0.0.1:5173/my-mindmap-tool/`: `HTTP/1.1 200 OK`

---

## Codex 追加修正: 削除中にエッジが入力欄から離れる問題（2026-04-18）

### 症状

ルートノードから子ノードが出ている状態で、子ノードを編集中に文字を削除すると、ルートノードと子ノードをつなぐ線の終点が入力欄から不自然に離れることがあった。

### 原因

FloatingEditor 導入後、編集中ノードは以下の2層になっている。

- ReactFlow 内の透明ノード: エッジの接続先 Handle を持つ
- ReactFlow 外の `FloatingEditor`: ユーザーに見えている textarea

`treeToFlow` は `editingDraft` を使ってレイアウト幅を計算していたが、`NodeData.node` には元の未確定テキストを渡していた。そのため、文字削除中に以下のズレが起きていた。

- FloatingEditor は短くなった文字列で幅が縮む
- レイアウト計算も短くなった文字列で幅を計算する
- 透明な ReactFlow ノードだけは古い長文を描画し続ける
- ReactFlow のエッジは透明ノードの DOM/Handle を基準に描画する

結果として、エッジの終点が見えている入力欄ではなく、古い文字列を持つ透明ノード側に引っ張られていた。

### 修正内容

`src/utils/treeToFlow.ts` で、編集中ノードに限り `NodeData.node` へドラフト反映済みノードを渡すよう変更した。

```typescript
const originalNode = originalMap.get(node.id) ?? node;
const nodeForRender = node.id === editingId ? node : originalNode;

data: {
  node: nodeForRender,
  ...
}
```

これにより、透明ノードと FloatingEditor が同じ編集中テキストを基準に描画され、ReactFlow の Handle 位置と見えている入力欄の位置が揃いやすくなる。

### 残る可能性のある改善

もしまだ微細なズレや1フレーム遅れが残る場合は、次の追加対応を検討する。

- `nodeHeight` も `NodeData` に渡し、透明ノードの高さをレイアウト計算値に固定する
- `useUpdateNodeInternals(editingId)` を使い、幅・高さ変更後に ReactFlow の Handle/edge 内部キャッシュを更新する

---

## Codex 追加修正: 空文字時のエッジずれ・最小幅不一致の修正（2026-04-18）

### 症状

1. 編集中ノードの文字数が0になった瞬間、ルートノードと子ノードをつなぐ線が少し上にずれる。
2. 長い文字列から削除して7文字以下になったあたりで、入力中ノードの子ノードが入力欄にもぐり込むように見える。

### 原因

どちらも ReactFlow 内の透明ノードと FloatingEditor のサイズ基準がずれていたことが原因。

1つ目は、透明ノードが `nodeWidth` だけを受け取り、高さはDOM内容に任せていたために発生していた。文字数が0になると `<span>` の行ボックスが実質なくなり、透明ノードのDOM高さがレイアウト計算より小さくなる。ReactFlow の Handle は透明ノードの実DOM中心を基準にするため、エッジが上にずれていた。

2つ目は、FloatingEditor の最小幅が「全角7文字 + padding + buffer」相当である一方、`treeToFlow` 側の非ルート最小幅は `60px` のままだったために発生していた。レイアウト計算上は子ノードを短い幅の右側へ置くが、実際に見える FloatingEditor はもっと広いため、子ノードが入力欄に潜って見えていた。

### 修正内容

- `NodeData` に `nodeHeight` を追加
- `treeToFlow` で計算済みの `layout.height` を `nodeHeight` として渡す
- `MindMapNodeComponent` の外側ノードに `height: nodeHeight` を設定し、空文字でも透明ノードの高さが潰れないようにした
- `treeToFlow` の `MIN_NODE_WIDTH` を FloatingEditor の最小幅と揃えた

```typescript
const MIN_EDITOR_TEXT_WIDTH = 7 * 14;
const MIN_NODE_WIDTH = MIN_EDITOR_TEXT_WIDTH + PADDING_H + 16;

data: {
  nodeWidth: layout.width,
  nodeHeight: layout.height,
  ...
}
```

これにより、ReactFlow がエッジ計算に使う透明ノードの幅・高さと、ユーザーに見えている FloatingEditor の最低サイズが揃う。

### 確認観点

- 子ノード編集中に文字をすべて削除しても、エッジが上へ跳ねないこと
- 長文から7文字以下へ削除しても、子ノードが入力欄に潜り込まないこと
- 通常表示、編集開始、Enter確定、Escキャンセル、Undo/Redo に副作用がないこと

---

## Codex 追加修正: 1文字入力時に右側余白が大きすぎる問題（2026-04-18）

### 症状

入力中ノードの文字数が1文字の場合でも、右側に約6文字分の空白が残っていた。

### 原因

前回の「子ノードが入力欄にもぐる」対策で、FloatingEditor と `treeToFlow` の両方に「全角7文字分」を最低幅として持たせていたため。

```typescript
// 旧
const MIN_LOGICAL_TEXT_WIDTH = 7 * 14;
const MIN_NODE_WIDTH = MIN_EDITOR_TEXT_WIDTH + PADDING_H + 16;
```

これにより、入力内容が1文字でも入力欄が7文字相当より小さくならなかった。

### 修正内容

固定の「7文字分」下限を廃止し、幅は以下の式で揃える方針に戻した。

```typescript
width = max(textWidth + paddingH + buffer, MIN_NODE_WIDTH)
```

- `FloatingEditor`: `MIN_LOGICAL_TEXT_WIDTH = 0`
- `treeToFlow`: `ESTIMATE_BUFFER = 16` にして FloatingEditor の `EDITOR_BUFFER` と合わせる
- `treeToFlow`: `MIN_NODE_WIDTH = 60` に戻す

これにより、1文字入力時は不要な6文字分の余白を持たず、同時にレイアウト計算とFloatingEditorの実表示幅も同じ基準で縮む。

### 確認観点

- 1文字入力時に右側余白が過剰に残らないこと
- 文字削除で7文字以下になっても、子ノードが入力欄にもぐり込まないこと
- 空文字時でもエッジが上に跳ねないこと

---

## Codex 追加修正: 既存ノード編集開始時に文字が消える問題（2026-04-18）

### 症状

作成済みノードの文字列を修正しようとして編集開始すると、既存文字列がすべて消え、打ち直し前提の状態になっていた。

### 原因

FloatingEditor 導入後、`MindMapCanvas` は `editingDraft` を `treeToFlow` に渡し、編集中ノードのレイアウト計算へ反映している。

旧実装では `editingDraft` が単なる文字列 state で、初期値は空文字だった。編集開始直後の初回レンダーでは、`useEffect` による現在テキストの初期化がまだ実行されていないため、`treeToFlow` が空文字を「編集中ドラフト」として扱っていた。

その結果、FloatingEditor に渡される `node.text` も空文字になり、既存文字列が消えた状態で編集が始まっていた。

### 修正内容

`editingDraft` を `{ id, text }` 形式に変更し、以下を区別できるようにした。

- まだその編集セッションでユーザー入力が発生していない状態
- ユーザーが実際に全文削除して空文字にした状態

```typescript
const [editingDraft, setEditingDraft] = useState<{ id: string | null; text: string }>({ id: null, text: '' });

const effectiveEditingDraft = editingId
  ? editingDraft.id === editingId
    ? editingDraft.text
    : editingSourceText
  : '';
```

編集開始直後は `editingDraft.id !== editingId` のため、現在のノード文字列 `editingSourceText` を使う。ユーザーが入力・削除した後は `handleDraftChange` により `editingDraft.id === editingId` となるため、空文字も含めてドラフトを正として扱う。

これにより、既存文字列を修正したい場合は文字列が保持され、全文削除して打ち直したい場合は従来通り削除できる。

### 確認観点

- 既存ノードを編集開始したとき、文字列が残っていること
- 文字列の途中修正ができること
- 全文削除した場合は空文字として幅・高さ・エッジ位置が崩れないこと
- IME入力、1文字入力時の幅、子ノード潜り込み修正が維持されること
