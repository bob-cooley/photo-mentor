<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** extension => kind. Anything not listed is rejected (no svg/html/php/etc). */
const NB_TYPES = [
    'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'gif' => 'image', 'webp' => 'image',
    'heic' => 'image', 'heif' => 'image', 'tif' => 'image', 'tiff' => 'image', 'avif' => 'image',
    'mp4' => 'video', 'm4v' => 'video', 'mov' => 'video', 'webm' => 'video', 'avi' => 'video',
    'mpg' => 'video', 'mpeg' => 'video', '3gp' => 'video', 'mkv' => 'video', 'wmv' => 'video',
    'pdf' => 'pdf',
    'zip' => 'zip',
];

/** Content-Type used when serving (never trust the uploaded one). */
const NB_MIME = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'heic' => 'image/heic', 'heif' => 'image/heif', 'tif' => 'image/tiff',
    'tiff' => 'image/tiff', 'avif' => 'image/avif',
    'mp4' => 'video/mp4', 'm4v' => 'video/x-m4v', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
    'avi' => 'video/x-msvideo', 'mpg' => 'video/mpeg', 'mpeg' => 'video/mpeg', '3gp' => 'video/3gpp',
    'mkv' => 'video/x-matroska', 'wmv' => 'video/x-ms-wmv',
    'pdf' => 'application/pdf',
];

const NB_ZIP_MAX_ENTRIES = 20000;

function nb_clean_filename(string $name): string
{
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
    $name = trim($name);
    if (mb_strlen($name) > 200) {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $name = mb_substr($name, 0, 190) . ($ext !== '' ? '.' . $ext : '');
    }
    return $name;
}

function nb_ext(string $name): string
{
    return strtolower(pathinfo($name, PATHINFO_EXTENSION));
}

function nb_clean_person_name(string $name): string
{
    $name = (string) preg_replace('/[\x00-\x1F\x7F<>]/u', '', $name);
    $name = trim((string) preg_replace('/\s+/u', ' ', $name));
    return mb_substr($name, 0, 60);
}

/** Cheap content sniff: the extension must be plausible for the actual bytes. */
function nb_sniff_ok(string $path, string $kind, string $ext): bool
{
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    switch ($kind) {
        case 'image':
            return str_starts_with($mime, 'image/')
                || ($mime === 'application/octet-stream' && in_array($ext, ['heic', 'heif', 'avif'], true));
        case 'video':
            return str_starts_with($mime, 'video/') || in_array($mime, ['application/mp4', 'application/octet-stream'], true);
        case 'pdf':
            return $mime === 'application/pdf';
        case 'zip':
            return in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/x-zip'], true);
    }
    return false;
}

/**
 * Move a completed file into the media store and record it.
 * $meta: uploader, anonymous, batch, folder(optional)
 * Returns ['status' => added|duplicate|rejected, 'id' => ?string]. Consumes $tmpPath in every case.
 */
function nb_store_file(string $tmpPath, string $origName, array $meta, string $source = ''): array
{
    $origName = nb_clean_filename($origName);
    $ext = nb_ext($origName);
    $kind = NB_TYPES[$ext] ?? null;
    if ($kind === null || $kind === 'zip' || !nb_sniff_ok($tmpPath, $kind, $ext)) {
        @unlink($tmpPath);
        return ['status' => 'rejected', 'id' => null];
    }

    $size = (int) filesize($tmpPath);
    $hash = hash_file('xxh128', $tmpPath);
    $db = nb_db();
    $folder = $meta['folder'] ?? NB_DEFAULT_FOLDER;
    $id = bin2hex(random_bytes(6));
    $dest = nb_media_path($folder, $id, $ext);
    $anon = !empty($meta['anonymous']) ? 1 : 0;

    // One write transaction covers: duplicate check, number assignment, file move, row insert.
    // Rejected files and duplicates never burn a number, and two simultaneous uploads can't collide.
    $db->exec('BEGIN IMMEDIATE');
    try {
        $st = $db->prepare('SELECT id FROM media WHERE hash = ? AND size = ?');
        $st->execute([$hash, $size]);
        if ($dup = $st->fetchColumn()) {
            $db->exec('ROLLBACK');
            @unlink($tmpPath);
            return ['status' => 'duplicate', 'id' => (string) $dup];
        }
        if (!@rename($tmpPath, $dest) && !(@copy($tmpPath, $dest) && @unlink($tmpPath))) {
            throw new RuntimeException('could not store file');
        }
        @chmod($dest, 0600);
        $seq = in_array($kind, ['image', 'video'], true) ? nb_next_seq($db, $kind) : null;
        $db->prepare('INSERT INTO media (id, folder, orig_name, ext, kind, size, hash, uploader, anonymous, batch, source, created_at, seq)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $id, $folder, $origName, $ext, $kind, $size, $hash,
                $anon ? '' : nb_clean_person_name((string) ($meta['uploader'] ?? '')),
                $anon, substr((string) ($meta['batch'] ?? ''), 0, 40), $source, time(), $seq,
            ]);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        @unlink($dest);
        @unlink($tmpPath);
        return ['status' => 'rejected', 'id' => null];
    }
    return ['status' => 'added', 'id' => $id];
}

