import PhotoSwipeLightbox from '../vendor/photoswipe/photoswipe-lightbox.esm.min.js?v=5.4.4';

const { base, folder, icons, maxFiles } = window.NBHS;
const $ = (id) => document.getElementById(id);
const grid = $('grid');
const selbar = $('selbar');
const selmsg = $('selmsg');

const KEY = `nbhs86_sel_${folder}`;
let downloading = false;
const state = { items: [], byId: new Map(), page: 0, more: true, loading: false, kind: 'all', sort: 'arrival', counts: null, selected: new Set() };

// Every visit starts with nothing ticked. (Only the chosen sort order is remembered for the tab.)
try {
  sessionStorage.removeItem(KEY); // an older version saved the ticked files here; discard them
  state.sort = sessionStorage.getItem('nbhs86_sort') || 'arrival';
} catch (e) { /* storage unavailable */ }
$('sort').value = state.sort;

const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmtBytes = (b) => (b >= 1073741824 ? (b / 1073741824).toFixed(1) + ' GB' : b >= 1048576 ? Math.round(b / 1048576) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB');

// A single photo or video (from the lightbox's own download button, or "Download selected" with exactly one file
// ticked): try the OS share sheet ("Save to Photos"/"Save Video") instead of a plain download, so one tap lands it
// in the phone's own Photos app instead of the Files/Downloads app. Only attempted when the browser supports
// sharing files at all, and only under SHARE_MAX_BYTES: fetching a large video into memory first is slow and risks
// Safari deciding the tap's "user activation" expired before navigator.share() runs. Any failure (unsupported,
// cancelled, fetch error, oversized) falls back to the plain download link, exactly as before this feature existed.
const SHARE_MAX_BYTES = 25 * 1024 * 1024;

async function tryShareFile(url, name) {
  if (!navigator.canShare || !navigator.share) return false;
  try {
    const res = await fetch(url, { credentials: 'same-origin' });
    if (!res.ok) return false;
    const blob = await res.blob();
    const file = new File([blob], name, { type: blob.type || 'application/octet-stream' });
    if (!navigator.canShare({ files: [file] })) return false;
    await navigator.share({ files: [file] });
    return true;
  } catch (e) {
    return false; // unsupported, the user cancelled, or the share failed for any reason: caller falls back
  }
}

// ---------- listing ----------

function renderChips() {
  const c = state.counts;
  const defs = [['all', 'All', c.all], ['image', 'Photos', c.image], ['video', 'Videos', c.video], ['pdf', 'Documents', c.pdf]];
  $('chips').innerHTML = defs
    .filter(([k, , n]) => k === 'all' || n > 0)
    .map(([k, label, n]) => `<button type="button" class="chip" data-kind="${k}" aria-pressed="${state.kind === k}">${label} (${n})</button>`)
    .join('');
  // With a single kind present, the filter row adds nothing.
  $('chips').hidden = defs.filter(([k, , n]) => k !== 'all' && n > 0).length < 2;
}

function tileHtml(it) {
  const badge = it.kind === 'video' ? icons.play : it.kind === 'pdf' ? icons.pdf : '';
  return `<div class="tile${state.selected.has(it.id) ? ' sel' : ''}" data-id="${it.id}">
    <button type="button" class="pick" aria-label="Select ${esc(it.name)}" aria-pressed="${state.selected.has(it.id)}">${icons.check}</button>
    <a class="thumb" href="${it.src}" data-id="${it.id}"><img loading="lazy" decoding="async" src="${it.thumb}" alt="${esc(it.name)}"></a>
    ${badge ? `<span class="badge">${badge}</span>` : ''}
    <div class="cap"><b title="${esc(it.name)}">${esc(it.name)}</b><span>${esc(it.credit)}</span></div>
  </div>`;
}

async function loadPage() {
  if (state.loading || !state.more) return;
  state.loading = true;
  const url = `${base}/api/list.php?f=${encodeURIComponent(folder)}&sort=${state.sort}&kind=${state.kind}&page=${state.page + 1}`;
  try {
    const r = await fetch(url, { credentials: 'same-origin' });
    if (r.status === 401) { location.href = base + '/'; return; }
    const d = await r.json();
    if (d.error) { selmsg.textContent = d.message || 'Could not load this folder.'; selmsg.className = 'selmsg err'; return; }
    state.page = d.page;
    state.more = d.hasMore;
    if (!state.counts || d.page === 1) { state.counts = d.counts; renderChips(); updateBar(); }
    d.items.forEach((it) => { state.items.push(it); state.byId.set(it.id, it); });
    grid.insertAdjacentHTML('beforeend', d.items.map(tileHtml).join(''));
    $('empty').hidden = state.items.length > 0;
  } catch (e) {
    selmsg.textContent = 'Connection problem while loading. Scroll again to retry.';
    selmsg.className = 'selmsg err';
  } finally {
    state.loading = false;
  }
}

function reset() {
  state.items = []; state.byId = new Map(); state.page = 0; state.more = true;
  grid.innerHTML = '';
  loadPage();
}

new IntersectionObserver((entries) => { if (entries.some((e) => e.isIntersecting)) loadPage(); }, { rootMargin: '900px' }).observe($('sentinel'));

$('chips').addEventListener('click', (e) => {
  const b = e.target.closest('.chip');
  if (!b) return;
  state.kind = b.dataset.kind;
  renderChips();
  reset();
});
$('sort').addEventListener('change', (e) => {
  state.sort = e.target.value;
  try { sessionStorage.setItem('nbhs86_sort', state.sort); } catch (err) { /* ignore */ }
  reset();
});

// ---------- selection ----------

function updateBar() {
  // The bar is always visible so "Select all" is easy to find; Download stays greyed out until something is ticked.
  const n = state.selected.size;
  $('selcount').textContent = n === 0 ? 'Nothing selected yet' : `${n} selected`;
  $('selDownload').disabled = n === 0 || downloading;
  $('selDownload').title = n === 0 ? 'Tick one or more files first, or use Select all' : '';
  $('selClear').disabled = n === 0;
  $('selAll').disabled = !!state.counts && state.counts.all === 0;
  if (n > maxFiles) { setMsg(`A zip can hold up to ${maxFiles} files. Deselect some, or download in groups.`, true); }
  // The unzip instructions only apply once a zip is actually going to happen (2+ files); one file shares/downloads directly.
  const zipHint = $('selZipHint');
  if (zipHint) zipHint.hidden = n < 2;
}
function clearSelection() {
  state.selected.clear();
  grid.querySelectorAll('.tile.sel').forEach((t) => { t.classList.remove('sel'); t.querySelector('.pick').setAttribute('aria-pressed', 'false'); });
  updateBar();
}
function setMsg(t, err = false) { selmsg.textContent = t; selmsg.className = 'selmsg' + (err ? ' err' : ''); }

function toggle(id, on) {
  on = on ?? !state.selected.has(id);
  on ? state.selected.add(id) : state.selected.delete(id);
  const tile = grid.querySelector(`.tile[data-id="${id}"]`);
  if (tile) {
    tile.classList.toggle('sel', on);
    tile.querySelector('.pick').setAttribute('aria-pressed', String(on));
  }
  updateBar();
}

grid.addEventListener('click', (e) => {
  const pick = e.target.closest('.pick');
  if (pick) { toggle(pick.closest('.tile').dataset.id); return; }
  const thumb = e.target.closest('.thumb');
  if (!thumb) return;
  e.preventDefault();
  const it = state.byId.get(thumb.dataset.id);
  if (!it) return;
  if (it.kind === 'pdf') { window.open(it.src, '_blank', 'noopener'); return; }
  openLightbox(it.id);
});

$('selClear').addEventListener('click', () => { clearSelection(); setMsg(''); });

$('selAll').addEventListener('click', async () => {
  setMsg('Selecting…');
  try {
    const r = await fetch(`${base}/api/list.php?f=${encodeURIComponent(folder)}&sort=${state.sort}&kind=${state.kind}&ids=1`, { credentials: 'same-origin' }).then((x) => x.json());
    (r.ids || []).forEach((id) => state.selected.add(id));
    grid.querySelectorAll('.tile').forEach((t) => { const on = state.selected.has(t.dataset.id); t.classList.toggle('sel', on); t.querySelector('.pick').setAttribute('aria-pressed', String(on)); });
    updateBar();
    setMsg(state.selected.size > maxFiles ? '' : '');
  } catch (e) { setMsg('Could not select everything. Try again.', true); }
});

// ---------- download: one file shares/downloads directly, 2+ prepare in batches then stream as a zip ----------

$('selDownload').addEventListener('click', async () => {
  if (downloading) return;
  const ids = [...state.selected];
  if (!ids.length) return;
  if (ids.length > maxFiles) { setMsg(`A zip can hold up to ${maxFiles} files. You selected ${ids.length}.`, true); return; }
  downloading = true;
  updateBar();
  try {
    if (ids.length === 1) {
      const it = state.byId.get(ids[0]);
      setMsg('');
      const canTry = !!(navigator.canShare && navigator.share) && (!it.size || it.size <= SHARE_MAX_BYTES);
      const shared = canTry && await tryShareFile(it.dl, it.name);
      if (!shared) window.location.href = it.dl;
      clearSelection();
      if (!shared) setMsg(`${it.name} is downloading.`);
      return;
    }
    let r;
    for (;;) {
      r = await fetch(`${base}/api/prepare.php`, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ ids: ids.join(',') }) }).then((x) => x.json());
      if (r.error) { setMsg(r.message || 'Could not prepare the download.', true); return; }
      setMsg(`Preparing your files… ${r.ready} of ${r.total}`);
      if (r.pending === 0) break;
    }
    if (r.failed) { setMsg(`${r.failed} file${r.failed === 1 ? '' : 's'} could not be prepared. Deselect ${r.failed === 1 ? 'it' : 'them'} or try again.`, true); return; }
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = `${base}/api/zip.php`;
    form.style.display = 'none';
    const input = document.createElement('input');
    input.type = 'hidden'; input.name = 'ids'; input.value = ids.join(',');
    form.appendChild(input);
    document.body.appendChild(form);
    form.submit();
    form.remove();
    const bytes = state.items.filter((i) => state.selected.has(i.id)).reduce((a, i) => a + i.size, 0);
    clearSelection(); // the download has started: leave nothing ticked for the next one
    setMsg(`Your download of ${ids.length} file${ids.length === 1 ? '' : 's'} (${fmtBytes(bytes)}+) is starting. Large zips can take a minute to begin.`);
  } catch (e) {
    setMsg('Connection problem. Please try again.', true);
  } finally {
    downloading = false;
    updateBar();
  }
});

