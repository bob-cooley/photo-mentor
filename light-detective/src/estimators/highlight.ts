import type { FaceLandmarkerResult, NormalizedLandmark } from "@mediapipe/tasks-vision";
import type { EstimatorResult } from "../types";
import { averageLuminanceInWindow } from "../lib/imageCanvas";

// A handful of well-known points in the canonical 468-point face mesh: two
// roughly-symmetric cheek points, forehead center, and chin. We don't rely on
// which is anatomically "left" — see below, we sort by actual pixel position
// instead, which is robust to that ambiguity and to head rotation direction.
const CHEEK_A = 50;
const CHEEK_B = 280;
const FOREHEAD = 10;
const CHIN = 152;

const SAMPLE_RADIUS_PX = 6;
const MIN_DYNAMIC_RANGE = 8; // below this, treat the face as too flatly lit to read

function toPixel(lm: NormalizedLandmark, width: number, height: number) {
  return { x: lm.x * width, y: lm.y * height };
}

export function estimateFromHighlights(
  faceResult: FaceLandmarkerResult,
  imageData: ImageData,
  width: number,
  height: number,
): EstimatorResult {
  const landmarks = faceResult.faceLandmarks?.[0];
  if (!landmarks) return null;

  const cheekALm = landmarks[CHEEK_A];
  const cheekBLm = landmarks[CHEEK_B];
  const foreheadLm = landmarks[FOREHEAD];
  const chinLm = landmarks[CHIN];
  if (!cheekALm || !cheekBLm || !foreheadLm || !chinLm) return null;

  const cheekA = toPixel(cheekALm, width, height);
  const cheekB = toPixel(cheekBLm, width, height);
  const forehead = toPixel(foreheadLm, width, height);
  const chin = toPixel(chinLm, width, height);

  // Sort by actual pixel position rather than trusting index-to-anatomy
  // mapping, so this is robust regardless of head yaw/mirroring.
  const [leftPt, rightPt] = cheekA.x <= cheekB.x ? [cheekA, cheekB] : [cheekB, cheekA];
  const [topPt, bottomPt] = forehead.y <= chin.y ? [forehead, chin] : [chin, forehead];

  const leftLum = averageLuminanceInWindow(imageData, leftPt.x, leftPt.y, SAMPLE_RADIUS_PX);
  const rightLum = averageLuminanceInWindow(imageData, rightPt.x, rightPt.y, SAMPLE_RADIUS_PX);
  const topLum = averageLuminanceInWindow(imageData, topPt.x, topPt.y, SAMPLE_RADIUS_PX);
  const bottomLum = averageLuminanceInWindow(imageData, bottomPt.x, bottomPt.y, SAMPLE_RADIUS_PX);

  const samples = [leftLum, rightLum, topLum, bottomLum];
  const dynamicRange = Math.max(...samples) - Math.min(...samples);
  if (dynamicRange < MIN_DYNAMIC_RANGE) {
    return null; // lighting too flat/diffuse across the face to read a direction
  }

  const horizontalNorm = (rightLum - leftLum) / dynamicRange; // + = brighter on image-right
  const verticalNorm = (topLum - bottomLum) / dynamicRange; // + = brighter on top (forehead)

  const azimuthDeg = (((horizontalNorm * 80) % 360) + 360) % 360;
  const elevationDeg = Math.max(-10, Math.min(80, verticalNorm * 60));

  // Coarser than catchlight geometry — capped confidence reflects that even
  // a strong gradient here is a rough directional cue, not precise geometry.
  const confidence = Math.max(0, Math.min(0.55, dynamicRange / 150));

  return {
    method: "highlight",
    azimuthDeg,
    elevationDeg,
    confidence,
    note: "coarse cue from facial brightness gradient",
  };
}
