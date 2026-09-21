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
const NB_DEFAULT_FOLDER = 'classmate-uploads'; // PHYSICAL storage dir under media/ (never changes); not a gallery folder
/** Logical gallery folder a new upload lands in, by file type. Bob moves things (e.g. into Slideshows) from the admin page. */
const NB_ALBUM_BY_KIND = ['image' => 'photos', 'video' => 'videos', 'pdf' => 'documents'];
const NB_ZIP_MAX_FILES = 500;
const NB_ZIP_MAX_BYTES = 2147483648; // 2 GB per zip download

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
        $d = rtrim((string) (getenv('NBHS86_DATA_DIR') ?: (nb_config()['data_dir'] ?? dirname(NB_ROOT, 3) . '/nbhs86-data')), '/'); // env override is for tests
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

        // Permanent reunion numbering: NBHS_reunions_0001.jpg. One counter for all image types,
        // a separate one for videos. Counters only ever move forward, so numbers are never reused.
        $cols = array_column($pdo->query('PRAGMA table_info(media)')->fetchAll(), 'name');
        if (!in_array('seq', $cols, true)) {
            $pdo->exec('ALTER TABLE media ADD COLUMN seq INTEGER');
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS counters (kind TEXT PRIMARY KEY, next INTEGER NOT NULL)');
        nb_backfill_seq($pdo);
        nb_migrate($pdo);
    }
    return $pdo;
}

/** Hand out the next number for 'image' or 'video'. Call inside a write transaction. */
function nb_next_seq(PDO $db, string $kind): int
{
    $st = $db->prepare('SELECT next FROM counters WHERE kind = ?');
    $st->execute([$kind]);
    $next = (int) ($st->fetchColumn() ?: 1);
    $db->prepare('INSERT INTO counters (kind, next) VALUES (?, ?) ON CONFLICT(kind) DO UPDATE SET next = excluded.next')
        ->execute([$kind, $next + 1]);
    return $next;
}

