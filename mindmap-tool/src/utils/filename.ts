/** ファイル名に使えない文字を置換する */
const sanitize = (text: string): string => {
  const replaced = text
    .replace(/[/\\:*?"<>|]/g, '_') // OS禁止文字
    .replace(/\s+/g, '_')           // 空白・改行
    .replace(/_{2,}/g, '_')         // 連続アンダースコアを1つに
    .replace(/^_|_$/g, '');         // 先頭末尾のアンダースコア除去
  return replaced.slice(0, 50) || 'mindmap';
};

/** yyyymmddhhmmss 形式のタイムスタンプを返す */
const timestamp = (): string => {
  const d = new Date();
  const p = (n: number) => String(n).padStart(2, '0');
  return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
};

/**
 * エクスポートファイル名を生成する。
 * 形式: {rootテキスト}_{yyyymmddhhmmss}.{ext}
 */
export const buildFilename = (rootText: string, ext: string): string =>
  `${sanitize(rootText)}_${timestamp()}.${ext}`;
