<?php
declare(strict_types=1);

// Step 1 of a zip download: builds the GPS-free / JPEG copies for the selection in short, time-boxed
// batches so the actual zip can then start streaming immediately. POST ids=comma,separated
require_once __DIR__ . '/../lib/selection.php';

nb_require_gallery();
set_time_limit(60);

$rows = nb_selected_rows();
$deadline = microtime(true) + 12;
$ready = 0;
$failed = 0;
foreach ($rows as $r) {
    if (nb_variant($r, 'download', false) !== null) {
        $ready++;
        continue;
    }
    if (microtime(true) > $deadline) {
        continue;
    }
    if (nb_variant($r, 'download', true) !== null) {
        $ready++;
    } else {
        $failed++;
    }
}
nb_json(['total' => count($rows), 'ready' => $ready, 'failed' => $failed, 'pending' => count($rows) - $ready - $failed, 'bytes' => (int) array_sum(array_column($rows, 'size'))]);
