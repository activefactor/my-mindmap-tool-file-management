# 開発ログ: TypeScriptエラー — moveNode未参照 / fitView未使用

**日付**: 2026-04-11  
**担当**: -  
**関連機能**: App.tsx / MindMapCanvas.tsx  
**エラー種別**: ビルドエラー（TSエラー2件）

---

## 発生したエラー

```
src/App.tsx(144,23): error TS2304: Cannot find name 'moveNode'.
src/components/Canvas/MindMapCanvas.tsx(47,11): error TS6133: 'fitView' is declared but its value is never read.
```

---

## 原因

### エラー1: `moveNode` 未定義
`useMindMap` フックは `moveNode` を返すが、`App.tsx` の destructure で取り出し忘れていた。

```typescript
// Before（moveNode が含まれていない）
const { addChild, addSibling, deleteNode, updateText, toggleCollapse } = useMindMap(...)

// After
const { addChild, addSibling, deleteNode, updateText, toggleCollapse, moveNode } = useMindMap(...)
```

### エラー2: `fitView` 未使用
`MindMapCanvas.tsx` のリファクタリング時に、`useReactFlow()` から `fitView` を destructure したまま、
その使用箇所を削除した（`fitView` は `App.tsx` 内で別途使っているため Canvas では不要になった）。

```typescript
// Before
const { fitView, getNodes } = useReactFlow();

// After
const { getNodes } = useReactFlow();
```

---

## 教訓

- フックの戻り値を途中で追加した場合、呼び出し元の destructure も同時に更新すること
- コンポーネント分割時、props/変数の使用箇所を整理したら未使用変数チェックをすること
- `tsc --noEmit` でビルド前に型チェックを通す習慣をつける

---

## 修正内容

- `App.tsx`: `moveNode` を destructure に追加
- `MindMapCanvas.tsx`: `fitView` を destructure から削除
