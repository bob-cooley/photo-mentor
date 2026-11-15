<?php
// Temporary account maintenance script (2026-11-15). Deleted after use.
// Requires header X-Audit-Token whose SHA-256 matches below (token not in the repo).
// Read-only parts: inventory, wp. Write parts (approved by Bob 2026-11-15): chmod (7 named folders to 0755),
// nyc-php83 / nyc-revert (bobcooley.nyc PHP handler, with a backup copy).
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
if (!hash_equals('1e4f96c3c5cb1874ce7ee9943f554512f294ea69f6576406f2a8eda7ad8766ec', hash('sha256', $_SERVER['HTTP_X_AUDIT_TOKEN'] ?? ''))) {
    http_response_code(404);
    exit;
}
@set_time_limit(60);
$START = microtime(true);
$PUBLIC = dirname(__DIR__);
$HOME = dirname($PUBLIC);
$part = $_GET['part'] ?? '';

function out($s = '') { echo $s, "\n"; }
function perms($p) { return substr(sprintf('%o', @fileperms($p)), -4); }
function overBudget($b = 24.0) { global $START; return (microtime(true) - $START) > $b; }
function wpVersion($dir) {
    $v = @file_get_contents("$dir/wp-includes/version.php");
    return ($v && preg_match('/\$wp_version\s*=\s*\'([^\']+)\'/', $v, $m)) ? $m[1] : null;
}
function header_field($file, $field) {
    $h = @file_get_contents($file, false, null, 0, 8192);
    return ($h && preg_match('/^[ \t\/*#@]*' . preg_quote($field, '/') . ':\s*(.+)$/mi', $h, $m)) ? trim($m[1]) : null;
}
function phpHandler($dir) {
    $h = @file_get_contents("$dir/.htaccess");
    if (!$h) return '-';
    $lines = [];
    foreach (explode("\n", $h) as $l) if (preg_match('/^\s*(AddType|AddHandler|Action|SetHandler)\b.*php/i', $l)) $lines[] = trim($l);
    return $lines ? implode(' | ', $lines) : '-';
}
function quickStats($dir, $limitSeconds) {
    $t = microtime(true); $files = 0; $php = 0; $newest = 0; $newestPath = ''; $stack = [$dir]; $trunc = false;
    while ($stack) {
        if (microtime(true) - $t > $limitSeconds) { $trunc = true; break; }
        $d = array_pop($stack);
        foreach (@scandir($d) ?: [] as $it) {
            if ($it === '.' || $it === '..') continue;
            $p = "$d/$it";
            if (is_link($p)) continue;
            if (is_dir($p)) { $stack[] = $p; continue; }
            $files++;
            if (preg_match('/\.(php\d?|phtml|inc)$/i', $it)) $php++;
            $m = @filemtime($p);
            if ($m > $newest) { $newest = $m; $newestPath = substr($p, strlen($dir) + 1); }
        }
    }
    return [$files, $php, $newest, $newestPath, $trunc];
}

