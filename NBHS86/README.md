# NBHS86 - Class of '86 photo intake + gallery

Live at https://www.bobcooleyphoto.com/NBHS86/ (path is case-sensitive).
Deployed by the `deploy-nbhs86` job in `.github/workflows/deploy.yml` to `public_html/bobcooleyphoto/NBHS86/`.
This README, `.gitkeep` files and other `*.md` are excluded from deploy.

## Purpose
1. **Intake** (the `/NBHS86/` page itself, behind the passcode): classmates drag-and-drop or pick photos, videos, PDFs, or .zip archives. Works on mobile and desktop.
2. **Gallery** (`gallery/`): folders/categories, grid + lightbox, per-file download, checkbox select + "Download selected (.zip)".

The two sides launch separately. Intake goes live first for testing.

## Decisions (2026-09-20)
- Access: shared class passcode, stored hashed server-side. **The repo is public. Never commit the passcode, admin password, or any config with secrets.**
- Moderation: none for now. Uploads go straight into the gallery, in the folder for their type (photos to Photos, videos to Videos, PDFs to Documents), mixed in with the committee's own material. There is no separate classmate folder.
- Build: custom PHP + vanilla JS assembled from MIT parts (Uppy, PhotoSwipe, ZipStream-PHP), with a small in-repo tus server instead of tus-php (which pulled ~2,500 vendor files). Not Piwigo.
- Uploader credit: name field plus a "Submit anonymously" checkbox that bypasses it; anonymous uploads are credited as **NBHS Alumni** (`NB_ANON_CREDIT` in `lib/credits.php`, used by the upload page label, gallery, lightbox captions and admin). Credit is first name only; duplicate first names get last initial appended.

## Layout
```
NBHS86/
  index.php            passcode gate + intake page (same URL); shows "uploads closed" when switched off
  gallery/             folder cards, folder grid, lightbox (gallery/.htaccess maps /gallery/<slug>/ to index.php)
  admin/index.php      launch switches, folders, slideshow upload box, move/credit/delete (Bob only)
  api/tus.php          minimal tus 1.0 server (routed from /api/tus/<id> by api/.htaccess)
  api/process.php      continues unfinished zip extraction (intake page polls it)
  api/list.php         folder listing JSON (sort, kind filter, paging, ids for select-all)
  api/thumb.php        gated thumbnail, generated on first request
  api/file.php         gated delivery: inline (GPS-free) / ?dl=1 (NBHS name, HEIC as JPG) / ?raw=1 (admin only)
  api/prepare.php      step 1 of a zip: builds derived copies in time-boxed batches
  api/zip.php          step 2: streams the zip (ZipStream-PHP, no compression, no Zip64)
  lib/                 bootstrap (config, SQLite + migrations, auth, settings), ingest, derive, thumbs, credits, icons, layout, selection
  assets/              css, js (intake.js, gallery.js), vendor/uppy, vendor/photoswipe (MIT, self-hosted)
  tools/, tests/       local only, excluded from deploy
  vendor/              Composer output, built by CI (ZipStream-PHP), gitignored
```

## Gallery behavior
- **Folders** (table `folders`): Photos (camera), Videos (film), Documents (file-text), Slideshows (grid). Icons are Lucide (ISC). New uploads land by type (`NB_ALBUM_BY_KIND`); Slideshows starts empty and Bob moves items into it from the admin page. A file's folder (`album`) is separate from where it sits on disk (`folder`, always `media/classmate-uploads/`, never changes).
- **Launch switches** (table `settings`): `gallery_open` (default closed) and `intake_open` (default open), toggled in the admin page. Admin always sees both sides.
- **Sorting** (labels in `gallery/index.php`): "Upload date" (default, = arrival order) and "Creation date, oldest/newest first" (read with exiftool at ingest, and lazily for older files; scans and other files without a date sort last). Width and height are read the same way.
- **Privacy**: every view and download is served from a GPS-free copy (`derived/<id>-clean.<ext>`, created with exiftool on first use). Originals are only served to the admin (`?raw=1`). If exiftool is missing the admin page warns.
- **HEIC**: lightbox shows a 2400px JPEG; download is a full-size JPEG named `.jpg`. TIFF gets a 2400px JPEG for the lightbox and downloads as TIFF.
- **Selection is never remembered**: the ticked files live only in the open page (the folder is one infinitely scrolling page, so nothing needs saving). Every visit, reload, Back or return starts with nothing ticked, and the selection is cleared when a zip download starts. Only the sort choice is kept for the tab. (An earlier version saved the ticks in `sessionStorage`, which made photos look pre-checked on return; the code now deletes any such leftover.)
- **Selection bar**: always visible at the bottom of a folder (so Select all is easy to find); Download is greyed out until at least one file is ticked. On phones it collapses to two short rows. Tile captions show the full file name, wrapping when long.
- **Zip downloads**: max 500 files and 2 GB. The page first calls `prepare.php` until every derived copy exists, then a plain form POST to `zip.php` streams the archive. Entry names are the NBHS names; identical document names get " (2)".
- **Schema**: versioned with `PRAGMA user_version`; a snapshot of the database is written to `nbhs86-data/backups/` before any migration (last 10 kept).

