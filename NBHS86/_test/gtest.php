<?php
// TEMPORARY server capability test for the gallery (delete after use). Prints results only; no secrets, no DB.
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
if (($_GET['run'] ?? '') !== '1') { exit("add ?run=1\n"); }
set_time_limit(120);
require __DIR__ . '/../lib/derive.php';
$dir = sys_get_temp_dir() . '/nbhs86-gtest-' . bin2hex(random_bytes(4));
mkdir($dir);
function line($k, $v) { echo str_pad($k, 44) . (is_bool($v) ? ($v ? 'yes' : 'NO') : $v) . "\n"; }
function t() { return microtime(true); }

echo "== environment ==\n";
line('php', PHP_VERSION);
line('memory_limit', ini_get('memory_limit'));
line('exiftool', nb_tool('exiftool') ?: 'MISSING');
line('ffmpeg', nb_tool('ffmpeg') ?: 'MISSING');
line('Imagick class', class_exists('Imagick'));

echo "\n== ZipStream on this PHP ==\n";
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
line('vendor/autoload.php present', is_file($autoload));
if (is_file($autoload)) {
    require $autoload;
    line('ZipStream class loads', class_exists('ZipStream\ZipStream'));
    try {
        file_put_contents("$dir/a.txt", str_repeat('A', 5000));
        file_put_contents("$dir/b.txt", str_repeat('B', 7000));
        $out = fopen("$dir/t.zip", 'wb');
        $z = new ZipStream\ZipStream(defaultCompressionMethod: ZipStream\CompressionMethod::STORE, enableZip64: false, sendHttpHeaders: false, outputStream: $out, outputName: 'x.zip');
        $z->addFileFromPath(fileName: 'NBHS_reunions_0001.jpg', path: "$dir/a.txt", compressionMethod: ZipStream\CompressionMethod::STORE);
        $z->addFileFromPath(fileName: 'Yearbook (2).pdf', path: "$dir/b.txt", compressionMethod: ZipStream\CompressionMethod::STORE);
        $z->finish();
        fclose($out);
        $za = new ZipArchive();
        $ok = $za->open("$dir/t.zip") === true;
        line('zip written and opens with ZipArchive', $ok);
        line('zip entries', $ok ? implode(' | ', [$za->getNameIndex(0), $za->getNameIndex(1)]) : '-');
        line('zip integrity (CRC) check', $ok && $za->locateName('Yearbook (2).pdf') !== false && strlen($za->getFromName('NBHS_reunions_0001.jpg')) === 5000);
    } catch (Throwable $e) {
        line('ZipStream error', get_class($e) . ': ' . $e->getMessage());
    }
}

echo "\n== HEIC (12 MP, 4032x3024, 4.6 MB) ==\n";
$src = __DIR__ . '/sample.heic';
line('sample present', is_file($src));
if (class_exists('Imagick')) {
    $fmts = Imagick::queryFormats('HEI*');
    line('Imagick HEIC/HEIF read support', implode(',', $fmts) ?: 'NONE');
}
$m0 = memory_get_peak_usage(true);
$t0 = t();
$full = "$dir/full.jpg";
$ok = nb_make_jpeg($src, $full, null, 92);
line('full-size JPEG conversion succeeded', $ok);
line('  time (s)', round(t() - $t0, 2));
if ($ok) {
    [$w, $h] = getimagesize($full);
    line('  output', "{$w}x{$h}, " . round(filesize($full) / 1048576, 2) . ' MB');
    line('  GPS present after conversion (before strip)', nb_has_gps($full));
    $t1 = t();
    line('  strip GPS ok', nb_strip_gps($full));
    line('  GPS present after strip', nb_has_gps($full));
    line('  strip time (s)', round(t() - $t1, 2));
}
$t2 = t();
$view = "$dir/view.jpg";
$ok2 = nb_make_jpeg($src, $view, NB_VIEW_PX, 85);
line('2400px lightbox JPEG succeeded', $ok2);
if ($ok2) { [$w, $h] = getimagesize($view); line('  output', "{$w}x{$h}, " . round(filesize($view) / 1048576, 2) . ' MB'); line('  time (s)', round(t() - $t2, 2)); }
line('PHP peak memory (MB, real)', round((memory_get_peak_usage(true) - $m0) / 1048576, 1) . ' above baseline');

echo "\n== GPS strip on JPEG and video (exiftool on the server) ==\n";
$im = imagecreatetruecolor(300, 200); imagejpeg($im, "$dir/p.jpg");
nb_run([nb_tool('exiftool'), '-q', '-overwrite_original', '-GPSLatitude=40.7', '-GPSLatitudeRef=N', '-GPSLongitude=74.0', '-GPSLongitudeRef=W', "$dir/p.jpg"]);
line('JPEG has GPS (setup)', nb_has_gps("$dir/p.jpg"));
line('JPEG strip ok', nb_strip_gps("$dir/p.jpg"));
if (nb_tool('ffmpeg')) {
    nb_run([nb_tool('ffmpeg'), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=64x64:d=1', '-pix_fmt', 'yuv420p', "$dir/v.mp4"]);
    nb_run([nb_tool('exiftool'), '-q', '-overwrite_original', '-Keys:GPSCoordinates=+40.7128-074.0060/', "$dir/v.mp4"]);
    line('MP4 has GPS (setup)', nb_has_gps("$dir/v.mp4"));
    line('MP4 strip ok', nb_strip_gps("$dir/v.mp4"));
    [$c] = nb_run([nb_tool('ffprobe') ?: 'ffprobe', '-v', 'error', "$dir/v.mp4"]);
    line('MP4 still valid after strip (ffprobe exit 0)', $c === 0);
}
exec('rm -rf ' . escapeshellarg($dir));
echo "\ndone\n";
