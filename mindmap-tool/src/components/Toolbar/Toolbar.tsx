import { useRef, useState } from 'react';
import type { MindMapNode, MapTheme } from '../../types/mindmap';
import { exportJSON } from '../../utils/exportJSON';
import { importJSON } from '../../utils/importJSON';
import { exportText } from '../../utils/exportText';
import { importText } from '../../utils/importText';

// ============================================================
// スタイル定数（コンポーネント外でモジュール単位で定義）
// ============================================================
const btnStyle = (disabled = false): React.CSSProperties => ({
  padding: 'var(--spacing-2) var(--spacing-3)',
  borderRadius: 'var(--radius-md)',
  fontSize: 'var(--font-size-sm)',
  fontWeight: 'var(--font-weight-medium)' as React.CSSProperties['fontWeight'],
  color: disabled ? 'var(--color-gray-300)' : 'var(--color-text-primary)',
  background: 'transparent',
  border: 'none',
  cursor: disabled ? 'default' : 'pointer',
  transition: 'background var(--transition-fast)',
  fontFamily: 'var(--font-family-base)',
  pointerEvents: disabled ? 'none' : 'auto',
});

const menuItemStyle: React.CSSProperties = {
  display: 'block',
  width: '100%',
  textAlign: 'left',
  padding: 'var(--spacing-2) var(--spacing-4)',
  fontSize: 'var(--font-size-sm)',
  color: 'var(--color-text-primary)',
  background: 'transparent',
  border: 'none',
  cursor: 'pointer',
  fontFamily: 'var(--font-family-base)',
  whiteSpace: 'nowrap',
};

// ============================================================
// サブコンポーネント（モジュールレベルで宣言してコンポーネント内生成を回避）
// ============================================================
interface DropMenuProps {
  name: string;
  label: string;
  children: React.ReactNode;
  openMenu: string | null;
  onToggle: (name: string) => void;
}

const DropMenu = ({ name, label, children, openMenu, onToggle }: DropMenuProps) => (
  <div style={{ position: 'relative' }}>
    <button
      style={btnStyle()}
      onClick={() => onToggle(name)}
      onMouseEnter={(e) => { (e.target as HTMLButtonElement).style.background = 'var(--color-gray-100)'; }}
      onMouseLeave={(e) => { (e.target as HTMLButtonElement).style.background = 'transparent'; }}
    >
      {label} ▾
    </button>
    {openMenu === name && (
      <div
        style={{
          position: 'absolute',
          top: '100%',
          left: 0,
          background: 'var(--color-bg-toolbar)',
          border: `1px solid var(--color-border-default)`,
          borderRadius: 'var(--radius-md)',
          boxShadow: 'var(--shadow-lg)',
          zIndex: 'var(--z-context-menu)' as unknown as number,
          minWidth: '180px',
          padding: 'var(--spacing-1)',
        }}
      >
        {children}
      </div>
    )}
  </div>
);

interface MenuItemProps {
  label: string;
  onClick: () => void;
  onClose: () => void;
}

const MenuItem = ({ label, onClick, onClose }: MenuItemProps) => (
  <button
    style={menuItemStyle}
    onClick={() => { onClick(); onClose(); }}
    onMouseEnter={(e) => { (e.target as HTMLButtonElement).style.background = 'var(--color-gray-100)'; }}
    onMouseLeave={(e) => { (e.target as HTMLButtonElement).style.background = 'transparent'; }}
  >
    {label}
  </button>
);

// ============================================================
// Toolbar
// ============================================================
interface ToolbarProps {
  root: MindMapNode;
  canUndo: boolean;
  canRedo: boolean;
  themeColor: string;
  onUndo: () => void;
  onRedo: () => void;
  onNew: () => void;
  onImport: (root: MindMapNode, theme?: MapTheme) => void;
  onFitView: () => void;
  onExportPNG: () => void;
  onExportPDF: () => void;
  onThemeColorChange: (color: string) => void;
}

