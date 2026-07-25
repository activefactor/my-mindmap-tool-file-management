import { useEffect, useRef } from 'react';
import { createPortal } from 'react-dom';
import type { MindMapNode } from '../../types/mindmap';

const MAX_TEXT_LENGTH = 500;
const EDITOR_BUFFER = 16; // 入力中の文字がはみ出ないよう余裕を持たせる

// 最低幅は treeToFlow の nodeWidth に任せ、ここでは文字数固定の下限を持たない。
const MIN_LOGICAL_TEXT_WIDTH = 0;

interface FloatingEditorProps {
  node: MindMapNode;
  isRoot: boolean;
  /** Canvas 上のノード左端X座標（ReactFlow座標系） */
  nodeX: number;
  /** Canvas 上のノード上端Y座標（ReactFlow座標系） */
  nodeY: number;
  /** ReactFlow の現在ズーム倍率 */
  zoom: number;
  /** ReactFlow viewport の x オフセット（px） */
  vpX: number;
  /** ReactFlow viewport の y オフセット（px） */
  vpY: number;
  /** Canvas DOM要素の getBoundingClientRect() */
  canvasRect: DOMRect;
  /** ノードの推定幅（px、ReactFlow座標系） */
  nodeWidth: number;
  onCommit: (id: string, text: string) => void;
  onCancel: () => void;
  onDraftChange: (id: string, text: string) => void;
  /** Tab キー押下時: 現テキストを確定しつつ子ノードを作成する */
  onAddChild: (id: string, text: string) => void;
}

