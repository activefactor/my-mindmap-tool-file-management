import { toCanvas } from 'html-to-image';
import { captureEdgesAsCanvas } from './exportUtils';
import { buildFilename } from './filename';

const PIXEL_RATIO = 2;

/**
 * .react-flow をキャプチャして PNG を書き出す。
 * html-to-image（HTML ノード）と XMLSerializer（SVG エッジ）を合成する。
 * 呼び出し前に fitView() で全ノードを表示しておくこと。
 */
export const exportPNG = async (rootText: string): Promise<void> => {
  const flowEl     = document.querySelector<HTMLElement>('.react-flow');
  const viewportEl = document.querySelector<HTMLElement>('.react-flow__viewport');
  if (!flowEl || !viewportEl) return;

  await document.fonts.ready;

  const bgColor = getComputedStyle(document.documentElement)
    .getPropertyValue('--color-bg-canvas')
    .trim() || '#F3F4F6';

  const { width, height } = flowEl.getBoundingClientRect();
  const W = Math.round(width);
  const H = Math.round(height);

  const nodeFilter = (node: HTMLElement) => {
    const cls = node.classList;
    if (!cls) return true;
    return !cls.contains('react-flow__controls') &&
           !cls.contains('react-flow__attribution') &&
           !cls.contains('react-flow__minimap') &&
           !cls.contains('react-flow__edges');
  };

  // HTML ノードをキャプチャ（エッジ SVG は除外）
  const mainCanvas = await toCanvas(flowEl, {
    backgroundColor: bgColor,
    width:      W,
    height:     H,
    pixelRatio: PIXEL_RATIO,
    filter:     nodeFilter as (node: Node) => boolean,
  });

  // SVG エッジを別途キャプチャ
  const edgeCanvas = await captureEdgesAsCanvas(flowEl, viewportEl, PIXEL_RATIO);

  // 合成：エッジをノードレイヤーの上に重ねる
  const ctx = mainCanvas.getContext('2d')!;
  if (edgeCanvas) {
    ctx.drawImage(edgeCanvas, 0, 0);
  }

  const dataUrl = mainCanvas.toDataURL('image/png');
  const a = document.createElement('a');
  a.href     = dataUrl;
  a.download = buildFilename(rootText, 'png');
  a.click();
};
