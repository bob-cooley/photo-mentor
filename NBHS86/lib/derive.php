<?php
declare(strict_types=1);

/**
 * Derived files. Everything a classmate can view or download comes from here, never straight from the
 * originals, so that:
 *   - GPS location data is stripped (decision: strip on all views and downloads),
 *   - HEIC downloads as a full-size JPEG, and HEIC/TIFF get a 2400px JPEG for the lightbox.
 * Originals are only served to the admin (?raw=1). Derivatives are created on first use and cached.
 */

require_once __DIR__ . '/ingest.php';

const NB_VIEW_PX = 2400;
/** Formats exiftool can rewrite. GPS is stripped from these; other video containers are served as-is. */
const NB_STRIPPABLE = ['jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'heic', 'heif', 'avif', 'gif', 'mp4', 'm4v', 'mov', '3gp'];

function nb_derived_dir(): string
{
    $d = nb_data_dir() . '/derived';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    return $d;
}

function nb_src_path(array $row): string
{
    return nb_media_path($row['folder'], $row['id'], $row['ext']);
}

/** Path of a command-line tool, or null. Cached per request. */
function nb_tool(string $name): ?string
{
    static $cache = [];
    if (!array_key_exists($name, $cache)) {
        $out = [];
        @exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null', $out);
        $cache[$name] = isset($out[0]) && $out[0] !== '' ? $out[0] : null;
    }
    return $cache[$name];
}

/** Run a command (no shell) with a timeout. @return array{0:int,1:string} exit code, stdout */
function nb_run(array $cmd, int $timeout = 60): array
{
    $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return [-1, ''];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $deadline = microtime(true) + $timeout;
    while (true) {
        $out .= (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        $st = proc_get_status($proc);
        if (!$st['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            return [-2, $out];
        }
        usleep(50000);
    }
    $out .= (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = $st['exitcode'];
    proc_close($proc);
    return [(int) $code, $out];
}

// ---------- GPS ----------

function nb_has_gps(string $path): bool
{
    $et = nb_tool('exiftool');
    if (!$et) {
        return false;
    }
    [, $out] = nb_run([$et, '-a', '-G1', '-s', '-*gps*', $path], 60);
    return trim($out) !== '';
}

/** Strip every GPS tag in place. True only if exiftool ran and no GPS tag remains afterwards. */
function nb_strip_gps(string $path): bool
{
    $et = nb_tool('exiftool');
    if (!$et) {
        return false;
    }
    nb_run([$et, '-q', '-overwrite_original', '-gps*=', $path], 300);
    return !nb_has_gps($path);
}

// ---------- image conversion ----------

/** Write a JPEG copy of an image (HEIC, TIFF, ...). $maxEdge null = full size and keep non-GPS metadata. */
function nb_make_jpeg(string $src, string $dst, ?int $maxEdge, int $quality): bool
{
    $tmp = $dst . '.part.jpg';
    $ok = false;
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick();
            $im->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 160 * 1048576);
            $im->setResourceLimit(Imagick::RESOURCETYPE_MAP, 320 * 1048576);
            $im->readImage($src . '[0]');
            if (method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            }
            $im->setImageBackgroundColor('white');
            if ($im->getImageAlphaChannel()) {
                $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }
            if ($maxEdge !== null && max($im->getImageWidth(), $im->getImageHeight()) > $maxEdge) {
                $im->resizeImage($maxEdge, $maxEdge, Imagick::FILTER_LANCZOS, 1, true);
            }
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality($quality);
            if ($maxEdge !== null) {
                $im->stripImage();
            }
            $ok = $im->writeImage($tmp);
            $im->clear();
        } catch (Throwable) {
            $ok = false;
        }
    }
    if (!$ok || !is_file($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        $magick = nb_tool('magick') ?? nb_tool('convert');
        if ($magick) {
            $cmd = [$magick, '-limit', 'memory', '160MiB', '-limit', 'map', '320MiB', $src . '[0]', '-auto-orient'];
            if ($maxEdge !== null) {
                array_push($cmd, '-resize', $maxEdge . 'x' . $maxEdge . '>', '-strip');
            }
            array_push($cmd, '-quality', (string) $quality, $tmp);
            nb_run($cmd, 120);
        }
    }
    if (is_file($tmp) && filesize($tmp) > 0 && @rename($tmp, $dst)) {
        return true;
    }
    @unlink($tmp);
    return false;
}

// ---------- variants ----------

function nb_needs_convert(array $row): bool
{
    return $row['kind'] === 'image' && in_array($row['ext'], ['heic', 'heif', 'tif', 'tiff'], true);
}

/**
 * The GPS-free copy of the original file (same format). Returns the original's path when it has no GPS,
 * the cleaned copy otherwise, or null if GPS is present but could not be removed.
 */
function nb_clean_path(array $row, bool $create): ?string
{
    $src = nb_src_path($row);
    $dir = nb_derived_dir();
    $clean = "$dir/{$row['id']}-clean.{$row['ext']}";
    $none = "$dir/{$row['id']}-clean.none";
    if (is_file($clean)) {
        return $clean;
    }
    if (is_file($none)) {
        return $src;
    }
    if (!$create) {
        return null;
    }
    if (!nb_tool('exiftool')) {
        return $src; // development machine without exiftool; production has it (checked 2026-09-20)
    }
    if (!nb_has_gps($src)) {
        @touch($none);
        return $src;
    }
    if (!in_array($row['ext'], NB_STRIPPABLE, true)) {
        return $src; // container exiftool cannot rewrite; GPS in such files is very unlikely
    }
    $tmp = "$dir/{$row['id']}-tmp.{$row['ext']}";
    if (!@copy($src, $tmp)) {
        return null;
    }
    if (nb_strip_gps($tmp) && @rename($tmp, $clean)) {
        return $clean;
    }
    @unlink($tmp);
    return null;
}

/**
 * Where to serve a file from. $variant: 'view' (inline: lightbox, player) or 'download'.
 * @return array{path:string,ext:string,mime:string}|null null = not ready (create=false) or failed
 */
function nb_variant(array $row, string $variant, bool $create = true): ?array
{
    $ext = $row['ext'];
    $path = null;
    if ($row['kind'] === 'pdf') {
        $path = nb_src_path($row);
    } elseif ($row['kind'] === 'image' && $variant === 'view' && nb_needs_convert($row)) {
        $path = nb_derived_dir() . "/{$row['id']}-view.jpg";
        $ext = 'jpg';
        if (!is_file($path) && (!$create || !nb_make_jpeg(nb_src_path($row), $path, NB_VIEW_PX, 85))) {
            return null;
        }
    } elseif ($row['kind'] === 'image' && $variant === 'download' && in_array($ext, ['heic', 'heif'], true)) {
        $path = nb_derived_dir() . "/{$row['id']}-dl.jpg";
        $ext = 'jpg';
        if (!is_file($path)) {
            if (!$create || !nb_make_jpeg(nb_src_path($row), $path, null, 92)) {
                return null;
            }
            if (nb_tool('exiftool') && !nb_strip_gps($path)) {
                @unlink($path);
                return null;
            }
        }
    } else {
        $path = nb_clean_path($row, $create);
        if ($path === null) {
            return null;
        }
    }
    return ['path' => $path, 'ext' => $ext, 'mime' => NB_MIME[$ext] ?? 'application/octet-stream'];
}

// ---------- metadata (dimensions, shot date) ----------

/** Read width/height (orientation-corrected) and capture time; store them. Failures store 0 so we don't retry forever. */
function nb_extract_meta(array $row): void
{
    $w = $h = 0;
    $taken = null;
    if ($row['kind'] !== 'pdf') {
        $src = nb_src_path($row);
        $et = nb_tool('exiftool');
        if ($et) {
            [, $json] = nb_run([$et, '-j', '-n', '-ImageWidth', '-ImageHeight', '-Orientation', '-DateTimeOriginal', '-CreateDate', '-MediaCreateDate', $src], 30);
            $d = json_decode($json, true)[0] ?? [];
            $w = (int) ($d['ImageWidth'] ?? 0);
            $h = (int) ($d['ImageHeight'] ?? 0);
            if (in_array((int) ($d['Orientation'] ?? 1), [5, 6, 7, 8], true)) {
                [$w, $h] = [$h, $w];
            }
            foreach (['DateTimeOriginal', 'CreateDate', 'MediaCreateDate'] as $k) {
                if (!empty($d[$k]) && preg_match('/^(\d{4}):(\d\d):(\d\d)[ T](\d\d:\d\d:\d\d)/', (string) $d[$k], $m) && (int) $m[1] > 1800) {
                    $t = strtotime("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}");
                    if ($t !== false) {
                        $taken = $t;
                        break;
                    }
                }
            }
        } elseif ($row['kind'] === 'image' && ($info = @getimagesize($src))) {
            [$w, $h] = $info;
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data') && ($ex = @exif_read_data($src))) {
                if (in_array((int) ($ex['Orientation'] ?? 1), [5, 6, 7, 8], true)) {
                    [$w, $h] = [$h, $w];
                }
                $dt = $ex['DateTimeOriginal'] ?? $ex['DateTime'] ?? null;
                if ($dt && preg_match('/^(\d{4}):(\d\d):(\d\d) (\d\d:\d\d:\d\d)/', $dt, $m) && (int) $m[1] > 1800) {
                    $taken = strtotime("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}") ?: null;
                }
            }
        }
    }
    nb_db()->prepare('UPDATE media SET w = ?, h = ?, taken_at = ? WHERE id = ?')->execute([$w, $h, $taken, $row['id']]);
}

/** Fill in metadata for rows that don't have it yet (existing uploads, old zips). Time-boxed. */
function nb_fill_meta(int $max = 40, float $budget = 5.0): int
{
    $deadline = microtime(true) + $budget;
    $rows = nb_db()->query('SELECT * FROM media WHERE w IS NULL ORDER BY seq, rowid LIMIT ' . $max)->fetchAll();
    $done = 0;
    foreach ($rows as $row) {
        if (microtime(true) > $deadline) {
            break;
        }
        nb_extract_meta($row);
        $done++;
    }
    return $done;
}

/**
 * Unique names for a zip: NBHS names are already unique; documents keep their own names so identical
 * ones get " (2)", " (3)".
 * @param array<int,array> $rows
 * @return array<string,string> media id => entry name
 */
function nb_zip_names(array $rows): array
{
    $used = [];
    $out = [];
    foreach ($rows as $row) {
        $name = nb_download_name($row, in_array($row['ext'], ['heic', 'heif'], true));
        $base = $name;
        $i = 2;
        while (isset($used[mb_strtolower($name)])) {
            $dot = strrpos($base, '.');
            $name = $dot === false ? "$base ($i)" : substr($base, 0, $dot) . " ($i)" . substr($base, $dot);
            $i++;
        }
        $used[mb_strtolower($name)] = true;
        $out[$row['id']] = $name;
    }
    return $out;
}
