<?php
// TEMPORARY whole-site diagnostic (delete after use). Read-only against live data; tests run in temp dirs.
// Prints aggregates only: no uploader names, no file names, no secrets.
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
if (($_GET['run'] ?? '') !== '1') { exit("add ?run=1\n"); }
set_time_limit(280);
ini_set('display_errors', '0');
require __DIR__ . '/../lib/derive.php';
require __DIR__ . '/../lib/thumbs.php';
$root = dirname(__DIR__);
function sect($t) { echo "\n=== $t ===\n"; }
function line($k, $v = '') { echo str_pad($k, 46) . (is_bool($v) ? ($v ? 'yes' : 'NO') : $v) . "\n"; }
function t() { return microtime(true); }
function human($b) { return $b >= 1073741824 ? round($b / 1073741824, 2) . ' GB' : ($b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024) . ' KB'); }

sect('A. Environment');
line('php', PHP_VERSION . ' (' . PHP_SAPI . ')');
foreach (['memory_limit', 'max_execution_time', 'upload_max_filesize', 'post_max_size', 'log_errors', 'display_errors', 'error_log', 'output_buffering', 'zlib.output_compression'] as $k) { line($k, (string) ini_get($k) ?: '(empty)'); }
foreach (['zip', 'gd', 'imagick', 'fileinfo', 'exif', 'mbstring', 'sqlite3', 'pdo_sqlite', 'curl', 'intl', 'opcache'] as $e) { line("ext $e", extension_loaded($e)); }
foreach (['exiftool', 'ffmpeg', 'ffprobe', 'magick', 'gs', 'php', 'unzip'] as $b) { line("tool $b", nb_tool($b) ?: 'not found'); }
$php = nb_tool('php') ?: PHP_BINARY;
line('php used to run sub-tests', basename($php));
line('apache mod_rewrite/headers', function_exists('apache_get_modules') ? implode(',', array_intersect(['mod_rewrite', 'mod_headers'], apache_get_modules())) : 'n/a (fcgi)');
line('opcache enabled', (bool) ini_get('opcache.enable'));

sect('B. PHP syntax check of every application file (on THIS php)');
$files = []; $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) { $p = $f->getPathname(); if (substr($p, -4) === '.php' && strpos($p, '/vendor/') === false && strpos($p, '/_test/') === false) { $files[] = $p; } }
sort($files); $bad = 0;
foreach ($files as $f) { [$c, $o] = nb_run([$php, '-l', $f], 20); if ($c !== 0 || stripos($o, 'No syntax errors') === false) { $bad++; line('SYNTAX PROBLEM', str_replace($root, '', $f) . ' :: ' . trim(substr($o, 0, 160))); } }
line('files checked / with problems', count($files) . ' / ' . $bad);

sect('C. Automated test suites, run on this server');
foreach (['bootstrap', 'credits', 'numbering', 'folders', 'derive', 'slideshow'] as $name) {
    $t0 = t();
    [$c, $o] = nb_run([$php, '-q', __DIR__ . "/t_$name.php"], 200);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $o))));
    $last = $lines ? implode(' | ', array_slice($lines, -3)) : '(no output)';
    line("$name (exit $c, " . round(t() - $t0, 1) . 's)', substr($last, 0, 230));
}

