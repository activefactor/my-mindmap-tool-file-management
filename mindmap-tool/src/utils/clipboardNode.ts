import type { MindMapNode } from '../types/mindmap';
import { generateId } from './generateId';
import { validateAndNormalizeNode } from './importJSON';

const CLIPBOARD_FORMAT = 'mindmap-tool.node';
const CLIPBOARD_VERSION = '1.0';

interface ClipboardNodePayload {
  format: typeof CLIPBOARD_FORMAT;
  version: typeof CLIPBOARD_VERSION;
  node: MindMapNode;
}

const cloneWithNewIds = (node: MindMapNode): MindMapNode => ({
  ...node,
  id: generateId(),
  children: node.children.map(cloneWithNewIds),
});

export const nodeToClipboardJSON = (node: MindMapNode): string => {
  const payload: ClipboardNodePayload = {
    format: CLIPBOARD_FORMAT,
    version: CLIPBOARD_VERSION,
    node,
  };

  return JSON.stringify(payload);
};

export const parseClipboardJSON = (raw: string): MindMapNode | null => {
  try {
    const parsed: unknown = JSON.parse(raw);
    if (typeof parsed !== 'object' || parsed === null) return null;

    const payload = parsed as Record<string, unknown>;
    if (payload.format !== CLIPBOARD_FORMAT) return null;
    if (payload.version !== CLIPBOARD_VERSION) return null;
    if (!('node' in payload)) return null;

    const normalized = validateAndNormalizeNode(payload.node, 0);
    return cloneWithNewIds(normalized);
  } catch {
    return null;
  }
};
