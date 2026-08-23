import * as THREE from "three";
import { OrbitControls } from "three/examples/jsm/controls/OrbitControls.js";
import type { EnsembleResult } from "../types";
import { METHOD_COLORS, CONSENSUS_COLOR, INK_COLOR, LINE_COLOR } from "./colors";

const SPHERE_RADIUS = 6;
const MIN_VIZ_DISTANCE = SPHERE_RADIUS * 0.22;
const MAX_VIZ_DISTANCE = SPHERE_RADIUS * 0.62;
const MIN_REAL_METERS = 0.8;
const MAX_REAL_METERS = 12;

function azElToPosition(azimuthDeg: number, elevationDeg: number, radius: number): THREE.Vector3 {
  const az = (azimuthDeg * Math.PI) / 180;
  const el = (elevationDeg * Math.PI) / 180;
  const x = radius * Math.cos(el) * Math.sin(az);
  const y = radius * Math.sin(el);
  const z = radius * Math.cos(el) * Math.cos(az);
  return new THREE.Vector3(x, y, z);
}

/** Clamped log-ish mapping so the subject marker stays legibly placed regardless of how extreme the distance estimate is. */
function mapDistanceToViz(meters: number): number {
  const clamped = Math.max(MIN_REAL_METERS, Math.min(MAX_REAL_METERS, meters));
  const t =
    (Math.log(clamped) - Math.log(MIN_REAL_METERS)) /
    (Math.log(MAX_REAL_METERS) - Math.log(MIN_REAL_METERS));
  return MIN_VIZ_DISTANCE + t * (MAX_VIZ_DISTANCE - MIN_VIZ_DISTANCE);
}

export interface LightDetectiveScene {
  setResult(ensemble: EnsembleResult, distanceMeters: number): void;
  dispose(): void;
}

export function createLightDetectiveScene(container: HTMLElement): LightDetectiveScene {
  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(
    45,
    container.clientWidth / container.clientHeight,
    0.1,
    1000,
  );
  camera.position.set(SPHERE_RADIUS * 1.6, SPHERE_RADIUS * 0.9, SPHERE_RADIUS * 1.6);

  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.setSize(container.clientWidth, container.clientHeight);
  container.appendChild(renderer.domElement);

  const controls = new OrbitControls(camera, renderer.domElement);
  controls.target.set(0, 0, 0); // the photographer is the fixed center of rotation
  controls.enableDamping = true;
  controls.dampingFactor = 0.06;
  controls.minDistance = SPHERE_RADIUS * 1.1;
  controls.maxDistance = SPHERE_RADIUS * 4;

  // Reference globe — thin wireframe only, deliberately minimal.
  const sphereGeo = new THREE.SphereGeometry(SPHERE_RADIUS, 24, 16);
  const wireframe = new THREE.WireframeGeometry(sphereGeo);
  const sphereLines = new THREE.LineSegments(
    wireframe,
    new THREE.LineBasicMaterial({ color: LINE_COLOR, transparent: true, opacity: 0.35 }),
  );
  scene.add(sphereLines);

  // Photographer, fixed at the absolute center.
  const photographerMarker = new THREE.Mesh(
    new THREE.SphereGeometry(0.14, 16, 16),
    new THREE.MeshBasicMaterial({ color: INK_COLOR }),
  );
  scene.add(photographerMarker);

  // Subject, placed along the photographer's axis once we know the distance.
  const subjectMarker = new THREE.Mesh(
    new THREE.SphereGeometry(0.18, 16, 16),
    new THREE.MeshBasicMaterial({ color: INK_COLOR }),
  );
  scene.add(subjectMarker);

  const axisLineGeo = new THREE.BufferGeometry().setFromPoints([
    new THREE.Vector3(0, 0, 0),
    new THREE.Vector3(0, 0, 0),
  ]);
  const axisLine = new THREE.Line(
    axisLineGeo,
    new THREE.LineBasicMaterial({ color: LINE_COLOR, transparent: true, opacity: 0.5 }),
  );
  scene.add(axisLine);

  const markerGroup = new THREE.Group();
  scene.add(markerGroup);

  function clearMarkerGroup() {
    for (const child of [...markerGroup.children]) {
      markerGroup.remove(child);
      if (child instanceof THREE.Mesh) {
        child.geometry.dispose();
        (child.material as THREE.Material).dispose();
      }
    }
  }

  function setResult(ensemble: EnsembleResult, distanceMeters: number) {
    clearMarkerGroup();

    const vizDistance = mapDistanceToViz(distanceMeters);
    subjectMarker.position.set(0, 0, vizDistance);
    axisLine.geometry.setFromPoints([
      new THREE.Vector3(0, 0, 0),
      new THREE.Vector3(0, 0, vizDistance),
    ]);

    for (const estimate of ensemble.estimates) {
      const pos = azElToPosition(estimate.azimuthDeg, estimate.elevationDeg, SPHERE_RADIUS * 0.97);
      const size = 0.08 + estimate.confidence * 0.08;
      const dot = new THREE.Mesh(
        new THREE.SphereGeometry(size, 12, 12),
        new THREE.MeshBasicMaterial({
          color: METHOD_COLORS[estimate.method],
          transparent: true,
          opacity: 0.4 + estimate.confidence * 0.5,
        }),
      );
      dot.position.copy(pos);
      markerGroup.add(dot);
    }

    if (ensemble.consensus) {
      const pos = azElToPosition(
        ensemble.consensus.azimuthDeg,
        ensemble.consensus.elevationDeg,
        SPHERE_RADIUS,
      );
      const dot = new THREE.Mesh(
        new THREE.SphereGeometry(0.26, 20, 20),
        new THREE.MeshBasicMaterial({ color: CONSENSUS_COLOR }),
      );
      dot.position.copy(pos);
      markerGroup.add(dot);

      const rayGeo = new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(0, 0, 0), pos]);
      const ray = new THREE.Line(
        rayGeo,
        new THREE.LineBasicMaterial({ color: CONSENSUS_COLOR, transparent: true, opacity: 0.3 }),
      );
      markerGroup.add(ray);
    }
  }

  function handleResize() {
    const w = container.clientWidth;
    const h = container.clientHeight;
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
    renderer.setSize(w, h);
  }
  window.addEventListener("resize", handleResize);

  let animationFrame = 0;
  function animate() {
    animationFrame = requestAnimationFrame(animate);
    controls.update();
    renderer.render(scene, camera);
  }
  animate();

  function dispose() {
    cancelAnimationFrame(animationFrame);
    window.removeEventListener("resize", handleResize);
    controls.dispose();
    renderer.dispose();
    container.removeChild(renderer.domElement);
  }

  return { setResult, dispose };
}
