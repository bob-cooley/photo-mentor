<?php
// TEMPORARY verification of the reliability changes (delete after use). Aggregates only.
header('Content-Type: text/plain; charset=utf-8'); header('X-Robots-Tag: noindex, nofollow');
if (($_GET['run'] ?? '') !== '1') { exit("add ?run=1\n"); }
set_time_limit(280); ini_set('display_errors', '0');
$tmp = sys_get_temp_dir() . '/nbhs86-d3-' . bin2hex(random_bytes(4)); mkdir($tmp, 0700);
putenv("NBHS86_DATA_DIR=$tmp/data");            // everything the app does below happens in a scratch dir
require __DIR__ . '/../lib/ingest.php';
function line($k, $v = '') { echo str_pad($k, 50) . $v . "\n"; }
function human($b) { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024) . ' KB'; }
$php = nb_tool('php') ?: PHP_BINARY;
echo "=== A. all 9 test suites on this server (PHP " . PHP_VERSION . ") ===\n";
foreach (['bootstrap', 'credits', 'numbering', 'folders', 'derive', 'slideshow', 'errorlog', 'backup', 'thumbs'] as $n) {
    $t0 = microtime(true); [$c, $o] = nb_run([$php, '-q', __DIR__ . "/t_$n.php"], 200);
    $l = array_values(array_filter(array_map('trim', explode("\n", $o)))); line("$n (exit $c, " . round(microtime(true) - $t0, 1) . 's)', substr(implode(' | ', array_slice($l, -3)), 0, 200));
}
echo "\n=== B. live artifacts (read-only) ===\n";
$cfg = nb_config(); $real = rtrim((string) ($cfg['data_dir'] ?? dirname(__DIR__, 4) . '/nbhs86-data'), '/');
$log = "$real/php-errors.log";
line('php-errors.log exists', is_file($log)); if (is_file($log)) { line('  permissions / size', substr(sprintf('%o', fileperms($log)), -4) . ' / ' . human(filesize($log))); $t = array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -5); foreach ($t as $x) { echo '   ' . preg_replace(['/\b\d{1,3}(\.\d{1,3}){3}\b/', '#/usr/home/[^/ ]+#'], ['<ip>', '~'], substr($x, 0, 200)) . "\n"; } }
$b = glob("$real/backups/nbhs86-*.sqlite") ?: []; rsort($b);
line('backups on disk', count($b)); foreach (array_slice($b, 0, 6) as $f) { echo '   ' . preg_replace('/^nbhs86-/', '', basename($f)) . '  ' . human(filesize($f)) . "\n"; }
$m = "$real/backups/.last-daily"; line('daily marker', is_file($m) ? 'present, written ' . date('Y-m-d H:i', filemtime($m)) . ' UTC' : 'MISSING');
$db = new PDO('sqlite:' . $real . '/nbhs86.sqlite', null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$rows = $db->query('select id from media')->fetchAll(PDO::FETCH_COLUMN); $noThumb = 0; foreach ($rows as $id) { if (!is_file("$real/thumbs/$id.jpg")) { $noThumb++; } }
line('live media rows / rows without a thumbnail', count($rows) . ' / ' . $noThumb);
echo "\n=== C. what a classmate's upload costs now (thumbnail included), in a scratch folder ===\n";
$j = nb_data_dir() . '/jobs'; @mkdir($j, 0700, true); $m2 = ['uploader' => 'T', 'anonymous' => 0, 'batch' => 't'];
function timed($label, $fn) { $t0 = microtime(true); $r = $fn(); line($label, round((microtime(true) - $t0) * 1000) . ' ms  ' . $r); }
$W = 4032; $H = 3024; $im = imagecreatetruecolor($W, $H);
for ($y = 0; $y < $H; $y += 6) for ($x = 0; $x < $W; $x += 6) imagefilledrectangle($im, $x, $y, $x + 5, $y + 5, imagecolorallocate($im, ($x * 7 + $y * 3) % 255, ($x + $y * 5) % 255, mt_rand(0, 255)));
imagejpeg($im, "$j/p.jpg", 90);
timed('12 MP JPEG (' . human(filesize("$j/p.jpg")) . ')', function () use ($j, $m2) { $r = nb_store_file("$j/p.jpg", 'p.jpg', $m2); return $r['status'] . ' thumb:' . (is_file(nb_data_dir() . "/thumbs/{$r['id']}.jpg") ? 'yes' : 'NO'); });
copy(__DIR__ . '/sample.heic', "$j/h.heic");
timed('12 MP HEIC (' . human(filesize("$j/h.heic")) . ')', function () use ($j, $m2) { $r = nb_store_file("$j/h.heic", 'h.heic', $m2); return $r['status'] . ' thumb:' . (is_file(nb_data_dir() . "/thumbs/{$r['id']}.jpg") ? 'yes' : 'NO'); });
nb_run([nb_tool('ffmpeg'), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=1920x1080:rate=30', '-t', '8', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', "$j/v.mp4"], 90);
timed('1080p video (' . human(filesize("$j/v.mp4")) . ')', function () use ($j, $m2) { $r = nb_store_file("$j/v.mp4", 'v.mp4', $m2); return $r['status'] . ' thumb:' . (is_file(nb_data_dir() . "/thumbs/{$r['id']}.jpg") ? 'yes' : 'NO'); });
$pdf = new Imagick(); $pdf->newImage(1200, 1600, 'white'); $pdf->setImageFormat('pdf'); $pdf->writeImage("$j/d.pdf");
timed('PDF (' . human(filesize("$j/d.pdf")) . ')', function () use ($j, $m2) { $r = nb_store_file("$j/d.pdf", 'd.pdf', $m2); return $r['status'] . ' thumb:' . (is_file(nb_data_dir() . "/thumbs/{$r['id']}.jpg") ? 'yes' : 'NO (falls back to the placeholder tile)'); });
line('peak memory', human(memory_get_peak_usage(true)));
exec('rm -rf ' . escapeshellarg($tmp)); echo "\ndone\n";
