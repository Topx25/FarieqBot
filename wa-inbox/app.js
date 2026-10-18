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

// ▶ one player at a time: starting a recording or a video pauses the one that was playing (Topx1.Host)
document.addEventListener('play', function (e) { var t = e.target; if (!t || (t.tagName !== 'AUDIO' && t.tagName !== 'VIDEO')) return;
  [].forEach.call(document.querySelectorAll('audio,video'), function (m) { if (m !== t && !m.paused && !m.muted) m.pause(); }); }, true);

// ⏎ Enter in a one-line field saves: it presses the one main button of its dialog or card; a field that saves on change just commits (Topx1.Host)
document.addEventListener('keydown', function (e) {
  var t = e.target; if (e.key !== 'Enter' || e.isComposing || e.shiftKey || e.defaultPrevented || !t || t.tagName !== 'INPUT') return;
  if (['checkbox', 'radio', 'button', 'submit', 'file', 'color', 'range', 'search'].indexOf(t.type) !== -1 || t.form || t.hasAttribute('data-no-enter')) return;
  var scope = t.closest('dialog, .ml-fw, [data-enter], .card'); if (scope) { var bs = [].filter.call(scope.querySelectorAll('button.primary, .btn.primary, [data-enter-btn]'), function (b) { return !b.disabled && b.offsetParent !== null; });
    if (bs.length === 1) { e.preventDefault(); bs[0].click(); return; } }
  t.blur();
});
  /* ---------------- Media library (shared): pick a media item, choose chats, send / forward with a caption ---------------- */
  // Used by the Media page, the 🖼️ button of the chat box and the ↪ forward action on a chat's media.
  var ML = { items: null, vkeys: {} };
  // a short message that fades (app.js has no toast of its own)
  function mlToast(msg) { var x = document.createElement('div'); x.className = 'fx-toast'; x.textContent = msg; document.body.appendChild(x); setTimeout(function () { x.remove(); }, 3000); }
  // the library's kinds, in the order they are shown
  var ML_KINDS = [['sticker', '🙂', 'Stickers'], ['image', '🖼️', 'Photos'], ['gif', '🎞️', 'GIFs'], ['video', '🎬', 'Videos'], ['voice', '🎙️', 'Voice notes'], ['audio', '🎧', 'Audio'], ['document', '📄', 'Documents']];
  function mlUrl(key) { if (ML.vkeys && ML.vkeys[key]) return '?api=media_vault_file&k=' + encodeURIComponent(key); var p = String(key).split('/'); return p[0] === 'inbox_uploads' ? '?api=media&f=' + encodeURIComponent(p[1]) : '?api=media&a=' + encodeURIComponent(p[1]) + '&f=' + encodeURIComponent(p[2]); }
  function mlThumb(it) {
    var u = mlUrl(it.key);
    if (it.kind === 'image' || it.kind === 'sticker') return '<img src="' + u + '" alt="" loading="lazy"' + (it.kind === 'sticker' ? ' class="ml-stk"' : '') + '>';
    // videos and GIFs: only a still frame, and only once on screen (a phone on mobile data does not download whole videos)
    if (it.kind === 'gif') return '<video data-lz="' + u + '#t=0.1" preload="none" muted playsinline></video><i class="ml-badge">GIF</i>';
    if (it.kind === 'video') return '<video data-lz="' + u + '#t=0.5" preload="none" muted playsinline></video><i class="ml-badge">🎬</i>';
    return '<span class="ml-ic">' + (it.kind === 'voice' ? '🎙️' : it.kind === 'audio' ? '🎧' : '📄') + '</span>';
  }
  var mlLz = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) { es.forEach(function (e) { if (!e.isIntersecting) return; mlLz.unobserve(e.target); e.target.preload = 'metadata'; e.target.src = e.target.dataset.lz; }); }, { rootMargin: '150px' }) : null;
  new MutationObserver(function () { [].forEach.call(document.querySelectorAll('video[data-lz]:not([data-lzq])'), function (v) { v.setAttribute('data-lzq', '1'); if (mlLz) mlLz.observe(v); else { v.preload = 'metadata'; v.src = v.dataset.lz; } }); }).observe(document.documentElement, { childList: true, subtree: true });
  function mlSize(b) { b = +b || 0; if (!b) return '0 KB'; return b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  function mlLoad(force) { if (ML.items && !force) return Promise.resolve(ML.items); return WI.get('media_lib').then(function (r) { ML.items = r.ok ? r.items : []; ML.folders = r.folders || []; ML.bin = r.bin || null; ML.vaultN = r.vault_count || 0; return ML.items; }); }
  function mlDialog(html) { var d = document.getElementById('ml-dlg'); if (!d) { d = document.createElement('dialog'); d.id = 'ml-dlg'; d.className = 'dlg ml-dlg'; document.body.appendChild(d); }
    d.innerHTML = html; if (!d.open) d.showModal(); d.onclose = function () { d.innerHTML = ''; }; return d; }
  function mlFilter(items, q, kind, folder) { q = String(q || '').toLowerCase();
    return items.filter(function (it) { return (!kind || it.kind === kind) && (!folder || it.folder === folder) && (!q || (it.title + ' ' + (it.name || '') + ' ' + it.caption + ' ' + it.tags.join(' ') + ' ' + it.folder + ' ' + it.key).toLowerCase().indexOf(q) !== -1); }); }
  // the name people know a file by: its title, else the name it was sent with, else its caption
  function mlName(it) { return it.title || it.name || it.caption || ''; }
  function mlFileLike(it) { return it.kind === 'voice' || it.kind === 'audio' || it.kind === 'document'; }
  function mlGridHtml(items) { return items.length ? items.slice(0, 300).map(function (it) { var nm = mlName(it), file = mlFileLike(it);
    return '<button type="button" class="ml-it' + (file ? ' ml-file' : '') + '" data-key="' + esc(it.key) + '" title="' + esc(nm) + '">' + mlThumb(it) + (it.fav ? '<i class="ml-fav">★</i>' : '')
      + (nm || file ? '<span class="ml-t' + (it.title || it.name ? '' : ' cap') + '" dir="auto">' + esc(nm || (T(file ? (it.kind === 'document' ? 'Document' : 'Audio') : '') + (file && it.at ? ' · ' + when(it.at) : ''))) + '</span>' : '')
      + (it.size ? '<span class="ml-sz">' + mlSize(it.size) + '</span>' : '') + (it.youtube && it.youtube.state === 'done' ? '<i class="ml-yt" title="YouTube">▶</i>' : '') + '</button>'; }).join('') : '<div class="empty">' + T('Nothing found.') + '</div>'; }
  /** Pick one item, then send it to the given chat(s) with its caption (editable). */
  function mlPick(jids, done) {
    mlLoad().then(function (items) {
      var d = mlDialog('<div class="ml-head"><b>🖼️ ' + T('Send from the media library') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div>'
        + '<div class="ml-bar"><div class="search-xl"><span>🔎</span><input type="search" id="mlp-q" placeholder="' + esc(T('Search title, caption, tags…')) + '" dir="auto"></div>'
        + '<div class="seg sm" id="mlp-k"><button class="on" data-k="">' + T('All') + '</button>' + ML_KINDS.map(function (k) { return '<button data-k="' + k[0] + '" title="' + esc(T(k[2])) + '">' + k[1] + '</button>'; }).join('') + '</div>'
        + (ML.folders.length ? '<select id="mlp-f"><option value="">📁 ' + T('All folders') + '</option>' + ML.folders.map(function (f) { return '<option>' + esc(f) + '</option>'; }).join('') + '</select>' : '') + '</div>'
        + '<div class="ml-grid" id="mlp-g"></div><div class="ml-send" id="mlp-s" hidden></div>');
      var k = '', draw = function () { document.getElementById('mlp-g').innerHTML = mlGridHtml(mlFilter(items, document.getElementById('mlp-q').value, k, (document.getElementById('mlp-f') || {}).value)); };
      draw(); document.getElementById('mlp-q').oninput = draw; if (document.getElementById('mlp-f')) document.getElementById('mlp-f').onchange = draw;
      document.getElementById('mlp-k').onclick = function (e) { var b = e.target.closest('[data-k]'); if (!b) return; k = b.dataset.k; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); draw(); };
      d.onclick = function (e) { if (e.target.closest('[data-mlx]')) { d.close(); return; }
        var it = e.target.closest('.ml-it'); if (it) { var x = items.filter(function (i) { return i.key === it.dataset.key; })[0], s = document.getElementById('mlp-s'); s.hidden = false;
          s.innerHTML = '<div class="ml-prev">' + (x.kind === 'voice' || x.kind === 'audio' ? '<audio controls preload="none" src="' + mlUrl(x.key) + '"></audio>' : mlThumb(x)) + (mlName(x) ? '<b class="ml-pn" dir="auto">' + esc(mlName(x)) + '</b>' : '') + '</div><label>' + T('Caption') + '<textarea id="mlp-c" rows="3" dir="auto">' + esc(x.caption) + '</textarea></label><div class="row"><button type="button" class="btn primary" id="mlp-go">📤 ' + T('Send') + '</button><span class="muted small" id="mlp-m"></span></div>';
          document.getElementById('mlp-go').onclick = function () { this.disabled = true; document.getElementById('mlp-m').textContent = '⏳';
            WI.post('media_send', { key: x.key, jids: jids, caption: document.getElementById('mlp-c').value }).then(function (r) { if (r.ok) { d.close(); mlToast('📤 ' + T('Sending from the server — you can close this page')); if (done) setTimeout(function () { done(r); }, 5000); } else document.getElementById('mlp-m').textContent = '⚠️ ' + (r.error || T('Failed')); }); }; } };
    });
  }
  /** One chat's media and files, by kind: open, download or forward any of them. */
  function mlChatMedia(jid, title) {
    var d = mlDialog('<div class="ml-head"><b>📁 ' + T('Media of this chat') + ' <small class="muted" dir="auto">' + esc(title || '') + '</small></b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-cm muted">' + T('Loading…') + '</div>');
    d.onclick = function (e) { if (e.target.closest('[data-mlx]')) d.close(); };
    WI.get('chat_media', { jid: jid }).then(function (r) { var box = d.querySelector('.ml-cm'); if (!r.ok) { box.textContent = '⚠️ ' + (r.error || ''); return; }
      var items = r.items || [], k = '', cnt = {}; items.forEach(function (i) { cnt[i.kind] = (cnt[i.kind] || 0) + 1; });
      if (!items.length) { box.textContent = T('No photos, recordings or files in this chat yet.'); return; }
      box.className = 'ml-cm'; box.innerHTML = '<div class="seg sm" id="mlc-k"><button class="on" data-k="">' + T('All') + ' ' + items.length + '</button>' + ML_KINDS.filter(function (x) { return cnt[x[0]]; }).map(function (x) { return '<button data-k="' + x[0] + '" title="' + esc(T(x[2])) + '">' + x[1] + ' ' + cnt[x[0]] + '</button>'; }).join('') + '</div>'
        + '<div class="ml-grid" id="mlc-g"></div><div class="ml-send" id="mlc-s" hidden></div>';
      var draw = function () { document.getElementById('mlc-g').innerHTML = mlGridHtml(items.filter(function (i) { return !k || i.kind === k; })); }; draw();
      document.getElementById('mlc-k').onclick = function (e) { var b = e.target.closest('[data-k]'); if (!b) return; k = b.dataset.k; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); draw(); };
      document.getElementById('mlc-g').onclick = function (e) { var it = e.target.closest('.ml-it'); if (!it) return; var x = items.filter(function (i) { return i.key === it.dataset.key; })[0], s = document.getElementById('mlc-s'); s.hidden = false;
        s.innerHTML = '<div class="ml-prev">' + (x.kind === 'voice' || x.kind === 'audio' ? '<audio controls preload="none" src="' + mlUrl(x.key) + '"></audio>' : mlThumb(x)) + (mlName(x) ? '<b class="ml-pn" dir="auto">' + esc(mlName(x)) + '</b>' : '') + '<small class="muted">' + mlSize(x.size) + '</small></div>'
          + '<div class="row"><a class="btn sm" href="' + mlUrl(x.key) + '&dl=1' + (x.name ? '&name=' + encodeURIComponent(x.name.replace(/\.[^.]+$/, '')) : '') + '">⬇️ ' + T('Download') + '</a><a class="btn sm" href="' + mlUrl(x.key) + '" target="_blank" rel="noopener">👁️ ' + T('Open') + '</a><button type="button" class="btn primary sm" id="mlc-fw">↪ ' + T('Forward') + '</button></div>';
        document.getElementById('mlc-fw').onclick = function () { mlForward(x.key, x.caption || ''); }; };
    });
  }
  /** YouTube: one or several videos → the channel of the connected Google account (uploaded in the background). */
  function mlYoutube(keys, it, done) {
    var one = keys.length === 1 && it;
    var d = mlDialog('<div class="ml-head"><b>▶️ ' + T('Upload to YouTube') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-fw">'
      + (one ? '<label>' + T('Title') + '<input id="yt-t" maxlength="100" dir="auto" value="' + esc(it.title || '') + '"></label><label>' + T('Description') + '<textarea id="yt-d" rows="4" dir="auto">' + esc(it.caption || '') + '</textarea></label>'
        : '<div class="ml-many">🎬 ' + keys.length + ' ' + T('videos — each with its own title and caption from the library') + '</div>')
      + '<label>' + T('Tags') + '<input id="yt-g" dir="auto" placeholder="' + esc(T('separated by commas')) + '" value="' + esc(one ? (it.tags || []).join(', ') : '') + '"></label>'
      + '<label>' + T('Who can watch') + '<select id="yt-p"><option value="private">🔒 ' + T('Private') + '</option><option value="unlisted">🔗 ' + T('Unlisted (anyone with the link)') + '</option><option value="public">🌍 ' + T('Public') + '</option></select></label>'
      + '<p class="muted small">' + T('Uploaded in the background to the YouTube channel of the connected Google account. Until Google reviews your app, YouTube keeps uploads private — make them public from YouTube Studio.') + '</p>'
      + '<div class="row"><button type="button" class="btn primary" id="yt-go">▶️ ' + T('Upload') + '</button><span class="muted small" id="yt-m"></span></div></div>');
    d.onclick = function (e) { if (e.target.closest('[data-mlx]')) { d.close(); return; }
      if (e.target.closest('#yt-go')) { var b = document.getElementById('yt-go'); b.disabled = true; document.getElementById('yt-m').textContent = '⏳';
        WI.post('media_youtube', { keys: keys, privacy: document.getElementById('yt-p').value, tags: document.getElementById('yt-g').value, title: one ? document.getElementById('yt-t').value : '', desc: one ? document.getElementById('yt-d').value : undefined })
          .then(function (r) { if (!r.ok && r.need_yt) { b.disabled = false; document.getElementById('yt-m').innerHTML = '⚠️ ' + esc(r.error || '') + ' <button type="button" class="btn primary sm" id="yt-con">▶️ ' + T('Connect YouTube') + '</button>'; document.getElementById('yt-con').onclick = function () { WI.post('youtube_connect', {}).then(function (x) { if (x.ok) location.href = x.url; else document.getElementById('yt-m').textContent = '⚠️ ' + (x.error || T('Failed')); }); }; return; }
            if (!r.ok) { b.disabled = false; document.getElementById('yt-m').textContent = '⚠️ ' + (r.error || T('Failed')); return; } document.getElementById('yt-m').textContent = '✓ ' + T('Queued') + ' (' + r.queued + ')'; setTimeout(function () { d.close(); if (done) done(r); }, 900); }); } };
  }
  /** Choose up to 10 chats and send / forward one media file to them. */
  function mlForward(key, caption, done) {
    var isText = key === null; // a text message: sent as text
    var keys = isText ? [] : (Array.isArray(key) ? key : [key]), many = keys.length > 1, maxChats = Math.max(1, Math.min(10, Math.floor(30 / Math.max(1, keys.length)))); key = keys[0]; // several files: each goes with its own caption
    var kindOf = function (k) { return /\.webp$/i.test(k) ? 'sticker' : /\.(jpe?g|png)$/i.test(k) ? 'image' : (/\.(mp4|3gp|mov)$/i.test(k) ? 'video' : (/\.(ogg|opus|mp3|m4a|aac|wav)$/i.test(k) ? 'audio' : 'document')); };
    Promise.all([WI.get('chats'), isText ? Promise.resolve([]) : mlLoad().catch(function () { return []; })]).then(function (res) {
      var chats = (res[0].chats || []).slice(0, 400), lib = res[1] || [], sel = {}, gt = '';
      var meta = function (k) { return lib.filter(function (i) { return i.key === k; })[0] || { key: k, kind: kindOf(k) }; };
      var it = isText ? null : meta(key), size = 0; keys.forEach(function (k) { size += +meta(k).size || 0; });
      var label = function (c) { return c.name || ('+' + (c.phone || String(c.jid).split('@')[0])); };
      // what is sent: a compact card (file thumbnail, name, kind and size — or the text)
      var what = isText ? '<div class="fw-item"><span class="fw-ic">💬</span><div class="grow"><b>' + T('Message') + '</b><textarea id="mlf-c" rows="6" dir="auto">' + esc(caption || '') + '</textarea></div></div>'
        : many ? '<div class="fw-item"><span class="fw-ic">📦</span><div class="grow"><b>' + keys.length + ' ' + T('files') + '</b><small class="muted">' + (size ? mlSize(size) + ' · ' : '') + T('files — each is sent with its own caption') + '</small></div></div>'
        : '<div class="fw-item"><div class="fw-th">' + (it.kind === 'voice' || it.kind === 'audio' ? '<span class="fw-ic">' + (it.kind === 'voice' ? '🎙️' : '🎧') + '</span>' : mlThumb(it)) + '</div><div class="grow"><b dir="auto">' + esc(mlName(it) || T(it.kind === 'document' ? 'Document' : 'Audio')) + '</b><small class="muted">' + (it.size ? mlSize(it.size) : '') + '</small>'
          + (it.kind === 'voice' || it.kind === 'audio' ? '<audio controls preload="none" src="' + mlUrl(key) + '"></audio>' : '') + '<textarea id="mlf-c" rows="2" dir="auto" placeholder="' + esc(T('Caption')) + '">' + esc(caption || '') + '</textarea></div></div>';
      var d = mlDialog('<div class="ml-head"><b>↪ ' + T('Forward to chats') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div>'
        + '<div class="fw">' + what
        + '<div class="fw-to"><div class="search-xl"><span>🔎</span><input type="search" id="mlf-q" placeholder="' + esc(T('Search name or number…')) + '" dir="auto"></div>'
        + '<div class="seg sm" id="mlf-t"><button class="on" data-t="">' + T('All') + '</button><button data-t="p">👤 ' + T('Chats') + '</button><button data-t="g">👥 ' + T('Groups') + '</button></div></div>'
        + '<div class="fw-sel" id="mlf-s" hidden></div><div class="fw-list" id="mlf-l"></div>'
        + '<div class="fw-foot"><span class="muted small" id="mlf-m">' + T('Up to') + ' ' + maxChats + ' ' + T('chats, sent one by one.') + '</span><button type="button" class="btn primary" id="mlf-go" disabled>📤 ' + T('Send') + ' <span id="mlf-n"></span></button></div></div>');
      d.classList.add('fw-dlg');
      var drawSel = function () { var ks = Object.keys(sel), box = document.getElementById('mlf-s'); box.hidden = !ks.length;
        box.innerHTML = ks.map(function (j) { var c = chats.filter(function (x) { return x.jid === j; })[0] || { jid: j }; return '<span class="fw-chip" data-un="' + esc(j) + '" dir="auto">' + (c.group ? '👥 ' : '') + esc(label(c)) + ' ✕</span>'; }).join('');
        document.getElementById('mlf-n').textContent = ks.length ? '(' + ks.length + ')' : ''; document.getElementById('mlf-go').disabled = !ks.length; };
      var draw = function () { var q = document.getElementById('mlf-q').value.toLowerCase();
        var rows = chats.filter(function (c) { return (!gt || (gt === 'g') === !!c.group) && (!q || ((c.name || '') + ' ' + (c.phone || '')).toLowerCase().indexOf(q) !== -1); }).slice(0, 150);
        document.getElementById('mlf-l').innerHTML = rows.length ? rows.map(function (c) { var on = !!sel[c.jid]; return '<button type="button" class="fw-row' + (on ? ' on' : '') + '" data-j="' + esc(c.jid) + '">' + av(c.jid, label(c))
          + '<span class="grow"><b dir="auto">' + esc(label(c)) + '</b><small class="muted" dir="ltr">' + (c.group ? '👥 ' + T('Group') : (c.phone ? '+' + esc(c.phone) : '')) + '</small></span><i class="fw-ck">' + (on ? '✓' : '') + '</i></button>'; }).join('') : '<div class="empty">' + T('Nothing found.') + '</div>'; };
      var toggle = function (j) { if (sel[j]) delete sel[j]; else { if (Object.keys(sel).length >= maxChats) { mlToast(T('Up to') + ' ' + maxChats + ' ' + T('chats, sent one by one.')); return; } sel[j] = 1; } draw(); drawSel(); };
      draw(); document.getElementById('mlf-q').oninput = draw;
      document.getElementById('mlf-t').onclick = function (e) { var b = e.target.closest('[data-t]'); if (!b) return; gt = b.dataset.t; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); draw(); };
      d.onclick = function (e) { if (e.target.closest('[data-mlx]')) { d.close(); return; }
        var r = e.target.closest('[data-j]'); if (r) { toggle(r.dataset.j); return; }
        var u = e.target.closest('[data-un]'); if (u) { toggle(u.dataset.un); return; }
        if (e.target.closest('#mlf-go')) { var b = document.getElementById('mlf-go'); b.disabled = true; document.getElementById('mlf-m').textContent = '⏳ ' + T('Sending…'); var cap = document.getElementById('mlf-c');
          (isText ? WI.post('forward_text', { text: cap.value, jids: Object.keys(sel) }) : WI.post('media_send', many ? { keys: keys, jids: Object.keys(sel) } : { key: key, jids: Object.keys(sel), caption: cap ? cap.value : '' })).then(function (x) { document.getElementById('mlf-m').textContent = x.ok ? '✓ ' + (x.queued ? T('Sending from the server — you can close this page') + ' (' + x.of + ')' : T('Sent') + ' ' + x.sent + ' / ' + x.of) : '⚠️ ' + (x.error || T('Failed')); if (x.ok) setTimeout(function () { d.close(); if (done) done(x); }, 1200); else b.disabled = false; }); } };
    });
  }

  if (page === 'inbox') {
    var msgById = {}; // the shown messages by id (forwarding a text)
    var hashP = decodeURIComponent(location.hash.slice(1) || '').split('|'), focusMsg = hashP[1] || ''; // #chat or #chat|message (from the Media page)
    var chats = [], filter = '', open = hashP[0];
    var unread = {}, typing = {}, labelFilter = '';
    var meta = { me: '', replies: [], labels: [], chat_labels: {}, assign: {}, reminders: [], viewing: {}, users: [] };
    var CAT = { inquiry: '❓ Inquiry', complaint: '⚠️ Complaint', payment: '💳 Payment', new_order: '🛒 New order', follow_up: '🔁 Follow-up', support: '🛠️ Support', social: '💬 Social', other: '📁 Other' }; Object.assign(CAT, (window.WI_PACK || {}).summary_labels || {}); // extra categories from the business profile
    function labelById(id) { return meta.labels.filter(function (l) { return l.id === id; })[0]; }
    function userName(email) { var u = meta.users.filter(function (x) { return x.email === email; })[0]; return u ? u.name : email; }
    function remindersFor(jid) { return meta.reminders.filter(function (r) { return r.jid === jid; }); }
    function rowMeta(jid) {
      var h = '', ls = (meta.chat_labels[jid] || []).map(labelById).filter(Boolean), a = meta.assign[jid], rm = remindersFor(jid), v = meta.viewing[jid];
      ls.forEach(function (l) { h += '<span class="lbl" style="--c:' + esc(l.color) + '">' + esc(l.name) + '</span>'; });
      if (a) h += '<span class="asg" title="' + T('Assigned to') + ' ' + esc(userName(a.to)) + '">👤 ' + esc(a.to === meta.me ? T('Me') : userName(a.to).split(/[ @]/)[0]) + '</span>';
      if (rm.length) { var due = rm.some(function (r) { return Date.parse(r.at) <= Date.now(); }); h += '<span class="rmd' + (due ? ' due' : '') + '" title="' + esc(rm[0].note || '') + '">⏰ ' + esc(when(rm[0].at)) + '</span>'; }
      if (v && v.length) h += '<span class="viewing">👀 ' + esc(userName(v[0]).split(/[ @]/)[0]) + '</span>';
      var sm = meta.summaries && meta.summaries[jid];
      if (sm) h += '<span class="cat cat-' + esc(sm.category) + '">' + esc(CAT[sm.category] ? T(CAT[sm.category]) : sm.category) + '</span>' + (sm.needs_reply ? '<span class="cat needs">' + T('needs reply') + '</span>' : '');
      return h ? '<div class="c-meta">' + h + '</div>' : '';
    }
    var csrfM = app.getAttribute('data-csrf');
    function setMeta(body) { return WI.post('meta_set', body); }
    // What this user may do inside a conversation (chat permissions from the server; unknown = allowed, the server checks anyway).
    function CAN(k) { return !meta.can || meta.can[k] !== false; }
    function applyCan() { window.WI_CAN = meta.can || null; var comp = document.getElementById('composer'), at = document.getElementById('attach'), qb = document.getElementById('qr-btn');
      if (comp && !CAN('reply')) { comp.classList.add('no-reply'); comp.querySelector('textarea, #reply') && (comp.querySelector('#reply').disabled = true); comp.title = T('You can read this conversation but not reply.'); }
      if (at && !CAN('media')) at.closest('label').hidden = true; if (qb && !CAN('reply')) qb.hidden = true; }
    function loadMeta() {
      return api('meta').then(function (r) {
        if (!r.ok) return;
        meta = r; meta.chat_labels = r.chat_labels || {}; meta.assign = r.assign || {}; meta.viewing = r.viewing || {}; applyCan();
        document.getElementById('label-filter').innerHTML = meta.labels.map(function (l) { return '<button class="' + (labelFilter === l.id ? 'on' : '') + '" data-label="' + esc(l.id) + '" style="--c:' + esc(l.color) + '">● ' + esc(l.name) + '</button>'; }).join('');
        drawDue(); drawList(); if (open) drawTools(open);
      });
    }
    function drawDue() {
      var due = meta.reminders.filter(function (r) { return Date.parse(r.at) <= Date.now(); });
      var box = document.getElementById('due-box');
      box.hidden = !due.length;
      box.innerHTML = due.map(function (r) { return '<div class="due-item"><span>⏰ <b>' + esc(r.name || r.jid.split('@')[0]) + '</b> — ' + esc(r.note || T('Follow up')) + '</span><span class="row"><button class="chip-btn sm" data-open-r="' + esc(r.jid) + '">' + T('Open') + '</button><button class="chip-btn sm" data-done-r="' + esc(r.id) + '">✓ ' + T('Done') + '</button></span></div>'; }).join('');
    }
    try { unread = JSON.parse(localStorage.getItem('wi_unread_' + (app.getAttribute('data-acc') || '')) || '{}') || {}; } catch (e) { unread = {}; }
    function saveUnread() { try { localStorage.setItem('wi_unread_' + (app.getAttribute('data-acc') || ''), JSON.stringify(unread)); } catch (e) {} updateTitle(); }
    function updateTitle() { var n = 0; for (var k in unread) n += unread[k] || 0; document.title = (n ? '(' + n + ') ' : '') + ((window.WI_BRAND || {}).name || ''); }
    document.addEventListener('click', function (e) { if (e.target.closest('[data-hide-chat]') && open) { toggleHidden(open); var b = e.target.closest('[data-hide-chat]'); b.textContent = hiddenChats.indexOf(open) !== -1 ? '👁️' : '🙈'; } });
    // 🔒 Who sees this chat: hide it from chosen team members (their lists, search, groups and reports never show it)
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-hide-for]'); if (!b || !open) return; var jid = open;
      api('hide_for&jid=' + encodeURIComponent(jid)).then(function (r) {
        if (!r.ok) return alert(r.error || ''); var d = document.createElement('dialog'); d.className = 'dlg';
        d.innerHTML = '<form method="dialog" class="stack pad"><h3>🔒 ' + T('Who sees this chat') + '</h3><p class="muted small">' + T('Ticked people do not see this chat anywhere: list, search, groups, reports.') + '</p>'
          + ((r.users || []).length ? r.users.map(function (u) { return '<label class="check"><input type="checkbox" data-em="' + esc(u.email) + '"' + (u.hidden ? ' checked' : '') + '> ' + T('Hide from') + ' <b dir="auto">' + esc(u.name) + '</b> <small class="muted">' + esc(u.role) + '</small></label>'; }).join('') : '<p class="muted">' + T('No team members on this number.') + '</p>')
          + '<div class="row"><button class="btn primary">' + T('Done') + '</button></div></form>';
        document.body.appendChild(d); d.showModal(); d.addEventListener('close', function () { d.remove(); });
        d.addEventListener('change', function (ev) { var i = ev.target.closest('[data-em]'); if (!i) return; i.disabled = true;
          WI.post('hide_for&jid=' + encodeURIComponent(jid), { email: i.dataset.em, hide: i.checked ? 1 : 0 }).then(function (x) { i.disabled = false; if (!x.ok) { i.checked = !i.checked; alert(x.error || ''); } }); });
      });
    });    // Offline banner: the number is disconnected — chats, files and search keep working, replies wait in the outbox
    function accState() { api('acc_state').then(function (r) { if (!r || !r.ok) return; var b = document.getElementById('off-banner');
      if (!b) { b = document.createElement('div'); b.id = 'off-banner'; b.className = 'off-banner'; var th = document.querySelector('.inbox .thread'); if (th) th.insertBefore(b, th.firstChild); }
      b.hidden = r.connected && !r.waiting; b.innerHTML = !r.connected ? '📴 ' + T('WhatsApp is offline — chats and files stay here; replies wait and go out when the number is back.') + (r.waiting ? ' <b>(' + fmt(r.waiting) + ' ' + T('waiting') + ')</b>' : '') : '⏳ ' + fmt(r.waiting) + ' ' + T('replies are being sent…'); }); }
    accState(); setInterval(accState, 60000);
    var listEl = document.getElementById('chats'), msgsEl = document.getElementById('msgs'), headEl = document.getElementById('thread-h');
    // View options of this browser: full screen, previews, chat list, hidden chats (per number)
    var VK = 'wi_view', HK = 'wi_hidden_' + (app.getAttribute('data-acc') || ''), rd = function (k, d) { try { return JSON.parse(localStorage.getItem(k) || '') || d; } catch (e) { return d; } }, wr = function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} };
    var view = rd(VK, { focus: false, thumbs: true, list: true, showHidden: false }), hiddenChats = rd(HK, []);
    function applyView() { app.classList.toggle('v-focus', !!view.focus); app.classList.toggle('v-nothumbs', !view.thumbs); app.classList.toggle('v-nolist', !view.list);
      [].forEach.call(document.querySelectorAll('#view-bar [data-view]'), function (b) { var k = b.dataset.view; b.classList.toggle('on', k === 'thumbs' ? !view.thumbs : (k === 'list' ? !view.list : (k === 'hidden' ? !!view.showHidden : !!view[k]))); }); }
    document.addEventListener('click', function (e) { var b = e.target.closest('[data-view]'); if (!b) return; var k = b.dataset.view;
      if (k === 'thumbs') view.thumbs = !view.thumbs; else if (k === 'list') view.list = !view.list; else if (k === 'hidden') view.showHidden = !view.showHidden; else view[k] = !view[k];
      wr(VK, view); applyView(); if (k === 'hidden') drawList(); if (k === 'all') loadChats(); });
    function toggleHidden(jid) { var i = hiddenChats.indexOf(jid); if (i === -1) hiddenChats.push(jid); else hiddenChats.splice(i, 1); wr(HK, hiddenChats); drawList(); }
    setTimeout(applyView, 0);
    function drawList() {
      var q = (document.getElementById('chat-q').value || '').toLowerCase();
      var html = chats.filter(function (c) {
        if (filter === 'customers' && !c.customer) return false;
        if (filter === 'others' && (c.customer || c.group)) return false;
        if (filter === 'groups' && !c.group) return false;
        var asg = meta.assign[c.jid];
        if (filter === 'mine' && !(asg && asg.to === meta.me)) return false;
        if (filter === 'unassigned' && (asg || c.group)) return false;
        if (filter === 'remind' && !remindersFor(c.jid).length) return false;
        if (labelFilter && (meta.chat_labels[c.jid] || []).indexOf(labelFilter) === -1) return false;
        if (hiddenChats.indexOf(c.jid) !== -1 && !view.showHidden) return false; // hidden by this user
        return !q || (title(c) + ' ' + (c.phone || '') + ' ' + (c.last ? c.last.text : '')).toLowerCase().indexOf(q) !== -1;
      }).map(function (c) {
        var t = title(c);
        return '<div class="c' + (c.jid === open ? ' on' : '') + (hiddenChats.indexOf(c.jid) !== -1 ? ' c-hid' : '') + '" data-jid="' + esc(c.jid) + '"' + (c.acc ? ' data-acc="' + esc(c.acc) + '" style="border-inline-start:4px solid ' + color(c.acc) + '"' : '') + '>' + av(c.jid, t) + (c.acc ? '<span class="acc-mark" style="background:' + color(c.acc) + '" title="' + esc(c.acc_name || '') + '">' + esc(String(c.acc_name || '').slice(0, 10)) + '</span>' : '') + '<div class="c-b"><div class="c-t"><b>' + esc(t) + (c.customer ? '<span class="tag">' + T('Customer') + '</span>' : '') + (c.group ? '<span class="tag g">' + T('Group') + '</span>' : '') + '</b><small>' + esc(c.last ? when(c.last.time) : '') + '</small></div>' + rowMeta(c.jid) + '<div class="c-row2"><div class="c-p" dir="auto">' + (typing[c.jid] && typing[c.jid] > Date.now() ? '<i class="typing">' + T('typing…') + '</i>' : (c.last && c.last.dir === 'out' ? '✓ ' : '') + (c.last ? (c.last.kind === 'reaction' ? esc(c.last.text) + ' ' + T('reacted') : (c.last.text ? esc(c.last.text) : esc(kindLabel(c.last.kind) || '…'))) : '')) + '</div>' + (c.notes ? '<span class="note-ic" title="' + T('Notes') + '">📝</span>' : '') + (unread[c.jid] ? '<span class="unread">' + unread[c.jid] + '</span>' : '') + '</div></div></div>';
      }).join('');
      listEl.innerHTML = html || '<div class="empty">' + T('No conversations.') + '</div>';
    }
    // Phone: the list and the chat take the whole screen one at a time (like WhatsApp); the phone's back button returns to the list.
    var inboxEl = document.querySelector('.inbox'), isPhone = function () { return window.matchMedia('(max-width: 900px)').matches; };
    function closeChat() { if (inboxEl) inboxEl.classList.remove('has-open'); }
    window.addEventListener('popstate', function () { if (inboxEl && inboxEl.classList.contains('has-open')) closeChat(); });
    function openChat(jid) {
      if (inboxEl) { if (isPhone() && !inboxEl.classList.contains('has-open')) history.pushState({ chat: 1 }, '', '#' + encodeURIComponent(jid)); inboxEl.classList.add('has-open'); }
      open = jid; history.replaceState(history.state, '', '#' + encodeURIComponent(jid)); drawList();
      var ip = document.getElementById('info-panel'); if (ip && !ip.hidden) ip.hidden = true;
      if (unread[jid]) { delete unread[jid]; saveUnread(); }
      var sbx = document.getElementById('sum-box'); if (sbx) sbx.hidden = true;
      api('presence', { jid: jid });
      setTimeout(function () { drawTools(jid); setMeta({ action: 'viewing', jid: jid }); }, 0);
      var c = chats.filter(function (x) { return x.jid === jid; })[0] || { jid: jid };
      var isG = /@g\.us$/.test(jid);
      headEl.innerHTML = '<div class="th-top"><button type="button" class="icon-btn th-back" title="' + T('Back to the chats') + '">‹</button>' + av(jid, title(c))
        + '<div class="th-id grow"><div class="th-name"><b dir="auto">' + esc(title(c)) + '</b>' + (c.customer ? '<span class="th-badge cust">🧾 ' + T('Customer') + '</span>' : '') + (isG ? '<span class="th-badge grp">👥 ' + T('Group') + '</span>' : '') + '<span id="th-badges"></span></div>'
        + '<div class="th-sub"><span dir="ltr">' + (c.phone ? '+' + esc(c.phone) : (isG ? '' : esc(String(jid).split('@')[0]))) + '</span><span>💬 ' + fmt(c.count) + ' ' + T('messages') + '</span>' + (c.last && c.last.time ? '<span>🕐 ' + esc(when(c.last.time)) + '</span>' : '') + '<span class="info-hint">ⓘ ' + T('Contact info') + '</span><span class="typing" id="typing-h" hidden></span></div></div>'
        + '<div class="th-acts"><button type="button" class="icon-btn" data-view="list" title="' + T('Show / hide the chat list') + '">📋</button><button type="button" class="icon-btn" data-hide-chat title="' + T('Hide / show this chat in the list') + '">' + (hiddenChats.indexOf(jid) !== -1 ? '👁️' : '🙈') + '</button>' + (app.getAttribute('data-canusers') ? '<button type="button" class="icon-btn" data-hide-for title="' + T('Who sees this chat') + '">🔒</button>' : '') + (isG ? '<a class="icon-btn" href="?p=groups&g=' + encodeURIComponent(jid) + '" title="' + T('Group page') + '">👥</a>' : '<a class="icon-btn" href="?p=customer&jid=' + encodeURIComponent(jid) + '" title="' + T('Customer profile') + '">👤</a><a class="icon-btn" href="?p=programs&jid=' + encodeURIComponent(jid) + '" title="' + T('Enrol in a program') + '">💊</a>')
        + (!isG && CAN('groups') ? '<button type="button" class="icon-btn" id="add-grp" title="' + T('Add to a group') + '">👥➕</button>' : '')
        + '<button type="button" class="icon-btn" id="chat-media" title="' + T('Media of this chat') + '">📁</button>'
        + (CAN('delete_chat') ? '<button type="button" class="icon-btn" id="del-chat" title="' + T('Delete this chat') + '">🧹</button>' : '')
        + (!CAN('export') ? '' : '<div class="export"><button type="button" class="icon-btn" id="exp-btn" title="' + T('Export') + '">⬇️</button><div class="export-menu" hidden><a href="?api=export&fmt=txt&jid=' + encodeURIComponent(jid) + '">📄 ' + T('Text (.txt)') + '</a><a href="?api=export&fmt=csv&jid=' + encodeURIComponent(jid) + '">📊 ' + T('Excel (.csv)') + '</a><a href="?api=export&fmt=html&jid=' + encodeURIComponent(jid) + '">🌐 ' + T('Web page (.html)') + '</a></div></div>') + '</div></div>'
        + '<div class="th-info" id="th-info"></div>'
        + '<div class="thread-tools" id="thread-tools"></div>';
      // Customer / case details for the header (filled in when they arrive).
      if (!isG) api('customer', { jid: jid }).then(function (r) {
        if (jid !== open || !r || !r.ok) return;
        var chips = [], b = [];
        if (r.customer) { chips.push('<span class="th-chip">🧾 ' + esc(r.customer.name) + '</span>'); if (r.customer.due > 0) chips.push('<span class="th-chip due">💰 ' + T('Due') + ': ' + fmt(r.customer.due) + '</span>'); chips.push('<span class="th-chip">📄 ' + fmt(r.customer.count) + ' ' + T('invoices') + '</span>'); }
        if (r.case) { b.push('<a class="th-badge case" href="?p=cases&phone=' + encodeURIComponent(r.phone) + '">🩺 ' + T('Case') + '</a>'); var d = r.case.data || {}; if (d.complaint) chips.push('<span class="th-chip" dir="auto">🗣️ ' + esc(String(d.complaint).slice(0, 60)) + '</span>'); (r.case.labels || []).slice(0, 4).forEach(function (l) { chips.push('<span class="lbl">🏷️ ' + esc(l) + '</span>'); }); }
        (r.enrollments || []).filter(function (e) { return e.status === 'active'; }).forEach(function (e) { chips.push('<span class="th-chip">💊 ' + esc(e.program_name) + ' · ' + fmt(Object.keys(e.sent || {}).length) + '</span>'); });
        if (r.summary) chips.push('<span class="th-chip" title="' + esc(r.summary.summary) + '">🧠 ' + esc(r.summary.category) + (r.summary.needs_reply ? ' · ⏳ ' + T('needs a reply') : '') + '</span>');
        if (r.stats && r.stats.first) chips.push('<span class="th-chip">📅 ' + T('since') + ' ' + esc(when(r.stats.first, true)) + '</span>');
        if ((r.scheduled || []).length) chips.push('<span class="th-chip">⏰ ' + fmt(r.scheduled.length) + ' ' + T('scheduled') + '</span>');
        var bb = document.getElementById('th-badges'); if (bb) bb.innerHTML = b.join('');
        var inf = document.getElementById('th-info'); if (inf) inf.innerHTML = chips.join('');
      });
      msgsEl.innerHTML = '<div class="empty">' + T('Loading…') + '</div>';
      shown = PAGE; loadThread(jid, true);
      var comp = document.getElementById('composer'); if (comp) { comp.hidden = false; document.getElementById('safe-note').hidden = false; document.getElementById('reply').focus(); }
    }
    // Add this person to a group the number administers (their number does not have to be saved on the phone).
    function addToGroup(jid) {
      var d = document.getElementById('ag-dlg'); if (!d) { d = document.createElement('dialog'); d.id = 'ag-dlg'; d.className = 'dlg'; document.body.appendChild(d); }
      d.innerHTML = '<div class="pad stack"><h2>👥 ' + T('Add to a group') + '</h2><div id="ag-body" class="muted">' + T('Loading…') + '</div><div class="row"><button type="button" class="btn ghost" data-agx>' + T('Close') + '</button></div></div>'; d.showModal();
      d.querySelector('[data-agx]').onclick = function () { d.close(); };
      var body = d.querySelector('#ag-body');
      api('group_admin_list').then(function (r) {
        if (!r.ok) { body.textContent = r.error || T('Failed'); return; }
        if (!r.groups.length) { body.textContent = T('This number is not an admin of any group. Only a group admin can add people.'); return; }
        body.innerHTML = '<p class="small">' + T('Groups where this number is an admin') + ' · ' + T('left today') + ': <b>' + r.left + '</b></p>' + r.groups.map(function (g) { return '<div class="row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:6px 0"><span dir="auto">' + esc(g.name || g.jid) + ' <small class="muted">' + g.members + '</small></span><span><button type="button" class="btn primary sm" data-ag="' + esc(g.jid) + '"' + (r.left > 0 ? '' : ' disabled') + '>➕ ' + T('Add') + '</button> <button type="button" class="btn ghost sm" data-agi="' + esc(g.jid) + '">🔗 ' + T('Send invite link') + '</button></span></div>'; }).join('');
      });
      body.onclick = function (e) { var a = e.target.closest('[data-ag]'), i = e.target.closest('[data-agi]'); if (!a && !i) return; (a || i).disabled = true;
        if (i) { api('group_removed', { group: i.dataset.agi, jid: jid }).then(function (rm) { if (rm.removed) { alert(T('This person was removed from this group earlier, so WhatsApp refuses the public link. The add / personal invitation is used instead.')); i.disabled = false; a = i.parentNode.querySelector('[data-ag]'); a.click(); return; }
          sendLink(); }); return; }
        function sendLink() { if (!confirm(T('Send the group invite link to this person in the chat?'))) { i.disabled = false; return; } WI.post('group_invite_send', { jid: jid, group: i.dataset.agi }).then(function (r) { alert(r.result === 'invited' ? '✓ ' + T('Personal invitation sent') : (r.result === 'added' ? '✓ ' + T('Added to the group') : (r.ok ? '✓ ' + T('Invite link sent') : (r.error || T('Failed'))))); }); }
        if (!a) return;
        WI.post('group_add', { jid: jid, group: a.dataset.ag }).then(function (r) {
          var m = { added: '✓ ' + T('Added to the group'), invited: '✓ ' + T('Their privacy settings do not allow adding them directly, so WhatsApp\'s personal group invitation was sent to them in the chat. They join with one tap (it works even if they were removed before).'), already: T('Already in this group'), privacy: T('Their privacy settings do not allow adding them. Send them the invite link instead.'), left_recently: T('They left this group recently; WhatsApp does not allow adding them again yet. Send the invite link.'), blocked: T('WhatsApp refused (they blocked this number).') }[r.result] || (r.error || T('Failed'));
          alert(m); if (r.result === 'privacy' || r.result === 'left_recently') a.parentNode.querySelector('[data-agi]').classList.add('primary'); });
      };
    }
    function drawTools(jid) {
      var el = document.getElementById('thread-tools'); if (!el) return;
      var ls = meta.chat_labels[jid] || [], a = meta.assign[jid], rm = remindersFor(jid), v = meta.viewing[jid];
      var mine = a && a.to === meta.me;
      el.innerHTML = (!CAN('labels') ? '' : '<div class="tool-menu"><button type="button" class="chip-btn sm" data-tool="labels">🏷️ ' + (ls.length ? ls.map(function (id) { var l = labelById(id); return l ? esc(l.name) : ''; }).join(', ') : T('Labels')) + '</button><div class="menu" hidden>'
        + meta.labels.map(function (l) { return '<label class="check"><input type="checkbox" data-label-t="' + esc(l.id) + '" ' + (ls.indexOf(l.id) !== -1 ? 'checked' : '') + '> <span class="lbl" style="--c:' + esc(l.color) + '">' + esc(l.name) + '</span></label>'; }).join('')
        + '<button type="button" class="link-btn" data-tool="manage-labels">⚙️ ' + T('Manage labels') + '</button></div></div>')
        + (!CAN('assign') ? (!a ? '<button type="button" class="chip-btn sm" data-tool="take">✋ ' + T('Take this chat') + '</button>' : (mine ? '<button type="button" class="chip-btn sm" data-tool="giveback">↩️ ' + T('Give it back') + '</button>' : '<span class="chip-btn sm">👤 ' + esc(userName(a.to)) + '</span>')) : '<select class="asg-sel" data-tool="assign"><option value="">👤 ' + T('Unassigned') + '</option>' + meta.users.map(function (u) { return '<option value="' + esc(u.email) + '" ' + (a && a.to === u.email ? 'selected' : '') + '>👤 ' + esc(u.email === meta.me ? T('Me') + ' (' + u.name + ')' : u.name) + '</option>'; }).join('') + '</select>')
        + (CAN('notes') ? '<button type="button" class="chip-btn sm" data-tool="remind">⏰ ' + (rm.length ? esc(when(rm[0].at)) : T('Remind me')) + '</button>' : '')
        + (CAN('invoices') ? '<button type="button" class="chip-btn sm" data-tool="invoices">🧾 ' + T('Invoices') + '</button>' : '')
        + '<button type="button" class="chip-btn sm" data-tool="summary">🧠 ' + T('Summary') + '</button>'
        + (v && v.length ? '<span class="viewing big">👀 ' + esc(v.map(userName).join(', ')) + ' ' + T('is viewing this chat') + '</span>' : '');
    }
    // Only the newest messages are loaded (PAGE at a time); "older messages" at the top loads more, keeping your place.
    var lastSig = '', PAGE = 300, shown = PAGE, keepPos = false;
    function loadThread(jid, scroll) {
      api('thread', { jid: jid, limit: shown }).then(function (r) {
        if (jid !== open) return;
        var rows = r.rows || [], sig = rows.length + ':' + ((rows[rows.length - 1] || {}).id || '') + ':' + ((rows[rows.length - 1] || {}).status || '');
        if (!scroll && !keepPos && sig === lastSig) return;
        var nearBottom = msgsEl.scrollHeight - msgsEl.scrollTop - msgsEl.clientHeight < 120, oldH = msgsEl.scrollHeight, oldTop = msgsEl.scrollTop;
        lastSig = sig;
        var day = '', html = r.more ? '<div class="th-older-w"><button type="button" class="chip-btn sm th-older">⬆️ ' + T('Older messages') + '</button></div>' : '';
        (r.rows || []).forEach(function (m) {
          var d = when(m.time, true); if (d !== day) { html += '<div class="day">' + esc(d) + '</div>'; day = d; }
          var via = m.dir === 'out' && m.via === 'bot' ? T('Bot') : (m.dir === 'out' && m.via === 'alert' ? T('Alert') : (m.dir === 'out' && m.via === 'inbox' ? '💻 ' + (m.by || T('You')) : (m.group && m.from ? (m.from_name || '+' + m.from) : '')));
          if ((m.kind || 'text') === 'text' && !m.text && !m.attachment) return; // empty system message
          var file = '';
          if (m.attachment && /^inbox_uploads\//.test(m.attachment)) file = '?api=media&f=' + encodeURIComponent(m.attachment.split('/').pop());
          else if (m.attachment && /^inbox_media\//.test(m.attachment)) { var pp = m.attachment.split('/'); file = '?api=media&a=' + encodeURIComponent(pp[1]) + '&f=' + encodeURIComponent(pp[2]); }
          var media = '';
          if (file && m.kind === 'image') media = '<a href="' + file + '" target="_blank" rel="noopener"><img class="thumb" src="' + file + '" alt="" loading="lazy"></a>';
          else if (file && m.kind === 'sticker') media = '<img class="sticker" src="' + file + '" alt="" loading="lazy">';
          else if (file && (m.kind === 'audio' || m.kind === 'voice')) media = '<audio controls preload="metadata" src="' + file + '"></audio>';
          else if (file && m.kind === 'document' && /\.(mp3|m4a|ogg|opus|wav|aac)$/i.test(file)) media = '<audio controls preload="metadata" src="' + file + '"></audio><div class="doc-note">📄 ' + esc(m.file_name || '') + ' · ' + T('sent as a file') + '</div>';
          else if (file && m.kind === 'video') media = '<video controls preload="metadata" class="thumb" src="' + file + '"></video>';
          else if (file && m.kind === 'document') media = '<a class="doc" href="' + file + '" target="_blank" rel="noopener">📄 ' + esc(m.file_name || T('📄 File')) + '</a>';
          if (m.kind === 'reaction') { html += '<div class="reaction ' + (m.dir === 'out' ? 'out' : 'in') + '">' + esc(m.text || '👍') + ' <small>' + T('reacted') + '</small></div>'; return; }
          var label = kindLabel(m.kind);
          var body = media + (m.text ? (label && m.kind !== 'text' && !media ? '<span class="kind">' + esc(label) + '</span>' : '') + '<div class="t" dir="auto">' + esc(m.text) + '</div>' : (media ? '' : (label ? '<span class="kind">' + esc(label) + '</span>' : '<span class="muted">…</span>')));
          if (m.id) msgById[m.id] = m;
          html += '<div class="b ' + (m.dir === 'out' ? 'out' : 'in') + (m.deleted ? ' del' : '') + '"' + (m.id ? ' data-id="' + esc(m.id) + '"' : '') + (m.attachment && /^(inbox_media\/[a-z0-9_-]+|inbox_uploads)\/[A-Za-z0-9_.-]+$/.test(m.attachment) ? ' data-att="' + esc(m.attachment) + '" data-cap="' + esc(m.text || '') + '"' : '') + '>' + (m.reaction ? '<span class="b-react">' + esc(m.reaction) + '</span>' : '') + (via ? '<span class="via">' + esc(via) + '</span>' : '') + body + (m.deleted ? '<br><i class="muted">' + T('Deleted') + '</i>' : '') + (m.edits && m.edits.length ? '<span class="ed" dir="auto">✏️ ' + T('edited') + ': ' + esc(m.edits[m.edits.length - 1].text) + '</span>' : '') + (m.revoked_at ? '<span class="rev">🗑️ ' + T('Deleted on WhatsApp — kept here') + '</span>' : '') + '<small>' + esc(new Date(m.time).toLocaleTimeString((AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB'), { hour: '2-digit', minute: '2-digit' })) + (m.dir === 'out' ? ' <span class="tick">' + tick(m.status) + '</span>' : '') + '</small></div>';
        });
        msgsEl.innerHTML = html || '<div class="empty">' + T('No messages.') + '</div>';
        if (keepPos) { msgsEl.scrollTop = msgsEl.scrollHeight - oldH + oldTop; keepPos = false; }
        else if (scroll || nearBottom) msgsEl.scrollTop = msgsEl.scrollHeight;
        if (focusMsg) { var fe = msgsEl.querySelector('.b[data-id="' + focusMsg.replace(/"/g, '') + '"]'); if (fe) { fe.scrollIntoView({ block: 'center' }); fe.classList.add('flash'); setTimeout(function () { fe.classList.remove('flash'); }, 2500); } focusMsg = ''; }
      });
    }
    msgsEl.addEventListener('click', function (e) { var b = e.target.closest('.th-older'); if (!b || !open) return; b.disabled = true; b.textContent = '⏳'; shown += PAGE; keepPos = true; loadThread(open, false); });
    var uploading = false;
    // Live: pull new events (messages, typing, ticks) every 2 s (6 s in a background tab).
    var liveSeq = null, lastLive = 0, beepCtx = null;
    function beep() { try { beepCtx = beepCtx || new (window.AudioContext || window.webkitAudioContext)(); var o = beepCtx.createOscillator(), g = beepCtx.createGain(); o.frequency.value = 880; g.gain.value = 0.06; o.connect(g); g.connect(beepCtx.destination); o.start(); o.stop(beepCtx.currentTime + 0.12); } catch (e) {} }
    function chatOf(jid) { return chats.filter(function (x) { return x.jid === jid; })[0]; }
    function live() {
      if (uploading) return;
      api('events', liveSeq === null ? {} : { since: liveSeq }).then(function (r) {
        if (!r || !r.ok) return;
        if (liveSeq === null || r.reset) { liveSeq = r.seq; if (r.reset && open) loadThread(open, false); return; }
        liveSeq = r.seq;
        var redrawList = false, reloadOpen = false, needChats = false, ding = false;
        (r.events || []).forEach(function (ev) {
          if (ev.type === 'msg') {
            var m = ev.row || {}, jid = m.jid;
            if (!jid) return;
            if (jid === open) reloadOpen = true;
            if (m.dir !== 'in' && m.dir !== 'out') return;
            var c = chatOf(jid);
            if (!c) { needChats = true; } else {
              c.last = { time: m.time, dir: m.dir, text: m.text || '', kind: m.kind || 'text' }; c.count = (c.count || 0) + 1;
              chats.splice(chats.indexOf(c), 1); chats.unshift(c); redrawList = true;
            }
            delete typing[jid];
            if (m.dir === 'in' && (jid !== open || document.hidden)) { unread[jid] = (unread[jid] || 0) + 1; saveUnread(); ding = true; }
          } else if (ev.type === 'hidden') { // a colleague deleted a message or the whole chat
            if (ev.jid === open) { if (ev.chat) location.reload(); else { var hb = msgsEl.querySelector('.b[data-id="' + String(ev.id).replace(/"/g, '') + '"]'); if (hb) hb.remove(); } }
          } else if (ev.type === 'presence') {
            var on = ev.state === 'composing' || ev.state === 'recording';
            if (on) typing[ev.jid] = Date.now() + 7000; else delete typing[ev.jid];
            redrawList = true;
            if (ev.jid === open) setTyping(on ? (ev.state === 'recording' ? T('recording…') : T('typing…')) : '');
          } else if (ev.type === 'status' && ev.jid === open) {
            var el = msgsEl.querySelector('.b[data-id="' + ev.id + '"] .tick'); if (el) el.innerHTML = tick(ev.status);
          }
        });
        if (needChats) api(view.all ? 'chats_all' : 'chats').then(function (x) { if (x.ok) { chats = x.chats || []; drawList(); } });
        else if (redrawList) drawList();
        if (reloadOpen) loadThread(open, false);
        if (ding) beep();
      }).catch(function () {});
    }
    function setTyping(txt) { var t = document.getElementById('typing-h'); if (t) { t.textContent = txt; t.hidden = !txt; } }
    setInterval(function () { var now = Date.now(); if (now - lastLive >= (document.hidden ? 6000 : 2000)) { lastLive = now; live(); } }, 1000);
    setInterval(function () { var ch = false; for (var k in typing) if (typing[k] < Date.now()) { delete typing[k]; ch = true; if (k === open) setTyping(''); } if (ch) drawList(); }, 3000);
    updateTitle();
    setInterval(function () { if (open && !document.hidden && !uploading) loadThread(open, false); }, 30000);
    var infoEl = document.getElementById('info-panel');
    function money(v, cur) { return Number(v || 0).toLocaleString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB') + ' ' + (cur || (window.WI_SYS || {}).currency || ''); }
    function showInfo(jid) {
      infoEl.hidden = false;
      infoEl.innerHTML = '<div class="info-h"><b>' + T('Contact info') + '</b><button type="button" class="link-btn" id="info-x">✕</button></div><div class="empty">' + T('Loading…') + '</div>';
      api('contact', { jid: jid }).then(function (c) {
        if (!c.ok) { infoEl.querySelector('.empty').textContent = c.error || T('Could not load'); return; }
        var name = (c.customer && c.customer.name) || c.name || (c.phone ? '+' + c.phone : T('Unknown'));
        var h = '<div class="info-h"><b>' + T('Contact info') + '</b><button type="button" class="link-btn" id="info-x">✕</button></div><div class="info-body">';
        h += '<div class="info-top">' + (c.photo ? '<a href="?api=photo&jid=' + encodeURIComponent(jid) + '" target="_blank" rel="noopener"><img class="info-photo" src="?api=photo&jid=' + encodeURIComponent(jid) + '" alt=""></a>' : '<div class="info-photo av" style="background:' + color(jid) + '">' + initial(name) + '</div>') + '<h3>' + esc(name) + '</h3>';
        h += '<div class="info-actions"><button type="button" class="chip-btn sm" data-copy="' + esc(name) + '">📋 ' + T('Copy name') + '</button>';
        if (c.phone) h += '<button type="button" class="chip-btn sm" data-copy="+' + esc(c.phone) + '">📋 ' + T('Copy number') + '</button>';
        if (c.photo) h += '<a class="chip-btn sm" href="?api=photo&dl=1&jid=' + encodeURIComponent(jid) + '">⬇️ ' + T('Download photo') + '</a>';
        if (c.phone) h += '<a class="chip-btn sm" target="_blank" rel="noopener" href="https://wa.me/' + esc(c.phone) + '">' + T('Open in WhatsApp') + '</a>';
        h += '</div>';
        if (c.phone) h += '<div class="info-phone" dir="ltr">+' + esc(c.phone) + '</div>';
        else if (!c.group) h += '<div class="muted small">' + T('Number hidden by WhatsApp (privacy). It appears once they message you directly.') + '</div>';
        h += '</div><dl class="kv">';
        function row(k, v) { if (v !== undefined && v !== null && v !== '') h += '<dt>' + k + '</dt><dd>' + v + '</dd>'; }
        if (c.group) { row(T('Members'), fmt(c.participants)); row(T('Admins'), fmt(c.admins)); row(T('Created'), c.created_at ? esc(when(c.created_at, true)) : ''); row(T('Description'), c.description ? esc(c.description) : ''); }
        else {
          row(T('About'), c.about ? esc(c.about) : ''); row(T('WhatsApp names'), (c.push_names || []).map(esc).join('، '));
          if (c.business) { row(T('Business'), esc([c.business.category, c.business.description].filter(Boolean).join(' — '))); row(T('Email'), esc(c.business.email)); row(T('Website'), c.business.website ? '<a href="' + esc(safeUrl(c.business.website)) + '" target="_blank" rel="noopener">' + esc(c.business.website) + '</a>' : ''); row(T('Address'), esc(c.business.address)); }
          row(T('Hidden ID'), c.lid ? '<span dir="ltr">' + esc(c.lid) + '</span>' : '');
        }
        row(T('Messages'), fmt(c.messages) + ' (' + fmt(c.received) + ' ' + T('received') + ' · ' + fmt(c.sent) + ' ' + T('sent') + ')');
        row(T('First message'), c.first_at ? esc(when(c.first_at, true)) : ''); row(T('Last message'), c.last_at ? esc(when(c.last_at, true)) : '');
        h += '</dl>';
        if (c.customer) {
          var cu = c.customer;
          h += '<div class="info-cust"><div class="info-cust-h">⭐ ' + T('Customer in the invoice system') + '</div><dl class="kv">';
          row(T('Name'), esc(cu.person)); row(T('Email'), esc(cu.email)); row(T('Invoices'), fmt(cu.count)); row(T('Total billed'), money(cu.total));
          h += '<dt>' + T('Balance due') + '</dt><dd class="' + (cu.due > 0 ? 'due' : 'paid') + '">' + money(cu.due) + '</dd></dl>';
          if (cu.invoices && cu.invoices.length) h += '<ul class="inv-list">' + cu.invoices.map(function (i) { return '<li><b>' + esc(i.reference) + '</b><span>' + esc(i.date) + '</span><span class="badge ' + (i.status === 'paid' ? 'ok' : 'warn') + '">' + esc(i.status) + '</span><span>' + money(i.total_due > 0 ? i.total_due : i.total, i.currency) + '</span></li>'; }).join('') + '</ul>';
          h += '<a class="chip-btn sm" target="_blank" rel="noopener" href="/index.php/billers/view/' + cu.id + '">' + T('Open customer') + ' ↗</a></div>';
        }
        h += '<div class="notes"><div class="info-cust-h">📝 ' + T('Notes') + '</div><div id="notes-list"></div><textarea id="note-text" rows="2" dir="auto" maxlength="1000" placeholder="' + T('Add a note about this number…') + '"></textarea><button type="button" class="btn primary sm" id="note-add">' + T('Save note') + '</button></div>';
        infoEl.innerHTML = h + '</div>';
        loadNotes(jid);
      });
    }
    var csrfN = app.getAttribute('data-csrf');
    function drawNotes(list) {
      var el = document.getElementById('notes-list'); if (!el) return;
      el.innerHTML = (list || []).map(function (n, i) { return '<div class="note"><div dir="auto">' + esc(n.text) + '</div><small>' + esc(n.by) + ' · ' + esc(when(n.time, true)) + ' <button type="button" class="link-btn" data-note-del="' + i + '">✕</button></small></div>'; }).join('') || '<div class="muted small">' + T('No notes yet.') + '</div>';
      var c = chatOf(open); if (c) { c.notes = (list || []).length; drawList(); }
    }
    function loadNotes(jid) { api('notes', { jid: jid }).then(function (r) { if (r.ok) drawNotes(r.notes); }); }
    function postNote(body) {
      return WI.post('notes&jid=' + encodeURIComponent(open), body).then(function (r) { if (r.ok) drawNotes(r.notes); else alert(r.error || ''); });
    }
    headEl.addEventListener('change', function (e) {
      if (e.target.matches('[data-tool="assign"]')) setMeta({ action: 'assign', jid: open, to: e.target.value }).then(function (r) { if (!r.ok) alert(r.error || ''); loadMeta(); });
      if (e.target.matches('[data-label-t]')) setMeta({ action: 'label_toggle', jid: open, id: e.target.getAttribute('data-label-t') }).then(loadMeta);
    });
    // Media: send from the library (🖼️) and forward a chat's photo / video / file (↪ on the message)
    var mlBtn = document.getElementById('ml-btn'); if (mlBtn) mlBtn.addEventListener('click', function () { if (!open) { alert(T('Choose a conversation first.')); return; } mlPick([open], function () { loadThread(open, true); }); });
    // ↪ on a message (hover on a computer, a tap on the phone): media go with their file, text as text
    var fwBtn = function (bub) { if (!bub || bub.querySelector('.b-fw') || bub.classList.contains('del') || !(bub.dataset.att || (msgById[bub.dataset.id] || {}).text)) return; var x = document.createElement('button'); x.type = 'button'; x.className = 'b-fw'; x.title = T('Forward'); x.textContent = '↪'; bub.appendChild(x); };
    msgsEl.addEventListener('click', function (e) { var f = e.target.closest('.b-fw'), bub = e.target.closest('.b');
      if (!f) { if (bub && !e.target.closest('a,button,audio,video,img')) fwBtn(bub); return; }
      if (bub.dataset.att) mlForward(bub.dataset.att, bub.dataset.cap || ''); else mlForward(null, (msgById[bub.dataset.id] || {}).text || ''); });
    msgsEl.addEventListener('mouseover', function (e) { fwBtn(e.target.closest('.b')); });
    headEl.addEventListener('click', function (e) { if (e.target.closest('#chat-media')) { var cc = chats.filter(function (x) { return x.jid === open; })[0] || {}; mlChatMedia(open, title(cc)); return; } });
    headEl.addEventListener('click', function (e) { if (e.target.closest('.th-back')) { if (history.state && history.state.chat) history.back(); else closeChat(); } });
    headEl.addEventListener('click', function (e) {
      var tl = e.target.closest('[data-tool]');
      if (tl && tl.getAttribute('data-tool') === 'labels') { var mn = tl.parentNode.querySelector('.menu'); mn.hidden = !mn.hidden; return; }
      if (tl && tl.getAttribute('data-tool') === 'manage-labels') { openLabels(); return; }
      if (tl && tl.getAttribute('data-tool') === 'remind') { openRemind(); return; }
      if (tl && tl.getAttribute('data-tool') === 'invoices') { openInvoices(); return; }
      if (tl && tl.getAttribute('data-tool') === 'summary') { summarize([open], true); return; }
      if (tl && (tl.getAttribute('data-tool') === 'take' || tl.getAttribute('data-tool') === 'giveback')) { setMeta({ action: 'assign', jid: open, to: tl.getAttribute('data-tool') === 'take' ? meta.me : '' }).then(function (r) { if (!r.ok) alert(r.error || ''); loadMeta(); }); return; }
      if (e.target.closest('#add-grp')) { addToGroup(open); return; }
      if (e.target.id === 'del-chat') { if (!confirm(T('Delete this chat? It is deleted from WhatsApp on this number and from the dashboard. New messages from this person will show again.'))) return;
        WI.post('delete_chat', { jid: open }).then(function (r) { if (!r.ok) { alert(r.error || T('Failed')); return; } alert('🧹 ' + T('Chat deleted') + (r.phone ? '' : ' · ' + T('removed from the dashboard only'))); location.reload(); }); return; }
      if (e.target.closest('.thread-tools')) return;
      if (e.target.id === 'exp-btn') { var m = headEl.querySelector('.export-menu'); m.hidden = !m.hidden; return; }
      if (e.target.closest('.export')) return;
      if (open && e.target.closest('.grow, .av')) { if (infoEl.hidden) showInfo(open); else infoEl.hidden = true; }
    });
    infoEl.addEventListener('click', function (e) {
      if (e.target.id === 'info-x') infoEl.hidden = true;
      var cp = e.target.closest('[data-copy]');
      if (cp) { var v = cp.getAttribute('data-copy'), done = function () { var o = cp.innerHTML; cp.innerHTML = '✓ ' + T('Copied'); setTimeout(function () { cp.innerHTML = o; }, 1500); };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(v).then(done); else { var ta = document.createElement('textarea'); ta.value = v; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); done(); } }
      if (e.target.id === 'note-add') { var tx = document.getElementById('note-text'); if (tx.value.trim()) postNote({ text: tx.value.trim() }).then(function () { tx.value = ''; }); }
      var nd = e.target.closest('[data-note-del]'); if (nd && confirm(T('Delete this note?'))) postNote({ delete: Number(nd.getAttribute('data-note-del')) });
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.export')) { var m = headEl.querySelector('.export-menu'); if (m) m.hidden = true; } });
    var comp = document.getElementById('composer');
    if (comp) {
      var box = document.getElementById('reply'), btn = document.getElementById('send-btn'), csrf = app.getAttribute('data-csrf');
      box.addEventListener('input', function () { box.style.height = 'auto'; box.style.height = Math.min(160, box.scrollHeight) + 'px'; });
      // Quick replies: "/" opens the list, arrows + Enter pick one; variables are filled for this chat.
      var qrPop = document.getElementById('qr-pop'), qrIdx = 0, qrItems = [];
      var nrm = function (t) { return String(t || '').replace(/[\u064B-\u065F\u0670\u0640]/g, '').replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim(); };
      // "/" or "#" (easier on an Arabic or phone keyboard) starts a shortcut; it may have several words ("/القسم السليماني").
      var QR_RE = /(?:^|\s)[\/#]([^\/#\n]{0,40})$/;
      function qrQuery() { var m = QR_RE.exec(box.value.slice(0, box.selectionStart)); return m ? m[1] : null; }
      function qrShow() {
        var q0 = qrQuery(); if (q0 === null) { qrPop.hidden = true; return; }
        var q = nrm(q0);
        // typed the whole name of exactly one shortcut → it turns into its text by itself
        // the typed words ("#تحصين-المكان", "#المكان") point to exactly one reply — by whole words of its name or of a trigger → it is written by itself
        if (q.length >= 3) { var wq = ' ' + q + ' ', hit = function (r) { return [r.title].concat(r.triggers || []).some(function (w) { return (' ' + nrm(w) + ' ').indexOf(wq) !== -1; }); };
          var ex = meta.replies.filter(function (r) { return nrm(r.title) === q; }), whole = meta.replies.filter(hit);
          if (ex.length === 1 && whole.length === 1) { qrUse(ex[0]); return; }
          if (!ex.length && whole.length === 1 && q.length >= 4) { qrUse(whole[0]); return; } }
        qrItems = meta.replies.filter(function (r) { return !q || nrm(r.title + ' ' + (r.triggers || []).join(' ') + ' ' + r.text).indexOf(q) !== -1; })
          .sort(function (a, b) { return (nrm(b.title).indexOf(q) === 0) - (nrm(a.title).indexOf(q) === 0); }).slice(0, 8);
        if (q0 && /\s$/.test(q0) && !qrItems.length) { qrPop.hidden = true; return; } // a normal "/" in a sentence
        if (qrIdx >= qrItems.length) qrIdx = 0;
        qrPop.innerHTML = (qrItems.length ? qrItems.map(function (r, i) { return '<div class="qr-it' + (i === qrIdx ? ' on' : '') + '" data-qr="' + i + '"><b>/' + esc(r.title) + '</b><span dir="auto">' + esc(r.text.slice(0, 90)) + '</span></div>'; }).join('') : '<div class="qr-it muted">' + T('No quick replies match.') + '</div>') + '<div class="qr-it manage" data-qr-manage>⚙️ ' + T('Manage quick replies') + '</div>';
        qrPop.hidden = false;
      }
      var qrBusy = false;
      function qrUse(r) { if (qrBusy) return; qrBusy = true;
        api('reply_fill', { jid: open, text: r.text }).then(function (x) {
          var before = box.value.slice(0, box.selectionStart).replace(/(?:^|\s)[\/#][^\/#\n]{0,40}$/, function (m0) { return /[\/#]/.test(m0.charAt(0)) ? '' : m0.charAt(0); });
          box.value = before + (x.ok ? x.text : r.text) + box.value.slice(box.selectionStart); qrPop.hidden = true; qrBusy = false; box.focus(); box.dispatchEvent(new Event('input'));
        }, function () { qrBusy = false; });
      }
      box.addEventListener('input', qrShow);
      // Smart suggestions: while typing, quick replies whose trigger words appear in the text are offered as chips above the box.
      var sug = document.createElement('div'); sug.className = 'qr-sug'; sug.hidden = true; document.getElementById('composer').parentNode.insertBefore(sug, document.getElementById('composer'));
      var sugHidden = {};
      function sugShow() { var v = box.value; if (!v.trim() || QR_RE.test(v)) { sug.hidden = true; return; }
        var t = ' ' + nrm(v) + ' ', hits = [], tail = nrm(v.slice(-30)).split(' ').slice(-3);
        meta.replies.forEach(function (r) { if (sugHidden[r.id] || v.indexOf(r.text.slice(0, 40)) !== -1) return; var best = 0;
          (r.triggers || []).concat([r.title]).forEach(function (w) { var n = nrm(w); if (n.length >= 3 && t.indexOf(' ' + n + ' ') !== -1 || (n.length >= 5 && t.indexOf(n) !== -1)) best = Math.max(best, n.length);
            // still typing: the last words are the start of a trigger word ("تحص" → "التحصين اليومي")
            for (var k = 0; k < tail.length; k++) { var p = tail.slice(k).join(' '); if (p.replace(/^ال/, '').length >= 3 && (n.indexOf(p) === 0 || n.indexOf(' ' + p) !== -1 || n.indexOf('ال' + p) === 0)) { best = Math.max(best, p.length - 1); break; } } });
          if (best) hits.push({ r: r, s: best }); });
        hits.sort(function (a, b) { return b.s - a.s; }); hits = hits.slice(0, 4);
        if (!hits.length) { sug.hidden = true; return; }
        sug.innerHTML = '<span class="muted small">💡 ' + T('Suggested replies') + '</span>' + hits.map(function (h) { return '<button type="button" class="chip-btn sm" data-sug="' + esc(h.r.id) + '" title="' + esc(h.r.text.slice(0, 300)) + '">⚡ ' + esc(h.r.title) + '</button>'; }).join('') + '<button type="button" class="link-btn" data-sugx>✕</button>';
        sug.hidden = false; }
      box.addEventListener('input', sugShow);
      sug.addEventListener('mousedown', function (e) { e.preventDefault();
        if (e.target.closest('[data-sugx]')) { meta.replies.forEach(function (r) { sugHidden[r.id] = 1; }); sug.hidden = true; return; }
        var b = e.target.closest('[data-sug]'); if (!b) return; var r = meta.replies.filter(function (x) { return x.id === b.getAttribute('data-sug'); })[0];
        api('reply_fill', { jid: open, text: r.text }).then(function (x) { box.value = box.value.replace(/\s+$/, '') + (box.value.trim() ? '\n' : '') + (x.ok ? x.text : r.text); sugHidden[r.id] = 1; sug.hidden = true; box.focus(); box.dispatchEvent(new Event('input')); }); });
      box.addEventListener('keydown', function (e) {
        if (!qrPop.hidden && qrItems.length) {
          if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); qrIdx = (qrIdx + (e.key === 'ArrowDown' ? 1 : qrItems.length - 1)) % qrItems.length; qrShow(); return; }
          if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); qrUse(qrItems[qrIdx]); return; }
        }
        if (e.key === 'Escape') qrPop.hidden = true;
      });
      qrPop.addEventListener('mousedown', function (e) { e.preventDefault(); var it = e.target.closest('[data-qr]'); if (it) qrUse(qrItems[Number(it.getAttribute('data-qr'))]); if (e.target.closest('[data-qr-manage]')) openReplies(); });
      document.getElementById('qr-btn').addEventListener('click', function () { if (!open) { alert(T('Choose a conversation first.')); return; } box.value = (box.value ? box.value + ' ' : '') + '/'; box.focus(); qrShow(); });
      function drawReplies() {
        var q = (document.getElementById('qr-q').value || '').trim().toLowerCase(), cur = document.getElementById('qr-id').value;
        var list = meta.replies.filter(function (r) { return !q || (r.title + ' ' + r.text + ' ' + (r.triggers || []).join(' ')).toLowerCase().indexOf(q) !== -1; });
        document.getElementById('qr-count').textContent = fmt(meta.replies.length) + ' ' + T('quick replies');
        document.getElementById('qr-list').innerHTML = list.map(function (r) { return '<div class="qr-row' + (r.id === cur ? ' on' : '') + '" data-qr-edit="' + esc(r.id) + '"><div class="qr-row-t"><b dir="auto">/' + esc(r.title) + '</b>' + (r.keyword ? '<span class="qr-kwb" title="' + esc(T('Members\' keyword')) + '">#' + esc(r.keyword) + '</span>' : '') + '<button type="button" class="icon-btn qr-del" data-qr-del="' + esc(r.id) + '" title="' + T('Delete') + '">🗑</button></div>'
          + '<div class="qr-prev muted small" dir="auto">' + esc(r.text) + '</div>' + ((r.triggers || []).length ? '<div class="qr-chips">' + r.triggers.slice(0, 6).map(function (t) { return '<span dir="auto">' + esc(t) + '</span>'; }).join('') + '</div>' : '') + '</div>'; }).join('')
          || '<div class="muted small">' + (q ? T('Nothing found.') : T('No quick replies yet.')) + '</div>';
        document.getElementById('qr-mode').textContent = cur ? T('Edit quick reply') : T('New quick reply');
      }
      document.getElementById('qr-q').addEventListener('input', drawReplies);
      if (document.getElementById('qr-import')) document.getElementById('qr-import').addEventListener('click', function () {
        if (!confirm(T('Copy your quick replies to this client\'s number? Replies with the same name are skipped; the client can edit them afterwards.'))) return;
        setMeta({ action: 'reply_import', jid: open || 'x' }).then(function (x) { if (!x.ok) { alert(x.error || T('Failed')); return; } meta.replies = x.replies; drawReplies(); alert('📥 ' + x.added + ' ' + T('quick replies copied')); }); });
      function openReplies() { qrPop.hidden = true; ['qr-id', 'qr-title', 'qr-text', 'qr-trig', 'qr-kw', 'qr-q'].forEach(function (k) { document.getElementById(k).value = ''; }); document.getElementById('qr-mined').innerHTML = ''; drawReplies(); document.getElementById('qr-dlg').showModal(); }
      document.getElementById('qr-list').addEventListener('click', function (e) {
        var dl = e.target.closest('[data-qr-del]'), ed = dl ? null : e.target.closest('[data-qr-edit]');
        if (ed) { var r = meta.replies.filter(function (x) { return x.id === ed.getAttribute('data-qr-edit'); })[0]; document.getElementById('qr-id').value = r.id; document.getElementById('qr-title').value = r.title; document.getElementById('qr-trig').value = (r.triggers || []).join('، '); document.getElementById('qr-kw').value = r.keyword || ''; document.getElementById('qr-text').value = r.text; drawReplies(); document.getElementById('qr-title').focus(); }
        if (dl && confirm(T('Delete this quick reply?'))) setMeta({ action: 'reply_delete', id: dl.getAttribute('data-qr-del') }).then(function (x) { if (x.ok) { meta.replies = x.replies; drawReplies(); } });
      });
      document.getElementById('qr-new').addEventListener('click', function () { document.getElementById('qr-id').value = ''; document.getElementById('qr-kw').value = ''; document.getElementById('qr-title').value = ''; document.getElementById('qr-text').value = ''; document.getElementById('qr-trig').value = ''; drawReplies(); document.getElementById('qr-title').focus(); });
      // Messages the team keeps sending → one-click quick replies (with suggested trigger words)
      var MINED = [];
      document.getElementById('qr-mine').addEventListener('click', function () { var b = this, out = document.getElementById('qr-mined'); b.disabled = true; out.innerHTML = '<div class="muted small">⏳ ' + T('Looking for messages the team sends again and again…') + '</div>';
        api('qr_mine').then(function (x) { b.disabled = false; if (!x.ok) { out.innerHTML = '⚠️ ' + esc(x.error); return; } MINED = x.items;
          out.innerHTML = x.items.length ? '<div class="qr-mined">' + x.items.map(function (m, i) { return '<div class="qr-row"><div><b>⚡ ' + esc(m.title) + '</b> <span class="tag">🔁 ' + m.count + ' ' + T('times') + '</span><div class="muted small" dir="auto">' + esc(m.text.slice(0, 160)) + '…</div><div class="small">💡 ' + esc((m.triggers || []).join('، ')) + '</div></div><button type="button" class="btn ghost sm" data-mine-add="' + i + '">➕ ' + T('Add') + '</button></div>'; }).join('') + '</div>' : '<div class="muted small">' + T('No long message was sent 3 times or more in the last 3 months.') + '</div>'; }, function () { b.disabled = false; out.innerHTML = '⚠️'; }); });
      document.getElementById('qr-mined').addEventListener('click', function (e) { var b = e.target.closest('[data-mine-add]'); if (!b) return; var m = MINED[+b.getAttribute('data-mine-add')];
        document.getElementById('qr-id').value = ''; document.getElementById('qr-title').value = m.title.slice(0, 60); document.getElementById('qr-text').value = m.text.slice(0, 2000); document.getElementById('qr-trig').value = (m.triggers || []).join('، ');
        drawReplies(); document.getElementById('qr-title').focus(); });
      document.getElementById('qr-save').addEventListener('click', function () {
        setMeta({ action: 'reply_save', id: document.getElementById('qr-id').value, title: document.getElementById('qr-title').value, text: document.getElementById('qr-text').value, triggers: document.getElementById('qr-trig').value, keyword: document.getElementById('qr-kw').value })
          .then(function (x) { if (x.ok) { meta.replies = x.replies; drawReplies(); document.getElementById('qr-id').value = ''; document.getElementById('qr-title').value = ''; document.getElementById('qr-text').value = ''; } else alert(x.error || ''); });
      });
      box.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && qrPop.hidden) { e.preventDefault(); comp.requestSubmit(); } });
      var fileIn = document.getElementById('attach'), prev = document.getElementById('attach-preview'), picked = null;
      fileIn.addEventListener('change', function () {
        picked = fileIn.files[0] || null;
        if (picked && picked.size > 70 * 1048576) { alert(T('File is larger than 70 MB.')); picked = null; fileIn.value = ''; }
        prev.hidden = !picked;
        prev.innerHTML = picked ? '📎 <b>' + esc(picked.name) + '</b> · ' + Math.round(picked.size / 1024) + ' KB <button type="button" class="link-btn" id="attach-x">✕</button><span class="muted small"> ' + T('Text is sent as the caption.') + (picked && picked.size > 16 * 1048576 ? ' ' + T(/^audio\//.test(picked.type) ? 'Large audio is tried as audio first; if WhatsApp refuses, it is sent as a file.' : 'Large photos (over 16 MB) are sent as files.') : '') + '</span>' : '';
        box.focus();
      });
      prev.addEventListener('click', function (e) { if (e.target.id === 'attach-x') { picked = null; fileIn.value = ''; prev.hidden = true; } });
      function pick(file) {
        if (!file) return;
        if (!open) { alert(T('Choose a conversation first.')); return; }
        var dt = new DataTransfer(); dt.items.add(file); fileIn.files = dt.files; fileIn.dispatchEvent(new Event('change'));
      }
      var thread = document.querySelector('.thread');
      thread.addEventListener('dragover', function (e) { if (e.dataTransfer && [].indexOf.call(e.dataTransfer.types, 'Files') !== -1) { e.preventDefault(); thread.classList.add('drop'); } });
      thread.addEventListener('dragleave', function (e) { if (!thread.contains(e.relatedTarget)) thread.classList.remove('drop'); });
      thread.addEventListener('drop', function (e) { e.preventDefault(); thread.classList.remove('drop'); pick(e.dataTransfer.files[0]); });
      box.addEventListener('paste', function (e) { var it = [].filter.call((e.clipboardData || {}).items || [], function (x) { return x.kind === 'file'; })[0]; if (it) { e.preventDefault(); pick(it.getAsFile()); } });
      fileIn.addEventListener('click', function (e) { if (!open) { e.preventDefault(); alert(T('Choose a conversation first.')); } });
      comp.addEventListener('submit', function (e) {
        e.preventDefault();
        if (picked && open && !btn.disabled) {
          var fd = new FormData(); fd.append('file', picked); fd.append('jid', open); fd.append('text', box.value.trim()); fd.append('_csrf', csrf);
          btn.disabled = true; btn.textContent = '…';
          var pend = document.createElement('div'); pend.className = 'b out pending'; pend.textContent = '📎 ' + picked.name; pend.insertAdjacentHTML('beforeend', '<small>' + T('Sending…') + '</small>');
          msgsEl.appendChild(pend); msgsEl.scrollTop = msgsEl.scrollHeight;
          var xhr = new XMLHttpRequest();
          uploading = true;
          prev.hidden = false;
          prev.innerHTML = '<div class="up-row"><span>📤 <b>' + esc(picked.name) + '</b></span><span class="up-pct">0%</span></div><div class="up-bar"><span style="width:0%"></span></div><div class="muted small up-msg">' + T('Uploading…') + '</div>';
          xhr.open('POST', '?api=send_media'); xhr.setRequestHeader('X-CSRF', csrf);
          xhr.upload.onprogress = function (ev) {
            if (!ev.lengthComputable) return;
            var pc = Math.round(ev.loaded / ev.total * 100);
            prev.querySelector('.up-bar span').style.width = pc + '%'; prev.querySelector('.up-pct').textContent = pc + '%';
            pend.querySelector('small').textContent = T('Uploading…') + ' ' + pc + '%';
            if (pc >= 100) prev.querySelector('.up-msg').textContent = T('Uploaded — sending on WhatsApp…');
          };
          xhr.onloadend = function () { uploading = false; };
          xhr.onload = function () {
            var r = null; try { r = JSON.parse(xhr.responseText); } catch (err) { r = { ok: false, error: 'HTTP ' + xhr.status + (xhr.status === 403 ? ' — ' + T('blocked before reaching the server (firewall/Cloudflare)') : (xhr.status === 413 ? ' — ' + T('file too large for the server') : '')) }; }
            uploading = false;
            if (r.ok) { pend.remove(); picked = null; fileIn.value = ''; prev.hidden = true; box.value = ''; loadThread(open, true); }
            else { pend.classList.add('failed'); pend.querySelector('small').textContent = T('Not sent:') + ' ' + (r.error || ''); prev.querySelector('.up-msg').textContent = T('Not sent:') + ' ' + (r.error || ''); prev.classList.add('bad'); }
            btn.disabled = false; btn.textContent = '➤';
          };
          xhr.onerror = function () { pend.classList.add('failed'); pend.querySelector('small').textContent = T('Not sent:') + ' ' + T('network error'); btn.disabled = false; btn.textContent = '➤'; };
          xhr.send(fd);
          return;
        }
        var text = box.value.trim(); if (!text || !open || btn.disabled) return;
        btn.disabled = true; btn.textContent = '…';
        var pending = document.createElement('div'); pending.className = 'b out pending'; pending.textContent = text; pending.insertAdjacentHTML('beforeend', '<small>' + T('Sending…') + '</small>');
        msgsEl.appendChild(pending); msgsEl.scrollTop = msgsEl.scrollHeight; box.value = ''; box.style.height = 'auto';
        fetch('?api=send', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: JSON.stringify({ jid: open, text: text, quote_id: window.WI_QUOTE || undefined }) })
          .then(wiSafeJson, wiNetFail)
          .then(function (r) {
            if (r.ok && r.queued) { pending.classList.add('queued'); pending.querySelector('small').textContent = '⏳ ' + T('Waiting — sent when WhatsApp is back'); window.WI_QUOTE = null; accState(); } // offline: kept in the outbox
            else if (r.ok) { pending.remove(); loadThread(open, true); window.WI_QUOTE = null; document.dispatchEvent(new Event('wi-sent')); }
            else { pending.classList.add('failed'); pending.querySelector('small').textContent = T('Not sent:') + ' ' + (r.error || ''); box.value = text; }
          })
          .catch(function () { pending.classList.add('failed'); box.value = text; })
          .finally(function () { btn.disabled = false; btn.textContent = '➤'; });
      });
    }
    var curAcc = app.getAttribute('data-acc') || '';
    function loadChats() { return api(view.all ? 'chats_all' : 'chats').then(function (r) {
      if (!r.ok) { listEl.innerHTML = '<div class="empty">' + esc(r.error || T('Could not load')) + '</div>'; return; }
      chats = r.chats || []; drawList(); return r; }); }
    loadChats().then(function (r) { if (!r) return; if (open) openChat(open);
      else { var dj = new URLSearchParams(location.search).get('jid'); if (dj) openChat(dj); } // links from other pages (team activity, cases…)
    });
    listEl.addEventListener('click', function (e) { var c = e.target.closest('.c'); if (!c) return; var a = c.getAttribute('data-acc');
      if (a && a !== curAcc) { location.href = '?p=inbox&acc=' + encodeURIComponent(a) + '&jid=' + encodeURIComponent(c.getAttribute('data-jid')); return; } // a chat of another number opens on that number
      openChat(c.getAttribute('data-jid')); });
    document.getElementById('chat-q').addEventListener('input', drawList);
    // ---- AI summary & category
    function showSummary(jid, x) {
      var box = document.getElementById('sum-box'); if (jid !== open) return;
      if (!x) { box.hidden = true; return; }
      box.hidden = false; box.classList.remove('open'); box.title = T('Click to open / close the summary'); var th = document.getElementById('thread-h'); if (th) box.style.top = (th.offsetTop + th.offsetHeight + 8) + 'px';
      box.innerHTML = x.ok === false ? '<span class="muted">' + (x.error === T('Summarising…') ? '⏳ ' : '⚠️ ') + esc(x.error || '') + '</span>'
        : '<div class="row" style="justify-content:space-between;align-items:flex-start"><div><b>🧠 ' + T('Summary') + '</b> <span class="cat cat-' + esc(x.category) + '">' + esc(CAT[x.category] ? T(CAT[x.category]) : x.category) + '</span> <span class="cat sent-' + esc(x.sentiment) + '">' + esc(T(x.sentiment)) + '</span></div><button type="button" class="link-btn" id="sum-x">✕</button></div>'
          + '<div class="sum-t" dir="auto">' + esc(x.summary) + '</div>' + (x.next_action ? '<div class="sum-n" dir="auto">➡️ ' + esc(x.next_action) + '</div>' : '') + '<small class="muted">' + esc(when(x.at, true)) + ' · ' + esc(x.model || '') + '</small>';
    }
    var csrfS = app.getAttribute('data-csrf');
    function summarize(jids, show) {
      if (show) showSummary(open, { ok: false, error: T('Summarising…') });
      return fetch('?api=summarize', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrfS }, body: JSON.stringify({ jids: jids }) })
        .then(wiSafeJson, wiNetFail)
        .then(function (r) { if (r.ok) { for (var j in r.results) { if (r.results[j].ok) meta.summaries[j] = { category: r.results[j].category, needs_reply: r.results[j].needs_reply, sentiment: r.results[j].sentiment }; if (show && j === open) showSummary(j, r.results[j]); } drawList(); } return r; });
    }
    document.getElementById('msgs').parentNode.addEventListener('click', function (e) { if (e.target.id === 'sum-x') { document.getElementById('sum-box').hidden = true; return; }
      var sb = e.target.closest('#sum-box'); if (sb && !e.target.closest('a,button')) sb.classList.toggle('open'); }); // one line until clicked
    if (CAN('delete_chat') || CAN('delete_msg')) { // recycle bin: deleted chats and messages can be put back
      var cbar = document.querySelector('.classify-bar'), tb = document.createElement('button'); tb.type = 'button'; tb.className = 'chip-btn sm'; tb.textContent = '🗑️ ' + T('Deleted'); cbar.insertBefore(tb, cbar.firstChild);
      var brief = function (m) { return m ? (m.text ? esc(m.text) : '📎 ' + esc(T(m.kind))) : ''; };
      var trash = function () {
        var d = document.getElementById('trash-dlg'); if (!d) { d = document.createElement('dialog'); d.id = 'trash-dlg'; d.className = 'dlg trash-dlg'; document.body.appendChild(d); }
        d.innerHTML = '<div class="ml-head"><b>🗑️ ' + T('Deleted chats and messages') + '</b><button type="button" class="btn ghost sm" data-tx>✕</button></div><p class="muted small">' + T('Put back shows them in the dashboard again. A delete on the phone cannot be undone by WhatsApp.') + '</p><div class="tr-box muted">' + T('Loading…') + '</div>';
        if (!d.open) d.showModal();
        api('trash').then(function (r) { var box = d.querySelector('.tr-box'); if (!r.ok) { box.textContent = '⚠️ ' + (r.error || ''); return; }
          var row = function (x, isMsg) { var nm = (x.name || (isMsg && x.msg && x.msg.name) || '') + ' ' + String(x.jid).split('@')[0];
            return '<div class="tr-r">' + av(x.jid, nm) + '<div class="grow"><b dir="auto">' + esc(nm.trim()) + '</b>' + (isMsg ? '' : ' <small class="muted">💬 ' + fmt(x.count) + '</small>')
              + '<div class="muted small" dir="auto">' + brief(isMsg ? x.msg : x.last) + '</div><small class="muted">🗑️ ' + esc(when(x.time)) + (x.by ? ' · ' + esc(x.by) : '') + '</small></div>'
              + '<button type="button" class="btn sm" data-rs="' + esc(x.jid) + '" data-id="' + esc(isMsg ? x.id : '') + '">↩️ ' + T('Put back') + '</button></div>'; };
          box.className = 'tr-box';
          box.innerHTML = (r.chats.length || !r.messages.length ? '<h4>🧹 ' + T('Chats') + ' (' + fmt(r.chats.length) + ')</h4>' + (r.chats.map(function (x) { return row(x, false); }).join('') || '<p class="muted">' + T('Nothing deleted.') + '</p>') : '')
            + (r.messages.length ? '<h4>💬 ' + T('Messages') + ' (' + fmt(r.messages.length) + ')</h4>' + r.messages.map(function (x) { return row(x, true); }).join('') : '');
        });
        d.onclick = function (e) { if (e.target.closest('[data-tx]')) { d.close(); return; }
          var b = e.target.closest('[data-rs]'); if (!b) return; b.disabled = true;
          WI.post('trash_restore', { jid: b.dataset.rs, id: b.dataset.id }).then(function (x) { if (!x.ok) { b.disabled = false; alert(x.error || T('Failed')); return; } b.closest('.tr-r').remove(); d.dataset.changed = '1'; });
        };
        d.onclose = function () { if (d.dataset.changed) location.reload(); };
      };
      tb.addEventListener('click', trash);
    }
    document.getElementById('classify-btn').addEventListener('click', function () {
      var b = this, st = document.getElementById('classify-st');
      var todo = chats.filter(function (c) { return !c.group && !(meta.summaries && meta.summaries[c.jid]); }).slice(0, 40).map(function (c) { return c.jid; });
      if (!todo.length) { st.textContent = T('All recent chats are classified.'); return; }
      b.disabled = true; var done = 0;
      (function next() {
        if (!todo.length) { b.disabled = false; st.textContent = '✓ ' + done; return; }
        var batch = todo.splice(0, 4); st.textContent = T('Classifying…') + ' ' + done + ' / ' + (done + batch.length + todo.length);
        summarize(batch, false).then(function () { done += batch.length; next(); }).catch(function () { b.disabled = false; });
      })();
    });
    // ---- Invoices from the chat + overdue reminder for this number
    var invData = null;
    function money2(v, c) { return Number(v || 0).toLocaleString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB') + ' ' + (c || (window.WI_SYS || {}).currency || ''); }
    function openInvoices() {
      var body = document.getElementById('inv-body'); body.innerHTML = '<div class="empty">' + T('Loading…') + '</div>';
      document.getElementById('inv-dlg').showModal();
      api('chat_invoices', { jid: open }).then(function (r) {
        invData = r;
        if (!r.ok) { body.innerHTML = '<div class="empty">' + esc(r.error || '') + '</div>'; return; }
        if (!r.customer) { body.innerHTML = '<div class="empty">' + T('This number is not a customer in the invoice system.') + (r.phone ? '<br><span dir="ltr">+' + esc(r.phone) + '</span>' : '') + '</div>'; return; }
        var c = r.customer, rem = r.reminder || {};
        var h = '<div class="inv-head"><div><b>' + esc(c.name) + '</b><div class="muted small">' + esc(c.person) + ' · <span dir="ltr">+' + esc(r.phone) + '</span></div></div><div class="inv-due ' + (c.due > 0 ? 'bad' : 'ok') + '"><small>' + T('Balance due') + '</small><b>' + money2(c.due) + '</b></div></div>';
        h += '<label>' + T('Message with the invoice (optional)') + '<textarea id="inv-text" rows="2" dir="auto" placeholder="' + T('e.g. Here is your invoice, thank you') + '"></textarea></label>';
        h += '<table class="table zebra"><thead><tr><th>' + T('Invoice') + '</th><th>' + T('Date') + '</th><th>' + T('Total') + '</th><th>' + T('Due') + '</th><th>' + T('Status') + '</th><th></th></tr></thead><tbody>'
          + (r.invoices || []).map(function (i) { return '<tr><td><b>' + esc(i.reference) + '</b></td><td class="small">' + esc(i.date) + (i.date_due && i.date_due !== '0000-00-00' ? '<br><span class="muted">' + T('due') + ' ' + esc(i.date_due) + '</span>' : '') + '</td><td>' + money2(i.total, i.currency) + '</td><td>' + (i.total_due > 0 ? money2(i.total_due, i.currency) : '—') + '</td><td><span class="badge ' + (i.status === 'paid' ? 'ok' : (i.overdue ? 'bad' : 'warn')) + '">' + esc(i.overdue ? T('overdue') : i.status) + '</span></td><td><button class="btn primary sm" data-send-inv="' + i.id + '">📤 ' + T('Send') + '</button></td></tr>'; }).join('') + '</tbody></table>';
        h += '<div class="inv-rem"><h3>⏰ ' + T('Overdue reminder for this number') + '</h3>'
          + '<label class="check"><input type="checkbox" id="ir-on" ' + (rem.enabled ? 'checked' : '') + '> ' + T('Remind automatically when an invoice is overdue') + '</label>'
          + '<div class="row wrap"><label>' + T('Every') + '<select id="ir-days">' + [1, 2, 3, 5, 7, 10, 14, 30].map(function (d) { return '<option value="' + d + '" ' + ((rem.every_days || 3) === d ? 'selected' : '') + '>' + d + ' ' + T('days') + '</option>'; }).join('') + '</select></label>'
          + '<label>' + T('At') + '<select id="ir-hour">' + Array.from({ length: 13 }, function (_, k) { var hh = 9 + k; return '<option value="' + hh + '" ' + ((rem.hour || 11) === hh ? 'selected' : '') + '>' + hh + ':00</option>'; }).join('') + '</select></label>'
          + '<label>' + T('Max reminders') + '<select id="ir-max">' + [1, 2, 3, 5, 10].map(function (d) { return '<option ' + ((rem.max_times || 5) === d ? 'selected' : '') + '>' + d + '</option>'; }).join('') + '</select></label>'
          + '<label class="check" style="align-self:end"><input type="checkbox" id="ir-img" ' + (rem.with_image ? 'checked' : '') + '> ' + T('Attach the invoice image') + '</label></div>'
          + '<label>' + T('Message') + ' <small class="muted">{name} {invoice} {due} {count} {methods} {date}</small><textarea id="ir-msg" rows="4" dir="auto">' + esc(rem.message || '') + '</textarea></label>'
          + (rem.last_sent ? '<div class="muted small">' + T('Last sent') + ': ' + esc(when(rem.last_sent, true)) + ' · ' + fmt(rem.sent_count || 0) + ' ' + T('times') + (rem.last_result ? ' · ' + esc(rem.last_result) : '') + '</div>' : '')
          + '<button class="btn primary" id="ir-save">' + T('Save reminder settings') + '</button></div>';
        body.innerHTML = h;
      });
    }
    document.getElementById('inv-body').addEventListener('click', function (e) {
      var sb = e.target.closest('[data-send-inv]');
      if (sb) {
        if (!confirm(T('Send this invoice to the customer on WhatsApp?'))) return;
        sb.disabled = true; sb.textContent = T('Sending…');
        fetch('?api=send_invoice', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrfS }, body: JSON.stringify({ jid: open, invoice_id: Number(sb.dataset.sendInv), text: document.getElementById('inv-text').value }) })
          .then(wiSafeJson, wiNetFail).then(function (r) { sb.textContent = r.ok ? '✓ ' + T('Sent!') : '✕ ' + (r.error || ''); if (r.ok) loadThread(open, true); });
      }
      if (e.target.id === 'ir-save') {
        fetch('?api=inv_reminder', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrfS }, body: JSON.stringify({ jid: open, enabled: document.getElementById('ir-on').checked, every_days: Number(document.getElementById('ir-days').value), hour: Number(document.getElementById('ir-hour').value), max_times: Number(document.getElementById('ir-max').value), with_image: document.getElementById('ir-img').checked, message: document.getElementById('ir-msg').value }) })
          .then(wiSafeJson, wiNetFail).then(function (r) { e.target.textContent = r.ok ? '✓ ' + T('Saved') : (r.error || ''); if (r.ok && r.reminder) document.getElementById('ir-msg').value = r.reminder.message; });
      }
    });
    document.getElementById('label-filter').addEventListener('click', function (e) {
      var b = e.target.closest('[data-label]'); if (!b) return;
      labelFilter = labelFilter === b.getAttribute('data-label') ? '' : b.getAttribute('data-label');
      [].forEach.call(this.children, function (x) { x.classList.toggle('on', x.getAttribute('data-label') === labelFilter); }); drawList();
    });
    document.getElementById('due-box').addEventListener('click', function (e) {
      var o = e.target.closest('[data-open-r]'), d = e.target.closest('[data-done-r]');
      if (o) openChat(o.getAttribute('data-open-r'));
      if (d) setMeta({ action: 'remind_done', jid: open || 'x', id: d.getAttribute('data-done-r') }).then(loadMeta);
    });
    [].forEach.call(document.querySelectorAll('dialog [data-close]'), function (b) { b.addEventListener('click', function () { b.closest('dialog').close(); }); });
    // Reminders
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function toLocal(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
    function openRemind() { var d = new Date(Date.now() + 3600000); document.getElementById('rm-at').value = toLocal(d); document.getElementById('rm-note').value = ''; document.getElementById('rm-dlg').showModal(); }
    document.getElementById('rm-quick').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return; var d;
      if (b.dataset.in) d = new Date(Date.now() + Number(b.dataset.in) * 60000); else { d = new Date(); d.setDate(d.getDate() + 1); d.setHours(Number(b.dataset.tomorrow), 0, 0, 0); }
      document.getElementById('rm-at').value = toLocal(d);
    });
    document.getElementById('rm-save').addEventListener('click', function () {
      var c = chatOf(open) || {};
      setMeta({ action: 'remind', jid: open, at: new Date(document.getElementById('rm-at').value).toISOString(), note: document.getElementById('rm-note').value, name: title(c) })
        .then(function (r) { if (r.ok) { document.getElementById('rm-dlg').close(); loadMeta(); } else alert(r.error || ''); });
    });
    // Labels (admin)
    function openLabels() {
      document.getElementById('lb-list').innerHTML = meta.labels.map(function (l) { return '<div class="row" style="justify-content:space-between"><span class="lbl" style="--c:' + esc(l.color) + '">' + esc(l.name) + '</span><button class="btn danger sm" data-lb-del="' + esc(l.id) + '">' + T('Delete') + '</button></div>'; }).join('') || '<div class="muted small">' + T('No labels yet.') + '</div>';
      document.getElementById('lb-dlg').showModal();
    }
    document.getElementById('lb-add').addEventListener('click', function () { setMeta({ action: 'label_def', name: document.getElementById('lb-name').value, color: document.getElementById('lb-color').value }).then(function (r) { if (!r.ok) { alert(r.error || ''); return; } document.getElementById('lb-name').value = ''; loadMeta().then(openLabels); }); });
    document.getElementById('lb-list').addEventListener('click', function (e) { var b = e.target.closest('[data-lb-del]'); if (b && confirm(T('Delete this label?'))) setMeta({ action: 'label_def_delete', id: b.getAttribute('data-lb-del') }).then(function () { loadMeta().then(openLabels); }); });
    loadMeta();
    setInterval(function () { if (!document.hidden) loadMeta(); }, 20000);
    setInterval(function () { if (open && !document.hidden) setMeta({ action: 'viewing', jid: open }); }, 15000);
    document.getElementById('chat-filter').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); filter = b.getAttribute('data-f'); drawList();
    });
  }

  /* ---------------- Search ---------------- */
  if (page === 'search') {
    var sq = document.getElementById('sq'), res = document.getElementById('results'), timer;
    setTimeout(function () { var q0 = new URLSearchParams(location.search).get('q'); if (q0) { sq.value = q0; sq.dispatchEvent(new Event('input')); } }, 0); // from the top-bar search
    sq.addEventListener('input', function () {
      clearTimeout(timer);
      var q = sq.value.trim();
      if (q.length < 2) { res.innerHTML = '<div class="empty">' + T('Type at least 2 characters.') + '</div>'; return; }
      timer = setTimeout(function () {
        res.innerHTML = '<div class="empty">' + T('Searching…') + '</div>';
        api('search', { q: q }).then(function (r) {
          var re = new RegExp(q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
          var rows = (r.rows || []);
          res.innerHTML = rows.length ? rows.map(function (m) {
            var t = m.customer ? m.customer.name : (m.name || (m.phone ? '+' + m.phone : String(m.jid || '').split('@')[0]));
            return '<a class="r" href="?p=inbox#' + encodeURIComponent(m.jid || '') + '">' + av(m.jid, t) + '<div class="r-b"><div class="r-t"><b>' + esc(t) + (m.dir === 'out' ? ' <span class="badge">' + T('Sent') + '</span>' : '') + '</b><small class="muted">' + esc(when(m.time, true)) + '</small></div><p dir="auto">' + esc(m.text || '').replace(re, function (x) { return '<mark>' + x + '</mark>'; }) + '</p></div></a>';
          }).join('') : '<div class="empty">' + T('No results.') + '</div>';
        });
      }, 300);
    });
  }

  /* ---------------- WhatsApp link ---------------- */
  if (page === 'link') {
    var csrf = app.getAttribute('data-csrf'), box = document.getElementById('lk-qr'), badge = document.getElementById('lk-badge'), acts = document.getElementById('lk-actions');
    function post(name) { return WI.post(name); }
    function render(r) {
      if (!r || !r.ok) {
        var starting = r && /not running yet/i.test(r.error || '');
        badge.textContent = starting ? T('⏳ Starting…') : T('⚠️ Gateway not responding'); badge.className = 'badge warn';
        box.innerHTML = '<div class="empty big">' + (starting ? '⏳<br>' + T('Starting… the QR will appear within a minute.') : esc(r && r.error)) + '</div>'; acts.innerHTML = ''; return;
      }
      if (r.connected) {
        badge.textContent = T('🟢 Connected'); badge.className = 'badge ok';
        box.innerHTML = '<div class="empty big">✅<br>' + T('Connected') + (r.account ? ' — ' + esc(r.account) : '') + (r.phone ? '<div class="lk-phone" dir="ltr" style="font-size:20px;font-weight:700;margin-top:6px">📱 +' + esc(r.phone) + '</div>' : '') + '</div>';
        acts.innerHTML = '<button class="btn danger" id="lk-relink">' + T('Re-link to pull old chats') + '</button>';
      } else {
        badge.textContent = T('🟡 Waiting for scan'); badge.className = 'badge warn';
        box.innerHTML = r.qr ? '<img class="qr" alt="QR" src="' + esc(r.qr) + '"><p class="muted center small">' + T('WhatsApp → Linked devices → Link a device') + '</p>' : '<div class="empty">' + T('Preparing the QR…') + ' ' + esc(r.error || '') + '</div>';
        acts.innerHTML = '<button class="btn ghost" id="lk-restore">' + T('Restore previous session') + '</button>';
      }
    }
    function poll() { api('qr').then(render).catch(function () {}); }
    poll(); setInterval(poll, 4000);
    acts.addEventListener('click', function (e) {
      if (e.target.id === 'lk-relink') {
        if (!confirm(T('The bot stops until you scan the new QR from your phone. Continue?'))) return;
        e.target.disabled = true; post('relink').then(function () { setTimeout(poll, 1500); });
      }
      if (e.target.id === 'lk-restore') {
        e.target.disabled = true; post('restore').then(function (r) { if (!r.ok) alert(r.error || T('Could not restore')); setTimeout(poll, 3000); });
      }
    });
  }

  /* ---------------- Accounts ---------------- */
  if (page === 'accounts') {
    function loadAcc() {
      api('accounts').then(function (r) {
        (r.accounts || []).forEach(function (a) {
          var card = document.querySelector('form.acc[data-acc="' + a.id + '"]'); if (!card) return;
          var st = card.querySelector('[data-state]');
          st.textContent = !a.enabled ? T('⏸ Off') : (!a.running ? T('⏳ Starting…') : (a.connected ? T('🟢 Connected') : T('🟡 Scan QR')));
          st.className = 'badge ' + (a.connected ? 'ok' : (a.running ? 'warn' : ''));
          card.querySelector('[data-num]').textContent = a.number ? '+' + a.number + (a.wa_name ? ' · ' + a.wa_name : '') : T('Not linked yet');
          card.querySelector('[data-msgs]').textContent = fmt(a.messages);
          card.querySelector('[data-chats]').textContent = fmt(a.chats);
        });
      });
    }
    loadAcc(); setInterval(loadAcc, WIR(15000));
    var rb = document.querySelector('[data-reload-acc]'); if (rb) rb.addEventListener('click', loadAcc);
  }

  /* ---------------- Gateway & Guard ---------------- */
  if (page === 'gateway') {
    var gcsrf = app.getAttribute('data-csrf'), grid = document.getElementById('gw-grid');
    function dur(sec) { sec = Number(sec || 0); var d = Math.floor(sec / 86400), h = Math.floor(sec % 86400 / 3600), m = Math.floor(sec % 3600 / 60); return (d ? d + 'd ' : '') + (h ? h + 'h ' : '') + m + 'm'; }
    function ago(t) { if (!t) return '—'; var s = Math.round((Date.now() - Date.parse(t)) / 1000); return s < 90 ? s + 's' : Math.round(s / 60) + ' min'; }
    function loadGw() {
      api('gateway').then(function (r) {
        if (!r.ok) { grid.innerHTML = '<div class="card empty">' + esc(r.error || '') + '</div>'; return; }
        var g = r.guard || {};
        document.getElementById('g-main').textContent = ago(g.main_checked_at);
        document.getElementById('g-acc').textContent = ago(g.accounts_checked_at);
        var fresh = g.main_checked_at && Date.now() - Date.parse(g.main_checked_at) < 180000;
        var gs = document.getElementById('guard-state'); gs.textContent = fresh ? T('🟢 Active') : T('⚠️ Not reporting'); gs.className = 'badge ' + (fresh ? 'ok' : 'warn');
        grid.innerHTML = (r.accounts || []).map(function (a) {
          var h = a.health || {}, state = !a.enabled ? T('⏸ Off') : (!a.running ? (a.starting ? T('⏳ Starting…') : T('🔴 Stopped')) : (a.connected ? T('🟢 Connected') : (h.needs_qr ? T('🟡 Scan QR') : T('🟠 Reconnecting'))));
          var rows = a.running ? [
            [T('Number'), a.number ? '+' + a.number : '—'], [T('Uptime'), dur(h.uptime_seconds)], [T('Memory'), (h.memory_mb || 0) + ' MB'],
            [T('Last message'), h.last_message_at ? ago(h.last_message_at) + ' ' + T('ago') : '—'], [T('Messages since start'), fmt(h.messages_since_start)],
            [T('Reconnect attempts'), fmt(h.reconnect_attempts)], [T('Guard restarts (24 h)'), fmt(a.restarts_24h)],
            [T('Last error'), h.last_error ? esc(h.last_error) : '—'], ['PID', h.pid || '—']
          ] : [[T('Guard restarts (24 h)'), fmt(a.restarts_24h)]];
          return '<div class="card gw" data-id="' + esc(a.id) + '"><div class="card-h"><h2>📱 ' + esc(T(a.name)) + '</h2><span class="badge ' + (a.connected ? 'ok' : 'warn') + '">' + state + '</span></div>'
            + '<div class="pad"><dl class="kv">' + rows.map(function (x) { return '<dt>' + x[0] + '</dt><dd>' + x[1] + '</dd>'; }).join('') + '</dl>'
            + '<div class="row wrap" style="margin-top:12px"><button class="btn ghost sm" data-gw="reconnect">🔄 ' + T('Reconnect') + '</button><button class="btn danger sm" data-gw="restart">⟳ ' + T('Restart gateway') + '</button></div></div></div>';
        }).join('') || '<div class="card empty">' + T('No numbers you can manage.') + '</div>';
      });
    }
    loadGw(); setInterval(loadGw, WIR(10000));
    document.querySelector('[data-reload-gw]').addEventListener('click', loadGw);
    grid.addEventListener('click', function (e) {
      var b = e.target.closest('[data-gw]'); if (!b) return;
      var act = b.getAttribute('data-gw'), id = b.closest('.gw').getAttribute('data-id');
      if (act === 'restart' && !confirm(T('Restart this gateway? It comes back within a minute; the link to WhatsApp is kept.'))) return;
      b.disabled = true;
      fetch('?api=gw_action', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': gcsrf }, body: JSON.stringify({ acc: id, action: act }) })
        .then(wiSafeJson, wiNetFail).then(function (r) { if (!r.ok) alert(r.error || 'Failed'); setTimeout(loadGw, act === 'restart' ? 4000 : 2500); })
        .finally(function () { b.disabled = false; });
    });
  }

  /* ---------------- AI providers ---------------- */
  if (page === 'ai') {
    var acsrf = app.getAttribute('data-csrf'), AP = [];
    var SL = { ok: ['✓', 'Working', 'good'], not_configured: ['○', 'No key', 'muted'], unchecked: ['?', 'Not checked', 'muted'] };
    function apPost(b) { return WI.post('ai_providers', b); }
    function apDraw() {
      document.getElementById('ai-list').innerHTML = AP.map(function (p, i) {
        var s = SL[p.status] || ['⚠️', p.status, 'bad'], h = p.health || {};
        var cool = p.cooldowns || {}, now = Date.now() / 1000;
        function opt(m) { var id = m.id || m; return '<option value="' + esc(id) + '" ' + (p.model_setting === id ? 'selected' : '') + '>' + (m.vision ? '👁 ' : '') + esc(id) + (m.free === false ? ' 💲' : '') + (cool[id] > now ? ' ⏳' : '') + '</option>'; }
        var ms = p.models || [], models = '<optgroup label="' + T('Read images (receipts)') + '">' + ms.filter(function (m) { return m.vision; }).map(opt).join('') + '</optgroup><optgroup label="' + T('Text only') + '">' + ms.filter(function (m) { return !m.vision; }).map(opt).join('') + '</optgroup>';
        var ck = (p.checks && p.checks.items) || [];
        var checks = ck.length ? '<ul class="ai-checks">' + ck.map(function (c) { return '<li class="' + (c.ok === true ? 'ok' : (c.ok === false ? 'no' : 'na')) + '"><b>' + (c.ok === true ? '✓' : (c.ok === false ? '✗' : '–')) + '</b><span><span dir="auto">' + esc(c.label) + '</span>' + (c.detail ? '<small dir="auto">' + esc(c.detail) + '</small>' : '') + '</span></li>'; }).join('') + '</ul>' + (p.checks.at ? '<div class="muted small">' + T('Last check') + ': ' + esc(when(p.checks.at, true)) + ' ' + esc(String(p.checks.at).slice(11, 16)) + ' · ' + fmt(ms.length) + ' ' + T('models') + '</div>' : '') : '<div class="muted small">' + T('Not checked yet — press “Test & refresh models”.') + '</div>';
        var cl = Object.keys(cool).filter(function (k) { return cool[k] > now; });
        if (cl.length) checks += '<div class="muted small">⏳ ' + T('Resting (limit reached, other models still work)') + ': ' + cl.map(function (k) { return esc(k) + ' → ' + new Date(cool[k] * 1000).toLocaleTimeString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { hour: '2-digit', minute: '2-digit' }); }).join(' · ') + '</div>';
        var win = (p.windows || []).map(function (w) { return '<span>' + esc(w.label) + ': ' + (w.remaining != null ? fmt(w.remaining) : '?') + ' / ' + (w.limit != null ? fmt(w.limit) : '?') + '</span>'; }).join('');
        var u = p.usage || {};
        return '<div class="card ai-card" data-id="' + esc(p.id) + '" style="--c:' + esc(p.color) + '"><div class="ai-h"><span class="ai-n">' + (i + 1) + '</span><div class="ai-name"><b>' + esc(p.name) + '</b><div class="muted small">' + esc(p.tagline) + '</div></div>'
          + '<span class="pill ' + s[2] + '">' + s[0] + ' ' + T(s[1]) + '</span>' + (p.active ? '<span class="pill good">' + T('In use') + '</span>' : (p.skip_reason ? '<span class="pill muted" title="' + esc(p.skip_reason) + '">' + T('Skipped') + '</span>' : ''))
          + '<button class="ai-tg' + (p.enabled ? ' on' : '') + '" data-toggle title="' + T(p.enabled ? 'Deactivate' : 'Activate') + '"><i></i><span>' + T(p.enabled ? 'Active' : 'Inactive') + '</span></button>'
          + '<div class="ai-move"><button class="link-btn" data-up ' + (i ? '' : 'disabled') + '>▲</button><button class="link-btn" data-down ' + (i < AP.length - 1 ? '' : 'disabled') + '>▼</button></div></div>'
          + checks + (h.reason && !h.ok ? '<div class="small bad-t">⏳ ' + esc(h.reason) + '</div>' : '')
          + '<div class="ai-f"><input type="checkbox" data-f="enabled" hidden ' + (p.enabled ? 'checked' : '') + '>'
          + (p.custom ? '<label>' + T('Name') + '<input data-f="name" maxlength="40" value="' + esc(p.name && p.name.indexOf('Custom') !== 0 ? p.name : '') + '" placeholder="FlexAI"></label><label class="ai-url">Base URL<input data-f="base_url" dir="ltr" value="' + esc(p.base_url || '') + '" placeholder="https://…/v1"></label>' : '')
          + '<label>' + T('API key') + '<input type="password" data-f="api_key" autocomplete="off" placeholder="' + (p.has_key ? '•••••••• ' + T('saved — type to replace') : esc(p.key_hint)) + '"></label>'
          + '<label>' + T('Extra keys (one per line) — used in turn when a key reaches its limit') + (p.extra_keys ? ' <small class="muted">🔑 ' + fmt(p.extra_keys) + ' ' + T('extra keys saved') + ' · <button type="button" class="link-btn" data-clear-extra>' + T('remove them') + '</button></small>' : '') + '<textarea data-f="extra_keys" rows="2" dir="ltr" autocomplete="off" placeholder="' + esc(p.key_hint || '') + '"></textarea></label>'
          + '<label>' + T('Model') + '<select data-f="model"><option value="auto">' + T('Auto (best free)') + (p.model ? ' — ' + esc(p.model) : '') + '</option>' + models + '</select></label>'
          + '<label>' + T('Plan') + '<select data-f="plan">' + ['auto', 'free', 'paid'].map(function (x) { return '<option value="' + x + '" ' + (p.plan_setting === x ? 'selected' : '') + '>' + T(x === 'auto' ? 'Auto-detect' : (x === 'free' ? 'Free' : 'Paid')) + '</option>'; }).join('') + '</select></label>'
          + '<label>' + T('Daily limit (0 = none)') + '<input type="number" min="0" data-f="daily_limit" value="' + (p.daily_limit || 0) + '"></label></div>'
          + '<div class="ai-stats muted small">' + (p.latency_ms ? '<span>⚡ ' + fmt(p.latency_ms) + ' ms</span>' : '') + (u.today != null ? '<span>📊 ' + T('Today') + ': ' + fmt(u.today) + '</span>' : '') + (p.last_used_at ? '<span>🕐 ' + esc(when(p.last_used_at, true)) + '</span>' : '') + win + '</div>'
          + '<div class="row wrap"><button class="btn sm" data-save>💾 ' + T('Save') + '</button><button class="chip-btn sm" data-refresh>🔄 ' + T('Test & refresh models') + '</button>'
          + (p.has_key ? '<button class="link-btn" data-clear>🗑 ' + T('Remove key') + '</button>' : '') + '<a class="link small" href="' + esc(p.signup_url) + '" target="_blank" rel="noopener">🔑 ' + T('Get a free key') + '</a><span class="muted small" data-st></span></div></div>';
      }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>';
    }
    function apMode(m) { [].forEach.call(document.querySelectorAll('#ai-mode button'), function (b) { b.classList.toggle('on', b.dataset.m === m); }); }
    function apLoad() { api('ai_providers').then(function (r) { if (!r.ok) { document.getElementById('ai-list').innerHTML = '<div class="empty">' + esc(r.error || '') + '</div>'; return; }
      var eb = document.getElementById('ai-engine'); if (!eb) { eb = document.createElement('div'); eb.id = 'ai-engine'; eb.className = 'pp-view'; document.getElementById('ai-list').parentNode.insertBefore(eb, document.getElementById('ai-list')); }
      eb.innerHTML = (r.engine === 'local' ? '🔐 ' + T('Engine: this system (keys are stored here, encrypted).') : '🔗 ' + T('Engine: through the invoice system (its keys). To use this system\'s own keys, set the AI engine to "local" in General settings → System, then add the keys here.')) + ' <a href="?p=settings">' + T('General settings') + ' →</a>'; AP = r.providers; apMode(r.mode); apDraw(); }); }
    document.getElementById('ai-mode').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return; var st = document.getElementById('ai-mode-st'); st.textContent = T('Working…');
      apPost({ do: 'mode', mode: b.dataset.m }).then(function (r) { if (!r.ok) { st.textContent = '⚠️ ' + (r.error || T('Failed')); return; } apMode(r.mode); st.textContent = '✓ ' + T('Saved'); });
    });
    document.getElementById('ai-list').addEventListener('click', function (e) {
      var card = e.target.closest('.ai-card'); if (!card) return; var id = card.dataset.id, st = card.querySelector('[data-st]'), b = e.target.closest('button'); if (!b) return;
      var order = AP.map(function (p) { return p.id; }), i = order.indexOf(id);
      function f(n) { return card.querySelector('[data-f="' + n + '"]'); }
      var body = { id: id, enabled: f('enabled').checked ? 1 : 0, api_key: f('api_key').value.trim(), extra_keys: (f('extra_keys') || { value: '' }).value.trim(), model: f('model').value, plan: f('plan').value, daily_limit: Number(f('daily_limit').value || 0), order: order };
      if (f('base_url')) { body.base_url = f('base_url').value.trim(); body.name = f('name').value.trim(); }
      if (b.hasAttribute('data-up') || b.hasAttribute('data-down')) { var k = b.hasAttribute('data-up') ? i - 1 : i + 1; order.splice(k, 0, order.splice(i, 1)[0]); body.order = order; delete body.api_key; }
      else if (b.hasAttribute('data-toggle')) { var cur = AP[i]; if (cur.enabled && !confirm(T('Deactivate this provider? It will no longer be used.'))) return; if (!cur.enabled && !cur.has_key && !cur.key_optional && !body.api_key) { st.textContent = '⚠️ ' + T('Add an API key first.'); f('api_key').focus(); return; } body.enabled = cur.enabled ? 0 : 1; delete body.api_key; }
      else if (b.hasAttribute('data-clear-extra')) { if (!confirm(T('Remove the extra keys?'))) return; body.clear_extra = 1; }
      else if (b.hasAttribute('data-clear')) { if (!confirm(T('Remove the saved key?'))) return; body.clear_key = 1; body.enabled = 0; }
      else if (b.hasAttribute('data-refresh')) { if (body.api_key) body.do = 'save'; else body = { do: 'refresh', id: id }; }
      else if (!b.hasAttribute('data-save')) return;
      b.disabled = true; st.textContent = b.hasAttribute('data-refresh') ? T('Checking key, plan and models… (up to 2 minutes)') : T('Working…');
      apPost(body).then(function (r) { if (!r.ok) { st.textContent = '⚠️ ' + (r.error || T('Failed')); b.disabled = false; return; } AP = r.providers; apDraw(); }).catch(function () { st.textContent = '⚠️ ' + T('Failed'); b.disabled = false; });
    });
    apLoad();
  }

  /* ---------------- Files ---------------- */
  if (page === 'files') {
    var F = { items: [], kind: '', timer: null }, fcsrf = app.getAttribute('data-csrf');
    function fUrl(att, name, dl) { if (!att) return ''; var p = att.split('/'), u = p[0] === 'inbox_uploads' ? '?api=media&f=' + encodeURIComponent(p[1]) : (p[0] === 'inbox_media' ? '?api=media&a=' + encodeURIComponent(p[1]) + '&f=' + encodeURIComponent(p[2]) : ''); return u ? u + (name ? '&name=' + encodeURIComponent(name.replace(/\.[a-z0-9]{2,4}$/i, '')) : '') + (dl ? '&dl=1' : '') : ''; }
    function fIcon(x) { var m = String(x.mimetype || '') + ' ' + (x.file_name || ''); return x.kind === 'image' ? '🖼️' : (x.kind === 'video' ? '🎬' : (/pdf/i.test(m) ? '📕' : (/word|docx?$/i.test(m) ? '📘' : (/sheet|excel|xlsx?$/i.test(m) ? '📗' : (/zip|rar/i.test(m) ? '🗜️' : '📄'))))); }
    function drawF() {
      var rows = F.items.filter(function (x) { return !F.kind || x.kind === F.kind; });
      document.getElementById('fl-count').textContent = fmt(rows.length) + ' ' + T('files') + ' · ' + fmt(rows.reduce(function (a, b) { return a + Math.max(0, b.copies - 1); }, 0)) + ' ' + T('duplicates (counted only)');
      document.getElementById('fl-grid').innerHTML = rows.slice(0, 400).map(function (x) {
        var url = fUrl(x.attachment), nm = x.file_name || x.text || (x.kind === 'image' ? T('🖼️ Photo') : T('📄 File'));
        var prev = x.kind === 'image' && url ? '<a href="' + url + '" target="_blank" rel="noopener"><img src="' + url + '" alt="" loading="lazy"></a>'
          : (x.kind === 'video' && url ? '<video controls preload="metadata" playsinline src="' + url + '#t=0.5"></video>' // plays and seeks in place
          : ((x.kind === 'audio' || x.kind === 'voice') && url ? '<div class="fl-aud"><span class="fl-ic">' + (x.kind === 'voice' ? '🎙️' : '🎧') + '</span><audio controls preload="none" src="' + url + '"></audio></div>' : '<div class="fl-ic">' + fIcon(x) + '</div>'));
        return '<div class="fl-card"><div class="fl-prev">' + prev + '</div><div class="fl-b"><b dir="auto" title="' + esc(nm) + '">' + esc(nm) + '</b>'
          + '<div class="muted small">' + (x.size ? (x.size / 1048576).toFixed(1) + ' MB · ' : '') + esc(when(x.time, true)) + (x.copies > 1 ? ' · 🔁 ' + fmt(x.copies) + '×' : '') + '</div>'
          + '<div class="muted small"><a href="?p=inbox#' + encodeURIComponent(x.jid) + '">' + esc(x.customer ? x.customer.name : (x.name || (x.phone ? '+' + x.phone : ''))) + '</a></div>'
          + '<div class="row wrap fl-acts">' + (url ? '<a class="chip-btn sm" href="' + fUrl(x.attachment, nm, true) + '" title="' + esc(T('Download')) + '">⬇️ ' + T('Download') + '</a>'
            + '<a class="chip-btn sm" href="' + url + '" target="_blank" rel="noopener" title="' + esc(T('Open')) + '">👁️</a>'
            + '<button type="button" class="chip-btn sm" data-fw="' + esc(x.attachment) + '" data-cap="' + esc(x.text || '') + '" title="' + esc(T('Send to chats')) + '">📤</button>'
            + '<a class="chip-btn sm" href="?p=inbox&jid=' + encodeURIComponent(x.jid) + '" title="' + esc(T('Open the chat')) + '">💬</a>'
            + '<button type="button" class="chip-btn sm" data-copy="' + esc(location.origin + location.pathname + url) + '" title="' + esc(T('Copy link')) + '">🔗</button>' : (x.fetchable ? '<button class="chip-btn sm" data-fetch="' + esc(x.id) + '">⬇️ ' + T('Fetch from WhatsApp') + '</button>' : '<span class="muted small">' + T('File not kept') + '</span>')) + '</div></div></div>';
      }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>';
    }
    function loadF() { api('files', { q: document.getElementById('fl-q').value || '' }).then(function (r) { if (!r.ok) { document.getElementById('fl-grid').innerHTML = '<div class="empty">' + esc(r.error || '') + '</div>'; return; } F.items = r.items; drawF(); }); }
    document.getElementById('fl-kind').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); F.kind = b.dataset.k; drawF(); });
    document.getElementById('fl-q').addEventListener('input', function () { clearTimeout(F.timer); F.timer = setTimeout(loadF, 350); });
    document.getElementById('fl-grid').addEventListener('click', function (e) {
      var w = e.target.closest('[data-fw]'); if (w) { if (typeof mlForward === 'function') mlForward(w.dataset.fw, w.dataset.cap || '', function () {}); return; }
      var cp = e.target.closest('[data-copy]'); if (cp) { try { navigator.clipboard.writeText(cp.dataset.copy); cp.textContent = '✓'; setTimeout(function () { cp.textContent = '🔗'; }, 1500); } catch (er) {} return; }
      var f = e.target.closest('[data-fetch]'); if (!f) return; f.disabled = true; f.textContent = T('Downloading…');
      WI.post('fetch_media', { id: f.dataset.fetch }).then(function (r) { if (r.ok) loadF(); else f.textContent = r.error || T('Failed'); });
    });
    loadF();
  }

  /* ---------------- Audio library ---------------- */
  if (page === 'audio') {
    var A = { items: [], cat: '', view: 'active', sort: 'copies', sel: {} }, acsrf = app.getAttribute('data-csrf');
    var ACAT = { '': ['❔', 'Uncategorised', '#94a3b8'] }, AKEYS = []; // audio categories come from the business profile
    ((window.WI_PACK || {}).audio_cats || []).forEach(function (c) { ACAT[c.key] = [c.icon, c.label, c.color]; AKEYS.push(c.key); });
    function aUrl(att, name, dl) { if (!att) return ''; var p = att.split('/'), u = p[0] === 'inbox_uploads' ? '?api=media&f=' + encodeURIComponent(p[1]) : '?api=media&a=' + encodeURIComponent(p[1]) + '&f=' + encodeURIComponent(p[2]); return u + (name ? '&name=' + encodeURIComponent(name) : '') + (dl ? '&dl=1' : ''); }
    function adur(x) { x = Number(x || 0); return x ? Math.floor(x / 60) + ':' + ('0' + x % 60).slice(-2) : '—'; }
    function aname(it) { return it.title || it.auto_title || (it.kind === 'voice' ? T('🎤 Voice message') : T('🎵 Audio')); }
    function post(body) { return WI.post('audio_meta', body); }
    function list() {
      var q = (document.getElementById('au-q').value || '').toLowerCase();
      var rows = A.items.filter(function (it) { return (A.view === 'archived') === it.archived && (!A.cat || (A.cat === '-' ? !it.category : it.category === A.cat)) && (!q || (aname(it) + ' ' + it.note + ' ' + it.from).toLowerCase().indexOf(q) !== -1); });
      var key = { copies: function (x) { return x.copies; }, last: function (x) { return Date.parse(x.last) || 0; }, long: function (x) { return x.seconds || 0; } }[A.sort];
      return A.sort === 'name' ? rows.sort(function (a, b) { return aname(a).localeCompare(aname(b)); }) : rows.sort(function (a, b) { return key(b) - key(a); });
    }
    function tiles() {
      var cnt = { '': 0 }, all = 0;
      A.items.forEach(function (it) { if (it.archived !== (A.view === 'archived')) return; all++; cnt[it.category || ''] = (cnt[it.category || ''] || 0) + 1; });
      document.getElementById('au-tiles').innerHTML = '<button class="sec-tile' + (A.cat ? '' : ' on') + '" data-cat="" style="--c:#0f172a"><span class="st-ic">🎧</span><b>' + fmt(all) + '</b><small>' + T('All') + '</small></button>'
        + AKEYS.map(function (k) { var c = ACAT[k]; return '<button class="sec-tile' + (A.cat === k ? ' on' : '') + '" data-cat="' + k + '" style="--c:' + c[2] + '"><span class="st-ic">' + c[0] + '</span><b>' + fmt(cnt[k] || 0) + '</b><small>' + T(c[1]) + '</small></button>'; }).join('')
        + '<button class="sec-tile' + (A.cat === '-' ? ' on' : '') + '" data-cat="-" style="--c:#94a3b8"><span class="st-ic">❔</span><b>' + fmt(cnt[''] || 0) + '</b><small>' + T('Uncategorised') + '</small></button>';
    }
    function selBar() { var n = Object.keys(A.sel).length; document.getElementById('au-sel').hidden = !n; document.getElementById('au-sel-n').textContent = fmt(n) + ' ' + T('selected'); document.getElementById('au-sel-arch').hidden = A.view === 'archived'; document.getElementById('au-sel-rest').hidden = A.view !== 'archived'; }
    function draw() {
      var rows = list(); tiles(); selBar();
      document.getElementById('au-count').textContent = fmt(rows.length) + ' ' + T('recordings');
      document.getElementById('au-list').innerHTML = rows.slice(0, 300).map(function (it) {
        var c = (ACAT[it.category || ''] || ACAT['']), url = aUrl(it.attachment, '', false), dl = aUrl(it.attachment, aname(it), true);
        var chk = A.sel[it.key] ? ' checked' : '';
        return '<div class="au-row ml-cell mcard' + (A.sel[it.key] ? ' sel picked' : '') + '" data-key="' + esc(it.key) + '"><label class="ml-ck"><input type="checkbox" data-pick' + chk + '></label>'
          + '<div class="mc-media mk-audio" style="--c:' + c[2] + '"><div class="mc-audio"><span class="mc-big">' + (it.kind === 'voice' ? '🎙️' : (it.category ? c[0] : '🎧')) + '</span>'
          + (url ? '<audio controls preload="none" style="display:block;width:100%;min-width:200px;height:40px" src="' + url + '"></audio>' : (it.fetchable ? '<button class="chip-btn sm" data-fetch="' + esc(it.fetch_id) + '">⬇️ ' + T('Fetch from WhatsApp') + '</button>' : '<span class="muted small">' + T('File not kept') + '</span>')) + '</div></div>'
          + '<div class="mc-body"><input class="au-title mc-name" value="' + esc(it.title) + '" placeholder="' + esc(it.auto_title || T('Name this recording…')) + '" dir="auto" maxlength="120">'
          + (it.code ? '<div class="lc-code" dir="auto">🔑 ' + esc(it.code) + '</div>' : '')
          + '<div class="mc-meta"><span>⏱ ' + adur(it.seconds) + '</span>' + (it.size ? '<span>💾 ' + (it.size / 1048576).toFixed(1) + ' MB</span>' : '') + '<span>🔁 ' + fmt(it.copies) + '× ' + T('shared') + '</span><span>💬 ' + fmt(it.chats) + '</span><span>🕐 ' + esc(when(it.last, true)) + '</span></div>'
          + (it.from ? '<div class="mc-chat" dir="auto">👤 ' + esc(it.from) + '</div>' : '') + (it.group ? '<div class="mc-chat" dir="auto">👥 ' + esc(it.group) + '</div>' : '')
          + '<select class="au-cat">' + Object.keys(ACAT).map(function (k) { return '<option value="' + k + '" ' + ((it.category || '') === k ? 'selected' : '') + '>' + ACAT[k][0] + ' ' + T(ACAT[k][1]) + '</option>'; }).join('') + '</select></div>'
          + '<div class="mc-acts">' + (url ? '<button type="button" data-aname aria-label="' + esc(T('Name & shortcut')) + '">'+'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16z"/></svg>' + T('Name') + '</button>'
          + '<button type="button" data-asend aria-label="' + esc(T('Send to chats')) + '">'+'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 3 3 10.5l7 3 3 7z"/></svg>' + T('Send') + '</button>'
          + '<a class="mc-dl" href="' + dl + '" aria-label="' + esc(T('Download')) + '">'+'<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 20h14"/></svg>' + T('Download') + '</a>' : '')
          + '<button type="button" data-arch>' + (it.archived ? '↩️ ' + T('Restore') : '🗄️ ' + T('Archive')) + '</button></div></div>';
      }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>';
    }
    var lastQ = null;
    function hm(sec) { sec = Math.round(sec || 0); var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60); return (h ? fmt(h) + ' ' + T('h') + ' ' : '') + fmt(m) + ' ' + T('min'); }
    function mb(b) { return b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : (b / 1048576).toFixed(0) + ' MB'; }
    function bars(rows, total) { return rows.map(function (r) { var pc = total ? Math.round(r[1] / total * 100) : 0; return '<div class="sbar"><span class="sb-l">' + r[0] + '</span><span class="sb-t"><span style="width:' + Math.max(pc, r[1] ? 2 : 0) + '%;background:' + (r[2] || 'var(--brand)') + '"></span></span><b>' + fmt(r[1]) + '</b><small>' + (r[3] || (pc + '%')) + '</small></div>'; }).join(''); }
    function stats() {
      var it = A.items, uniq = it.length, msgs = 0, dur = 0, size = 0, saved = 0, files = 0, fetchable = 0, lost = 0, cat = {}, month = {};
      it.forEach(function (x) {
        msgs += x.copies; dur += (x.seconds || 0); size += (x.size || 0); saved += (x.size || 0) * Math.max(0, x.copies - 1);
        if (x.attachment) files++; else if (x.fetchable) fetchable++; else lost++;
        var k = x.category || ''; cat[k] = cat[k] || [0, 0, 0]; cat[k][0]++; cat[k][1] += x.copies; cat[k][2] += (x.seconds || 0);
        var mo = String(x.last || '').slice(0, 7); if (mo) month[mo] = (month[mo] || 0) + x.copies;
      });
      document.getElementById('au-stats').innerHTML =
        kpi('🎧', T('Unique recordings'), uniq, fmt(msgs) + ' ' + T('audio messages'), 'rgba(139,92,246,.16)') +
        kpi('🔁', T('Duplicates (counted, not downloaded)'), msgs - uniq, T('saved') + ' ' + mb(saved), 'rgba(16,185,129,.16)') +
        '<div class="kpi" style="--glow:rgba(14,165,233,.16)"><div class="k-ic">⏱</div><small>' + T('Total listening time') + '</small><b>' + hm(dur) + '</b><em>' + mb(size) + '</em></div>' +
        kpi('✅', T('Downloaded'), files, fmt(fetchable) + ' ' + T('waiting') + ' · ' + fmt(lost) + ' ' + T('not available'), 'rgba(245,158,11,.16)');
      var order = AKEYS.concat(['']);
      document.getElementById('au-bycat').innerHTML = bars(order.filter(function (k) { return cat[k]; }).map(function (k) { return [ACAT[k][0] + ' ' + T(ACAT[k][1]), cat[k][0], ACAT[k][2], fmt(cat[k][1]) + '× · ' + hm(cat[k][2])]; }), uniq);
      var q = lastQ || {};
      document.getElementById('au-bydl').innerHTML = bars([
        ['✅ ' + T('Downloaded'), files, '#10b981'], ['⏳ ' + T('In the queue'), q.pending || 0, '#f59e0b'], ['🔁 ' + T('duplicates skipped'), q.dupes || 0, '#6366f1'],
        ['⌛ ' + T('expired on WhatsApp'), q.expired || 0, '#94a3b8'], ['⚠️ ' + T('failed'), q.failed || 0, '#ef4444']], Math.max(1, files + (q.pending || 0) + (q.dupes || 0) + (q.expired || 0) + (q.failed || 0)));
      var top = it.slice().sort(function (a, b) { return b.copies - a.copies; }).slice(0, 8), tmax = top.length ? top[0].copies : 1;
      document.getElementById('au-top').innerHTML = top.map(function (x) { return '<div class="p-row"><div class="av" style="background:' + (ACAT[x.category || ''] || ACAT[''])[2] + '">' + (ACAT[x.category || ''] || ACAT[''])[0] + '</div><div class="p-b"><b dir="auto">' + esc(aname(x)) + '</b><span class="meter"><span style="width:' + Math.round(x.copies / tmax * 100) + '%"></span></span></div><span class="n">' + fmt(x.copies) + '×</span></div>'; }).join('') || '<div class="empty">—</div>';
      var ms = Object.keys(month).sort().slice(-12), mmax = 1; ms.forEach(function (k) { mmax = Math.max(mmax, month[k]); });
      document.getElementById('au-month').innerHTML = ms.length ? '<div class="mbars">' + ms.map(function (k) { return '<div class="mb" title="' + k + ': ' + month[k] + '"><span style="height:' + Math.round(month[k] / mmax * 100) + '%"></span><small>' + k.slice(2).replace('-', '/') + '</small></div>'; }).join('') + '</div>' : '<div class="empty">—</div>';
    }
    function load() {
      api('audio_lib').then(function (r) { if (!r.ok) { document.getElementById('au-list').innerHTML = '<div class="empty">' + esc(r.error || '') + '</div>'; return; } A.items = r.items; draw(); stats(); });
      api('media_queue').then(function (q) { if (q.ok) { lastQ = q; if (A.items.length) stats(); } if (q.ok) document.getElementById('au-queue').textContent = (q.pending ? '⏳ ' + fmt(q.pending) + ' ' + T('left') + ' · ' : '') + '✓ ' + fmt(q.done) + ' · ' + T('duplicates skipped') + ' ' + fmt(q.dupes || 0) + ' · ' + T('expired on WhatsApp') + ' ' + fmt(q.expired); });
    }
    function itemOf(el) { var k = el.closest('.au-row').dataset.key; return A.items.filter(function (x) { return x.key === k; })[0]; }
    document.getElementById('au-tiles').addEventListener('click', function (e) { var b = e.target.closest('[data-cat]'); if (!b) return; A.cat = b.dataset.cat; draw(); });
    document.getElementById('au-view').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); A.view = b.dataset.v; A.sel = {}; A.cat = ''; draw(); });
    // 🏷️ the categories: add, rename, change icon and colour, delete (files of a deleted category become uncategorised)
    document.getElementById('au-cats').addEventListener('click', function () {
      var cats = AKEYS.map(function (k) { return { key: k, icon: ACAT[k][0], label: T(ACAT[k][1]), color: ACAT[k][2], n: A.items.filter(function (i) { return i.category === k; }).length }; });
      var d = mlDialog('<div class="ml-head"><b>🏷️ ' + T('Manage categories') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-fw"><div id="ac-rows"></div>'
        + '<div class="row"><button type="button" class="btn ghost sm" id="ac-add">＋ ' + T('Add a category') + '</button><button type="button" class="btn primary" id="ac-go">💾 ' + T('Save') + '</button><span class="muted small" id="ac-m"></span></div></div>');
      var rows = function () { document.getElementById('ac-rows').innerHTML = cats.map(function (c, i) { return '<div class="ac-row" data-i="' + i + '"><input class="ac-ic" value="' + esc(c.icon) + '" maxlength="4" aria-label="' + esc(T('Icon')) + '"><input class="ac-lb" value="' + esc(c.label) + '" maxlength="30" dir="auto" placeholder="' + esc(T('Name')) + '"><input class="ac-co" type="color" value="' + esc(c.color) + '">'
        + '<span class="muted small">' + (c.n ? fmt(c.n) + ' ' + T('recordings') : '') + '</span><button type="button" class="chip-btn sm" data-acdel>🗑️</button></div>'; }).join(''); };
      var sync = function () { [].forEach.call(d.querySelectorAll('.ac-row'), function (r) { var c = cats[+r.dataset.i]; c.icon = r.querySelector('.ac-ic').value; c.label = r.querySelector('.ac-lb').value; c.color = r.querySelector('.ac-co').value; }); };
      rows();
      d.onclick = function (ev) { if (ev.target.closest('[data-mlx]')) { d.close(); return; }
        if (ev.target.closest('#ac-add')) { sync(); cats.push({ key: '', icon: '📁', label: '', color: '#64748b', n: 0 }); rows(); return; }
        var del = ev.target.closest('[data-acdel]'); if (del) { sync(); var c = cats[+del.closest('.ac-row').dataset.i]; if (c.n && !confirm(T('Delete this category? Its recordings stay, uncategorised.'))) return; cats.splice(+del.closest('.ac-row').dataset.i, 1); rows(); return; }
        if (ev.target.closest('#ac-go')) { sync(); WI.post('audio_cats', { cats: cats }).then(function (r) { if (!r.ok) { document.getElementById('ac-m').textContent = '⚠️ ' + (r.error || T('Failed')); return; } location.reload(); }); } };
    });
    document.getElementById('au-sort').addEventListener('change', function () { A.sort = this.value; draw(); });
    document.getElementById('au-q').addEventListener('input', draw);
    var list$ = document.getElementById('au-list');
    list$.addEventListener('change', function (e) {
      var it = itemOf(e.target);
      if (e.target.matches('[data-pick]')) { if (e.target.checked) A.sel[it.key] = 1; else delete A.sel[it.key]; e.target.closest('.au-row').classList.toggle('picked', e.target.checked); selBar(); }
      if (e.target.matches('.au-cat')) { it.category = e.target.value; post({ key: it.key, category: it.category }).then(tiles); }
      if (e.target.matches('.au-title')) { it.title = e.target.value.trim(); post({ key: it.key, title: it.title }).then(function (r) { e.target.classList.add(r.ok ? 'saved' : 'bad'); setTimeout(function () { e.target.classList.remove('saved', 'bad'); }, 1200); }); }
    });
    list$.addEventListener('keydown', function (e) { if (e.target.matches('.au-title') && e.key === 'Enter') { e.preventDefault(); e.target.blur(); } });
    list$.addEventListener('click', function (e) {
      var an = e.target.closest('[data-aname]');
      if (an) { var x = itemOf(an), key = x.attachment; var d = mlDialog('<div class="ml-head"><b>✏️ ' + T('Name & shortcut') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-fw">'
          + '<label>' + T('Name') + '<input id="ln-t" dir="auto" maxlength="80" value="' + esc(x.title || '') + '" placeholder="' + esc(T('e.g. Ruqyah Al-Athiri')) + '"></label>'
          + '<label>' + T('Shortcut — write it in any chat or group and the file is sent') + '<input id="ln-c" dir="auto" maxlength="60" value="' + esc(x.code || '') + '" placeholder="' + esc(T('#ruqyah-name')) + '"></label>'
          + '<div class="row"><button type="button" class="btn primary" id="ln-go">💾 ' + T('Save') + '</button><span class="muted small" id="ln-m"></span></div></div>');
        d.onclick = function (ev) { if (ev.target.closest('[data-mlx]')) { d.close(); return; } if (!ev.target.closest('#ln-go')) return;
          var sent = { title: document.getElementById('ln-t').value.trim(), code: document.getElementById('ln-c').value.trim() };
          WI.post('media_meta', { key: key, title: sent.title, code: sent.code }).then(function (r) { if (!r.ok) { document.getElementById('ln-m').textContent = '⚠️ ' + (r.error || T('Failed')); return; }
            post({ key: x.key, title: sent.title }); x.title = sent.title; x.code = sent.code; ML.items = null; d.close(); draw(); }); }; return; }
      var sd = e.target.closest('[data-asend]'); if (sd) { mlForward(itemOf(sd).attachment, ''); return; }
      var ar = e.target.closest('[data-arch]');
      if (ar) { var it = itemOf(ar); it.archived = !it.archived; post({ key: it.key, archived: it.archived }).then(draw); return; }
      var f = e.target.closest('[data-fetch]');
      if (f) { f.disabled = true; f.textContent = T('Downloading…');
        WI.post('fetch_media', { id: f.dataset.fetch }).then(function (r) { if (r.ok) load(); else f.textContent = r.error || T('Failed'); }); }
    });
    function bulk(body) { body.keys = Object.keys(A.sel); post(body).then(function () { A.items.forEach(function (x) { if (A.sel[x.key]) { if ('archived' in body) x.archived = body.archived; if ('category' in body) x.category = body.category; } }); A.sel = {}; draw(); }); }
    document.getElementById('au-sel-arch').addEventListener('click', function () { bulk({ archived: true }); });
    document.getElementById('au-sel-rest').addEventListener('click', function () { bulk({ archived: false }); });
    document.getElementById('au-sel-cat').addEventListener('change', function () { if (this.value) bulk({ category: this.value }); this.value = ''; });
    document.getElementById('au-sel-x').addEventListener('click', function () { A.sel = {}; draw(); });
    load(); setInterval(function () { api('media_queue').then(function (q) { if (q.ok) document.getElementById('au-queue').textContent = (q.pending ? '⏳ ' + fmt(q.pending) + ' ' + T('left') + ' · ' : '') + '✓ ' + fmt(q.done) + ' · ' + T('duplicates skipped') + ' ' + fmt(q.dupes || 0) + ' · ' + T('expired on WhatsApp') + ' ' + fmt(q.expired); }); }, 15000);
  }

  /* ---------------- Library ---------------- */
  if (page === 'library') {
    var L = { data: null, section: app.getAttribute('data-sec') || '', topic: '', sort: 'shared', view: 'cards', shown: 40, timer: null, sel: {} };
    function stamp(t) { var d = new Date(t); return isNaN(d) ? '' : d.toLocaleDateString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) + ' · ' + d.toLocaleTimeString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { hour: '2-digit', minute: '2-digit' }); }
    function selBar() {
      var n = Object.keys(L.sel).length, arch = document.getElementById('lib-archived').checked;
      document.getElementById('sel-bar').hidden = !n; document.getElementById('sel-n').textContent = fmt(n) + ' ' + T('selected');
      document.getElementById('sel-archive').hidden = arch; document.getElementById('sel-delete').hidden = false; document.getElementById('sel-restore').hidden = !arch;
    }
    // Same words and letters as the best version (ignoring tashkeel, spacing, emojis, punctuation) = identical copy.
    function core(t) { return String(t || '').replace(/[\u064B-\u065F\u0670\u0640\u06D6-\u06ED]/g, '').replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/[^\p{L}\p{N}]+/gu, '').toLowerCase(); }
    function identical(v, gi) { var g = current[gi]; return !!(g && !g.audio && g.best !== v && v.text && core(v.text) === core(g.best.text)); }
    function vcard(v, gi, isBest, audio) {
      var k = v.key || '', same = !isBest && !audio && identical(v, gi);
      return '<div class="vcard' + (isBest ? ' best' : '') + (L.sel[k] ? ' picked' : '') + '"><div class="vc-h"><label class="check"><input type="checkbox" data-pick="' + esc(k) + '" ' + (L.sel[k] ? 'checked' : '') + '></label>'
        + (isBest ? '<span class="vc-best">⭐ ' + T('Best') + '</span>' : '') + (v.score !== undefined ? '<span class="score sm" title="' + esc(scoreTip(v.parts)) + '">' + v.score + '</span>' : '')
        + '<span class="vc-time">🕐 ' + esc(stamp(v.last || v.time)) + '</span>' + (v.first && v.first !== v.last ? '<span class="vc-time muted">' + T('first') + ' ' + esc(stamp(v.first)) + '</span>' : '')
        + (v.count ? '<span class="badge">' + fmt(v.count) + '×</span>' : '') + (same ? '<span class="badge same" title="' + T('Same words as the best version — only spacing, tashkeel or emojis differ') + '">🟰 ' + T('Identical') + '</span>' : '') + (audio && !isBest ? '<span class="badge same">🟰 ' + T('Identical copy — not downloaded again') + '</span>' : '') + '</div>'
        + '<div class="vc-meta"><span>👤 ' + esc(sender(v)) + '</span><span>📅 ' + esc(dayStamp(v.time || v.last)) + '</span>' + (v.group ? '<span>👥 ' + esc(v.name || '') + '</span>' : '') + (v.seconds ? '<span>⏱ ' + dur(v.seconds) + '</span>' : '') + (+v.size ? '<span>💾 ' + (typeof mlSize === 'function' ? mlSize(v.size) : Math.round(v.size / 1024) + ' KB') + '</span>' : '') + '</div>'
        + (audio ? player(v) : '<div class="vc-t clamp" dir="auto" title="' + T('Click to show the full text') + '">' + esc(v.text) + '</div>')
        + '<div class="vc-a"><a class="link" href="?p=inbox#' + encodeURIComponent(v.jid) + '">💬 ' + esc(who(v)) + '</a>' + (audio ? '' : '<button class="link-btn" data-copy-v="' + gi + ':' + esc(k) + '">📋 ' + T('Copy') + '</button>') + '</div></div>';
    }
    var lcsrf = app.getAttribute('data-csrf');
    try { L.view = localStorage.getItem('wi_lib_view') || 'cards'; } catch (e) {}
    // Library sections, icons and colours come from the content rules (Settings → Gateway rules); colours from a fixed palette by position.
    var LIB = (window.WI_PACK || {}).library || {}, PAL = ['#8b5cf6', '#0ea5e9', '#6366f1', '#10b981', '#14b8a6', '#f59e0b', '#eab308', '#ef4444', '#ec4899', '#22c55e'];
    var SEC = [[LIB.audio || 'Audio', '🎧', PAL[0]]].concat((LIB.sections || []).map(function (x, i) { return [x.name, x.icon || '📁', PAL[(i + 1) % PAL.length]]; }), [[LIB.other || 'Other', '📁', '#64748b']]);
    function secInfo(n) { return SEC.filter(function (x) { return x[0] === n; })[0] || [n, '📁', '#64748b']; }
    function mediaUrl(att) { if (!att) return ''; var p = att.split('/'); return p[0] === 'inbox_uploads' ? '?api=media&f=' + encodeURIComponent(p[1]) : '?api=media&a=' + encodeURIComponent(p[1]) + '&f=' + encodeURIComponent(p[2]); }
    function dur(x) { x = Number(x || 0); return x ? Math.floor(x / 60) + ':' + ('0' + x % 60).slice(-2) : ''; }
    function sender(v) { if (v.dir === 'out') return T('You'); if (v.group) return v.from_name || (v.from ? '+' + v.from : '—'); return who(v); }
    function who(r) { return r.customer ? r.customer.name : (r.name || (r.phone ? '+' + r.phone : String(r.jid || '').split('@')[0])); }
    function scoreTip(p) { return p ? T('Completeness') + ' ' + Math.round(p.complete * 100) + '% · ' + T('Popularity') + ' ' + Math.round(p.popular * 100) + '% · ' + T('Diacritics') + ' ' + Math.round(p.tashkeel * 100) + '% · ' + T('Recent') + ' ' + Math.round(p.recent * 100) + '%' : ''; }
    function list() {
      var gs = (L.data ? L.data.groups : []).filter(function (g) { return (!L.section || g.section === L.section) && (!L.topic || g.topic === L.topic); });
      var key = { shared: function (g) { return g.total; }, score: function (g) { return g.best.score || 0; }, versions: function (g) { return g.versions; }, recent: function (g) { return Date.parse(g.best.last || g.best.time) || 0; } }[L.sort];
      return gs.sort(function (x, y) { return key(y) - key(x); });
    }
    function tiles() {
      var sec = (L.data && L.data.sections) || {}, total = 0; for (var k in sec) total += sec[k];
      document.getElementById('sec-tiles').innerHTML = '<button class="sec-tile' + (L.section ? '' : ' on') + '" data-sec="" style="--c:#0f172a"><span class="st-ic">🗂️</span><b>' + fmt(total) + '</b><small>' + T('All') + '</small></button>'
        + SEC.filter(function (x) { return sec[x[0]]; }).map(function (x) { return '<button class="sec-tile' + (L.section === x[0] ? ' on' : '') + '" data-sec="' + esc(x[0]) + '" style="--c:' + x[2] + '"><span class="st-ic">' + x[1] + '</span><b>' + fmt(sec[x[0]]) + '</b><small>' + esc(x[0]) + '</small></button>'; }).join('');
    }
    function topics() {
      var tc = {};
      for (var k in (L.data && L.data.topics) || {}) { var p = k.split('|'); if (!L.section || p[0] === L.section) tc[p[1]] = (tc[p[1]] || 0) + L.data.topics[k]; }
      if (L.topic && !tc[L.topic]) L.topic = '';
      var tp = Object.keys(tc).sort(function (a, b) { return tc[b] - tc[a]; }), all = 0; tp.forEach(function (t) { all += tc[t]; });
      document.getElementById('lib-topics').innerHTML = '<button class="' + (L.topic ? '' : 'on') + '" data-topic=""><span>' + T('All topics') + '</span><i>' + fmt(all) + '</i></button>' + tp.map(function (t) { return '<button class="' + (L.topic === t ? 'on' : '') + '" data-topic="' + esc(t) + '"><span>' + esc(t) + '</span><i>' + fmt(tc[t]) + '</i></button>'; }).join('');
      var ex = document.getElementById('lib-export'); ex.hidden = L.section === (LIB.audio || 'Audio');
      ex.href = '?api=library&export=txt&type=sections&section=' + encodeURIComponent(L.section) + '&topic=' + encodeURIComponent(L.topic) + (document.getElementById('lib-short').checked ? '&min=0' : '');
    }
    function player(r) {
      var url = mediaUrl(r.attachment);
      if (url) return '<div class="player"><audio controls preload="none" src="' + url + '"></audio><a class="icon-a" href="' + url + '" download title="' + T('Download') + '">⬇️</a></div>';
      return r.fetchable ? '<button class="chip-btn sm" data-fetch="' + esc(r.id) + '">⬇️ ' + T('Fetch from WhatsApp') + '</button>' : '<span class="muted small">' + T('File not kept') + '</span>';
    }
    function card(g, i) {
      var si = secInfo(g.section), b = g.best;
      var head = '<div class="lc-top"><span class="lc-sec" style="--c:' + si[2] + '">' + si[1] + ' ' + esc(g.section) + '</span><span class="lc-topic">' + esc(g.topic) + '</span>'
        + (g.audio ? '' : '<span class="score" title="' + esc(scoreTip(b.parts)) + '">⭐ ' + b.score + '</span>') + '</div>'
        + '<h3 class="lc-t" dir="auto">' + esc(g.title) + '</h3>'
        + (g.cover ? '<img class="lc-cover" loading="lazy" alt="" src="' + mediaUrl(g.cover) + '">' : '')
        + (g.code ? '<div class="lc-code" dir="auto">🔑 ' + esc(g.code) + '</div>' : '')
        + (g.keywords ? '<div class="lc-kw muted small" dir="auto">🏷️ ' + esc(g.keywords) + '</div>' : '')
        + (g.ai && g.ai.quality ? '<div class="lc-ai' + (g.ai.valid === false ? ' bad' : '') + '">🤖 ' + (g.ai.quality ? '★'.repeat(g.ai.quality) + '☆'.repeat(5 - g.ai.quality) : '') + (g.ai.valid === false ? ' · ⚠️ ' + T('Needs checking') : ' · ✓ ' + T('Checked')) + (g.ai.note ? ' · ' + esc(g.ai.note) : '') + '</div>' : '');
      var meta = '<div class="lc-meta"><span>🧬 ' + fmt(g.versions) + ' ' + T(g.audio ? 'copies' : 'versions') + '</span>' + (g.audio ? '' : '<span>🔁 ' + fmt(g.total) + '× ' + T('shared') + '</span>') + '<span>💬 ' + fmt(g.chats) + ' ' + T('chats') + '</span><span>🕐 ' + esc(when(b.last || b.time, true)) + '</span></div>';
      var body;
      if (g.audio) {
        body = vcard(b, i, true, true)
          + (g.others.length ? '<details class="lc-more"><summary>' + fmt(g.others.length) + ' ' + T('other copies') + '</summary><div class="row" style="margin:8px 0"><button class="chip-btn sm" data-pick-dupes="' + i + '">☑️ ' + T('Select duplicates (keep the best)') + '</button></div><div class="vgrid">' + g.others.map(function (o) { return vcard(o, i, false, true); }).join('') + '</div></details>' : '');
      } else {
        body = (g.refs && g.refs.length ? '<div class="refs">' + g.refs.slice(0, 10).map(function (r) { return '<span class="ref">📖 ' + esc(r.surah) + ' ' + r.a + '</span>'; }).join('') + (g.refs.length > 10 ? '<span class="ref">+' + (g.refs.length - 10) + '</span>' : '') + '</div>' : '')
          + vcard(b, i, true, false)
          + (g.others.length ? '<details class="lc-more"><summary>' + fmt(g.others.length) + ' ' + T('other versions') + ' — ' + T('each with its date and time') + '</summary><div class="row" style="margin:8px 0"><button class="chip-btn sm" data-pick-dupes="' + i + '">☑️ ' + T('Select duplicates (keep the best)') + '</button></div><div class="vgrid">' + g.others.map(function (o) { return vcard(o, i, false, false); }).join('') + '</div></details>' : '');
      }
      var ai = g.ai || {}, gk = g.gkey || (b && b.key) || '', aiBox = '';
      if (!g.audio && ai.compare && ai.compare.reason) aiBox += '<div class="ai-box"><b>🏆 ' + T('AI picked the best version') + '</b><div dir="auto">' + esc(ai.compare.reason) + '</div>' + (ai.compare.differences ? '<div class="muted small" dir="auto">↔️ ' + esc(ai.compare.differences) + '</div>' : '') + '</div>';
      if (!g.audio && ai.formatted) aiBox += '<details class="ai-box"><summary>✨ ' + T('AI formatted version') + '</summary><div class="vc-t" dir="auto">' + esc(ai.formatted) + '</div><button class="link-btn" data-copy-f="' + i + '">📋 ' + T('Copy') + '</button></details>';
      var actions = '<div class="lc-actions">' + (g.audio ? '' : '<button class="chip-btn sm" data-copy="' + i + '">📋 ' + T('Copy') + '</button>')
        + (g.audio ? '' : (g.others.length ? '<button class="chip-btn sm" data-ai-do="compare" data-gk="' + esc(gk) + '">🏆 ' + T('Compare & pick best') + '</button>' : '') + '<button class="chip-btn sm" data-ai-do="format" data-gk="' + esc(gk) + '">✨ ' + T('Format with AI') + '</button>')
        + (g.audio ? '' : g.manual ? '<button class="chip-btn sm" data-lt-edit="' + i + '">✏️ ' + T('Edit') + '</button><button class="chip-btn sm bad-t" data-lt-del="' + i + '">🗑️ ' + T('Delete') + '</button>' : '<button class="chip-btn sm" data-lt-edit="' + i + '" title="' + esc(T('Make it a text you can edit, with a tag and keywords')) + '">✏️ ' + T('Edit as my text') + '</button>')
        + (g.audio && b.attachment ? '<button class="chip-btn sm" data-lname="' + esc(b.attachment) + '">✏️ ' + T('Name & shortcut') + '</button>' : '')
        + (g.audio ? (b.attachment ? '<button class="chip-btn sm primary" data-lfw-att="' + esc(b.attachment) + '">↪ ' + T('Send to chats and groups') + '</button>' : '') : '<button class="chip-btn sm primary" data-lfw-txt="' + i + '">↪ ' + T('Send to chats and groups') + '</button>')
        + '<a class="chip-btn sm" href="?p=inbox#' + encodeURIComponent(b.jid) + '">💬 ' + T('Open chat') + '</a></div>';
      body += aiBox;
      return '<article class="lcard" style="--c:' + si[2] + '">' + head + meta + body + actions + '</article>';
    }
    var current = [];
    function draw() {
      current = list();
      var grid = document.getElementById('lib-grid');
      grid.className = 'lib-grid ' + L.view;
      document.getElementById('lib-count').textContent = fmt(current.length) + ' ' + T('groups') + (L.section ? ' · ' + L.section : '') + (L.topic ? ' · ' + L.topic : '');
      grid.innerHTML = current.slice(0, L.shown).map(card).join('') || '<div class="card empty">' + T('Nothing found.') + '</div>';
      document.getElementById('lib-more').hidden = current.length <= L.shown;
      selBar();
    }
    function load() {
      document.getElementById('lib-grid').innerHTML = '<div class="card empty">' + T('Loading…') + '</div>';
      api('library', { type: 'sections', q: document.getElementById('lib-q').value || '', min: document.getElementById('lib-short').checked ? 0 : 60, view: document.getElementById('lib-archived').checked ? 'archived' : '' }).then(function (r) {
        if (!r.ok) { document.getElementById('lib-grid').innerHTML = '<div class="card empty">' + esc(r.error || '') + '</div>'; return; }
        L.data = r; L.shown = 40; tiles(); topics(); draw();
      });
    }
    [].forEach.call(document.querySelectorAll('#lib-view button'), function (b) { b.classList.toggle('on', b.dataset.v === L.view); });
    function mq() {
      api('media_queue').then(function (q) {
        if (!q.ok) { document.getElementById('mq-st').textContent = q.error || ''; return; }
        var tot = q.done + q.failed + q.expired + q.pending, pc = tot ? Math.round((q.done + q.failed + q.expired) / tot * 100) : 0;
        document.getElementById('mq-bar').style.width = pc + '%';
        document.getElementById('mq-st').innerHTML = (q.pending ? '⏳ ' + T('Downloading…') + ' ' + fmt(q.pending) + ' ' + T('left') + '<br>' : '') + '✓ ' + fmt(q.done) + ' · ' + T('expired on WhatsApp') + ' ' + fmt(q.expired) + ' · ' + T('failed') + ' ' + fmt(q.failed);
      });
    }
    // ➕ a text written by hand (a written ruqyah, a price list…): add, edit, delete — with a cover, a tag, a title, a topic and keywords
    function textDialog(g) {
      var t = g ? { id: g.manual ? g.mid : '', section: g.section, topic: g.topic, title: g.title, code: g.code || '', keywords: g.keywords || '', text: g.best.text, cover: g.cover || '' }
        : { id: '', section: L.section || (SEC[1] || [''])[0], topic: L.topic || '', title: '', code: '', keywords: '', text: '', cover: '' };
      var secs = SEC.filter(function (x) { return x[0] !== (LIB.audio || 'Audio'); }), tps = {}; ((L.data && L.data.topics) || {}) && Object.keys((L.data && L.data.topics) || {}).forEach(function (k) { tps[k.split('|')[1]] = 1; });
      var d = mlDialog('<div class="ml-head"><b>' + (t.id ? '✏️ ' + T('Edit text') : '➕ ' + T('Add text')) + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-fw lt-form">'
        + '<label>' + T('Section') + '<select id="lt-sec">' + secs.map(function (x) { return '<option value="' + esc(x[0]) + '"' + (x[0] === t.section ? ' selected' : '') + '>' + x[1] + ' ' + esc(x[0]) + '</option>'; }).join('') + '</select></label>'
        + '<label>' + T('Title') + '<input id="lt-title" dir="auto" maxlength="120" value="' + esc(t.title) + '"></label>'
        + '<label>' + T('Topic') + '<input id="lt-topic" dir="auto" maxlength="60" list="lt-topics" value="' + esc(t.topic) + '"><datalist id="lt-topics">' + Object.keys(tps).map(function (x) { return '<option value="' + esc(x) + '">'; }).join('') + '</datalist></label>'
        + '<label>' + T('Tag — write it in any chat or group and the text is sent') + '<input id="lt-code" dir="auto" maxlength="60" value="' + esc(t.code) + '" placeholder="' + esc(T('#ruqyah-name')) + '"></label>'
        + '<label>' + T('Keywords — separated by commas') + '<input id="lt-kw" dir="auto" maxlength="300" value="' + esc(t.keywords) + '"></label>'
        + '<label>' + T('Text') + '<textarea id="lt-text" rows="8" dir="auto">' + esc(t.text) + '</textarea></label>'
        + '<div class="lt-cover"><b>' + T('Cover') + '</b><div id="lt-prev"></div><input type="file" id="lt-file" accept="image/jpeg,image/png,image/webp"><button type="button" class="chip-btn sm" id="lt-rm">✕ ' + T('Remove cover') + '</button></div>'
        + '<div class="row"><button type="button" class="btn primary" id="lt-go">💾 ' + T('Save') + '</button><span class="muted small" id="lt-m"></span></div></div>');
      var prev = function () { document.getElementById('lt-prev').innerHTML = t.cover ? '<img class="lc-cover" alt="" src="' + mediaUrl(t.cover) + '">' : ''; document.getElementById('lt-rm').hidden = !t.cover; }; prev();
      document.getElementById('lt-file').onchange = function () { var f = this.files[0]; if (!f) return; var fd = new FormData(); fd.append('file', f); fd.append('cover', '1'); document.getElementById('lt-m').textContent = '⏳';
        fetch('?api=lib_upload', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': lcsrf }, body: fd }).then(wiSafeJson, wiNetFail).then(function (r) { document.getElementById('lt-m').textContent = r.ok ? '' : '⚠️ ' + (r.error || T('Failed')); if (r.ok) { t.cover = r.key; prev(); } }); };
      d.onclick = function (ev) { if (ev.target.closest('[data-mlx]')) { d.close(); return; }
        if (ev.target.closest('#lt-rm')) { t.cover = ''; prev(); return; }
        if (!ev.target.closest('#lt-go')) return;
        WI.post('lib_text_save', { id: t.id, section: document.getElementById('lt-sec').value, title: document.getElementById('lt-title').value, topic: document.getElementById('lt-topic').value, code: document.getElementById('lt-code').value, keywords: document.getElementById('lt-kw').value, text: document.getElementById('lt-text').value, cover: t.cover }).then(function (r) {
          if (!r.ok) { document.getElementById('lt-m').textContent = '⚠️ ' + (r.error || T('Failed')); return; } d.close(); load(); }); };
    }
    document.getElementById('lib-add').addEventListener('click', function () { textDialog(null); });
    document.getElementById('lib-up').addEventListener('change', function () { var fs = [].slice.call(this.files), inp = this, ok = 0, bad = '';
      (function next() { var f = fs.shift(); if (!f) { inp.value = ''; mlToast(ok ? '✓ ' + fmt(ok) + ' ' + T('files added — find them in Media') : '⚠️ ' + bad); return; }
        var fd = new FormData(); fd.append('file', f); mlToast('⏳ ' + f.name);
        fetch('?api=lib_upload', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': lcsrf }, body: fd }).then(wiSafeJson, wiNetFail).then(function (r) { if (r.ok) ok++; else bad = r.error || T('Failed'); next(); }); })(); });
    document.addEventListener('click', function (e) {
      var ed = e.target.closest('[data-lt-edit]'); if (ed) { textDialog(current[+ed.dataset.ltEdit]); return; }
      var del = e.target.closest('[data-lt-del]'); if (del) { var g = current[+del.dataset.ltDel]; if (g && confirm(T('Delete this text?') + '\n' + g.title)) WI.post('lib_text_delete', { id: g.mid }).then(function () { load(); }); }
    });
    mq(); setInterval(mq, WIR(10000));
    // ↪ a recording or a text of the library to chats and groups (sent from the server, one by one)
    // ✏️ a recording's name and shortcut (writing the shortcut in a chat or group sends the file)
    document.addEventListener('click', function (e) { var b = e.target.closest('[data-lname]'); if (!b || typeof mlLoad !== 'function') return; var key = b.dataset.lname;
      mlLoad(true).then(function (items) { var it = items.filter(function (i) { return i.key === key; })[0] || { title: '', code: '' };
        var d = mlDialog('<div class="ml-head"><b>✏️ ' + T('Name & shortcut') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-fw">'
          + '<label>' + T('Name') + '<input id="ln-t" dir="auto" maxlength="80" value="' + esc(it.title || '') + '" placeholder="' + esc(T('e.g. Ruqyah Al-Athiri')) + '"></label>'
          + '<label>' + T('Shortcut — write it in any chat or group and the file is sent') + '<input id="ln-c" dir="auto" maxlength="60" value="' + esc(it.code || '') + '" placeholder="' + esc(T('#ruqyah-name')) + '"></label>'
          + '<div class="row"><button type="button" class="btn primary" id="ln-go">💾 ' + T('Save') + '</button><span class="muted small" id="ln-m"></span></div></div>');
        d.onclick = function (ev) { if (ev.target.closest('[data-mlx]')) { d.close(); return; } if (!ev.target.closest('#ln-go')) return;
          var sent = { title: document.getElementById('ln-t').value.trim(), code: document.getElementById('ln-c').value.trim() };
          WI.post('media_meta', { key: key, title: sent.title, code: sent.code }).then(function (r) {
            if (!r.ok) { document.getElementById('ln-m').textContent = '⚠️ ' + (r.error || T('Failed')); return; } ML.items = null; d.close();
            var nt = sent.title, nc = sent.code; // the card shows it at once, no refresh
            ((L.data && L.data.groups) || []).forEach(function (g) { if (g.audio && [g.best].concat(g.others || []).some(function (v) { return v.attachment === key; })) { if (nt) g.title = nt; g.code = nc; } });
            draw(); }); }; }); });
    document.addEventListener('click', function (e) { var b = e.target.closest('[data-lfw-att],[data-lfw-txt]'); if (!b || typeof mlForward !== 'function') return;
      if (b.dataset.lfwAtt) mlForward(b.dataset.lfwAtt, ''); else { var g = current[+b.dataset.lfwTxt]; if (g && g.best) mlForward(null, g.best.text || ''); } });
    document.getElementById('mq-go').addEventListener('click', function () {
      var b = this; b.disabled = true;
      fetch('?api=media_queue', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': lcsrf } }).then(wiSafeJson, wiNetFail).then(function (r) { b.disabled = false; b.textContent = r.ok ? '✓ ' + fmt(r.queued) + ' ' + T('queued') : (r.error || ''); mq(); });
    });
    document.getElementById('sec-tiles').addEventListener('click', function (e) { var b = e.target.closest('[data-sec]'); if (!b) return; L.section = b.dataset.sec; L.topic = ''; L.shown = 40; tiles(); topics(); draw(); });
    document.getElementById('lib-topics').addEventListener('click', function (e) { var b = e.target.closest('[data-topic]'); if (!b) return; L.topic = b.dataset.topic; L.shown = 40; topics(); draw(); });
    document.getElementById('lib-sort').addEventListener('change', function () { L.sort = this.value; draw(); });
    document.getElementById('lib-view').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; L.view = b.dataset.v; try { localStorage.setItem('wi_lib_view', L.view); } catch (x) {} [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); draw(); });
    document.getElementById('lib-q').addEventListener('input', function () { clearTimeout(L.timer); L.timer = setTimeout(load, 350); });
    document.getElementById('lib-short').addEventListener('change', load);
    document.getElementById('lib-more').addEventListener('click', function () { L.shown += 40; draw(); });
    document.getElementById('lib-grid').addEventListener('change', function (e) {
      var pk = e.target.closest('[data-pick]'); if (!pk) return;
      var k = pk.getAttribute('data-pick'); if (pk.checked) L.sel[k] = 1; else delete L.sel[k];
      pk.closest('.vcard').classList.toggle('picked', pk.checked); selBar();
    });
    function hide(state) {
      var keys = Object.keys(L.sel); if (!keys.length) return;
      if (state === 'deleted' && !confirm(T('Remove the selected items from the library? (Nothing is deleted from WhatsApp.)'))) return;
      fetch('?api=lib_hide', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': lcsrf }, body: JSON.stringify({ keys: keys, state: state }) })
        .then(wiSafeJson, wiNetFail).then(function (r) { if (r.ok) { L.sel = {}; load(); } else alert(r.error || ''); });
    }
    document.getElementById('sel-archive').addEventListener('click', function () { hide('archived'); });
    document.getElementById('sel-delete').addEventListener('click', function () { hide('deleted'); });
    document.getElementById('sel-restore').addEventListener('click', function () { hide('restore'); });
    document.getElementById('sel-clear').addEventListener('click', function () { L.sel = {}; draw(); });
    // Select every version of all groups shown (current section/topic/search), or only the duplicates (keeps the best of each).
    document.getElementById('lib-all').addEventListener('click', function () { current.forEach(function (g) { [g.best].concat(g.others).forEach(function (v) { if (v.key) L.sel[v.key] = 1; }); }); draw(); });
    document.getElementById('lib-same').addEventListener('click', function () { current.forEach(function (g, gi) { g.others.forEach(function (v) { if (v.key && identical(v, gi)) L.sel[v.key] = 1; }); }); draw(); });
    document.getElementById('lib-all-dupes').addEventListener('click', function () { current.forEach(function (g) { g.others.forEach(function (v) { if (v.key) L.sel[v.key] = 1; }); }); draw(); });
    document.getElementById('lib-archived').addEventListener('change', function () { L.sel = {}; load(); });
    (function () {
      var btn = document.getElementById('lib-best'), st = document.getElementById('lib-best-st'), run = false, label = btn ? btn.textContent : '';
      if (!btn) return;
      btn.addEventListener('click', function () {
        if (run) { run = false; btn.textContent = label; return; }
        run = true; btn.textContent = '⏹ ' + T('Stop');
        (function step() {
          if (!run) return;
          st.textContent = T('AI is comparing versions…');
          fetch('?api=lib_ai_best', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': lcsrf }, body: '{}' }).then(wiSafeJson, wiNetFail).then(function (r) {
            if (!r.ok) { run = false; btn.textContent = label; st.textContent = '⚠️ ' + (r.error || T('Failed')); return; }
            st.textContent = '✓ ' + fmt(r.total - r.remaining) + ' / ' + fmt(r.total);
            if (r.remaining > 0 && run) setTimeout(step, 1500); else { run = false; btn.textContent = label; load(); }
          }).catch(function () { run = false; btn.textContent = label; st.textContent = '⚠️ ' + T('Failed'); });
        })();
      });
    })();
    (function () {
      var btn = document.getElementById('lib-ai'), st = document.getElementById('lib-ai-st'), run = false;
      if (!btn) return;
      btn.addEventListener('click', function () {
        if (run) { run = false; btn.textContent = '🤖 ' + T('Sort & check with AI'); return; }
        run = true; btn.textContent = '⏹ ' + T('Stop'); var got = 0;
        (function step() {
          if (!run) return;
          st.textContent = T('AI is reviewing…') + ' ' + fmt(got);
          fetch('?api=lib_ai', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': lcsrf }, body: '{}' }).then(wiSafeJson, wiNetFail).then(function (r) {
            if (!r.ok) { run = false; btn.textContent = '🤖 ' + T('Sort & check with AI'); st.textContent = '⚠️ ' + (r.error || T('Failed')); return; }
            got += r.reviewed; st.textContent = '✓ ' + fmt(r.total - r.remaining) + ' / ' + fmt(r.total) + ' · ' + (r.model || '');
            if (r.remaining > 0 && run) setTimeout(step, 1500); else { run = false; btn.textContent = '🤖 ' + T('Sort & check with AI'); load(); }
          }).catch(function () { run = false; btn.textContent = '🤖 ' + T('Sort & check with AI'); st.textContent = '⚠️ ' + T('Failed'); });
        })();
      });
    })();
    document.getElementById('lib-grid').addEventListener('click', function (e) {
      var vt = e.target.closest('.vc-t, .lc-text'); if (vt && !window.getSelection().toString()) { vt.classList.toggle('clamp'); var nx = vt.nextElementSibling; if (nx && nx.hasAttribute('data-expand')) nx.textContent = vt.classList.contains('clamp') ? T('Read more') + ' ▾' : T('Show less') + ' ▴'; return; }
      var ad = e.target.closest('[data-ai-do]');
      if (ad) { var lbl = ad.textContent; ad.disabled = true; ad.textContent = '⏳ ' + T('AI is working…');
        WI.post('lib_ai_group', { key: ad.dataset.gk, do: ad.dataset.aiDo })
          .then(function (r) { if (r.ok) load(); else { ad.disabled = false; ad.textContent = lbl; alert(r.error || T('Failed')); } }).catch(function () { ad.disabled = false; ad.textContent = lbl; });
        return; }
      var cf = e.target.closest('[data-copy-f]');
      if (cf) { var gf = current[Number(cf.dataset.copyF)]; if (gf && gf.ai && navigator.clipboard) navigator.clipboard.writeText(gf.ai.formatted); cf.textContent = '✓ ' + T('Copied'); return; }
      var dp = e.target.closest('[data-pick-dupes]');
      if (dp) { var g = current[Number(dp.dataset.pickDupes)]; g.others.forEach(function (o) { if (o.key) L.sel[o.key] = 1; }); draw(); return; }
      var cv = e.target.closest('[data-copy-v]');
      if (cv) { var pr = cv.dataset.copyV.split(':'), gg = current[Number(pr[0])], vv = [gg.best].concat(gg.others).filter(function (x) { return x.key === pr.slice(1).join(':'); })[0]; if (vv && navigator.clipboard) navigator.clipboard.writeText(vv.text); cv.textContent = '✓ ' + T('Copied'); return; }
      var ex = e.target.closest('[data-expand]');
      if (ex) { var t = ex.previousSibling; t.classList.toggle('clamp'); ex.textContent = t.classList.contains('clamp') ? T('Read more') + ' ▾' : T('Show less') + ' ▴'; return; }
      var cp = e.target.closest('[data-copy]');
      if (cp) { var v = current[Number(cp.dataset.copy)].best.text; if (navigator.clipboard) navigator.clipboard.writeText(v); cp.textContent = '✓ ' + T('Copied'); return; }
      var f = e.target.closest('[data-fetch]');
      if (f) { f.disabled = true; f.textContent = T('Downloading…');
        WI.post('fetch_media', { id: f.dataset.fetch })
          .then(function (r) { if (r.ok) load(); else f.textContent = r.error || T('Failed'); }); }
    });
    load();
  }

  /* ---------------- Analytics & data ---------------- */
  if (page === 'analytics') {
    var anDays = 30, anData = null, anEnt = 'phones';
    function mins(m) { if (m === null || m === undefined) return '—'; m = Number(m); return m < 60 ? m + ' ' + T('min') : (m < 1440 ? Math.round(m / 60) + ' ' + T('h') : Math.round(m / 1440) + ' ' + T('d')); }
    function chatLink(jid) { return '?p=inbox#' + encodeURIComponent(jid); }
    function person(c) { return c.customer ? c.customer.name : (c.name || (c.phone ? '+' + c.phone : (c.group ? T('Group') : String(c.jid).split('@')[0]))); }
    function pRow(c, right, sub) { return '<a class="p-row" href="' + chatLink(c.jid) + '">' + av(c.jid, person(c)) + '<div class="p-b"><b dir="auto">' + esc(person(c)) + (c.customer ? ' <span class="tag">' + T('Customer') + '</span>' : '') + '</b><small class="muted" dir="ltr">' + (sub || (c.phone ? '+' + esc(c.phone) : '')) + '</small></div>' + right + '</a>'; }
    function drawEnt() {
      var q = (document.getElementById('an-q').value || '').toLowerCase();
      var rows = (anData.entities[anEnt] || []).filter(function (e) { return !q || e.value.toLowerCase().indexOf(q) !== -1; });
      document.getElementById('an-rows').innerHTML = rows.slice(0, 300).map(function (e) {
        var v = anEnt === 'links' ? '<a href="' + esc(safeUrl(e.value)) + '" target="_blank" rel="noopener noreferrer" dir="ltr">' + esc(e.value.length > 70 ? e.value.slice(0, 70) + '…' : e.value) + '</a>' : (anEnt === 'emails' ? '<a href="mailto:' + esc(e.value) + '" dir="ltr">' + esc(e.value) + '</a>' : '<span dir="auto" class="mono">' + esc(e.value) + '</span>');
        var extra = (e.customer ? ' <span class="tag">' + esc(e.customer.name) + '</span>' : '') + (anEnt === 'phones' && e.norm ? ' <a class="chip-btn sm" target="_blank" rel="noopener" href="https://wa.me/' + esc(e.norm) + '">WhatsApp</a>' : '');
        return '<tr><td>' + v + extra + '</td><td><span class="pill">' + fmt(e.count) + '</span></td><td>' + e.chats.slice(0, 3).map(function (j) { return '<a class="chip-btn sm" href="' + chatLink(j) + '">' + esc(j.split('@')[0].slice(-6)) + '</a>'; }).join(' ') + (e.chats.length > 3 ? ' <small class="muted">+' + (e.chats.length - 3) + '</small>' : '') + '</td><td class="muted small">' + esc(when(e.last, true)) + '</td><td><button class="icon-a" data-copy-v="' + esc(e.value) + '" title="' + T('Copy') + '">📋</button></td></tr>';
      }).join('') || '<tr><td colspan="5" class="empty">' + T('Nothing found.') + '</td></tr>';
      document.getElementById('an-csv').href = '?api=analyze&days=' + anDays + '&csv=' + anEnt;
    }
    function load() {
      document.getElementById('an-rows').innerHTML = '<tr><td colspan="5" class="empty">' + T('Loading…') + '</td></tr>';
      api('analyze', { days: anDays }).then(function (d) {
        if (!d.ok) { document.getElementById('an-kpis').innerHTML = '<div class="card empty">' + esc(d.error || '') + '</div>'; return; }
        anData = d;
        document.getElementById('an-kpis').innerHTML =
          kpi('💬', T('Messages'), d.total, fmt(d.received) + ' ' + T('received') + ' · ' + fmt(d.sent) + ' ' + T('sent'), 'rgba(16,185,129,.16)') +
          kpi('👥', T('Conversations'), d.chats, '', 'rgba(14,165,233,.16)') +
          '<div class="kpi" style="--glow:rgba(99,102,241,.16)"><div class="k-ic">⚡</div><small>' + T('Typical reply time') + '</small><b>' + mins(d.response.median) + '</b><em>' + T('average') + ' ' + mins(d.response.avg) + '</em></div>' +
          kpi('⏳', T('Waiting for your reply'), d.waiting.length, '', 'rgba(239,68,68,.14)');
        // daily bars
        var pd = d.per_day.slice(-Math.min(d.per_day.length, anDays > 90 ? 120 : anDays)), mx = 1;
        pd.forEach(function (x) { mx = Math.max(mx, x.in + x.out); });
        document.getElementById('an-daily').innerHTML = pd.length ? '<div class="bars">' + pd.map(function (x) { var dt = new Date(x.date); return '<div class="bar2" title="' + esc(x.date) + ' — ' + T('received') + ' ' + x.in + ' · ' + T('sent') + ' ' + x.out + '"><span class="o" style="height:' + (x.out / mx * 100) + '%"></span><span class="i" style="height:' + (x.in / mx * 100) + '%"></span></div>'; }).join('') + '</div><div class="bars-x"><small>' + esc(pd[0].date) + '</small><small>' + esc(pd[pd.length - 1].date) + '</small></div>' : '<div class="empty">—</div>';
        // reply speed gauge
        var w15 = d.response.within15 || 0;
        document.getElementById('an-speed').innerHTML = '<div class="gauge" style="--p:' + w15 + '"><div><b>' + (d.response.within15 === null ? '—' : w15 + '%') + '</b><small>' + T('within 15 min') + '</small></div></div>'
          + '<dl class="kv"><dt>' + T('Typical reply time') + '</dt><dd>' + mins(d.response.median) + '</dd><dt>' + T('average') + '</dt><dd>' + mins(d.response.avg) + '</dd><dt>' + T('Replies measured') + '</dt><dd>' + fmt(d.response.count) + '</dd></dl>';
        // heatmap
        var max = 1; d.heat.forEach(function (r) { r.forEach(function (v) { max = Math.max(max, v); }); });
        var dn = [0, 1, 2, 3, 4, 5, 6].map(function (d) { return new Date(2023, 0, 1 + d).toLocaleDateString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { weekday: AR ? 'long' : 'short' }); }); // Sunday first, in the chosen locale
        var h = '<div class="heat"><div></div>' + Array.from({ length: 24 }, function (_, i) { return '<small>' + (i % 3 === 0 ? i : '') + '</small>'; }).join('');
        d.heat.forEach(function (r, di) { h += '<small>' + dn[di] + '</small>' + r.map(function (v, hi) { return '<span title="' + dn[di] + ' ' + hi + ':00 — ' + v + '" style="opacity:' + (v ? 0.12 + 0.88 * v / max : 0.05) + '"></span>'; }).join(''); });
        document.getElementById('an-heat').innerHTML = h + '</div><div class="heat-l"><small>' + T('Less') + '</small><span style="opacity:.15"></span><span style="opacity:.4"></span><span style="opacity:.7"></span><span></span><small>' + T('More') + '</small></div>';
        document.getElementById('an-wait').innerHTML = d.waiting.slice(0, 12).map(function (c) { return pRow(c, '<span class="badge ' + (c.waiting_minutes > 1440 ? 'bad' : 'warn') + '">' + mins(c.waiting_minutes) + '</span>'); }).join('') || '<div class="empty">' + T('Nobody is waiting 🎉') + '</div>';
        var tmax = d.top.length ? d.top[0].in + d.top[0].out : 1;
        document.getElementById('an-top').innerHTML = d.top.slice(0, 12).map(function (c) { return pRow(c, '<span class="n">' + fmt(c.in + c.out) + '</span>', '<span class="meter"><span style="width:' + Math.max(6, Math.round((c.in + c.out) / tmax * 100)) + '%"></span></span>'); }).join('');
        var wmax = d.words.length ? d.words[0].n : 1;
        document.getElementById('an-words').innerHTML = d.words.map(function (x) { var r = x.n / wmax; return '<span style="font-size:' + (12 + Math.round(r * 18)) + 'px;opacity:' + (0.55 + 0.45 * r) + '" title="' + x.n + '">' + esc(x.w) + '</span>'; }).join(' ');
        document.getElementById('an-wait-csv').href = '?api=analyze&days=' + anDays + '&csv=waiting';
        document.getElementById('an-top-csv').href = '?api=analyze&days=' + anDays + '&csv=top';
        [].forEach.call(document.querySelectorAll('#an-ent button'), function (b) { b.querySelector('i').textContent = fmt((d.entities[b.dataset.e] || []).length); });
        drawEnt();
      });
    }
    document.getElementById('an-days').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); anDays = Number(b.dataset.d); load(); });
    document.getElementById('an-ent').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); anEnt = b.dataset.e; drawEnt(); });
    document.getElementById('an-q').addEventListener('input', function () { if (anData) drawEnt(); });
    document.getElementById('an-rows').addEventListener('click', function (e) { var b = e.target.closest('[data-copy-v]'); if (!b) return; if (navigator.clipboard) navigator.clipboard.writeText(b.getAttribute('data-copy-v')); b.textContent = '✓'; });
    load();
  }

  /* ---------------- Broadcast ---------------- */
  if (page === 'broadcast') {
    var bcsrf = app.getAttribute('data-csrf'), lists = [], allChats = [], editing = null, picked = {}, bcFilter = '';
    function post(name, body) { return WI.post(name, body); }
    function drawLists() {
      document.getElementById('bc-lists').innerHTML = lists.length ? lists.map(function (l) { return '<div class="bc-l"><div><b>' + esc(l.name) + '</b><small class="muted">' + fmt(l.members.length) + ' ' + T('people') + '</small></div><div class="row"><button class="btn ghost sm" data-edit-list="' + esc(l.id) + '">' + T('Edit') + '</button><button class="btn danger sm" data-del-list="' + esc(l.id) + '">' + T('Delete') + '</button></div></div>'; }).join('') : '<div class="empty">' + T('No lists yet.') + '</div>';
      document.getElementById('bc-list').innerHTML = lists.map(function (l) { return '<option value="' + esc(l.id) + '">' + esc(l.name) + ' (' + l.members.length + ')</option>'; }).join('');
      est();
    }
    function est() { var l = lists.filter(function (x) { return x.id === document.getElementById('bc-list').value; })[0]; document.getElementById('bc-est').textContent = l ? T('Estimated time:') + ' ~' + Math.ceil(l.members.length * 45 / 60) + ' ' + T('min') + (l.members.length > 60 ? ' · ' + T('over 60 people continues the next day') : '') : ''; }
    function loadLists() { api('lists').then(function (r) { if (r.ok) { lists = r.lists || []; drawLists(); } else document.getElementById('bc-lists').innerHTML = '<div class="empty">' + esc(r.error || '') + '</div>'; }); }
    function drawPick() {
      var q = (document.getElementById('bc-q').value || '').toLowerCase(), n = 0;
      document.getElementById('bc-pick').innerHTML = allChats.filter(function (c) {
        if (c.group) return false;
        if (bcFilter === 'customers' && !c.customer) return false; if (bcFilter === 'others' && c.customer) return false;
        return !q || (title(c) + ' ' + (c.phone || '')).toLowerCase().indexOf(q) !== -1;
      }).map(function (c) { return '<label class="check"><input type="checkbox" data-jid="' + esc(c.jid) + '" ' + (picked[c.jid] ? 'checked' : '') + '> <span dir="auto">' + esc(title(c)) + '</span> <small class="muted" dir="ltr">' + (c.phone ? '+' + esc(c.phone) : '') + '</small>' + (c.customer ? '<span class="tag">' + T('Customer') + '</span>' : '') + '</label>'; }).join('') || '<div class="empty">' + T('No conversations.') + '</div>';
      for (var k in picked) n++; document.getElementById('bc-count').textContent = n + ' ' + T('selected');
    }
    function openDlg(list) {
      editing = list; picked = {};
      (list ? list.members : []).forEach(function (m) { picked[m.jid] = m.name || true; });
      document.getElementById('bc-name').value = list ? list.name : '';
      document.getElementById('bc-dlg-t').textContent = list ? T('Edit list') : T('New list');
      api('chats').then(function (r) { allChats = r.chats || []; drawPick(); });
      document.getElementById('bc-dlg').showModal();
    }
    document.getElementById('bc-new').addEventListener('click', function () { openDlg(null); });
    document.getElementById('bc-lists').addEventListener('click', function (e) {
      var ed = e.target.closest('[data-edit-list]'), dl = e.target.closest('[data-del-list]');
      if (ed) openDlg(lists.filter(function (l) { return l.id === ed.getAttribute('data-edit-list'); })[0]);
      if (dl && confirm(T('Delete this list?'))) post('lists', { delete: dl.getAttribute('data-del-list') }).then(function (r) { if (r.ok) { lists = r.lists; drawLists(); } });
    });
    document.getElementById('bc-q').addEventListener('input', drawPick);
    document.getElementById('bc-f').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); bcFilter = b.getAttribute('data-f'); drawPick(); });
    document.getElementById('bc-pick').addEventListener('change', function (e) { var cb = e.target; if (!cb.dataset.jid) return; var c = allChats.filter(function (x) { return x.jid === cb.dataset.jid; })[0]; if (cb.checked) picked[cb.dataset.jid] = c ? (c.customer ? c.customer.name : c.name) || '' : ''; else delete picked[cb.dataset.jid]; drawPick(); });
    document.getElementById('bc-all').addEventListener('click', function () { [].forEach.call(document.querySelectorAll('#bc-pick input[data-jid]'), function (cb) { var c = allChats.filter(function (x) { return x.jid === cb.dataset.jid; })[0]; picked[cb.dataset.jid] = c ? (c.customer ? c.customer.name : c.name) || '' : ''; }); drawPick(); });
    document.getElementById('bc-none').addEventListener('click', function () { picked = {}; drawPick(); });
    document.getElementById('bc-save').addEventListener('click', function (e) {
      e.preventDefault();
      var members = []; for (var k in picked) members.push({ jid: k, name: typeof picked[k] === 'string' ? picked[k] : '' });
      post('lists', { id: editing ? editing.id : '', name: document.getElementById('bc-name').value, members: members }).then(function (r) { if (r.ok) { lists = r.lists; drawLists(); document.getElementById('bc-dlg').close(); } else alert(r.error || ''); });
    });
    document.getElementById('bc-list').addEventListener('change', est);
    document.getElementById('bc-send').addEventListener('click', function () {
      var id = document.getElementById('bc-list').value, text = document.getElementById('bc-text').value.trim();
      if (!id || !text) { alert(T('Choose a list and write the message.')); return; }
      var l = lists.filter(function (x) { return x.id === id; })[0];
      if (!confirm(T('Send this message to') + ' ' + l.members.length + ' ' + T('people, slowly (30–60 s apart)?'))) return;
      post('broadcast', { list: id, text: text }).then(function (r) { if (r.ok) { alert(T('Queued:') + ' ' + r.queued + (r.skipped ? ' · ' + T('skipped (no chat yet):') + ' ' + r.skipped : '')); document.getElementById('bc-text').value = ''; loadJobs(); } else alert(r.error || ''); });
    });
    function loadJobs() {
      api('broadcast_status').then(function (r) {
        if (!r.ok) { document.getElementById('bc-jobs').innerHTML = '<div class="empty">' + esc(r.error || '') + '</div>'; return; }
        document.getElementById('bc-today').textContent = T('Sent today') + ': ' + r.sent_today + ' / ' + r.daily_max;
        document.getElementById('bc-jobs').innerHTML = (r.jobs || []).length ? '<table class="table"><thead><tr><th>' + T('Name') + '</th><th>' + T('Progress') + '</th><th>' + T('Status') + '</th><th></th></tr></thead><tbody>' + r.jobs.map(function (j) {
          var pc = j.total ? Math.round((j.sent + j.failed) / j.total * 100) : 0;
          return '<tr><td><b>' + esc(j.name) + '</b><div class="muted small" dir="auto">' + esc(j.text.slice(0, 80)) + '</div></td><td><div class="up-bar"><span style="width:' + pc + '%"></span></div><small>' + j.sent + ' ✓ · ' + j.failed + ' ✕ · ' + j.pending + ' ⏳ / ' + j.total + '</small></td><td><span class="badge">' + esc(T(j.status)) + '</span>' + (j.note ? '<div class="muted small">' + esc(j.note) + '</div>' : '') + '</td><td>'
            + (j.status === 'running' ? '<button class="btn ghost sm" data-bc="pause" data-id="' + j.id + '">⏸ ' + T('Pause') + '</button>' : '') + (j.status === 'paused' ? '<button class="btn ghost sm" data-bc="resume" data-id="' + j.id + '">▶ ' + T('Resume') + '</button>' : '')
            + (['running', 'paused'].indexOf(j.status) !== -1 ? '<button class="btn danger sm" data-bc="cancel" data-id="' + j.id + '">✕ ' + T('Cancel') + '</button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div class="empty">' + T('No broadcasts yet.') + '</div>';
      });
    }
    document.getElementById('bc-jobs').addEventListener('click', function (e) { var b = e.target.closest('[data-bc]'); if (!b) return; if (b.dataset.bc === 'cancel' && !confirm(T('Cancel this broadcast?'))) return; post('broadcast_control', { id: b.dataset.id, action: b.dataset.bc }).then(loadJobs); });
    loadLists(); loadJobs(); setInterval(loadJobs, WIR(15000));
  }

  /* ---------------- Users ---------------- */
  if (page === 'users') {
    // Users & permissions: one dialog, steps shown for the chosen role only, and a plain summary of what the user will be able to do.
    var f = function (id) { return document.getElementById(id); }, dlg = f('uf-dlg'), form = f('uf');
    var role = function () { var r = form.querySelector('input[name="role"]:checked'); return r ? r.value : 'viewer'; };
    function sync() { var r = role();
      [].forEach.call(form.querySelectorAll('.ux-step[data-for]'), function (s) { s.hidden = s.dataset.for.split(' ').indexOf(r) === -1; });
      [].forEach.call(form.querySelectorAll('[data-only]'), function (x) { x.hidden = x.dataset.only !== r; });
      // an employee never deletes chats or messages
      [].forEach.call(form.querySelectorAll('[data-never]'), function (x) { var no = x.dataset.never === r, i = x.querySelector('input'); x.classList.toggle('dim', no); i.disabled = no; if (no) i.checked = false; });
      [].forEach.call(form.querySelectorAll('[data-edit-only]'), function (x) { x.hidden = f('uf-do').value !== 'update_user'; });
      var own = f('uf-own') && f('uf-own').checked; if (f('uf-accs')) f('uf-accs').hidden = own;
      [].forEach.call(form.querySelectorAll('.ux-rc'), function (c) { c.classList.toggle('on', c.querySelector('input').checked); });
      [].forEach.call(form.querySelectorAll('.ux-ft'), function (c) { var i = c.querySelector('input'); c.classList.toggle('on', i && i.checked); });
      summary(); }
    function summary() { var r = role(), L = [], flag = function (k) { var i = form.querySelector('[data-flag="' + k + '"]'); return i && i.checked; };
      var nums = [].filter.call(form.querySelectorAll('input[name="accounts[]"]'), function (x) { return x.checked; }).length;
      if (r === 'super') L.push(T('Sees and manages everything: all numbers, users and system settings.'));
      else if (r === 'client') { L.push(T('Links their own number and runs everything of it.')); var on = ['can_bot', 'can_meetings', 'can_cases', 'can_backup'].filter(flag).map(function (k) { return form.querySelector('[data-flag="' + k + '"]').closest('.ux-ft').querySelector('b').textContent; });
        L.push(T('Extra features') + ': ' + (on.length ? on.join('، ') : T('none'))); L.push(T('Cannot add users, change their permissions or see system settings.')); }
      else { L.push((f('uf-own') && f('uf-own').checked) ? T('Links their own number after signing in.') : T('Numbers') + ': ' + nums);
        if (r === 'viewer') { var v = 0, e = 0; [].forEach.call(form.querySelectorAll('input[data-pg]:checked'), function (x) { if (x.value === 'view') v++; if (x.value === 'edit') e++; }); L.push('👁 ' + v + ' · ✏️ ' + e + ' ' + T('pages')); }
        else if (r === 'manager') L.push(T('All pages of their numbers, except users and technical pages.'));
        else if (r === 'admin') L.push(T('Runs their numbers and adds their own staff.'));
        if (r === 'lead' || r === 'agent') { var v2 = 0, e2 = 0; [].forEach.call(form.querySelectorAll('input[data-pg]:checked'), function (x) { if (x.value === 'view') v2++; if (x.value === 'edit') e2++; }); L.push('👁 ' + v2 + ' · ✏️ ' + e2 + ' ' + T('pages'));
          L.push(r === 'agent' ? T('Sees the chats given to them and the chats nobody has taken yet.') : T('Sees all chats of the chosen numbers.'));
          var cp = [].filter.call(form.querySelectorAll('input[data-chat]'), function (x) { return x.checked; }).map(function (x) { return x.closest('.ux-ft').querySelector('b').textContent; });
          L.push(T('In a conversation') + ': ' + (cp.length ? cp.join('، ') : T('read only'))); }
        if (flag('can_reply') && r !== 'lead' && r !== 'agent') L.push(T('Can reply in chats')); }
      f('uf-sum').innerHTML = '<b>✅ ' + T('This user will be able to') + '</b><ul>' + L.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>'; }
    function open(edit) { form.reset(); f('uf-do').value = edit ? 'update_user' : 'add_user'; f('uf-email').readOnly = !!edit;
      f('uf-pw').required = !edit; f('uf-pw-l').querySelector('span').textContent = edit ? T('New password (optional)') : T('Password (at least 10 characters)');
      f('uf-title').textContent = edit ? T('Edit') + ' — ' + edit : T('Add user'); if (!edit) applyDefaults(); sync(); dlg.showModal(); }
    // A client's panel: a new supervisor / employee starts with the saved defaults of that role.
    var DEF = JSON.parse(dlg.getAttribute('data-defaults') || '{}');
    function fillPerms(pgs, chat) { [].forEach.call(form.querySelectorAll('input[data-pg]'), function (x) { x.checked = ((pgs || {})[x.dataset.pg] || '') === x.value; });
      [].forEach.call(form.querySelectorAll('input[data-chat]'), function (x) { x.checked = !!(chat || {})[x.dataset.chat]; }); }
    function applyDefaults() { var d = DEF[role()]; if (d) fillPerms(d.pages, d.chat); }
    form.addEventListener('change', function (e) { if (e.target.name === 'role' && f('uf-do').value === 'add_user') { applyDefaults(); sync(); } });
    if (f('uf-def')) f('uf-def').addEventListener('click', function () { f('uf-do').value = 'team_roles'; });
    f('uf-new').addEventListener('click', function () { open(null); });
    form.addEventListener('change', sync);
    form.addEventListener('click', function (e) { var b = e.target.closest('[data-all]'); if (!b) return;
      [].forEach.call(form.querySelectorAll('input[data-pg][value="' + b.dataset.all + '"]'), function (x) { x.checked = true; }); summary(); });
    [].forEach.call(dlg.querySelectorAll('[data-close]'), function (b) { b.addEventListener('click', function () { dlg.close(); }); });
    document.addEventListener('click', function (e) { var b = e.target.closest('[data-edit]'); if (!b) return; open(b.getAttribute('data-edit'));
      f('uf-email').value = b.getAttribute('data-edit'); f('uf-name').value = b.getAttribute('data-name') || ''; f('uf-phone').value = b.getAttribute('data-phone');
      var rr = form.querySelector('input[name="role"][value="' + b.getAttribute('data-role') + '"]'); if (rr) rr.checked = true;
      var fl = JSON.parse(b.getAttribute('data-flags') || '{}'); [].forEach.call(form.querySelectorAll('[data-flag]'), function (i) { i.checked = !!fl[i.dataset.flag]; });
      if (fl.own) { f('uf-own').checked = true; } else { f('uf-pick').checked = true; }
      var accs = (b.getAttribute('data-accounts') || '').split(','); [].forEach.call(form.querySelectorAll('input[name="accounts[]"]'), function (x) { x.checked = accs.indexOf(x.value) !== -1; });
      var pgs = JSON.parse(b.getAttribute('data-pages') || 'null') || {}; [].forEach.call(form.querySelectorAll('input[data-pg]'), function (x) { x.checked = (pgs[x.dataset.pg] || '') === x.value; });
      var ch = JSON.parse(b.getAttribute('data-chat') || '{}'); [].forEach.call(form.querySelectorAll('input[data-chat]'), function (x) { x.checked = !!ch[x.dataset.chat]; });
      if (f('uf-max-team')) f('uf-max-team').value = b.getAttribute('data-max-team') || 0;
      sync(); });
    sync();
    // From the Clients page: "New client" opens the dialog with the Client role chosen.
    if (new URLSearchParams(location.search).get('new') === 'client' && f('uf-new')) { open(null); var rc = form.querySelector('input[name="role"][value="client"]'); if (rc) { rc.checked = true; sync(); } }
  }
  /* ---------------- Media library page: folders (with sub-folders), kinds, selection with move / favourite / delete,
     a bin to put deleted items back, and an edit panel (title, caption, folder, tags) with "send to chats" ---------------- */
  if (page === 'media') {
    var mroot = document.getElementById('ml-page'), mk = '', mf = '', mq = '', mcur = null, mbin = /[?&]bin=1/.test(location.search), mvault = false, msel = {}, mfolders = [], msort = 'new';
    try { msort = localStorage.getItem('wi_ml_sort') || 'new'; } catch (e) {}
    var mchat = '', mfrom = '', mto = '', mview = 'grid', mdet = false; try { mview = localStorage.getItem('wi_ml_view') || 'grid'; mdet = localStorage.getItem('wi_ml_det') === '1'; } catch (e) {}
    function mlChats(items) { var by = {}; items.forEach(function (i) { if (!i.jid) return; by[i.jid] = by[i.jid] || { n: 0, name: i.chat || i.jid.split('@')[0], g: /@g\.us$/.test(i.jid) }; by[i.jid].n++; });
      var el = document.getElementById('ml-chat'); if (!el) return; el.innerHTML = '<option value="">💬 ' + T('All chats and groups') + '</option>' + Object.keys(by).sort(function (a, b) { return by[b].n - by[a].n; }).map(function (j) { return '<option value="' + esc(j) + '"' + (j === mchat ? ' selected' : '') + '>' + (by[j].g ? '👥 ' : '👤 ') + esc(by[j].name) + ' (' + fmt(by[j].n) + ')</option>'; }).join(''); }
    // storage used: the whole library and each kind, as bars
    function mlSpace(items) { // space used by kind: files, size and share of the total (+ what waits in Deleted)
      var tot = 0, by = {}, n = {}; items.forEach(function (i) { tot += i.size; by[i.kind] = (by[i.kind] || 0) + i.size; n[i.kind] = (n[i.kind] || 0) + 1; });
      var bin = ML.bin || { count: 0, size: 0 };
      return '<div class="ml-space"><div class="ml-sp-h">💾 ' + T('Space used') + ' <b>' + mlSize(tot) + '</b><small class="muted">' + fmt(items.length) + ' ' + T('files') + '</small></div>'
        + ML_KINDS.filter(function (k) { return by[k[0]]; }).sort(function (a, b) { return by[b[0]] - by[a[0]]; }).map(function (k) { var pc = tot ? by[k[0]] / tot * 100 : 0;
          return '<div class="ml-sp-r" title="' + esc(fmt(n[k[0]]) + ' ' + T('files')) + '"><span>' + k[1] + ' ' + T(k[2]) + ' <small class="muted">' + fmt(n[k[0]]) + '</small></span><b>' + mlSize(by[k[0]]) + ' <small class="muted">' + (pc < 1 ? '<1' : fmt(Math.round(pc))) + '%</small></b><i style="width:' + Math.max(2, Math.round(pc)) + '%"></i></div>'; }).join('')
        + (bin.count ? '<div class="ml-sp-r ml-sp-bin"><span>🗑 ' + T('Deleted') + ' <small class="muted">' + fmt(bin.count) + '</small></span><b>' + mlSize(bin.size) + '</b></div>' : '') + '</div>'; }
    function mlFolders() { return WI.get('media_folders').then(function (r) { mfolders = r.ok ? r.folders : []; }); }
    function mlTree() { // folders as a tree: "Parent/Child" → indented, the open one highlighted
      // counts are of every kind (like the space card); the kind buttons above the grid count the open folder
      var all = ML.items || [], inF = function (f) { return all.filter(function (i) { return i.folder === f || i.folder.indexOf(f + '/') === 0; }); },
        cnt = function (f) { var a = inF(f), s = 0; a.forEach(function (i) { s += i.size; }); return fmt(a.length) + (a.length ? ' · ' + mlSize(s) : ''); };
      return mlSpace(ML.items || []) + '<div class="ml-fh">' + T('Show') + '</div><button type="button" class="ml-fd' + (mf === '' && !mbin ? ' on' : '') + '" data-fd="">📚 ' + T('All media') + ' <i>' + fmt(all.length) + '</i></button>'
        + '<button type="button" class="ml-fd' + (mf === '__fav' ? ' on' : '') + '" data-fd="__fav">★ ' + T('Favourites') + ' <i>' + fmt(all.filter(function (i) { return i.fav; }).length) + '</i></button>'
        + '<button type="button" class="ml-fd ml-binb' + (mbin ? ' on' : '') + '" data-bin>🗑 ' + T('Deleted') + ' <i>' + fmt((ML.bin || {}).count || 0) + '</i></button>' // always in sight, next to Favourites
        + '<button type="button" class="ml-fd ml-vaultb' + (mvault ? ' on' : '') + '" data-vault>🔐 ' + T('Private folder') + ' <i>' + fmt(ML.vaultN || 0) + '</i></button>'
        + '<button type="button" class="ml-fd' + (mf === '__none' ? ' on' : '') + '" data-fd="__none">📥 ' + T('Not in a folder') + ' <i>' + fmt(all.filter(function (i) { return !i.folder; }).length) + '</i></button>'
        + '<div class="ml-fh">' + T('Folders') + '<button type="button" class="link-btn" data-fnew="">＋ ' + T('New folder') + '</button></div>'
        + mfolders.map(function (f) { var depth = f.split('/').length - 1, name = f.split('/').pop();
          return '<div class="ml-fr" style="padding-inline-start:' + (depth * 16) + 'px"><button type="button" class="ml-fd' + (mf === f ? ' on' : '') + '" data-fd="' + esc(f) + '">' + (depth ? '└ ' : '') + '📁 ' + esc(name) + ' <i>' + cnt(f) + '</i></button>'
            + '<span class="ml-fa"><button type="button" title="' + esc(T('New sub-folder')) + '" data-fnew="' + esc(f) + '">＋</button><button type="button" title="' + esc(T('Rename')) + '" data-fren="' + esc(f) + '">✏️</button><button type="button" title="' + esc(T('Delete folder')) + '" data-fdel="' + esc(f) + '">🗑</button></span></div>'; }).join('')
        + '<div class="ml-fh">' + T('Tools') + '</div><button type="button" class="ml-fd" data-dupes>🧬 ' + T('Duplicates') + '</button>'
        + '<button type="button" class="ml-fd" data-inv>📋 ' + T('Inventory') + '</button>'
        ;
    }
    // 📋 Inventory: everything in the library and every shortcut, built now — always the latest; downloadable as a sheet
    function mlInventory() {
      var d = mlDialog('<div class="ml-head"><b>📋 ' + T('Library inventory') + '</b><span class="grow"></span><a class="btn sm" href="?api=media_inventory&fmt=csv">⬇️ ' + T('Download (Excel)') + '</a><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="inv muted">' + T('Loading…') + '</div>');
      d.onclick = function (e) { if (e.target.closest('[data-mlx]')) d.close(); };
      WI.get('media_inventory').then(function (r) { var box = d.querySelector('.inv'); if (!r.ok) { box.textContent = '⚠️ ' + (r.error || ''); return; } var S = r.summary, K = {}; ML_KINDS.forEach(function (k) { K[k[0]] = k[1]; });
        box.className = 'inv';
        var row = function (x) { return '<tr><td>' + (K[x.kind] || '📄') + '</td><td dir="auto">' + (x.code ? '<code>' + esc(x.code) + '</code>' : '<span class="muted">—</span>') + '</td><td dir="auto">' + esc(x.title || x.name || '') + (x.folder ? ' <small class="muted">📁 ' + esc(x.folder) + '</small>' : '') + '</td><td>' + mlSize(x.size) + '</td><td>' + fmt(x.used) + '</td></tr>'; };
        box.innerHTML = '<div class="inv-sum"><span><b>' + fmt(S.files) + '</b> ' + T('files') + '</span><span><b>' + mlSize(S.size) + '</b></span><span><b>' + fmt(S.named) + '</b> ' + T('named') + '</span><span><b>' + fmt(S.with_code) + '</b> ' + T('with a shortcut') + '</span>'
          + Object.keys(S.by_kind || {}).map(function (k) { return '<span>' + (K[k] || '📄') + ' ' + fmt(S.by_kind[k]) + '</span>'; }).join('') + '</div>'
          + '<div class="search-xl"><span>🔎</span><input type="search" id="inv-q" placeholder="' + esc(T('Search title, caption, tags…')) + '" dir="auto"></div>'
          + '<div class="inv-t"><table><thead><tr><th></th><th>' + T('Shortcut') + '</th><th>' + T('Name') + '</th><th>' + T('Size') + '</th><th>' + T('Sent') + '</th></tr></thead><tbody id="inv-b"></tbody></table></div>'
          + (r.quick.length ? '<h4>⚡ ' + T('Quick replies') + ' (' + fmt(r.quick.length) + ')</h4><div class="inv-chips">' + r.quick.map(function (q) { return '<span dir="auto">' + (q.keyword ? '<code>#' + esc(q.keyword.replace(/^[#\/]/, '')) + '</code> ' : '') + esc(q.title) + '</span>'; }).join('') + '</div>' : '')
          + (r.programs.length ? '<h4>📚 ' + T('Programs') + ' (' + fmt(r.programs.length) + ')</h4><div class="inv-chips">' + r.programs.map(function (p) { return '<span dir="auto">' + esc(p.name) + ' · ' + fmt(p.days) + '</span>'; }).join('') + '</div>' : '')
          + '<p class="muted small">💬 ' + T('In WhatsApp: write the list command and the library word to get this list there.') + '</p>';
        var draw = function () { var q = (document.getElementById('inv-q').value || '').toLowerCase(); document.getElementById('inv-b').innerHTML = r.items.filter(function (x) { return !q || (x.code + ' ' + x.title + ' ' + x.name + ' ' + x.folder + ' ' + x.caption).toLowerCase().indexOf(q) !== -1; }).slice(0, 1500).map(row).join(''); };
        draw(); document.getElementById('inv-q').oninput = draw; });
    }
    function mlDupes() { // the same file saved more than once: one healthy copy is kept, the others go to the recycle bin
      var d = mlDialog('<div class="ml-head"><b>🧬 ' + T('Duplicates') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-dp muted">' + T('Looking for copies of the same file…') + '</div>');
      d.onclick = function (e) { if (e.target.closest('[data-mlx]')) d.close(); };
      WI.get('media_dupes').then(function (r) { var box = d.querySelector('.ml-dp'); if (!r.ok) { box.textContent = '⚠️ ' + (r.error || ''); return; }
        if (!r.groups.length) { box.innerHTML = '✓ ' + T('No duplicates: every file is saved once.'); return; }
        var by = {}, th = function (k) { return by[k] ? mlThumb(by[k]) : '📄'; }; (ML.items || []).forEach(function (i) { by[i.key] = i; });
        box.className = 'ml-dp'; box.innerHTML = '<p><b>' + fmt(r.copies) + '</b> ' + T('extra copies') + ' · ' + T('space freed') + ' <b>' + mlSize(r.waste) + '</b></p>'
          + '<p class="muted">' + T('For each file one healthy copy is kept (favourite, most sent, with a caption, else the oldest); its tags, caption and send count are kept. The copies go to Deleted and can be put back.') + '</p>'
          + '<div class="ml-dg">' + r.groups.slice(0, 60).map(function (g) { return '<div class="ml-dr"><span class="ml-dk" title="' + esc(T('Kept')) + '">✅ ' + th(g.keep) + '</span><span class="ml-dx">'
            + g.remove.map(function (k) { return '<span title="' + esc(T('Removed')) + '">' + th(k) + '</span>'; }).join('') + '</span><small class="muted">' + mlSize(g.size) + ' × ' + fmt(g.remove.length) + '</small></div>'; }).join('') + '</div>'
          + (r.groups.length > 60 ? '<p class="muted">+ ' + fmt(r.groups.length - 60) + '</p>' : '')
          + '<div class="row"><button type="button" class="btn danger" id="ml-dgo">🧹 ' + T('Remove the duplicates') + ' (' + fmt(r.copies) + ')</button></div>';
        d.querySelector('#ml-dgo').onclick = function () { if (!confirm(T('Move the extra copies to Deleted and keep one copy of each file?'))) return; this.disabled = true;
          WI.post('media_dupes', {}).then(function (x) { d.close(); mlToast(x.ok ? '✓ ' + fmt(x.removed) + ' · ' + mlSize(x.waste) : '⚠️ ' + (x.error || '')); mlPage(true); }); }; });
    }
    // One file as a card: the media itself (photos open the editor; voice, audio and video play and seek in place),
    // a readable name (never the raw file id), size, date and the chat it came from, and the actions.
    var KN = {}; ML_KINDS.forEach(function (k) { KN[k[0]] = k; });
    function mlChatHref(it) { return '?p=inbox#' + encodeURIComponent(it.jid || '') + (it.mid ? '|' + encodeURIComponent(it.mid) : ''); } // the chat, scrolled to the message that carried the file
    function mlNice(it) { var n = mlName(it); if (n && !/^[A-F0-9]{16,}\.[a-z0-9]+$/i.test(n)) return n; var k = KN[it.kind]; return (k ? k[1] + ' ' + T(k[2]).replace(/s$/, '') : '📄') + (it.at ? ' · ' + when(it.at) : ''); }
    function mlCard(it) {
      var u = mlUrl(it.key), media;
      if (it.kind === 'voice' || it.kind === 'audio') media = '<div class="mc-audio"><span class="mc-big">' + (it.kind === 'voice' ? '🎙️' : '🎧') + '</span><audio controls preload="none" src="' + u + '"></audio></div>';
      else if (it.kind === 'video') media = '<video class="mc-vid" controls preload="none" playsinline data-lz="' + u + '#t=0.5"></video>';
      else if (it.kind === 'gif') media = '<button type="button" class="ml-it mc-pic" data-key="' + esc(it.key) + '"><video data-lz="' + u + '" preload="none" muted loop autoplay playsinline></video><i class="ml-badge">GIF</i></button>';
      else if (it.kind === 'image' || it.kind === 'sticker') media = '<button type="button" class="ml-it mc-pic" data-key="' + esc(it.key) + '"><img src="' + u + '" alt="" loading="lazy"' + (it.kind === 'sticker' ? ' class="ml-stk"' : '') + '></button>';
      else media = '<button type="button" class="ml-it mc-pic mc-doc" data-key="' + esc(it.key) + '"><svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h8l5 5v13H6z"/><path d="M14 3v5h5M9 13h7M9 17h7"/></svg><span class="mc-ext">' + esc((it.key.split('.').pop() || '').toUpperCase()) + '</span></button>';
      var chat = it.chat || (it.jid ? it.jid.split('@')[0] : '');
      return '<div class="ml-cell mcard' + (msel[it.key] ? ' sel' : '') + '"><label class="ml-ck"><input type="checkbox" data-sel="' + esc(it.key) + '"' + (msel[it.key] ? ' checked' : '') + '></label>'
        + (it.fav ? '<i class="mc-fav">★</i>' : '') + '<div class="mc-media mk-' + esc(it.kind) + '">' + media + '</div>'
        + '<div class="mc-body"><button type="button" class="ml-it mc-name" data-key="' + esc(it.key) + '" dir="auto" title="' + esc(T('Edit')) + '">' + esc(mlNice(it)) + '</button>'
        + '<div class="mc-meta"><span>' + mlSize(it.size) + '</span>' + (it.at ? '<span>' + esc(String(it.at).slice(0, 10)) + '</span>' : '') + (it.used ? '<span>📤 ' + fmt(it.used) + '</span>' : '') + '</div>'
        + (it.from ? '<a class="mc-from" dir="auto" href="' + mlChatHref(it) + '" title="' + esc(T('Open the chat at this file')) + '">👤 ' + esc(it.from === 'You' ? T('You') : it.from) + '</a>' : '')
        + (chat ? '<a class="mc-chat" dir="auto" href="' + mlChatHref(it) + '">' + (/@g\.us$/.test(it.jid || '') ? '👥 ' : '💬 ') + esc(chat) + '</a>' : '')
        + (it.folder ? '<div class="mc-chat">📁 ' + esc(it.folder) + '</div>' : '')
        + '</div>' + (mbin ? '<div class="mc-acts"><button type="button" data-bulk-one="restore" data-k1="' + esc(it.key) + '">↩️ ' + T('Put back') + '</button></div>' : '<div class="mc-acts">'
          + '<button type="button" data-rn="' + esc(it.key) + '" aria-label="' + esc(T('Rename')) + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16z"/></svg>' + T('Name') + '</button>'
          + (mvault ? '' : '<button type="button" data-send1="' + esc(it.key) + '" data-cap1="' + esc(it.caption || '') + '" aria-label="' + esc(T('Send to chats')) + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 3 3 10.5l7 3 3 7z"/></svg>' + T('Send') + '</button>')
          + '<a class="mc-dl" href="' + u + '&dl=1" aria-label="' + esc(T('Download')) + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 20h14"/></svg>' + T('Download') + '</a>'
          + '<button type="button" class="ml-it" data-key="' + esc(it.key) + '" aria-label="' + esc(T('Edit')) + '" title="' + esc(T('Edit')) + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1.2l2-1.5-2-3.4-2.3.9a7 7 0 0 0-2-1.2L14 3h-4l-.6 2.6a7 7 0 0 0-2 1.2l-2.3-.9-2 3.4 2 1.5A7 7 0 0 0 5 12c0 .4 0 .8.1 1.2l-2 1.5 2 3.4 2.3-.9c.6.5 1.3.9 2 1.2L10 21h4l.6-2.6c.7-.3 1.4-.7 2-1.2l2.3.9 2-3.4-2-1.5c.1-.4.1-.8.1-1.2z"/></svg>' + T('Settings') + '</button></div>') + '</div>';
    }
    function mlPage(force) {
      var load = mvault ? WI.get('media_lib', { vault: 1 }).then(function (r) { if (r.ok && r.vault) { ML.vkeys = {}; r.items.forEach(function (i) { ML.vkeys[i.key] = 1; }); return r.items; } mvault = false; ML.vkeys = {}; return mlLoad(true); }) : (ML.vkeys = {}, mbin ? WI.get('media_lib', { bin: 1 }).then(function (r) { return r.ok ? r.items : []; }) : mlLoad(force));
      Promise.all([load, force || !mfolders.length ? mlFolders() : null]).then(function (res) { var items = res[0];
        document.getElementById('ml-tree').innerHTML = mlTree(); // also while in Deleted, so the way back is always there
        var inF = items.filter(function (i) { return mbin || mf === '' ? true : mf === '__fav' ? i.fav : mf === '__none' ? !i.folder : i.folder === mf; });
        var cnt = {}; inF.forEach(function (i) { cnt[i.kind] = (cnt[i.kind] || 0) + 1; });
        document.getElementById('ml-k').innerHTML = '<button data-k="" class="' + (mk === '' ? 'on' : '') + '">' + T('All') + ' <i>' + fmt(inF.length) + '</i></button>'
          + ML_KINDS.filter(function (k) { return cnt[k[0]]; }).map(function (k) { return '<button data-k="' + k[0] + '" class="' + (mk === k[0] ? 'on' : '') + '">' + k[1] + ' ' + T(k[2]) + ' <i>' + fmt(cnt[k[0]]) + '</i></button>'; }).join('');
        mlChats(inF); inF = inF.filter(function (i) { var d = String(i.at || '').slice(0, 10); return (!mchat || i.jid === mchat) && (!mfrom || d >= mfrom) && (!mto || d <= mto); }); // chat / group and dates
        var list = mlFilter(inF, mq, mk, '');
        list.sort(function (a, b) { return msort === 'big' ? b.size - a.size : msort === 'used' ? b.used - a.used : msort === 'old' ? (a.at > b.at ? 1 : -1) : (b.fav - a.fav) || (a.at < b.at ? 1 : -1); }); ML.shown = list;
        var tot = 0; list.forEach(function (i) { tot += i.size; }); document.getElementById('ml-tot').textContent = fmt(list.length) + ' ' + T('items') + ' · ' + mlSize(tot);
        document.getElementById('ml-crumb').innerHTML = mvault ? '🔐 ' + T('Private folder') + ' <button type="button" class="link-btn" data-vlock>🔒 ' + T('Lock') + '</button>' : mbin ? '🗑 ' + T('Deleted — select and press «Put back»') : (mf === '' ? '📚 ' + T('All media') : mf === '__fav' ? '★ ' + T('Favourites') : mf === '__none' ? '📥 ' + T('Not in a folder') : '📁 ' + mf.split('/').map(esc).join(' › '));
        var grid = function (arr) { return arr.length ? arr.slice(0, 300).map(function (it) { return mlCard(it); }).join('') : '<div class="empty">' + T('Nothing found.') + '</div>'; };
        document.getElementById('ml-g').innerHTML = mk || mbin ? '<div class="ml-grid in">' + grid(list) + '</div>' : (ML_KINDS.map(function (k) { var part = list.filter(function (i) { return i.kind === k[0]; }); if (!part.length) return '';
          return '<div class="ml-sec"><h3>' + k[1] + ' ' + T(k[2]) + ' <small class="muted">' + fmt(part.length) + '</small>' + (part.length > 24 ? ' <button type="button" class="link-btn" data-k="' + k[0] + '">' + T('Show all') + ' →</button>' : '') + '</h3><div class="ml-grid in">' + grid(part.slice(0, 24)) + '</div></div>'; }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>');
        [].forEach.call(document.querySelectorAll('#ml-g video[data-lz]'), function (v) { if (mlLz) mlLz.observe(v); else v.src = v.dataset.lz; });
        document.querySelector('.ml-v2 .ml-layout').classList.toggle('ed-on', !document.getElementById('ml-ed').hidden);
        var g = document.getElementById('ml-g'); g.classList.toggle('mv-list', mview === 'list'); g.classList.toggle('mv-big', mview === 'big'); g.classList.toggle('mv-det', mdet || mview === 'list');
        mlBar();
      });
    }
    [['ml-chat', function (v) { mchat = v; }], ['ml-from', function (v) { mfrom = v; }], ['ml-to', function (v) { mto = v; }], ['ml-view', function (v) { mview = v; try { localStorage.setItem('wi_ml_view', v); } catch (e) {} }]].forEach(function (p) { var el = document.getElementById(p[0]); if (el) { if (p[0] === 'ml-view') el.value = mview; el.addEventListener('change', function () { p[1](el.value); mlPage(); }); } });
    function segSync() { [].forEach.call(document.querySelectorAll('[data-mv]'), function (b) { b.classList.toggle('on', b.dataset.mv === mview); }); }
    document.addEventListener('click', function (e) { var b = e.target.closest('[data-mv]'); if (!b) return; var sel = document.getElementById('ml-view'); sel.value = b.dataset.mv; sel.dispatchEvent(new Event('change')); segSync(); }); segSync();
    var detB = document.getElementById('ml-det'); if (detB) { detB.classList.toggle('on', mdet); detB.onclick = function () { mdet = !mdet; detB.classList.toggle('on', mdet); try { localStorage.setItem('wi_ml_det', mdet ? '1' : '0'); } catch (e) {} mlPage(); }; }
    function mlBar() { var n = Object.keys(msel).length, b = document.getElementById('ml-bulk'); b.hidden = !n;
      if (mvault) { b.innerHTML = '<b>' + fmt(n) + ' ' + T('selected') + '</b><button type="button" class="btn primary sm" data-bulk="vshow">↩️ ' + T('Take out of the private folder') + '</button><button type="button" class="link-btn" data-bulk="all">' + T('Select all shown') + '</button><button type="button" class="link-btn" data-bulk="none">' + T('Clear') + '</button>'; return; }
      b.innerHTML = '<b>' + fmt(n) + ' ' + T('selected') + '</b>' + (!mbin ? '<a class="btn sm" href="?api=media_zip&keys=' + encodeURIComponent(Object.keys(msel).join(',')) + '">⬇️ ' + T('Download (.zip)') + '</a>' : '') + (mbin ? '<button type="button" class="btn primary sm" data-bulk="restore">↩️ ' + T('Put back') + '</button>'
        : '<button type="button" class="btn primary sm" data-bulk="send">📤 ' + T('Send to chats…') + '</button><button type="button" class="btn ghost sm" data-bulk="move">📁 ' + T('Move to folder…') + '</button>'
          + '<button type="button" class="btn ghost sm" data-bulk="rename">✏️ ' + T('Rename') + '</button><button type="button" class="btn ghost sm" data-bulk="tag">🏷️ ' + T('Add tags') + '</button><button type="button" class="btn ghost sm" data-bulk="caption">✍️ ' + T('Same caption') + '</button><button type="button" class="btn ghost sm" data-bulk="zip">⬇️ ' + T('Download') + '</button><button type="button" class="btn ghost sm" data-bulk="youtube">▶️ YouTube</button><button type="button" class="btn ghost sm" data-bulk="fav">★ ' + T('Favourite') + '</button><button type="button" class="btn ghost sm" data-bulk="unfav">☆</button><button type="button" class="btn ghost sm bad-t" data-bulk="delete">🗑 ' + T('Delete') + '</button>')
        + (mbin ? '' : mvault ? '<button type="button" class="btn ghost sm" data-bulk="vshow">↩️ ' + T('Take out of the private folder') + '</button>' : '<button type="button" class="btn ghost sm" data-bulk="vhide">🔒 ' + T('Hide in the private folder') + '</button>')
        + '<button type="button" class="link-btn" data-bulk="all">' + T('Select all shown') + '</button><button type="button" class="link-btn" data-bulk="none">' + T('Clear') + '</button>'; }
    function mlBulk(do_, folder, extra) { WI.post('media_bulk', Object.assign({ do: do_, keys: Object.keys(msel), folder: folder || '' }, extra || {})).then(function (r) { mlToast(r.ok ? '✓ ' + fmt(r.count) : '⚠️ ' + (r.error || '')); msel = {}; mlPage(true); }); }
    function mlChooseFolder(cb) { // pick a folder (or make a new one) for "move"
      var d = mlDialog('<div class="ml-head"><b>📁 ' + T('Move to folder') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-fw">'
        + '<button type="button" class="ml-fd" data-pick="">📥 ' + T('Not in a folder') + '</button>' + mfolders.map(function (f) { return '<button type="button" class="ml-fd" data-pick="' + esc(f) + '" style="padding-inline-start:' + (12 + (f.split('/').length - 1) * 16) + 'px">📁 ' + esc(f.split('/').pop()) + ' <small class="muted">' + esc(f) + '</small></button>'; }).join('')
        + '<div class="row"><input id="mcf-new" placeholder="' + esc(T('or a new folder, e.g. Offers/2026')) + '" dir="auto" style="flex:1"><button type="button" class="btn primary sm" id="mcf-go">' + T('Move') + '</button></div></div>');
      d.onclick = function (e) { var b; if (e.target.closest('[data-mlx]')) { d.close(); return; }
        if ((b = e.target.closest('[data-pick]'))) { d.close(); cb(b.dataset.pick); return; }
        if (e.target.closest('#mcf-go')) { var v = document.getElementById('mcf-new').value.trim(); if (!v) return; d.close(); cb(v); } };
    }
    // 🔐 the private folder: files hidden from the library, opened with the password or a code sent on WhatsApp
    function mlVaultOpen(after) {
      WI.get('media_vault').then(function (v) {
        var enter = function () { mvault = true; mbin = false; mf = ''; msel = {}; mlPage(true); if (after) after(); };
        if (v.unlocked) { enter(); return; }
        if (!v.has_password) { mlVaultSet(enter); return; }
        mlVaultUnlock(enter);
      });
    }
    function mlVaultSet(done) {
      var d = mlDialog('<div class="ml-head"><b>🔐 ' + T('Private folder password') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-fw lt-form">'
        + '<p class="muted small">' + T('Set a password for the private folder. Files you hide there disappear from the library until it is opened with this password or a code sent on WhatsApp.') + '</p>'
        + '<label>' + T('Password') + '<input id="vp-1" type="password" autocomplete="new-password"></label><label>' + T('Repeat the password') + '<input id="vp-2" type="password" autocomplete="new-password"></label>'
        + '<div class="row"><button type="button" class="btn primary" id="vp-go">💾 ' + T('Save') + '</button><span class="muted small" id="vp-m"></span></div></div>');
      d.onclick = function (e) { if (e.target.closest('[data-mlx]')) { d.close(); return; } if (!e.target.closest('#vp-go')) return;
        var a = document.getElementById('vp-1').value; if (a !== document.getElementById('vp-2').value) { document.getElementById('vp-m').textContent = '⚠️ ' + T('The two passwords are different.'); return; }
        WI.post('media_vault_set', { password: a }).then(function (r) { if (!r.ok) { document.getElementById('vp-m').textContent = '⚠️ ' + (r.error || T('Failed')); return; } d.close(); done(); }); };
    }
    function mlVaultUnlock(done) {
      var d = mlDialog('<div class="ml-head"><b>🔐 ' + T('Private folder') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-fw lt-form">'
        + '<label>' + T('Password') + '<input id="vu-pw" type="password" autocomplete="off"></label>'
        + '<div class="row"><button type="button" class="btn primary" id="vu-go">🔓 ' + T('Open') + '</button><button type="button" class="btn ghost sm" id="vu-otp">📲 ' + T('Send me a code on WhatsApp') + '</button><span class="muted small" id="vu-m"></span></div>'
        + '<div id="vu-code" hidden><label>' + T('The code from WhatsApp') + '<input id="vu-c" inputmode="numeric" maxlength="6" dir="ltr" autocomplete="one-time-code"></label><div class="row"><button type="button" class="btn primary" id="vu-cgo">🔓 ' + T('Open with the code') + '</button></div></div></div>');
      var m = function (t) { document.getElementById('vu-m').textContent = t; }, go = function (body) { WI.post('media_vault_unlock', body).then(function (r) { if (!r.ok) { m('⚠️ ' + (r.error || T('Failed'))); return; } d.close(); done(); }); };
      d.onclick = function (e) { if (e.target.closest('[data-mlx]')) { d.close(); return; }
        if (e.target.closest('#vu-go')) { go({ password: document.getElementById('vu-pw').value }); return; }
        if (e.target.closest('#vu-cgo')) { go({ code: document.getElementById('vu-c').value }); return; }
        if (e.target.closest('#vu-otp')) { m('⏳'); WI.post('media_vault_otp', {}).then(function (r) { if (!r.ok) { m('⚠️ ' + (r.error || T('Failed'))); return; } m('✓ ' + T('The code was sent to your WhatsApp') + ' ' + (r.to || '')); document.getElementById('vu-code').hidden = false; document.getElementById('vu-c').focus(); }); } };
    }
    function mlVaultMove(act) {
      var keys = Object.keys(msel); if (!keys.length) return;
      WI.post('media_vault_move', { keys: keys, do: act }).then(function (r) {
        if (r.need_password) { mlVaultSet(function () { mlVaultMove(act); }); return; }
        if (r.need_unlock) { mlVaultUnlock(function () { mlVaultMove(act); }); return; }
        if (!r.ok) { alert(r.error || T('Failed')); return; } msel = {}; mlPage(true); });
    }
    function mlEdClose() { document.getElementById('ml-ed').hidden = true; var bg = document.getElementById('ml-ed-bg'); if (bg) bg.hidden = true; document.querySelector('.ml-v2 .ml-layout').classList.remove('ed-on'); }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !document.getElementById('ml-ed').hidden) mlEdClose(); });
    document.getElementById('ml-ed-bg').addEventListener('click', mlEdClose);
    function mlEdit(it) { mcur = it; var p = document.getElementById('ml-ed'); p.hidden = false; var bg = document.getElementById('ml-ed-bg'); if (bg) bg.hidden = false;
      p.innerHTML = '<button type="button" class="ml-ed-x" id="me-x" aria-label="' + esc(T('Close')) + '">✕</button><div class="ml-prev big">' + (it.kind === 'audio' || it.kind === 'voice' ? '<audio controls src="' + mlUrl(it.key) + '"></audio>' : it.kind === 'video' || it.kind === 'gif' ? '<video controls src="' + mlUrl(it.key) + '"></video>' : mlThumb(it)) + '</div>'
        + (it.from || it.chat ? '<div class="ml-owner" dir="auto">' + (it.from ? '<a href="' + mlChatHref(it) + '">👤 ' + esc(it.from === 'You' ? T('You') : it.from) + '</a>' : '') + (it.chat ? ' · <a href="' + mlChatHref(it) + '">💬 ' + esc(it.chat) + '</a>' : '') + '</div>' : '')
        + '<div class="ml-form"><label>' + T('Title') + '<input id="me-t" value="' + esc(it.title) + '" dir="auto" maxlength="80"></label>'
        + '<label>' + T('Shortcut — write it in any chat or group and the file is sent') + '<input id="me-code" value="' + esc(it.code || '') + '" dir="auto" maxlength="60" placeholder="' + esc(T('#ruqyah-name')) + '"></label>'
        + '<label>' + T('Caption (sent with it)') + '<textarea id="me-c" rows="4" dir="auto" maxlength="1000">' + esc(it.caption) + '</textarea></label>'
        + '<div class="row wrap"><label>' + T('Folder') + '<select id="me-f"><option value="">📥 ' + T('Not in a folder') + '</option>' + mfolders.map(function (f) { return '<option value="' + esc(f) + '"' + (it.folder === f ? ' selected' : '') + '>' + esc(f.replace(/\//g, ' › ')) + '</option>'; }).join('') + '</select></label>'
        + '<label>' + T('Tags') + '<input id="me-g" value="' + esc(it.tags.join('، ')) + '" dir="auto" placeholder="' + esc(T('separated by commas')) + '"></label></div>'
        + '<label class="check"><input type="checkbox" id="me-v"' + (it.fav ? ' checked' : '') + '> ★ ' + T('Favourite (shown first)') + '</label>'
        + (it.kind === 'video' ? '<div class="ml-ytb">' + (function (y) { if (!y) return '<button type="button" class="btn ghost sm" id="me-yt">▶️ ' + T('Upload to YouTube') + '</button>';
            if (y.state === 'done') return '▶️ <a href="' + esc(y.url) + '" target="_blank" rel="noopener">' + esc(y.url) + '</a> <small class="muted">' + esc(T({ private: 'Private', unlisted: 'Unlisted', public: 'Public' }[y.privacy] || '')) + '</small> <button type="button" class="link-btn" id="me-ytc">📋 ' + T('Copy link') + '</button>';
            if (y.state === 'failed') return '⚠️ ' + esc(y.error || T('Failed')) + ' <button type="button" class="link-btn" id="me-yt">' + T('Try again') + '</button>';
            return '⏳ ' + T(y.state === 'uploading' ? 'Uploading to YouTube…' : 'Waiting to upload to YouTube…'); })(it.youtube) + '</div>' : '')
        + '<p class="muted small">' + T('Sent') + ' ' + fmt(it.used) + ' · ' + esc(when(it.at)) + ' · ' + Math.round(it.size / 1024) + ' KB</p>'
        + '<div class="row wrap"><button type="button" class="btn primary sm" id="me-save">💾 ' + T('Save') + '</button><button type="button" class="btn ghost sm" id="me-send">📤 ' + T('Send to chats…') + '</button><a class="btn ghost sm" href="' + mlUrl(it.key) + '&dl=1' + (it.title ? '&name=' + encodeURIComponent(it.title) : '') + '">⬇️</a><button type="button" class="link-btn bad-t" id="me-hide">🗑 ' + T('Delete') + '</button><span class="muted small" id="me-m"></span></div></div>'; }
    function mlSave(extra) { var b = Object.assign({ key: mcur.key, title: document.getElementById('me-t').value, code: (document.getElementById('me-code') || { value: '' }).value, caption: document.getElementById('me-c').value, folder: document.getElementById('me-f').value, tags: document.getElementById('me-g').value, fav: document.getElementById('me-v').checked ? 1 : 0 }, extra || {});
      return WI.post('media_meta', b).then(function (r) { document.getElementById('me-m').textContent = r.ok ? '✓ ' + T('Saved') : '⚠️ ' + (r.error || ''); if (r.ok) mlPage(true); return r; }); }
    function mlRename(keys) { // name (and caption) of one file, or of several at once (numbered 1, 2, 3…)
      var one = keys.length === 1 ? (ML.items || []).filter(function (i) { return i.key === keys[0]; })[0] : null;
      var d = mlDialog('<div class="ml-head"><b>✏️ ' + T('Rename') + (one ? '' : ' · ' + fmt(keys.length)) + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-form">'
        + (one ? '<div class="ml-prev">' + mlThumb(one) + '</div>' : '')
        + '<label>' + T('Name') + '<input id="mrn-t" maxlength="70" dir="auto" value="' + esc(one ? one.title : '') + '"></label>' + (one ? '' : '<p class="muted small">' + T('Each file gets the name with its number: name 1, name 2…') + '</p>')
        + '<label>' + T('Caption (sent with it)') + '<textarea id="mrn-c" rows="3" dir="auto" maxlength="1000" placeholder="' + esc(one ? '' : T('Leave empty to keep each file\'s caption')) + '">' + esc(one ? one.caption : '') + '</textarea></label>'
        + '<div class="row"><button type="button" class="btn primary sm" id="mrn-go">💾 ' + T('Save') + '</button></div></div>');
      d.onclick = function (e) { if (e.target.closest('[data-mlx]')) d.close(); };
      var t = d.querySelector('#mrn-t'); t.focus(); t.select();
      d.querySelector('#mrn-go').onclick = function () { var n = t.value.trim(), c = d.querySelector('#mrn-c').value; if (!n && !one) { t.focus(); return; }
        var body = one ? { key: one.key, title: n, caption: c } : Object.assign({ do: 'rename', keys: keys, name: n }, c.trim() ? { caption: c } : {});
        WI.post(one ? 'media_meta' : 'media_bulk', body).then(function (r) { if (!r.ok) { mlToast('⚠️ ' + (r.error || '')); return; } d.close(); mlToast('✓ ' + T('Saved')); if (!one) msel = {}; mlPage(true); }); };
    }
    function folderAsk(title, val) { var v = prompt(title, val || ''); return v === null ? null : v.trim(); }
    mroot.addEventListener('change', function (e) { var c = e.target.closest('[data-sel]'); if (!c) return; if (c.checked) msel[c.dataset.sel] = 1; else delete msel[c.dataset.sel]; c.closest('.ml-cell').classList.toggle('sel', c.checked); mlBar(); });
    mroot.addEventListener('click', function (e) { var b;
      if (e.target.closest('.ml-ck')) return;
      if ((b = e.target.closest('[data-rn]'))) { mlRename([b.dataset.rn]); return; }
      if ((b = e.target.closest('[data-send1]'))) { mlForward(b.dataset.send1, b.dataset.cap1 || '', function () { mlToast('✓ ' + T('Sent')); }); return; }
      if ((b = e.target.closest('[data-bulk-one]'))) { WI.post('media_bulk', { keys: [b.dataset.k1], do: 'restore' }).then(function () { mlPage(true); }); return; }
      if ((b = e.target.closest('#ml-k [data-k], .ml-sec [data-k]'))) { mk = b.dataset.k; mlPage(); window.scrollTo(0, 0); return; }
      if ((b = e.target.closest('[data-fd]'))) { mf = b.dataset.fd; mbin = false; mvault = false; msel = {}; mlPage(); return; }
      if (e.target.closest('[data-vault]')) { mlVaultOpen(); return; }
      if (e.target.closest('[data-vlock]')) { WI.post('media_vault_lock', {}).then(function () { mvault = false; msel = {}; mlPage(true); }); return; }
      if (e.target.closest('[data-dupes]')) { mlDupes(); return; }
      if (e.target.closest('[data-inv]')) { mlInventory(); return; }
      if (e.target.closest('[data-bin]')) { mbin = true; mvault = false; mf = ''; msel = {}; mlPage(); return; }
      if ((b = e.target.closest('[data-fnew]'))) { var base = b.dataset.fnew, n = folderAsk(base ? T('Name of the sub-folder inside') + ' «' + base + '»' : T('Name of the new folder'));
        if (n) WI.post('media_folders', { do: 'create', path: (base ? base + '/' : '') + n }).then(function () { mlPage(true); }); return; }
      if ((b = e.target.closest('[data-fren]'))) { var f = b.dataset.fren, nn = folderAsk(T('New name'), f.split('/').pop());
        if (nn) WI.post('media_folders', { do: 'rename', path: f, to: (f.indexOf('/') > -1 ? f.slice(0, f.lastIndexOf('/') + 1) : '') + nn }).then(function () { if (mf === f) mf = ''; mlPage(true); }); return; }
      if ((b = e.target.closest('[data-fdel]'))) { if (confirm(T('Delete this folder and its sub-folders? Their files move up to the parent folder; nothing is deleted.'))) WI.post('media_folders', { do: 'delete', path: b.dataset.fdel }).then(function () { if (mf.indexOf(b.dataset.fdel) === 0) mf = ''; mlPage(true); }); return; }
      if ((b = e.target.closest('[data-bulk]'))) { var d = b.dataset.bulk; if (d === 'rename') { mlRename(Object.keys(msel)); return; }
        if (d === 'vhide' || d === 'vshow') { mlVaultMove(d === 'vhide' ? 'hide' : 'show'); return; }
        if (d === 'all') { (ML.shown || []).slice(0, 300).forEach(function (i) { msel[i.key] = 1; }); mlPage(); return; }
        if (d === 'none') { msel = {}; mlPage(); return; }
        if (d === 'move') { mlChooseFolder(function (f) { mlBulk('move', f); }); return; }
        if (d === 'send') { var ks = Object.keys(msel); if (ks.length > 30) { alert(T('At most 30 files at once.')); return; } mlForward(ks, null, function () { msel = {}; mlPage(true); }); return; }
        if (d === 'tag') { var tg = folderAsk(T('Tags to add to the selected files (separated by commas)')); if (tg) mlBulk('tag', '', { tags: tg }); return; }
        if (d === 'caption') { var cp = prompt(T('Caption for all the selected files (empty = remove)'), ''); if (cp !== null) mlBulk('caption', '', { caption: cp }); return; }
        if (d === 'youtube') { var vids = Object.keys(msel).filter(function (k) { var i = (ML.items || []).filter(function (x) { return x.key === k; })[0]; return i && i.kind === 'video'; });
          if (!vids.length) { alert(T('Choose videos.')); return; } mlYoutube(vids, null, function () { msel = {}; mlPage(true); }); return; }
        if (d === 'zip') { location.href = '?api=media_zip&keys=' + encodeURIComponent(Object.keys(msel).slice(0, 200).join(',')); return; }
        if (d === 'delete' && !confirm(T('Delete the selected files from the library? They stay in the chats and can be put back from «Deleted».'))) return;
        mlBulk(d); return; }
      if ((b = e.target.closest('.ml-it'))) { var it = (ML.shown || []).filter(function (i) { return i.key === b.dataset.key; })[0]; if (it && !mbin) mlEdit(it); return; }
      if (e.target.closest('#me-x')) { mlEdClose(); return; }
      if (e.target.closest('#me-save')) { mlSave().then(function (r) { if (r && r.ok) mlEdClose(); }); return; }
      if (e.target.closest('#me-yt')) { mlYoutube([mcur.key], mcur, function () { mlPage(true); }); return; }
      if (e.target.closest('#me-ytc')) { try { navigator.clipboard.writeText(mcur.youtube.url); mlToast('✓ ' + T('Copied')); } catch (x) { prompt('', mcur.youtube.url); } return; }
      if (e.target.closest('#me-send')) { mlSave().then(function () { mlForward(mcur.key, document.getElementById('me-c').value, function () { mlPage(true); }); }); return; }
      if (e.target.closest('#me-hide') && confirm(T('Delete this file from the library? It stays in the chats and can be put back from «Deleted».'))) { mlSave({ hidden: 1 }).then(function () { mlEdClose(); }); }
    });
    document.getElementById('ml-q').oninput = function () { mq = this.value; mlPage(); };
    var so = document.getElementById('ml-sort'); so.value = msort; so.onchange = function () { msort = this.value; try { localStorage.setItem('wi_ml_sort', msort); } catch (e) {} mlPage(); };
    mlPage(true);
  }

})();
