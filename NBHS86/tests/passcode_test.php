<?php
// php tests/passcode_test.php - the passcode is matched exactly: case and spaces both count
$fail = 0;
function check(string $label, $got, $want): void { global $fail; if ($got !== $want) { $fail++; echo "FAIL $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n"; } }
$dir = sys_get_temp_dir() . '/nbhs86-passcode-' . bin2hex(random_bytes(4)); mkdir($dir, 0700);
putenv('NBHS86_DATA_DIR=' . $dir);
$cfgFile = $dir . '/config.local.php';
$php = trim((string) shell_exec('command -v php')) ?: 'php';
shell_exec(escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/../tools/make-config.php') . ' --passcode=' . escapeshellarg('Bisons1986') . ' --admin-password=x --out=' . escapeshellarg($cfgFile) . ' 2>&1');
require __DIR__ . '/../lib/bootstrap.php';
// nb_config() caches the path from NB_BASE-derived defaults; point it straight at our test file.
$GLOBALS['__nb_config_path_override'] ?? null;
function test_cfg(string $file): array { return require $file; }
$cfg = test_cfg($cfgFile);

check('exact match works', password_verify('Bisons1986', $cfg['passcode_hash']), true);
check('wrong case is rejected', password_verify('bisons1986', $cfg['passcode_hash']), false);
check('wrong case is rejected (all caps)', password_verify('BISONS1986', $cfg['passcode_hash']), false);
check('a stray leading space is rejected', password_verify(' Bisons1986', $cfg['passcode_hash']), false);
check('a stray trailing space is rejected', password_verify('Bisons1986 ', $cfg['passcode_hash']), false);
check('an internal double space is rejected', password_verify('Bisons  1986', $cfg['passcode_hash']), false);

check('nb_normalize_secret_input no longer exists', function_exists('nb_normalize_secret_input'), false);

exec('rm -rf ' . escapeshellarg($dir));
echo $fail ? "$fail failure(s)\n" : "all passcode tests passed\n";
exit($fail ? 1 : 0);
