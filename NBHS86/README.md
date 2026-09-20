# NBHS86 - Class of '86 photo intake + gallery

Live at https://www.bobcooleyphoto.com/NBHS86/ (path is case-sensitive).
Deployed by the `deploy-nbhs86` job in `.github/workflows/deploy.yml` to `public_html/bobcooleyphoto/NBHS86/`.
This README, `.gitkeep` files and other `*.md` are excluded from deploy.

## Purpose
1. **Intake** (`upload/`): classmates drag-and-drop or pick photos, videos, PDFs, or .zip archives. Works on mobile and desktop.
2. **Gallery** (`gallery/`): folders/categories, grid + lightbox, per-file download, checkbox select + "Download selected (.zip)".

The two sides launch separately. Intake goes live first for testing.

## Decisions (2026-09-20)
- Access: shared class passcode, stored hashed server-side. **The repo is public. Never commit the passcode, admin password, or any config with secrets.**
- Moderation: none for now. Uploads go straight to a public "Classmate uploads" folder.
- Build: custom PHP 8.3 + vanilla JS assembled from MIT parts (Uppy + tus-php, PhotoSwipe, ZipStream-PHP). Not Piwigo.
- Uploader credit: name field plus an "Anonymous" checkbox that bypasses it. Credit is first name only; duplicate first names get last initial appended.

## Layout (planned)
```
NBHS86/
  index.php       landing / passcode gate
  upload/         intake page
  gallery/        output side
  admin/          folder + media management (Bob only)
  api/            upload, list, zip-download endpoints
  lib/            shared PHP
  assets/css|js   front-end
```
Media and config live **outside** this deploy tree (server-side only) so the FTP deploy can never overwrite or delete them.

## Server facts
- Pair.com shared hosting, PHP 8.3, MySQL 8.0, Apache 2.4 (per Pair KB, Aug 2024 - re-verify via `_diag.php` output).
- Cloudflare proxies the domain: 100 MB max request body on Free/Pro, so uploads must be chunked.
- `.htaccess` has `RewriteEngine Off` to bypass IO200's CMS rewrites, same as the repo root.
