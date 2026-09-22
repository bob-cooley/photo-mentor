// Builds mailto: links at runtime from split data attributes (see lib/layout.php nb_contact_line()), so the
// address never sits in the page source as a scrapable user@domain string.
document.querySelectorAll('.email-link').forEach((el) => {
  const addr = `${el.dataset.user}@${el.dataset.domain}`;
  el.href = `mailto:${addr}`;
  el.textContent = addr;
});
