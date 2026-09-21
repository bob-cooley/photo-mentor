<?php
declare(strict_types=1);

// Gated file delivery with Range support (video seeking).
//   ?id=X            inline, GPS-free copy (HEIC/TIFF shown as a 2400px JPEG)
//   ?id=X&dl=1       download under the NBHS name (HEIC as full-size JPEG)
//   ?id=X&raw=1      the untouched original, admin only
require_once __DIR__ . '/../lib/derive.php';

nb_require_gallery();
set_time_limit(0);

$id = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
    http_response_code(404);
    exit;
}
$st = nb_db()->prepare('SELECT * FROM media WHERE id = ?');
$st->execute([$id]);
$row = $st->fetch();
if (!$row || !is_file(nb_src_path($row))) {
    http_response_code(404);
    exit;
}

$download = !empty($_GET['dl']);
if (!empty($_GET['raw']) && nb_is_admin()) {
    $path = nb_src_path($row);
    $mime = NB_MIME[$row['ext']] ?? 'application/octet-stream';
    $outName = $row['orig_name'];
} else {
    $v = nb_variant($row, $download ? 'download' : 'view');
    if (!$v) {
        nb_json(['error' => 'unavailable', 'message' => 'This file could not be prepared. Please try again.'], 503);
    }
    $path = $v['path'];
    $mime = $v['mime'];
    $outName = nb_download_name($row, $v['ext'] === 'jpg' && in_array($row['ext'], ['heic', 'heif'], true));
}

$size = (int) filesize($path);
$start = 0;
$end = $size - 1;
$status = 200;
if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'], $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') {
        $start = max(0, $size - (int) $m[2]);
    } else {
        $start = (int) $m[1];
        $end = $m[2] !== '' ? min((int) $m[2], $size - 1) : $end;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header('Content-Range: bytes */' . $size);
        exit;
    }
    $status = 206;
}

$fname = str_replace(['"', '\\', '/'], '_', $outName);
nb_headers(false);
http_response_code($status);
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
    . '; filename="' . preg_replace('/[^\x20-\x7E]/', '_', $fname) . '"; filename*=UTF-8\'\'' . rawurlencode($outName));
if ($status === 206) {
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));

$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh) && !connection_aborted()) {
    $chunk = fread($fh, min(1048576, $left));
    if ($chunk === false) {
        break;
    }
    echo $chunk;
    $left -= strlen($chunk);
    flush();
}
fclose($fh);
