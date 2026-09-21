import { Uppy, Dashboard, Tus } from '../vendor/uppy/uppy.min.js';

// Admin-only upload box: videos sent here go straight to Slideshows (NBHS_slideshow_0001, credited to
// Reunion Committee). The server honours the `slideshow` flag only for a signed-in admin.
const BASE = window.NBHS.base;
const $ = (id) => document.getElementById(id);
const batch = 'slides' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
let uploading = false;

const uppy = new Uppy({
  autoProceed: false,
  restrictions: {
    maxFileSize: 2 * 1024 * 1024 * 1024,
    allowedFileTypes: ['video/*', '.mp4', '.m4v', '.mov', '.webm', '.avi', '.mpg', '.mpeg', '.3gp', '.mkv', '.wmv', '.zip'],
  },
  meta: { slideshow: '1', batch },
})
  .use(Dashboard, {
    inline: true,
    target: '#uppy-slideshow',
    width: '100%',
    height: 170, // compact: this box is used rarely
    theme: 'dark',
    proudlyDisplayPoweredByUppy: false,
    showProgressDetails: true,
    showRemoveButtonAfterComplete: false,
    locale: { strings: { dropPasteFiles: 'Drag slideshow videos here, or %{browseFiles}', browseFiles: 'choose files' } },
  })
  .use(Tus, {
    endpoint: BASE + '/api/tus/',
    chunkSize: 5 * 1024 * 1024,
    retryDelays: [0, 1000, 3000, 5000, 10000, 20000],
    limit: 2,
    removeFingerprintOnSuccess: true,
    allowedMetaFields: ['name', 'type', 'slideshow', 'batch'],
  });

uppy.on('upload', () => { uploading = true; $('slideMsg').textContent = ''; });
window.addEventListener('beforeunload', (e) => { if (uploading) { e.preventDefault(); e.returnValue = ''; } });

async function waitForZips() {
  const t0 = Date.now();
  for (;;) {
    let r;
    try {
      r = await fetch(`${BASE}/api/process.php?batch=${encodeURIComponent(batch)}`, { method: 'POST', credentials: 'same-origin' }).then((x) => x.json());
    } catch (e) { await new Promise((res) => setTimeout(res, 3000)); continue; }
    if (r.pending === 0 || Date.now() - t0 > 20 * 60 * 1000) return r;
    $('slideMsg').textContent = `Unpacking… ${r.added} added so far.`;
    await new Promise((res) => setTimeout(res, 1500));
  }
}

uppy.on('complete', async (result) => {
  uploading = false;
  const ok = result.successful || [];
  const bad = result.failed || [];
  if (!ok.length) return;
  const zips = ok.filter((f) => /\.zip$/i.test(f.name)).length;
  let text = `${ok.length - zips} slideshow${ok.length - zips === 1 ? '' : 's'} uploaded.`;
  if (zips) {
    $('slideMsg').textContent = 'Unpacking your zip…';
    const r = await waitForZips();
    text = `${ok.length - zips ? text + ' ' : ''}${r.added} file${r.added === 1 ? '' : 's'} from the zip added.`;
  }
  if (bad.length) text += ` ${bad.length} failed - use Retry.`;
  $('slideMsg').innerHTML = `${text} <a href="${BASE}/admin/" style="color:var(--accent)">Refresh the list</a>`;
});