// ---------- lightbox (photos and videos) ----------

const lightbox = new PhotoSwipeLightbox({
  pswpModule: () => import('../vendor/photoswipe/photoswipe.esm.min.js?v=5.4.4'),
  bgOpacity: 0.95,
  showHideAnimationType: 'fade',
  preload: [1, 2],
});

lightbox.on('contentLoad', (e) => {
  const { content } = e;
  if (content.data.type !== 'video') return;
  e.preventDefault();
  const wrap = document.createElement('div');
  wrap.className = 'pswp-video';
  const v = document.createElement('video');
  v.controls = true;
  v.playsInline = true;
  v.preload = 'metadata';
  v.poster = content.data.msrc || '';
  v.src = content.data.src;
  wrap.appendChild(v);
  content.element = wrap;
  content.onLoaded();
});
lightbox.on('contentDeactivate', ({ content }) => {
  const v = content.element && content.element.querySelector && content.element.querySelector('video');
  if (v) v.pause();
});
lightbox.on('contentDestroy', ({ content }) => {
  const v = content.element && content.element.querySelector && content.element.querySelector('video');
  if (v) { v.pause(); v.removeAttribute('src'); v.load(); }
});

lightbox.on('uiRegister', () => {
  lightbox.pswp.ui.registerElement({
    name: 'download-button', order: 8, isButton: true, tagName: 'a', html: icons.download,
    onInit: (el, pswp) => {
      el.setAttribute('title', 'Download');
      el.setAttribute('aria-label', 'Download');
      el.insertAdjacentHTML('beforeend', '<span class="pswp-dl-label">Download</span>');
      pswp.on('change', () => { el.href = pswp.currSlide.data.dl; });
      el.addEventListener('click', (e) => {
        const d = pswp.currSlide.data;
        const canTry = !!(navigator.canShare && navigator.share) && (!d.size || d.size <= SHARE_MAX_BYTES);
        if (!canTry) return; // plain <a href> download, exactly as before
        e.preventDefault();
        el.classList.add('pswp-dl-busy');
        tryShareFile(d.dl, d.name)
          .then((shared) => { if (!shared) window.location.href = d.dl; })
          .finally(() => el.classList.remove('pswp-dl-busy'));
      });
    },
  });
  lightbox.pswp.ui.registerElement({
    name: 'nb-caption', order: 9, isButton: false, appendTo: 'root', html: '',
    onInit: (el, pswp) => {
      el.classList.add('pswp__custom-caption');
      pswp.on('change', () => {
        const d = pswp.currSlide.data;
        el.innerHTML = `<b>${esc(d.name)}</b> &middot; <span>${esc(d.credit)}</span>`;
      });
    },
  });
});

function openLightbox(id) {
  const media = state.items.filter((i) => i.kind !== 'pdf');
  lightbox.options.dataSource = media.map((i) => ({
    type: i.kind === 'video' ? 'video' : 'image',
    src: i.src, dl: i.dl, name: i.name, credit: i.credit, msrc: i.thumb, size: i.size,
    width: i.w || (i.kind === 'video' ? 1280 : 1600),
    height: i.h || (i.kind === 'video' ? 720 : 1200),
    alt: i.name,
  }));
  lightbox.loadAndOpen(Math.max(0, media.findIndex((i) => i.id === id)));
}
lightbox.init();

window.addEventListener('pageshow', (e) => { if (e.persisted) clearSelection(); });

updateBar();
loadPage();
