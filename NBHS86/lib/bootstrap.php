<?php
declare(strict_types=1);

// Shared bootstrap: config, storage paths, SQLite, cookie auth, response headers.
// Target runtime: PHP 8.2 (Pair.com). Keep 8.2-compatible.

ini_set('display_errors', '0');
error_reporting(E_ALL);

define('NB_ROOT', dirname(__DIR__));
const NB_BASE = '/NBHS86';
const NB_MEMBER_COOKIE = 'nbhs86_member';
const NB_ADMIN_COOKIE = 'nbhs86_admin';
const NB_MAX_FILE = 2147483648; // 2 GB per file
const NB_DEFAULT_FOLDER = 'classmate-uploads';

/** config.local.php lives next to index.php, is never committed, and is not deployed by CI. */
function nb_config(): array
{
    static $c = null;
    if ($c === null) {
        $f = NB_ROOT . '/config.local.php';
        $c = is_file($f) ? require $f : [];
        if (!is_array($c)) {
            $c = [];
        }
    }
    return $c;
}

function nb_configured(): bool
{
    $c = nb_config();
    return !empty($c['passcode_hash']) && !empty($c['secret']) && strlen((string) $c['secret']) >= 32;
}

/** Media, thumbnails, tus partials and the SQLite file live OUTSIDE the deploy tree (and web root). */
function nb_data_dir(): string
{
    static $d = null;
    if ($d === null) {
        $d = rtrim((string) (nb_config()['data_dir'] ?? dirname(NB_ROOT, 3) . '/nbhs86-data'), '/');
        foreach (['', '/tus', '/jobs', '/thumbs', '/media', '/media/' . NB_DEFAULT_FOLDER] as $sub) {
            if (!is_dir($d . $sub)) {
                @mkdir($d . $sub, 0700, true);
            }
        }
    }
    return $d;
}

function nb_media_path(string $folder, string $id, string $ext): string
{
    return nb_data_dir() . '/media/' . $folder . '/' . $id . '.' . $ext;
}

function nb_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . nb_data_dir() . '/nbhs86.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 8000');
        // Deliberately not WAL: shared-hosting filesystems may be network-backed.
        $pdo->exec("CREATE TABLE IF NOT EXISTS media (
            id TEXT PRIMARY KEY,
            folder TEXT NOT NULL,
            orig_name TEXT NOT NULL,
            ext TEXT NOT NULL,
            kind TEXT NOT NULL,
            size INTEGER NOT NULL,
            hash TEXT NOT NULL,
            uploader TEXT NOT NULL DEFAULT '',
            anonymous INTEGER NOT NULL DEFAULT 0,
            batch TEXT NOT NULL DEFAULT '',
            source TEXT NOT NULL DEFAULT '',
            created_at INTEGER NOT NULL
        )");
        $pdo->exec('CREATE INDEX IF NOT EXISTS media_hash ON media(hash, size)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS media_folder ON media(folder, created_at)');
        $pdo->exec("CREATE TABLE IF NOT EXISTS jobs (
            id TEXT PRIMARY KEY,
            path TEXT NOT NULL,
            orig_name TEXT NOT NULL DEFAULT '',
            uploader TEXT NOT NULL DEFAULT '',
            anonymous INTEGER NOT NULL DEFAULT 0,
            batch TEXT NOT NULL DEFAULT '',
            folder TEXT NOT NULL,
            next_index INTEGER NOT NULL DEFAULT 0,
            added INTEGER NOT NULL DEFAULT 0,
            skipped INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'pending',
            created_at INTEGER NOT NULL
        )");
        $pdo->exec('CREATE TABLE IF NOT EXISTS login_fail (ip TEXT NOT NULL, ts INTEGER NOT NULL)');
    }
    return $pdo;
}

// ---------- response headers ----------

function nb_headers(bool $noStore = true): void
{
    header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    if ($noStore) {
        header('Cache-Control: no-store');
    }
}

function nb_json(array $data, int $status = 200): never
{
    nb_headers();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------- cookie auth (signed, stateless) ----------

function nb_sign(string $payload): string
{
    return hash_hmac('sha256', $payload, (string) (nb_config()['secret'] ?? ''));
}

function nb_https(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function nb_issue_cookie(string $name, int $ttl): void
{
    $exp = time() + $ttl;
    setcookie($name, $exp . '.' . nb_sign($name . '|' . $exp), [
        'expires' => $exp,
        'path' => NB_BASE . '/',
        'secure' => nb_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function nb_clear_cookie(string $name): void
{
    setcookie($name, '', ['expires' => 1, 'path' => NB_BASE . '/', 'secure' => nb_https(), 'httponly' => true, 'samesite' => 'Lax']);
}

function nb_cookie_valid(string $name): bool
{
    if (!nb_configured() || empty($_COOKIE[$name]) || !is_string($_COOKIE[$name])) {
        return false;
    }
    $parts = explode('.', $_COOKIE[$name], 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0]) || (int) $parts[0] < time()) {
        return false;
    }
    return hash_equals(nb_sign($name . '|' . $parts[0]), $parts[1]);
}

function nb_is_admin(): bool
{
    return nb_cookie_valid(NB_ADMIN_COOKIE);
}

/** Admin counts as a member so the admin page can show thumbnails. */
function nb_is_member(): bool
{
    return nb_cookie_valid(NB_MEMBER_COOKIE) || nb_is_admin();
}

function nb_require_member(): void
{
    if (!nb_is_member()) {
        nb_json(['error' => 'auth'], 401);
    }
}

function nb_normalize_secret_input(string $s): string
{
    return strtolower((string) preg_replace('/\s+/', '', $s));
}

function nb_check_passcode(string $input): bool
{
    $h = (string) (nb_config()['passcode_hash'] ?? '');
    return $h !== '' && password_verify(nb_normalize_secret_input($input), $h);
}

function nb_check_admin_password(string $input): bool
{
    $h = (string) (nb_config()['admin_hash'] ?? '');
    return $h !== '' && password_verify($input, $h);
}

// ---------- login throttling ----------

function nb_client_ip(): string
{
    return substr((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0'), 0, 64);
}

function nb_login_allowed(): bool
{
    $db = nb_db();
    $db->prepare('DELETE FROM login_fail WHERE ts < ?')->execute([time() - 900]);
    $st = $db->prepare('SELECT COUNT(*) FROM login_fail WHERE ip = ?');
    $st->execute([nb_client_ip()]);
    return (int) $st->fetchColumn() < 10;
}

function nb_login_failed(): void
{
    nb_db()->prepare('INSERT INTO login_fail (ip, ts) VALUES (?, ?)')->execute([nb_client_ip(), time()]);
    usleep(700000);
}

function nb_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
