import type { FaceDetector, FaceLandmarker, NormalizedLandmark } from "@mediapipe/tasks-vision";

// FaceLandmarker's own bundled detector is short-range (tuned for
// close-up, front-facing, selfie-camera-style faces). Confirmed against
// real photography test photos: it misses faces even when large, in
// sharp focus, and well lit, if the shot is a normal environmental/
// documentary portrait rather than a tight selfie-style headshot. The
// full-range detector (blaze_face_full_range) catches what the bundled
// one misses; cropping tightly to its detection and re-running
// FaceLandmarker on the crop then lets landmark refinement (including
// iris) succeed where it failed on the full frame — verified empirically
// on the same test photos before building this.
const CROP_PADDING_FACTOR = 1.0; // extra margin around the detected box, as a multiple of box size

export function detectFaceLandmarks(
  faceDetector: FaceDetector,
  faceLandmarker: FaceLandmarker,
  canvas: HTMLCanvasElement,
): NormalizedLandmark[] | null {
  const direct = faceLandmarker.detect(canvas);
  if (direct.faceLandmarks?.[0]) {
    return direct.faceLandmarks[0];
  }

  const detection = faceDetector.detect(canvas);
  const box = detection.detections?.[0]?.boundingBox;
  if (!box) return null;

  const pad = Math.max(box.width, box.height) * CROP_PADDING_FACTOR;
  const sx = Math.max(0, box.originX - pad);
  const sy = Math.max(0, box.originY - pad);
  const sw = Math.min(canvas.width - sx, box.width + pad * 2);
  const sh = Math.min(canvas.height - sy, box.height + pad * 2);
  if (sw <= 0 || sh <= 0) return null;

  const cropCanvas = document.createElement("canvas");
  cropCanvas.width = sw;
  cropCanvas.height = sh;
  const cropCtx = cropCanvas.getContext("2d");
  if (!cropCtx) return null;
  cropCtx.drawImage(canvas, sx, sy, sw, sh, 0, 0, sw, sh);

  const cropped = faceLandmarker.detect(cropCanvas);
  const cropLandmarks = cropped.faceLandmarks?.[0];
  if (!cropLandmarks) return null;

  // Remap from crop-normalized back to full-image-normalized coordinates
  // so every downstream consumer can keep treating this as if it came
  // straight from the full frame.
  return cropLandmarks.map((lm) => ({
    ...lm,
    x: (lm.x * sw + sx) / canvas.width,
    y: (lm.y * sh + sy) / canvas.height,
  }));
}
