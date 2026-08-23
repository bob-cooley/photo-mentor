import * as SunCalc from "suncalc";
import type { EstimatorResult, ExifFacts } from "../types";

/**
 * The sun's true compass position at capture time/location — deterministic,
 * no image analysis. Available whenever the photo carries GPS + timestamp.
 * This is informational ("fact panel") regardless of whether it can join
 * the ensemble — see estimateFromExif below for that gate.
 */
export function getTrueSunFact(
  facts: ExifFacts,
): { azimuthDeg: number; elevationDeg: number } | null {
  if (facts.gpsLat === null || facts.gpsLon === null || facts.timestamp === null) {
    return null;
  }
  const pos = SunCalc.getPosition(facts.timestamp, facts.gpsLat, facts.gpsLon);
  return { azimuthDeg: pos.azimuth, elevationDeg: pos.altitude };
}

/**
 * Camera-relative sun estimate for the ensemble. Only possible when the
 * photo also carries a camera heading (EXIF GPSImgDirection) — without it
 * we know where the sun was in the world, but not which way the camera was
 * pointing, so we can't rotate the true position into camera-relative terms.
 * Inconsistently written across devices, so this will often be null even
 * when getTrueSunFact() isn't.
 */
export function estimateFromExif(facts: ExifFacts): EstimatorResult {
  const trueSun = getTrueSunFact(facts);
  if (!trueSun || facts.cameraHeadingDeg === null) return null;

  const azimuthDeg = ((trueSun.azimuthDeg - facts.cameraHeadingDeg) % 360 + 360) % 360;

  return {
    method: "exif",
    azimuthDeg,
    elevationDeg: trueSun.elevationDeg,
    confidence: 0.95, // deterministic astronomy, not a CV guess — trust it heavily when available
    note: "from GPS + timestamp + camera heading (exact)",
  };
}
