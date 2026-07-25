import type { Node, Edge } from 'reactflow';
import type { MindMapNode, NodeData } from '../types/mindmap';

// ============================================================
// レイアウト定数 — DESIGN.md / 基本設計書のレイアウト設計と対応
// ============================================================
const ROOT_MIN_WIDTH = 200;     // ルートノードの最小幅
const H_GAP = 44;               // ノード右端〜子ノード左端の水平余白
const V_GAP = 20;               // ノード間の垂直余白
const LINE_HEIGHT = 22;         // 14px フォントの行高
const ROOT_LINE_HEIGHT = 32;    // 20px bold フォントの行高（ルート）
const PADDING_V = 16;           // 上下パディング合計（8px × 2）
const ROOT_PADDING_V = 24;      // ルート上下パディング合計（12px × 2）
const PADDING_H = 32;           // 水平パディング合計（16px × 2）
const ROOT_PADDING_H = 48;      // ルート水平パディング合計（spacing-6: 24px × 2）
const ESTIMATE_BUFFER = 16;     // FloatingEditor の EDITOR_BUFFER と合わせる
const MIN_NODE_WIDTH = 60;      // 非ルートノードの最小幅
const MIN_HEIGHT = 40;

// CSS変数と同じフォントを使ってcanvas.measureTextで実測する
const FONT_BASE = '14px "Noto Sans JP", "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif';
const FONT_ROOT = 'bold 20px "Noto Sans JP", "Hiragino Kaku Gothic ProN", "Yu Gothic", sans-serif';

// モジュールレベルのシングルトン canvas（ブラウザ環境でのみ使用）
let _measureCtx: CanvasRenderingContext2D | null = null;
const getMeasureCtx = (): CanvasRenderingContext2D | null => {
  if (typeof document === 'undefined') return null;
  if (!_measureCtx) {
    _measureCtx = document.createElement('canvas').getContext('2d');
  }
  return _measureCtx;
};

/** 1行のピクセル幅を canvas.measureText で実測する（フォールバック付き） */
const measureLineWidth = (line: string, isRoot: boolean): number => {
  const ctx = getMeasureCtx();
  if (ctx) {
    ctx.font = isRoot ? FONT_ROOT : FONT_BASE;
    return ctx.measureText(line).width;
  }
  // フォールバック: 全角/半角の簡易推定
  let w = 0;
  for (const char of line) {
    const code = char.codePointAt(0) ?? 0;
    const isWide =
      (code >= 0x3000 && code <= 0x9FFF) ||
      (code >= 0xAC00 && code <= 0xD7AF) ||
      (code >= 0xF900 && code <= 0xFAFF) ||
      (code >= 0xFF01 && code <= 0xFF60) ||
      (code >= 0xFFE0 && code <= 0xFFE6);
    w += isWide ? (isRoot ? 20 : 14) : (isRoot ? 12 : 8);
  }
  return w;
};

/**
 * テキストからノードの推定幅を計算する。
 * canvas.measureText で実フォントを使って正確に計算。
 */
const estimateWidth = (text: string, isRoot: boolean): number => {
  const paddingH = isRoot ? ROOT_PADDING_H : PADDING_H;
  const minWidth = isRoot ? ROOT_MIN_WIDTH : MIN_NODE_WIDTH;
  if (!text) return minWidth;
  const maxLineWidth = text.split('\n').reduce(
    (max, line) => Math.max(max, measureLineWidth(line, isRoot)),
    0,
  );
  return Math.max(maxLineWidth + paddingH + ESTIMATE_BUFFER, minWidth);
};

/**
 * テキストからノードの推定高さを計算する。
 * 改行文字と、推定幅に基づく折り返しを両方考慮。
 */
const estimateHeight = (text: string, isRoot: boolean): number => {
  if (!text) return MIN_HEIGHT;
  const lineH = isRoot ? ROOT_LINE_HEIGHT : LINE_HEIGHT;
  const paddingV = isRoot ? ROOT_PADDING_V : PADDING_V;
  const paddingH = isRoot ? ROOT_PADDING_H : PADDING_H;

  const nodeWidth = estimateWidth(text, isRoot);
  const textAreaWidth = Math.max(1, nodeWidth - paddingH);
  const totalLines = text.split('\n').reduce((sum, line) => {
    const lineWidth = measureLineWidth(line, isRoot);
    return sum + Math.max(1, Math.ceil(lineWidth / textAreaWidth));
  }, 0);
  return Math.max(MIN_HEIGHT, totalLines * lineH + paddingV);
};

