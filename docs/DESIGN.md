# DESIGN.md — マインドマップツール デザイン定義書

**バージョン**: 1.0  
**作成日**: 2026-04-11

> このファイルはUIの見た目に関するすべての定義をまとめた単一の情報源（Single Source of Truth）です。
> デザインを変更する場合はこのファイルを編集し、コンポーネントはここで定義された値のみを参照します。
> テーマの変更・ブランドリビルドはこのファイルの更新で完結させることを目指します。

---

## 1. デザイン原則

1. **シンプル**: 余計な装飾を排除し、情報構造に集中させる
2. **一貫性**: 同じ要素には同じ見た目を使い、予測可能なUIを提供する
3. **再現性**: すべての値はこのファイルに定義し、コンポーネント内にハードコードしない
4. **アクセシビリティ**: コントラスト比 4.5:1 以上を維持する（WCAG 2.1 AA）

---

## 2. カラートークン

### 2.1 ブランドカラー

```
--color-primary-50:   #EFF6FF
--color-primary-100:  #DBEAFE
--color-primary-200:  #BFDBFE
--color-primary-300:  #93C5FD
--color-primary-400:  #60A5FA
--color-primary-500:  #3B82F6   ← メインブランドカラー
--color-primary-600:  #2563EB
--color-primary-700:  #1D4ED8
--color-primary-800:  #1E40AF
--color-primary-900:  #1E3A8A
```

### 2.2 グレースケール

```
--color-gray-0:    #FFFFFF   ← 背景（白）
--color-gray-50:   #F9FAFB
--color-gray-100:  #F3F4F6
--color-gray-200:  #E5E7EB
--color-gray-300:  #D1D5DB
--color-gray-400:  #9CA3AF
--color-gray-500:  #6B7280
--color-gray-600:  #4B5563
--color-gray-700:  #374151
--color-gray-800:  #1F2937
--color-gray-900:  #111827   ← テキスト（黒）
```

### 2.3 セマンティックカラー

```
--color-bg-canvas:      var(--color-gray-100)   ← キャンバス背景
--color-bg-toolbar:     var(--color-gray-0)      ← ツールバー背景
--color-bg-node:        var(--color-gray-0)      ← ノード背景
--color-bg-node-root:   var(--color-primary-500) ← ルートノード背景
--color-bg-node-hover:  var(--color-primary-50)  ← ノードホバー時
--color-bg-node-selected: var(--color-primary-100) ← ノード選択時

--color-text-primary:   var(--color-gray-900)    ← 主要テキスト
--color-text-secondary: var(--color-gray-500)    ← 補助テキスト
--color-text-on-primary: var(--color-gray-0)     ← プライマリ背景上のテキスト

--color-border-default: var(--color-gray-200)    ← 標準ボーダー
--color-border-node:    var(--color-primary-300) ← ノードボーダー
--color-border-node-selected: var(--color-primary-500) ← 選択ノードボーダー

--color-edge:           var(--color-gray-400)    ← 接続線
--color-edge-hover:     var(--color-primary-400) ← 接続線ホバー

--color-danger:         #EF4444   ← 削除・エラー
--color-danger-hover:   #DC2626
--color-success:        #22C55E   ← 成功
--color-warning:        #F59E0B   ← 警告
```

---

## 3. タイポグラフィトークン

### 3.1 フォントファミリー

```
--font-family-base:  'Noto Sans JP', 'Hiragino Kaku Gothic ProN', 'Yu Gothic', sans-serif
--font-family-mono:  'JetBrains Mono', 'Source Code Pro', monospace
```

### 3.2 フォントサイズ

```
--font-size-xs:   11px
--font-size-sm:   12px
--font-size-base: 14px   ← ノードテキスト・UIの基本サイズ
--font-size-md:   16px
--font-size-lg:   18px
--font-size-xl:   20px   ← ルートノードテキスト
--font-size-2xl:  24px
```

### 3.3 フォントウェイト

```
--font-weight-normal:   400
--font-weight-medium:   500
--font-weight-semibold: 600
--font-weight-bold:     700
```

### 3.4 行間

```
--line-height-tight:  1.2
--line-height-base:   1.5
--line-height-loose:  1.8
```

---

## 4. スペーシングトークン

8px ベースのスペーシングスケール。

```
--spacing-1:   4px
--spacing-2:   8px
--spacing-3:  12px
--spacing-4:  16px
--spacing-5:  20px
--spacing-6:  24px
--spacing-8:  32px
--spacing-10: 40px
--spacing-12: 48px
--spacing-16: 64px
```

---

## 5. ボーダー・角丸トークン

```
--radius-sm:   4px
--radius-md:   8px   ← ノード・ボタン標準
--radius-lg:  12px
--radius-xl:  16px
--radius-full: 9999px ← ピル型・アイコンボタン

--border-width-default: 1px
--border-width-thick:   2px
```

---

## 6. シャドウトークン

```
--shadow-sm:  0 1px 2px rgba(0, 0, 0, 0.05)
--shadow-md:  0 2px 8px rgba(0, 0, 0, 0.08)   ← ノード標準
--shadow-lg:  0 4px 16px rgba(0, 0, 0, 0.12)  ← ノード選択時
--shadow-xl:  0 8px 32px rgba(0, 0, 0, 0.16)  ← ドラッグ中
```

