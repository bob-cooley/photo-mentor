<?php
declare(strict_types=1);

/**
 * Minimal tus 1.0.0 server (core + creation + termination), just enough for
 * Uppy's tus client: chunked, resumable uploads that stay under Cloudflare's
 * 100 MB request cap and PHP's 30 s execution limit.
 * Routed from /NBHS86/api/tus/<id> by api/.htaccess.
 */

require_once __DIR__ . '/../lib/ingest.php';

nb_headers();
header('Tus-Resumable: 1.0.0');
set_time_limit(120);

function tus_fail(int $code, string $msg = ''): never
{
    http_response_code($code);
    if ($msg !== '') {
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
    exit;
}

if (!nb_is_member()) {
    tus_fail(401, 'Please reload the page and enter the class passcode again.');
}

$method = $_SERVER['REQUEST_METHOD'];
$id = (string) ($_GET['id'] ?? '');
if ($method === 'POST' && $id !== '' && strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? '') === 'PATCH') {
    $method = 'PATCH';
}

if ($method === 'OPTIONS') {
    header('Tus-Version: 1.0.0');
    header('Tus-Extension: creation,termination');
    header('Tus-Max-Size: ' . NB_MAX_FILE);
    tus_fail(204);
}
if (($_SERVER['HTTP_TUS_RESUMABLE'] ?? '') !== '1.0.0') {
    header('Tus-Version: 1.0.0');
    tus_fail(412);
}

$dir = nb_data_dir() . '/tus';

// ---- create ----
if ($method === 'POST' && $id === '') {
    $len = $_SERVER['HTTP_UPLOAD_LENGTH'] ?? '';
    if (!ctype_digit($len) || (int) $len < 1) {
        tus_fail(400, 'Missing file size.');
    }
    if ((int) $len > NB_MAX_FILE) {
        tus_fail(413, 'That file is larger than the 2 GB limit.');
    }

    $meta = [];
    foreach (explode(',', $_SERVER['HTTP_UPLOAD_METADATA'] ?? '') as $pair) {
        $kv = explode(' ', trim($pair), 2);
        if ($kv[0] !== '') {
            $meta[$kv[0]] = isset($kv[1]) ? (string) base64_decode($kv[1], true) : '';
        }
    }
    $filename = nb_clean_filename($meta['name'] ?? $meta['filename'] ?? ''); // Uppy sends `name`; plain tus clients send `filename`
    $kind = NB_TYPES[nb_ext($filename)] ?? null;
    if ($kind === null) {
        tus_fail(415, '"' . $filename . '" is not a supported file type. Use photos, videos, PDFs, or .zip files.');
    }

    $newId = bin2hex(random_bytes(16));
    file_put_contents($dir . '/' . $newId . '.bin', '');
    file_put_contents($dir . '/' . $newId . '.json', json_encode([
        'length' => (int) $len,
        'filename' => $filename,
        'uploader' => nb_clean_person_name($meta['uploader'] ?? ''),
        'anonymous' => ($meta['anonymous'] ?? '') === '1' ? 1 : 0,
        'batch' => preg_replace('/[^a-zA-Z0-9_-]/', '', substr($meta['batch'] ?? '', 0, 40)),
        'created' => time(),
    ]));

    // Occasionally discard abandoned partial uploads (> 3 days old).
    if (random_int(1, 40) === 1) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - 3 * 86400) {
                @unlink($f);
            }
        }
    }

    header('Location: ' . NB_BASE . '/api/tus/' . $newId);
    tus_fail(201);
}

// ---- existing upload ----
if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
    tus_fail(404);
}
$bin = $dir . '/' . $id . '.bin';
$jsonPath = $dir . '/' . $id . '.json';
if (!is_file($bin) || !is_file($jsonPath)) {
    tus_fail(404);
}
$info = json_decode((string) file_get_contents($jsonPath), true);
if (!is_array($info)) {
    tus_fail(404);
}
$length = (int) $info['length'];

if ($method === 'HEAD') {
    clearstatcache(true, $bin);
    header('Upload-Offset: ' . filesize($bin));
    header('Upload-Length: ' . $length);
    header('Cache-Control: no-store');
    tus_fail(200);
}

if ($method === 'DELETE') {
    @unlink($bin);
    @unlink($jsonPath);
    tus_fail(204);
}

if ($method !== 'PATCH') {
    tus_fail(405);
}
if (($_SERVER['CONTENT_TYPE'] ?? '') !== 'application/offset+octet-stream') {
    tus_fail(415);
}

$fh = fopen($bin, 'ab');
if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
    tus_fail(423);
}
clearstatcache(true, $bin);
$offset = (int) filesize($bin);
if ((string) $offset !== ($_SERVER['HTTP_UPLOAD_OFFSET'] ?? '')) {
    flock($fh, LOCK_UN);
    fclose($fh);
    tus_fail(409);
}

$in = fopen('php://input', 'rb');
$written = 0;
$overflow = false;
while (!feof($in)) {
    $buf = fread($in, 1048576);
    if ($buf === false || $buf === '') {
        break;
    }
    if ($offset + $written + strlen($buf) > $length) {
        $overflow = true;
        break;
    }
    fwrite($fh, $buf);
    $written += strlen($buf);
}
fclose($in);
fflush($fh);
flock($fh, LOCK_UN);
fclose($fh);

if ($overflow) {
    tus_fail(413, 'Upload is larger than declared.');
}

$newOffset = $offset + $written;
header('Upload-Offset: ' . $newOffset);

if ($newOffset === $length) {
    @unlink($jsonPath);
    $res = nb_ingest_upload($bin, $info);
    if ($res['status'] === 'rejected') {
        tus_fail(422, '"' . $info['filename'] . '" could not be accepted (the file does not look like a valid ' . (NB_TYPES[nb_ext($info['filename'])] ?? 'supported') . ' file).');
    }
}
tus_fail(204);