switch ($part) {
case 'inventory':
    out('# public_html inventory  ' . gmdate('Y-m-d H:i') . ' UTC');
    out('fcgi-bin wrappers: ' . implode(', ', array_map('basename', glob("$PUBLIC/fcgi-bin/*") ?: [])));
    $items = array_values(array_filter(scandir($PUBLIC), fn($i) => $i !== '.' && $i !== '..'));
    $dirs = array_filter($items, fn($i) => is_dir("$PUBLIC/$i"));
    $per = max(0.5, 20.0 / max(1, count($dirs)));
    foreach ($items as $it) {
        $p = "$PUBLIC/$it";
        if (!is_dir($p)) { out("FILE  " . perms($p) . '  ' . date('Y-m-d', filemtime($p)) . "  $it  (" . filesize($p) . 'B)'); continue; }
        $type = [];
        if (is_file("$p/wp-config.php") || is_file("$p/wp-load.php")) $type[] = 'WordPress ' . (wpVersion($p) ?? '?');
        if (is_file("$p/admin/api/site.php") || is_file("$p/serve.php")) $type[] = 'IO200';
        if (is_file("$p/index.php")) $type[] = 'index.php';
        if (is_file("$p/index.html") || is_file("$p/index.htm")) $type[] = 'index.html';
        if (is_file("$p/mt.cgi") || is_dir("$p/mt-static")) $type[] = 'MovableType?';
        [$files, $php, $newest, $newestPath, $trunc] = quickStats($p, $per);
        out("DIR   " . perms($p) . "  $it");
        out('      type: ' . ($type ? implode(', ', $type) : 'unknown'));
        out("      files: $files" . ($trunc ? '+ (count cut short)' : '') . ", php files: $php, newest file: " . ($newest ? date('Y-m-d', $newest) . " ($newestPath)" : '-'));
        out('      php handler: ' . phpHandler($p));
    }
    break;

case 'chmod':
    $targets = ["$PUBLIC/briansbrain.art", "$PUBLIC/mt-static", "$PUBLIC/urbanvirus", "$HOME/public_ftp/incoming", "$HOME/public_ftp/netactiv", "$HOME/public_ftp/netactiv_ftp", "$HOME/public_ftp/netactiv/incoming"];
    out('# chmod 0755');
    foreach ($targets as $t) {
        if (!is_dir($t)) { out("  missing  $t"); continue; }
        $before = perms($t);
        $ok = @chmod($t, 0755);
        clearstatcache(true, $t);
        out('  ' . ($ok ? 'ok  ' : 'FAIL') . "  $before -> " . perms($t) . '  ' . str_replace($HOME, '~', $t));
    }
    break;

case 'wp':
    $site = basename($_GET['site'] ?? '');
    $d = "$PUBLIC/$site";
    if ($site === '' || !is_file("$d/wp-config.php")) { out('not a WordPress site'); break; }
    out("# WordPress check: $site  " . gmdate('Y-m-d H:i') . ' UTC');
    out('core version: ' . wpVersion($d));
    out('wp-config.php perms: ' . perms("$d/wp-config.php") . '   php handler: ' . phpHandler($d));
    $cfg = file_get_contents("$d/wp-config.php");
    foreach (['DISALLOW_FILE_EDIT', 'WP_DEBUG', 'WP_DEBUG_DISPLAY', 'FORCE_SSL_ADMIN', 'WP_AUTO_UPDATE_CORE', 'AUTOMATIC_UPDATER_DISABLED'] as $c) {
        out("  $c: " . (preg_match("/define\(\s*['\"]" . $c . "['\"]\s*,\s*([^)]+)\)/", $cfg, $m) ? trim($m[1]) : 'not set'));
    }
    out("\n## plugins (folder | version | active unknown here)");
    foreach (glob("$d/wp-content/plugins/*", GLOB_ONLYDIR) as $pd) {
        $ver = null; $name = null;
        foreach (glob("$pd/*.php") as $pf) { if ($n = header_field($pf, 'Plugin Name')) { $name = $n; $ver = header_field($pf, 'Version'); break; } }
        out('  ' . basename($pd) . ' | ' . ($ver ?? '?') . ' | ' . ($name ?? '?'));
    }
    foreach (glob("$d/wp-content/mu-plugins/*") ?: [] as $mu) out('  MU-PLUGIN: ' . basename($mu));
    out("\n## themes (folder | version)");
    foreach (glob("$d/wp-content/themes/*", GLOB_ONLYDIR) as $td) out('  ' . basename($td) . ' | ' . (header_field("$td/style.css", 'Version') ?? '?'));
    if (preg_match("/define\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)/", $cfg, $dbn) && preg_match("/define\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)/", $cfg, $dbu)
        && preg_match("/define\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]*)/", $cfg, $dbp) && preg_match("/define\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)/", $cfg, $dbh)) {
        $prefix = preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)/', $cfg, $tp) ? $tp[1] : 'wp_';
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new mysqli($dbh[1], $dbu[1], $dbp[1], $dbn[1]);
        out("\n## users with administrator role");
        if ($db->connect_errno) { out('  db connect failed'); }
        else {
            $pre = $db->real_escape_string($prefix);
            $q = $db->query("SELECT u.user_login, u.user_registered FROM `{$pre}users` u JOIN `{$pre}usermeta` m ON m.user_id=u.ID WHERE m.meta_key='{$pre}capabilities' AND m.meta_value LIKE '%administrator%' ORDER BY u.user_registered");
            while ($q && $r = $q->fetch_assoc()) out('  ' . $r['user_login'] . '  registered ' . $r['user_registered']);
            $q = $db->query("SELECT COUNT(*) c FROM `{$pre}users`"); out('  total users: ' . ($q ? $q->fetch_assoc()['c'] : '?'));
            $q = $db->query("SELECT option_value FROM `{$pre}options` WHERE option_name='active_plugins'");
            if ($q && $r = $q->fetch_assoc()) { $ap = @unserialize($r['option_value']); out("\n## active plugins (main file)"); foreach ((array) $ap as $a) out('  ' . $a); }
        }
    }
    out("\n## PHP files inside wp-content/uploads");
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$d/wp-content/uploads", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) { if (preg_match('/\.(php\d?|phtml|phar)$/i', $f->getFilename())) out('  ' . date('Y-m-d H:i', $f->getMTime()) . '  ' . filesize($f) . 'B  ' . substr($f->getPathname(), strlen($d))); if (overBudget(15)) { out('  ** cut short'); break; } }
    out("\n## core file md5 (wp-admin, wp-includes, root *.php)");
    foreach (glob("$d/*.php") as $f) out('  ' . md5_file($f) . '  ' . basename($f));
    foreach (['wp-admin', 'wp-includes'] as $cd) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$d/$cd", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) { if ($f->isFile()) out('  ' . md5_file($f->getPathname()) . '  ' . substr($f->getPathname(), strlen($d) + 1)); if (overBudget(40)) { out('  ** cut short'); break 2; } }
    }
    break;

