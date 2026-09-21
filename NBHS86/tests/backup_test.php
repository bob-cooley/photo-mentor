<?php
// php tests/backup_test.php - automatic daily snapshots, retention, locking and validity
$fail = 0;
function check(string $label, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; } }
$boot = var_export(realpath(__DIR__ . '/../lib/bootstrap.php'), true);
$dir = sys_get_temp_dir() . '/nbhs86-backup-' . bin2hex(random_bytes(4)); mkdir($dir);
$run = fn(string $code) => shell_exec('NBHS86_DATA_DIR=' . escapeshellarg($dir) . ' php -r ' . escapeshellarg("require $boot; $code") . ' 2>&1');
$count = fn(string $g) => count(glob("$dir/backups/$g") ?: []);

$run('nb_db();');
check('first request takes one daily backup', $count('nbhs86-daily-*.sqlite'), 1);
check('marker file written', filesize("$dir/backups/.last-daily") > 0, true);
$run('nb_db();'); $run('nb_db();');
check('later requests within 24 h add nothing', $count('nbhs86-daily-*.sqlite'), 1);

// the snapshot is a real, complete database
$snap = glob("$dir/backups/nbhs86-daily-*.sqlite")[0];
$pdo = new PDO('sqlite:' . $snap);
check('snapshot passes integrity_check', $pdo->query('PRAGMA integrity_check')->fetchColumn(), 'ok');
check('snapshot has the tables', $pdo->query("select count(*) from sqlite_master where name in ('media','folders','settings','counters','jobs')")->fetchColumn(), 5);
check('snapshot keeps the schema version', (int) $pdo->query('PRAGMA user_version')->fetchColumn(), 4);
unset($pdo);
check('snapshot is private', decoct(fileperms($snap) & 0777), '600');

// a stale marker (older than 24 h) triggers a new one
touch("$dir/backups/.last-daily", time() - 2 * 86400);
sleep(1); $run('nb_db();');
check('after 24 h a new daily backup is taken', $count('nbhs86-daily-*.sqlite'), 2);

// several requests arriving together on a stale marker take exactly one
touch("$dir/backups/.last-daily", time() - 2 * 86400);
sleep(1);
$before = $count('nbhs86-daily-*.sqlite');
$php = trim((string) shell_exec('command -v php')) ?: 'php';
$env = array_merge(getenv(), ['NBHS86_DATA_DIR' => $dir]); // keep PATH etc.: a bare environment cannot find php on some hosts
$procs = []; $pipes = [];
for ($i = 0; $i < 8; $i++) { $procs[$i] = proc_open([$php, '-r', "require $boot; nb_db();"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], null, $env); }
foreach ($procs as $i => $pr) { stream_get_contents($pipes[$i][1]); proc_close($pr); }
check('8 simultaneous requests -> exactly one new backup', $count('nbhs86-daily-*.sqlite') - $before, 1);

// retention: 14 daily / 10 pre / 10 manual, each label independent
for ($i = 0; $i < 20; $i++) { foreach (['daily', 'pre-v9', 'manual'] as $l) { $f = "$dir/backups/nbhs86-$l-2020" . sprintf('%02d', $i + 1) . '01-000000.sqlite'; file_put_contents($f, 'x'); touch($f, time() - 900000 + $i); } }
sleep(1);
$run('nb_backup_db(nb_db(), "manual");');
check('daily copies capped at 14', $count('nbhs86-daily-*.sqlite') <= 14, true);
check('manual copies capped at 10', $count('nbhs86-manual-*.sqlite'), 10);
check('pre-migration copies capped at 10 (untouched by manual)', $count('nbhs86-pre-*.sqlite'), 10);

// listing: newest first, label parsed
$list = json_decode($run('echo json_encode(array_map(fn($b) => $b["label"], array_slice(nb_list_backups(), 0, 1)));'), true);
check('newest backup listed first (the manual one just taken)', $list, ['manual']);

echo $fail ? "$fail failure(s)\n" : "all backup tests passed\n";
exec('rm -rf ' . escapeshellarg($dir));
exit($fail ? 1 : 0);
