import { toCanvas } from 'html-to-image';
import jsPDF from 'jspdf';
import { captureEdgesAsCanvas } from './exportUtils';
import { buildFilename } from './filename';

const PIXEL_RATIO = 2;

/**
 * .react-flow をキャプチャして PDF を書き出す。
 * html-to-image（HTML ノード）と XMLSerializer（SVG エッジ）を合成する。
 * 呼び出し前に fitView() で全ノードを表示しておくこと。
 */
export const exportPDF = async (rootText: string): Promise<void> => {
  const flowEl     = document.querySelector<HTMLElement>('.react-flow');
  const viewportEl = document.querySelector<HTMLElement>('.react-flow__viewport');
  if (!flowEl || !viewportEl) return;

  await document.fonts.ready;

  const bgColor = getComputedStyle(document.documentElement)
    .getPropertyValue('--color-bg-canvas')
    .trim() || '#F3F4F6';

  const { width, height } = flowEl.getBoundingClientRect();
  const imgWidth  = Math.round(width);
  const imgHeight = Math.round(height);

  const nodeFilter = (node: HTMLElement) => {
    const cls = node.classList;
    if (!cls) return true;
    return !cls.contains('react-flow__controls') &&
           !cls.contains('react-flow__attribution') &&
           !cls.contains('react-flow__minimap') &&
           !cls.contains('react-flow__edges');
  };

  // HTML ノードをキャプチャ（エッジ SVG は除外）
  const canvas = await toCanvas(flowEl, {
    backgroundColor: bgColor,
    width:      imgWidth,
    height:     imgHeight,
    pixelRatio: PIXEL_RATIO,
    filter:     nodeFilter as (node: Node) => boolean,
  });

  // SVG エッジを別途キャプチャして合成
  const edgeCanvas = await captureEdgesAsCanvas(flowEl, viewportEl, PIXEL_RATIO);
  if (edgeCanvas) {
    canvas.getContext('2d')!.drawImage(edgeCanvas, 0, 0);
  }

  const imgData = canvas.toDataURL('image/png');
  const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

  const pageW = pdf.internal.pageSize.getWidth();
  const pageH = pdf.internal.pageSize.getHeight();
  const ratio = Math.min(pageW / imgWidth, pageH / imgHeight);
  const w = imgWidth  * ratio;
  const h = imgHeight * ratio;
  const x = (pageW - w) / 2;
  const y = (pageH - h) / 2;

  pdf.addImage(imgData, 'PNG', x, y, w, h);
  pdf.save(buildFilename(rootText, 'pdf'));
};
