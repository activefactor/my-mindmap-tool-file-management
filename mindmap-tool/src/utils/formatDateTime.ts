/**
 * サーバーが返す日時文字列を表示用に整形する。
 *
 * DBは UTC で保存している（基本設計書_Phase2.md §5.1）ため、表示時にブラウザの
 * タイムゾーンへ変換する。MySQL の `DATETIME` は `2026-08-01 01:23:45` の形式で
 * タイムゾーン情報を持たないため、`Z` を補って UTC として解釈させる。
 */
export const formatDateTime = (value: string | null): string => {
  if (value === null || value === '') {
    return '—';
  }

  const normalized = value.includes('T') ? value : `${value.replace(' ', 'T')}Z`;
  const date = new Date(normalized);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat('ja-JP', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
};

/** バイト数を人間が読める単位に変換する（ストレージ使用状況の表示用）。 */
export const formatBytes = (bytes: number): string => {
  if (bytes < 1024) {
    return `${bytes} B`;
  }

  const units = ['KB', 'MB', 'GB'];
  let value = bytes / 1024;
  let unitIndex = 0;

  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024;
    unitIndex += 1;
  }

  return `${value.toFixed(1)} ${units[unitIndex]}`;
};
