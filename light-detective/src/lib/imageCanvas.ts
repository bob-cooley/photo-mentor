export interface LoadedImage {
  image: HTMLImageElement;
  canvas: HTMLCanvasElement;
  ctx: CanvasRenderingContext2D;
  width: number;
  height: number;
}

export async function loadImageToCanvas(file: File): Promise<LoadedImage> {
  const url = URL.createObjectURL(file);
  try {
    const image = await new Promise<HTMLImageElement>((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = reject;
      img.src = url;
    });

    const canvas = document.createElement("canvas");
    canvas.width = image.naturalWidth;
    canvas.height = image.naturalHeight;
    const ctx = canvas.getContext("2d", { willReadFrequently: true });
    if (!ctx) throw new Error("2D canvas context unavailable");
    ctx.drawImage(image, 0, 0);

    return { image, canvas, ctx, width: canvas.width, height: canvas.height };
  } finally {
    URL.revokeObjectURL(url);
  }
}

export function relativeLuminance(r: number, g: number, b: number): number {
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Average luminance within a square window centered at (cx, cy), pixel coords. */
export function averageLuminanceInWindow(
  data: ImageData,
  cx: number,
  cy: number,
  radiusPx: number,
): number {
  const { width, height, data: px } = data;
  let sum = 0;
  let count = 0;
  const x0 = Math.max(0, Math.floor(cx - radiusPx));
  const x1 = Math.min(width - 1, Math.ceil(cx + radiusPx));
  const y0 = Math.max(0, Math.floor(cy - radiusPx));
  const y1 = Math.min(height - 1, Math.ceil(cy + radiusPx));
  for (let y = y0; y <= y1; y++) {
    for (let x = x0; x <= x1; x++) {
      const i = (y * width + x) * 4;
      sum += relativeLuminance(px[i], px[i + 1], px[i + 2]);
      count++;
    }
  }
  return count > 0 ? sum / count : 0;
}

/** Finds the brightest pixel within a square search window. Returns image-space coords. */
export function findBrightestPixel(
  data: ImageData,
  cx: number,
  cy: number,
  radiusPx: number,
): { x: number; y: number; luminance: number } {
  const { width, height, data: px } = data;
  let best = { x: cx, y: cy, luminance: -1 };
  const x0 = Math.max(0, Math.floor(cx - radiusPx));
  const x1 = Math.min(width - 1, Math.ceil(cx + radiusPx));
  const y0 = Math.max(0, Math.floor(cy - radiusPx));
  const y1 = Math.min(height - 1, Math.ceil(cy + radiusPx));
  for (let y = y0; y <= y1; y++) {
    for (let x = x0; x <= x1; x++) {
      const i = (y * width + x) * 4;
      const lum = relativeLuminance(px[i], px[i + 1], px[i + 2]);
      if (lum > best.luminance) best = { x, y, luminance: lum };
    }
  }
  return best;
}
