import { Uppy, Dashboard, Tus } from '../vendor/uppy/uppy.min.js';

const BASE = window.NBHS.base;
const MAX_BYTES = 2 * 1024 * 1024 * 1024;
const $ = (id) => document.getElementById(id);

const nameInput = $('uploader');
const anon = $('anon');
const nameErr = $('nameErr');
const thanks = $('thanks');

const batchId = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
let batch = batchId();
let uploading = false;

// Remember the name on this device (best effort; storage can be unavailable).
try {
  nameInput.value = localStorage.getItem('nbhs86_name') || '';
  anon.checked = localStorage.getItem('nbhs86_anon') === '1';
} catch (e) { /* ignore */ }

function syncAnon() {
  nameInput.disabled = anon.checked;
  if (anon.checked) nameErr.textContent = '';
}
anon.addEventListener('change', syncAnon);
syncAnon();

const uppy = new Uppy({
  autoProceed: false,
  restrictions: {
    maxFileSize: MAX_BYTES,
    allowedFileTypes: [
      'image/*', 'video/*',
      '.jpg', '.jpeg', '.png', '.gif', '.webp', '.heic', '.heif', '.tif', '.tiff', '.avif',
      '.mp4', '.m4v', '.mov', '.webm', '.avi', '.mpg', '.mpeg', '.3gp', '.mkv', '.wmv',
      '.pdf', '.zip',
    ],
  },
  onBeforeUpload(files) {
    const name = nameInput.value.trim();
    if (!anon.checked && name === '') {
      nameErr.textContent = 'Please enter your name, or tick "Submit anonymously".';
      nameInput.focus();
      nameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return false;
    }
    nameErr.textContent = '';
    try {
      localStorage.setItem('nbhs86_name', name);
      localStorage.setItem('nbhs86_anon', anon.checked ? '1' : '0');
    } catch (e) { /* ignore */ }
    uppy.setMeta({
      uploader: anon.checked ? '' : name,
      anonymous: anon.checked ? '1' : '0',
      batch,
    });
    return true;
  },
})
  .use(Dashboard, {
    inline: true,
    target: '#uppy',
    width: '100%',
    height: 330, // 75% of the previous 440
    theme: 'dark',
    proudlyDisplayPoweredByUppy: false,
    showProgressDetails: true,
    showRemoveButtonAfterComplete: false,
    locale: {
      strings: {
        dropPasteFiles: 'Drag files here, or %{browseFiles}',
        browseFiles: 'tap or click to choose',
      },
    },
  })
  .use(Tus, {
    endpoint: BASE + '/api/tus/',
    chunkSize: 5 * 1024 * 1024,
    retryDelays: [0, 1000, 3000, 5000, 10000, 20000],
    limit: 3,
    removeFingerprintOnSuccess: true,
    allowedMetaFields: ['name', 'type', 'uploader', 'anonymous', 'batch'], // Uppy's own file name/type keys
  });

uppy.on('upload', () => {
  uploading = true;
  thanks.classList.remove('show');
});

window.addEventListener('beforeunload', (e) => {
  if (uploading) {
    e.preventDefault();
    e.returnValue = '';
  }
});

async function waitForZips(zipCount) {
  const body = $('thanksBody');
  const t0 = Date.now();
  for (;;) {
    let r;
    try {
      r = await fetch(`${BASE}/api/process.php?batch=${encodeURIComponent(batch)}`, { method: 'POST', credentials: 'same-origin' }).then((x) => x.json());
    } catch (e) {
      await new Promise((res) => setTimeout(res, 3000));
      continue;
    }
    if (r.pending === 0 || Date.now() - t0 > 20 * 60 * 1000) return r;
    body.textContent = `Unpacking your .zip file${zipCount > 1 ? 's' : ''}… ${r.added} files added so far. You can leave this page open.`;
    await new Promise((res) => setTimeout(res, 1500));
  }
}

uppy.on('complete', async (result) => {
  uploading = false;
  const ok = result.successful || [];
  const bad = result.failed || [];
  if (ok.length === 0) return; // Uppy's own error UI + Retry covers this

  const zips = ok.filter((f) => /\.zip$/i.test(f.name)).length;
  const who = anon.checked || !nameInput.value.trim() ? '' : `, ${nameInput.value.trim().split(/\s+/)[0]}`;
  $('thanksTitle').textContent = bad.length ? 'Some files were uploaded' : `Thank you${who}!`;
  const plain = ok.length - zips;
  const parts = [];
  if (plain) parts.push(`${plain} file${plain === 1 ? '' : 's'}`);
  if (zips) parts.push(`${zips} zip archive${zips === 1 ? '' : 's'}`);
  $('thanksBody').textContent = `${parts.join(' and ')} uploaded.` + (bad.length ? ` ${bad.length} did not go through — use Retry below.` : '');
  thanks.classList.add('show');
  thanks.scrollIntoView({ behavior: 'smooth', block: 'center' });

  if (zips) {
    const r = await waitForZips(zips);
    const failed = r.failed ? ` ${r.failed} archive${r.failed === 1 ? '' : 's'} could not be opened.` : '';
    const skipped = r.skipped ? ` ${r.skipped} unsupported or duplicate file${r.skipped === 1 ? ' was' : 's were'} skipped.` : '';
    $('thanksBody').textContent = `${plain ? plain + ' file' + (plain === 1 ? '' : 's') + ' and ' : ''}${r.added} file${r.added === 1 ? '' : 's'} from your zip uploaded.${skipped}${failed}`;
  }
});

$('moreBtn').addEventListener('click', () => {
  uppy.cancelAll();
  batch = batchId();
  thanks.classList.remove('show');
  $('filesCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
});
