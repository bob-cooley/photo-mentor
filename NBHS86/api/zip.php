<?php
declare(strict_types=1);

// Step 2: streams the selection as a zip (no compression: photos/videos are already compressed, so this is
// fast and uses almost no memory). The browser submits a plain form POST, so the download just starts.
require_once __DIR__ . '/../lib/selection.php';
require_once NB_ROOT . '/vendor/autoload.php';

use ZipStream\CompressionMethod;
use ZipStream\ZipStream;

nb_require_gallery();
set_time_limit(0);
ignore_user_abort(false);

$rows = nb_selected_rows();
$files = [];
foreach ($rows as $r) {
    $v = nb_variant($r, 'download', false);
    if ($v === null) {
        nb_json(['error' => 'not_ready', 'message' => 'Some files were not prepared yet. Go back and press Download again.'], 409);
    }
    $files[$r['id']] = $v;
}
$names = nb_zip_names($rows);

while (ob_get_level() > 0) {
    ob_end_clean();
}
nb_headers(false);
header('Cache-Control: no-store');
header('X-Accel-Buffering: no');
$zip = new ZipStream(
    defaultCompressionMethod: CompressionMethod::STORE,
    enableZip64: false, // our caps (500 files, 2 GB) are far below the 4 GB / 65k limits; opens everywhere
    sendHttpHeaders: true,
    outputName: 'NBHS_reunions_' . count($rows) . '_files.zip',
    flushOutput: true,
);
foreach ($rows as $r) {
    $zip->addFileFromPath(fileName: $names[$r['id']], path: $files[$r['id']]['path'], compressionMethod: CompressionMethod::STORE);
}
$zip->finish();
