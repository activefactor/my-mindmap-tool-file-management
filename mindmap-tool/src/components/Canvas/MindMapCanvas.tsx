import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import ReactFlow, {
  Background,
  BackgroundVariant,
  Controls,
  useEdgesState,
  useNodesState,
  useReactFlow,
  useViewport,
} from 'reactflow';
import type { Node as RFNode } from 'reactflow';
import 'reactflow/dist/style.css';

import type { MindMapNode, ContextMenuState } from '../../types/mindmap';
import { MindMapNodeComponent } from './MindMapNodeComponent';
import { MindMapEdge } from './MindMapEdge';
import { FloatingEditor } from './FloatingEditor';
import { treeToFlow } from '../../utils/treeToFlow';

const nodeTypes = { mindMapNode: MindMapNodeComponent } as const;
const edgeTypes = { mindMapEdge: MindMapEdge } as const;

const NODE_HEIGHT = 40;
const DROP_PADDING = 20;

interface MindMapCanvasProps {
  root: MindMapNode;
  selectedId: string | null;
  editingId: string | null;
  edgeColor: string;
  buttonColor: string;
  onSelect: (id: string | null) => void;
  onStartEdit: (id: string) => void;
  onCommitEdit: (id: string, text: string) => void;
  onCancelEdit: () => void;
  onContextMenu: (state: ContextMenuState) => void;
  onToggleCollapse: (id: string) => void;
  onMoveNode: (nodeId: string, newParentId: string) => void;
  onAddChild: (id: string, text: string) => void;
}