/** One-time: number any images/videos that predate the numbering, in upload order. */
function nb_backfill_seq(PDO $db): void
{
    if (!(int) $db->query("SELECT COUNT(*) FROM media WHERE seq IS NULL AND kind IN ('image','video')")->fetchColumn()) {
        return;
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        foreach (['image', 'video'] as $kind) {
            $ids = $db->query("SELECT id FROM media WHERE kind = '$kind' AND seq IS NULL ORDER BY created_at, rowid")->fetchAll(PDO::FETCH_COLUMN);
            $up = $db->prepare('UPDATE media SET seq = ? WHERE id = ?');
            foreach ($ids as $id) {
                $up->execute([nb_next_seq($db, $kind), $id]);
            }
        }
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

/**
 * The name everyone sees and downloads. Photos/videos: NBHS_reunions_0001.<ext>; anything else keeps its
 * uploaded name. $converted = the file is being served as a JPEG made from a HEIC.
 */
function nb_download_name(array $row, bool $converted = false): string
{
    if (!in_array($row['kind'], ['image', 'video'], true) || empty($row['seq'])) {
        return (string) $row['orig_name'];
    }
    $ext = (string) $row['ext'];
    $ext = ['jpeg' => 'jpg', 'tiff' => 'tif'][$ext] ?? $ext;
    if ($converted && in_array($ext, ['heic', 'heif'], true)) {
        $ext = 'jpg';
    }
    return sprintf('NBHS_reunions_%04d.%s', (int) $row['seq'], $ext);
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

// ---------- versioned schema migrations ----------

/** Copy the SQLite file before a schema change. VACUUM INTO is a consistent snapshot even mid-write. */
function nb_backup_db(PDO $db, string $label): void
{
    $dir = nb_data_dir() . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $dest = $dir . '/nbhs86-' . $label . '-' . date('Ymd-His') . '.sqlite';
    try {
        $db->exec("VACUUM INTO '" . str_replace("'", "''", $dest) . "'");
    } catch (Throwable $e) {
        @copy(nb_data_dir() . '/nbhs86.sqlite', $dest);
    }
    // keep the 10 most recent
    $files = glob($dir . '/nbhs86-*.sqlite') ?: [];
    rsort($files);
    foreach (array_slice($files, 10) as $old) {
        @unlink($old);
    }
}

const NB_SCHEMA_VERSION = 2;

function nb_migrate(PDO $db): void
{
    if ((int) $db->query('PRAGMA user_version')->fetchColumn() >= NB_SCHEMA_VERSION) {
        return;
    }
    $hasRows = (int) $db->query('SELECT COUNT(*) FROM media')->fetchColumn() > 0;
    if ($hasRows) {
        nb_backup_db($db, 'pre-v' . NB_SCHEMA_VERSION);
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        $v = (int) $db->query('PRAGMA user_version')->fetchColumn();
        if ($v < 1) {
            // v1: gallery. Logical folders (album) are separate from where a file sits on disk (folder).
            $cols = array_column($db->query('PRAGMA table_info(media)')->fetchAll(), 'name');
            foreach (['album TEXT', 'credit_override TEXT', 'taken_at INTEGER', 'w INTEGER', 'h INTEGER'] as $def) {
                if (!in_array(explode(' ', $def)[0], $cols, true)) {
                    $db->exec('ALTER TABLE media ADD COLUMN ' . $def);
                }
            }
            $db->exec("UPDATE media SET album = folder WHERE album IS NULL");
            $db->exec('CREATE INDEX IF NOT EXISTS media_album ON media(album, seq)');
            $db->exec('CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
            $db->exec("INSERT OR IGNORE INTO settings (k, v) VALUES ('gallery_open', '0'), ('intake_open', '1')");
            $db->exec("CREATE TABLE IF NOT EXISTS folders (
                slug TEXT PRIMARY KEY,
                title TEXT NOT NULL,
                icon TEXT NOT NULL DEFAULT 'grid',
                sort INTEGER NOT NULL DEFAULT 0,
                description TEXT NOT NULL DEFAULT ''
            )");
            $ins = $db->prepare('INSERT OR IGNORE INTO folders (slug, title, icon, sort) VALUES (?,?,?,?)');
            foreach ([['photos', 'Photos', 'camera', 10], ['videos', 'Videos/Slideshows', 'film', 20],
                      ['documents', 'Documents', 'file-text', 30], [NB_DEFAULT_FOLDER, 'Classmate uploads', 'grid', 40]] as $f) {
                $ins->execute($f);
            }
        }
        if ($v < 2) {
            // v2: folders are Photos / Videos / Documents / Slideshows. No separate classmate folder: everything
            // that was in it goes to the folder for its type.
            $db->exec("UPDATE folders SET title = 'Videos' WHERE slug = 'videos' AND title = 'Videos/Slideshows'");
            $db->exec("INSERT OR IGNORE INTO folders (slug, title, icon, sort) VALUES ('slideshows', 'Slideshows', 'grid', 40)");
            $db->exec("UPDATE media SET album = CASE kind WHEN 'image' THEN 'photos' WHEN 'video' THEN 'videos' ELSE 'documents' END WHERE album = 'classmate-uploads'");
            $db->exec("DELETE FROM folders WHERE slug = 'classmate-uploads'");
        }
        $db->exec('PRAGMA user_version = ' . NB_SCHEMA_VERSION);
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    }
}

// ---------- settings, folders, launch switches ----------

function nb_setting(string $key, string $default = ''): string
{
    $st = nb_db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string) $v;
}

function nb_set_setting(string $key, string $value): void
{
    nb_db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')->execute([$key, $value]);
}

function nb_gallery_open(): bool
{
    return nb_setting('gallery_open', '0') === '1';
}

function nb_intake_open(): bool
{
    return nb_setting('intake_open', '1') === '1';
}

/** Members see the gallery only when it is open; admin always can (to preview before launch). */
function nb_can_view_gallery(): bool
{
    return nb_is_admin() || (nb_is_member() && nb_gallery_open());
}

function nb_require_gallery(): void
{
    if (!nb_is_member()) {
        nb_json(['error' => 'auth'], 401);
    }
    if (!nb_can_view_gallery()) {
        nb_json(['error' => 'closed', 'message' => 'The gallery is not open yet.'], 403);
    }
}

/** @return array<int,array{slug:string,title:string,icon:string,sort:int,description:string}> */
function nb_folders(): array
{
    return nb_db()->query('SELECT * FROM folders ORDER BY sort, title')->fetchAll();
}

function nb_folder(string $slug): ?array
{
    $st = nb_db()->prepare('SELECT * FROM folders WHERE slug = ?');
    $st->execute([$slug]);
    return $st->fetch() ?: null;
}

function nb_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
