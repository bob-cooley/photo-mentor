<?php
declare(strict_types=1);

// Bob-only: launch switches, folders, and file management (move, credit, delete).
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/derive.php';
require_once __DIR__ . '/../lib/credits.php';
require_once __DIR__ . '/../lib/contacts.php';

nb_headers();
set_time_limit(60);
$self = NB_BASE . '/admin/';
$error = '';

if (!nb_configured() || empty(nb_config()['admin_hash'])) {
    http_response_code(503);
    exit('Admin is not configured.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    if (!nb_login_allowed()) {
        $error = 'Too many attempts. Wait a few minutes.';
    } elseif (nb_check_admin_password((string) $_POST['admin_password'])) {
        nb_issue_cookie(NB_ADMIN_COOKIE, 12 * 3600);
        header('Location: ' . $self, true, 303);
        exit;
    } else {
        nb_login_failed();
        $error = 'Wrong password.';
    }
}

$isAdmin = nb_is_admin();
$csrf = $isAdmin ? nb_sign('csrf|' . $_COOKIE[NB_ADMIN_COOKIE]) : '';
$notice = '';

function admin_ids(): array
{
    return array_values(array_filter((array) ($_POST['ids'] ?? []), fn($i) => is_string($i) && preg_match('/^[a-f0-9]{12}$/', $i)));
}

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Bad token.');
    }
    $db = nb_db();
    switch ($_POST['action']) {
        case 'logout':
            nb_clear_cookie(NB_ADMIN_COOKIE);
            header('Location: ' . $self, true, 303);
            exit;

        case 'set_gallery':
        case 'set_intake':
            $key = $_POST['action'] === 'set_gallery' ? 'gallery_open' : 'intake_open';
            nb_set_setting($key, ($_POST['value'] ?? '') === '1' ? '1' : '0');
            $notice = ($key === 'gallery_open' ? 'Gallery' : 'Uploading') . (nb_setting($key) === '1' ? ' is now OPEN to classmates.' : ' is now CLOSED to classmates.');
            break;

        case 'folder_save':
            $up = $db->prepare('UPDATE folders SET title = ?, icon = ?, sort = ? WHERE slug = ?');
            foreach ((array) ($_POST['f'] ?? []) as $slug => $f) {
                $title = trim((string) ($f['title'] ?? ''));
                if ($title === '' || !is_string($slug)) {
                    continue;
                }
                $icon = in_array($f['icon'] ?? '', ['camera', 'film', 'file-text', 'grid'], true) ? $f['icon'] : 'grid';
                $up->execute([mb_substr($title, 0, 60), $icon, (int) ($f['sort'] ?? 0), $slug]);
            }
            $notice = 'Folders saved.';
            break;

        case 'folder_add':
            $title = trim((string) ($_POST['new_title'] ?? ''));
            if ($title === '') {
                $notice = 'Give the new folder a name.';
                break;
            }
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: 'folder';
            $base = $slug;
            for ($i = 2; nb_folder($slug); $i++) {
                $slug = "$base-$i";
            }
            $icon = in_array($_POST['new_icon'] ?? '', ['camera', 'film', 'file-text', 'grid'], true) ? $_POST['new_icon'] : 'grid';
            $max = (int) $db->query('SELECT COALESCE(MAX(sort), 0) FROM folders')->fetchColumn();
            $db->prepare('INSERT INTO folders (slug, title, icon, sort) VALUES (?,?,?,?)')->execute([$slug, mb_substr($title, 0, 60), $icon, $max + 10]);
            $notice = "Folder \"$title\" added.";
            break;

        case 'move':
            $dest = (string) ($_POST['album'] ?? '');
            if (!nb_folder($dest)) {
                $notice = 'Pick a folder to move to.';
                break;
            }
            $up = $db->prepare('UPDATE media SET album = ? WHERE id = ?');
            $n = 0;
            foreach (admin_ids() as $i) {
                $up->execute([$dest, $i]);
                $n += $up->rowCount();
            }
            $notice = "Moved $n file" . ($n === 1 ? '' : 's') . ' to ' . nb_folder($dest)['title'] . '.';
            break;

        case 'credit_set':
        case 'credit_clear':
            $text = $_POST['action'] === 'credit_set' ? mb_substr(trim((string) ($_POST['credit_text'] ?? '')), 0, 60) : '';
            if ($_POST['action'] === 'credit_set' && $text === '') {
                $notice = 'Type the credit to show (for example: Reunion Committee).';
                break;
            }
            $up = $db->prepare('UPDATE media SET credit_override = ? WHERE id = ?');
            $n = 0;
            foreach (admin_ids() as $i) {
                $up->execute([$text === '' ? null : $text, $i]);
                $n += $up->rowCount();
            }
            $notice = ($text === '' ? 'Credit override cleared on ' : "Credit set to \"$text\" on ") . "$n file" . ($n === 1 ? '' : 's') . '.';
            break;

        case 'delete':
            $sel = $db->prepare('SELECT * FROM media WHERE id = ?');
            $del = $db->prepare('DELETE FROM media WHERE id = ?');
            $n = 0;
            foreach (admin_ids() as $i) {
                $sel->execute([$i]);
                if ($row = $sel->fetch()) {
                    @unlink(nb_src_path($row));
                    @unlink(nb_data_dir() . '/thumbs/' . $row['id'] . '.jpg');
                    foreach (glob(nb_derived_dir() . '/' . $row['id'] . '-*') ?: [] as $d) {
                        @unlink($d);
                    }
                    $del->execute([$i]);
                    $n++;
                }
            }
            $notice = "Deleted $n file" . ($n === 1 ? '' : 's') . '.';
            break;

        case 'reset_numbering':
            if ((int) $db->query('SELECT COUNT(*) FROM media')->fetchColumn() === 0 && ($_POST['confirm'] ?? '') === 'RESET') {
                $db->exec('DELETE FROM counters');
                $notice = 'Numbering reset. The next photo and video will be 0001.';
            } else {
                $notice = 'Numbering can only be reset while no files are stored.';
            }
            break;

        case 'contact_delete':
            $notice = nb_delete_contact($db, (string) ($_POST['email'] ?? '')) ? 'Contact removed.' : 'That contact was not found.';
            break;

        case 'clear_errors':
            @file_put_contents(nb_error_log_path(), '');
            $notice = 'Error log cleared.';
            break;

        case 'run_jobs':
            $left = nb_run_jobs(20);
            $notice = $left ? "$left zip job(s) still pending; run again." : 'All zip jobs finished.';
            break;

        case 'fill_meta':
            $before = (int) $db->query('SELECT COUNT(*) FROM media WHERE w IS NULL')->fetchColumn();
            nb_fill_meta(400, 20.0);
            $now = (int) $db->query('SELECT COUNT(*) FROM media WHERE w IS NULL')->fetchColumn();
            $notice = $now ? 'Read dimensions and dates for ' . ($before - $now) . " files; $now left, run again." : 'Dimensions and shot dates are up to date.';
            break;
    }
}

