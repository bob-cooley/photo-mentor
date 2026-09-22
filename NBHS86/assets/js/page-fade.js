// Fade transition scoped to the two CTA buttons only (gallery <-> upload). The overlay (#pageFade) starts opaque
// in site.css, so this just clears it in on load and re-covers it for 300ms before following a .cta-btn link out.
// Everything else on the site (nav, folder links, forms, downloads) is untouched.
const fade = document.getElementById('pageFade');
if (fade) {
  const DURATION = 300;
  requestAnimationFrame(() => fade.classList.add('is-clear'));

  document.querySelectorAll('a.cta-btn').forEach((link) => {
    link.addEventListener('click', (e) => {
      if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
      e.preventDefault();
      fade.classList.remove('is-clear');
      window.setTimeout(() => { window.location.assign(link.href); }, DURATION);
    });
  });
}
