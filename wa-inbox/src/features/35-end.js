})();

/* ---------- Page permissions: hide pages the user can't open, view-only banner, share button ---------- */
(function () {
  var P = window.WI_PERM; if (!P) return;
  var T = window.T || function (x) { return (window.WI_T || {})[x] || x; };
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var alias = { link: 'accounts', gateway: 'accounts' };
  [].forEach.call(document.querySelectorAll('a[href^="?p="]'), function (a) { var k = (a.getAttribute('href').match(/\?p=([a-z]+)/) || [])[1]; k = alias[k] || k;
    if (k && (P.pages[k] === '' || P.pages[k] === undefined && k !== 'dashboard' && k !== 'users' && k !== 'changes' && k !== 'link' && k !== 'gateway' && k !== 'search')) a.classList.add('pp-hidden'); });
  var main = document.querySelector('.main'); if (!main) return;
  if (P.level === 'view' && P.page !== 'dashboard') { var v = document.createElement('div'); v.className = 'pp-view'; v.textContent = '👁 ' + T('View only — you can see this page but not change it.'); main.insertBefore(v, main.firstChild); }
  if (!P.share) return;
  var b = document.createElement('button'); b.className = 'btn ghost sm pp-share-btn'; b.textContent = '🔗 ' + T('Share this page');
  var bar = document.querySelector('.topbar') || main; bar.appendChild(b);
  var dlg = document.createElement('dialog'); dlg.className = 'dlg'; document.body.appendChild(dlg);
  function api(n, q, body) { var u = '?api=' + n + (q ? '&' + new URLSearchParams(q) : ''); return fetch(u, body ? { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': ((document.querySelector('[data-csrf]') || {}).getAttribute ? document.querySelector('[data-csrf]').getAttribute('data-csrf') : '') }, body: JSON.stringify(body), credentials: 'same-origin' } : { credentials: 'same-origin' }).then(function (r) { return r.text().then(function (t) { try { return JSON.parse(t); } catch (e) { return { ok: false, error: 'Unexpected reply from the server (' + r.status + ')' }; } }); }, function () { return { ok: false, error: 'No connection to the server — try again' }; }); }
  function draw() {
    dlg.innerHTML = '<div class="stack pad"><h2>🔗 ' + T('Share this page') + ': ' + esc(P.names[P.page] || P.page) + '</h2><p class="muted small">' + T('Give another user access to this page with their email or WhatsApp number.') + '</p>'
      + '<div class="row wrap"><input id="pp-to" dir="ltr" placeholder="' + T('Email or WhatsApp number') + '" style="flex:1"><select id="pp-lv"><option value="view">👁 ' + T('View') + '</option><option value="edit">✏️ ' + T('View & edit') + '</option></select><button class="btn primary" id="pp-go">' + T('Share') + '</button></div><div class="muted small" id="pp-msg"></div>'
      + '<h3>' + T('Who has this page') + '</h3><div id="pp-list">' + T('Loading…') + '</div><div class="row"><button class="btn ghost" id="pp-x">' + T('Close') + '</button></div></div>';
    dlg.querySelector('#pp-x').onclick = function () { dlg.close(); };
    dlg.querySelector('#pp-go').onclick = function () { var to = dlg.querySelector('#pp-to').value.trim(); if (!to) return;
      api('perm_share', null, { page: P.page, to: to, level: dlg.querySelector('#pp-lv').value }).then(function (x) { dlg.querySelector('#pp-msg').textContent = x.ok ? '✓ ' + (x.status === 'invited' ? T('Invitation sent to') + ' ' + x.name + ' — ' + T('it works once they accept it') : T('Removed')) : '⚠️ ' + x.error; if (x.ok) { dlg.querySelector('#pp-to').value = ''; list(); } }); };
    list();
  }
  function list() { api('perm_list', { page: P.page }).then(function (x) { var el = dlg.querySelector('#pp-list'); if (!x.ok) { el.textContent = '⚠️ ' + x.error; return; }
    el.innerHTML = x.users.length ? '<table class="table"><tbody>' + x.users.map(function (u) { return '<tr><td>' + esc(u.name || u.email) + '<div class="muted small" dir="ltr">' + esc(u.email) + (u.phone ? ' · +' + esc(u.phone) : '') + '</div></td><td>' + (u.level === 'edit' ? '✏️ ' + T('View & edit') : '👁 ' + T('View')) + (u.pending ? ' <span class="tag">⏳ ' + T('waiting for acceptance') + '</span>' : '') + '</td><td><button class="btn danger sm" data-rm="' + esc(u.email) + '">' + T('Remove') + '</button></td></tr>'; }).join('') + '</tbody></table>' : '<div class="empty">' + T('Nobody yet.') + '</div>';
    [].forEach.call(el.querySelectorAll('[data-rm]'), function (r) { r.onclick = function () { api('perm_share', null, { page: P.page, to: r.dataset.rm, level: '' }).then(list); }; }); }); }
  b.onclick = function () { draw(); dlg.showModal(); };
})();

/* ---------- Menu: pinned (🔒) or auto (🔓), right/left (⇄), show/hide (☰) — saved per browser ---------- */
(function () {
  var app = document.querySelector('.app.shell-v3'), bar = document.querySelector('.topbar .tb-title'); if (!app || !bar) return;
  var T = function (x) { return (window.WI_T || {})[x] || x; };
  function ld(k, d) { try { var v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (e) { return d; } }
  function sv(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
  var st = { pin: ld('wi_nav_pin2', true), flip: ld('wi_nav_flip', false), hide: ld('wi_nav_hide', false) };
  var box = document.createElement('span'); box.className = 'nav-ctl';
  box.innerHTML = '<button type="button" data-n="hide"></button><button type="button" data-n="pin"></button><button type="button" data-n="flip" title="' + T('Move the menu to the other side') + '">⇄</button>';
  bar.insertBefore(box, bar.firstChild);
  function apply() {
    app.classList.toggle('nav-pin', st.pin && !st.hide); app.classList.toggle('nav-flip', st.flip); app.classList.toggle('nav-hide', st.hide); app.classList.remove('nav-peek');
    var h = box.querySelector('[data-n=hide]'), p = box.querySelector('[data-n=pin]');
    h.textContent = '☰'; h.title = st.hide ? T('Show the menu') : T('Hide the menu'); h.classList.toggle('on', !st.hide);
    p.textContent = st.pin ? '🔒' : '🔓'; p.title = st.pin ? T('Menu locked open — click to make it expand on hover') : T('Menu expands on hover — click to lock it open'); p.classList.toggle('on', st.pin); p.disabled = st.hide;
  }
  box.addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; var n = b.dataset.n;
    if (n === 'hide' && st.hide && !app.classList.contains('nav-peek') && e.shiftKey) { app.classList.add('nav-peek'); return; }
    st[n] = !st[n]; sv('wi_nav_' + n + (n === 'pin' ? '2' : ''), st[n]); apply(); });
  // While hidden: touching the screen edge on the menu side shows it for a moment.
  document.addEventListener('mousemove', function (e) { if (!st.hide) return; var w = window.innerWidth, rtl = document.documentElement.dir === 'rtl' || document.body.dir === 'rtl';
    var startRight = rtl !== st.flip, edge = startRight ? e.clientX > w - 6 : e.clientX < 6;
    if (edge) app.classList.add('nav-peek'); else if (app.classList.contains('nav-peek') && !e.target.closest('.side')) app.classList.remove('nav-peek'); });
  apply();
})();

/* ---------- Side menu sections fold/unfold (like the invoice system), remembered per browser ---------- */
(function () {
  var nav = document.getElementById('side-nav'); if (!nav) return;
  var closed = {}; try { closed = JSON.parse(localStorage.getItem('wi_nav_closed') || '{}') || {}; } catch (e) {}
  function links(h) { var out = [], n = h.nextElementSibling; while (n && !n.classList.contains('nav-h')) { if (n.tagName === 'A') out.push(n); n = n.nextElementSibling; } return out; }
  [].forEach.call(nav.querySelectorAll('.nav-h'), function (h) {
    var key = h.textContent.trim(), ls = links(h); if (!ls.length) { h.style.display = 'none'; return; }
    var hasOn = ls.some(function (a) { return a.classList.contains('on'); });
    function set(c) { h.classList.toggle('closed', c); ls.forEach(function (a) { a.classList.toggle('nav-folded', c); }); }
    set(!!closed[key] && !hasOn);
    h.addEventListener('click', function () { var c = !h.classList.contains('closed'); set(c); closed[key] = c; try { localStorage.setItem('wi_nav_closed', JSON.stringify(closed)); } catch (e) {} });
  });
})();

/* ---------- Theme: light / dark / automatic (follows the device), saved per browser ---------- */
(function () {
  var bar = document.querySelector('.topbar .tb-right') || document.querySelector('.topbar'); if (!bar) return;
  var T = function (x) { return (window.WI_T || {})[x] || x; }, root = document.documentElement;
  var modes = ['auto', 'light', 'dark'], ic = { auto: '🖥️', light: '☀️', dark: '🌙' }, lb = { auto: 'Theme: automatic (device)', light: 'Theme: light', dark: 'Theme: dark' };
  var cur = 'auto'; try { cur = localStorage.getItem('wi_theme') || 'auto'; } catch (e) {}
  var b = document.createElement('button'); b.type = 'button'; b.className = 'theme-btn';
  function apply() { if (cur === 'auto') root.removeAttribute('data-theme'); else root.setAttribute('data-theme', cur); b.textContent = ic[cur]; b.title = T(lb[cur]) + ' — ' + T('click to change'); }
  b.onclick = function () { cur = modes[(modes.indexOf(cur) + 1) % 3]; try { localStorage.setItem('wi_theme', cur); } catch (e) {} apply(); };
  bar.insertBefore(b, bar.firstChild); apply();
})();

/* ---------- Shared-page invitations: accept or decline from your own account ---------- */
(function () { var app = document.querySelector('.app'), csrf = app && app.getAttribute('data-csrf');
  document.addEventListener('click', function (e) { var b = e.target.closest('[data-inv-a]'); if (!b) return; var bar = b.closest('[data-inv]');
    b.disabled = true; fetch('?api=perm_invite', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: JSON.stringify({ page: bar.dataset.inv, accept: b.dataset.invA === '1' ? 1 : 0 }) })
      .then(function (r) { return r.text().then(function (t) { try { return JSON.parse(t); } catch (e) { return { ok: false, error: 'Unexpected reply from the server (' + r.status + ')' }; } }); }, function () { return { ok: false, error: 'No connection to the server — try again' }; }).then(function (x) { if (x.ok) location.reload(); else { b.disabled = false; alert(x.error || 'Failed'); } }); });
})();

/* ---------- Studio theme: the top menu becomes 5 section menus (no hidden items, no sideways scrolling) ---------- */
(function () {
  if (!document.querySelector('link[href*="studio.css"]') || window.innerWidth <= 1000) return;
  var nav = document.getElementById('side-nav'); if (!nav || nav.dataset.grouped) return; nav.dataset.grouped = 1;
  var kids = [].slice.call(nav.children), groups = [], cur = null;
  kids.forEach(function (n) { if (n.classList.contains('nav-h')) { cur = { title: n.textContent.trim(), links: [] }; groups.push(cur); } else if (n.tagName === 'A' && cur && !n.classList.contains('pp-hidden') && n.style.display !== 'none') cur.links.push(n); });
  groups = groups.filter(function (g) { return g.links.length; });
  nav.innerHTML = '';
  groups.forEach(function (g) {
    if (g.links.length === 1) { nav.appendChild(g.links[0]); return; }                       // a one-page section stays a plain tab
    var on = g.links.filter(function (a) { return a.classList.contains('on'); })[0];
    var wrap = document.createElement('div'); wrap.className = 'st-grp' + (on ? ' on' : '');
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'st-btn';
    btn.innerHTML = (on ? on.querySelector('i').outerHTML : g.links[0].querySelector('i').outerHTML) + '<span>' + g.title + (on ? ' · ' + on.textContent.replace(on.querySelector('i').textContent, '').trim() : '') + '</span><b>▾</b>';
    var menu = document.createElement('div'); menu.className = 'st-menu'; g.links.forEach(function (a) { menu.appendChild(a); });
    wrap.appendChild(btn); wrap.appendChild(menu); nav.appendChild(wrap);
    btn.onclick = function (e) { e.stopPropagation(); var open = wrap.classList.contains('open'); [].forEach.call(nav.querySelectorAll('.st-grp.open'), function (x) { x.classList.remove('open'); }); if (!open) wrap.classList.add('open'); };
  });
  document.addEventListener('click', function () { [].forEach.call(nav.querySelectorAll('.st-grp.open'), function (x) { x.classList.remove('open'); }); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') [].forEach.call(nav.querySelectorAll('.st-grp.open'), function (x) { x.classList.remove('open'); }); });
})();

