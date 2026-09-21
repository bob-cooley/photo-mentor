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
- Moderation: none for now. Uploads go straight to a public "Classmate uploads" folder.
- Build: custom PHP + vanilla JS assembled from MIT parts (Uppy, PhotoSwipe, ZipStream-PHP), with a small in-repo tus server instead of tus-php (which pulled ~2,500 vendor files). Not Piwigo.
- Uploader credit: name field plus an "Anonymous" checkbox that bypasses it. Credit is first name only; duplicate first names get last initial appended.

## Layout
```
NBHS86/
  index.php            passcode gate + intake page (same URL, no separate /upload/)
  admin/index.php      Bob-only upload list, delete, finish zips (testing aid until the gallery exists)
  api/tus.php          minimal tus 1.0 server (routed from /api/tus/<id> by api/.htaccess)
  api/process.php      continues unfinished zip extraction (intake page polls it)
  api/thumb.php        gated thumbnail, generated on first request (Imagick, GD fallback, ffmpeg for video)
  api/file.php         gated file delivery with Range support (?dl=1 forces download)
  lib/                 bootstrap (config, SQLite, cookie auth), ingest, thumbs, credits
  assets/              css, js, vendor/uppy (Uppy 6.0.1, MIT, self-hosted)
  gallery/             not built yet (output side)
  tools/, tests/       local only, excluded from deploy
```

## Config and storage (never in the repo)
- `NBHS86/config.local.php` holds bcrypt hashes for the passcode and admin password plus the cookie-signing secret. It is gitignored and built by the `deploy-nbhs86` job on every deploy from three GitHub secrets: `NBHS86_PASSCODE`, `NBHS86_ADMIN_PASSWORD`, `NBHS86_SECRET` (32+ random chars, keep it stable or everyone is logged out). If the secrets are unset the step is skipped. Local dev: `php tools/make-config.php --passcode=WORD --data-dir=/some/scratch/dir`. Rotate the passcode by editing the secret and pushing (or re-running the workflow).
- Media, thumbnails, SQLite and partial uploads live in `/usr/home/bobcooley/nbhs86-data/` (outside web root, created on first use). Override with `data_dir` in the config for local testing.
- Passcode is case-insensitive and ignores spaces.

## Behavior notes
- Search engines: `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet` from `.htaccess` and PHP, plus a robots meta tag. There is deliberately no `robots.txt` Disallow (it would advertise the path and stop crawlers seeing noindex).
- Accepted types are an extension whitelist (images incl. HEIC, common video, PDF, zip) plus a content sniff. Zip contents get the same checks; entry names are never used as paths.
- Identical files (by content hash) are stored once.
- Tests: `php tests/credits_test.php`.

## Server facts
- Pair.com shared hosting: PHP 8.2.33 (FastCGI, 128M memory, 30 s execution), Apache 2.4. Imagick 7 with HEIC/PDF, ffmpeg, Ghostscript, ZipArchive and SQLite all verified present on 2026-09-20.
- Cloudflare proxies the domain: 100 MB max request body on Free/Pro, so uploads must be chunked.
- `.htaccess` has `RewriteEngine Off` to bypass IO200's CMS rewrites, same as the repo root.