function fmt_bytes(int $b): string
{
    foreach (['B', 'KB', 'MB', 'GB'] as $i => $u) {
        if ($b < 1024 ** ($i + 1) || $u === 'GB') {
            return ($i ? number_format($b / 1024 ** $i, $i > 1 ? 2 : 1) : (string) $b) . ' ' . $u;
        }
    }
    return $b . ' B';
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<title>NBHS86 admin</title>
<link rel="stylesheet" href="<?= NB_BASE ?>/assets/css/site.css?v=17">
<?php if ($isAdmin): ?><link rel="stylesheet" href="<?= NB_BASE ?>/assets/vendor/uppy/uppy.min.css?v=6.0.1"><link rel="stylesheet" href="<?= NB_BASE ?>/assets/css/uppy-theme.css?v=7"><?php endif; ?>
</head>
<body>
<?php if (!$isAdmin): ?>
<div class="wrap gate">
  <header class="top"><h1>Admin</h1></header>
  <form class="card" method="post" action="<?= $self ?>" autocomplete="off">
    <label for="pw">Admin password</label>
    <input id="pw" name="admin_password" type="password" data-reveal autofocus required>
    <div class="err" role="alert"><?= nb_h($error) ?></div>
    <button type="submit">Sign in</button>
  </form>
</div>
<?php else:
    $db = nb_db();
    $tot = $db->query('SELECT COUNT(*) c, COALESCE(SUM(size),0) s FROM media')->fetch();
    $byKind = $db->query('SELECT kind, COUNT(*) c FROM media GROUP BY kind')->fetchAll(PDO::FETCH_KEY_PAIR);
    $pending = nb_pending_jobs();
    $noMeta = (int) $db->query('SELECT COUNT(*) FROM media WHERE w IS NULL')->fetchColumn();
    $folders = nb_folders();
    $folderTitles = array_column($folders, 'title', 'slug');
    $folderCounts = $db->query('SELECT album, COUNT(*) FROM media GROUP BY album')->fetchAll(PDO::FETCH_KEY_PAIR);
    $perPage = 100;
    $page = max(1, (int) ($_GET['p'] ?? 1));
    $rows = $db->query('SELECT * FROM media ORDER BY created_at DESC, id LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage))->fetchAll();
    $labels = nb_credit_labels($db->query("SELECT DISTINCT uploader FROM media WHERE anonymous = 0 AND uploader <> ''")->fetchAll(PDO::FETCH_COLUMN));
    $pages = max(1, (int) ceil($tot['c'] / $perPage));
    $galleryOpen = nb_gallery_open();
    $intakeOpen = nb_intake_open();
    $contacts = nb_contacts($db);
    $iconNames = ['camera' => 'Camera (Photos)', 'film' => 'Film (Videos)', 'file-text' => 'Document', 'grid' => 'Grid'];
    $hidden = '<input type="hidden" name="csrf" value="' . nb_h($csrf) . '">';
?>
<div class="wrap wide">
  <?= nb_nav('admin') ?>
  <header class="top"><h1>NBHS86 admin</h1></header>
  <?php if ($notice): ?><div class="card"><?= nb_h($notice) ?></div><?php endif; ?>
  <?php if (!nb_tool('exiftool')): ?><div class="card err">exiftool is not available here, so GPS location data is NOT being stripped from downloads.</div><?php endif; ?>

  <section class="card">
    <h2>Launch switches</h2>
    <div class="switches">
      <form method="post" action="<?= $self ?>"><?= $hidden ?>
        <input type="hidden" name="action" value="set_intake"><input type="hidden" name="value" value="<?= $intakeOpen ? '0' : '1' ?>">
        <span class="state <?= $intakeOpen ? 'on' : 'off' ?>">Uploading is <b><?= $intakeOpen ? 'OPEN' : 'CLOSED' ?></b> to classmates</span>
        <button type="submit" class="secondary"><?= $intakeOpen ? 'Close uploading' : 'Open uploading' ?></button>
      </form>
      <form method="post" action="<?= $self ?>"><?= $hidden ?>
        <input type="hidden" name="action" value="set_gallery"><input type="hidden" name="value" value="<?= $galleryOpen ? '0' : '1' ?>">
        <span class="state <?= $galleryOpen ? 'on' : 'off' ?>">Gallery is <b><?= $galleryOpen ? 'OPEN' : 'CLOSED' ?></b> to classmates</span>
        <button type="submit" class="secondary"><?= $galleryOpen ? 'Close gallery' : 'Open gallery' ?></button>
      </form>
    </div>
    <div class="hint">You always see both sides as admin, whatever the switches say.</div>
  </section>

  <section class="card">
    <h2>Folders</h2>
    <form method="post" action="<?= $self ?>"><?= $hidden ?>
      <input type="hidden" name="action" value="folder_save">
      <table class="grid">
        <thead><tr><th>Icon</th><th>Name</th><th>Order</th><th>Files</th></tr></thead>
        <tbody>
        <?php foreach ($folders as $f): ?>
          <tr>
            <td>
              <select name="f[<?= nb_h($f['slug']) ?>][icon]">
                <?php foreach ($iconNames as $k => $label): ?><option value="<?= $k ?>"<?= $f['icon'] === $k ? ' selected' : '' ?>><?= nb_h($label) ?></option><?php endforeach; ?>
              </select>
            </td>
            <td><input type="text" name="f[<?= nb_h($f['slug']) ?>][title]" value="<?= nb_h($f['title']) ?>" maxlength="60"></td>
            <td><input type="text" name="f[<?= nb_h($f['slug']) ?>][sort]" value="<?= (int) $f['sort'] ?>" inputmode="numeric" style="width:5em"></td>
            <td><?= (int) ($folderCounts[$f['slug']] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <p><button type="submit" class="secondary">Save folders</button></p>
    </form>
    <form method="post" action="<?= $self ?>" class="inline"><?= $hidden ?>
      <input type="hidden" name="action" value="folder_add">
      <input type="text" name="new_title" placeholder="New folder name" maxlength="60">
      <select name="new_icon"><?php foreach ($iconNames as $k => $label): ?><option value="<?= $k ?>"><?= nb_h($label) ?></option><?php endforeach; ?></select>
      <button type="submit" class="secondary">Add folder</button>
    </form>
  </section>

  <section class="card">
    <h2>Classmate contacts</h2>
    <p style="margin:0 0 8px"><b><?= count($contacts) ?></b> email<?= count($contacts) === 1 ? '' : 's' ?> collected from the upload page (everyone who uploaded, including anonymous uploads).</p>
    <div class="inline">
      <a class="btn secondary" href="<?= NB_BASE ?>/admin/contacts.php">Download contacts (.csv)</a>
    </div>
    <div class="hint">Columns: First name, Last name, Email. Opens in Excel, Numbers or Google Sheets. Private: shown only here, never in the gallery. Test entries stay until you remove them below.</div>
    <?php if ($contacts): ?>
    <details style="margin-top:12px">
      <summary><b>Show the list</b></summary>
      <div style="overflow-x:auto;max-height:320px;overflow-y:auto;margin-top:8px">
      <table class="grid">
        <thead><tr><th>First</th><th>Last</th><th>Email</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($contacts as $c): ?>
          <tr>
            <td><?= nb_h($c['first']) ?></td>
            <td><?= nb_h($c['last']) ?></td>
            <td><?= nb_h($c['email']) ?></td>
            <td>
              <form method="post" action="<?= $self ?>" onsubmit="return confirm('Remove this contact from the list?')"><?= $hidden ?>
                <input type="hidden" name="action" value="contact_delete"><input type="hidden" name="email" value="<?= nb_h($c['email']) ?>">
                <button type="submit" class="secondary">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </details>
    <?php endif; ?>
  </section>

  <section class="card">
    <details>
      <summary><b>Upload slideshows</b> <span class="hint">(open only when you have slideshow videos to add)</span></summary>
      <p class="hint" style="margin:10px 0">Videos added here go straight into Slideshows, numbered <b>NBHS_slideshow_0001</b>, <b>0002</b>&hellip; and credited to <?= nb_h(NB_SLIDESHOW_CREDIT) ?>. Classmates' uploads on the normal page are not affected.</p>
      <div id="uppy-slideshow"></div>
      <div id="slideMsg" class="hint" role="status" style="margin-top:8px"></div>
    </details>
  </section>

  <?php
    $backups = nb_list_backups();
    $auto = array_values(array_filter($backups, fn($b) => $b['label'] === 'daily'));
    $errs = nb_recent_errors(40);
  ?>
  <section class="card">
    <h2>Backups &amp; errors</h2>
    <p style="margin:0 0 8px">
      <b>Database:</b>
      <?php if ($auto): ?>last automatic backup <?= nb_h(date('M j, g:ia', $auto[0]['when'])) ?> (one is taken about every 24 hours; <?= count($backups) ?> kept on the server).
      <?php else: ?>no automatic backup yet (the first one is taken on the next page load).<?php endif; ?>
    </p>
    <div class="inline">
      <a class="btn secondary" href="<?= NB_BASE ?>/admin/backup.php">Download a fresh database backup (.sqlite)</a>
    </div>
    <div class="hint">Backups sit next to the uploads on the same server, so they protect against a bad change or a damaged database, not against losing the hosting account. Download one now and then, and ask Pair whether it keeps its own backups. The uploaded photos and videos themselves are not in this file.</div>
    <details style="margin-top:14px"<?= $errs ? ' open' : '' ?>>
      <summary><b>PHP errors:</b> <?= $errs ? '<span class="state off"><b>' . count($errs) . ' recent entr' . (count($errs) === 1 ? 'y' : 'ies') . '</b></span>' : 'none logged' ?></summary>
      <?php if ($errs): ?>
        <pre style="white-space:pre-wrap;word-break:break-word;font-size:.8rem;background:var(--panel-2);padding:12px;border-radius:8px;max-height:280px;overflow:auto"><?= nb_h(implode("\n", $errs)) ?></pre>
        <form method="post" action="<?= $self ?>"><?= $hidden ?><input type="hidden" name="action" value="clear_errors"><button type="submit" class="secondary">Clear the error log</button></form>
      <?php endif; ?>
    </details>
  </section>

  <section class="card">
    <div class="stats">
      <div><b><?= (int) $tot['c'] ?></b><span>files</span></div>
      <div><b><?= nb_h(fmt_bytes((int) $tot['s'])) ?></b><span>stored</span></div>
      <div><b><?= (int) ($byKind['image'] ?? 0) ?></b><span>images</span></div>
      <div><b><?= (int) ($byKind['video'] ?? 0) ?></b><span>videos</span></div>
      <div><b><?= (int) ($byKind['pdf'] ?? 0) ?></b><span>PDFs</span></div>
      <div><b><?= $pending ?></b><span>zip jobs pending</span></div>
      <div><b><?= $noMeta ?></b><span>awaiting date/size read</span></div>
    </div>
  </section>

  <form method="post" action="<?= $self ?>" onsubmit="var a=document.activeElement&&document.activeElement.value; return a!=='delete' || confirm('Delete the selected files permanently?')"><?= $hidden ?>
    <section class="card" style="overflow-x:auto">
      <table class="grid">
        <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.pick').forEach(c=>c.checked=this.checked)" aria-label="Select all"></th><th></th><th>Name</th><th>Original file</th><th>Folder</th><th>Credit</th><th>Entered as</th><th>Size</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><input class="pick" type="checkbox" name="ids[]" value="<?= nb_h($r['id']) ?>"></td>
            <td><a href="<?= NB_BASE ?>/api/file.php?id=<?= nb_h($r['id']) ?>" target="_blank" rel="noopener"><img loading="lazy" src="<?= NB_BASE ?>/api/thumb.php?id=<?= nb_h($r['id']) ?>" alt=""></a></td>
            <td><b><?= nb_h(nb_download_name($r, in_array($r['ext'], ['heic', 'heif'], true))) ?></b></td>
            <td><?= nb_h($r['orig_name']) ?><div class="hint"><?= nb_h($r['kind']) ?><?= str_starts_with((string) $r['source'], 'zip:') ? ' &middot; from ' . nb_h(substr($r['source'], 4)) : '' ?></div></td>
            <td><?= nb_h($folderTitles[$r['album'] ?? ''] ?? (string) $r['album']) ?></td>
            <td><?= nb_h(nb_credit_for($r, $labels)) ?><?= !empty($r['credit_override']) ? ' <span class="hint">(override)</span>' : '' ?></td>
            <td><?= $r['anonymous'] ? '<span class="hint">(submitted anonymously)</span>' : nb_h($r['uploader']) ?></td>
            <td><?= nb_h(fmt_bytes((int) $r['size'])) ?></td>
            <td><?= nb_h(date('M j, g:ia', (int) $r['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="hint">Nothing uploaded yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>
    <section class="card">
      <h2>For the selected files</h2>
      <div class="inline">
        <select name="album"><?php foreach ($folders as $f): ?><option value="<?= nb_h($f['slug']) ?>"><?= nb_h($f['title']) ?></option><?php endforeach; ?></select>
        <button type="submit" name="action" value="move" class="secondary">Move to folder</button>
      </div>
      <div class="inline">
        <input type="text" name="credit_text" placeholder="Credit to show, e.g. Reunion Committee" maxlength="60">
        <button type="submit" name="action" value="credit_set" class="secondary">Set credit</button>
        <button type="submit" name="action" value="credit_clear" class="secondary">Clear credit override</button>
      </div>
      <div class="inline">
        <button type="submit" name="action" value="delete" class="secondary">Delete selected</button>
        <button type="submit" name="action" value="run_jobs" class="secondary">Finish pending zips</button>
        <button type="submit" name="action" value="fill_meta" class="secondary">Read dates &amp; sizes</button>
        <button type="submit" name="action" value="logout" class="secondary">Sign out</button>
      </div>
    </section>
  </form>
  <?php if ($pages > 1): ?>
    <p class="hint">Page <?= $page ?> of <?= $pages ?>
      <?php if ($page > 1): ?> &middot; <a href="?p=<?= $page - 1 ?>" style="color:var(--accent)">newer</a><?php endif; ?>
      <?php if ($page < $pages): ?> &middot; <a href="?p=<?= $page + 1 ?>" style="color:var(--accent)">older</a><?php endif; ?></p>
  <?php endif; ?>
  <?php if ((int) $tot['c'] === 0): ?>
    <form method="post" action="<?= $self ?>" class="card" onsubmit="return confirm('Restart photo and video numbering at 0001?')"><?= $hidden ?>
      <input type="hidden" name="action" value="reset_numbering">
      <input type="hidden" name="confirm" value="RESET">
      <span class="hint">No files are stored, so numbering can be restarted (do this only before launch).</span>
      <button type="submit" class="secondary">Reset numbering to 0001</button>
    </form>
  <?php endif; ?>
</div>
<script>window.NBHS = { base: <?= json_encode(NB_BASE) ?> };</script>
<script type="module" src="<?= NB_BASE ?>/assets/js/admin-upload.js?v=2"></script>
<?php endif; ?>
<script src="<?= NB_BASE ?>/assets/js/reveal.js?v=1"></script>
</body>
</html>
