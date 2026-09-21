<?php
declare(strict_types=1);

// Admin only: takes a fresh snapshot of the database and sends it as a download.
require_once __DIR__ . '/../lib/bootstrap.php';

nb_headers();
if (!nb_is_admin()) {
    http_response_code(401);
    exit('Sign in on the admin page first.');
}
nb_backup_db(nb_db(), 'manual');
$latest = nb_list_backups()[0] ?? null;
if (!$latest || !is_file($latest['path'])) {
    http_response_code(500);
    exit('Could not create a backup. Check the error log on the admin page.');
}
header('Content-Type: application/vnd.sqlite3');
header('Content-Disposition: attachment; filename="nbhs86-backup-' . date('Ymd-His', $latest['when']) . '.sqlite"');
header('Content-Length: ' . $latest['size']);
readfile($latest['path']);
