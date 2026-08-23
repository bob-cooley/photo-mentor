import {
  FilesetResolver,
  FaceLandmarker,
  FaceDetector,
  ImageSegmenter,
} from "@mediapipe/tasks-vision";

// All paths resolve relative to Vite's BASE_URL so this works both in dev
// (served from /) and production (served from /light-detective/).
const base = import.meta.env.BASE_URL;

let visionFilesetPromise: ReturnType<typeof FilesetResolver.forVisionTasks> | null = null;

function getVisionFileset() {
  if (!visionFilesetPromise) {
    visionFilesetPromise = FilesetResolver.forVisionTasks(`${base}mediapipe-wasm`);
  }
  return visionFilesetPromise;
}

let faceLandmarkerPromise: Promise<FaceLandmarker> | null = null;

export function getFaceLandmarker(): Promise<FaceLandmarker> {
  if (!faceLandmarkerPromise) {
    faceLandmarkerPromise = getVisionFileset().then((fileset) =>
      FaceLandmarker.createFromOptions(fileset, {
        baseOptions: {
          modelAssetPath: `${base}models/face_landmarker.task`,
        },
        runningMode: "IMAGE",
        numFaces: 1,
        outputFaceBlendshapes: false,
        outputFacialTransformationMatrixes: true,
      }),
    );
  }
  return faceLandmarkerPromise;
}

let faceDetectorPromise: Promise<FaceDetector> | null = null;

/** Full-range detector, used as a fallback when FaceLandmarker's own
 * bundled (short-range/selfie-style) detector misses a face — see
 * lib/faceDetection.ts for why this is needed. */
export function getFaceDetector(): Promise<FaceDetector> {
  if (!faceDetectorPromise) {
    faceDetectorPromise = getVisionFileset().then((fileset) =>
      FaceDetector.createFromOptions(fileset, {
        baseOptions: {
          modelAssetPath: `${base}models/blaze_face_full_range.tflite`,
        },
        runningMode: "IMAGE",
        minDetectionConfidence: 0.3,
      }),
    );
  }
  return faceDetectorPromise;
}

let imageSegmenterPromise: Promise<ImageSegmenter> | null = null;

export function getImageSegmenter(): Promise<ImageSegmenter> {
  if (!imageSegmenterPromise) {
    imageSegmenterPromise = getVisionFileset().then((fileset) =>
      ImageSegmenter.createFromOptions(fileset, {
        baseOptions: {
          modelAssetPath: `${base}models/selfie_segmenter.tflite`,
        },
        runningMode: "IMAGE",
        outputCategoryMask: true,
        outputConfidenceMasks: false,
      }),
    );
  }
  return imageSegmenterPromise;
}
