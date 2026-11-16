<?php
// Temporary (2026-11-15): list public_html/urbanvirus, and move named files from it to ~/backup/urbanvirus-joomla-2026-11-15/
// (Bob asked to remove the Joomla files; moved, not deleted, so it can be undone). Deleted after use.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if (!hash_equals('f22b70403da183948cb6c0eac5e27d30f81b752b06e2ffdb26ec85333b6d053d', hash('sha256', $_SERVER['HTTP_X_AUDIT_TOKEN'] ?? ''))) { http_response_code(404); exit; }
$UV = realpath(dirname(__DIR__) . '/urbanvirus');
$BK = dirname(dirname(__DIR__)) . '/backup/urbanvirus-joomla-2026-11-15';
$list = function () use ($UV) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($UV, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($UV) + 1);
        $head = $f->isFile() ? preg_replace('/\s+/', ' ', substr((string) @file_get_contents($f->getPathname(), false, null, 0, 160), 0, 160)) : '';
        echo ($f->isDir() ? 'DIR  ' : 'FILE ') . substr(sprintf('%o', $f->getPerms()), -4) . '  ' . date('Y-m-d', $f->getMTime()) . '  ' . ($f->isFile() ? $f->getSize() . 'B  ' : '') . $rel . ($head !== '' ? "\n        head: " . $head : '') . "\n";
    }
};
if (($_GET['part'] ?? '') === 'list') { echo "# urbanvirus\n"; $list(); exit; }
if (($_GET['part'] ?? '') === 'move') {
    foreach ((array) ($_GET['f'] ?? []) as $rel) {
        $src = realpath($UV . '/' . $rel);
        if ($src === false || !str_starts_with($src, $UV . '/') || !is_file($src)) { echo "SKIP (not a file inside urbanvirus): $rel\n"; continue; }
        $dst = $BK . '/' . substr($src, strlen($UV) + 1);
        if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0700, true);
        echo (rename($src, $dst) ? 'moved  ' : 'FAILED ') . "$rel\n";
    }
    echo "\n# urbanvirus after move\n"; $list();
    exit;
}
if (($_GET['part'] ?? '') === 'movedirs') {
    if (!is_dir($BK)) mkdir($BK, 0700, true);
    foreach (['modules', 'administrator'] as $d) {
        $src = $UV . '/' . $d;
        if (!is_dir($src)) { echo "missing  $d\n"; continue; }
        if (file_exists($BK . '/' . $d)) { echo "SKIP (already in backup)  $d\n"; continue; }
        echo (rename($src, $BK . '/' . $d) ? 'moved  ' : 'FAILED ') . "$d/\n";
    }
    echo "\n# urbanvirus after move\n"; $list();
    echo "\n# backup folder\n"; foreach (scandir($BK) as $i) if ($i[0] !== '.') echo "  $i\n";
    exit;
}
http_response_code(400);
