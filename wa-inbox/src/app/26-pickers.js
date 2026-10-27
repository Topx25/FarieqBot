/* Dates and times: one calendar / clock for the whole product (flatpickr), shown as day/month/year and 24 hours, in the interface language.
   The real field keeps its usual value (2026-10-27, 2026-10-27T14:30, 14:30), so every form and save reads it as before.
   An hour list (09:00, 10:00 … as a <select>) gets a clock too; the list stays underneath and keeps the value. */
(function () {
  var V = 'https://cdnjs.cloudflare.com/ajax/libs/flatpickr/4.6.13/', ready = null;
  var ar = (document.documentElement.lang || '').indexOf('ar') === 0;
  function load() { if (ready) return ready;
    ready = new Promise(function (ok) { var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = V + 'flatpickr.min.css'; document.head.appendChild(l);
      var s = document.createElement('script'); s.src = V + 'flatpickr.min.js'; s.onload = function () { if (!ar) return ok(); var a = document.createElement('script'); a.src = V + 'l10n/ar.min.js'; a.onload = ok; a.onerror = ok; document.head.appendChild(a); }; s.onerror = function () { ok(); }; document.head.appendChild(s); });
    return ready; }
  var KIND = { 'date': ['Y-m-d', 'd/m/Y', false, true], 'datetime-local': ['Y-m-d\\TH:i', 'd/m/Y H:i', true, true], 'time': ['H:i', 'H:i', true, false] };
  function opts(k) { var o = { dateFormat: k[0], altInput: true, altFormat: k[1], enableTime: k[2], noCalendar: !k[3], time_24hr: true, disableMobile: true, allowInput: true };
    if (ar && window.flatpickr && flatpickr.l10ns && flatpickr.l10ns.ar) o.locale = 'ar'; return o; }
  function hourList(sel) { var t = [].map.call(sel.options, function (o) { return o.text.trim(); }); return t.length >= 12 && t.every(function (x) { return /^\d{1,2}:00$/.test(x); }); }
  function wire(root) {
    [].forEach.call((root || document).querySelectorAll('input[type=date], input[type=datetime-local], input[type=time]'), function (i) { if (i._flatpickr || i.dataset.fp) return; i.dataset.fp = 1; var k = KIND[i.type];
      load().then(function () { if (window.flatpickr && !i._flatpickr) flatpickr(i, opts(k)); }); });
    [].forEach.call((root || document).querySelectorAll('select'), function (sel) { if (sel.dataset.fp || !hourList(sel)) return; sel.dataset.fp = 1;
      var px = document.createElement('input'); px.type = 'text'; px.className = 'fp-hour'; px.readOnly = true; sel.parentNode.insertBefore(px, sel); sel.classList.add('fp-under');
      var show = function () { px.value = (sel.options[sel.selectedIndex] || {}).text || ''; }; show(); sel.addEventListener('change', show);
      load().then(function () { if (!window.flatpickr) { sel.classList.remove('fp-under'); px.remove(); return; }
        var o = { enableTime: true, noCalendar: true, time_24hr: true, minuteIncrement: 60, dateFormat: 'H:i', defaultDate: px.value, disableMobile: true,
          onChange: function (d) { if (!d[0]) return; var h = d[0].getHours(); var want = [].filter.call(sel.options, function (o) { return parseInt(o.text, 10) === h; })[0]; if (want && sel.value !== want.value) { sel.value = want.value; sel.dispatchEvent(new Event('change', { bubbles: true })); } } };
        if (ar && flatpickr.l10ns && flatpickr.l10ns.ar) o.locale = 'ar'; flatpickr(px, o); }); });
  }
  var t = null; new MutationObserver(function () { clearTimeout(t); t = setTimeout(function () { wire(document); }, 150); }).observe(document.documentElement, { childList: true, subtree: true });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { wire(document); }); else wire(document);
})();
