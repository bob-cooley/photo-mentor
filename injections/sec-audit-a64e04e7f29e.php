<?php
// Temporary read-only security audit for bobcooleyphoto.com (2026-11-09). Deleted after use.
// Requires header X-Audit-Token whose SHA-256 matches the hash below (the token itself is not in the repo).
// Reports paths, permissions, dates and pattern names only. It never prints file contents except .htaccess files.
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$TOKEN_SHA256 = '0a4be8b249691074fbda6221ab4021bbab14b9861f83094a0caf42bf9ef0c755';
if (!hash_equals($TOKEN_SHA256, hash('sha256', $_SERVER['HTTP_X_AUDIT_TOKEN'] ?? ''))) {
    http_response_code(404);
    exit;
}
@set_time_limit(60);
$START = microtime(true);
$BUDGET = 24.0;
$SITE = __DIR__;                    // public_html/bobcooleyphoto
$PUBLIC = dirname($SITE);           // public_html
$HOME = dirname($PUBLIC);           // account home
$SINCE = strtotime('2026-10-01 00:00:00 UTC');
$part = $_GET['part'] ?? 'env';

function out($s = '') { echo $s, "\n"; }
function rel($p) { global $HOME; return str_starts_with($p, $HOME) ? '~' . substr($p, strlen($HOME)) : $p; }
function perms($p) { return substr(sprintf('%o', @fileperms($p)), -4); }
function overBudget() { global $START, $BUDGET; return (microtime(true) - $START) > $BUDGET; }

const PHP_EXT = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'phps', 'inc', 'shtml', 'cgi', 'pl', 'py', 'sh'];
const SUSPECT = [
    'eval(base64'      => '/eval\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13|strrev)/i',
    'eval($_'          => '/eval\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)/i',
    'assert($_'        => '/assert\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
    'exec-from-input'  => '/(system|exec|shell_exec|passthru|popen|proc_open)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)/i',
    'preg_replace/e'   => '/preg_replace\s*\(\s*[\'"].*\/e[\'"]/i',
    'create_function'  => '/create_function\s*\(/i',
    'var-function-call'=> '/\$_(GET|POST|REQUEST|COOKIE)\s*\[[^\]]+\]\s*\(/i',
    'long-base64'      => '/[A-Za-z0-9+\/]{400,}={0,2}/',
    'hex-obfuscation'  => '/(\\\\x[0-9a-f]{2}){20,}/i',
    'fopen-remote-write'=> '/file_put_contents\s*\([^;]*\$_(GET|POST|REQUEST)/i',
    'known-shell-name' => '/(c99|r57|wso\s*shell|b374k|FilesMan|alfa\s*shell|indoxploit|webshell)/i',
];

