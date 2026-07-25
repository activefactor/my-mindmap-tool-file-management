import type { MindMapNode, MindMapFile, MapTheme } from '../types/mindmap';
import { generateId } from './generateId';

const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
const MAX_TEXT_LENGTH = 500;
const MAX_DEPTH = 50;
const SUPPORTED_VERSIONS = ['1.0'];

/**
 * ノード単体を検証・正規化する。
 * localStorage 復元でも共用するため export している。
 */
export const validateAndNormalizeNode = (node: unknown, depth: number): MindMapNode => {
  if (depth > MAX_DEPTH) throw new Error('ノードの階層が深すぎます（上限50階層）。');
  if (typeof node !== 'object' || node === null) throw new Error('不正なノード形式です。');

  const n = node as Record<string, unknown>;
  if (typeof n.text !== 'string') throw new Error('text フィールドが不正です。');
  if (n.text.length > MAX_TEXT_LENGTH) throw new Error(`テキストが長すぎます（上限${MAX_TEXT_LENGTH}文字）。`);
  if (!Array.isArray(n.children)) throw new Error('children フィールドが不正です。');

  return {
    id: typeof n.id === 'string' && n.id ? n.id : generateId(),
    text: n.text,
    collapsed: typeof n.collapsed === 'boolean' ? n.collapsed : false,
    children: (n.children as unknown[]).map((c) => validateAndNormalizeNode(c, depth + 1)),
  };
};

export const importJSON = (file: File): Promise<{ root: MindMapNode; theme?: MapTheme }> => {
  return new Promise((resolve, reject) => {
    if (file.size > MAX_FILE_SIZE) {
      reject(new Error('ファイルサイズが上限（5MB）を超えています。'));
      return;
    }

    const reader = new FileReader();
    reader.onload = (e) => {
      try {
        const raw = e.target?.result as string;
        const parsed: unknown = JSON.parse(raw);

        // ファイル全体のスキーマ検証
        if (typeof parsed !== 'object' || parsed === null) {
          throw new Error('JSONファイルの形式が不正です。');
        }
        const fileObj = parsed as Record<string, unknown>;

        if (typeof fileObj.version !== 'string') {
          throw new Error('version フィールドがありません。');
        }
        if (!SUPPORTED_VERSIONS.includes(fileObj.version)) {
          throw new Error(`未対応のバージョンです（${fileObj.version}）。対応バージョン: ${SUPPORTED_VERSIONS.join(', ')}`);
        }
        if (!('root' in fileObj)) {
          throw new Error('root フィールドがありません。');
        }

        const root = validateAndNormalizeNode((parsed as MindMapFile).root, 0);

        // theme フィールドの任意読み込み
        let theme: MapTheme | undefined;
        const t = fileObj.theme;
        if (typeof t === 'object' && t !== null) {
          const th = t as Record<string, unknown>;
          if (typeof th.edgeColor === 'string' && typeof th.buttonColor === 'string') {
            theme = { edgeColor: th.edgeColor, buttonColor: th.buttonColor };
          }
        }

        resolve({ root, theme });
      } catch (err) {
        reject(new Error(`JSONの読み込みに失敗しました: ${(err as Error).message}`));
      }
    };
    reader.onerror = () => reject(new Error('ファイルの読み込みに失敗しました。'));
    reader.readAsText(file, 'utf-8');
  });
};
