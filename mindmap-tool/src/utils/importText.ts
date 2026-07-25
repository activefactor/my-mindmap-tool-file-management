import type { MindMapNode } from '../types/mindmap';
import { generateId } from './generateId';

const MAX_FILE_SIZE = 1 * 1024 * 1024; // 1MB
const MAX_LINES = 10000;
const MAX_DEPTH = 50;
const INDENT_SIZE = 4;
const MAX_TEXT_LENGTH = 500; // importJSON.ts の MAX_TEXT_LENGTH と一致させること

const unescapeNodeText = (text: string): string => {
  let result = '';
  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    if (char !== '\\' || i === text.length - 1) {
      result += char;
      continue;
    }

    const next = text[i + 1];
    if (next === 'n') {
      result += '\n';
      i++;
    } else if (next === '\\') {
      result += '\\';
      i++;
    } else {
      result += char;
    }
  }
  return result;
};

export const importText = (file: File): Promise<MindMapNode> => {
  return new Promise((resolve, reject) => {
    if (file.size > MAX_FILE_SIZE) {
      reject(new Error('ファイルサイズが上限（1MB）を超えています。'));
      return;
    }

    const reader = new FileReader();
    reader.onload = (e) => {
      try {
        const raw = e.target?.result as string;
        const root = _parseIndentText(raw);
        resolve(root);
      } catch (err) {
        reject(new Error(`テキストの解析に失敗しました: ${(err as Error).message}`));
      }
    };
    reader.onerror = () => reject(new Error('ファイルの読み込みに失敗しました。'));
    reader.readAsText(file, 'utf-8');
  });
};

export const parseIndentText = (raw: string): MindMapNode | null => {
  try {
    return _parseIndentText(raw);
  } catch {
    return null;
  }
};

const _parseIndentText = (raw: string): MindMapNode => {
  const lines = raw.split('\n').filter((l) => l.trim() !== '');

  if (lines.length === 0) throw new Error('テキストが空です。');
  if (lines.length > MAX_LINES) throw new Error(`行数が上限（${MAX_LINES}行）を超えています。`);

  // インデント深さを計算
  const items = lines.map((line) => {
    const spaces = line.match(/^( *)/)?.[1].length ?? 0;
    const depth = Math.floor(spaces / INDENT_SIZE);
    if (depth > MAX_DEPTH) throw new Error('インデントの深さが上限（50階層）を超えています。');
    const text = unescapeNodeText(line.trim()).slice(0, MAX_TEXT_LENGTH);
    return { depth, text };
  });

  // 最初の行をルートとして扱う（depth=0 以外の先頭行も受け入れる）
  const rootItem = items[0];
  const root: MindMapNode = { id: generateId(), text: rootItem.text, children: [], collapsed: false };

  // スタックで親子関係を構築
  // stack[i] = depth i のノード
  const stack: MindMapNode[] = [root];

  for (let i = 1; i < items.length; i++) {
    const { depth, text } = items[i];
    const node: MindMapNode = { id: generateId(), text, children: [], collapsed: false };

    // depth に対応する親を探す
    // stack を depth に切り詰めて、stack[depth-1] を親にする
    const relativeDepth = depth - rootItem.depth;

    if (relativeDepth <= 0) {
      // ルートと同じか浅い → ルートの兄弟扱いでルートに追加（例外処理）
      root.children.push(node);
      stack[1] = node;
      stack.length = 2;
    } else {
      // 通常の子ノード
      stack.length = relativeDepth + 1;
      const parent = stack[relativeDepth - 1] ?? root;
      parent.children.push(node);
      stack[relativeDepth] = node;
    }
  }

  return root;
};
