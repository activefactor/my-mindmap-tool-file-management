import { useCallback, useRef, useState } from 'react';
import type { MindMapNode } from '../types/mindmap';

const DEFAULT_MAX = 50;
const STORAGE_KEY = 'mindmap-history-max';

const loadMax = (): number => {
  const v = localStorage.getItem(STORAGE_KEY);
  const n = v ? parseInt(v, 10) : DEFAULT_MAX;
  return isNaN(n) || n < 1 ? DEFAULT_MAX : n;
};

export const useHistory = (initial: MindMapNode) => {
  // 履歴スタックは ref（描画には直接関与しない）
  const past = useRef<MindMapNode[]>([]);
  const future = useRef<MindMapNode[]>([]);

  // canUndo / canRedo / maxHistory は state で管理（レンダー中の ref 参照を回避）
  const [max, setMax] = useState<number>(loadMax);
  const [canUndo, setCanUndo] = useState(false);
  const [canRedo, setCanRedo] = useState(false);
  const [current, setCurrent] = useState<MindMapNode>(initial);

  // スタック操作後に can* フラグを同期する
  const syncFlags = () => {
    setCanUndo(past.current.length > 0);
    setCanRedo(future.current.length > 0);
  };

  // 新しい状態をコミット（Undo スタックに積む）
  const commit = useCallback((next: MindMapNode) => {
    past.current.push(current);
    if (past.current.length > max) {
      past.current.shift(); // 古いものを削除（FIFO）
    }
    future.current = [];
    setCurrent(next);
    syncFlags();
  }, [current, max]);

  const undo = useCallback(() => {
    if (past.current.length === 0) return;
    const prev = past.current.pop()!;
    future.current.push(current);
    setCurrent(prev);
    syncFlags();
  }, [current]);

  const redo = useCallback(() => {
    if (future.current.length === 0) return;
    const next = future.current.pop()!;
    past.current.push(current);
    setCurrent(next);
    syncFlags();
  }, [current]);

  const reset = useCallback((node: MindMapNode) => {
    past.current = [];
    future.current = [];
    setCurrent(node);
    setCanUndo(false);
    setCanRedo(false);
  }, []);

  const setMaxHistory = useCallback((newMax: number) => {
    setMax(newMax);
    localStorage.setItem(STORAGE_KEY, String(newMax));
    // 超過分を削除
    while (past.current.length > newMax) past.current.shift();
    syncFlags();
  }, []);

  return {
    current,
    commit,
    undo,
    redo,
    reset,
    canUndo,
    canRedo,
    maxHistory: max,
    setMaxHistory,
  };
};
