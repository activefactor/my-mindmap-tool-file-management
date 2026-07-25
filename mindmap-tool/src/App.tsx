import { useCallback, useRef, useState } from 'react';
import { ReactFlowProvider, useReactFlow } from 'reactflow';

import { Toolbar } from './components/Toolbar/Toolbar';
import { MindMapCanvas } from './components/Canvas/MindMapCanvas';
import { NodeContextMenu } from './components/ContextMenu/NodeContextMenu';

import { useHistory } from './hooks/useHistory';
import { useMindMap } from './hooks/useMindMap';
import { useKeyboard } from './hooks/useKeyboard';
import { useAutoSave, loadFromStorage } from './hooks/useLocalStorage';

import type { MindMapNode, ContextMenuState, MapTheme } from './types/mindmap';
import { generateId } from './utils/generateId';
import { nodeToText } from './utils/exportText';
import { parseIndentText } from './utils/importText';
import { nodeToClipboardJSON, parseClipboardJSON } from './utils/clipboardNode';
import { exportPNG } from './utils/exportPNG';
import { exportPDF } from './utils/exportPDF';

const createInitialRoot = (): MindMapNode => ({
  id: generateId(),
  text: 'メインテーマ',
  children: [],
  collapsed: false,
});

const loadInitial = (): MindMapNode => loadFromStorage() ?? createInitialRoot();

const findNodeById = (node: MindMapNode, id: string): MindMapNode | null =>
  node.id === id ? node : node.children.map((child) => findNodeById(child, id)).find(Boolean) ?? null;

