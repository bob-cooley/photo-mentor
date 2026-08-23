import ExifReader from "exifreader";
import type { ExifFacts } from "../types";

export async function readExifFacts(file: File): Promise<ExifFacts> {
  const buffer = await file.arrayBuffer();
  const tags = ExifReader.load(buffer, { expanded: true });

  const gps = tags.gps;
  const exif = tags.exif ?? {};

  const gpsLat = typeof gps?.Latitude === "number" ? gps.Latitude : null;
  const gpsLon = typeof gps?.Longitude === "number" ? gps.Longitude : null;

  let timestamp: Date | null = null;
  const dateTimeOriginal = exif.DateTimeOriginal?.description;
  if (typeof dateTimeOriginal === "string") {
    // EXIF datetimes look like "2026:06:15 14:32:10" — normalize to ISO-ish.
    const iso = dateTimeOriginal.replace(/^(\d{4}):(\d{2}):(\d{2})/, "$1-$2-$3");
    const parsed = new Date(iso);
    if (!Number.isNaN(parsed.getTime())) timestamp = parsed;
  }

  let cameraHeadingDeg: number | null = null;
  const imgDirection = exif["GPSImgDirection"];
  if (imgDirection && typeof imgDirection.description === "string") {
    const val = parseFloat(imgDirection.description);
    if (!Number.isNaN(val)) cameraHeadingDeg = val;
  }

  let focalLength35mm: number | null = null;
  const focalIn35mm = exif.FocalLengthIn35mmFilm?.value;
  if (typeof focalIn35mm === "number") focalLength35mm = focalIn35mm;
  else if (Array.isArray(focalIn35mm) && typeof focalIn35mm[0] === "number") {
    focalLength35mm = focalIn35mm[0];
  }

  let imageWidthPx: number | null = null;
  const width = tags.file?.["Image Width"]?.value ?? exif.PixelXDimension?.value;
  if (typeof width === "number") imageWidthPx = width;

  return { gpsLat, gpsLon, timestamp, cameraHeadingDeg, focalLength35mm, imageWidthPx };
}
