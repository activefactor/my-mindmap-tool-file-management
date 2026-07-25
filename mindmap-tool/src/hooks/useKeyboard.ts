import { useEffect } from 'react';

interface KeyboardHandlers {
  onUndo: () => void;
  onRedo: () => void;
  onAddChild: () => void;
  onAddSibling: () => void;
  onDelete: () => void;
  onStartEdit: () => void;
  onFitView: () => void;
  onSave: () => void;
  onCopy: () => void;
  onCopyForApp: () => void;
  onCut: () => void;
  onPaste: () => void;
  isEditing: boolean;
}

export const useKeyboard = ({
  onUndo,
  onRedo,
  onAddChild,
  onAddSibling,
  onDelete,
  onStartEdit,
  onFitView,
  onSave,
  onCopy,
  onCopyForApp,
  onCut,
  onPaste,
  isEditing,
}: KeyboardHandlers) => {
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      // テキスト編集中はほとんどのショートカットを無効化
      if (isEditing) return;

      const ctrl = e.ctrlKey || e.metaKey;

      if (ctrl && e.key === 'z' && !e.shiftKey) { e.preventDefault(); onUndo(); return; }
      if (ctrl && e.key === 'Z' && e.shiftKey) { e.preventDefault(); onRedo(); return; }
      if (ctrl && e.shiftKey && e.key === 'z') { e.preventDefault(); onRedo(); return; }
      if (ctrl && e.key === 's') { e.preventDefault(); onSave(); return; }
      if (ctrl && e.shiftKey && e.key.toLowerCase() === 'c') { e.preventDefault(); onCopyForApp(); return; }
      if (ctrl && e.key === 'c') { e.preventDefault(); onCopy(); return; }
      if (ctrl && e.key === 'x') { e.preventDefault(); onCut(); return; }
      if (ctrl && e.key === 'v') { e.preventDefault(); onPaste(); return; }

      if (e.key === 'Tab') { e.preventDefault(); onAddChild(); return; }
      if (e.key === 'Enter') { e.preventDefault(); onAddSibling(); return; }
      if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); onDelete(); return; }
      if (e.key === 'F2') { e.preventDefault(); onStartEdit(); return; }
      if (e.key === 'f' || e.key === 'F') { onFitView(); return; }
    };

    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [isEditing, onUndo, onRedo, onAddChild, onAddSibling, onDelete, onStartEdit, onFitView, onSave, onCopy, onCopyForApp, onCut, onPaste]);
};
