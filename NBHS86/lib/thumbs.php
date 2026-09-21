<?php
declare(strict_types=1);

require_once __DIR__ . '/ingest.php';

const NB_THUMB_PX = 480;

function nb_thumb_path(string $id): string
{
    return nb_data_dir() . '/thumbs/' . $id . '.jpg';
}

/**
 * Returns the cached thumbnail path, generating it if needed. Called at upload time (so a folder is instant
 * to browse) and again on request as a fallback. Written to a private temp name then renamed, so a second
 * request can never see or serve a half-written file.
 */
function nb_thumb(array $row): ?string
{
    $out = nb_thumb_path($row['id']);
    if (is_file($out) && filesize($out) > 0) {
        return $out;
    }
    $src = nb_media_path($row['folder'], $row['id'], $row['ext']);
    if (!is_file($src)) {
        return null;
    }
    $tmp = $out . '.' . bin2hex(random_bytes(4)) . '.part.jpg';
    $ok = match ($row['kind']) {
        'video' => nb_thumb_video($src, $tmp),
        default => nb_thumb_image($src, $tmp, $row['kind'] === 'pdf'),
    };
    if ($ok && is_file($tmp) && filesize($tmp) > 0 && @rename($tmp, $out)) {
        return $out;
    }
    @unlink($tmp);
    return is_file($out) && filesize($out) > 0 ? $out : null;
}

function nb_thumb_image(string $src, string $out, bool $isPdf): bool
{
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick();
            $im->setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 96 * 1048576);
            $im->setResourceLimit(Imagick::RESOURCETYPE_MAP, 192 * 1048576);
            if ($isPdf) {
                $im->setResolution(96, 96);
            } else {
                $im->setOption('jpeg:size', (NB_THUMB_PX * 2) . 'x' . (NB_THUMB_PX * 2)); // DCT-scaled decode
            }
            $im->readImage($src . '[0]');
            $im->setImageBackgroundColor('white');
            if ($isPdf || $im->getImageAlphaChannel()) {
                $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            }
            if (!$isPdf && method_exists($im, 'autoOrient')) {
                $im->autoOrient();
            }
            $im->thumbnailImage(NB_THUMB_PX, NB_THUMB_PX, true);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(78);
            $im->stripImage();
            $ok = $im->writeImage($out);
            $im->clear();
            return $ok;
        } catch (Throwable) {
            // fall through to GD for formats it can handle
        }
    }
    return $isPdf ? false : nb_thumb_gd($src, $out);
}

function nb_thumb_gd(string $src, string $out): bool
{
    $info = @getimagesize($src);
    if (!$info) {
        return false;
    }
    [$w, $h, $type] = $info;
    if ($w * $h > 60_000_000) {
        return false;
    }
    $img = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG => @imagecreatefrompng($src),
        IMAGETYPE_GIF => @imagecreatefromgif($src),
        IMAGETYPE_WEBP => @imagecreatefromwebp($src),
        default => false,
    };
    if (!$img) {
        return false;
    }
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $orient = (int) (@exif_read_data($src)['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orient] ?? 0;
        if ($angle) {
            $img = imagerotate($img, $angle, 0);
        }
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $scale = min(1, NB_THUMB_PX / max($w, $h));
    $tw = max(1, (int) round($w * $scale));
    $th = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($tw, $th);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
    $ok = imagejpeg($dst, $out, 78);
    return $ok;
}

function nb_thumb_video(string $src, string $out): bool
{
    foreach (['1', '0'] as $seek) { // try 1 s in, fall back to first frame for very short clips
        $cmd = ['ffmpeg', '-v', 'error', '-y', '-ss', $seek, '-i', $src, '-frames:v', '1',
            '-vf', 'scale=' . NB_THUMB_PX . ':-2', '-q:v', '4', $out];
        $proc = @proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 20;
        while (($status = proc_get_status($proc))['running']) {
            if (microtime(true) > $deadline) {
                proc_terminate($proc, 9);
                break;
            }
            usleep(100000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        if (is_file($out) && filesize($out) > 0) {
            return true;
        }
    }
    return false;
}

/** Simple placeholder tile when no thumbnail can be produced. */
function nb_placeholder_svg(string $kind, string $ext): string
{
    $label = $kind === 'pdf' ? 'PDF' : ($kind === 'video' ? 'VIDEO' : strtoupper($ext));
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 480 360"><rect width="480" height="360" fill="#222"/>'
        . '<text x="240" y="195" font-family="sans-serif" font-size="44" fill="#888" text-anchor="middle">' . htmlspecialchars($label) . '</text></svg>';
}
