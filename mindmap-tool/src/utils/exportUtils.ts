/**
 * SVG エッジ キャプチャ ユーティリティ
 *
 * html-to-image は SVG foreignObject 内の inline SVG を描画できない（ブラウザ制限）。
 * そのため SVG エッジを XMLSerializer でシリアライズ → Image → Canvas に変換し、
 * html-to-image のキャプチャ結果と合成する。
 *
 * ビューポートの CSS transform（translate / scale）を SVG に直接埋め込むことで、
 * HTML ノードと座標が一致した画像を生成する。
 */

const SVG_NS = 'http://www.w3.org/2000/svg';

/** SVG path/line などのプレゼンテーション属性をインライン style に解決する */
const inlineSvgElementStyles = (orig: SVGElement, cloned: SVGElement): void => {
  const cs = window.getComputedStyle(orig);
  const stroke = cs.stroke;
  cloned.style.stroke      = (stroke && stroke !== 'none') ? stroke : '#b1b1b7';
  cloned.style.strokeWidth = cs.strokeWidth  || '1';
  cloned.style.fill        = cs.fill         || 'none';
  cloned.style.opacity     = cs.opacity      || '1';
  cloned.style.strokeDasharray = cs.strokeDasharray || '';
};

/**
 * `.react-flow__edges` SVG をキャプチャしてキャンバスを返す。
 *
 * @param flowEl     `.react-flow` 要素（キャプチャ全体のサイズ基準）
 * @param viewportEl `.react-flow__viewport` 要素（CSS transform 取得用）
 * @param pixelRatio 出力解像度の倍率（html-to-image の pixelRatio と合わせる）
 */
export const captureEdgesAsCanvas = async (
  flowEl: HTMLElement,
  viewportEl: HTMLElement,
  pixelRatio = 2,
): Promise<HTMLCanvasElement | null> => {
  const svgEl = flowEl.querySelector<SVGSVGElement>('.react-flow__edges');
  if (!svgEl) return null;

  const W = flowEl.clientWidth;
  const H = flowEl.clientHeight;

  // ビューポートの CSS transform matrix を読み取る
  const matrix = new DOMMatrix(getComputedStyle(viewportEl).transform);
  const s  = Number.isFinite(matrix.a) && matrix.a !== 0 ? matrix.a : 1;
  const vx = Number.isFinite(matrix.e) ? matrix.e : 0;
  const vy = Number.isFinite(matrix.f) ? matrix.f : 0;

  // 出力 SVG を構築：ビューポートトランスフォームを <g> で適用
  const svgRoot = document.createElementNS(SVG_NS, 'svg');
  svgRoot.setAttribute('xmlns', SVG_NS);
  svgRoot.setAttribute('width',  String(W));
  svgRoot.setAttribute('height', String(H));

  const vpGroup = document.createElementNS(SVG_NS, 'g');
  vpGroup.setAttribute('transform', `translate(${vx},${vy}) scale(${s})`);

  // defs（マーカー等）をコピー
  const defsEl = svgEl.querySelector('defs');
  if (defsEl) {
    vpGroup.appendChild(defsEl.cloneNode(true));
  }

  // エッジグループをコピー（スタイルをインライン化）
  const edgeGroups = Array.from(svgEl.querySelectorAll<SVGGElement>('.react-flow__edge'));
  edgeGroups.forEach((origGroup) => {
    const clonedGroup = origGroup.cloneNode(true) as SVGGElement;

    const origPaths   = Array.from(origGroup.querySelectorAll<SVGElement>('path, line, polyline'));
    const clonedPaths = Array.from(clonedGroup.querySelectorAll<SVGElement>('path, line, polyline'));
    origPaths.forEach((orig, i) => {
      const cloned = clonedPaths[i];
      if (cloned) inlineSvgElementStyles(orig, cloned);
    });

    vpGroup.appendChild(clonedGroup);
  });

  svgRoot.appendChild(vpGroup);

  const svgStr = new XMLSerializer().serializeToString(svgRoot);
  const blob   = new Blob([svgStr], { type: 'image/svg+xml;charset=utf-8' });
  const url    = URL.createObjectURL(blob);

  return new Promise((resolve) => {
    const img = new Image(W, H);
    img.onload = () => {
      const canvas  = document.createElement('canvas');
      canvas.width  = W * pixelRatio;
      canvas.height = H * pixelRatio;
      const ctx = canvas.getContext('2d')!;
      ctx.scale(pixelRatio, pixelRatio);
      ctx.drawImage(img, 0, 0, W, H);
      URL.revokeObjectURL(url);
      resolve(canvas);
    };
    img.onerror = () => {
      URL.revokeObjectURL(url);
      resolve(null);
    };
    img.src = url;
  });
};