sect('D. Live data health (read-only)');
$cfg = nb_config();
line('config.local.php present', is_file($root . '/config.local.php'));
line('config has passcode_hash, admin_hash, secret(>=32)', !empty($cfg['passcode_hash']) && !empty($cfg['admin_hash']) && strlen((string) ($cfg['secret'] ?? '')) >= 32);
$dir = rtrim((string) ($cfg['data_dir'] ?? dirname($root, 3) . '/nbhs86-data'), '/');
line('data dir exists / writable', is_dir($dir) ? (is_writable($dir) ? 'yes / yes' : 'yes / NO') : 'MISSING');
line('data dir permissions', is_dir($dir) ? substr(sprintf('%o', fileperms($dir)), -4) : '-');
$dbf = $dir . '/nbhs86.sqlite';
line('database file', is_file($dbf) ? human(filesize($dbf)) : 'MISSING');
if (is_file($dbf)) {
    $db = new PDO('sqlite:' . $dbf, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $one = fn($q) => $db->query($q)->fetchColumn();
    line('sqlite version', $one('select sqlite_version()'));
    line('schema version (code expects ' . NB_SCHEMA_VERSION . ')', $one('PRAGMA user_version'));
    line('integrity_check', $one('PRAGMA integrity_check'));
    line('settings', implode(', ', array_map(fn($r) => $r['k'] . '=' . $r['v'], $db->query('select k,v from settings order by k')->fetchAll())));
    line('folders', implode(', ', array_map(fn($r) => $r['slug'], $db->query('select slug from folders order by sort')->fetchAll())));
    $rows = $db->query('select id, folder, ext, kind, size, hash, seq, slideshow_seq, album, w from media')->fetchAll();
    line('media rows', count($rows));
    $byAlbum = []; $missing = 0; $sizeBad = 0; $noHash = 0; $seqMissing = 0; $ids = [];
    foreach ($rows as $r) {
        $byAlbum[$r['album']] = ($byAlbum[$r['album']] ?? 0) + 1;
        $p = nb_media_path($r['folder'], $r['id'], $r['ext']); $ids[$r['id'] . '.' . $r['ext']] = 1;
        if (!is_file($p)) { $missing++; } elseif (filesize($p) !== (int) $r['size']) { $sizeBad++; }
        if ($r['hash'] === '') { $noHash++; }
        if (in_array($r['kind'], ['image', 'video'], true) && empty($r['seq']) && empty($r['slideshow_seq'])) { $seqMissing++; }
    }
    line('rows per folder', json_encode($byAlbum));
    line('rows whose FILE IS MISSING on disk', $missing);
    line('rows whose file size differs from record', $sizeBad);
    line('photo/video rows with no number', $seqMissing);
    line('rows without a content hash', $noHash);
    line('rows still awaiting dimensions/date read', $one('select count(*) from media where w is null'));
    line('duplicate content (same hash+size) rows', $one('select count(*) from (select 1 from media group by hash,size having count(*)>1)'));
    $c = $db->query('select kind,next from counters')->fetchAll(PDO::FETCH_KEY_PAIR);
    line('counters (next number to hand out)', json_encode($c));
    $mx = ['image' => (int) $one("select coalesce(max(seq),0) from media where kind='image'"), 'video' => (int) $one("select coalesce(max(seq),0) from media where kind='video'"), 'slideshow' => (int) $one('select coalesce(max(slideshow_seq),0) from media')];
    $warn = [];
    foreach ($mx as $k => $m) { if (($c[$k] ?? 1) <= $m) { $warn[] = "$k counter " . ($c[$k] ?? 'none') . " <= max used $m"; } }
    line('counter sanity (next > highest used)', $warn ? 'PROBLEM: ' . implode('; ', $warn) : 'ok');
    line('zip jobs by status', json_encode($db->query('select status, count(*) c from jobs group by status')->fetchAll(PDO::FETCH_KEY_PAIR)));
    line('login_fail rows in window', $one('select count(*) from login_fail'));
    // files on disk without a row
    $orph = 0; $mdir = $dir . '/media/' . NB_DEFAULT_FOLDER;
    foreach (glob($mdir . '/*') ?: [] as $f) { if (!isset($ids[basename($f)])) { $orph++; } }
    line('files in media dir with NO database row (orphans)', $orph);
}
foreach (['thumbs', 'derived', 'tus', 'jobs', 'backups'] as $sub) {
    $fs = glob("$dir/$sub/*") ?: []; $bytes = 0; $old = 0;
    foreach ($fs as $f) { if (is_file($f)) { $bytes += filesize($f); if (filemtime($f) < time() - 3 * 86400) { $old++; } } }
    line("dir $sub: files / size / older than 3 days", count($fs) . ' / ' . human($bytes) . ' / ' . $old);
}
line('backups (newest first)', implode(', ', array_map(fn($f) => preg_replace('/^nbhs86-/', '', basename($f)) . ' ' . human(filesize($f)), array_slice(array_reverse(glob("$dir/backups/*.sqlite") ?: []), 0, 5))) ?: 'none');
[, $du] = nb_run(['du', '-sk', $dir], 30);
line('data dir total size', human(((int) $du) * 1024));
line('disk free (filesystem)', human((float) @disk_free_space($dir)));

sect('E. Timing of the heavy operations (real server, temp files)');
$tmp = sys_get_temp_dir() . '/nbhs86-diag-' . bin2hex(random_bytes(4)); mkdir($tmp);
$W = 4032; $H = 3024; $im = imagecreatetruecolor($W, $H);
for ($y = 0; $y < $H; $y += 6) { for ($x = 0; $x < $W; $x += 6) { imagefilledrectangle($im, $x, $y, $x + 5, $y + 5, imagecolorallocate($im, ($x * 7 + $y * 3) % 255, ($x + $y * 5) % 255, mt_rand(0, 255))); } }
imagejpeg($im, "$tmp/photo.jpg", 90); imagedestroy($im);
line('test photo (12 MP JPEG)', human(filesize("$tmp/photo.jpg")));
$ts = []; for ($i = 0; $i < 5; $i++) { $o = "$tmp/th$i.jpg"; $t0 = t(); $ok = nb_thumb_image("$tmp/photo.jpg", $o, false); $ts[] = t() - $t0; if (!$ok) { line('thumbnail FAILED', $i); } }
line('JPEG thumbnail (avg of 5)', round(array_sum($ts) / 5, 2) . ' s each');
$t0 = t(); $ok = nb_thumb_image(__DIR__ . '/sample.heic', "$tmp/h.jpg", false); line('HEIC thumbnail', ($ok ? 'ok ' : 'FAILED ') . round(t() - $t0, 2) . ' s');
nb_run([nb_tool('ffmpeg'), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=1920x1080:rate=30', '-t', '6', '-pix_fmt', 'yuv420p', '-b:v', '20M', "$tmp/v.mp4"], 90);
$t0 = t(); $ok = nb_thumb_video("$tmp/v.mp4", "$tmp/vp.jpg"); line('video poster frame (1080p)', ($ok ? 'ok ' : 'FAILED ') . round(t() - $t0, 2) . ' s');
$t0 = t(); nb_run([nb_tool('exiftool'), '-j', '-n', '-ImageWidth', "$tmp/photo.jpg"], 30); line('exiftool metadata read', round(t() - $t0, 2) . ' s');
// big-file finalisation estimate: 300 MB
$fh = fopen("$tmp/big.bin", 'wb'); $chunk = random_bytes(1048576); for ($i = 0; $i < 300; $i++) { fwrite($fh, $chunk); } fclose($fh);
$t0 = t(); $h = hash_file('xxh128', "$tmp/big.bin"); $hs = t() - $t0;
line('content hash, 300 MB', round($hs, 2) . ' s  => 2 GB about ' . round($hs * 2048 / 300, 1) . ' s');
$t0 = t(); rename("$tmp/big.bin", "$tmp/big2.bin"); line('move 300 MB within the data filesystem', round(t() - $t0, 3) . ' s');
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
    $out = fopen("$tmp/z.zip", 'wb'); $t0 = t();
    $z = new ZipStream\ZipStream(defaultCompressionMethod: ZipStream\CompressionMethod::STORE, enableZip64: false, sendHttpHeaders: false, outputStream: $out);
    $z->addFileFromPath(fileName: 'big.bin', path: "$tmp/big2.bin", compressionMethod: ZipStream\CompressionMethod::STORE); $z->finish(); fclose($out);
    $zt = t() - $t0; line('zip 300 MB (store) to disk', round($zt, 2) . ' s  => 2 GB about ' . round($zt * 2048 / 300, 1) . ' s');
}
line('peak PHP memory during all of the above', human(memory_get_peak_usage(true)));
exec('rm -rf ' . escapeshellarg($tmp));

sect('F. Error logs');
$cands = [];
if (ini_get('error_log')) { $cands[] = ini_get('error_log'); }
foreach ([$root, dirname($root), dirname($root, 2), dirname($root, 3), $dir] as $d) { foreach (['error_log', 'php_errors.log', 'php-error.log', 'error.log'] as $n) { $cands[] = "$d/$n"; } }
foreach (glob($root . '/*/error_log') ?: [] as $g) { $cands[] = $g; }
$seen = 0;
foreach (array_unique($cands) as $f) {
    if (is_file($f) && is_readable($f)) {
        $seen++; line('log file', str_replace([$root, dirname($root, 3)], ['~site/NBHS86', '~'], $f) . '  ' . human(filesize($f)) . ', modified ' . date('Y-m-d H:i', filemtime($f)));
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: [], -25);
        foreach ($tail as $l) { echo '   ' . preg_replace(['/\b\d{1,3}(\.\d{1,3}){3}\b/', '/\/usr\/home\/[^\/ ]+/'], ['<ip>', '~'], substr($l, 0, 240)) . "\n"; }
    }
}
if (!$seen) { line('no readable PHP error log found in the usual places', ''); }
echo "\ndone\n";
