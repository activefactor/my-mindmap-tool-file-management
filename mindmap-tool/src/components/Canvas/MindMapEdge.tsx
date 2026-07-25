import { getBezierPath } from 'reactflow';
import type { EdgeProps } from 'reactflow';

// curvature: 制御点をエッジ長の何割オフセットするか（0=直線、1=大きなS字）
// 0.6 にすることで「ノードから水平に出て中間でしっかりカーブ」する形になる
const CURVATURE = 1.8;

export const MindMapEdge = ({
  sourceX,
  sourceY,
  targetX,
  targetY,
  sourcePosition,
  targetPosition,
  markerEnd,
  data,
}: EdgeProps) => {
  const [edgePath] = getBezierPath({
    sourceX,
    sourceY,
    sourcePosition,
    targetX,
    targetY,
    targetPosition,
    curvature: CURVATURE,
  });

  return (
    <path
      d={edgePath}
      fill="none"
      className="react-flow__edge-path"
      markerEnd={markerEnd}
      style={data?.edgeColor ? { stroke: data.edgeColor } : undefined}
    />
  );
};
