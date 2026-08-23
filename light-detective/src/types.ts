// Shared vocabulary for every estimator + the ensemble/viewer that consume them.
//
// Coordinate convention (locked once, used everywhere):
//   - The photographer->subject axis is +Z ("into the scene").
//   - +Y is up.
//   - azimuthDeg is measured clockwise around +Y starting at +Z (0deg = straight
//     ahead of the photographer, matching the subject's position; 90deg = to the
//     photographer's right; 180deg = directly behind the photographer).
//   - elevationDeg is measured up from the horizontal (Y=0) plane, 0..90.

export interface SunEstimate {
  method: EstimatorMethod;
  azimuthDeg: number;
  elevationDeg: number;
  /** 0..1, how much weight this estimate should carry in the ensemble. */
  confidence: number;
  /** Short human-readable note surfaced in the UI (why this confidence, caveats). */
  note?: string;
}

export type EstimatorMethod =
  | "shadow"
  | "catchlight"
  | "highlight"
  | "exif";

/** Returned by an estimator that has nothing to say about this photo. */
export type EstimatorResult = SunEstimate | null;

export interface EnsembleResult {
  consensus: { azimuthDeg: number; elevationDeg: number; confidence: number } | null;
  estimates: SunEstimate[];
}

export interface DistanceEstimate {
  meters: number | null;
  approximate: boolean;
  note: string;
}

export interface ExifFacts {
  gpsLat: number | null;
  gpsLon: number | null;
  timestamp: Date | null;
  cameraHeadingDeg: number | null;
  focalLength35mm: number | null;
  imageWidthPx: number | null;
}
