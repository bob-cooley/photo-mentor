<?php
// php tests/errorlog_test.php - PHP errors go to a private log, never to the page; the admin view masks paths/IPs.
$fail = 0;
function check(string $label, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; } }
$boot = var_export(realpath(__DIR__ . '/../lib/bootstrap.php'), true);
$dir = sys_get_temp_dir() . '/nbhs86-errlog-' . bin2hex(random_bytes(4)); mkdir($dir, 0700); // like the real data dir
$child = fn(string $code) => shell_exec('NBHS86_DATA_DIR=' . escapeshellarg($dir) . ' php -r ' . escapeshellarg("require $boot; $code") . ' 2>&1');

// a warning is written to the log and not shown
$out = $child('trigger_error("nb-marker-warning", E_USER_WARNING); echo "page-output";');
check('nothing leaks to the page', trim((string) $out), 'page-output');
$log = file_get_contents("$dir/php-errors.log");
check('warning is in the log', str_contains($log, 'nb-marker-warning'), true);
check('log line has a timestamp and level', (bool) preg_match('/^\[[^\]]+\] PHP Warning:  nb-marker-warning/m', $log), true);
check('log file is owner-only (0600)', decoct(fileperms("$dir/php-errors.log") & 0777), '600');

// a fatal error is logged too (this is what a broken page looks like)
$child('function boom(int $x) {} boom("not a number"); echo "unreachable";');
check('fatal error is logged', str_contains(file_get_contents("$dir/php-errors.log"), 'TypeError'), true);

// admin view: masking + tail
file_put_contents("$dir/php-errors.log", "[21-Sep-2026 04:00:00 UTC] PHP Warning:  x in /usr/home/bobcooley/public_html/bobcooleyphoto/NBHS86/lib/a.php on line 3 from 203.0.113.9\n" . str_repeat("filler\n", 100));
$tail = json_decode($child('echo json_encode(nb_recent_errors(5));'), true);
check('tail returns the last 5 lines', count($tail), 5);
file_put_contents("$dir/php-errors.log", "[t] see /usr/home/bobcooley/secret/path and 198.51.100.7 done\n");
$masked = json_decode($child('echo json_encode(nb_recent_errors(5));'), true)[0] ?? '';
check('home path and IP are masked', [str_contains($masked, 'bobcooley'), str_contains($masked, '198.51.100.7'), str_contains($masked, '~/secret/path'), str_contains($masked, '<ip>')], [false, false, true, true]);
file_put_contents("$dir/php-errors.log", '');
check('empty log -> no entries', json_decode($child('echo json_encode(nb_recent_errors());'), true), []);
@unlink("$dir/php-errors.log");
check('missing log -> no entries', json_decode($child('echo json_encode(nb_recent_errors());'), true), []);

// rotation at 2 MB
file_put_contents("$dir/php-errors.log", str_repeat(str_repeat('x', 999) . "\n", 2100));
$child('trigger_error("after-rotation", E_USER_WARNING);');
check('old log rotated to .1', is_file("$dir/php-errors.log.1") && filesize("$dir/php-errors.log.1") > 2000000, true);
check('new log is small and has the new entry', filesize("$dir/php-errors.log") < 2000 && str_contains(file_get_contents("$dir/php-errors.log"), 'after-rotation'), true);

echo $fail ? "$fail failure(s)\n" : "all error-log tests passed\n";
exec('rm -rf ' . escapeshellarg($dir));
exit($fail ? 1 : 0);
