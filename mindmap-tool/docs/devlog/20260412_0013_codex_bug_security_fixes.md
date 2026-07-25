# 開発ログ: Codex バグ・セキュリティ報告書の対応

**日付**: 2026-04-12  
**担当**: -  
**参照**: `docs/バグ_セキュリティチェック報告書_20260412.md`

---

## 対応一覧

| 指摘 | 重要度 | ステータス |
|------|--------|------------|
| BUG-01: ESLint 13エラー | High | 修正済み |
| BUG-02: canUndo/canRedo が ref 由来 | Medium | 修正済み |
| SEC-01: CSP メタタグ未設定 | High | 修正済み |
| SEC-02: localStorage 復元時の検証なし | Medium | 修正済み |
| SEC-03: JSON インポートのスキーマ検証不足 | Medium | 修正済み |

---

## BUG-01: Toolbar.tsx — コンポーネント内コンポーネント定義

### 問題

`Toolbar` コンポーネント内で `DropMenu`・`MenuItem` を関数として定義していた。
React は毎レンダーごとに新しい関数 = 新しいコンポーネント型として扱うため、
`react-hooks/static-components` ルール違反になり、状態リセットの潜在的バグになる。

### 修正

`DropMenu`・`MenuItem` をモジュールレベル（`Toolbar` の外）へ移動。  
クロージャで参照していた `openMenu`・`setOpenMenu` は props に変換:

- `DropMenu` に `openMenu: string | null` と `onToggle: (name: string) => void` を追加
- `MenuItem` に `onClose: () => void` を追加
- `btnStyle`・`menuItemStyle` も定数として外出し（React 状態非依存のため）

---

## BUG-02: useHistory.ts — ref をレンダー中に参照

### 問題

`canUndo: past.current.length > 0` はフックの return 文（レンダーフェーズ）で ref を読んでいた。
`react-hooks/refs` ルール違反。ref はレンダーフェーズに読み書きすることを React が禁止している。

### 修正

`canUndo`・`canRedo`・`maxHistory` を `useState` で管理。  
各操作（`commit`/`undo`/`redo`/`reset`/`setMaxHistory`）の末尾で `syncFlags()` を呼び state を更新。

```typescript
const syncFlags = () => {
  setCanUndo(past.current.length > 0);
  setCanRedo(future.current.length > 0);
};
```

`past`・`future` スタック自体は ref のまま（描画に関与しないため）。
can* フラグと `max` だけを state にすることで不必要な再レンダーを抑えつつ規則に準拠。

---

## SEC-01: index.html — CSP メタタグ

HTTP ヘッダーが設定できない静的配信のため `<meta http-equiv="Content-Security-Policy">` で代用。

```
default-src 'self';
script-src 'self' 'unsafe-inline';    ← Vite / React 動作に必要
style-src 'self' 'unsafe-inline';     ← ReactFlow インラインスタイルに必要
img-src 'self' data: blob:;           ← html2canvas の data URI に必要
font-src 'self' data:;
connect-src 'none';                   ← 外部 API 呼び出しなし
object-src 'none';
base-uri 'self';
```

`'unsafe-inline'` は ReactFlow がノードに動的インラインスタイルを付与するため外せない。
将来的にサーバーへの移行時は HTTP ヘッダー + nonce 方式への切り替えを検討。

---

## SEC-02 & SEC-03: バリデーション共通化

### SEC-03: importJSON — ファイル全体スキーマ検証追加

```typescript
// version 検証
if (!SUPPORTED_VERSIONS.includes(file.version)) {
  throw new Error(`未対応のバージョンです（${file.version}）...`);
}
// root 必須フィールド確認
if (!('root' in file)) {
  throw new Error('root フィールドがありません。');
}
```

### SEC-02: localStorage — 共通バリデーター適用

`validateAndNormalizeNode` を `importJSON.ts` から export し、  
`useLocalStorage.ts` の `loadFromStorage` でも同関数を使用。

```typescript
// Before
return JSON.parse(raw) as MindMapNode; // 型アサーションのみ

// After
const parsed: unknown = JSON.parse(raw);
return validateAndNormalizeNode(parsed, 0); // 構造・型・深さを検証
```

復元失敗時は `catch` で null を返し初期ルートへフォールバック（挙動は変更なし）。

---

## 最終確認

```
npm run lint  → 0 errors, 0 warnings
tsc --noEmit  → 0 errors
```

報告書の「条件付きNG」→「リリース候補」に移行可能な状態。
