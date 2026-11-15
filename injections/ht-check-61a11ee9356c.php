<?php
// Temporary read-only check (2026-11-15): prints .htaccess files in the site root and key folders. Deleted after use.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if (!hash_equals('8384b01599b51b4d36a595886ac6eaf0135b504d170aaa2cf2a1ce7490f9ca5a', hash('sha256', $_SERVER['HTTP_X_AUDIT_TOKEN'] ?? ''))) { http_response_code(404); exit; }
foreach (['', 'storage', 'storage/system', 'storage/originals', 'storage/cache', 'storage/cache2', 'storage/custom', 'storage/lang', 'storage/import', 'listener', 'res', 'admin', 'sys', 'templates', 'cgi-bin'] as $d) {
    $f = __DIR__ . ($d === '' ? '' : "/$d") . '/.htaccess';
    echo "=== /$d/.htaccess: ", is_file($f) ? ('exists, ' . filesize($f) . 'B, sha1 ' . sha1_file($f) . ', modified ' . date('Y-m-d H:i', filemtime($f))) : 'none', "\n";
    if (is_file($f)) echo file_get_contents($f), "\n";
}
