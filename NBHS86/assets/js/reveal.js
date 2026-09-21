// Adds a Show/Hide button to every password field marked data-reveal.
// The field always loads hidden; there is no memory between visits.
document.querySelectorAll('input[data-reveal]').forEach((input) => {
  const wrap = document.createElement('div');
  wrap.className = 'pw';
  input.parentNode.insertBefore(wrap, input);
  wrap.appendChild(input);
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'pw-toggle';
  btn.textContent = 'Show';
  btn.setAttribute('aria-controls', input.id);
  btn.setAttribute('aria-pressed', 'false');
  btn.addEventListener('click', () => {
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Hide' : 'Show';
    btn.setAttribute('aria-pressed', String(show));
    input.focus();
  });
  wrap.appendChild(btn);
});
