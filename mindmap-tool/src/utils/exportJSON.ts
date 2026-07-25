import type { MindMapNode, MindMapFile, MapTheme } from '../types/mindmap';
import { buildFilename } from './filename';

export const exportJSON = (root: MindMapNode, theme: MapTheme): void => {
  const file: MindMapFile = {
    version: '1.0',
    createdAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
    root,
    theme,
  };

  const blob = new Blob([JSON.stringify(file, null, 2)], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = buildFilename(root.text, 'json');
  a.click();
  URL.revokeObjectURL(url);
};
