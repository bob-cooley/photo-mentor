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
const NB_SLIDESHOW_ALBUM = 'slideshows';
const NB_FRIENDS_PREFIX = 'NBHS-friends_';

/**
 * Default names that phones and their apps give videos. A classmate's video whose whole name matches one of
 * these is renamed NBHS-friends_0001.<ext>; anything else keeps its own name (spaces as dashes).
 * Sources: iPhone camera IMG_1234.MOV and edited IMG_E1234.MOV (JEITA DCF standard, Apple Community);
 * iPhone screen recording RPReplay_Final<number>.MP4 (ReplayKit); Google Pixel PXL_YYYYMMDD_HHMMSSmmm.mp4;
 * Android/AOSP VID_YYYYMMDD_HHMMSS.mp4 (and older video-YYYY-MM-DD-HH-MM-SS); Samsung YYYYMMDD_HHMMSS.mp4;
 * WhatsApp VID-YYYYMMDD-WA0001.mp4; Telegram video_YYYY-MM-DD_HH-MM-SS.mp4.
 * A trailing " (1)" is allowed (browsers and iCloud add it to repeat downloads). The extension is ignored.
 */
const NB_PHONE_VIDEO_PATTERNS = [
    '/^IMG_E?\d{4,}$/i',
    '/^RPReplay_Final\d+$/i',
    '/^PXL_\d{8}_\d{6,9}(?:\.[A-Za-z0-9-]+)*$/i',
    '/^VID_\d{8}_\d{6,9}(?:_\d+)?$/i',
    '/^video-\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$/i',
    '/^\d{8}_\d{6,9}(?:_\d+)?$/',
    '/^VID-\d{8}-WA\d{4,}$/i',
    '/^video_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/i',
];
const NB_SLIDESHOW_CREDIT = 'Reunion Committee';
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
        nb_migrate($pdo);
        nb_backfill_seq($pdo);
        nb_daily_backup($pdo);
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
    $cols = array_column($db->query('PRAGMA table_info(media)')->fetchAll(), 'name');
    $skipSlides = in_array('slideshow_seq', $cols, true) ? ' AND slideshow_seq IS NULL' : '';
    if (!(int) $db->query("SELECT COUNT(*) FROM media WHERE seq IS NULL AND kind IN ('image','video')" . $skipSlides)->fetchColumn()) {
        return;
    }
    $db->exec('BEGIN IMMEDIATE');
    try {
        foreach (['image', 'video'] as $kind) {
            $ids = $db->query("SELECT id FROM media WHERE kind = '$kind' AND seq IS NULL" . $skipSlides . " ORDER BY created_at, rowid")->fetchAll(PDO::FETCH_COLUMN);
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
 * A file's original name with spaces turned into dashes ("Reunion Video 1985.mp4" -> "Reunion-Video-1985.mp4"),
 * so the name is identical everywhere and never shows up as %20. A space next to a dash collapses into it
 * ("Video - 1985" -> "Video-1985"); anything else in the name is left exactly as uploaded.
 */
function nb_dash_spaces(string $name): string
{
    $sp = '[\s\x{00A0}\x{2000}-\x{200B}\x{202F}\x{205F}\x{3000}]';
    $name = trim($name);
    $dot = strrpos($name, '.');
    $ext = '';
    if ($dot !== false && $dot > 0) {          // set the extension aside so "Name .mov" never becomes "Name-.mov"
        $ext = substr($name, $dot);
        $name = rtrim(substr($name, 0, $dot));
    }
    return (string) preg_replace("/$sp*-$sp*|$sp+/u", '-', $name) . $ext;
}

/** True if a video's uploaded file name is one of the phone defaults listed in NB_PHONE_VIDEO_PATTERNS. */
function nb_is_phone_video_name(string $filename): bool
{
    $base = trim((string) preg_replace('/\.[A-Za-z0-9]{2,5}$/', '', trim($filename)));
    $base = trim((string) preg_replace('/\s*\(\d+\)$/', '', $base)); // "IMG_1234 (1)" -> "IMG_1234"
    foreach (NB_PHONE_VIDEO_PATTERNS as $re) {
        if (preg_match($re, $base) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * The name everyone sees and downloads.
 *   photos                      NBHS_reunions_0001.jpg    (numbered once, at upload; HEIC downloads as .jpg)
 *   slideshows                  NBHS_slideshow_0001.mp4   (their own series, added by the admin)
 *   classmates' phone videos    NBHS-friends_0001.mov     (only when the uploaded name is a phone default)
 *   every other video, PDFs...  their own uploaded name with spaces as dashes
 * $converted = the file is being served as a JPEG made from a HEIC.
 */
function nb_download_name(array $row, bool $converted = false): string
{
    if ($row['kind'] === 'video' && !empty($row['slideshow_seq'])) {
        return sprintf('NBHS_slideshow_%04d.%s', (int) $row['slideshow_seq'], (string) $row['ext']);
    }
    if ($row['kind'] === 'video' && !empty($row['friends_seq'])) {
        return sprintf(NB_FRIENDS_PREFIX . '%04d.%s', (int) $row['friends_seq'], strtolower((string) $row['ext']));
    }
    if ($row['kind'] === 'image' && !empty($row['seq'])) {
        $ext = (string) $row['ext'];
        $ext = ['jpeg' => 'jpg', 'tiff' => 'tif'][$ext] ?? $ext;
        if ($converted && in_array($ext, ['heic', 'heif'], true)) {
            $ext = 'jpg';
        }
        return sprintf('NBHS_reunions_%04d.%s', (int) $row['seq'], $ext);
    }
    return nb_dash_spaces((string) $row['orig_name']);
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

/**
 * Snapshot the SQLite file. VACUUM INTO is a consistent copy even mid-write. Labels: pre-vN (before a schema
 * change), daily (automatic), manual (admin button). Each label keeps its own most recent copies.
 */
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
    @chmod($dest, 0600);
    $keep = ['pre' => 10, 'daily' => 14, 'manual' => 10];
    foreach ($keep as $prefix => $n) {
        $files = glob($dir . '/nbhs86-' . $prefix . '*.sqlite') ?: [];
        rsort($files);
        foreach (array_slice($files, $n) as $old) {
            @unlink($old);
        }
    }
}

/**
 * At most one automatic snapshot per 24 h. There is no cron on this host, so the first request after the
 * 24 h mark takes it (cheap: the database is a few hundred KB). A lock stops simultaneous requests doubling up.
 */
function nb_daily_backup(PDO $db): void
{
    $dir = nb_data_dir() . '/backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $marker = $dir . '/.last-daily';
    if (is_file($marker) && filesize($marker) > 0 && filemtime($marker) > time() - 86400) {
        return;
    }
    $fh = @fopen($marker, 'c');
    if (!$fh) {
        return;
    }
    if (flock($fh, LOCK_EX | LOCK_NB)) {
        clearstatcache(true, $marker);
        if (!(filesize($marker) > 0 && filemtime($marker) > time() - 86400)) { // nobody beat us to it
            try {
                nb_backup_db($db, 'daily');
                ftruncate($fh, 0);
                fwrite($fh, (string) time());
                fflush($fh);
            } catch (Throwable $e) {
                error_log('nbhs86 daily backup failed: ' . $e->getMessage());
            }
        }
        flock($fh, LOCK_UN);
    }
    fclose($fh);
}

/** @return array{path:string,when:int,size:int,label:string}[] newest first */
function nb_list_backups(): array
{
    $out = [];
    foreach (glob(nb_data_dir() . '/backups/nbhs86-*.sqlite') ?: [] as $f) {
        $out[] = ['path' => $f, 'when' => (int) filemtime($f), 'size' => (int) filesize($f), 'label' => preg_replace('/^nbhs86-(.+)-\d{8}-\d{6}\.sqlite$/', '$1', basename($f))];
    }
    usort($out, fn($a, $b) => [$b['when'], $b['path']] <=> [$a['when'], $a['path']]); // newest first; name (holds the time) breaks ties
    return $out;
}

const NB_SCHEMA_VERSION = 4;

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
        if ($v < 3) {
            // v3: slideshows get their own number series (NBHS_slideshow_0001), assigned when the admin uploads them.
            $mc = array_column($db->query('PRAGMA table_info(media)')->fetchAll(), 'name');
            if (!in_array('slideshow_seq', $mc, true)) {
                $db->exec('ALTER TABLE media ADD COLUMN slideshow_seq INTEGER');
            }
            $jc = array_column($db->query('PRAGMA table_info(jobs)')->fetchAll(), 'name');
            if (!in_array('slideshow', $jc, true)) {
                $db->exec('ALTER TABLE jobs ADD COLUMN slideshow INTEGER NOT NULL DEFAULT 0');
            }
        }
        if ($v < 4) {
            // v4: classmates' phone-named videos get their own number series (NBHS-friends_0001). Existing rows are
            // left alone (they keep their own names); only new uploads are numbered.
            $mc = array_column($db->query('PRAGMA table_info(media)')->fetchAll(), 'name');
            if (!in_array('friends_seq', $mc, true)) {
                $db->exec('ALTER TABLE media ADD COLUMN friends_seq INTEGER');
            }
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

// ---------- error logging ----------
// PHP errors are written to a private file beside the data (never shown to visitors) so a failure that a
// classmate hits can be diagnosed afterwards. The admin page shows the latest entries. Rotates at 2 MB.

function nb_error_log_path(): string
{
    return nb_data_dir() . '/php-errors.log';
}

/** Last $n log lines with server paths and IP addresses masked. */
function nb_recent_errors(int $n = 40): array
{
    $log = nb_error_log_path();
    if (!is_file($log) || filesize($log) === 0) {
        return [];
    }
    $fh = fopen($log, 'rb');
    fseek($fh, max(0, filesize($log) - 65536));
    $lines = preg_split('/\R/', trim((string) stream_get_contents($fh))) ?: [];
    fclose($fh);
    $lines = array_slice($lines, -$n);
    return array_map(fn($l) => (string) preg_replace(['#/(?:usr/)?home/[^/\s]+#', '/\b\d{1,3}(?:\.\d{1,3}){3}\b/'], ['~', '<ip>'], substr($l, 0, 400)), $lines);
}

(static function (): void {
    try {
        $log = nb_error_log_path();
        if (is_file($log) && filesize($log) > 2000000) {
            @rename($log, $log . '.1');
        }
        if (!is_file($log) && @touch($log)) {
            @chmod($log, 0600);
        }
        ini_set('log_errors', '1');
        ini_set('error_log', $log);
    } catch (Throwable) {
        // logging is best effort; never let it stop a page
    }
})();

