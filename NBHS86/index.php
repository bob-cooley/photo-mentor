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
<link rel="stylesheet" href="<?= NB_BASE ?>/assets/css/site.css?v=14">
<?php if ($member && !$intakeClosed): ?>
<link rel="stylesheet" href="<?= NB_BASE ?>/assets/vendor/uppy/uppy.min.css?v=6.0.1">
<link rel="stylesheet" href="<?= NB_BASE ?>/assets/css/uppy-theme.css?v=7">
<?php endif; ?>
</head>
<body>
<?php if (!$member): ?>
<div class="wrap gate">
  <?= nb_header_image() ?>
  <header class="top">
    <p>Enter the class passcode to continue.</p>
  </header>
  <form class="card" method="post" action="<?= NB_BASE ?>/" autocomplete="off">
    <div>
      <label for="passcode">Passcode</label>
      <input id="passcode" name="passcode" type="password" data-reveal autocapitalize="off" autocorrect="off" spellcheck="false" autofocus required>
      <div class="err" role="alert"><?= nb_h($error) ?></div>
    </div>
    <button type="submit"<?= nb_configured() ? '' : ' disabled' ?>>Continue</button>
  </form>
  <footer class="foot"><p class="contact-line"><?= nb_contact_line() ?></p></footer>
</div>
<?php elseif ($intakeClosed): ?>
<div class="wrap">
  <?= nb_header_image() ?>
  <?= nb_nav('upload') ?>
  <header class="top">
    <h1>Uploads are closed</h1>
    <p>Thank you to everyone who shared photos and videos.<?= nb_gallery_open() ? '' : ' The gallery will open soon.' ?></p>
  </header>
  <footer class="foot"><p class="contact-line"><?= nb_contact_line() ?></p></footer>
</div>
<?php else: ?>
<div class="wrap">
  <?= nb_header_image() ?>
  <?= nb_nav('upload') ?>
  <header class="top">
    <h1>Share your reunion photos &amp; videos</h1>
    <p>Upload and share photos and videos from the reunion and party - one at a time, a whole batch, or in a .zip file. Works from your phone or computer. They will only be available to people who were at the events!</p>
  </header>

  <section class="card" id="whoCard">
    <h2>1. Who's sharing?</h2>
    <label for="firstName">Your name</label>
    <div class="namerow">
      <input id="firstName" type="text" autocomplete="given-name" maxlength="60" placeholder="First name" aria-label="First name">
      <input id="lastName" type="text" autocomplete="family-name" maxlength="60" placeholder="Last name" aria-label="Last name">
    </div>
    <label for="email" class="fieldgap">Email</label>
    <input id="email" type="email" autocomplete="email" inputmode="email" maxlength="254" autocapitalize="off" autocorrect="off" spellcheck="false" placeholder="you@example.com">
    <div class="hint">Your email will be used only to notify you when the gallery is open for downloads (soon!). It will be kept private (we promise!)</div>
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
    <div class="hint">Photos (JPG, HEIC, PNG), videos (MP4, MOV), or .zip archives &middot; up to 2 GB per file.</div>
  </section>

  <footer class="foot">
    <p>Uploads stalled? Try reloading the page and uploading again. Partial uploads pick up where they left off.</p>
    <p class="contact-line"><?= nb_contact_line() ?></p>
  </footer>
</div>
<script>window.NBHS = { base: <?= json_encode(NB_BASE) ?> };</script>
<script type="module" src="<?= NB_BASE ?>/assets/js/intake.js?v=8"></script>
<?php endif; ?>
<script src="<?= NB_BASE ?>/assets/js/reveal.js?v=1"></script>
<script src="<?= NB_BASE ?>/assets/js/contact.js?v=1"></script>
</body>
</html>