interface LayoutNode {
  id: string;
  x: number;
  y: number;
  height: number;
  width: number;
}

const calcLayout = (
  node: MindMapNode,
  depth: number,
  yOffset: number,
  nodeX: number,
  result: LayoutNode[],
): number => {
  const isRoot = depth === 0;
  const width = estimateWidth(node.text, isRoot);
  const height = estimateHeight(node.text, isRoot);
  const visibleChildren = node.collapsed ? [] : node.children;
  const childX = nodeX + width + H_GAP;

  if (visibleChildren.length === 0) {
    result.push({ id: node.id, x: nodeX, y: yOffset, height, width });
    return height;
  }

  let childY = yOffset;
  let totalChildrenHeight = 0;
  for (const child of visibleChildren) {
    const h = calcLayout(child, depth + 1, childY, childX, result);
    childY += h + V_GAP;
    totalChildrenHeight += h + V_GAP;
  }
  totalChildrenHeight -= V_GAP;

  const firstChild = result.find((r) => r.id === visibleChildren[0].id)!;
  const lastChild = result.find(
    (r) => r.id === visibleChildren[visibleChildren.length - 1].id,
  )!;
  const childrenMidY = (firstChild.y + lastChild.y + lastChild.height) / 2;
  const nodeY = childrenMidY - height / 2;

  result.push({ id: node.id, x: nodeX, y: nodeY, height, width });
  return Math.max(totalChildrenHeight, height);
};

export const treeToFlow = (
  root: MindMapNode,
  editingId: string | null,
  editingDraft: string,
  selectedId: string | null,
  dragTargetId: string | null,
  edgeColor: string,
  buttonColor: string,
  callbacks: Omit<NodeData, 'node' | 'isRoot' | 'isEditing' | 'isDragTarget' | 'isSelected' | 'nodeWidth' | 'nodeHeight' | 'buttonColor'>,
): { nodes: Node<NodeData>[]; edges: Edge[] } => {
  // 編集中ノードのテキストを draft で上書きしたツリーを作る（入力中の幅・高さ推定に使用）
  const applyDraft = (node: MindMapNode): MindMapNode =>
    node.id === editingId
      ? { ...node, text: editingDraft, children: node.children.map(applyDraft) }
      : { ...node, children: node.children.map(applyDraft) };

  const effectiveRoot = editingId ? applyDraft(root) : root;

  const layoutResult: LayoutNode[] = [];
  calcLayout(effectiveRoot, 0, 0, 0, layoutResult);
  const posMap = new Map(layoutResult.map((r) => [r.id, r]));

  // NodeData には元データを渡し、レイアウトだけ draft 反映済みにする
  const originalMap = new Map<string, MindMapNode>();
  const collectOriginals = (node: MindMapNode) => {
    originalMap.set(node.id, node);
    node.children.forEach(collectOriginals);
  };
  collectOriginals(root);

  const nodes: Node<NodeData>[] = [];
  const edges: Edge[] = [];

  const traverse = (node: MindMapNode, parentId: string | null) => {
    const layout = posMap.get(node.id) ?? { x: 0, y: 0, height: MIN_HEIGHT, width: MIN_NODE_WIDTH };
    const originalNode = originalMap.get(node.id) ?? node;
    const nodeForRender = node.id === editingId ? node : originalNode;

    nodes.push({
      id: node.id,
      type: 'mindMapNode',
      position: { x: layout.x, y: layout.y },
      data: {
        node: nodeForRender,
        isRoot: parentId === null,
        isEditing: node.id === editingId,
        isDragTarget: node.id === dragTargetId,
        isSelected: node.id === selectedId,
        nodeWidth: layout.width,
        nodeHeight: layout.height,
        buttonColor,
        ...callbacks,
      },
    });

    if (parentId) {
      edges.push({
        id: `e-${parentId}-${node.id}`,
        source: parentId,
        target: node.id,
        type: 'mindMapEdge',
        data: { edgeColor },
      });
    }

    if (!node.collapsed) {
      for (const child of node.children) {
        traverse(child, node.id);
      }
    }
  };

  traverse(effectiveRoot, null);
  return { nodes, edges };
};
