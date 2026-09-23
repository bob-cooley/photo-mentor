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
<link rel="stylesheet" href="<?= NB_BASE ?>/assets/css/site.css?v=17">
<link rel="stylesheet" href="<?= NB_BASE ?>/assets/css/gallery.css?v=16">
<?php if ($folder): ?>
<link rel="stylesheet" href="<?= NB_BASE ?>/assets/vendor/photoswipe/photoswipe.css?v=5.4.4">
<?php endif; ?>
</head>
<body class="gallery-page">
<?= nb_page_fade() ?>
<div class="wrap wide">
  <?= nb_header_image() ?>
  <?php if (nb_is_admin()): ?><?= nb_nav('gallery') ?><?php endif; ?>
  <?= nb_gallery_cta() ?>
<?php if (!$open): ?>
  <?php
    $soonByFolder = nb_media_counts_by_folder();
    $soonTotal = array_sum(array_map('array_sum', $soonByFolder));
    $soonFolders = array_values(array_filter(nb_folders(), fn($f) => array_sum($soonByFolder[$f['slug']] ?? []) > 0));
  ?>
  <header class="top">
    <h1><?= $soonTotal > 0 ? number_format($soonTotal) . ' item' . ($soonTotal === 1 ? '' : 's') . ' uploaded so far!' : 'The gallery opens soon' ?></h1>
    <p><?= $soonTotal > 0
        ? 'Photos, videos and documents from the reunion are already coming in. The gallery isn&rsquo;t open for browsing and downloads yet, so here&rsquo;s a preview. Check back soon to see it all.'
        : 'Photos, videos and documents from the reunion will be here. Check back shortly.' ?></p>
  </header>
  <?php if ($soonFolders): ?>
  <div class="folders">
  <?php foreach ($soonFolders as $f): ?>
    <div class="folder-card folder-preview">
      <span class="folder-icon"><?= nb_icon($f['icon'], 44) ?></span>
      <span class="folder-title"><?= nb_h($f['title']) ?></span>
      <span class="folder-count"><?= nb_h(nb_folder_count_label($f['slug'], $soonByFolder[$f['slug']] ?? [])) ?></span>
    </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php elseif ($slug !== '' && !$folder): ?>
  <header class="top"><h1>Folder not found</h1><p><a href="<?= $gal ?>">Back to the gallery</a></p></header>
<?php elseif (!$folder): ?>
  <header class="top">
    <p>Reunion photos, videos and documents. Open a folder to browse, download single files, or tick several and download them together as a zip.</p>
  </header>
  <?php
    $byFolder = nb_media_counts_by_folder();
  ?>
  <div class="folders">
  <?php foreach (nb_folders() as $f): ?>
    <a class="folder-card" href="<?= $gal . nb_h($f['slug']) ?>/">
      <span class="folder-icon"><?= nb_icon($f['icon'], 44) ?></span>
      <span class="folder-title"><?= nb_h($f['title']) ?></span>
      <span class="folder-count"><?= nb_h(nb_folder_count_label($f['slug'], $byFolder[$f['slug']] ?? [])) ?></span>
    </a>
  <?php endforeach; ?>
  </div>
<?php else: ?>
  <header class="folder-head">
    <a class="back" href="<?= $gal ?>">&larr; All Galleries</a>
    <h1><span class="folder-icon sm"><?= nb_icon($folder['icon'], 26) ?></span> <?= nb_h($folder['title']) ?></h1>
    <?php if ($folder['slug'] === 'photos'): ?><p class="hint">Click on any photo to open it in a scrollable lightbox.</p><?php endif; ?>
  </header>

  <div class="toolbar" id="toolbar">
    <div class="chips" id="chips" role="group" aria-label="Show"></div>
    <label class="sort">Sort
      <select id="sort">
        <option value="arrival">Upload date</option>
        <option value="date_asc">Creation date, oldest first</option>
        <option value="date_desc">Creation date, newest first</option>
      </select>
    </label>
  </div>

  <div id="grid" class="grid" aria-live="polite"></div>
  <p id="empty" class="hint" hidden>Nothing here yet.</p>
  <div id="sentinel" aria-hidden="true"></div>

  <?php $zipHint = nb_mobile_zip_hint(); ?>
  <div class="selbar" id="selbar">
    <span id="selcount">Nothing selected yet</span>
    <span class="selactions">
      <button type="button" class="secondary" id="selAll">Select all</button>
      <button type="button" class="secondary" id="selClear">Clear</button>
      <button type="button" id="selDownload" disabled title="Tick one or more files first, or use Select all"><?= nb_icon('download', 18) ?> Download <span class="hide-sm">selected</span></button>
    </span>
    <?php if ($zipHint !== ''): ?><span class="selbar-hint" id="selZipHint" hidden><?= nb_h($zipHint) ?></span><?php endif; ?>
    <span id="selmsg" class="selmsg" role="status"></span>
    <div class="selbar-contact"><?= nb_contact_line() ?></div>
  </div>
<?php endif; ?>
<?php if (!$folder): ?>
  <footer class="foot"><p class="contact-line"><?= nb_contact_line() ?></p></footer>
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
<script type="module" src="<?= NB_BASE ?>/assets/js/gallery.js?v=7"></script>
<?php endif; ?>
<script src="<?= NB_BASE ?>/assets/js/contact.js?v=1"></script>
<script src="<?= NB_BASE ?>/assets/js/page-fade.js?v=1"></script>
</body>
</html>
