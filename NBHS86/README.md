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
- **Sorting**: default is arrival order; shot date (oldest/newest first) is an option. Shot date, width and height are read with exiftool at ingest (and lazily for older files); files with no date sort last.
- **Privacy**: every view and download is served from a GPS-free copy (`derived/<id>-clean.<ext>`, created with exiftool on first use). Originals are only served to the admin (`?raw=1`). If exiftool is missing the admin page warns.
- **HEIC**: lightbox shows a 2400px JPEG; download is a full-size JPEG named `.jpg`. TIFF gets a 2400px JPEG for the lightbox and downloads as TIFF.
- **Zip downloads**: max 500 files and 2 GB. The page first calls `prepare.php` until every derived copy exists, then a plain form POST to `zip.php` streams the archive. Entry names are the NBHS names; identical document names get " (2)".
- **Schema**: versioned with `PRAGMA user_version`; a snapshot of the database is written to `nbhs86-data/backups/` before any migration (last 10 kept).

## Config and storage (never in the repo)
- `NBHS86/config.local.php` holds bcrypt hashes for the passcode and admin password plus the cookie-signing secret. It is gitignored and built by the `deploy-nbhs86` job on every deploy from three GitHub secrets: `NBHS86_PASSCODE`, `NBHS86_ADMIN_PASSWORD`, `NBHS86_SECRET` (32+ random chars, keep it stable or everyone is logged out). If the secrets are unset the step is skipped. Local dev: `php tools/make-config.php --passcode=WORD --data-dir=/some/scratch/dir`. Rotate the passcode by editing the secret and pushing (or re-running the workflow).
- Media, thumbnails, SQLite and partial uploads live in `/usr/home/bobcooley/nbhs86-data/` (outside web root, created on first use). Override with `data_dir` in the config for local testing.
- Passcode is case-insensitive and ignores spaces.

## Look and feel
- Palette (classmate-facing pages: gate, upload, gallery): primary **#102F73** (page background; panels are darker tints of it) and accent **#E5B24B** (buttons, links, selection, focus rings), with dark navy text-on-gold (#102F73). Defined once as CSS variables at the top of `assets/css/site.css`; contrast checked (all text pairs WCAG AA or better).
- The admin page opts out with `<html class="admin">` and keeps the original neutral dark palette.
- Uppy's dashboard is re-coloured by `assets/css/uppy-theme.css` (its dark theme hard-codes blue/green at high specificity, so every override carries `[data-uppy-theme=dark]`). PhotoSwipe's background is set with `body.gallery-page .pswp`.
- Bump the `?v=` on a stylesheet link whenever it changes (Cloudflare and browsers cache them).

## Deploy gotcha: never delete a folder
FTP-Deploy-Action cannot remove folders on this host: the folder-removal step fails with `550 ... No such file or directory`, which aborts the whole deploy before it saves its state, so every later deploy fails the same way. Deleting single files works. To retire a folder, delete its files and leave a stub `index.php` in it (see `_test/`, `upload/`).

## Behavior notes
- Search engines: `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` from `.htaccess` and PHP, plus a robots meta tag. There is deliberately no `robots.txt` Disallow (it would advertise the path and stop crawlers seeing noindex).
- Accepted types are an extension whitelist (images incl. HEIC, common video, PDF, zip) plus a content sniff. Zip contents get the same checks; entry names are never used as paths.
- Identical files (by content hash) are stored once; the second uploader is pointed at the existing file.
- **Permanent reunion numbering**: every photo and video gets `NBHS_reunions_0001.<ext>` assigned once, at ingest, in arrival order, so everyone sees and downloads the same name. One counter for all image types, a separate counter for videos, PDFs keep their own names. Extension is the stored type, `jpeg` becomes `jpg`, and HEIC will download as `.jpg` once conversion exists (`nb_download_name($row, $converted)`). Numbers are assigned inside the same write transaction as the insert, so duplicates and rejected files never burn a number, parallel uploads can't collide, and a deleted file's number is never reused (counters only move forward). Existing rows are numbered by upload time on first run. **Slideshows have their own series**, `NBHS_slideshow_0001.<ext>`, with a separate counter. They are numbered only when the admin uploads them through the admin page's "Upload slideshows" box (the tus request carries `slideshow=1`, which `api/tus.php` honours only for a signed-in admin). They land in the Slideshows folder credited to "Reunion Committee", take no video number (so Videos never gets a gap), and only videos are treated this way (a zip of slideshows works; photos/PDFs inside it follow the normal rules). Moving any file between folders never changes its name. The admin page can reset all numbering only while no files are stored (pre-launch).
- Tests: `php tests/{bootstrap,credits,numbering,folders,derive,slideshow}_test.php` (need GD, exiftool and ffmpeg). `bootstrap_test.php` checks that no core function has been accidentally deleted; run all of them after editing `lib/`.

## Server facts
- Pair.com shared hosting: PHP 8.2.33 (FastCGI, 128M memory, 30 s execution), Apache 2.4. Imagick 7 with HEIC/PDF, ffmpeg, Ghostscript, ZipArchive and SQLite all verified present on 2026-09-20.
- Cloudflare proxies the domain: 100 MB max request body on Free/Pro, so uploads must be chunked.
- Gallery `.htaccess` files turn rewriting on only for `api/` and `gallery/`. Root `.htaccess` has `RewriteEngine Off` to bypass IO200's CMS rewrites, same as the repo root.
