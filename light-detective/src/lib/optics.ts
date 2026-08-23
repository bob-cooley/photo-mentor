// Shared geometry helpers for turning 2D image-space measurements into the
// azimuth/elevation convention defined in types.ts.
//
// Key physical assumption used throughout: the sun is far enough away that
// its rays are effectively parallel across the photographer<->subject
// baseline (true to a small fraction of a degree at any human-scale
// distance), so an azimuthal offset measured relative to the subject is
// interchangeable with one measured relative to the photographer.

const DEFAULT_35MM_EQUIV_FOCAL = 50; // "normal" lens assumption when EXIF has none
const FULL_FRAME_SENSOR_WIDTH_MM = 36;
const FULL_FRAME_SENSOR_HEIGHT_MM = 24;

export function estimateFovDeg(focalLength35mm: number | null | undefined): {
  horizontalDeg: number;
  verticalDeg: number;
} {
  const f = focalLength35mm && focalLength35mm > 0 ? focalLength35mm : DEFAULT_35MM_EQUIV_FOCAL;
  const horizontalDeg = 2 * Math.atan(FULL_FRAME_SENSOR_WIDTH_MM / (2 * f)) * (180 / Math.PI);
  const verticalDeg = 2 * Math.atan(FULL_FRAME_SENSOR_HEIGHT_MM / (2 * f)) * (180 / Math.PI);
  return { horizontalDeg, verticalDeg };
}

/**
 * Maps a normalized image-plane offset (dxNorm, dyNorm each roughly -1..1,
 * relative to a reference point, image-y-down) to an azimuth/elevation
 * offset from "straight ahead," by linearly scaling across the camera's
 * field of view. This is an approximation (true perspective projection is
 * not linear in angle), acceptable for the modest offsets involved here.
 */
export function pixelOffsetToAzimuthElevation(
  dxNorm: number,
  dyNorm: number,
  hFovDeg: number,
  vFovDeg: number,
): { azimuthDeg: number; elevationDeg: number } {
  const azimuthDeg = (((dxNorm * hFovDeg) / 2) % 360 + 360) % 360;
  const elevationDeg = (-dyNorm * vFovDeg) / 2;
  return { azimuthDeg, elevationDeg };
}

/**
 * Converts a specular-highlight cone description (theta = total angular
 * deviation of the light from the camera's view axis, phi = direction of
 * that deviation measured from "up" rotating toward "right") into our
 * azimuth/elevation convention. See catchlight.ts for where theta/phi
 * come from.
 */
export function directionFromConeAngles(
  thetaRad: number,
  phiRad: number,
): { azimuthDeg: number; elevationDeg: number } {
  const x = Math.sin(thetaRad) * Math.sin(phiRad);
  const y = Math.sin(thetaRad) * Math.cos(phiRad);
  const z = Math.cos(thetaRad);
  const elevationDeg = Math.asin(Math.max(-1, Math.min(1, y))) * (180 / Math.PI);
  const azimuthDeg = (((Math.atan2(x, z) * 180) / Math.PI) + 360) % 360;
  return { azimuthDeg, elevationDeg };
}