export const FloatingEditor = ({
  node,
  isRoot,
  nodeX,
  nodeY,
  zoom,
  vpX,
  vpY,
  canvasRect,
  nodeWidth,
  onCommit,
  onCancel,
  onDraftChange,
  onAddChild,
}: FloatingEditorProps) => {
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const draftRef = useRef(node.text);
  const isComposingRef = useRef(false);

  // Canvas 座標 → スクリーン座標（fixed）
  const screenX = canvasRect.left + nodeX * zoom + vpX;
  const screenY = canvasRect.top + nodeY * zoom + vpY;

  // ノード幅をスクリーン座標系に変換
  const screenNodeWidth = nodeWidth * zoom;

  // 水平パディング（ノードの CSS padding-left/right と同じ値をスケール）
  // spacing-6: 24px × 2 = 48px（ルート）/ spacing-4: 16px × 2 = 32px（通常）
  const logicalHPad = isRoot ? 48 : 32;
  const hPad = logicalHPad * zoom;

  // 最低幅（スクリーン座標系）: レイアウト計算済みの nodeWidth と同じ基準にする
  const minScreenWidth = Math.max(
    screenNodeWidth,
    (MIN_LOGICAL_TEXT_WIDTH + logicalHPad + EDITOR_BUFFER) * zoom,
  );

  /**
   * canvas.measureText を使って現在のドラフトテキストの幅（px）を測る。
   * textarea がマウント済みであれば実際のフォントを使用。
   */
  const measureText = (text: string): number => {
    const el = textareaRef.current;
    if (!el) return 0;
    if (!canvasRef.current) {
      canvasRef.current = document.createElement('canvas');
    }
    const ctx = canvasRef.current.getContext('2d');
    if (!ctx) return 0;
    ctx.font = getComputedStyle(el).font;
    const lines = text.split('\n');
    return lines.reduce((max, line) => Math.max(max, ctx.measureText(line).width), 0);
  };

  /** textarea の幅・高さを動的に更新する */
  const updateWidth = () => {
    const el = textareaRef.current;
    if (!el) return;
    const textWidth = measureText(draftRef.current);
    // テキスト幅 + バッファ、最低幅、ノード元幅 の最大値
    const required = Math.max(textWidth + hPad + EDITOR_BUFFER, minScreenWidth);
    el.style.width = `${required}px`;

    // 幅確定後に高さを再計算
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;

    // Canvas 側にも幅変化を通知（レイアウト推定用）
    onDraftChange(node.id, draftRef.current);
  };

  // マウント時：初期値・フォーカス・キャレット末尾・サイズ初期化
  useEffect(() => {
    const el = textareaRef.current;
    if (!el) return;
    draftRef.current = node.text;
    el.value = node.text;
    // ブラウザがフォント計算を終えてから幅・高さを初期化
    requestAnimationFrame(() => {
      updateWidth();
      el.focus();
      // 新規作成直後のノード（デフォルトテキスト）は全選択 → そのまま打ち替えられる
      // 既存ノードの編集は末尾にカーソルを置く
      if (node.text === '新しいノード') {
        el.select();
      } else {
        el.setSelectionRange(el.value.length, el.value.length);
      }
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    // IME 中判定: e.nativeEvent.isComposing は環境（特に Mac の Google 日本語入力 /
    // ことえり）によっては、変換確定の Enter 押下時点で既に false になっており、
    // compositionend より前に keydown が走るケースがある。その場合 draftRef.current
    // はまだ変換前のテキストのままで、誤って未確定文字で onCommit してしまう。
    // isComposingRef (compositionstart→true / compositionend→false) と keyCode 229
    // を併用して、より確実に IME 中を検出する。
    const isIMEActive =
      e.nativeEvent.isComposing ||
      isComposingRef.current ||
      e.keyCode === 229;

    if (e.key === 'Enter') {
      if (isIMEActive) return;
      if (e.shiftKey) return; // Shift+Enter は改行
      e.preventDefault();
      // draftRef ではなく DOM の最新値を読む（同期ずれに対する保険）
      onCommit(node.id, e.currentTarget.value);
    }
    if (e.key === 'Tab') {
      if (isIMEActive) return;
      e.preventDefault(); // ブラウザのフォーカス移動を抑止
      onAddChild(node.id, e.currentTarget.value);
    }
    if (e.key === 'Escape') {
      onCancel();
    }
    e.stopPropagation();
  };

  const handleChange = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
    draftRef.current = e.target.value;
    updateWidth();
  };

  const handleCompositionStart = () => {
    isComposingRef.current = true;
  };

  const handleCompositionEnd = () => {
    isComposingRef.current = false;
    const el = textareaRef.current;
    if (!el) return;
    draftRef.current = el.value;
    // 即時に親へ draft を通知して editingDraft を最新化する。
    // rAF 越しにすると、ユーザーが Enter を素早く押した場合に
    // 「中間 IME 候補」が editingDraft に残ったまま commit が走り、
    // 表示側に古い文字列が描画される race を引き起こすため。
    updateWidth();
  };

  const handleBlur = () => {
    if (isComposingRef.current) return;
    const el = textareaRef.current;
    onCommit(node.id, el ? el.value : draftRef.current);
  };

  // パディング（CSS変数と同値をズームスケールしてインラインで再現）
  const paddingV = isRoot ? 12 : 8;
  const paddingH = isRoot ? 24 : 16;

  // createPortal で document.body 直下にレンダリング:
  // → ReactFlow の stacking context (transform) を完全に脱出し、
  //   z-index が他ノードに邪魔されなくなる
  return createPortal(
    <textarea
      ref={textareaRef}
      onChange={handleChange}
      onKeyDown={handleKeyDown}
      onCompositionStart={handleCompositionStart}
      onCompositionEnd={handleCompositionEnd}
      onBlur={handleBlur}
      maxLength={MAX_TEXT_LENGTH}
      rows={1}
      style={{
        position: 'fixed',
        left: `${screenX}px`,
        top: `${screenY}px`,
        width: `${minScreenWidth}px`,   // 初期幅（updateWidth で上書きされる）
        minWidth: `${minScreenWidth}px`,
        zIndex: 9999,
        font: 'inherit',
        fontSize: `${(isRoot ? 20 : 14) * zoom}px`,
        fontWeight: isRoot ? 700 : 400,
        fontFamily: 'var(--font-family-base)',
        lineHeight: `${(isRoot ? 28 : 22) * zoom / ((isRoot ? 20 : 14) * zoom)}`,
        color: isRoot ? 'var(--color-text-on-primary)' : 'var(--color-text-primary)',
        background: isRoot ? 'var(--color-bg-node-root)' : 'var(--color-bg-node)',
        border: '2px solid var(--color-primary-400)',
        borderRadius: `${(isRoot ? 12 : 8) * zoom}px`,
        boxShadow: '0 0 0 2px var(--color-primary-500), var(--shadow-lg)',
        padding: `${paddingV * zoom}px ${paddingH * zoom}px`,
        resize: 'none',
        overflow: 'hidden',
        outline: 'none',
        boxSizing: 'border-box',
        wordBreak: 'break-all',
        whiteSpace: 'pre-wrap',
      }}
    />,
    document.body,
  );
};
