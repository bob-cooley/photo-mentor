<?php
declare(strict_types=1);

// Bob-only view of what has been uploaded (testing aid until the gallery exists).
require_once __DIR__ . '/../lib/ingest.php';
require_once __DIR__ . '/../lib/credits.php';

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

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Bad token.');
    }
    switch ($_POST['action']) {
        case 'logout':
            nb_clear_cookie(NB_ADMIN_COOKIE);
            header('Location: ' . $self, true, 303);
            exit;
        case 'delete':
            $ids = array_filter((array) ($_POST['ids'] ?? []), fn($i) => is_string($i) && preg_match('/^[a-f0-9]{12}$/', $i));
            $sel = nb_db()->prepare('SELECT * FROM media WHERE id = ?');
            $del = nb_db()->prepare('DELETE FROM media WHERE id = ?');
            $n = 0;
            foreach ($ids as $i) {
                $sel->execute([$i]);
                if ($row = $sel->fetch()) {
                    @unlink(nb_media_path($row['folder'], $row['id'], $row['ext']));
                    @unlink(nb_data_dir() . '/thumbs/' . $row['id'] . '.jpg');
                    $del->execute([$i]);
                    $n++;
                }
            }
            $notice = "Deleted $n file" . ($n === 1 ? '' : 's') . '.';
            break;
        case 'reset_numbering':
            if ((int) nb_db()->query('SELECT COUNT(*) FROM media')->fetchColumn() === 0 && ($_POST['confirm'] ?? '') === 'RESET') {
                nb_db()->exec('DELETE FROM counters');
                $notice = 'Numbering reset. The next photo and video will be 0001.';
            } else {
                $notice = 'Numbering can only be reset while no files are stored.';
            }
            break;
        case 'run_jobs':
            $left = nb_run_jobs(20);
            $notice = $left ? "$left zip job(s) still pending; run again." : 'All zip jobs finished.';
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
<link rel="stylesheet" href="<?= NB_BASE ?>/assets/css/site.css?v=1">
</head>
<body>
<?php if (!$isAdmin): ?>
<div class="wrap gate">
  <header class="top"><h1>Admin</h1></header>
  <form class="card" method="post" action="<?= $self ?>" autocomplete="off">
    <label for="pw">Admin password</label>
    <input id="pw" name="admin_password" type="password" autofocus required>
    <div class="err" role="alert"><?= nb_h($error) ?></div>
    <button type="submit">Sign in</button>
  </form>
</div>
<?php else:
    $db = nb_db();
    $tot = $db->query('SELECT COUNT(*) c, COALESCE(SUM(size),0) s FROM media')->fetch();
    $byKind = $db->query('SELECT kind, COUNT(*) c FROM media GROUP BY kind')->fetchAll(PDO::FETCH_KEY_PAIR);
    $pending = nb_pending_jobs();
    $perPage = 100;
    $page = max(1, (int) ($_GET['p'] ?? 1));
    $rows = $db->query('SELECT * FROM media ORDER BY created_at DESC, id LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage))->fetchAll();
    $labels = nb_credit_labels($db->query("SELECT DISTINCT uploader FROM media WHERE anonymous = 0 AND uploader <> ''")->fetchAll(PDO::FETCH_COLUMN));
    $pages = max(1, (int) ceil($tot['c'] / $perPage));
?>
<div class="wrap wide">
  <header class="top"><h1>NBHS86 uploads</h1></header>
  <?php if ($notice): ?><div class="card"><?= nb_h($notice) ?></div><?php endif; ?>

  <section class="card">
    <div class="stats">
      <div><b><?= (int) $tot['c'] ?></b><span>files</span></div>
      <div><b><?= nb_h(fmt_bytes((int) $tot['s'])) ?></b><span>stored</span></div>
      <div><b><?= (int) ($byKind['image'] ?? 0) ?></b><span>images</span></div>
      <div><b><?= (int) ($byKind['video'] ?? 0) ?></b><span>videos</span></div>
      <div><b><?= (int) ($byKind['pdf'] ?? 0) ?></b><span>PDFs</span></div>
      <div><b><?= $pending ?></b><span>zip jobs pending</span></div>
    </div>
  </section>

  <form method="post" action="<?= $self ?>" onsubmit="return this.querySelector('[name=action]:checked')?.value !== 'delete' || confirm('Delete the selected files permanently?')">
    <input type="hidden" name="csrf" value="<?= nb_h($csrf) ?>">
    <section class="card" style="overflow-x:auto">
      <table class="grid">
        <thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('.pick').forEach(c=>c.checked=this.checked)" aria-label="Select all"></th><th></th><th>Name</th><th>Original file</th><th>Credit</th><th>Entered as</th><th>Size</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><input class="pick" type="checkbox" name="ids[]" value="<?= nb_h($r['id']) ?>"></td>
            <td><a href="<?= NB_BASE ?>/api/file.php?id=<?= nb_h($r['id']) ?>" target="_blank" rel="noopener"><img loading="lazy" src="<?= NB_BASE ?>/api/thumb.php?id=<?= nb_h($r['id']) ?>" alt=""></a></td>
            <td><b><?= nb_h(nb_download_name($r)) ?></b></td>
            <td><?= nb_h($r['orig_name']) ?><div class="hint"><?= nb_h($r['kind']) ?><?= $r['source'] ? ' &middot; from ' . nb_h(substr($r['source'], 4)) : '' ?></div></td>
            <td><?= nb_h(nb_credit_for($r, $labels)) ?></td>
            <td><?= $r['anonymous'] ? '<span class="hint">(anonymous)</span>' : nb_h($r['uploader']) ?></td>
            <td><?= nb_h(fmt_bytes((int) $r['size'])) ?></td>
            <td><?= nb_h(date('M j, g:ia', (int) $r['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="hint">Nothing uploaded yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>
    <p>
      <button type="submit" name="action" value="delete" class="secondary">Delete selected</button>
      <button type="submit" name="action" value="run_jobs" class="secondary">Finish pending zips</button>
      <button type="submit" name="action" value="logout" class="secondary">Sign out</button>
    </p>
  </form>
  <?php if ((int) $tot['c'] === 0): ?>
    <form method="post" action="<?= $self ?>" class="card" onsubmit="return confirm('Restart photo and video numbering at 0001?')">
      <input type="hidden" name="csrf" value="<?= nb_h($csrf) ?>">
      <input type="hidden" name="action" value="reset_numbering">
      <input type="hidden" name="confirm" value="RESET">
      <span class="hint">No files are stored, so numbering can be restarted (do this only before launch).</span>
      <button type="submit" class="secondary">Reset numbering to 0001</button>
    </form>
  <?php endif; ?>
  <?php if ($pages > 1): ?>
    <p class="hint">Page <?= $page ?> of <?= $pages ?>
      <?php if ($page > 1): ?> &middot; <a href="?p=<?= $page - 1 ?>" style="color:var(--accent)">newer</a><?php endif; ?>
      <?php if ($page < $pages): ?> &middot; <a href="?p=<?= $page + 1 ?>" style="color:var(--accent)">older</a><?php endif; ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>
</body>
</html>
