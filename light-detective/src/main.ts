import "./style.css";
import { loadImageToCanvas } from "./lib/imageCanvas";
import { readExifFacts } from "./lib/exif";
import { estimateSubjectDistance } from "./lib/distance";
import { getFaceLandmarker, getFaceDetector, getImageSegmenter } from "./lib/mediapipeModels";
import { detectFaceLandmarks } from "./lib/faceDetection";
import { estimateFromCatchlights } from "./estimators/catchlight";
import { estimateFromHighlights } from "./estimators/highlight";
import { estimateFromShadow } from "./estimators/shadow";
import { estimateFromExif, getTrueSunFact } from "./estimators/exifFastPath";
import { buildEnsemble } from "./estimators/ensemble";
import { createLightDetectiveScene, type LightDetectiveScene } from "./viewer/scene";
import { METHOD_COLORS, METHOD_LABELS, CONSENSUS_COLOR } from "./viewer/colors";
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
    <dl class="facts-panel" id="facts-panel"></dl>
    <div class="legend" id="legend"></div>
  </div>
`;

const uploadScreen = document.getElementById("upload-screen")!;
const dropzone = document.getElementById("dropzone")!;
const fileInput = document.getElementById("file-input") as HTMLInputElement;
const stage = document.getElementById("stage")!;
const statusLine = document.getElementById("status-line")!;
const factsPanel = document.getElementById("facts-panel")!;
const legend = document.getElementById("legend")!;
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

    const results: EstimatorResult[] = [
      estimateFromCatchlights(faceLandmarks, imageData, loaded.width, loaded.height),
      estimateFromHighlights(faceLandmarks, imageData, loaded.width, loaded.height),
      estimateFromShadow(segResult, imageData, loaded.width, loaded.height, exifFacts.focalLength35mm),
      estimateFromExif(exifFacts),
    ];

    const ensemble = buildEnsemble(results);
    const distance = estimateSubjectDistance(faceLandmarks, loaded.width, exifFacts.focalLength35mm);
    const trueSunFact = getTrueSunFact(exifFacts);

    if (!scene) {
      scene = createLightDetectiveScene(stage);
    }
    scene.setResult(ensemble, distance.meters ?? 3);

    renderFacts(ensemble, distance, trueSunFact, exifFacts.cameraHeadingDeg !== null);
    renderLegend(ensemble);

    if (ensemble.consensus) {
      setStatus(
        `<span class="accent">${ensemble.estimates.length}</span> method${
          ensemble.estimates.length === 1 ? "" : "s"
        } weighed in &mdash; drag to rotate the case file`,
      );
    } else {
      setStatus("No method could read a confident light direction from this photo.");
    }
  } catch (err) {
    console.error(err);
    setStatus("Something went wrong analyzing this photo. Try a different one.");
  }
}

function renderFacts(
  ensemble: ReturnType<typeof buildEnsemble>,
  distance: ReturnType<typeof estimateSubjectDistance>,
  trueSunFact: { azimuthDeg: number; elevationDeg: number } | null,
  hasHeading: boolean,
) {
  const rows: string[] = [];

  if (ensemble.consensus) {
    rows.push(
      `<dt>Consensus sun direction</dt><dd>azimuth ${ensemble.consensus.azimuthDeg.toFixed(
        0,
      )}&deg;, elevation ${ensemble.consensus.elevationDeg.toFixed(0)}&deg; (confidence ${(
        ensemble.consensus.confidence * 100
      ).toFixed(0)}%)</dd>`,
    );
  }

  rows.push(
    `<dt>Subject distance</dt><dd>${
      distance.meters !== null ? `~${distance.meters.toFixed(1)} m` : "unknown"
    } &mdash; ${distance.note}</dd>`,
  );

  if (trueSunFact) {
    rows.push(
      `<dt>True compass sun position (EXIF)</dt><dd>azimuth ${trueSunFact.azimuthDeg.toFixed(
        0,
      )}&deg; from North, elevation ${trueSunFact.elevationDeg.toFixed(0)}&deg;${
        hasHeading ? "" : " &mdash; camera heading not in EXIF, so not folded into the consensus above"
      }</dd>`,
    );
  }

  factsPanel.innerHTML = rows.join("");
}

function renderLegend(ensemble: ReturnType<typeof buildEnsemble>) {
  const rows = ensemble.estimates.map(
    (e) =>
      `${METHOD_LABELS[e.method]}<span class="swatch" style="background:${METHOD_COLORS[e.method]}"></span><br>`,
  );
  if (ensemble.consensus) {
    rows.push(`Consensus<span class="swatch" style="background:${CONSENSUS_COLOR}"></span>`);
  }
  legend.innerHTML = rows.join("");
}
