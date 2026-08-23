import "./style.css";
import { loadImageToCanvas } from "./lib/imageCanvas";
import { readExifFacts } from "./lib/exif";
import { estimateSubjectDistance } from "./lib/distance";
import { getFaceLandmarker, getFaceDetector, getImageSegmenter } from "./lib/mediapipeModels";
import { detectFaceLandmarks } from "./lib/faceDetection";
import { estimateFromCatchlights } from "./estimators/catchlight";
import { estimateFromHighlights } from "./estimators/highlight";
import { estimateFromShadow } from "./estimators/shadow";
import { estimateFromExif } from "./estimators/exifFastPath";
import { buildEnsemble } from "./estimators/ensemble";
import { createLightDetectiveScene, type LightDetectiveScene } from "./viewer/scene";
import type { EstimatorResult } from "./types";

const app = document.getElementById("app")!;

app.innerHTML = `
  <div class="upload-screen" id="upload-screen">
    <div class="upload-badge">Case File // Open</div>
    <h1 class="upload-title">Light Detective</h1>
    <label class="dropzone" id="dropzone">
      <p id="dropzone-text">Drop a photo here, or click to choose one</p>
      <input type="file" id="file-input" accept="image/*" />
    </label>
    <p class="upload-hint">
      Works best on outdoor, natural-light photos with a visible face. Nothing leaves your
      browser — analysis runs entirely on-device.
    </p>
  </div>
  <div class="stage" id="stage">
    <a href="#" class="reset-link" id="reset-link">New photo</a>
    <div class="status-line" id="status-line"></div>
    <div class="distance-note" id="distance-note"></div>
  </div>
`;

const uploadScreen = document.getElementById("upload-screen")!;
const dropzone = document.getElementById("dropzone")!;
const fileInput = document.getElementById("file-input") as HTMLInputElement;
const stage = document.getElementById("stage")!;
const statusLine = document.getElementById("status-line")!;
const distanceNote = document.getElementById("distance-note")!;
const resetLink = document.getElementById("reset-link")!;

let scene: LightDetectiveScene | null = null;

dropzone.addEventListener("dragover", (e) => {
  e.preventDefault();
  dropzone.classList.add("dragover");
});
dropzone.addEventListener("dragleave", () => dropzone.classList.remove("dragover"));
dropzone.addEventListener("drop", (e) => {
  e.preventDefault();
  dropzone.classList.remove("dragover");
  const file = e.dataTransfer?.files?.[0];
  if (file) handleFile(file);
});
fileInput.addEventListener("change", () => {
  const file = fileInput.files?.[0];
  if (file) handleFile(file);
});
resetLink.addEventListener("click", (e) => {
  e.preventDefault();
  location.reload();
});

function setStatus(text: string) {
  statusLine.innerHTML = text;
}

async function handleFile(file: File) {
  uploadScreen.style.display = "none";
  stage.classList.add("active");
  distanceNote.textContent = "";
  setStatus("Reading photo&hellip;");

  try {
    const [loaded, exifFacts] = await Promise.all([loadImageToCanvas(file), readExifFacts(file)]);

    setStatus("Loading analysis models&hellip;<br>(first run only, a few MB)");
    const [faceLandmarker, faceDetector, imageSegmenter] = await Promise.all([
      getFaceLandmarker(),
      getFaceDetector(),
      getImageSegmenter(),
    ]);

    setStatus("Analyzing lighting&hellip;");
    const faceLandmarks = detectFaceLandmarks(faceDetector, faceLandmarker, loaded.canvas);
    const segResult = imageSegmenter.segment(loaded.canvas);
    const imageData = loaded.ctx.getImageData(0, 0, loaded.width, loaded.height);

    // Every signal below feeds one combined sun position — none of it is
    // shown individually. The diagram is the answer, not the working.
    const results: EstimatorResult[] = [
      estimateFromCatchlights(faceLandmarks, imageData, loaded.width, loaded.height),
      estimateFromHighlights(faceLandmarks, imageData, loaded.width, loaded.height),
      estimateFromShadow(segResult, imageData, loaded.width, loaded.height, exifFacts.focalLength35mm),
      estimateFromExif(exifFacts),
    ];
    const ensemble = buildEnsemble(results);
    const distance = estimateSubjectDistance(faceLandmarks, loaded.width, exifFacts.focalLength35mm);

    if (!scene) {
      scene = createLightDetectiveScene(stage);
    }
    scene.setResult(ensemble.consensus, distance.meters ?? 3);

    if (ensemble.consensus) {
      setStatus("Drag to rotate");
      if (distance.meters !== null) {
        distanceNote.textContent = `Subject ~${distance.meters.toFixed(1)} m from photographer`;
      }
    } else {
      setStatus("Couldn&rsquo;t find a clear enough light direction in this photo &mdash; try another.");
    }
  } catch (err) {
    console.error(err);
    setStatus("Something went wrong analyzing this photo. Try a different one.");
  }
}
