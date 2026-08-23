import type { ImageSegmenterResult } from "@mediapipe/tasks-vision";
import type { EstimatorResult } from "../types";
import { relativeLuminance } from "../lib/imageCanvas";
import { estimateFovDeg, pixelOffsetToAzimuthElevation } from "../lib/optics";

// Provisional thresholds — this method is the least geometrically clean of
// the three (it leans on a flat-ground-plane assumption and a fairly naive
// darkness threshold rather than real shadow segmentation) and its constants
// are expected to need retuning once run against real ground-truth photos
// in the QA pass. It's built to fail safe (return null) rather than guess
// when the picture doesn't cooperate — most portrait crops won't even show
// the subject's feet, and that's fine, the ensemble just does without it.

const PERSON_CLASS_VALUE = 1;
const MIN_SUBJECT_HEIGHT_MASK_PX = 20;
const MIN_SHADOW_PIXELS = 20;

export function estimateFromShadow(
  segResult: ImageSegmenterResult,
  imageData: ImageData,
  width: number,
  height: number,
  focalLength35mm: number | null,
): EstimatorResult {
  const mask = segResult.categoryMask;
  if (!mask) return null;

  const maskData = mask.getAsUint8Array();
  const maskW = mask.width;
  const maskH = mask.height;
  const sx = width / maskW;
  const sy = height / maskH;

  let minX = maskW, maxX = -1, minY = maskH, maxY = -1;
  for (let y = 0; y < maskH; y++) {
    for (let x = 0; x < maskW; x++) {
      if (maskData[y * maskW + x] === PERSON_CLASS_VALUE) {
        if (x < minX) minX = x;
        if (x > maxX) maxX = x;
        if (y < minY) minY = y;
        if (y > maxY) maxY = y;
      }
    }
  }
  if (maxY < 0 || maxY - minY < MIN_SUBJECT_HEIGHT_MASK_PX) {
    return null; // no person mask found, or subject too small to measure
  }

  // Contact point: centroid of the bottom slice of the person mask (the
  // feet/base), not just the single bottom-most row, for a bit of
  // robustness against one noisy row.
  const bottomSliceStart = Math.max(minY, maxY - Math.max(2, Math.round((maxY - minY) * 0.03)));
  let contactSumX = 0;
  let contactCount = 0;
  for (let y = bottomSliceStart; y <= maxY; y++) {
    for (let x = minX; x <= maxX; x++) {
      if (maskData[y * maskW + x] === PERSON_CLASS_VALUE) {
        contactSumX += x;
        contactCount++;
      }
    }
  }
  if (contactCount === 0) return null;
  const contactXMask = contactSumX / contactCount;
  const contactYMask = maxY;
  const subjectHeightMaskPx = maxY - minY;

  const contactXFull = contactXMask * sx;
  const contactYFull = contactYMask * sy;
  const subjectHeightFullPx = subjectHeightMaskPx * sy;

  // Search box below/around the feet for a dark ground-shadow blob.
  const searchHalfWidth = subjectHeightFullPx * 0.7;
  const searchDepth = subjectHeightFullPx * 1.1;
  const bx0 = Math.max(0, Math.round(contactXFull - searchHalfWidth));
  const bx1 = Math.min(width - 1, Math.round(contactXFull + searchHalfWidth));
  const by0 = Math.max(0, Math.round(contactYFull));
  const by1 = Math.min(height - 1, Math.round(contactYFull + searchDepth));
  if (by1 <= by0) return null;

  const px = imageData.data;
  let groundLumSum = 0;
  let groundCount = 0;
  const groundSamples: { x: number; y: number; lum: number }[] = [];

  for (let y = by0; y <= by1; y++) {
    const maskY = Math.min(maskH - 1, Math.round(y / sy));
    for (let x = bx0; x <= bx1; x++) {
      const maskX = Math.min(maskW - 1, Math.round(x / sx));
      if (maskData[maskY * maskW + maskX] === PERSON_CLASS_VALUE) continue; // skip the subject itself
      const i = (y * width + x) * 4;
      const lum = relativeLuminance(px[i], px[i + 1], px[i + 2]);
      groundLumSum += lum;
      groundCount++;
      groundSamples.push({ x, y, lum });
    }
  }
  if (groundCount < MIN_SHADOW_PIXELS) return null;

  const groundLumAvg = groundLumSum / groundCount;
  const darkDelta = Math.max(15, groundLumAvg * 0.22);
  const threshold = groundLumAvg - darkDelta;

  let weightSum = 0;
  let cxSum = 0;
  let cySum = 0;
  let darkCount = 0;
  for (const s of groundSamples) {
    if (s.lum < threshold) {
      const w = threshold - s.lum;
      weightSum += w;
      cxSum += s.x * w;
      cySum += s.y * w;
      darkCount++;
    }
  }
  if (darkCount < MIN_SHADOW_PIXELS || weightSum <= 0) {
    return null; // no dark blob distinct enough from the surrounding ground to trust
  }

  const shadowCentroidX = cxSum / weightSum;
  const shadowCentroidY = cySum / weightSum;
  const shadowVecX = shadowCentroidX - contactXFull;
  const shadowVecY = shadowCentroidY - contactYFull;
  const shadowLengthPx = Math.hypot(shadowVecX, shadowVecY);

  if (shadowLengthPx < subjectHeightFullPx * 0.05) return null; // too short to read reliably

  const elevationDeg = Math.atan2(subjectHeightFullPx, shadowLengthPx) * (180 / Math.PI);

  const { horizontalDeg: hFovDeg } = estimateFovDeg(focalLength35mm);
  // Sun is opposite the shadow's horizontal direction; vertical component of
  // the shadow's image-plane position is perspective foreshortening, not
  // sun elevation (that came from the height:length ratio above), so dyNorm
  // is intentionally zeroed here.
  const dxNorm = -shadowVecX / (width / 2);
  const { azimuthDeg } = pixelOffsetToAzimuthElevation(dxNorm, 0, hFovDeg, 0);

  const contrastQuality = Math.min(1, darkDelta / 60);
  const sizeQuality = Math.min(1, darkCount / 200);
  const confidence = Math.max(0, Math.min(0.7, 0.7 * contrastQuality * sizeQuality));

  return {
    method: "shadow",
    azimuthDeg,
    elevationDeg,
    confidence,
    note: "from ground-shadow direction and length:height ratio",
  };
}
