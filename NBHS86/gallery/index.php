<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/layout.php';

nb_headers();
if (!nb_configured()) {
    http_response_code(503);
    exit('This site is not set up yet.');
}
if (!nb_is_member()) {
    header('Location: ' . NB_BASE . '/', true, 302); // back to the passcode page
    exit;
}

$open = nb_can_view_gallery();
$slug = (string) ($_GET['f'] ?? '');
$folder = ($open && $slug !== '') ? nb_folder($slug) : null;
if ($open && $slug !== '' && !$folder) {
    http_response_code(404);
}
$gal = NB_BASE . '/gallery/';
$title = $folder ? $folder['title'] . " - NBHS Class of '86" : "Gallery - NBHS Class of '86";
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="referrer" content="no-referrer">
<title><?= nb_h($title) ?></title>
<link rel="stylesheet" href="<?= NB_BASE ?>/assets/css/site.css?v=4">
<link rel="stylesheet" href="<?= NB_BASE ?>/assets/css/gallery.css?v=2">
<?php if ($folder): ?>
<link rel="stylesheet" href="<?= NB_BASE ?>/assets/vendor/photoswipe/photoswipe.css?v=5.4.4">
<?php endif; ?>
</head>
<body class="gallery-page">
<div class="wrap wide">
  <?= nb_nav('gallery') ?>
<?php if (!$open): ?>
  <header class="top">
    <h1>The gallery opens soon</h1>
    <p>Photos, videos and documents from the reunion will be here. Check back shortly.</p>
  </header>
<?php elseif ($slug !== '' && !$folder): ?>
  <header class="top"><h1>Folder not found</h1><p><a href="<?= $gal ?>">Back to the gallery</a></p></header>
<?php elseif (!$folder): ?>
  <header class="top">
    <h1>NBHS Class of '86</h1>
    <p>Reunion photos, videos and documents. Open a folder to browse, download single files, or tick several and download them together as a zip.</p>
  </header>
  <?php
    $counts = nb_db()->query('SELECT album, COUNT(*) FROM media GROUP BY album')->fetchAll(PDO::FETCH_KEY_PAIR);
  ?>
  <div class="folders">
  <?php foreach (nb_folders() as $f): $n = (int) ($counts[$f['slug']] ?? 0); ?>
    <a class="folder-card" href="<?= $gal . nb_h($f['slug']) ?>/">
      <span class="folder-icon"><?= nb_icon($f['icon'], 44) ?></span>
      <span class="folder-title"><?= nb_h($f['title']) ?></span>
      <span class="folder-count"><?= $n ?> item<?= $n === 1 ? '' : 's' ?></span>
    </a>
  <?php endforeach; ?>
  </div>
<?php else: ?>
  <header class="folder-head">
    <a class="back" href="<?= $gal ?>">&larr; All folders</a>
    <h1><span class="folder-icon sm"><?= nb_icon($folder['icon'], 26) ?></span> <?= nb_h($folder['title']) ?></h1>
  </header>

  <div class="toolbar" id="toolbar">
    <div class="chips" id="chips" role="group" aria-label="Show"></div>
    <label class="sort">Sort
      <select id="sort">
        <option value="arrival">Arrival number</option>
        <option value="date_asc">Shot date, oldest first</option>
        <option value="date_desc">Shot date, newest first</option>
      </select>
    </label>
  </div>

  <div id="grid" class="grid" aria-live="polite"></div>
  <p id="empty" class="hint" hidden>Nothing here yet.</p>
  <div id="sentinel" aria-hidden="true"></div>

  <div class="selbar" id="selbar" hidden>
    <span id="selcount">0 selected</span>
    <span class="selactions">
      <button type="button" class="secondary" id="selAll">Select all</button>
      <button type="button" class="secondary" id="selClear">Clear</button>
      <button type="button" id="selDownload"><?= nb_icon('download', 18) ?> Download selected (.zip)</button>
    </span>
    <span id="selmsg" class="selmsg" role="status"></span>
  </div>
<?php endif; ?>
</div>
<?php if ($folder): ?>
<script>window.NBHS = <?= json_encode([
    'base' => NB_BASE,
    'folder' => $folder['slug'],
    'title' => $folder['title'],
    'icons' => ['check' => nb_icon('check', 18), 'play' => nb_icon('play', 22), 'download' => nb_icon('download', 22), 'pdf' => nb_icon('file-text', 22)],
    'maxFiles' => NB_ZIP_MAX_FILES,
]) ?>;</script>
<script type="module" src="<?= NB_BASE ?>/assets/js/gallery.js?v=1"></script>
<?php endif; ?>
</body>
</html>
