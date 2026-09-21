<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/credits.php';

nb_headers();

$title = "NBHS Class of '86";
$error = '';

if (!nb_configured()) {
    http_response_code(503);
    $error = 'This site is not set up yet.';
    $member = false;
} else {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['passcode'])) {
        if (!nb_login_allowed()) {
            $error = 'Too many attempts. Please wait a few minutes and try again.';
        } elseif (nb_check_passcode((string) $_POST['passcode'])) {
            nb_issue_cookie(NB_MEMBER_COOKIE, 90 * 86400);
            header('Location: ' . NB_BASE . '/', true, 303);
            exit;
        } else {
            nb_login_failed();
            $error = "That passcode didn't work. Please try again.";
        }
    }
    $member = nb_is_member();
}
$intakeClosed = $member && !nb_intake_open() && !nb_is_admin();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">
<meta name="referrer" content="no-referrer">
<title><?= nb_h($title) ?></title>
<link rel="stylesheet" href="<?= NB_BASE ?>/assets/css/site.css?v=10">
<?php if ($member && !$intakeClosed): ?>
<link rel="stylesheet" href="<?= NB_BASE ?>/assets/vendor/uppy/uppy.min.css?v=6.0.1">
<link rel="stylesheet" href="<?= NB_BASE ?>/assets/css/uppy-theme.css?v=7">
<?php endif; ?>
</head>
<body>
<?php if (!$member): ?>
<div class="wrap gate">
  <header class="top">
    <h1><?= nb_h($title) ?></h1>
    <p>Enter the class passcode to continue.</p>
  </header>
  <form class="card" method="post" action="<?= NB_BASE ?>/" autocomplete="off">
    <div>
      <label for="passcode">Passcode</label>
      <input id="passcode" name="passcode" type="password" autocapitalize="off" autocorrect="off" spellcheck="false" autofocus required>
      <div class="err" role="alert"><?= nb_h($error) ?></div>
    </div>
    <button type="submit"<?= nb_configured() ? '' : ' disabled' ?>>Continue</button>
  </form>
</div>
<?php elseif ($intakeClosed): ?>
<div class="wrap">
  <?= nb_header_image() ?>
  <?= nb_nav('upload') ?>
  <header class="top">
    <h1>Uploads are closed</h1>
    <p>Thank you to everyone who shared photos and videos.<?= nb_gallery_open() ? '' : ' The gallery will open soon.' ?></p>
  </header>
</div>
<?php else: ?>
<div class="wrap">
  <?= nb_header_image() ?>
  <?= nb_nav('upload') ?>
  <header class="top">
    <h1>Share your reunion photos &amp; videos</h1>
    <p>Add photos, videos, or PDFs from the reunion &mdash; one at a time, a whole batch, or a .zip file. Works from your phone or computer.</p>
  </header>

  <section class="card" id="whoCard">
    <h2>1. Who's sharing?</h2>
    <label for="uploader">Your name</label>
    <input id="uploader" type="text" autocomplete="name" maxlength="60" placeholder="First and last name">
    <label class="check"><input id="anon" type="checkbox"> <span>Submit anonymously (your &quot;name&quot; will show up as <?= nb_h(NB_ANON_CREDIT) ?>).</span></label>
    <div class="hint">Photos, Videos, etc. are credited by first name only.</div>
    <div class="err" id="nameErr" role="alert"></div>
  </section>

  <section class="card thanks" id="thanks" aria-live="polite">
    <h2 id="thanksTitle">Thank you!</h2>
    <p id="thanksBody"></p>
    <button type="button" id="moreBtn">Add more files</button>
  </section>

  <section class="card" id="filesCard">
    <h2>2. Choose your files</h2>
    <div id="uppy"></div>
    <div class="hint">Photos (JPG, HEIC, PNG), videos (MP4, MOV), PDFs, or .zip archives &middot; up to 2 GB per file.</div>
  </section>

  <footer class="foot">Something not working? Try reloading the page and uploading again &mdash; partial uploads pick up where they left off.</footer>
</div>
<script>window.NBHS = { base: <?= json_encode(NB_BASE) ?> };</script>
<script type="module" src="<?= NB_BASE ?>/assets/js/intake.js?v=5"></script>
<?php endif; ?>
</body>
</html>