case 'nyc-show':
    $h = "$PUBLIC/bobcooley.nyc/.htaccess";
    out('# bobcooley.nyc .htaccess  perms ' . perms($h) . '  modified ' . date('Y-m-d H:i', filemtime($h)));
    out(file_get_contents($h));
    foreach (glob("$PUBLIC/bobcooley.nyc/.htaccess*") as $b) out('file: ' . basename($b));
    break;

case 'nyc-php83':
    $h = "$PUBLIC/bobcooley.nyc/.htaccess";
    $bak = "$h.bak-2026-11-15";
    $src = file_get_contents($h);
    if (!is_file($bak)) { file_put_contents($bak, $src); out('backup written: .htaccess.bak-2026-11-15'); } else { out('backup already exists, left as is'); }
    $new = preg_replace('#^(\s*Action\s+application/x-httpd-php\s+)/fcgi-bin/php7_wrapper\.sh\s*$#mi', '$1/fcgi-bin/php83_wrapper.sh', $src, -1, $n);
    if ($n !== 1) { out("expected exactly 1 php7 Action line, found $n; nothing changed"); break; }
    file_put_contents($h, $new);
    out('switched Action line to /fcgi-bin/php83_wrapper.sh');
    break;

case 'nyc-revert':
    $h = "$PUBLIC/bobcooley.nyc/.htaccess";
    $bak = "$h.bak-2026-11-15";
    if (!is_file($bak)) { out('no backup found'); break; }
    copy($bak, $h);
    out('restored .htaccess from backup');
    break;

default:
    http_response_code(400);
    out('unknown part');
}
out("\nelapsed: " . round(microtime(true) - $START, 2) . 's');