## Config and storage (never in the repo)
- `NBHS86/config.local.php` holds bcrypt hashes for the passcode and admin password plus the cookie-signing secret. It is gitignored and built by the `deploy-nbhs86` job on every deploy from three GitHub secrets: `NBHS86_PASSCODE`, `NBHS86_ADMIN_PASSWORD`, `NBHS86_SECRET` (32+ random chars, keep it stable or everyone is logged out). If the secrets are unset the step is skipped. Local dev: `php tools/make-config.php --passcode=WORD --data-dir=/some/scratch/dir`. Rotate the passcode by editing the secret and pushing (or re-running the workflow).
- Media, thumbnails, SQLite and partial uploads live in `/usr/home/bobcooley/nbhs86-data/` (outside web root, created on first use). Override with `data_dir` in the config for local testing.
- Passcode is case-insensitive and ignores spaces.

## Look and feel
- Palette (classmate-facing pages: gate, upload, gallery): page background and inputs **#062365**, main blue **#083E92** (cards, tiles, selection bar), gold **#EFDB7C** (buttons, links, selection, focus rings, icons) with #062365 text on gold. Defined once as CSS variables at the top of `assets/css/site.css`; contrast checked (all text pairs WCAG AA or better, lowest 5.4:1). The gold is also written into the file-type icon URLs in `uppy-theme.css`, which cannot use variables: change it there too.
- The admin page uses the same palette as the rest of the site (it briefly had its own neutral one; that override was removed).
- Upload box: 330 px tall (admin slideshow box: 170 px, inside a collapsed section). File tiles use the site's icons (film strip = video, camera = image, document = PDF/other, grid = zip) on navy; the uploaded-check badge, Complete bar and Upload button are gold with navy ticks. Uppy paints file types in fixed colours, so `uppy-theme.css` recognises a type by the colour Uppy writes on the tile.
- Uppy's dashboard is re-coloured by `assets/css/uppy-theme.css` (its dark theme hard-codes blue/green at high specificity, so every override carries `[data-uppy-theme=dark]`). PhotoSwipe's background is set with `body.gallery-page .pswp`.
- Bump the `?v=` on a stylesheet link whenever it changes (Cloudflare and browsers cache them).

## Reliability features
- **Error log**: PHP errors and fatals are written to `nbhs86-data/php-errors.log` (owner-only, rotated at 2 MB, never shown to visitors). The admin page shows the latest entries with paths and IPs masked, and can clear the log. Tests that run PHP with `display_errors` off will show nothing on screen; check that log first.
- **Backups**: the database is snapshotted automatically about every 24 h (no cron on this host: the first request after 24 h takes it, behind a lock), before every schema migration, and on demand from the admin page ("Download a fresh database backup"). Retention: 14 daily, 10 pre-migration, 10 manual. They live beside the uploads, so they do not protect against losing the account; download one now and then. Uploaded media itself is not in the snapshot.
- **Thumbnails at upload time**: `nb_store_file()` builds the thumbnail (photo, HEIC, video poster, PDF page 1) right after storing, so browsing never waits on it. `api/thumb.php` still builds one on first view as a fallback. Thumbnails and derived copies are written to a unique temp name and renamed, so a concurrent request can never serve a half-written file.
- **Case-sensitive URLs**: the server treats `/nbhs86/` differently from `/NBHS86/` (404). Fix is a Cloudflare redirect rule, not code (see below). Do NOT add a lowercase `nbhs86/` folder: the Mac mirror is case-insensitive and Dreamweaver would merge the two.

## Show/Hide password
Both login fields (classmate passcode on `index.php`, admin password on `admin/index.php`) carry `data-reveal`; `assets/js/reveal.js` wraps them and adds a Show/Hide button. Fields always load hidden. Any new password input only needs the `data-reveal` attribute plus the script tag.

