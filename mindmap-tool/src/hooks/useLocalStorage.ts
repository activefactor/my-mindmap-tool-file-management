import { useEffect, useRef } from 'react';
import type { MindMapNode } from '../types/mindmap';
import { validateAndNormalizeNode } from '../utils/importJSON';

const STORAGE_KEY = 'mindmap-data';
const DEBOUNCE_MS = 500;

export const saveToStorage = (root: MindMapNode): void => {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(root));
  } catch (e) {
    console.error('[useLocalStorage] 保存に失敗しました:', e);
  }
};

export const loadFromStorage = (): MindMapNode | null => {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    // JSON.parse 後にスキーマ検証してから返す（壊れたデータや手動改変への対策）
    const parsed: unknown = JSON.parse(raw);
    return validateAndNormalizeNode(parsed, 0);
  } catch (e) {
    console.error('[useLocalStorage] 読み込みに失敗しました（初期状態に戻します）:', e);
    return null;
  }
};

export const useAutoSave = (root: MindMapNode): void => {
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (timer.current) clearTimeout(timer.current);
    timer.current = setTimeout(() => {
      saveToStorage(root);
    }, DEBOUNCE_MS);

    return () => {
      if (timer.current) clearTimeout(timer.current);
    };
  }, [root]);
};
