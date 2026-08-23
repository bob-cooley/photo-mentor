import type { NormalizedLandmark } from "@mediapipe/tasks-vision";
import type { DistanceEstimate } from "../types";

const AVERAGE_ADULT_FACE_WIDTH_M = 0.14; // cheekbone-to-cheekbone, rough population average
const FULL_FRAME_SENSOR_WIDTH_MM = 36; // by definition of "35mm-equivalent" focal length
const FALLBACK_SCHEMATIC_METERS = 3;

export function estimateSubjectDistance(
  landmarks: NormalizedLandmark[] | null,
  imageWidthPx: number,
  focalLength35mm: number | null,
): DistanceEstimate {
  if (!landmarks || landmarks.length === 0 || !focalLength35mm || focalLength35mm <= 0) {
    return {
      meters: FALLBACK_SCHEMATIC_METERS,
      approximate: true,
      note: "schematic distance only — no face detected or no focal length in EXIF",
    };
  }

  let minX = 1;
  let maxX = 0;
  for (const lm of landmarks) {
    if (lm.x < minX) minX = lm.x;
    if (lm.x > maxX) maxX = lm.x;
  }
  const faceWidthPx = (maxX - minX) * imageWidthPx;
  if (faceWidthPx <= 0) {
    return {
      meters: FALLBACK_SCHEMATIC_METERS,
      approximate: true,
      note: "schematic distance only — could not measure face width",
    };
  }

  const meters =
    (AVERAGE_ADULT_FACE_WIDTH_M * focalLength35mm * imageWidthPx) /
    (faceWidthPx * FULL_FRAME_SENSOR_WIDTH_MM);

  return {
    meters,
    approximate: true,
    note: "rough estimate from EXIF focal length and an assumed average face width — not a measurement",
  };
}