## Header art
- `assets/img/nbhs86-header.jpg` (1455x600, navy background identical to the page colour #062365, so it blends in) plus `-727.jpg` and `-485.jpg` copies for phones and retina screens (`srcset`). Source art: `~/Desktop/_Upload/nbhs86-header.jpg` (a .psd exists in the local `nusite/NBHS86/` mirror; never upload it, the site is public).
- `nb_header_image()` in `lib/layout.php` renders it at the top of the upload page (including the "uploads closed" view) and every gallery view. The passcode page does not have it.
- Height is `--header-h` in `assets/css/site.css`: 200px desktop, 100px tablet (<=900px) and phone (<=560px) values are separate so each can be tuned; on phones the page's top padding is also reduced. The picture is 2.425x wider than tall, so 100px tall = 243px wide and 200px tall = 485px wide. **If you change the height, update `sizes` in `nb_header_image()` (width = height x 2.425; it lists the tablet/phone width and the desktop width separately)** so phones/retina still pick the right file. Bump `?v=` there when the picture changes (images are cached a year).

## Deploy gotcha: never delete a folder
FTP-Deploy-Action cannot remove folders on this host: the folder-removal step fails with `550 ... No such file or directory`, which aborts the whole deploy before it saves its state, so every later deploy fails the same way. Deleting single files works. To retire a folder, delete its files and leave a stub `index.php` in it (see `_test/`, `upload/`).

## Behavior notes
- Search engines: `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` from `.htaccess` and PHP, plus a robots meta tag. There is deliberately no `robots.txt` Disallow (it would advertise the path and stop crawlers seeing noindex).
- Accepted types are an extension whitelist (images incl. HEIC, common video, PDF, zip) plus a content sniff. Zip contents get the same checks; entry names are never used as paths.
- Identical files (by content hash) are stored once; the second uploader is pointed at the existing file.
- **File names by kind** (`nb_download_name()` in `lib/bootstrap.php`; one function drives the gallery, downloads, zips and admin):
  - **Photos**: `NBHS_reunions_0001.jpg`, numbered once at upload in arrival order so everyone sees and downloads the same name (HEIC downloads as `.jpg`, `.jpeg` becomes `.jpg`).
  - **Slideshows**: `NBHS_slideshow_0001.mp4`, their own series, assigned only when the admin uploads them through the admin page's "Upload slideshows" box (the tus request carries `slideshow=1`, honoured only for a signed-in admin). They land in Slideshows credited to "Reunion Committee" and take no video number.
  - **Classmates' phone videos**: a video whose uploaded name is a phone default (`NB_PHONE_VIDEO_PATTERNS` in `lib/bootstrap.php`: iPhone `IMG_1234.MOV` / `IMG_E1234`, `RPReplay_Final...`, Pixel `PXL_YYYYMMDD_HHMMSSmmm`, Android `VID_YYYYMMDD_HHMMSS`, Samsung `YYYYMMDD_HHMMSS`, WhatsApp `VID-YYYYMMDD-WA0001`, Telegram `video_YYYY-MM-DD_HH-MM-SS`; a trailing ` (1)` is allowed, the extension is ignored) is renamed `NBHS-friends_0001.mov` (own counter, 4 digits, lowercase extension, `friends_seq` column, schema v4). The creation date stays in the file's metadata (only GPS tags are stripped; verified for iPhone- and Android-style files) and still drives "Creation date" sorting. Add a pattern to that constant to cover another phone convention. Existing rows were not renumbered when the rule was added (they keep their names).
  - **Every other video, and PDFs**: keep their own uploaded name with spaces turned into dashes (`nb_dash_spaces()`: "Class Picnic 1986.mp4" -> "Class-Picnic-1986.mp4"), so a name never shows as `%20`. The original is stored untouched (`orig_name`). Videos also still receive an unused video number (`seq`). Two same-named files get `-2`, `-3` in a zip.
  - Numbers are assigned inside the same write transaction as the insert, so duplicates and rejected files never burn a number, parallel uploads cannot collide, and a deleted file's number is never reused (counters only move forward). Existing rows were numbered by upload time on first run. Moving any file between folders never changes its name. The admin page can reset all numbering only while no files are stored (pre-launch).
- Tests: `php tests/{bootstrap,credits,numbering,folders,derive,slideshow,errorlog,backup,thumbs,names,friends}_test.php` (need GD, exiftool and ffmpeg). `bootstrap_test.php` checks that no core function has been accidentally deleted; run all of them after editing `lib/`.

## Server facts
- Pair.com shared hosting: PHP 8.2.33 (FastCGI, 128M memory, 30 s execution), Apache 2.4. Imagick 7 with HEIC/PDF, ffmpeg, Ghostscript, ZipArchive and SQLite all verified present on 2026-09-20.
- Cloudflare proxies the domain: 100 MB max request body on Free/Pro, so uploads must be chunked.
- Gallery `.htaccess` files turn rewriting on only for `api/` and `gallery/`. Root `.htaccess` has `RewriteEngine Off` to bypass IO200's CMS rewrites, same as the repo root.
