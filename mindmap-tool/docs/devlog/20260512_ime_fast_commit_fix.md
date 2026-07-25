# 開発ログ: IME 高速入力時にノードへ変換前テキストが確定される問題の修正

**日付**: 2026-05-12
**担当**: Claude Opus 4.7
**対象ファイル**:
- `src/components/Canvas/FloatingEditor.tsx`

---

## 背景・問題

### 報告された症状

ノード編集中に「文字を入力 → space で変換 → Enter で確定」を**素早く**操作すると、ノードに保存されるテキストが**変換前の中途半端な文字列**（ひらがな・ローマ字混在など）になってしまう。

ゆっくり同じ操作をすると正しく変換後テキストで確定される。日常的に使用していて非常に気持ち悪い挙動。

### 関連する過去の修正

- `3a74752 fix: IME確定問題を修正、カラーピッカーを1つに統合`
- `5669e51 fix: IME入力中の高さ更新・キャレット配置・英語幅を改善`
- `f9818d1 fix: IME編集とクリップボード形式を改善`

IME 関連は段階的に修正してきたが、今回の「高速入力時のみ再現」のケースが残っていた。

---

## 根本原因

`FloatingEditor.handleKeyDown` の IME 中判定が `e.nativeEvent.isComposing` のみに依存していた。

```tsx
if (e.key === 'Enter') {
  if (e.nativeEvent.isComposing) return; // ← これだけでは不十分
  ...
  onCommit(node.id, draftRef.current);
}
```

### イベント順序の環境差

`e.nativeEvent.isComposing` は「その keydown イベント時点で IME 合成中か」を表すが、
ブラウザ × IME の組み合わせによって変換確定 Enter 時の挙動が異なる：

| 環境 | keydown(Enter) の isComposing | compositionend のタイミング |
| --- | --- | --- |
| Chrome + Mac kotoeri（通常速度） | true | keydown より後 |
| Chrome + Google 日本語入力（高速時） | **false になることがある** | keydown より後 |
| Safari の一部バージョン | false | keydown より後 |

### 不具合シーケンス（高速入力時）

```
1. ユーザー: "あさ" 入力 (composition active)
   → handleChange → draftRef.current = "あさ"

2. ユーザー: space (変換 → "朝")
   → handleChange → draftRef.current = "朝" になる予定

3. ユーザー: 素早く Enter（変換確定の意図）
   → keydown(Enter) が isComposing=false で発火 ★
   → handleKeyDown: IME 中ではないと誤判定
   → onCommit(id, draftRef.current) を実行
   → ただし draftRef.current はまだ "あさ" のまま
     （step 2 の input イベントが処理しきれていない or
      IME 候補確定の input イベントは compositionend と同時にしか飛ばない実装）

4. compositionend が遅れて発火
   → draftRef.current = "朝" になるが手遅れ
   → ノードには既に "あさ" が保存済み
```

ゆっくり操作した場合は step 2 と step 3 の間に十分な時間があり、`handleChange` で `draftRef.current` が "朝" に更新されてから keydown が走るため、たとえ `isComposing=false` でも正しい値が commit される。

---

## 修正内容

### 1. IME 中判定の多重化

```tsx
const isIMEActive =
  e.nativeEvent.isComposing ||
  isComposingRef.current ||
  e.keyCode === 229;
```

| 検出手段 | 役割 |
| --- | --- |
| `e.nativeEvent.isComposing` | 標準的な判定。多くの環境で動作 |
| `isComposingRef.current` | `compositionstart` で true、`compositionend` で false にする自前フラグ。`isComposing` が早く false に落ちる環境のフォールバック |
| `e.keyCode === 229` | レガシーな IME 入力検出。古い実装でも検出可能 |

`isComposingRef` は `compositionend` まで true を保つため、たとえ IME が Enter を消費して `nativeEvent.isComposing` を false に落としても、`compositionend` 完了までは IME 中と判定される。

### 2. commit 時に DOM の最新値を直接読む

```tsx
onCommit(node.id, e.currentTarget.value);
```

`draftRef.current` は `handleChange` 経由で更新されるため、イベント処理タイミングによっては最新値より遅れる可能性がある。DOM の `textarea.value` を直接読むことで、ブラウザが認識している最新の確定済みテキストを取得できる（保険）。

### 3. `handleBlur` も同様に修正

`textareaRef.current.value` を直接読むよう変更。同じ理由（draftRef の同期ずれ防止）。

---

## 動作の変化

### Before
- "あさ" + space + Enter（変換確定の意図）
  - 高速操作時: ノードに "あさ" が保存されてしまう（不具合）
  - ゆっくり操作時: 編集モード継続、もう一度 Enter で "朝" 確定

### After
- "あさ" + space + Enter（変換確定の意図）
  - 高速・低速問わず: 編集モード継続（誤確定しない）
  - もう一度 Enter で "朝" 確定（正しい挙動）

Tab キーによる子ノード作成についても同じロジックを適用しているため、IME 高速入力中の Tab 押下でも誤確定しない。

---

## 残課題・既知の制限

