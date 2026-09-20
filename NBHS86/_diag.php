<?php
// TEMPORARY server capability report for NBHS86 planning. Reports no secrets
// or filesystem paths. DELETE this file after reading the output.
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

function line($k, $v) { echo str_pad($k, 30) . (is_bool($v) ? ($v ? 'yes' : 'no') : $v) . "\n"; }
function fn_ok($f) {
    $dis = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return function_exists($f) && !in_array($f, $dis, true);
}

echo "== PHP ==\n";
line('version', PHP_VERSION);
line('sapi', PHP_SAPI);
line('memory_limit', ini_get('memory_limit'));
line('max_execution_time', ini_get('max_execution_time'));
line('max_input_time', ini_get('max_input_time'));
line('upload_max_filesize', ini_get('upload_max_filesize'));
line('post_max_size', ini_get('post_max_size'));
line('max_file_uploads', ini_get('max_file_uploads'));
line('file_uploads', (bool) ini_get('file_uploads'));
line('open_basedir set', (bool) ini_get('open_basedir'));
line('disable_functions', ini_get('disable_functions') ?: '(none)');
line('user_ini.filename', ini_get('user_ini.filename') ?: '(unset)');
line('server_software', $_SERVER['SERVER_SOFTWARE'] ?? 'n/a');
line('mod_rewrite (apache mod)', function_exists('apache_get_modules') ? in_array('mod_rewrite', apache_get_modules(), true) : 'n/a (not apache module sapi)');

echo "\n== Extensions ==\n";
foreach (['zip', 'gd', 'imagick', 'fileinfo', 'exif', 'mbstring', 'sqlite3', 'pdo_sqlite', 'pdo_mysql', 'curl', 'json', 'intl'] as $e) {
    line($e, extension_loaded($e));
}
line('ZipArchive class', class_exists('ZipArchive'));

echo "\n== GD ==\n";
if (function_exists('gd_info')) {
    $g = gd_info();
    foreach (['GD Version', 'JPEG Support', 'PNG Support', 'GIF Read Support', 'WebP Support', 'AVIF Support', 'FreeType Support'] as $k) {
        if (isset($g[$k])) line($k, $g[$k]);
    }
} else {
    echo "not available\n";
}

echo "\n== Imagick ==\n";
if (class_exists('Imagick')) {
    $v = Imagick::getVersion();
    line('version', $v['versionString']);
    $fmts = Imagick::queryFormats();
    foreach (['JPEG', 'PNG', 'WEBP', 'HEIC', 'HEIF', 'AVIF', 'PDF', 'MOV', 'MP4'] as $f) {
        line("format $f", in_array($f, $fmts, true));
    }
} else {
    echo "not available\n";
}

echo "\n== CLI tools ==\n";
$shell = fn_ok('shell_exec');
line('shell_exec usable', $shell);
if ($shell) {
    foreach (['ffmpeg', 'ffprobe', 'convert', 'magick', 'gs', 'heif-convert', 'exiftool', 'unzip', 'zip'] as $t) {
        $p = trim((string) @shell_exec('command -v ' . escapeshellarg($t) . ' 2>/dev/null'));
        line($t, $p !== '' ? 'found' : 'not found');
    }
}

echo "\n== Filesystem ==\n";
$here = __DIR__;
$up1 = dirname($here);
$up2 = dirname($up1);
$up3 = dirname($up2);
line('NBHS86/ writable', is_writable($here));
line('site root (up 1) writable', is_writable($up1));
line('public_html (up 2) writable', is_writable($up2));
line('home (up 3) writable', is_writable($up3));
line('sys_get_temp_dir writable', is_writable(sys_get_temp_dir()));
$free = @disk_free_space($here);
$total = @disk_total_space($here);
line('disk free (GB)', $free === false ? 'n/a' : round($free / 1073741824, 1));
line('disk total (GB)', $total === false ? 'n/a' : round($total / 1073741824, 1));
echo "\n(Disk figures are for the filesystem, not necessarily your account quota. Check the quota in the ACC.)\n";
