<?php
declare(strict_types=1);

// Continues unfinished zip extraction. The intake page polls this after a .zip upload.
require_once __DIR__ . '/../lib/ingest.php';

nb_require_member();
set_time_limit(60);

$pending = nb_run_jobs(15);

$batch = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['batch'] ?? $_POST['batch'] ?? ''));
$added = 0;
$skipped = 0;
$failed = 0;
if ($batch !== '') {
    $st = nb_db()->prepare('SELECT status, added, skipped FROM jobs WHERE batch = ?');
    $st->execute([$batch]);
    foreach ($st->fetchAll() as $j) {
        $added += (int) $j['added'];
        $skipped += (int) $j['skipped'];
        $failed += $j['status'] === 'failed' ? 1 : 0;
    }
}
nb_json(['pending' => $pending, 'added' => $added, 'skipped' => $skipped, 'failed' => $failed]);
