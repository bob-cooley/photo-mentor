<?php
declare(strict_types=1);

// Gated file delivery with Range support (video seeking). ?dl=1 forces a download.
require_once __DIR__ . '/../lib/ingest.php';

nb_require_member();
set_time_limit(0);

$id = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[a-f0-9]{12}$/', $id)) {
    http_response_code(404);
    exit;
}
$st = nb_db()->prepare('SELECT * FROM media WHERE id = ?');
$st->execute([$id]);
$row = $st->fetch();
$path = $row ? nb_media_path($row['folder'], $row['id'], $row['ext']) : '';
if (!$row || !is_file($path)) {
    http_response_code(404);
    exit;
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

$download = !empty($_GET['dl']);
$fname = str_replace(['"', '\\', '/'], '_', $row['orig_name']);
nb_headers(false);
http_response_code($status);
header('Content-Type: ' . (NB_MIME[$row['ext']] ?? 'application/octet-stream'));
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=86400');
header('Content-Disposition: ' . ($download ? 'attachment' : 'inline')
    . '; filename="' . preg_replace('/[^\x20-\x7E]/', '_', $fname) . '"; filename*=UTF-8\'\'' . rawurlencode($row['orig_name']));
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
