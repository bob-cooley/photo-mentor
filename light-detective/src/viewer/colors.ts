import type { EstimatorMethod } from "../types";

export const METHOD_COLORS: Record<EstimatorMethod, string> = {
  shadow: "#4a90d9",
  catchlight: "#e0a030",
  highlight: "#5fbf7a",
  exif: "#c060c0",
};

export const METHOD_LABELS: Record<EstimatorMethod, string> = {
  shadow: "Shadow",
  catchlight: "Catchlight",
  highlight: "Facial highlight",
  exif: "EXIF (GPS + heading)",
};

export const CONSENSUS_COLOR = "#cc2222"; // site accent (#990000) lifted for visibility against near-black
export const INK_COLOR = "#e9e6e2";
export const LINE_COLOR = "#3a3838";
