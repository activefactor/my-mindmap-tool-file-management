# 開発ログ: IME入力時の横幅追従・縦拡張問題の修正

**日付**: 2026-04-18  
**担当**: Claude Sonnet 4.6  
**対象ファイル**:
- `src/components/Canvas/MindMapNodeComponent.tsx`
- `src/utils/treeToFlow.ts`
- `src/components/Canvas/MindMapCanvas.tsx`

---

## 背景・問題

### 報告された症状

1. **縦にボックスが伸びる（主訴）**  
   入力中（特にIME変換中）、テキストボックスの横幅が変わらないため、文字が折り返されて縦に伸びる。他のノードと重なって視覚的に不快。入力確定後に正しい表示に戻る。

2. **キャレット位置が指定できない**  
   ダブルクリックで編集開始すると `el.select()` で全選択状態になる。クリックで任意位置にキャレットを置くのが困難。また選択済みノードへのシングルクリックで編集を開始できなかった。

3. **英語テキストの幅が広すぎる**  
   `CHAR_WIDTH = 14` が全角文字基準のため、英数字入力時にノードの右側に大きな余白が発生していた。

### 根本原因の分析

**縦拡張問題の構造**:

```
ユーザー入力
  ↓
handleInputChange
  ├─ (変換中) → onDraftChange をスキップ → 幅更新なし
  └─ height = scrollHeight を即時更新  ← 古い（狭い）幅で計算
       → 折り返しが発生 → 縦に伸びる
```

旧実装では「React再レンダリングでIMEが壊れる」恐れから変換中の `onDraftChange` 呼び出しを抑制していた。しかし前フェーズで textarea を **uncontrolled** に変更済みのため、`onDraftChange` を呼んでも React は `value` prop を触らず、IME が壊れないことが判明。

また、高さを「古い幅で計算してから幅を更新」していたのが縦拡張の直接原因。

---

## 修正内容

### 1. `handleInputChange` の再設計（MindMapNodeComponent.tsx）

**変更前**:
```typescript
const handleInputChange = (e) => {
  draftRef.current = e.target.value;
  e.target.style.height = 'auto';
  e.target.style.height = `${e.target.scrollHeight}px`; // 古い幅で計算
  if (!isComposingRef.current) {
    onDraftChange(node.id, value); // 変換中はスキップ
  }
};
```

**変更後**:
```typescript
const handleInputChange = (e) => {
  draftRef.current = e.target.value;
  onDraftChange(node.id, value);          // 変換中も常に呼ぶ → 幅更新
  const el = e.target;
  requestAnimationFrame(() => {           // React再レンダリング後に高さを計算
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`; // 新しい（正しい）幅で計算
  });
};
```

**`requestAnimationFrame` を選んだ理由**:

- `onDraftChange` を呼ぶと React が非同期で `nodeWidth` を更新し DOM に反映する
- `requestAnimationFrame` は次の **ブラウザ描画フレーム前** に実行されるが、React 18 の `useState` は同一タスク内で同期的に DOM を更新するため、RAF 実行時には `nodeWidth` 由来の幅変更が DOM に反映済み
- 結果: 正しい（広い）幅で `scrollHeight` を計算 → 折り返しが起きにくく、高さが最小化される

### 2. ルートノードを動的幅に変更（treeToFlow.ts）

**変更前**: `if (isRoot) return ROOT_NODE_WIDTH` で常に 200px 固定  
**変更後**: `ROOT_MIN_WIDTH = 120` を最小値として、テキスト量に応じて伸縮

```typescript
// 旧
const ROOT_NODE_WIDTH = 200;
const estimateWidth = (text, isRoot) => {
  if (isRoot) return ROOT_NODE_WIDTH; // 固定
  ...
};

// 新
const ROOT_MIN_WIDTH = 120;
const estimateWidth = (text, isRoot) => {
  const paddingH = isRoot ? ROOT_PADDING_H : PADDING_H;
  const minWidth = isRoot ? ROOT_MIN_WIDTH : MIN_NODE_WIDTH;
  const maxLineWidth = text.split('\n').reduce(...estimateLinePixelWidth...);
  return Math.max(maxLineWidth + paddingH + ESTIMATE_BUFFER, minWidth);
};
```

ルートも幅が伸びるようになったことで、入力中に折り返しが減少し縦拡張が抑制される。

### 3. ドラッグ検出をノード実幅ベースに改善（MindMapCanvas.tsx）

ルートが動的幅になったことで、固定値 `NODE_MAX_WIDTH = 200` が不正確になった。  
`n.data.nodeWidth` から実際の幅を取得するよう変更。

```typescript
// 旧: 全ノードを一律 200px と仮定
const right = n.position.x + NODE_MAX_WIDTH + DROP_PADDING;

// 新: 実際の推定幅を使用
const nW = (n.data as { nodeWidth?: number })?.nodeWidth ?? 160;
const right = n.position.x + nW + DROP_PADDING;
```

---

## 副次的な改善（同セッションで実施）

### キャレット配置の改善（前コミット）

- `el.select()` を廃止 → 末尾にキャレット配置
- 選択済みノードへのシングルクリックで編集開始
- `document.caretRangeFromPoint` によるクリック位置へのキャレット配置試行

### 英語テキスト幅の正確化（前コミット）

```typescript
// 旧: 全文字 14px
const CHAR_WIDTH = 14;

// 新: 全角/半角を区別
const CHAR_WIDTH_WIDE = 14;   // ひらがな・カタカナ・漢字
const CHAR_WIDTH_NARROW = 8;  // 英数字・ASCII
```

---

## 残課題・既知の制限

1. **ルートノードのShift+Enter折り返し時の高さ**: ルートでも動的幅になったが、
   意図的に改行を入れた場合はテキストエリアの高さが増える（これは正常動作）。

2. **日本語変換中の幅推定**: 変換前ローマ字（ASCII 8px/char）→ 変換後漢字（14px/char）で
   幅が変わる。これは仕様として許容する。変換確定後に正しい幅に収束する。

3. **`caretRangeFromPoint` の textarea 対応**: ブラウザ依存。非対応時は末尾配置にフォールバック。

---

## テスト確認項目

- [ ] 日本語ローマ字入力時に横幅が伸びること（縦に伸びないこと）
- [ ] 日本語変換候補ウィンドウの位置が安定していること
- [ ] 英語入力時にノード幅が文字量に比例していること
- [ ] ルートノードが動的に幅を変えること
- [ ] 選択済みノードへのシングルクリックで編集が開始されること
- [ ] Shift+Enter での改行が正常に動作すること
- [ ] undo/redo が正常に動作すること