/** Entry point for a fully received tus upload. Returns a status array. */
function nb_ingest_upload(string $binPath, array $meta): array
{
    $name = nb_clean_filename((string) ($meta['filename'] ?? ''));
    $ext = nb_ext($name);
    $kind = NB_TYPES[$ext] ?? null;
    if ($kind === null) {
        @unlink($binPath);
        return ['status' => 'rejected'];
    }
    if ($kind !== 'zip') {
        return nb_store_file($binPath, $name, $meta);
    }

    if (!nb_sniff_ok($binPath, 'zip', 'zip')) {
        @unlink($binPath);
        return ['status' => 'rejected'];
    }
    $jobId = bin2hex(random_bytes(8));
    $dest = nb_data_dir() . '/jobs/' . $jobId . '.zip';
    if (!@rename($binPath, $dest)) {
        @unlink($binPath);
        return ['status' => 'rejected'];
    }
    nb_db()->prepare('INSERT INTO jobs (id, path, orig_name, uploader, anonymous, batch, folder, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([
            $jobId, $dest, $name,
            !empty($meta['anonymous']) ? '' : nb_clean_person_name((string) ($meta['uploader'] ?? '')),
            !empty($meta['anonymous']) ? 1 : 0,
            substr((string) ($meta['batch'] ?? ''), 0, 40),
            NB_DEFAULT_FOLDER, time(),
        ]);
    nb_run_jobs(12);
    return ['status' => 'zip'];
}

function nb_pending_jobs(): int
{
    return (int) nb_db()->query("SELECT COUNT(*) FROM jobs WHERE status = 'pending'")->fetchColumn();
}

/**
 * Work through pending zip jobs until the time budget runs out.
 * Safe to call from several requests: a non-blocking lock keeps it single-runner.
 * Re-running an entry is harmless (content-hash dedupe).
 */
function nb_run_jobs(int $budgetSeconds): int
{
    $lock = fopen(nb_data_dir() . '/jobs.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return nb_pending_jobs();
    }
    $deadline = microtime(true) + $budgetSeconds;
    $db = nb_db();
    $jobs = $db->query("SELECT * FROM jobs WHERE status = 'pending' ORDER BY created_at")->fetchAll();
    foreach ($jobs as $job) {
        if (microtime(true) >= $deadline) {
            break;
        }
        nb_run_zip_job($job, $deadline);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    return nb_pending_jobs();
}

function nb_finish_job(array $job, string $status): void
{
    @unlink($job['path']);
    nb_db()->prepare('UPDATE jobs SET status = ? WHERE id = ?')->execute([$status, $job['id']]);
}

function nb_run_zip_job(array $job, float $deadline): void
{
    $db = nb_db();
    $zip = new ZipArchive();
    if (!is_file($job['path']) || $zip->open($job['path'], ZipArchive::RDONLY) !== true) {
        nb_finish_job($job, 'failed');
        return;
    }
    $n = $zip->numFiles;
    if ($n > NB_ZIP_MAX_ENTRIES) {
        $zip->close();
        nb_finish_job($job, 'failed');
        return;
    }

    $meta = ['uploader' => $job['uploader'], 'anonymous' => (int) $job['anonymous'], 'batch' => $job['batch'], 'folder' => $job['folder']];
    $added = (int) $job['added'];
    $skipped = (int) $job['skipped'];
    $save = $db->prepare('UPDATE jobs SET next_index = ?, added = ?, skipped = ? WHERE id = ?');

    for ($i = (int) $job['next_index']; $i < $n; $i++) {
        if (microtime(true) >= $deadline) {
            $save->execute([$i, $added, $skipped, $job['id']]);
            $zip->close();
            return;
        }
        $st = $zip->statIndex($i);
        if ($st === false) {
            $skipped++;
            continue;
        }
        $entry = str_replace('\\', '/', (string) $st['name']);
        if (str_ends_with($entry, '/')) {
            continue; // directory
        }
        $base = nb_clean_filename($entry);
        $ext = nb_ext($base);
        $kind = NB_TYPES[$ext] ?? null;
        if ($base === '' || $base[0] === '.' || str_contains('/' . $entry, '/__MACOSX/')
            || $kind === null || $kind === 'zip' || (int) $st['size'] > NB_MAX_FILE) {
            $skipped++;
            continue;
        }

        // Entry names are never used as paths (zip-slip impossible); data goes to a random temp file.
        $in = $zip->getStreamIndex($i);
        if (!$in) {
            $skipped++;
            continue;
        }
        $tmp = nb_data_dir() . '/jobs/x-' . bin2hex(random_bytes(6));
        $out = fopen($tmp, 'wb');
        $declared = (int) $st['size'];
        $copied = $out ? stream_copy_to_stream($in, $out, $declared + 1) : false;
        fclose($in);
        if ($out) {
            fclose($out);
        }
        if ($copied === false || $copied > $declared) { // lying size header = possible bomb
            @unlink($tmp);
            $skipped++;
            continue;
        }
        $res = nb_store_file($tmp, $base, $meta, 'zip:' . $job['orig_name']);
        $res['status'] === 'added' ? $added++ : $skipped++;
        if (($i + 1) % 25 === 0) {
            $save->execute([$i + 1, $added, $skipped, $job['id']]);
        }
    }
    $zip->close();
    $save->execute([$n, $added, $skipped, $job['id']]);
    nb_finish_job($job, 'done');
}
