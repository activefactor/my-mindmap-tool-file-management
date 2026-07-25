import { memo } from 'react';
import { Handle, Position } from 'reactflow';
import type { NodeProps } from 'reactflow';
import type { NodeData } from '../../types/mindmap';

export const MindMapNodeComponent = memo(({ data }: NodeProps<NodeData>) => {
  const {
    node,
    isRoot,
    isEditing,
    isDragTarget,
    isSelected,
    nodeWidth,
    nodeHeight,
    buttonColor,
    onStartEdit,
    onContextMenu,
    onToggleCollapse,
  } = data;

  const hasChildren = node.children.length > 0;

  // ---- スタイル計算 ----
  const isBoxVisible = isRoot || isEditing || isSelected || isDragTarget;

  const ringStyle = isSelected
    ? `0 0 0 2px var(--color-primary-500), var(--shadow-lg)`
    : isDragTarget
      ? 'var(--shadow-lg)'
      : isRoot
        ? 'var(--shadow-md)'
        : 'none';

  const borderColor = isDragTarget
    ? 'var(--color-primary-500)'
    : isSelected
      ? 'var(--color-primary-400)'
      : isBoxVisible
        ? 'var(--color-border-node)'
        : 'transparent';

  const borderWidth = isDragTarget || isSelected
    ? 'var(--border-width-thick)'
    : 'var(--border-width-default)';

  const bgColor = isRoot
    ? 'var(--color-bg-node-root)'
    : isEditing
      ? 'var(--color-bg-node)'
      : isDragTarget
        ? 'var(--color-bg-node-hover)'
        : isSelected
          ? 'var(--color-bg-node-selected)'
          : 'transparent';

  return (
    <div
      onContextMenu={(e) => onContextMenu(e, node.id)}
      onClick={() => {
        // 選択済みノードへのシングルクリックで編集開始
        if (isSelected && !isEditing) {
          onStartEdit(node.id);
        }
      }}
      onDoubleClick={() => {
        if (!isEditing) {
          onStartEdit(node.id);
        }
      }}
      style={{
        width: `${nodeWidth}px`,
        height: `${nodeHeight}px`,
        background: bgColor,
        border: `${borderWidth} solid ${borderColor}`,
        borderRadius: isRoot ? 'var(--radius-lg)' : 'var(--radius-md)',
        boxShadow: isEditing ? 'none' : ringStyle, // 編集中は FloatingEditor がボーダーを担当
        padding: isRoot
          ? 'var(--spacing-3) var(--spacing-6)'
          : 'var(--spacing-2) var(--spacing-4)',
        cursor: 'default',
        position: 'relative',
        transition: [
          'box-shadow var(--transition-fast)',
          'border-color var(--transition-fast)',
          'background var(--transition-fast)',
          'transform var(--transition-fast)',
        ].join(', '),
        userSelect: 'none',
        transform: isDragTarget ? 'scale(1.04)' : 'scale(1)',
        boxSizing: 'border-box',
        // 編集中は透明化して FloatingEditor を重ねて表示
        opacity: isEditing ? 0 : 1,
      }}
    >
      {!isRoot && (
        <Handle type="target" position={Position.Left} style={{ opacity: 0 }} />
      )}

      <span
        translate="no"
        className="notranslate"
        style={{
          fontSize: isRoot ? 'var(--font-size-xl)' : 'var(--font-size-base)',
          fontWeight: isRoot ? 'var(--font-weight-bold)' : 'var(--font-weight-normal)',
          color: isRoot ? 'var(--color-text-on-primary)' : 'var(--color-text-primary)',
          lineHeight: 'var(--line-height-base)',
          whiteSpace: 'pre-wrap',
          wordBreak: 'break-word',
          display: 'block',
        }}
      >
        {node.text}
      </span>

      {hasChildren && (
        <button
          onClick={(e) => { e.stopPropagation(); onToggleCollapse(node.id); }}
          title={node.collapsed ? '展開' : '折りたたむ'}
          style={{
            position: 'absolute',
            left: '100%',
            top: '50%',
            transform: 'translateY(-50%)',
            display: 'flex',
            alignItems: 'center',
            background: 'none',
            border: 'none',
            padding: 0,
            margin: 0,
            cursor: 'pointer',
            zIndex: 10,
          }}
        >
          <div style={{
            width: '3px',
            height: '3px',
            background: buttonColor,
            flexShrink: 0,
          }} />
          <div style={{
            width: '10px',
            height: '10px',
            borderRadius: '50%',
            flexShrink: 0,
            background: node.collapsed ? 'var(--color-gray-0)' : buttonColor,
            border: `1.5px solid ${buttonColor}`,
            boxSizing: 'border-box',
          }} />
        </button>
      )}

      <Handle type="source" position={Position.Right} style={{ opacity: 0 }} />
    </div>
  );
});

MindMapNodeComponent.displayName = 'MindMapNodeComponent';
