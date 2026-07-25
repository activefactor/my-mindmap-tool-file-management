import { useEffect, useRef } from 'react';
import type { ContextMenuState } from '../../types/mindmap';

interface NodeContextMenuProps {
  state: ContextMenuState;
  isRoot: boolean;
  onAddChild: () => void;
  onAddSibling: () => void;
  onStartEdit: () => void;
  onDelete: () => void;
  onClose: () => void;
}

export const NodeContextMenu = ({
  state,
  isRoot,
  onAddChild,
  onAddSibling,
  onStartEdit,
  onDelete,
  onClose,
}: NodeContextMenuProps) => {
  const menuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        onClose();
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [onClose]);

  const item = (label: string, onClick: () => void, danger = false) => (
    <button
      onClick={() => { onClick(); onClose(); }}
      style={{
        display: 'block',
        width: '100%',
        textAlign: 'left',
        padding: 'var(--spacing-2) var(--spacing-4)',
        fontSize: 'var(--font-size-sm)',
        color: danger ? 'var(--color-danger)' : 'var(--color-text-primary)',
        background: 'transparent',
        border: 'none',
        cursor: 'pointer',
        borderRadius: 'var(--radius-sm)',
        transition: 'background var(--transition-fast)',
        fontFamily: 'var(--font-family-base)',
      }}
      onMouseEnter={(e) => {
        (e.target as HTMLButtonElement).style.background = 'var(--color-gray-100)';
      }}
      onMouseLeave={(e) => {
        (e.target as HTMLButtonElement).style.background = 'transparent';
      }}
    >
      {label}
    </button>
  );

  return (
    <div
      ref={menuRef}
      style={{
        position: 'fixed',
        top: state.y,
        left: state.x,
        background: 'var(--color-bg-toolbar)',
        border: `var(--border-width-default) solid var(--color-border-default)`,
        borderRadius: 'var(--radius-md)',
        boxShadow: 'var(--shadow-lg)',
        zIndex: 'var(--z-context-menu)',
        minWidth: '160px',
        padding: 'var(--spacing-1)',
        overflow: 'hidden',
      }}
    >
      {item('子ノードを追加', onAddChild)}
      {!isRoot && item('兄弟ノードを追加', onAddSibling)}
      <hr style={{ margin: 'var(--spacing-1) 0', border: 'none', borderTop: `1px solid var(--color-border-default)` }} />
      {item('編集', onStartEdit)}
      {!isRoot && item('削除', onDelete, true)}
    </div>
  );
};
