const texts = JSON.parse(document.getElementById('texts').textContent);
const fill = (text, params = {}) => text.replace(/\{(\w+)\}/g, (all, key) => params[key] ?? all);

// ---------- Navigation: inline when it fits, menu otherwise ----------
const topbar = document.querySelector('.topbar');
if (topbar) {
  const row = topbar.querySelector('.topbar-row');
  const menu = document.getElementById('menu');
  let lastWidth = 0;
  const measure = () => {
    const width = row.clientWidth;
    const inline = topbar.classList.contains('is-wide');
    // Once collapsed, measure again only when the row grows
    if (!inline && width <= lastWidth) { lastWidth = width; return; }
    lastWidth = width;
    const wasOpen = !inline && menu.matches(':popover-open');
    topbar.classList.add('is-measuring', 'is-wide');
    menu.removeAttribute('popover');
    const fits = row.scrollWidth <= row.clientWidth;
    topbar.classList.toggle('is-wide', fits);
    if (!fits) {
      menu.setAttribute('popover', 'auto');
      if (wasOpen) menu.showPopover();
    }
    requestAnimationFrame(() => topbar.classList.remove('is-measuring'));
  };
  const observer = new ResizeObserver(measure);
  observer.observe(topbar);
  observer.observe(menu);
  measure();
}

// ---------- Delete confirmation; without JS the form is submitted directly ----------
const dialog = document.getElementById('confirm');
let pending = null;
document.addEventListener('submit', (e) => {
  const form = e.target;
  if (!(form instanceof HTMLFormElement) || form.method === 'dialog') return;
  if (form.dataset.confirm && pending !== form) {
    e.preventDefault();
    pending = form;
    dialog.querySelector('h1').textContent = form.dataset.confirm;
    dialog.showModal();
    return;
  }
  for (const button of form.querySelectorAll('button:not([type=button])')) button.disabled = true; // no double submit
});
dialog.addEventListener('close', () => {
  const form = pending;
  const yes = dialog.returnValue === 'yes';
  dialog.returnValue = '';
  if (yes && form) form.requestSubmit();
  pending = null;
});
if (!('closedBy' in HTMLDialogElement.prototype)) {
  const outside = (e) => {
    const r = dialog.getBoundingClientRect();
    return e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom;
  };
  let pressed = false;
  dialog.addEventListener('pointerdown', (e) => { pressed = outside(e); });
  dialog.addEventListener('pointerup', (e) => { if (pressed && outside(e)) dialog.close(); });
}
addEventListener('pageshow', () => document.querySelectorAll('button[disabled]').forEach((b) => { b.disabled = false; }));

// ---------- Error messages next to each field ----------
const announcer = document.createElement('div');
announcer.setAttribute('aria-live', 'polite');
announcer.className = 'visually-hidden';
document.body.append(announcer);
let timer;
let announcedField;

