import type { FaceLandmarkerResult, NormalizedLandmark } from "@mediapipe/tasks-vision";
import type { EstimatorResult } from "../types";
import { weightedCircularMeanDeg, weightedMean } from "../lib/circularMean";
import { directionFromConeAngles } from "../lib/optics";
import { findBrightestPixel, averageLuminanceInWindow } from "../lib/imageCanvas";

// Canonical MediaPipe 478-point face mesh: indices 468-472 and 473-477 are
// the two iris rings (center + 4 boundary points each) appended after the
// 468 base mesh points. Which is anatomically "left" vs "right" doesn't
// matter here since both eyes are combined into one estimate.
const IRIS_GROUPS = [
  { center: 468, boundary: [469, 470, 471, 472] },
  { center: 473, boundary: [474, 475, 476, 477] },
];

interface EyeEstimate {
  azimuthDeg: number;
  elevationDeg: number;
  confidence: number;
}

function toPixel(lm: NormalizedLandmark, width: number, height: number) {
  return { x: lm.x * width, y: lm.y * height };
}

function estimateEye(
  landmarks: NormalizedLandmark[],
  group: (typeof IRIS_GROUPS)[number],
  imageData: ImageData,
  width: number,
  height: number,
): EyeEstimate | null {
  const centerLm = landmarks[group.center];
  if (!centerLm) return null;
  const center = toPixel(centerLm, width, height);

  let radiusSum = 0;
  for (const idx of group.boundary) {
    const lm = landmarks[idx];
    if (!lm) return null;
    const p = toPixel(lm, width, height);
    radiusSum += Math.hypot(p.x - center.x, p.y - center.y);
  }
  const irisRadiusPx = radiusSum / group.boundary.length;
  if (irisRadiusPx < 1.5) return null; // face too small in frame to resolve a catchlight

  const searchRadius = irisRadiusPx * 0.9;
  const brightest = findBrightestPixel(imageData, center.x, center.y, searchRadius);
  const irisAvgLuminance = averageLuminanceInWindow(imageData, center.x, center.y, irisRadiusPx);

  const offsetX = brightest.x - center.x;
  const offsetY = brightest.y - center.y;
  const offsetMag = Math.hypot(offsetX, offsetY);
  const offsetNorm = offsetMag / irisRadiusPx;
  if (offsetNorm >= 0.98) return null; // highlight at/beyond iris edge — geometry breaks down

  const theta = 2 * Math.asin(Math.min(1, offsetNorm));
  const phi = Math.atan2(offsetX, -offsetY);
  const { azimuthDeg, elevationDeg } = directionFromConeAngles(theta, phi);

  // Confidence from how much brighter the highlight is than the surrounding
  // iris — a real specular catchlight should stand out; a flat/overcast eye
  // won't show one and this will be low.
  const contrast = brightest.luminance - irisAvgLuminance;
  const confidence = Math.max(0, Math.min(0.8, contrast / 120));

  return { azimuthDeg, elevationDeg, confidence };
}

export function estimateFromCatchlights(
  faceResult: FaceLandmarkerResult,
  imageData: ImageData,
  width: number,
  height: number,
): EstimatorResult {
  const landmarks = faceResult.faceLandmarks?.[0];
  if (!landmarks || landmarks.length < 478) {
    return null; // model didn't resolve iris refinement landmarks for this face
  }

  const eyeEstimates = IRIS_GROUPS.map((g) => estimateEye(landmarks, g, imageData, width, height)).filter(
    (e): e is EyeEstimate => e !== null && e.confidence > 0.05,
  );

  if (eyeEstimates.length === 0) {
    return null; // no eye showed a specular highlight distinct enough to trust
  }

  const weights = eyeEstimates.map((e) => e.confidence);
  const azimuthDeg = weightedCircularMeanDeg(
    eyeEstimates.map((e) => e.azimuthDeg),
    weights,
  );
  const elevationDeg = weightedMean(
    eyeEstimates.map((e) => e.elevationDeg),
    weights,
  );
  const confidence = weightedMean(weights, weights.map(() => 1));

  return {
    method: "catchlight",
    azimuthDeg,
    elevationDeg,
    confidence,
    note: `from ${eyeEstimates.length} eye${eyeEstimates.length > 1 ? "s" : ""}`,
  };
}