function scan($root, $skipDirs, &$S) {
    $stack = [$root];
    while ($stack) {
        if (overBudget()) { $S['truncated'] = true; return; }
        $dir = array_pop($stack);
        $items = @scandir($dir);
        if ($items === false) { $S['unreadable'][] = rel($dir); continue; }
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $p = "$dir/$it";
            if (is_link($p)) { $S['symlinks'][] = rel($p) . ' -> ' . @readlink($p); continue; }
            $mode = @fileperms($p);
            if (is_dir($p)) {
                $S['dirs']++;
                if ($mode & 0002) $S['world_writable'][] = 'DIR  ' . perms($p) . '  ' . rel($p);
                if (is_writable($p)) $S['php_writable_dirs'][] = perms($p) . '  ' . rel($p);
                if (!in_array($p, $skipDirs, true)) $stack[] = $p;
                continue;
            }
            $S['files']++;
            if ($mode & 0002) $S['world_writable'][] = 'FILE ' . perms($p) . '  ' . rel($p);
            $mtime = @filemtime($p);
            $ext = strtolower(pathinfo($it, PATHINFO_EXTENSION));
            if ($it[0] === '.' && $it !== '.htaccess' && $it !== '.user.ini' && $it !== '.ftp-deploy-sync-state.json' && $it !== '.DS_Store') {
                $S['hidden'][] = date('Y-m-d H:i', $mtime) . '  ' . rel($p);
            }
            if ($it === '.htaccess' || $it === '.user.ini') $S['htaccess'][] = $p;
            $isCode = in_array($ext, PHP_EXT, true);
            if ($isCode && $mtime >= $GLOBALS['SINCE']) $S['recent_code'][] = date('Y-m-d H:i', $mtime) . '  ' . perms($p) . '  ' . filesize($p) . 'B  ' . rel($p);
            if ($isCode && preg_match('~/(storage|upload|uploads|cache|cache2|tmp|temp|originals|import)(/|$)~i', rel(dirname($p)))) {
                $S['code_in_data_dirs'][] = date('Y-m-d H:i', $mtime) . '  ' . rel($p);
            }
            if (($isCode || $mtime >= $GLOBALS['SINCE']) && filesize($p) < 3000000) {
                $buf = @file_get_contents($p);
                if ($buf !== false) {
                    if (!$isCode && preg_match('/<\?php|<\?=/i', $buf) && !in_array($ext, ['md', 'txt', 'html', 'htm', 'js', 'json'], true)) {
                        $S['php_tag_in_non_code'][] = date('Y-m-d H:i', $mtime) . '  ' . rel($p);
                    }
                    if ($isCode) {
                        foreach (SUSPECT as $name => $rx) {
                            if (preg_match($rx, $buf)) $S['suspect_patterns'][] = str_pad($name, 20) . date('Y-m-d H:i', $mtime) . '  ' . rel($p);
                        }
                    }
                }
            }
        }
    }
}

function report($S) {
    out("dirs scanned: {$S['dirs']}, files scanned: {$S['files']}" . (!empty($S['truncated']) ? '  ** TRUNCATED (time budget) **' : ''));
    foreach (['suspect_patterns', 'code_in_data_dirs', 'php_tag_in_non_code', 'recent_code', 'world_writable', 'hidden', 'symlinks', 'unreadable', 'php_writable_dirs'] as $k) {
        $list = $S[$k] ?? [];
        out("\n## $k (" . count($list) . ')');
        foreach (array_slice($list, 0, 400) as $l) out('  ' . $l);
        if (count($list) > 400) out('  ... ' . (count($list) - 400) . ' more');
    }
    out("\n## htaccess / .user.ini files (" . count($S['htaccess'] ?? []) . ')');
    foreach ($S['htaccess'] ?? [] as $h) {
        out("\n--- " . rel($h) . '  ' . perms($h) . '  ' . date('Y-m-d H:i', filemtime($h)) . '  sha1 ' . sha1_file($h));
        out(trim((string) @file_get_contents($h, false, null, 0, 6000)));
    }
}

function blank() { return ['dirs' => 0, 'files' => 0]; }

