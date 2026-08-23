import type { EnsembleResult, EstimatorResult, SunEstimate } from "../types";
import { weightedCircularMeanDeg, weightedMean } from "../lib/circularMean";

export function buildEnsemble(results: EstimatorResult[]): EnsembleResult {
  const estimates: SunEstimate[] = results.filter(
    (r): r is SunEstimate => r !== null && r.confidence > 0,
  );

  if (estimates.length === 0) {
    return { consensus: null, estimates: [] };
  }

  const weights = estimates.map((e) => e.confidence);
  const azimuthDeg = weightedCircularMeanDeg(
    estimates.map((e) => e.azimuthDeg),
    weights,
  );
  const elevationDeg = weightedMean(
    estimates.map((e) => e.elevationDeg),
    weights,
  );
  // Consensus confidence rewards agreement, not just individual confidence:
  // an average of high-confidence-but-conflicting estimates should read
  // lower than the same average of estimates that actually agree.
  const meanConfidence = weightedMean(weights, weights.map(() => 1));
  const spread = circularSpreadDeg(estimates.map((e) => e.azimuthDeg), weights);
  const agreementFactor = Math.max(0, 1 - spread / 90);
  const confidence = meanConfidence * agreementFactor;

  return {
    consensus: { azimuthDeg, elevationDeg, confidence },
    estimates,
  };
}

/** Weighted circular standard-deviation-ish spread, in degrees, for the agreement penalty above. */
function circularSpreadDeg(anglesDeg: number[], weights: number[]): number {
  const meanDeg = weightedCircularMeanDeg(anglesDeg, weights);
  let weightSum = 0;
  let sqDiffSum = 0;
  for (let i = 0; i < anglesDeg.length; i++) {
    let diff = Math.abs(anglesDeg[i] - meanDeg) % 360;
    if (diff > 180) diff = 360 - diff;
    sqDiffSum += diff * diff * weights[i];
    weightSum += weights[i];
  }
  return weightSum > 0 ? Math.sqrt(sqDiffSum / weightSum) : 0;
}