export const Toolbar = ({
  root,
  canUndo,
  canRedo,
  themeColor,
  onUndo,
  onRedo,
  onNew,
  onImport,
  onFitView,
  onExportPNG,
  onExportPDF,
  onThemeColorChange,
}: ToolbarProps) => {
  const jsonInputRef = useRef<HTMLInputElement>(null);
  const txtInputRef = useRef<HTMLInputElement>(null);
  const [openMenu, setOpenMenu] = useState<string | null>(null);

  const toggleMenu = (name: string) =>
    setOpenMenu((prev) => (prev === name ? null : name));
  const closeMenu = () => setOpenMenu(null);

  const handleImport = async (file: File, type: 'json' | 'txt') => {
    closeMenu();
    try {
      if (type === 'json') {
        const { root, theme } = await importJSON(file);
        onImport(root, theme);
      } else {
        const node = await importText(file);
        onImport(node);
      }
    } catch (e) {
      alert((e as Error).message);
    }
  };

  return (
    <header
      style={{
        height: 'var(--toolbar-height)',
        background: 'var(--color-bg-toolbar)',
        borderBottom: `1px solid var(--color-border-default)`,
        boxShadow: 'var(--shadow-sm)',
        display: 'flex',
        alignItems: 'center',
        padding: '0 var(--spacing-4)',
        gap: 'var(--spacing-1)',
        zIndex: 'var(--z-toolbar)' as unknown as number,
        position: 'relative',
      }}
      onClick={closeMenu}
    >
      {/* クリックのバブリングを止める */}
      <div
        style={{ display: 'flex', alignItems: 'center', gap: 'var(--spacing-1)' }}
        onClick={(e) => e.stopPropagation()}
      >
        {/* 新規 */}
        <button
          style={btnStyle()}
          onClick={onNew}
          onMouseEnter={(e) => { (e.target as HTMLButtonElement).style.background = 'var(--color-gray-100)'; }}
          onMouseLeave={(e) => { (e.target as HTMLButtonElement).style.background = 'transparent'; }}
        >
          新規
        </button>

        {/* 区切り */}
        <div style={{ width: '1px', height: '20px', background: 'var(--color-border-default)', margin: '0 var(--spacing-1)' }} />

        {/* 開く */}
        <DropMenu name="open" label="開く" openMenu={openMenu} onToggle={toggleMenu}>
          <MenuItem label="JSONを開く" onClick={() => jsonInputRef.current?.click()} onClose={closeMenu} />
          <MenuItem label="テキストを開く" onClick={() => txtInputRef.current?.click()} onClose={closeMenu} />
        </DropMenu>

        {/* 保存 */}
        <DropMenu name="save" label="保存" openMenu={openMenu} onToggle={toggleMenu}>
          <MenuItem label="JSONで保存" onClick={() => exportJSON(root, { edgeColor: themeColor, buttonColor: themeColor })} onClose={closeMenu} />
          <MenuItem label="テキストで保存" onClick={() => exportText(root)} onClose={closeMenu} />
        </DropMenu>

        {/* エクスポート */}
        <DropMenu name="export" label="エクスポート" openMenu={openMenu} onToggle={toggleMenu}>
          <MenuItem label="PNGで書き出し" onClick={onExportPNG} onClose={closeMenu} />
          <MenuItem label="PDFで書き出し" onClick={onExportPDF} onClose={closeMenu} />
        </DropMenu>

        {/* 区切り */}
        <div style={{ width: '1px', height: '20px', background: 'var(--color-border-default)', margin: '0 var(--spacing-1)' }} />

        {/* Undo / Redo */}
        <button
          style={btnStyle(!canUndo)}
          onClick={onUndo}
          title="元に戻す (Ctrl+Z)"
          onMouseEnter={(e) => { if (canUndo) (e.target as HTMLButtonElement).style.background = 'var(--color-gray-100)'; }}
          onMouseLeave={(e) => { (e.target as HTMLButtonElement).style.background = 'transparent'; }}
        >
          ↩ 戻す
        </button>
        <button
          style={btnStyle(!canRedo)}
          onClick={onRedo}
          title="やり直す (Ctrl+Shift+Z)"
          onMouseEnter={(e) => { if (canRedo) (e.target as HTMLButtonElement).style.background = 'var(--color-gray-100)'; }}
          onMouseLeave={(e) => { (e.target as HTMLButtonElement).style.background = 'transparent'; }}
        >
          ↪ やり直す
        </button>

        {/* 区切り */}
        <div style={{ width: '1px', height: '20px', background: 'var(--color-border-default)', margin: '0 var(--spacing-1)' }} />

        {/* フィット */}
        <button
          style={btnStyle()}
          onClick={onFitView}
          title="全体表示 (F)"
          onMouseEnter={(e) => { (e.target as HTMLButtonElement).style.background = 'var(--color-gray-100)'; }}
          onMouseLeave={(e) => { (e.target as HTMLButtonElement).style.background = 'transparent'; }}
        >
          全体表示
        </button>

        {/* 区切り */}
        <div style={{ width: '1px', height: '20px', background: 'var(--color-border-default)', margin: '0 var(--spacing-1)' }} />

        {/* テーマカラー（線・開閉ボタン共通） */}
        <label
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 'var(--spacing-2)',
            fontSize: 'var(--font-size-sm)',
            color: 'var(--color-text-secondary)',
            cursor: 'pointer',
            userSelect: 'none',
          }}
        >
          カラー
          <input
            type="color"
            value={themeColor}
            onChange={(e) => onThemeColorChange(e.target.value)}
            style={{
              width: '24px',
              height: '24px',
              padding: '1px',
              border: '1px solid var(--color-border-default)',
              borderRadius: 'var(--radius-sm)',
              cursor: 'pointer',
              background: 'none',
            }}
          />
        </label>
      </div>

      {/* 隠しファイル入力 */}
      <input
        ref={jsonInputRef}
        type="file"
        accept=".json"
        style={{ display: 'none' }}
        onChange={(e) => { if (e.target.files?.[0]) handleImport(e.target.files[0], 'json'); e.target.value = ''; }}
      />
      <input
        ref={txtInputRef}
        type="file"
        accept=".txt"
        style={{ display: 'none' }}
        onChange={(e) => { if (e.target.files?.[0]) handleImport(e.target.files[0], 'txt'); e.target.value = ''; }}
      />
    </header>
  );
};
