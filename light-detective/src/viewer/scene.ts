import * as THREE from "three";
import { OrbitControls } from "three/examples/jsm/controls/OrbitControls.js";
import { CSS2DRenderer, CSS2DObject } from "three/examples/jsm/renderers/CSS2DRenderer.js";
import { CONSENSUS_COLOR, INK_COLOR, LINE_COLOR } from "./colors";

const SPHERE_RADIUS = 6;
const MIN_VIZ_DISTANCE = SPHERE_RADIUS * 0.22;
const MAX_VIZ_DISTANCE = SPHERE_RADIUS * 0.62;
const MIN_REAL_METERS = 0.8;
const MAX_REAL_METERS = 12;

export interface SunDirection {
  azimuthDeg: number;
  elevationDeg: number;
}

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

function makeLabel(text: string): CSS2DObject {
  const div = document.createElement("div");
  div.textContent = text;
  div.style.color = INK_COLOR;
  div.style.fontFamily = '"Century Gothic", "Avant Garde", "ITC Avant Garde Gothic", "Urbanist", sans-serif';
  div.style.fontSize = "0.7rem";
  div.style.letterSpacing = "0.1em";
  div.style.textTransform = "uppercase";
  div.style.textShadow = "0 1px 3px rgba(0,0,0,0.9), 0 0 8px rgba(0,0,0,0.6)";
  div.style.pointerEvents = "none";
  div.style.whiteSpace = "nowrap";
  return new CSS2DObject(div);
}

export interface LightDetectiveScene {
  setResult(sun: SunDirection | null, distanceMeters: number): void;
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

  const labelRenderer = new CSS2DRenderer();
  labelRenderer.setSize(container.clientWidth, container.clientHeight);
  labelRenderer.domElement.style.position = "absolute";
  labelRenderer.domElement.style.top = "0";
  labelRenderer.domElement.style.left = "0";
  labelRenderer.domElement.style.pointerEvents = "none";
  container.appendChild(labelRenderer.domElement);

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
  const photographerLabel = makeLabel("Photographer");
  photographerLabel.position.set(0, 0.32, 0);
  photographerMarker.add(photographerLabel);
  scene.add(photographerMarker);

  // Subject, placed along the photographer's axis once we know the distance.
  const subjectMarker = new THREE.Mesh(
    new THREE.SphereGeometry(0.18, 16, 16),
    new THREE.MeshBasicMaterial({ color: INK_COLOR }),
  );
  const subjectLabel = makeLabel("Subject");
  subjectLabel.position.set(0, 0.36, 0);
  subjectMarker.add(subjectLabel);
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

  const sunGroup = new THREE.Group();
  scene.add(sunGroup);

  function clearSunGroup() {
    for (const child of [...sunGroup.children]) {
      sunGroup.remove(child);
      if (child instanceof THREE.Mesh) {
        child.geometry.dispose();
        (child.material as THREE.Material).dispose();
      }
    }
  }

  function setResult(sun: SunDirection | null, distanceMeters: number) {
    clearSunGroup();

    const vizDistance = mapDistanceToViz(distanceMeters);
    subjectMarker.position.set(0, 0, vizDistance);
    axisLine.geometry.setFromPoints([
      new THREE.Vector3(0, 0, 0),
      new THREE.Vector3(0, 0, vizDistance),
    ]);

    if (sun) {
      const pos = azElToPosition(sun.azimuthDeg, sun.elevationDeg, SPHERE_RADIUS);
      const dot = new THREE.Mesh(
        new THREE.SphereGeometry(0.26, 20, 20),
        new THREE.MeshBasicMaterial({ color: CONSENSUS_COLOR }),
      );
      dot.position.copy(pos);
      sunGroup.add(dot);

      // Added as a sibling of `dot`, not nested inside it: CSS2DObject only
      // auto-removes its DOM element when it directly receives a "removed"
      // event, which three.js dispatches on the object passed to .remove()
      // but not on that object's own children. Nesting this under `dot`
      // meant clearSunGroup()'s sunGroup.remove(dot) never reached the
      // label, leaking a stale "Sun" div across every photo.
      const sunLabel = makeLabel("Sun");
      sunLabel.position.copy(pos).add(new THREE.Vector3(0, 0.4, 0));
      sunGroup.add(sunLabel);

      const rayGeo = new THREE.BufferGeometry().setFromPoints([new THREE.Vector3(0, 0, 0), pos]);
      const ray = new THREE.Line(
        rayGeo,
        new THREE.LineBasicMaterial({ color: CONSENSUS_COLOR, transparent: true, opacity: 0.3 }),
      );
      sunGroup.add(ray);
    }
  }

  function handleResize() {
    const w = container.clientWidth;
    const h = container.clientHeight;
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
    renderer.setSize(w, h);
    labelRenderer.setSize(w, h);
  }
  window.addEventListener("resize", handleResize);

  let animationFrame = 0;
  function animate() {
    animationFrame = requestAnimationFrame(animate);
    controls.update();
    renderer.render(scene, camera);
    labelRenderer.render(scene, camera);
  }
  animate();

  function dispose() {
    cancelAnimationFrame(animationFrame);
    window.removeEventListener("resize", handleResize);
    controls.dispose();
    renderer.dispose();
    container.removeChild(renderer.domElement);
    container.removeChild(labelRenderer.domElement);
  }

  return { setResult, dispose };
}