switch ($part) {
case 'env':
    out('# env  ' . gmdate('Y-m-d H:i') . ' UTC');
    out('php_version: ' . PHP_VERSION . '   sapi: ' . PHP_SAPI);
    out('process_user: ' . (function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? posix_geteuid()) : get_current_user()) . '   file_owner: ' . get_current_user());
    out('umask: ' . sprintf('%04o', umask()));
    foreach (['disable_functions', 'open_basedir', 'allow_url_fopen', 'allow_url_include', 'display_errors', 'expose_php', 'file_uploads', 'upload_max_filesize', 'session.cookie_secure', 'session.cookie_httponly', 'cgi.fix_pathinfo'] as $i) out("$i: " . var_export(ini_get($i), true));
    out('loaded_ini: ' . php_ini_loaded_file() . '   scanned: ' . str_replace("\n", ' ', (string) php_ini_scanned_files()));
    out("\n## home top level");
    foreach (scandir($HOME) as $it) if ($it !== '.' && $it !== '..') out('  ' . perms("$HOME/$it") . '  ' . date('Y-m-d H:i', filemtime("$HOME/$it")) . '  ' . (is_dir("$HOME/$it") ? 'DIR  ' : 'FILE ') . $it);
    out("\n## public_html top level");
    foreach (scandir($PUBLIC) as $it) if ($it !== '.' && $it !== '..') out('  ' . perms("$PUBLIC/$it") . '  ' . date('Y-m-d H:i', filemtime("$PUBLIC/$it")) . '  ' . (is_dir("$PUBLIC/$it") ? 'DIR  ' : 'FILE ') . $it);
    out("\n## crontab -l");
    out(trim((string) @shell_exec('crontab -l 2>&1')));
    out("\n## processes owned by this account");
    out(trim((string) @shell_exec('ps -o pid,ppid,etime,command -U "$(id -u)" 2>&1 | head -40')));
    out("\n## IO200 admin user file (no email or hash printed)");
    $uf = "$SITE/storage/system/user.json";
    if (is_file($uf)) {
        $U = json_decode((string) file_get_contents($uf), true);
        out('  perms ' . perms($uf) . '  modified ' . date('Y-m-d H:i', filemtime($uf)));
        out('  passwordhash_present: ' . (!empty($U['passwordhash']) ? 'yes' : 'NO (anyone with the email can log in)'));
        out('  hash_algo: ' . (!empty($U['passwordhash']) ? (password_get_info($U['passwordhash'])['algoName'] ?? 'unknown') : '-'));
        out('  failed_attempts: ' . var_export($U['numberauthenticationattempts'] ?? null, true) . '   last_login: ' . (!empty($U['login_on']) ? date('Y-m-d H:i', (int) $U['login_on']) : '-'));
    } else {
        out('  not found at storage/system/user.json');
    }
    out("\n## storage/system listing");
    foreach (@scandir("$SITE/storage/system") ?: [] as $it) if ($it[0] !== '.') out('  ' . perms("$SITE/storage/system/$it") . '  ' . date('Y-m-d H:i', filemtime("$SITE/storage/system/$it")) . '  ' . $it);
    break;
case 'site':
    $S = blank();
    scan($SITE, ["$SITE/storage/originals", "$SITE/storage/cache", "$SITE/storage/cache2", "$SITE/bobboTrade", "$SITE/mad", "$SITE/light-detective", "$SITE/NBHS86/vendor"], $S);
    out('# site (excluding storage originals/cache and built JS apps)');
    report($S);
    break;
case 'storage':
    $S = blank();
    foreach (["$SITE/storage/originals", "$SITE/storage/cache", "$SITE/storage/cache2"] as $d) if (is_dir($d)) scan($d, [], $S);
    out('# storage originals + cache');
    report($S);
    break;
case 'other':
    $S = blank();
    foreach (scandir($PUBLIC) as $it) {
        if ($it === '.' || $it === '..' || "$PUBLIC/$it" === $SITE) continue;
        if (is_dir("$PUBLIC/$it")) scan("$PUBLIC/$it", [], $S);
    }
    foreach (["$SITE/bobboTrade", "$SITE/mad", "$SITE/light-detective", "$SITE/NBHS86/vendor"] as $d) if (is_dir($d)) scan($d, [], $S);
    out('# other public_html sites + built app folders');
    report($S);
    break;
case 'outside':
    $S = blank();
    foreach (scandir($HOME) as $it) {
        if ($it === '.' || $it === '..' || "$HOME/$it" === $PUBLIC) continue;
        if (is_dir("$HOME/$it")) scan("$HOME/$it", ["$HOME/$it/mail", "$HOME/mail"], $S);
    }
    out('# home directory outside public_html (mail folders skipped)');
    report($S);
    break;
default:
    http_response_code(400);
    out('unknown part');
}
out("\nelapsed: " . round(microtime(true) - $START, 2) . 's');
