// Azimuth wraps at 0/360deg, so a naive arithmetic mean is wrong (e.g. 350deg
// and 10deg should average to 0deg, not 180deg). Average as unit vectors instead.

export function weightedCircularMeanDeg(anglesDeg: number[], weights: number[]): number {
  let x = 0;
  let y = 0;
  for (let i = 0; i < anglesDeg.length; i++) {
    const rad = (anglesDeg[i] * Math.PI) / 180;
    x += Math.cos(rad) * weights[i];
    y += Math.sin(rad) * weights[i];
  }
  const meanRad = Math.atan2(y, x);
  const meanDeg = (meanRad * 180) / Math.PI;
  return (meanDeg + 360) % 360;
}

export function weightedMean(values: number[], weights: number[]): number {
  let sum = 0;
  let weightSum = 0;
  for (let i = 0; i < values.length; i++) {
    sum += values[i] * weights[i];
    weightSum += weights[i];
  }
  return weightSum > 0 ? sum / weightSum : 0;
}
