<?php
// Temporary (2026-11-15): move the leftover phpinfo test pages (info.php) of beckykliss.com and bobcooley.nyc
// to ~/backup/info-php-2026-11-15/ (Bob asked for them removed). Only moves a file whose code is just a phpinfo call. Deleted after use.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if (!hash_equals('6d4cd325f446e11b236a72f93de17cbe9e5ca5ea843a20560e4a960d436c57cb', hash('sha256', $_SERVER['HTTP_X_AUDIT_TOKEN'] ?? ''))) { http_response_code(404); exit; }
$PUBLIC = dirname(__DIR__);
$BK = dirname($PUBLIC) . '/backup/info-php-2026-11-15';
if (!is_dir($BK)) mkdir($BK, 0700, true);
foreach (['beckykliss.com', 'bobcooley.nyc'] as $site) {
    $f = "$PUBLIC/$site/info.php";
    if (!is_file($f)) { echo "$site: no info.php\n"; continue; }
    $code = file_get_contents($f);
    $stripped = trim(preg_replace('/\s+/', ' ', $code));
    echo "$site: info.php modified " . date('Y-m-d H:i', filemtime($f)) . ', owner uid ' . fileowner($f) . ', perms ' . substr(sprintf('%o', fileperms($f)), -4) . "; site .htaccess modified " . (is_file("$PUBLIC/$site/.htaccess") ? date('Y-m-d H:i', filemtime("$PUBLIC/$site/.htaccess")) : '-') . "\n";
    echo "$site: info.php is " . strlen($code) . "B, code: " . substr($stripped, 0, 120) . "\n";
    if (!preg_match('/^<\?php\s*(passthru\(\s*\'\/usr\/bin\/whoami\'\s*\)\s*;\s*)?phpinfo\s*\(\s*\)\s*;?\s*(\?>)?\s*$/i', trim($code))) { echo "  NOT moved: contains more than a phpinfo call\n"; continue; }
    echo (rename($f, "$BK/$site-info.php") ? "  moved to ~/backup/info-php-2026-11-15/$site-info.php\n" : "  FAILED to move\n");
}