const message = (el) => {
  const v = el.validity;
  if (v.valueMissing) return el.tagName === 'SELECT' || el.type === 'radio' ? texts.choose : texts.required;
  if (v.patternMismatch) {
    if (el.inputMode === 'decimal') return fill(texts.amount, { example: el.dataset.example ?? '' });
    return el.type === 'month' ? texts.month : texts.invalid;
  }
  if (v.tooShort) return fill(texts.too_short, { min: el.minLength });
  if (v.rangeUnderflow || v.rangeOverflow) return fill(texts.range, { min: el.min, max: el.max });
  if (v.badInput) return el.type === 'date' ? texts.date : texts.invalid;
  return el.validationMessage;
};
const link = (el, visible) => {
  const id = el.dataset.error;
  if (!id) return;
  const ids = (el.getAttribute('aria-describedby') || '').split(/\s+/).filter((x) => x && x !== id);
  if (visible) ids.push(id);
  if (ids.length) el.setAttribute('aria-describedby', ids.join(' ')); else el.removeAttribute('aria-describedby');
};
const flagged = new WeakSet();
const announced = new WeakSet();
const sync = (el, announce = false) => {
  if (!el.matches?.('.field input, .field select')) return;
  if (el.matches(':user-invalid')) {
    const box = document.getElementById(el.dataset.error);
    if (box) box.lastChild.textContent = ` ${message(el)}`;
    if (announce && !announced.has(el)) {
      clearTimeout(timer);
      announcer.textContent = '';
      announcedField = el;
      const label = (el.closest('fieldset')?.querySelector('legend') ?? el.labels?.[0])?.textContent.trim() ?? '';
      timer = setTimeout(() => { announcer.textContent = `${label}: ${texts.error}: ${message(el)}`; }, 500);
      announced.add(el);
    }
    el.setAttribute('aria-invalid', 'true');
    link(el, true);
    flagged.add(el);
  } else if (flagged.has(el) || el.getAttribute('aria-invalid') !== 'true') {
    el.setAttribute('aria-invalid', 'false');
    link(el, false);
    flagged.delete(el);
    announced.delete(el);
    if (announcedField === el) { clearTimeout(timer); announcer.textContent = ''; }
  }
};
let counter = 0;
for (const el of document.querySelectorAll('.field input:not([type=hidden], [type=checkbox]), .field select')) {
  const group = el.type === 'radio' ? el.closest('fieldset') : null;
  let box = group?.querySelector('.error-message');
  if (!box) {
    box = document.createElement('div');
    box.className = 'error-message';
    box.id = `error-${++counter}`;
    const icon = document.createElement('span');
    icon.setAttribute('role', 'img');
    icon.setAttribute('aria-label', texts.error);
    icon.textContent = '▲';
    box.append(icon, document.createTextNode(''));
    if (group) group.append(box); else el.after(box);
  }
  el.dataset.error = box.id;
  sync(el);
}
document.addEventListener('blur', (e) => sync(e.target, true), true);
document.addEventListener('input', (e) => { if (e.target.hasAttribute?.('aria-invalid')) sync(e.target); });
document.addEventListener('change', (e) => sync(e.target, true), true);
let first = null;
document.addEventListener('invalid', (e) => {
  if (!e.target.dataset.error) return;
  e.preventDefault(); // the message shows next to the field, without the native bubble
  announced.add(e.target);
  if (!first) {
    first = e.target;
    setTimeout(() => { first.focus(); first = null; });
  }
  sync(e.target);
}, true);

// ---------- Show password ----------
for (const el of document.querySelectorAll('input[type=password]')) {
  const label = el.labels?.[0];
  if (!label) continue;
  const button = document.createElement('button');
  button.type = 'button';
  button.className = 'plain';
  button.textContent = texts.show;
  button.setAttribute('aria-pressed', 'false');
  button.setAttribute('aria-label', `${texts.show}: ${label.textContent.trim()}`);
  button.addEventListener('click', () => {
    const show = el.type === 'password';
    el.type = show ? 'text' : 'password';
    button.setAttribute('aria-pressed', String(show));
  });
  const row = document.createElement('div');
  row.className = 'label-row';
  label.before(row);
  row.append(label, button);
}

// ---------- Estimates: the amount is only editable in fixed mode ----------
for (const select of document.querySelectorAll('select[data-mode]')) {
  select.addEventListener('change', () => { select.closest('tr').querySelector('input').readOnly = select.value !== 'fixed'; });
}

// ---------- Chart tooltip (hover and keyboard) ----------
const tooltip = document.createElement('div');
tooltip.className = 'tooltip';
tooltip.hidden = true;
const strong = document.createElement('strong');
const caption = document.createElement('span');
tooltip.append(strong, caption);
document.body.append(tooltip);
const show = (mark) => {
  strong.textContent = mark.dataset.value;
  caption.textContent = `${mark.dataset.series} · ${mark.dataset.label}`;
  tooltip.hidden = false;
  const r = mark.querySelector('path').getBoundingClientRect();
  const t = tooltip.getBoundingClientRect();
  tooltip.style.left = `${Math.max(8, Math.min(innerWidth - t.width - 8, r.left + r.width / 2 - t.width / 2))}px`;
  tooltip.style.top = `${Math.max(8, r.top - t.height - 8)}px`;
};
for (const mark of document.querySelectorAll('.mark')) {
  mark.addEventListener('pointerenter', () => show(mark));
  mark.addEventListener('focus', () => show(mark));
  mark.addEventListener('pointerleave', () => { tooltip.hidden = true; });
  mark.addEventListener('blur', () => { tooltip.hidden = true; });
}