// FloatingEditor をレンダリングする内部コンポーネント（useViewport が ReactFlow コンテキスト内で動作するため）
const CanvasInner = ({
  root,
  selectedId,
  editingId,
  edgeColor,
  buttonColor,
  onSelect,
  onStartEdit,
  onCommitEdit,
  onCancelEdit,
  onContextMenu,
  onToggleCollapse,
  onMoveNode,
  onAddChild,
  canvasWrapRef,
}: MindMapCanvasProps & { canvasWrapRef: React.RefObject<HTMLDivElement | null> }) => {
  const { getNodes } = useReactFlow();
  const viewport = useViewport();
  const [dragTargetId, setDragTargetId] = useState<string | null>(null);
  const [layoutTick, setLayoutTick] = useState(0);

  // 編集中ノードのドラフトテキスト（リアルタイムレイアウト更新用）。
  // id を一緒に持つことで、編集開始直後の未入力状態と、ユーザーが本当に空文字へ削除した状態を区別する。
  const [editingDraft, setEditingDraft] = useState<{ id: string | null; text: string }>({ id: null, text: '' });

  const findNode = useCallback((node: MindMapNode, targetId: string): MindMapNode | null =>
    node.id === targetId ? node : node.children.map((child) => findNode(child, targetId)).find(Boolean) ?? null,
  []);

  const editingSourceText = useMemo(() => {
    if (!editingId) return '';
    return findNode(root, editingId)?.text ?? '';
  }, [editingId, findNode, root]);

  const effectiveEditingDraft = editingId
    ? editingDraft.id === editingId
      ? editingDraft.text
      : editingSourceText
    : '';

  useEffect(() => {
    if (!editingId) setEditingDraft({ id: null, text: '' });
  }, [editingId]);

  const handleDraftChange = useCallback((id: string, text: string) => {
    setEditingDraft({ id, text });
  }, []);

  const handleContextMenu = useCallback(
    (e: React.MouseEvent, id: string) => {
      e.preventDefault();
      onContextMenu({ nodeId: id, x: e.clientX, y: e.clientY });
    },
    [onContextMenu],
  );

  const callbacks = useMemo(
    () => ({
      onStartEdit,
      onContextMenu: handleContextMenu,
      onToggleCollapse,
    }),
    [onStartEdit, handleContextMenu, onToggleCollapse],
  );

  // root / 編集状態などから ReactFlow 用の nodes/edges を算出（純粋関数）。
  const computed = useMemo(
    () => treeToFlow(root, editingId, effectiveEditingDraft, selectedId, dragTargetId, edgeColor, buttonColor, callbacks),
    // layoutTick はスナップバック強制に使用
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [root, editingId, effectiveEditingDraft, selectedId, dragTargetId, edgeColor, buttonColor, callbacks, layoutTick],
  );

  // ReactFlow の内部 store と props のズレを防ぐため、useNodesState 経由で明示的に同期する。
  // 以前は `<ReactFlow nodes={computed.nodes}>` のように props 直接渡しだったが、その方式だと
  // 内部 store の data が新しい props で上書きされないケース（特に同一 id ノードの data を入れ替える
  // パターン）が発生し、編集 commit 後もノードに古いテキストが残る不具合が起きていた。
  const [nodes, setNodes, onNodesChange] = useNodesState(computed.nodes);
  const [edges, setEdges, onEdgesChange] = useEdgesState(computed.edges);

  useEffect(() => {
    setNodes(computed.nodes);
    setEdges(computed.edges);
  }, [computed.nodes, computed.edges, setNodes, setEdges]);

  // ドラッグ中: ドロップ候補ノードを検出してハイライト
  const handleNodeDrag = useCallback(
    (_: React.MouseEvent, draggedNode: RFNode) => {
      const allNodes = getNodes();
      const dragW = (draggedNode.data as { nodeWidth?: number })?.nodeWidth ?? 160;
      const dragCenterX = draggedNode.position.x + dragW / 2;
      const dragCenterY = draggedNode.position.y + NODE_HEIGHT / 2;

      let closestId: string | null = null;
      let minDist = Infinity;

      for (const n of allNodes) {
        if (n.id === draggedNode.id) continue;
        const nW = (n.data as { nodeWidth?: number })?.nodeWidth ?? 160;
        const left = n.position.x - DROP_PADDING;
        const right = n.position.x + nW + DROP_PADDING;
        const top = n.position.y - DROP_PADDING;
        const bottom = n.position.y + NODE_HEIGHT + DROP_PADDING;

        if (dragCenterX >= left && dragCenterX <= right && dragCenterY >= top && dragCenterY <= bottom) {
          const dist = Math.hypot(
            dragCenterX - (n.position.x + nW / 2),
            dragCenterY - (n.position.y + NODE_HEIGHT / 2),
          );
          if (dist < minDist) { minDist = dist; closestId = n.id; }
        }
      }

      setDragTargetId(closestId);
    },
    [getNodes],
  );

  // ドロップ確定: 親子変更 or スナップバック
  const handleNodeDragStop = useCallback(
    (_: React.MouseEvent, draggedNode: RFNode) => {
      if (dragTargetId && dragTargetId !== draggedNode.id) {
        onMoveNode(draggedNode.id, dragTargetId);
      } else {
        setLayoutTick((t) => t + 1);
      }
      setDragTargetId(null);
    },
    [dragTargetId, onMoveNode],
  );

  // 編集中ノードの情報（FloatingEditor 表示用）。
  // 表示の最新性が重要なので state ではなく computed の方を引く（state は 1tick 遅れることがある）。
  const editingNode = useMemo(() => {
    if (!editingId) return null;
    const rfNode = computed.nodes.find((n) => n.id === editingId);
    if (!rfNode) return null;
    return rfNode;
  }, [editingId, computed.nodes]);

  return (
    <>
      <ReactFlow
        nodes={nodes}
        edges={edges}
        onNodesChange={onNodesChange}
        onEdgesChange={onEdgesChange}
        nodeTypes={nodeTypes}
        edgeTypes={edgeTypes}
        onNodeClick={(_, node) => onSelect(node.id)}
        onPaneClick={() => onSelect(null)}
        onNodeDrag={handleNodeDrag}
        onNodeDragStop={handleNodeDragStop}
        // 編集中はパン・ズームを無効化（FloatingEditor の位置がずれるのを防ぐ）
        panOnDrag={!editingId}
        zoomOnScroll={!editingId}
        zoomOnPinch={!editingId}
        zoomOnDoubleClick={false}
        fitView
        fitViewOptions={{ padding: 0.2 }}
        minZoom={0.2}
        maxZoom={3}
        deleteKeyCode={null}
        style={{ background: 'var(--color-bg-canvas)' }}
      >
        <Background
          variant={BackgroundVariant.Dots}
          gap={20}
          size={2}
          color="var(--color-gray-300)"
        />
        <Controls showInteractive={false} />
      </ReactFlow>

      {/* FloatingEditor: ReactFlow の transform 外に配置して IME 座標を正確にする */}
      {editingNode && canvasWrapRef.current && (() => {
        const canvasRect = canvasWrapRef.current.getBoundingClientRect();
        const editingNodeData = editingNode.data as { node: MindMapNode; isRoot: boolean; nodeWidth: number };
        return (
          <FloatingEditor
            key={editingId}
            node={editingNodeData.node}
            isRoot={editingNodeData.isRoot}
            nodeX={editingNode.position.x}
            nodeY={editingNode.position.y}
            zoom={viewport.zoom}
            vpX={viewport.x}
            vpY={viewport.y}
            canvasRect={canvasRect}
            nodeWidth={editingNodeData.nodeWidth}
            onCommit={(id, text) => {
              // commit と同時に editingDraft を即時クリア。
              // useEffect 経由の遅延クリアだと、commit 後に走り得る
              // input/composition 由来の onDraftChange で古い候補値が
              // 再代入され、表示側に stale な text が描画されるレースを防ぐ。
              setEditingDraft({ id: null, text: '' });
              onCommitEdit(id, text);
            }}
            onCancel={() => {
              setEditingDraft({ id: null, text: '' });
              onCancelEdit();
            }}
            onDraftChange={handleDraftChange}
            onAddChild={(id, text) => {
              setEditingDraft({ id: null, text: '' });
              onAddChild(id, text);
            }}
          />
        );
      })()}
    </>
  );
};

export const MindMapCanvas = (props: MindMapCanvasProps) => {
  const canvasWrapRef = useRef<HTMLDivElement>(null);

  return (
    <div ref={canvasWrapRef} style={{ width: '100%', height: '100%' }}>
      <CanvasInner {...props} canvasWrapRef={canvasWrapRef} />
    </div>
  );
};