// ReactFlow の fitView は Provider 内でしか使えないため内部コンポーネントに分離
const AppInner = () => {
  const { fitView } = useReactFlow();

  const { current, commit, undo, redo, reset, canUndo, canRedo } = useHistory(loadInitial());
  const { addChild, addSibling, deleteNode, updateText, toggleCollapse, moveNode, pasteNode, commitAndAddChild } = useMindMap(current, commit);

  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [contextMenu, setContextMenu] = useState<ContextMenuState | null>(null);
  const [themeColor, setThemeColor] = useState('#9CA3AF');
  const pendingFocusId = useRef<string | null>(null);

  useAutoSave(current);

  // --- 編集 ---
  const handleStartEdit = useCallback((id: string) => {
    setContextMenu(null);
    setEditingId(id);
  }, []);

  const handleCommitEdit = useCallback((id: string, text: string) => {
    if (text.trim()) updateText(id, text.trim());
    setEditingId(null);
  }, [updateText]);

  const handleCancelEdit = useCallback(() => setEditingId(null), []);

  // --- Tab キー: 編集中テキストを確定しつつ子ノードを追加 ---
  const handleAddChildFromEdit = useCallback((editingNodeId: string, text: string) => {
    const newId = commitAndAddChild(editingNodeId, text);
    if (newId) {
      setSelectedId(newId);
      setEditingId(newId);
    }
  }, [commitAndAddChild]);

  // --- ノード追加（追加後に新ノードを編集モードに） ---
  // targetId を明示渡しすることで右クリックメニューの非同期state問題を回避
  const handleAddChild = useCallback((targetId?: string) => {
    const id = targetId ?? selectedId ?? current.id;
    const newId = addChild(id);
    if (newId) {
      setSelectedId(newId);
      pendingFocusId.current = newId;
      setEditingId(newId);
    }
  }, [selectedId, current.id, addChild]);

  const handleAddSibling = useCallback((targetId?: string) => {
    const id = targetId ?? selectedId;
    if (!id) return;
    const newId = addSibling(id);
    if (newId) {
      setSelectedId(newId);
      pendingFocusId.current = newId;
      setEditingId(newId);
    }
  }, [selectedId, addSibling]);

  // --- 削除（Undo で復元可能なため確認なし） ---
  const handleDelete = useCallback((targetId?: string) => {
    const id = targetId ?? selectedId;
    if (!id || id === current.id) return;
    deleteNode(id);
    setSelectedId(null);
  }, [selectedId, current, deleteNode]);

  // --- 新規作成 ---
  const handleNew = useCallback(() => {
    if (!window.confirm('現在のマップを破棄して新規作成しますか？')) return;
    reset(createInitialRoot());
    setSelectedId(null);
    setEditingId(null);
  }, [reset]);

  // --- インポート ---
  const handleImport = useCallback((root: MindMapNode, theme?: MapTheme) => {
    reset(root);
    setSelectedId(null);
    setEditingId(null);
    if (theme) {
      setThemeColor(theme.edgeColor);
    }
    setTimeout(() => fitView({ padding: 0.2 }), 100);
  }, [reset, fitView]);

  // --- フィット ---
  const handleFitView = useCallback(() => {
    fitView({ padding: 0.2, duration: 300 });
  }, [fitView]);

  // --- エクスポート ---
  // fitView で全ノードを画面に収めてから撮影（DOM を変更しないため SVG エッジが確実に写る）
  const handleExportPNG = useCallback(async () => {
    fitView({ padding: 0.15 });
    await new Promise<void>((r) => requestAnimationFrame(() => requestAnimationFrame(() => r())));
    await exportPNG(current.text);
  }, [fitView, current.text]);

  const handleExportPDF = useCallback(async () => {
    fitView({ padding: 0.15 });
    await new Promise<void>((r) => requestAnimationFrame(() => requestAnimationFrame(() => r())));
    await exportPDF(current.text);
  }, [fitView, current.text]);

  // --- クリップボードコピー ---
  const handleCopy = useCallback(() => {
    if (!selectedId) return;
    const target = findNodeById(current, selectedId);
    if (!target) return;
    navigator.clipboard.writeText(nodeToText(target)).catch(() => {/* コピー失敗は無視 */});
  }, [selectedId, current]);

  // --- アプリ用コピー（JSON） ---
  const handleCopyForApp = useCallback(() => {
    if (!selectedId) return;
    const target = findNodeById(current, selectedId);
    if (!target) return;
    navigator.clipboard.writeText(nodeToClipboardJSON(target)).catch(() => {/* コピー失敗は無視 */});
  }, [selectedId, current]);

  // --- カット（アプリ用JSONで保持してから削除） ---
  const handleCut = useCallback(() => {
    if (!selectedId || selectedId === current.id) return;
    const target = findNodeById(current, selectedId);
    if (!target) return;
    navigator.clipboard.writeText(nodeToClipboardJSON(target)).then(() => {
      handleDelete(selectedId);
    }).catch(() => {/* クリップボード書き込み失敗は無視 */});
  }, [selectedId, current, handleDelete]);

  // --- ペースト ---
  const handlePaste = useCallback(async () => {
    const targetId = selectedId ?? current.id;
    try {
      const text = await navigator.clipboard.readText();
      if (text.length > 1 * 1024 * 1024) return; // 1MB超は無視
      const parsed = parseClipboardJSON(text) ?? parseIndentText(text);
      if (!parsed) return;
      pasteNode(targetId, parsed);
    } catch {/* クリップボード読み取り失敗は無視 */}
  }, [selectedId, current.id, pasteNode]);

  // --- キーボード ---
  useKeyboard({
    onUndo: undo,
    onRedo: redo,
    onAddChild: handleAddChild,
    onAddSibling: handleAddSibling,
    onDelete: handleDelete,
    onStartEdit: () => { if (selectedId) handleStartEdit(selectedId); },
    onFitView: handleFitView,
    onSave: () => {},
    onCopy: handleCopy,
    onCopyForApp: handleCopyForApp,
    onCut: handleCut,
    onPaste: handlePaste,
    isEditing: editingId !== null,
  });

  const contextMenuTargetIsRoot = contextMenu?.nodeId === current.id;

  return (
    <div style={{ height: '100vh', display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
      <Toolbar
        root={current}
        canUndo={canUndo}
        canRedo={canRedo}
        themeColor={themeColor}
        onUndo={undo}
        onRedo={redo}
        onNew={handleNew}
        onImport={handleImport}
        onFitView={handleFitView}
        onExportPNG={handleExportPNG}
        onExportPDF={handleExportPDF}
        onThemeColorChange={setThemeColor}
      />

      <div style={{ flex: 1, position: 'relative' }}>
        <MindMapCanvas
          root={current}
          selectedId={selectedId}
          editingId={editingId}
          edgeColor={themeColor}
          buttonColor={themeColor}
          onSelect={setSelectedId}
          onStartEdit={handleStartEdit}
          onCommitEdit={handleCommitEdit}
          onCancelEdit={handleCancelEdit}
          onContextMenu={setContextMenu}
          onToggleCollapse={toggleCollapse}
          onMoveNode={moveNode}
          onAddChild={handleAddChildFromEdit}
        />

        {contextMenu && (
          <NodeContextMenu
            state={contextMenu}
            isRoot={contextMenuTargetIsRoot}
            onAddChild={() => handleAddChild(contextMenu.nodeId)}
            onAddSibling={() => handleAddSibling(contextMenu.nodeId)}
            onStartEdit={() => handleStartEdit(contextMenu.nodeId)}
            onDelete={() => handleDelete(contextMenu.nodeId)}
            onClose={() => setContextMenu(null)}
          />
        )}
      </div>
    </div>
  );
};

export default function App() {
  return (
    <ReactFlowProvider>
      <AppInner />
    </ReactFlowProvider>
  );
}
