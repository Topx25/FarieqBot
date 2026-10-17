/* WhatsApp Inbox — dashboard, conversations, search, users. All text is escaped before display. */
// Refresh / polling pace: Settings → System → refresh_pct (100 = normal, 200 = half as often on a busy server).
function WIR(ms) { var p = +((window.WI_SYS || {}).refresh_pct) || 100; return Math.round(ms * Math.max(50, Math.min(400, p)) / 100); }
(function () {
  var app = document.querySelector('.app');
  if (!app) return;
  var page = app.getAttribute('data-page');
  var colors = ['#10b981', '#6366f1', '#f59e0b', '#ef4444', '#0ea5e9', '#a855f7', '#14b8a6', '#f97316'];

  var WI_DICT = window.WI_T || {}, AR = window.WI_LANG === 'ar'; // not named L: the library page keeps its own state in L
  function T(s) { return Object.prototype.hasOwnProperty.call(WI_DICT, s) ? WI_DICT[s] : s; } window.T = window.T || T;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]; }); }
  // Requests never fail silently: a lost connection or a non-JSON reply becomes {ok:false, error}.
  function wiSafeJson(r) { if (r.status === 401) { location.reload(); return { ok: false, error: 'Sign in again' }; }
    return r.text().then(function (t) { try { return JSON.parse(t); } catch (e) { return { ok: false, error: (r.status >= 500 ? 'Server error (' + r.status + ')' : 'Unexpected reply from the server'), status: r.status }; } }); }
  function wiNetFail() { return { ok: false, error: T('No connection to the server — try again') }; }
  // One way to talk to the server for the whole dashboard (features.js uses it too): same CSRF token, same error shape.
  window.WI = window.WI || {};
  WI.csrf = function () { return app.getAttribute('data-csrf') || ''; };
  WI.get = function (name, params) { var q = new URLSearchParams(Object.assign({ api: name }, params || {})); return fetch('?' + q.toString(), { credentials: 'same-origin', cache: 'no-store' }).then(wiSafeJson, wiNetFail); };
  WI.post = function (name, body) { var o = { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': WI.csrf() } }; if (body !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(body); } return fetch('?api=' + name, o).then(wiSafeJson, wiNetFail); };
  function api(name, params) { return WI.get(name, params); }
  // Links from messages / contacts open only as web pages (no javascript: or data: URLs).
  function safeUrl(u) { u = String(u || '').trim(); if (!/^https?:\/\//i.test(u)) u = /^[\w.-]+\.[a-z]{2,}(\/|$)/i.test(u) ? 'https://' + u : '#'; return u; }
  function fmt(n) { return Number(n || 0).toLocaleString((AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB')); }
  var KIND = { image: '🖼️ Photo', voice: '🎤 Voice message', audio: '🎵 Audio', video: '🎬 Video', gif: '🎞️ GIF', sticker: '🏷️ Sticker', document: '📄 File', contact: '👤 Contact', location: '📍 Location', poll: '📊 Poll', reaction: '', viewonce: '👁️ View-once photo or video — WhatsApp opens it on the phone only' };
  function tick(st) { st = Number(st || 0); return st >= 4 ? '<span class="read">✓✓</span>' : (st >= 3 ? '✓✓' : '✓'); }
  function kindLabel(k) { return KIND[k] ? T(KIND[k]) : ''; }
  function title(c) { if (c.name && /[·•∙⋅]{2,}/.test(c.name)) c.name = ''; return c.customer ? c.customer.name : (c.name || (c.phone ? '+' + c.phone : (c.group ? T('Group') : String(c.jid || '').split('@')[0] + ' (' + T('hidden') + ')'))); }
  function color(k) { var h = 0; k = String(k || ''); for (var i = 0; i < k.length; i++) h = (h * 31 + k.charCodeAt(i)) >>> 0; return colors[h % colors.length]; }
  // Avatar: coloured initial, replaced by the WhatsApp profile photo when there is one (cached on the server).
  function av(jid, t) { jid = String(jid || ''); return '<div class="av" style="background:' + color(jid) + '">' + initial(t) + (jid && !/@(broadcast|newsletter)/.test(jid) ? '<img data-ph="?api=photo&jid=' + encodeURIComponent(jid) + '" alt="" hidden>' : '') + '</div>'; }
  // Profile photos: load two at a time, only when their avatar is on screen; a photo the server is still
  // fetching from WhatsApp is retried once a bit later. The browser caches them for a week.
  var phQ = [], phBusy = 0, phIO = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) { es.forEach(function (e) { if (!e.isIntersecting) return; phIO.unobserve(e.target); var im = e.target.querySelector('img[data-ph]'); if (im) { phQ.push(im); phNext(); } }); }, { rootMargin: '200px' }) : null;
  function phNext() {
    while (phBusy < 2 && phQ.length) {
      var im = phQ.shift(); if (!im.isConnected || im.dataset.done) continue; phBusy++;
      im.onload = function () { this.hidden = false; this.dataset.done = '1'; phBusy--; phNext(); };
      im.onerror = function () { var me = this; phBusy--; phNext(); if (!me.dataset.retry) { me.dataset.retry = '1'; setTimeout(function () { if (me.isConnected) { me.src = me.getAttribute('data-ph') + '&r=1'; } }, 25000); } else me.remove(); };
      im.src = im.getAttribute('data-ph');
    }
  }
  new MutationObserver(function () { [].forEach.call(document.querySelectorAll('img[data-ph]:not([data-q])'), function (im) { im.setAttribute('data-q', '1'); var box = im.parentNode; if (phIO && box) phIO.observe(box); else { phQ.push(im); phNext(); } }); }).observe(document.body, { childList: true, subtree: true });
  function initial(t) { return esc(String(t).replace(/^\+/, '').charAt(0).toUpperCase() || '?'); }
  function dayStamp(t) { var d = new Date(t); return isNaN(d) ? '' : d.toLocaleDateString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) + ' · ' + d.toLocaleTimeString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { hour: '2-digit', minute: '2-digit' }); }
  function when(t, long) {
    var d = new Date(t); if (isNaN(d)) return '';
    var today = new Date();
    if (!long && d.toDateString() === today.toDateString()) return d.toLocaleTimeString((AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB'), { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString((AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB'), long ? { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } : { day: 'numeric', month: 'short' });
  }

  /* ---------------- Movable menu: drag items to reorder, drag the header to dock right / left / top, lock ---------------- */
  (function () {
    function load(k, d) { try { var v = localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } }
    function save(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} }
    var nav = document.getElementById('side-nav'), side = document.querySelector('.side'), lockBtn = document.getElementById('menu-lock');
    if (!nav || !side) return;
    var locked = load('wi_menu_locked', false), dock = load('wi_menu_dock', 'start');
    function applyDock() { app.classList.remove('dock-start', 'dock-end', 'dock-top'); } // v5: the menu side is set by ⇄ in the top bar
    function applyLock() { lockBtn.textContent = locked ? '🔒' : '🔓'; app.classList.toggle('menu-locked', locked); [].forEach.call(nav.querySelectorAll('a'), function (a) { a.draggable = !locked; }); side.querySelector('.side-handle').draggable = !locked; }
    // saved order of nav items
    var order = load('wi_menu_order2', []);
    // Items keep their group: a saved order only reorders links inside the same group.
    order.forEach(function (href) { var a = nav.querySelector('a[href="' + href + '"]'); if (!a) return; var h = a.previousElementSibling; while (h && !h.classList.contains('nav-h')) h = h.previousElementSibling; var last = a; var n = a.nextElementSibling; while (n && !n.classList.contains('nav-h')) { last = n; n = n.nextElementSibling; } if (last !== a) last.after(a); });
    applyDock(); applyLock();
    lockBtn.addEventListener('click', function (e) { e.stopPropagation(); locked = !locked; save('wi_menu_locked', locked); applyLock(); });
    // reorder items
    var dragging = null;
    nav.addEventListener('dragstart', function (e) { var a = e.target.closest('a'); if (!a || locked) return; dragging = a; a.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'nav'); });
    nav.addEventListener('dragover', function (e) {
      if (!dragging) return; e.preventDefault();
      var over = e.target.closest('a'); if (!over || over === dragging) return;
      var r = over.getBoundingClientRect(), vertical = !app.classList.contains('dock-top');
      var after = vertical ? (e.clientY > r.top + r.height / 2) : ((e.clientX > r.left + r.width / 2) !== (document.dir === 'rtl'));
      nav.insertBefore(dragging, after ? over.nextSibling : over);
    });
    nav.addEventListener('dragend', function () { if (!dragging) return; dragging.classList.remove('dragging'); dragging = null; save('wi_menu_order2', [].map.call(nav.querySelectorAll('a'), function (a) { return a.getAttribute('href'); })); });
    // dock the whole menu by dragging its header to an edge
    var zones = document.createElement('div'); zones.className = 'dock-zones';
    zones.innerHTML = '<div data-dock="start"></div><div data-dock="top"></div><div data-dock="end"></div>';
    document.body.appendChild(zones);
    var handle = side.querySelector('.side-handle');
    handle.addEventListener('dragstart', function (e) { if (locked) { e.preventDefault(); return; } e.dataTransfer.setData('text/plain', 'dock'); zones.classList.add('on'); });
    handle.addEventListener('dragend', function () { zones.classList.remove('on'); [].forEach.call(zones.children, function (z) { z.classList.remove('hot'); }); });
    [].forEach.call(zones.children, function (z) {
      z.textContent = T('Drop here');
      z.addEventListener('dragover', function (e) { e.preventDefault(); z.classList.add('hot'); });
      z.addEventListener('dragleave', function () { z.classList.remove('hot'); });
      z.addEventListener('drop', function (e) { e.preventDefault(); dock = z.getAttribute('data-dock'); save('wi_menu_dock', dock); applyDock(); zones.classList.remove('on'); });
    });
  })();

  // Gateway state in the sidebar.
  api('stats').then(function (s) {
    var el = document.getElementById('gw-state');
    if (el) el.textContent = s && s.ok ? (s.connected ? T('🟢 Connected') + (s.account ? ' · ' + s.account : '') : T('🔴 Disconnected')) : T('⚠️ Gateway not responding');
    if (page === 'dashboard') dashboard(s);
  });

  /* ---------------- Dashboard ---------------- */
  function kpi(icon, label, value, extra, glow) {
    return '<div class="kpi" style="--glow:' + glow + '"><div class="k-ic">' + icon + '</div><small>' + esc(label) + '</small><b>' + fmt(value) + '</b>' + (extra ? '<em>' + esc(extra) + '</em>' : '') + '</div>';
  }
  function dashboard(s) {
    var pp = (window.WI_PERM || {}).pages || {};
    if (pp.analytics === '' && pp.inbox === '') { ['kpis', 'chart', 'top'].forEach(function (id) { var el = document.getElementById(id); el = el && (el.closest('.card') || el); if (el) el.style.display = 'none'; }); document.getElementById('since').textContent = ''; return; } // no access to this number's chats
    if (!s || !s.ok) { document.getElementById('kpis').innerHTML = '<div class="card empty">' + T('Could not load data:') + ' ' + esc(s && s.error) + '</div>'; return; }
    var hr = new Date().getHours(), d = new Date();
    document.getElementById('h-date').textContent = d.toLocaleDateString(document.documentElement.lang === 'ar' || window.WI_LANG === 'ar' ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    document.getElementById('since').textContent = s.since ? T('Archive since') + ' ' + when(s.since, true) : T('No messages yet');
    // Cards: icon, big number, label and one line that explains it.
    var dd = s.days || [], yday = dd.length > 1 ? dd[dd.length - 2] : null, tday = dd.length ? dd[dd.length - 1] : null;
    var yTot = yday ? yday.in + yday.out : 0, diff = yTot ? Math.round(((s.today || 0) - yTot) / yTot * 100) : null;
    function card(cls, icon, label, value, note) { return '<div class="hk ' + cls + '"><div class="hk-ic">' + icon + '</div><div class="hk-b"><small>' + esc(label) + '</small><b>' + fmt(value) + '</b><em>' + note + '</em></div></div>'; }
    document.getElementById('kpis').innerHTML =
      card('k1', '💬', T('Messages today'), s.today, diff === null ? '' : (diff >= 0 ? '<span class="up">▲ ' + diff + '%</span> ' : '<span class="down">▼ ' + Math.abs(diff) + '%</span> ') + T('vs yesterday')) +
      card('k2', '👥', T('Active this week'), s.active7, T('out of') + ' ' + fmt(s.chats) + ' ' + T('conversations')) +
      card('k3', '⭐', T('Customer chats'), s.customer_chats || 0, T('people who are customers')) +
      card('k4', '🤖', T('Bot messages'), s.bot, T('sent by the bot in total'));
    document.getElementById('kpis2').innerHTML = [['📥', T('Received'), s.in], ['📤', T('Sent'), s.out], ['🖼️', T('Photos & files'), s.media]].map(function (x) { return '<div><span>' + x[0] + '</span><small>' + esc(x[1]) + '</small><b>' + fmt(x[2]) + '</b></div>'; }).join('');
    // Chart: one column per day — received (green) and sent (purple) side by side, the day's total on top, today highlighted.
    var days = dd.slice(-7), mx = 1, rtl = (document.documentElement.dir || document.body.dir) === 'rtl' || window.WI_LANG === 'ar';
    days.forEach(function (x) { mx = Math.max(mx, x.in, x.out); });
    var loc = window.WI_LANG === 'ar' ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', wk = 0; days.forEach(function (x) { wk += x.in + x.out; });
    var best = days.reduce(function (a, x) { return !a || x.in + x.out > a.in + a.out ? x : a; }, null);
    document.getElementById('chart').innerHTML = days.length ? '<p class="hc-sum">' + T('Last 7 days') + ': <b>' + fmt(wk) + '</b> ' + T('messages') + (best ? ' · ' + T('busiest day') + ': <b>' + new Date(best.date).toLocaleDateString(loc, { weekday: 'long' }) + '</b> (' + fmt(best.in + best.out) + ')' : '') + '</p><div class="hc">' + days.map(function (x, i) {
      var dt = new Date(x.date), isT = i === days.length - 1;
      return '<div class="hc-d' + (isT ? ' today' : '') + '" title="' + esc(x.date) + '"><span class="hc-n">' + fmt(x.in + x.out) + '</span><div class="hc-bars"><i class="in" style="height:' + Math.max(2, Math.round(x.in / mx * 100)) + '%"><em>' + fmt(x.in) + '</em></i><i class="out" style="height:' + Math.max(2, Math.round(x.out / mx * 100)) + '%"><em>' + fmt(x.out) + '</em></i></div><small>' + (isT ? T('Today') : dt.toLocaleDateString(loc, { weekday: 'short' })) + '<br><span>' + dt.getDate() + '/' + (dt.getMonth() + 1) + '</span></small></div>';
    }).join('') + '</div>' : '<div class="empty">' + T('No messages yet') + '</div>';
    var top = s.top || [], tmax = top.length ? top[0].count : 1;
    document.getElementById('top').innerHTML = top.length ? top.slice(0, 7).map(function (c, i) {
      var t = title(c);
      return '<a class="t-row" href="?p=inbox#' + encodeURIComponent(c.jid) + '"><span class="rank">' + (i + 1) + '</span>' + av(c.jid, t) + '<div class="t-b"><b>' + esc(t) + (c.customer ? '<span class="tag">' + T('Customer') + '</span>' : '') + (c.group ? '<span class="tag g">' + T('Group') + '</span>' : '') + '</b><div class="meter"><span style="width:' + Math.max(6, Math.round(c.count / tmax * 100)) + '%"></span></div></div><span class="n">' + fmt(c.count) + '</span></a>';
    }).join('') : '<div class="empty">' + T('No conversations yet.') + '</div>';
  }
  function home() {
    api('home').then(function (h) { if (!h || !h.ok) return;
      var hr = new Date().getHours(), g = hr < 12 ? T('Good morning') : hr < 18 ? T('Good afternoon') : T('Good evening');
      document.getElementById('h-greet').textContent = g + '، ' + (h.name || '').split(' ')[0] + ' 👋';
      document.getElementById('h-accs').innerHTML = h.accounts.map(function (a) { return '<a class="h-acc' + (a.current ? ' cur' : '') + '" href="?acc=' + encodeURIComponent(a.id) + '"><i class="' + (a.connected ? 'ok' : 'bad') + '"></i>' + esc(a.name) + '</a>'; }).join('');
      var P = h.pages, can = function (k) { return P[k] && P[k] !== ''; };
      var Q = [['inbox', '💬', 'Conversations'], ['meetings', '🎥', 'Meetings'], ['cases', '🩺', 'Cases'], ['botrun', '🤖', 'Bot'], ['library', '📚', 'Library'], ['schedule', '⏰', 'Scheduling']].filter(function (x) { return can(x[0]); });
      document.getElementById('h-quick').innerHTML = Q.slice(0, 4).map(function (x) { return '<a class="h-qbtn" href="?p=' + x[0] + '"><span>' + x[1] + '</span>' + esc(T(x[2])) + '</a>'; }).join('');
      // Needs attention
      var A = [];
      h.meetings.forEach(function (m) { A.push('<a class="h-it' + (m.pending ? ' hot' : '') + '" href="?p=meetings"><span class="ic">🎥</span><div><b>' + esc(m.title) + '</b><small>' + T('Open now') + ' · ' + fmt(m.joined) + ' ' + T('joined') + (m.pending ? ' · 🙋 ' + fmt(m.pending) + ' ' + T('waiting for approval') : '') + '</small></div></a>'); });
      if (h.reminders.due) A.push('<a class="h-it hot" href="?p=inbox"><span class="ic">⏰</span><div><b>' + fmt(h.reminders.due) + ' ' + T('follow-ups are due') + '</b><small>' + esc(h.reminders.items.map(function (r) { return r.name || (r.jid || '').split('@')[0]; }).slice(0, 3).join('، ')) + '</small></div></a>');
      if (h.drafts.count) A.push('<a class="h-it" href="?p=botrun"><span class="ic">📝</span><div><b>' + fmt(h.drafts.count) + ' ' + T('bot drafts to review') + '</b><small dir="auto">' + esc(h.drafts.items[0] ? (h.drafts.items[0].name + ': ' + h.drafts.items[0].q) : '') + '</small></div></a>');
      h.accounts.forEach(function (a) { if (!a.connected) A.push('<a class="h-it hot" href="?p=accounts"><span class="ic">📵</span><div><b>' + esc(a.name) + ' ' + T('is disconnected') + '</b><small>' + T('Open WhatsApp numbers to reconnect') + '</small></div></a>'); });
      if (h.backup && h.backup.ok === false) A.push('<a class="h-it hot" href="?p=health"><span class="ic">💾</span><div><b>' + T('The last backup failed') + '</b><small>' + esc(when(h.backup.at)) + '</small></div></a>');
      document.getElementById('h-attn').innerHTML = A.join('') || '<div class="h-calm">✅ ' + T('All clear — nothing waiting for you.') + '</div>';
      // Numbers & bot
      if (!h.accounts.length) { var sc = document.getElementById('h-sys'); if (sc && sc.closest('.card')) sc.closest('.card').style.display = 'none'; }
      var modes = { off: '⚪ ' + T('Off'), draft: '📝 ' + T('Drafts only'), auto: '🟢 ' + T('Automatic') };
      document.getElementById('h-sys').innerHTML = h.accounts.map(function (a) { return '<div class="h-row"><span class="h-dot ' + (a.connected ? 'ok' : 'bad') + '"></span><b>' + esc(a.name) + '</b><span class="tag">' + (modes[a.bot] || esc(a.bot)) + '</span></div>'; }).join('')
        + '<div class="h-stats"><div><small>' + T('Bot replies today') + '</small><b>' + fmt(h.bot_today) + '</b></div><div><small>' + T('AI tasks today') + '</small><b>' + fmt(h.ai.tasks) + '</b></div>' + (can('cases') ? '<div><small>' + T('Cases') + '</small><b>' + fmt(h.cases) + '</b></div>' : '') + '</div>'
        + (h.backup ? '<div class="muted small">💾 ' + T('Last backup') + ': ' + (h.backup.ok ? '✅ ' : '⚠️ ') + esc(when(h.backup.at)) + '</div>' : '');
      // Shortcuts to every page the user can open
      var S = [['inbox', '💬'], ['cases', '🩺'], ['groups', '👥'], ['meetings', '🎥'], ['botrun', '🤖'], ['bot', '🧠'], ['schedule', '⏰'], ['library', '📚'], ['analytics', '📈'], ['numbers', '📇'], ['broadcast', '📣'], ['settings', '⚙️']];
      var N = (window.WI_PERM || {}).names || {};
      document.getElementById('h-short').innerHTML = S.filter(function (x) { return can(x[0]); }).map(function (x) { return '<a href="?p=' + x[0] + '"><span>' + x[1] + '</span>' + esc(N[x[0]] || x[0]) + '</a>'; }).join('');
    });
  }
  if (page === 'dashboard') home();
  var reload = document.querySelector('[data-reload]');
  if (reload) reload.addEventListener('click', function () { api('stats').then(dashboard); home(); });

  /* ---------------- Inbox ---------------- */