---

## 7. アニメーション・トランジション

```
--transition-fast:   100ms ease
--transition-base:   200ms ease
--transition-slow:   300ms ease-in-out

--easing-default:    ease
--easing-in:         ease-in
--easing-out:        ease-out
--easing-spring:     cubic-bezier(0.34, 1.56, 0.64, 1)  ← ノード追加時
```

---

## 8. コンポーネント仕様

### 8.1 ノード

#### 標準ノード

| プロパティ | 値 |
|-----------|-----|
| 背景色 | `--color-bg-node` |
| ボーダー | `1px solid --color-border-node` |
| 角丸 | `--radius-md` |
| シャドウ | `--shadow-md` |
| パディング | `--spacing-2` × `--spacing-4`（縦×横） |
| テキストサイズ | `--font-size-base` |
| テキスト色 | `--color-text-primary` |
| 最小幅 | 80px |
| 最大幅 | 240px |

#### 選択時

| プロパティ | 値 |
|-----------|-----|
| ボーダー | `2px solid --color-border-node-selected` |
| 背景色 | `--color-bg-node-selected` |
| シャドウ | `--shadow-lg` |

#### ルートノード

| プロパティ | 値 |
|-----------|-----|
| 背景色 | `--color-bg-node-root` |
| テキスト色 | `--color-text-on-primary` |
| テキストサイズ | `--font-size-xl` |
| フォントウェイト | `--font-weight-bold` |
| 角丸 | `--radius-lg` |
| パディング | `--spacing-3` × `--spacing-6` |

#### ドラッグ中

| プロパティ | 値 |
|-----------|-----|
| シャドウ | `--shadow-xl` |
| 透明度 | 0.9 |
| スケール | 1.03 |

### 8.2 接続線（エッジ）

| プロパティ | 値 |
|-----------|-----|
| 線の色 | `--color-edge` |
| 線の太さ | 2px |
| 線のスタイル | 曲線（cubic bezier） |
| ホバー時色 | `--color-edge-hover` |

### 8.3 ツールバー

| プロパティ | 値 |
|-----------|-----|
| 背景色 | `--color-bg-toolbar` |
| 高さ | 48px |
| ボーダー下 | `1px solid --color-border-default` |
| シャドウ | `--shadow-sm` |
| パディング | `0 --spacing-4` |

### 8.4 ボタン（ツールバー）

| 状態 | 背景色 | テキスト色 |
|------|--------|----------|
| 通常 | transparent | `--color-text-primary` |
| ホバー | `--color-gray-100` | `--color-text-primary` |
| アクティブ | `--color-gray-200` | `--color-text-primary` |
| 無効 | transparent | `--color-gray-300` |

```
padding:       --spacing-2 --spacing-3
border-radius: --radius-md
font-size:     --font-size-sm
font-weight:   --font-weight-medium
transition:    --transition-fast
```

### 8.5 キャンバス

| プロパティ | 値 |
|-----------|-----|
| 背景色 | `--color-bg-canvas` |
| グリッドパターン | ドット（2px, 間隔20px, `--color-gray-300`） |

### 8.6 右クリックメニュー（コンテキストメニュー）

| プロパティ | 値 |
|-----------|-----|
| 背景色 | `--color-bg-toolbar` |
| ボーダー | `1px solid --color-border-default` |
| 角丸 | `--radius-md` |
| シャドウ | `--shadow-lg` |
| 項目パディング | `--spacing-2` × `--spacing-4` |
| 項目フォント | `--font-size-sm` |
| 項目ホバー背景 | `--color-gray-100` |

---

## 9. レイアウト

### 9.1 全体レイアウト

```
┌─────────────── 100vw ──────────────────┐
│  Toolbar（高さ: 48px）                  │  z-index: 10
├────────────────────────────────────────┤
│                                        │
│  Canvas（高さ: calc(100vh - 48px)）     │  z-index: 1
│                                        │
└────────────────────────────────────────┘
```

### 9.2 z-index スタック

```
--z-canvas:       1
--z-canvas-node:  2
--z-toolbar:     10
--z-context-menu: 100
--z-modal:        200
--z-toast:        300
```

---

## 10. テーマ変更ガイドライン

このファイルを更新してテーマを変更する際の手順：

1. セクション 2（カラートークン）のブランドカラーを変更する
2. セクション 3（タイポグラフィ）のフォントファミリーを変更する
3. セクション 8（コンポーネント仕様）でセマンティックカラーの参照先が正しいか確認する
4. コンポーネント内のスタイルは CSS カスタムプロパティ（変数）経由で適用するため、変数名が変わらなければコンポーネントの編集は不要

> コンポーネント内に直接カラーコードや px 値をハードコードすることを禁止する。
> すべての値はこのファイルで定義された CSS カスタムプロパティを使用すること。

---

## 11. 変更履歴

| バージョン | 日付 | 変更内容 |
|-----------|------|---------|
| 1.0 | 2026-04-11 | 初版作成（一般的なスタイル） |