1. **二段階確定の UX**: Enter を 2 回押す必要がある（1 回目で IME 確定、2 回目でノード確定）。これは Web の標準的な IME 動作で、一般的にユーザーが習熟している挙動。1 回の Enter で両方を済ませたい場合は別途検討が必要。

2. **`keyCode` の非推奨**: `e.keyCode === 229` は deprecated だが、互換性目的で広く使われており、当面残す。

3. **テスト自動化の困難さ**: IME 関連の挙動は OS / IME / ブラウザの組み合わせ依存で、ユニットテストで再現するのは困難。手動確認で検証する。

---

## 追記: 第二弾の修正（同日対応）

### 報告された残存症状

第一弾の修正後も、特定のシーケンスで以下の症状が残った：

- `くずれおちるよしむら` → IME 変換 → `崩れ落ちる吉村` を選択 → Enter で確定
- **ノードの表示**は `崩壊吉村` のような IME 中間候補で固まる
- **保存値は正しく** `崩れ落ちる吉村`（再編集して確認可能）

「保存値は正しいが表示だけ古い」という組み合わせは、commit は成功している一方で**表示レンダリングが古い draft 値で残っている**ことを示す。

### 原因（推定）と対策

3 つの経路を疑い、それぞれに防御策を入れた。

#### 経路 A: `editingDraft` に中間 IME 候補が残る

`handleChange` は IME 中の input イベントごとに `onDraftChange` を呼んで親の `editingDraft` を更新する。compositionend 前に「崩壊吉村」のような中間候補が `editingDraft` にセットされる瞬間がある。

`useEffect(() => { if (!editingId) setEditingDraft(...) }, [editingId])` は commit 後の cleanup を担うが、useEffect は render 後に走るため、commit 直前の中間値が一瞬残る window がある。

**対策**: commit / cancel / addChild の経路で `setEditingDraft({ id: null, text: '' })` を**同期的に**実行する（[MindMapCanvas.tsx](../../src/components/Canvas/MindMapCanvas.tsx)）。これで commit 後の input/composition 由来のイベントで draft が再代入される余地を消す。

#### 経路 B: `handleCompositionEnd` の `updateWidth` 遅延

```tsx
// Before
requestAnimationFrame(() => updateWidth());

// After
updateWidth();
```

`requestAnimationFrame` 越しに `onDraftChange` を呼んでいたため、Enter 確定時点で親の `editingDraft` が `compositionend` 直前の値で固まっていた。即時実行に変更（[FloatingEditor.tsx](../../src/components/Canvas/FloatingEditor.tsx)）。

#### 経路 C: `memo` の shallow 比較で再レンダが走らない

`MindMapNodeComponent` は `memo` で包まれており、デフォルトの shallow 比較は `data` プロップの**参照**のみを見る。ReactFlow は内部 store でノードを管理しており、`data` オブジェクトを再生成しても、ReactFlow 側の差分判定や React 側の最適化で再レンダがスキップされるケースがあると報告されている。

**対策**: `memo` に明示的な比較関数を追加し、`data.node.text` を含む主要プロパティを直接比較する（[MindMapNodeComponent.tsx](../../src/components/Canvas/MindMapNodeComponent.tsx)）。

```tsx
}, (prev, next) => {
  const a = prev.data;
  const b = next.data;
  return (
    a.node === b.node &&
    a.node.text === b.node.text &&
    a.node.collapsed === b.node.collapsed &&
    a.node.children.length === b.node.children.length &&
    // ...
  );
});
```

text の参照同一性だけでなく文字列比較も入れることで、参照だけ変わらなかった場合でも安全に再レンダする。

#### 診断ログ

commit 経路に `console.debug('[FloatingEditor.onCommit]', { id, text, editingDraft })` を一時的に残してある。再現時に DevTools コンソールを開いて、commit 時に渡されている `text` と `editingDraft` の値を確認することで、どの経路が悪さしているかをピンポイント特定できる。原因が確定したら削除する。

### 追加テスト項目

- [ ] 「くずれおちるよしむら」+ space で複数候補を経由 + Enter 確定 → 表示と保存値が一致すること
- [ ] 上記操作直後に再編集して、編集画面と表示が同じ文字列であること
- [ ] DevTools コンソールに `[FloatingEditor.onCommit]` ログが出力され、`text` と `editingDraft.text` が同一であること

---

## テスト確認項目

- [ ] 「あさ」+ space + Enter を**素早く**操作 → 編集モード継続、もう一度 Enter で "朝" が確定すること
- [ ] 「あさ」+ space + Enter を**ゆっくり**操作 → 従来通り正しく確定すること
- [ ] IME 確定後の Enter → ノードテキストとして保存され、編集モード終了
- [ ] IME 変換中に Tab → 誤って子ノードが作成されないこと
- [ ] IME 確定後に Tab → 子ノード作成と親ノードの確定が正しく動作すること
- [ ] IME 変換中に別の場所をクリック（blur）→ 即座に commit されないこと
- [ ] 英語入力時の Enter / Tab → 従来通り動作すること
- [ ] Shift+Enter での改行 → 従来通り動作すること

