// Downloads the MediaPipe model weights this app self-hosts (rather than
// depending on Google's CDN staying up at runtime). Run automatically
// before `dev`/`build`; skips files already present.
import { existsSync, mkdirSync } from "node:fs";
import { pipeline } from "node:stream/promises";
import { Readable } from "node:stream";
import path from "node:path";
import { fileURLToPath } from "node:url";
import fs from "node:fs";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const modelsDir = path.join(__dirname, "..", "public", "models");
const wasmSrcDir = path.join(__dirname, "..", "node_modules", "@mediapipe", "tasks-vision", "wasm");
const wasmDestDir = path.join(__dirname, "..", "public", "mediapipe-wasm");

const MODELS = [
  {
    name: "face_landmarker.task",
    url: "https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/latest/face_landmarker.task",
  },
  {
    name: "selfie_segmenter.tflite",
    url: "https://storage.googleapis.com/mediapipe-models/image_segmenter/selfie_segmenter/float16/latest/selfie_segmenter.tflite",
  },
  {
    // FaceLandmarker's bundled detector is short-range (selfie-camera
    // style) and misses faces in ordinary photographic framing even when
    // large and well-lit — confirmed against real test photos. This
    // full-range detector is used as a fallback: find the face, crop to
    // it, then re-run FaceLandmarker on the crop. See lib/faceDetection.ts.
    name: "blaze_face_full_range.tflite",
    url: "https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_full_range/float16/latest/blaze_face_full_range.tflite",
  },
];

async function download(url, destPath) {
  const res = await fetch(url);
  if (!res.ok || !res.body) {
    throw new Error(`Failed to fetch ${url}: ${res.status} ${res.statusText}`);
  }
  await pipeline(Readable.fromWeb(res.body), fs.createWriteStream(destPath));
}

async function main() {
  mkdirSync(modelsDir, { recursive: true });
  for (const model of MODELS) {
    const dest = path.join(modelsDir, model.name);
    if (existsSync(dest)) {
      console.log(`[fetch-models] ${model.name} already present, skipping`);
      continue;
    }
    console.log(`[fetch-models] downloading ${model.name}...`);
    await download(model.url, dest);
    console.log(`[fetch-models] saved ${dest}`);
  }

  // The Tasks Vision WASM runtime ships inside the npm package itself
  // (no download needed) but must live under public/ to be served
  // alongside the built app rather than pulled from a CDN at runtime.
  if (existsSync(wasmSrcDir)) {
    mkdirSync(wasmDestDir, { recursive: true });
    fs.cpSync(wasmSrcDir, wasmDestDir, { recursive: true });
    console.log(`[fetch-models] copied MediaPipe wasm runtime to ${wasmDestDir}`);
  } else {
    console.warn(`[fetch-models] ${wasmSrcDir} not found — run npm install first`);
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
