import { useCallback } from 'react';
import type { MindMapNode } from '../types/mindmap';
import { generateId } from '../utils/generateId';

// ツリーを immutable に操作するユーティリティ

const mapTree = (
  node: MindMapNode,
  fn: (n: MindMapNode) => MindMapNode,
): MindMapNode => {
  const updated = fn(node);
  return { ...updated, children: updated.children.map((c) => mapTree(c, fn)) };
};

const findParent = (
  root: MindMapNode,
  targetId: string,
): MindMapNode | null => {
  for (const child of root.children) {
    if (child.id === targetId) return root;
    const found = findParent(child, targetId);
    if (found) return found;
  }
  return null;
};

export const useMindMap = (
  current: MindMapNode,
  commit: (next: MindMapNode) => void,
) => {
  // 子ノード追加
  const addChild = useCallback((parentId: string) => {
    const newNode: MindMapNode = { id: generateId(), text: '新しいノード', children: [], collapsed: false };
    const next = mapTree(current, (n) => {
      if (n.id !== parentId) return n;
      return { ...n, collapsed: false, children: [...n.children, newNode] };
    });
    commit(next);
    return newNode.id;
  }, [current, commit]);

  // 兄弟ノード追加
  const addSibling = useCallback((siblingId: string) => {
    if (siblingId === current.id) return addChild(current.id);
    const newNode: MindMapNode = { id: generateId(), text: '新しいノード', children: [], collapsed: false };
    const next = mapTree(current, (n) => {
      const idx = n.children.findIndex((c) => c.id === siblingId);
      if (idx === -1) return n;
      const children = [...n.children];
      children.splice(idx + 1, 0, newNode);
      return { ...n, children };
    });
    commit(next);
    return newNode.id;
  }, [current, commit, addChild]);

  // ノード削除
  const deleteNode = useCallback((nodeId: string) => {
    if (nodeId === current.id) return; // ルートは削除不可
    const next = mapTree(current, (n) => ({
      ...n,
      children: n.children.filter((c) => c.id !== nodeId),
    }));
    commit(next);
  }, [current, commit]);

  // テキスト更新
  const updateText = useCallback((nodeId: string, text: string) => {
    const next = mapTree(current, (n) =>
      n.id === nodeId ? { ...n, text } : n,
    );
    commit(next);
  }, [current, commit]);

  // 折りたたみ切り替え
  const toggleCollapse = useCallback((nodeId: string) => {
    const next = mapTree(current, (n) =>
      n.id === nodeId ? { ...n, collapsed: !n.collapsed } : n,
    );
    commit(next);
  }, [current, commit]);

  // ノードを別の親へ移動
  const moveNode = useCallback((nodeId: string, newParentId: string) => {
    if (nodeId === current.id || nodeId === newParentId) return;

    // 移動先が nodeId の子孫でないかチェック
    const isDescendant = (root: MindMapNode, ancestorId: string, targetId: string): boolean => {
      if (root.id === ancestorId) {
        const check = (n: MindMapNode): boolean =>
          n.id === targetId || n.children.some(check);
        return check(root);
      }
      return root.children.some((c) => isDescendant(c, ancestorId, targetId));
    };
    if (isDescendant(current, nodeId, newParentId)) return;

    let moved: MindMapNode | null = null;

    // まず対象ノードを取り出す
    const withoutNode = mapTree(current, (n) => {
      const idx = n.children.findIndex((c) => c.id === nodeId);
      if (idx === -1) return n;
      moved = n.children[idx];
      return { ...n, children: n.children.filter((c) => c.id !== nodeId) };
    });

    if (!moved) return;
    const movedNode = moved as MindMapNode;

    // 新しい親に追加
    const next = mapTree(withoutNode, (n) => {
      if (n.id !== newParentId) return n;
      return { ...n, collapsed: false, children: [...n.children, movedNode] };
    });

    commit(next);
  }, [current, commit]);

  const getParent = useCallback((nodeId: string) =>
    findParent(current, nodeId),
  [current]);

  // パース済みノードを parentId の子として末尾に追加（ペースト用）
  const pasteNode = useCallback((parentId: string, nodeToAdd: MindMapNode) => {
    const next = mapTree(current, (n) => {
      if (n.id !== parentId) return n;
      return { ...n, collapsed: false, children: [...n.children, nodeToAdd] };
    });
    commit(next);
  }, [current, commit]);

  // テキスト確定 + 子ノード追加を1回の commit にまとめる（Tab キー用）
  // → updateText → addChild の2段階 setState だと addChild が古い current を掴む問題を回避
  const commitAndAddChild = useCallback((nodeId: string, text: string): string | null => {
    const newChildId = generateId();
    const newChild: MindMapNode = { id: newChildId, text: '新しいノード', children: [], collapsed: false };
    const next = mapTree(current, (n) => {
      if (n.id !== nodeId) return n;
      const withText = text.trim() ? { ...n, text: text.trim() } : n;
      return { ...withText, collapsed: false, children: [...withText.children, newChild] };
    });
    commit(next);
    return newChildId;
  }, [current, commit]);

  return { addChild, addSibling, deleteNode, updateText, toggleCollapse, moveNode, getParent, pasteNode, commitAndAddChild };
};