---

## 設計メモ: 「IME 中フラグ」を信頼するための原則

IME 関連の不具合は以下の組み合わせで発生する：

1. **`isComposing` の不一致**: ブラウザ × IME によって変換確定 Enter 時の値が異なる
2. **イベント順序の不確定性**: `compositionend` と `input` の順番がブラウザによって異なる
3. **値の同期遅れ**: React state / ref の更新タイミングと DOM の最新値がずれる

これらを防ぐための原則：

- **複数の検出手段を OR で組み合わせる**（`isComposing` 単独に依存しない）
- **自前の `isComposingRef` を `compositionstart` / `compositionend` で管理する**
- **commit / blur 時は DOM の最新値を直接読む**

今後 IME 関連で類似の問題が出た場合、まずこの 3 原則を満たしているかを確認する。

---

## 追記: 第三弾（真因確定）— Chrome 翻訳機能による DOM 書き換え

### 発覚の経緯

第一弾・第二弾の修正後も「commit 値は正しいのに表示だけ古い候補のまま固定される」症状が残った。詳細にログを仕込んで切り分けた結果：

| 観測ポイント | 値 |
| --- | --- |
| `localStorage` | `"崩れ落ちる吉村"` ✓ |
| `[Canvas.computed]`（useMemo 出力） | `"崩れ落ちる吉村"` ✓ |
| `[Canvas.state]`（useNodesState） | `"崩れ落ちる吉村"` ✓ |
| `[NodeRender]`（MindMapNodeComponent props） | `"崩れ落ちる吉村"` ✓ |
| 実 DOM の `innerText` | `"崩壊吉村"` ✗ |

**React の virtual DOM レベルでは全て正しい**のに、実 DOM だけが古い「崩壊吉村」を保持していた。

### 真因

該当ノードの `outerHTML` を確認したところ：

```html
<span>
  <font dir="auto"><font dir="auto">崩壊吉村</font></font>
</span>
```

`<font>` タグはアプリ側で出力していない。これは **Chrome の自動翻訳機能（または Google 翻訳拡張）**が DOM の text node を `<font>` タグで包んで「翻訳結果」テキストに置き換えていたもの。

Chrome 翻訳は次の挙動をする：
1. ページのテキスト node を検出
2. それを `<font>` タグで包み、翻訳結果テキストを書き込む
3. 以後、React が text node を更新しても、**React の virtual DOM diff は `<font>` の中まで降りていかず**、Chrome 翻訳が書き換えた古いテキストが残り続ける

「崩れ落ちる吉村」を Chrome 翻訳が**意訳して「崩壊吉村」と表示**していたため、ユーザーの IME 候補にも無い文字列が表示される不可解な現象になっていた。

### 症状の説明（すべて整合する）

- **編集モードでは正しく表示される**: FloatingEditor は createPortal で body 直下に出ており、textarea の value は DOM API で直接読まれるため `<font>` タグの影響を受けない
- **保存値は常に正しい**: データ層は無傷
- **F5 リロードしても直らない**: リロード後に Chrome 翻訳が再度 DOM を書き換えるため
- **IME 候補にない文字列が表示される**: 翻訳エンジンが意訳した結果
- **GitHub Pages 版で再現していた**: 本番ドメインで Chrome 翻訳が積極的に走っていた

### 対策

3 層で翻訳を抑止する（[index.html](../../index.html)、[MindMapNodeComponent.tsx](../../src/components/Canvas/MindMapNodeComponent.tsx)）：

1. `<html lang="ja">` に変更（英語サイト扱いを避ける）
2. `<meta name="google" content="notranslate">` をページ全体に追加
3. ノードテキストの `<span>` に `translate="no"` と `className="notranslate"` を明示

これで Chrome 翻訳が DOM に介入できなくなり、React が書き込んだ text node がそのまま表示される。

### 副次的な修正（保持する価値あり）

真因は翻訳エンジンだったが、調査過程で入れた以下の改善はそれ単体でも妥当なので残す：

- **多層 IME 検出**（`isComposing` + `isComposingRef` + `keyCode 229`）: 高速入力時の `isComposing=false` 取りこぼし対策として有効
- **commit 時に DOM の最新値を直接読む**（`e.currentTarget.value`）: draftRef との同期ずれ防止
- **`compositionend` の `updateWidth` を rAF から即時に変更**: 編集中の幅推定の正確性が上がる
- **`useNodesState` 化**: ReactFlow を controlled mode で正しく使うパターン
- **React 18 batching を意識した `setEditingDraft` の即時クリア**: commit 後の stale draft 防止

### 学び

- **React の virtual DOM と実 DOM の乖離を見たら、まず DOM 拡張・ブラウザ翻訳を疑う**
- DOM の `outerHTML` を直接見ることで `<font>` タグや不審な属性を検出できる
- `translate="no"` は短い識別子・固有名詞・コード表示・ユーザー入力テキストには常に付けておくべき
- 「症状が IME に紐づいているように見える」現象が、実は IME ではなく**ブラウザ拡張による DOM 書き換え**だったケースとして記録に残す
