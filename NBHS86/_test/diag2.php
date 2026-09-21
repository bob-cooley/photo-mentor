<?php
// TEMPORARY follow-up probe (delete after use): ffmpeg capabilities and data-size accounting. Aggregates only.
header('Content-Type: text/plain; charset=utf-8'); header('X-Robots-Tag: noindex, nofollow');
if (($_GET['run'] ?? '') !== '1') { exit("add ?run=1\n"); }
set_time_limit(200); ini_set('display_errors', '0');
require __DIR__ . '/../lib/derive.php'; require __DIR__ . '/../lib/thumbs.php';
function line($k, $v = '') { echo str_pad($k, 44) . $v . "\n"; }
function run2(array $cmd, int $to = 90): array {
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes); if (!is_resource($p)) return [-9, '', 'proc_open failed'];
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false); $o = $e = ''; $dl = microtime(true) + $to;
    while (true) { $o .= stream_get_contents($pipes[1]); $e .= stream_get_contents($pipes[2]); $s = proc_get_status($p); if (!$s['running']) break; if (microtime(true) > $dl) { proc_terminate($p, 9); return [-2, $o, $e . ' [TIMEOUT]']; } usleep(50000); }
    $o .= stream_get_contents($pipes[1]); $e .= stream_get_contents($pipes[2]); $code = $s['exitcode']; proc_close($p); return [$code, $o, $e];
}
$ff = nb_tool('ffmpeg'); $tmp = sys_get_temp_dir() . '/nbhs86-diag2-' . bin2hex(random_bytes(4)); mkdir($tmp);
echo "=== ffmpeg on this server ===\n";
[, $v] = run2([$ff, '-hide_banner', '-version']); line('version', strtok($v, "\n"));
[, $dec] = run2([$ff, '-hide_banner', '-decoders']); [, $enc] = run2([$ff, '-hide_banner', '-encoders']);
foreach (['h264', 'hevc', 'vp9', 'av1', 'aac', 'mpeg4'] as $c) { line("decoder $c", preg_match('/\b' . $c . '\b/m', $dec) ? 'yes' : 'NO'); }
foreach (['libx264', 'libx265', 'mpeg4'] as $c) { line("encoder $c", preg_match('/\b' . $c . '\b/m', $enc) ? 'yes' : 'NO'); }
line('cpu cores visible', trim((string) shell_exec('nproc 2>/dev/null')) ?: '?');

echo "\n=== can it make and poster a 1080p video? (reports real errors) ===\n";
$t0 = microtime(true);
[$c, , $e] = run2([$ff, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=1920x1080:rate=30', '-t', '3', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', "$tmp/v264.mp4"], 90);
line('make 1080p H.264 (exit / s)', "$c / " . round(microtime(true) - $t0, 1) . ' s ' . (is_file("$tmp/v264.mp4") ? human(filesize("$tmp/v264.mp4")) : 'NO FILE') . ' ' . substr(trim($e), 0, 160));
function human($b) { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024) . ' KB'; }
if (is_file("$tmp/v264.mp4")) { $t0 = microtime(true); $ok = nb_thumb_video("$tmp/v264.mp4", "$tmp/p264.jpg"); line('poster from 1080p H.264', ($ok ? 'ok' : 'FAILED') . ' ' . round(microtime(true) - $t0, 2) . ' s'); }
if (preg_match('/\blibx265\b/m', $enc)) {
    [$c] = run2([$ff, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=1280x720:rate=30', '-t', '2', '-c:v', 'libx265', '-preset', 'ultrafast', '-tag:v', 'hvc1', '-pix_fmt', 'yuv420p', "$tmp/hevc.mov"], 120);
    if (is_file("$tmp/hevc.mov")) { $ok = nb_thumb_video("$tmp/hevc.mov", "$tmp/phevc.jpg"); line('poster from HEVC/H.265 .mov (like iPhone)', $ok ? 'ok' : 'FAILED'); } else { line('HEVC sample could not be made', "exit $c"); }
} else { line('HEVC poster test', 'skipped (no libx265 encoder to make a sample)'); }

echo "\n=== where do the 483 MB in the data folder come from? ===\n";
$cfg = nb_config(); $dir = rtrim((string) ($cfg['data_dir'] ?? dirname(__DIR__, 4) . '/nbhs86-data'), '/');
$db = new PDO('sqlite:' . $dir . '/nbhs86.sqlite', null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
foreach ($db->query('select kind, count(*) c, sum(size) s, max(size) m from media group by kind') as $r) { line("rows: {$r['kind']}", $r['c'] . ' files, ' . human((float) $r['s']) . ' total, largest ' . human((float) $r['m'])); }
line('sum of all recorded sizes', human((float) $db->query('select sum(size) from media')->fetchColumn()));
foreach (['media', 'media/classmate-uploads', 'thumbs', 'derived', 'tus', 'jobs', 'backups'] as $sub) { [, $o] = nb_run(['du', '-sk', "$dir/$sub"], 30); line("du $sub", human(((int) $o) * 1024)); }
[, $o] = nb_run(['du', '-sk', $dir], 30); line('du whole data dir', human(((int) $o) * 1024));
$other = []; foreach (glob($dir . '/*') ?: [] as $p) { $other[] = basename($p) . (is_dir($p) ? '/' : ''); } line('top-level entries', implode(', ', $other));
exec('rm -rf ' . escapeshellarg($tmp)); echo "\ndone\n";
