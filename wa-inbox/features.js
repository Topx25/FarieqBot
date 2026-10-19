/* WhatsApp Inbox — feature pages (cases, groups, scheduling, programs, status, health, report, backup, customer)
   and chat tools (AI reply suggestion, quoted reply, reactions, schedule). All text is escaped before display. */
// Refresh / polling pace: Settings → System → refresh_pct (100 = normal, 200 = half as often on a busy server).
function WIR(ms) { var p = +((window.WI_SYS || {}).refresh_pct) || 100; return Math.round(ms * Math.max(50, Math.min(400, p)) / 100); }
// Example texts and case-form fields come from the number's business profile, never from the code.
function EX(k) { var e = window.WI_EX || {}; return e[k] || ''; }
function PK(k) { var c = (window.WI_PACK || {}).case || {}; return c[k] || ''; }

(function () {
  var app = document.querySelector('.app');
  if (!app) return;
  var page = app.getAttribute('data-page'), csrf = app.getAttribute('data-csrf');
  var L = window.WI_T || {}, AR = window.WI_LANG === 'ar';
  // Country from a phone number (calling code → region; the name comes from the browser in the page's language).
  var DIAL = {}; '1:US,7:RU,20:EG,27:ZA,30:GR,31:NL,32:BE,33:FR,34:ES,36:HU,39:IT,40:RO,41:CH,43:AT,44:GB,45:DK,46:SE,47:NO,48:PL,49:DE,51:PE,52:MX,53:CU,54:AR,55:BR,56:CL,57:CO,58:VE,60:MY,61:AU,62:ID,63:PH,64:NZ,65:SG,66:TH,81:JP,82:KR,84:VN,86:CN,90:TR,91:IN,92:PK,93:AF,94:LK,95:MM,98:IR,211:SS,212:MA,213:DZ,216:TN,218:LY,220:GM,221:SN,222:MR,223:ML,224:GN,225:CI,226:BF,227:NE,228:TG,229:BJ,230:MU,231:LR,232:SL,233:GH,234:NG,235:TD,236:CF,237:CM,238:CV,240:GQ,241:GA,242:CG,243:CD,244:AO,249:SD,250:RW,251:ET,252:SO,253:DJ,254:KE,255:TZ,256:UG,257:BI,258:MZ,260:ZM,261:MG,263:ZW,264:NA,265:MW,266:LS,267:BW,268:SZ,269:KM,291:ER,351:PT,352:LU,353:IE,354:IS,355:AL,356:MT,357:CY,358:FI,359:BG,370:LT,371:LV,372:EE,373:MD,374:AM,375:BY,380:UA,381:RS,382:ME,385:HR,386:SI,387:BA,389:MK,420:CZ,421:SK,880:BD,960:MV,961:LB,962:JO,963:SY,964:IQ,965:KW,966:SA,967:YE,968:OM,970:PS,971:AE,973:BH,974:QA,976:MN,977:NP,992:TJ,993:TM,994:AZ,995:GE,996:KG,998:UZ'.split(',').forEach(function (x) { var p = x.split(':'); DIAL[p[0]] = p[1]; });
  function regionOf(phone) { var p = String(phone || '').replace(/\D/g, ''); for (var n = 3; n >= 1; n--) if (DIAL[p.slice(0, n)]) return DIAL[p.slice(0, n)]; return ''; }
  function regionName(r) { try { return new Intl.DisplayNames([AR ? 'ar' : 'en'], { type: 'region' }).of(r) || r; } catch (e) { return r; } }
  function countryOf(phone) { var r = regionOf(phone); return r ? regionName(r) : ''; }
  function countryList(id) { var seen = {}, o = []; Object.keys(DIAL).forEach(function (k) { var n = regionName(DIAL[k]); if (!seen[n]) { seen[n] = 1; o.push(n); } }); o.sort(function (a, b) { return a.localeCompare(b); });
    return '<datalist id="' + id + '">' + o.map(function (n) { return '<option value="' + esc(n) + '">'; }).join('') + '</datalist>'; }
  // "10/10/2026" (older records) → "2026-10-10" for a date picker
  function isoDate(v) { var m = /^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/.exec(String(v || '').trim()); return m ? m[3] + '-' + ('0' + m[2]).slice(-2) + '-' + ('0' + m[1]).slice(-2) : String(v || '').trim(); }
  function genderOf(v) { v = String(v || ''); return /ث|female|woman|f$/i.test(v) ? 'f' : (/ذكر|male|man|^m$/i.test(v) ? 'm' : ''); }
  function T(s) { return Object.prototype.hasOwnProperty.call(L, s) ? L[s] : s; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]; }); }
  // Every request answers {ok:false, error} instead of failing silently (no connection, a server error page, a non-JSON reply),
  // so no button or page is left waiting forever.
  function safeJson(r) { if (r.status === 401) { location.reload(); return { ok: false, error: T('Sign in again') }; }
    return r.text().then(function (t) { try { return JSON.parse(t); } catch (e) { return { ok: false, error: r.status >= 500 ? T('Server error') + ' (' + r.status + ')' : (r.status === 403 ? T('Not allowed') : T('Unexpected reply from the server')), status: r.status }; } }); }
  /** A proper form dialog: askForm(title, [{key, label, type:'text'|'textarea'|'datetime-local', value, placeholder}]) → Promise of values, or null when cancelled. */
  function askForm(title, fields, okLabel) {
    return new Promise(function (done) {
      var d = document.createElement('dialog'); d.className = 'dlg ask-dlg';
      d.innerHTML = '<form method="dialog" class="stack pad"><h3>' + esc(title) + '</h3>' + fields.map(function (f, i) {
        var common = ' name="f' + i + '" dir="auto" placeholder="' + esc(f.placeholder || '') + '"';
        return '<label>' + esc(f.label || '') + (f.type === 'textarea' ? '<textarea' + common + ' rows="' + (f.rows || 6) + '">' + esc(f.value || '') + '</textarea>' : '<input type="' + (f.type || 'text') + '"' + common + ' value="' + esc(f.value || '') + '">') + '</label>'; }).join('')
        + '<div class="row"><button class="btn primary" value="ok">' + esc(okLabel || T('Save')) + '</button><button class="btn ghost" value="cancel" formnovalidate>' + T('Cancel') + '</button><span class="muted small">' + T('Ctrl + Enter to save') + '</span></div></form>';
      document.body.appendChild(d);
      d.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); d.close('ok'); } });
      d.addEventListener('close', function () { var out = null; if (d.returnValue === 'ok') { out = {}; fields.forEach(function (f, i) { out[f.key] = d.querySelector('[name=f' + i + ']').value; }); } d.remove(); done(out); });
      d.showModal(); var first = d.querySelector('input,textarea'); if (first) { first.focus(); if (first.select) first.select(); }
    });
  }
  function netFail() { return { ok: false, error: T('No connection to the server — try again') }; }
  // Shared request helpers from app.js (window.WI); the local versions stay as a fallback when app.js did not run.
  function get(name, params) { if (window.WI && WI.get) return WI.get(name, params); var q = new URLSearchParams(Object.assign({ api: name }, params || {})); return fetch('?' + q.toString(), { credentials: 'same-origin', cache: 'no-store' }).then(safeJson, netFail); }
  function post(name, body) { if (window.WI && WI.post) return WI.post(name, body || {}); return fetch('?api=' + name, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: JSON.stringify(body || {}) }).then(safeJson, netFail); }
  function fmt(n) { return Number(n || 0).toLocaleString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB'); }
  function when(t) { var d = new Date(t); return isNaN(d) ? '' : d.toLocaleDateString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }) + ' ' + d.toLocaleTimeString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { hour: '2-digit', minute: '2-digit' }); }
  function day(t) { var d = new Date(t); return isNaN(d) ? '' : d.toLocaleDateString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { day: 'numeric', month: 'short', year: 'numeric' }); }
  function avatar(jid, name) { var ch = String(name || '').replace(/^\+/, '').charAt(0); return '<div class="av" style="background:#64748b">' + (ch && !/\d/.test(ch) ? esc(ch.toUpperCase()) : '👤') + (jid ? '<img data-ph="?api=photo&jid=' + encodeURIComponent(jid) + '" alt="" hidden>' : '') + '</div>'; }
  function localInput(d) { var z = new Date(d.getTime() - d.getTimezoneOffset() * 60000); return z.toISOString().slice(0, 16); }
  function fail(el, r) { el.innerHTML = '<div class="empty">⚠️ ' + esc((r && r.error) || T('Failed')) + '</div>'; }
  function toast(msg) { var t = document.createElement('div'); t.className = 'fx-toast'; t.textContent = msg; document.body.appendChild(t); setTimeout(function () { t.remove(); }, 3500); }
  function mediaUrl(att) { if (!att) return ''; var p = String(att).split('/'); return p[0] === 'inbox_uploads' ? '?api=media&f=' + encodeURIComponent(p[1]) : (p[0] === 'inbox_media' ? '?api=media&a=' + encodeURIComponent(p[1]) + '&f=' + encodeURIComponent(p[2]) : ''); }
  function itemHtml(i) {
    var u = mediaUrl(i.attachment);
    if (i.kind === 'voice' || i.kind === 'audio') return u ? '<audio controls preload="none" src="' + u + '"></audio>' : (i.fetchable ? '<button class="chip-btn sm" data-fetch="' + esc(i.id) + '">🎤 ' + T('Download voice note') + '</button>' : '<span class="muted small">🎤 ' + T('Voice note (not kept)') + '</span>');
    if (i.kind === 'image') return u ? '<a href="' + u + '" target="_blank" rel="noopener"><img class="reg-img" src="' + u + '" alt="" loading="lazy"></a>' : (i.fetchable ? '<button class="chip-btn sm" data-fetch="' + esc(i.id) + '">🖼️ ' + T('Download photo') + '</button>' : '');
    if (i.kind !== 'text' && u) return '<a class="chip-btn sm" href="' + u + '" target="_blank">📎 ' + esc(i.kind) + '</a>';
    return '';
  }
  document.addEventListener('click', function (e) {
    var f = e.target.closest('[data-fetch]'); if (!f || !root) return;
    f.disabled = true; f.textContent = T('Downloading…');
    post('fetch_media', { id: f.dataset.fetch }).then(function (r) { if (r.ok && r.attachment) f.outerHTML = itemHtml({ kind: /\.(jpe?g|png|webp)$/i.test(r.attachment) ? 'image' : 'voice', attachment: r.attachment }); else f.textContent = r.error || T('Failed'); });
  });
  function inRange(t, from, to) { if (!from && !to) return true; var d = String(t || '').slice(0, 10); if (!d) return false; return (!from || d >= from) && (!to || d <= to); }
  function fbar(id, opts) {
    // opts: {sort:[[v,label]], groups:{jid:name}, dates:true, extra:html}
    return '<div class="fbar" id="' + id + '">' + (opts.sort ? '<label>↕️ ' + T('Sort') + ' <select data-f="sort">' + opts.sort.map(function (o) { return '<option value="' + o[0] + '">' + T(o[1]) + '</option>'; }).join('') + '</select></label>' : '')
      + (opts.groups ? '<label>👥 <select data-f="group"><option value="">' + T('All groups') + '</option>' + Object.keys(opts.groups).map(function (k) { return '<option value="' + esc(k) + '">' + esc(opts.groups[k]) + '</option>'; }).join('') + '</select></label>' : '')
      + (opts.dates ? '<label>📅 ' + T('From') + ' <input type="date" data-f="from"></label><label>' + T('To') + ' <input type="date" data-f="to"></label><span class="fq">' + ['7', '30', '90'].map(function (n) { return '<button class="link-btn" data-days="' + n + '">' + n + T('d') + '</button>'; }).join('') + '<button class="link-btn" data-days="">' + T('Any time') + '</button></span>' : '')
      + (opts.extra || '') + '</div>';
  }
  function fval(id) { var el = document.getElementById(id), o = {}; if (!el) return o; [].forEach.call(el.querySelectorAll('[data-f]'), function (i) { o[i.dataset.f] = i.value; }); return o; }
  function fwire(id, cb) {
    var el = document.getElementById(id); if (!el) return;
    el.addEventListener('change', cb);
    el.addEventListener('click', function (e) { var b = e.target.closest('[data-days]'); if (!b) return; var f = el.querySelector('[data-f=from]'), t = el.querySelector('[data-f=to]'); if (b.dataset.days) { var d = new Date(Date.now() - b.dataset.days * 86400000); f.value = d.toISOString().slice(0, 10); t.value = ''; } else { f.value = ''; t.value = ''; } cb(); });
  }
  /* ---- Pagination shared by every list ---- */
  var PG = {}, PGR = {};
  function pg(key, rows, redraw, def) {
    var st = PG[key] = PG[key] || { p: 1, n: Number(viewGet('pg_' + key, def || 50)) };
    PGR[key] = redraw; var pages = Math.max(1, Math.ceil(rows.length / st.n)); if (st.p > pages) st.p = pages;
    var from = (st.p - 1) * st.n, list = rows.slice(from, from + st.n), nums = [];
    for (var i = 1; i <= pages; i++) if (i === 1 || i === pages || Math.abs(i - st.p) <= 2) nums.push(i); else if (nums[nums.length - 1] !== '…') nums.push('…');
    var html = rows.length <= 10 ? '' : '<div class="pager" data-pgk="' + key + '"><span class="muted small">' + fmt(rows.length ? from + 1 : 0) + '–' + fmt(from + list.length) + ' ' + T('of') + ' ' + fmt(rows.length) + '</span>'
      + '<button class="pg-b" data-pg="' + (st.p - 1) + '" ' + (st.p <= 1 ? 'disabled' : '') + '>‹</button>' + nums.map(function (x) { return x === '…' ? '<span class="pg-gap">…</span>' : '<button class="pg-b' + (x === st.p ? ' on' : '') + '" data-pg="' + x + '">' + x + '</button>'; }).join('')
      + '<button class="pg-b" data-pg="' + (st.p + 1) + '" ' + (st.p >= pages ? 'disabled' : '') + '>›</button><select class="pg-n">' + [20, 50, 100, 200].map(function (n) { return '<option value="' + n + '" ' + (n === st.n ? 'selected' : '') + '>' + n + ' / ' + T('page') + '</option>'; }).join('') + '</select></div>';
    return { rows: list, html: html, offset: from };
  }
  document.addEventListener('click', function (e) { var b = e.target.closest('.pager [data-pg]'); if (!b) return; var k = b.closest('.pager').dataset.pgk; PG[k].p = Number(b.dataset.pg); if (PGR[k]) PGR[k](); var top = b.closest('.card') || root; if (top && top.scrollIntoView) top.scrollIntoView({ block: 'start', behavior: 'smooth' }); });
  document.addEventListener('change', function (e) { var s = e.target.closest('.pager .pg-n'); if (!s) return; var k = s.closest('.pager').dataset.pgk; PG[k].n = Number(s.value); PG[k].p = 1; viewSet('pg_' + k, s.value); if (PGR[k]) PGR[k](); });
  function pgReset(key) { if (PG[key]) PG[key].p = 1; }
  function viewGet(k, d) { try { return localStorage.getItem('wi_view_' + k) || d; } catch (e) { return d; } }
  function viewSet(k, v) { try { localStorage.setItem('wi_view_' + k, v); } catch (e) {} }
  function viewSwitch(k, cur) { return '<div class="seg view-sw" data-vk="' + k + '"><button data-v="icons" class="' + (cur === 'icons' ? 'on' : '') + '" title="' + T('Icons') + '">▦ ' + T('Icons') + '</button><button data-v="list" class="' + (cur === 'list' ? 'on' : '') + '" title="' + T('List') + '">☰ ' + T('List') + '</button></div>'; }
  function hero(icon, title, sub, tools) { return '<section class="hero"><div class="hero-t"><span class="hero-ic">' + icon + '</span><div><h1>' + T(title) + '</h1><p class="muted">' + T(sub) + '</p></div></div>' + (tools ? '<div class="hero-tools">' + tools + '</div>' : '') + '</section>'; }

  /* ===================== Shell v3: top bar title, tooltips, connection dot ===================== */
  (function () {
    var on = document.querySelector('#side-nav a.on');
    [].forEach.call(document.querySelectorAll('#side-nav a'), function (a) { if (!a.title) a.title = a.textContent.trim(); });
    var nm = document.getElementById('tb-name'), ic = document.getElementById('tb-ic');
    function label(a) { var c = a.cloneNode(true), x = c.querySelector('i'); if (x) x.remove(); return c.textContent.trim(); }
    [].forEach.call(document.querySelectorAll('#side-nav a'), function (a) { a.title = label(a); });
    if (nm && on) { nm.textContent = label(on); var i = on.querySelector('i'); if (i && ic) ic.textContent = i.textContent; }
    var h1 = document.querySelector('.hero h1, header.head h1'); if (nm && !on && h1) nm.textContent = h1.textContent;
    var dot = document.getElementById('tb-dot'), gw = document.getElementById('gw-state');
    function sync() { if (!dot || !gw) return; var t = gw.textContent || ''; dot.className = 'tb-dot ' + (/connect|متصل/i.test(t) && !/not|غير|dis/i.test(t) ? 'on' : (t.trim() && t.trim() !== '…' ? 'off' : '')); dot.title = t; }
    if (gw) new MutationObserver(sync).observe(gw, { childList: true, characterData: true, subtree: true }); sync();
  })();

  /* ===================== AI activity widget (every page) ===================== */
  (function () {
    // Docked at the top of the page (under the header), above the page content.
    var w = document.createElement('div'); w.className = 'ai-w'; w.hidden = true;
    var host = document.querySelector('main.main') || document.body; host.insertBefore(w, host.firstChild);
    var open = false, timer = null, lastId = '';
    function tok(x) { return fmt((x.tokens_in || 0) + (x.tokens_out || 0)); }
    function row(x) {
      var st = x.state === 'running' ? '<span class="ai-spin"></span>' : (x.state === 'done' ? '✅' : '⚠️');
      var pr = x.progress && x.progress.total ? '<div class="prog"><i style="width:' + Math.round(x.progress.done / x.progress.total * 100) + '%"></i></div><small>' + fmt(x.progress.done) + ' / ' + fmt(x.progress.total) + '</small>' : '';
      return '<div class="ai-wr ' + x.state + '">' + st + '<div class="ai-wb"><b>' + esc(T(x.task)) + '</b>' + (x.subject ? ' <small class="muted" dir="auto">' + esc(x.subject) + '</small>' : '')
        + '<div class="muted small">' + (x.state === 'running' ? T('working…') + ' ' + Math.round((Date.now() - Date.parse(x.at)) / 1000) + 's' : esc(x.model || '—') + ' · 🔢 ' + tok(x) + ' ' + T('tokens') + ' (' + fmt(x.tokens_in) + '↑ ' + fmt(x.tokens_out) + '↓)' + ' · ⏱ ' + ((x.ms || 0) / 1000).toFixed(1) + 's' + (x.requests > 1 ? ' · ' + x.requests + ' ' + T('attempts') : '')) + '</div>'
        + (x.error ? '<div class="small bad-t">' + esc(x.error) + '</div>' : '') + pr + '</div></div>';
    }
    function poll() {
      get('ai_tasks', { n: open ? 12 : 3 }).then(function (r) {
        if (!r || !r.ok) return;
        var running = r.running.length, latest = r.recent[0];
        var fresh = latest && latest.id !== lastId && Date.now() - Date.parse(latest.at) < 120000;
        if (latest) lastId = latest.id;
        w.hidden = !(running || open || fresh);
        w.innerHTML = '<div class="ai-wh"><b>🤖 ' + T('AI activity') + '</b><span class="muted small">' + T('today') + ': ' + fmt(r.today.tasks) + ' ' + T('tasks') + ' · ' + fmt(r.today.tokens_in + r.today.tokens_out) + ' ' + T('tokens') + '</span><button class="link-btn" data-aiw="t">' + (open ? '▾' : '▴') + '</button><button class="link-btn" data-aiw="x">✕</button></div>'
          + (r.running.map(row).join('') + (open ? r.recent.filter(function (x) { return x.state !== 'running'; }).map(row).join('') : (r.recent[0] && r.recent[0].state !== 'running' ? row(r.recent[0]) : '')));
        clearTimeout(timer); timer = setTimeout(poll, running ? 2000 : 8000);
      }).catch(function () { clearTimeout(timer); timer = setTimeout(poll, WIR(15000)); });
    }
    w.addEventListener('click', function (e) { var b = e.target.closest('[data-aiw]'); if (!b) return; if (b.dataset.aiw === 't') open = !open; else { open = false; w.hidden = true; } poll(); });
    window.WI_AI_POLL = function () { clearTimeout(timer); timer = setTimeout(poll, 400); };
    document.addEventListener('click', function (e) { if (e.target.closest('#lib-ai,#lib-best,[data-ai-do],#fx-suggest,#cd-ai,[data-tool="summary"],#sum-all')) window.WI_AI_POLL(); }, true);
    poll();
  })();

  /* ===================== Chat tools (inbox) ===================== */
  if (page === 'inbox') {
    var composer = document.getElementById('composer'), box = document.getElementById('reply'), msgs = document.getElementById('msgs') || document.querySelector('.msgs');
    function openJid() { return decodeURIComponent((location.hash || '').slice(1)); }
    if (composer && box) {
      var bar = document.createElement('div'); bar.className = 'fx-cbar';
      bar.innerHTML = '<button type="button" class="chip-btn sm" id="fx-suggest">🤖 ' + T('Suggest a reply') + '</button><button type="button" class="chip-btn sm" id="fx-sched">⏰ ' + T('Schedule') + '</button><a class="chip-btn sm" id="fx-prof" href="#">👤 ' + T('Customer profile') + '</a><span class="muted small" id="fx-cst"></span>';
      composer.parentNode.insertBefore(bar, composer);
      var qbar = document.createElement('div'); qbar.className = 'fx-quote'; qbar.hidden = true; composer.parentNode.insertBefore(qbar, composer);
      function syncBar() { var j = openJid(); bar.hidden = !j || composer.hidden; document.getElementById('fx-prof').href = '?p=customer&jid=' + encodeURIComponent(j); }
      new MutationObserver(syncBar).observe(composer, { attributes: true }); window.addEventListener('hashchange', function () { window.WI_QUOTE = null; qbar.hidden = true; syncBar(); }); syncBar();
      document.addEventListener('wi-sent', function () { qbar.hidden = true; if (dbar.dataset.jid) post('bot_draft', { jid: dbar.dataset.jid }); dbar.hidden = true; });
      // The bot's draft for this chat (suggest-only mode, or when it was not confident enough to send).
      var dbar = document.createElement('div'); dbar.className = 'fx-draft'; dbar.hidden = true; composer.parentNode.insertBefore(dbar, bar);
      function loadDraft() { var j = openJid(); dbar.hidden = true; if (!j) return; get('bot_draft', { jid: j }).then(function (r) { if (!r.ok || !r.draft || openJid() !== j) return; var d = r.draft; dbar.dataset.jid = j;
        dbar.innerHTML = '<div class="fd-h">🤖 ' + T('Bot draft') + ' <span class="muted small">' + Math.round((d.confidence || 0) * 100) + '% · ' + esc(T(d.why || '')) + '</span></div><div class="fd-t" dir="auto">' + esc(d.reply) + '</div><div class="row"><button type="button" class="btn sm primary" id="fd-use">✍️ ' + T('Put in the reply box') + '</button><button type="button" class="link-btn" id="fd-x">✕ ' + T('Dismiss') + '</button></div>';
        dbar.hidden = false;
        document.getElementById('fd-use').onclick = function () { box.value = d.reply; box.dispatchEvent(new Event('input')); box.focus(); };
        document.getElementById('fd-x').onclick = function () { post('bot_draft', { jid: j }); dbar.hidden = true; }; }); }
      window.addEventListener('hashchange', loadDraft); loadDraft();
      // Bot status for this chat: /stop – /start (also a button).
      var pb = document.createElement('button'); pb.type = 'button'; pb.className = 'chip-btn sm'; pb.hidden = true; bar.insertBefore(pb, bar.firstChild);
      function loadPause() { var j = openJid(); pb.hidden = true; if (!j || /@g\.us$/.test(j)) return; get('bot_pause', { jid: j }).then(function (r) { if (!r.ok || r.mode === 'off' || openJid() !== j) return; pb.hidden = false; pb.dataset.p = r.paused ? '1' : ''; pb.textContent = r.paused ? '⏸️ ' + T('Bot paused here — /start') : '🤖 ' + T('Bot on here — /stop'); pb.title = T('Type /stop or /start in the chat (from here or the phone) to pause or resume the bot in this chat.'); }); }
      pb.onclick = function () { post('bot_pause', { jid: openJid(), pause: pb.dataset.p ? 0 : 1 }).then(loadPause); };
      window.addEventListener('hashchange', loadPause); loadPause();
      document.addEventListener('wi-sent', function () { setTimeout(loadPause, 300); });
      document.getElementById('fx-suggest').addEventListener('click', function () {
        var b = this, st = document.getElementById('fx-cst'); b.disabled = true; st.textContent = T('AI is writing a reply…');
        post('suggest_reply', { jid: openJid() }).then(function (r) {
          b.disabled = false;
          if (!r.ok) { st.textContent = '⚠️ ' + (r.error || T('Failed')); return; }
          box.value = r.reply; box.dispatchEvent(new Event('input')); box.focus();
          st.textContent = '✓ ' + T('Review it, then send') + (r.used ? ' · 📚 ' + T('from the library') : '') + (r.note ? ' · ' + r.note : '');
        }).catch(function () { b.disabled = false; st.textContent = '⚠️ ' + T('Failed'); });
      });
      document.getElementById('fx-sched').addEventListener('click', function () {
        var text = box.value.trim(), st = document.getElementById('fx-cst');
        if (!text) { st.textContent = T('Write the message first, then choose ⏰.'); box.focus(); return; }
        var d = new Date(Date.now() + 3600000);
        askForm('⏰ ' + T('Schedule this message'), [{ key: 'at', label: T('Send at'), type: 'datetime-local', value: localInput(d).slice(0, 16) }], T('Schedule')).then(function (f) { if (!f || !f.at) return; var v = f.at;
          post('schedule', { jid: openJid(), text: text, at: v }).then(function (r) { if (r.ok) { box.value = ''; st.textContent = '⏰ ' + T('Scheduled') + ' · ' + v.replace('T', ' '); } else st.textContent = '⚠️ ' + (r.error || T('Failed')); }); });
      });
      // Hover actions on a message: reply (quote) and react.
      document.addEventListener('click', function (e) {
        var act = e.target.closest('[data-fx-act]');
        if (act) {
          var bub = act.closest('.b[data-id]'); if (!bub) return;
          if (act.dataset.fxAct === 'del') {
            var out = bub.classList.contains('out'), q = out ? T('Delete this message for everyone? (WhatsApp allows it for about two days; after that it is deleted from this number only.)') : T('Delete this message? It is deleted from this number and the dashboard; the other person keeps their copy.');
            if (!confirm(q)) return;
            post('delete_msg', { jid: openJid(), id: bub.dataset.id }).then(function (r) { if (!r.ok) { toast('⚠️ ' + (r.error || T('Failed'))); return; } bub.remove(); toast('🗑️ ' + (r.everyone ? T('Deleted for everyone') : T('Deleted'))); });
            return;
          }
          if (act.dataset.fxAct === 'quote') {
            window.WI_QUOTE = bub.dataset.id; var t = (bub.querySelector('.t') || bub).textContent.trim().slice(0, 140);
            qbar.innerHTML = '<span>↩️ ' + esc(t) + '</span><button type="button" class="link-btn">✕</button>'; qbar.hidden = false; box.focus();
            qbar.querySelector('button').onclick = function () { window.WI_QUOTE = null; qbar.hidden = true; };
          } else {
            post('react', { jid: openJid(), id: bub.dataset.id, emoji: act.dataset.e }).then(function (r) { if (!r.ok) toast('⚠️ ' + (r.error || T('Failed'))); else { var x = bub.querySelector('.b-react'); if (!x) { x = document.createElement('span'); x.className = 'b-react'; bub.prepend(x); } x.textContent = act.dataset.e; } });
          }
          return;
        }
      });
      document.addEventListener('mouseover', function (e) {
        var bub = e.target.closest('.b[data-id]'); if (!bub || bub.querySelector('.fx-acts') || bub.classList.contains('pending')) return;
        var a = document.createElement('div'); a.className = 'fx-acts';
        var C = window.WI_CAN || {};
        a.innerHTML = (C.reply === false ? '' : '<button type="button" data-fx-act="quote" title="' + T('Reply to this message') + '">↩️</button>' + ['👍', '❤️', '🤲', '🙏', '✅'].map(function (x) { return '<button type="button" data-fx-act="react" data-e="' + x + '">' + x + '</button>'; }).join(''))
          + (C.delete_msg ? '<button type="button" data-fx-act="del" title="' + T('Delete message') + '">🗑️</button>' : '');
        if (!a.innerHTML) return;
        bub.appendChild(a);
      });
    }
    return;
  }

  var root = document.getElementById('fx-root');
  if (!root) return;
  var isAdmin = root.dataset.admin === '1';

  /* ===================== Cases ===================== */
  if (page === 'cases') {
    var C = { list: [], q: '', st: '', statuses: {} };
    var ph = root.dataset.phone;
    if (ph) { caseDetail(ph); return; }
    if (new URLSearchParams(location.search).get('tab') === 'registry') { registry(); return; }
    if (new URLSearchParams(location.search).get('tab') === 'new') { newCase(); return; }
    if (new URLSearchParams(location.search).get('tab') === 'settings') { caseSettings(); return; }
    // Opening a case from a group: "#keyword" + details → AI fills the form, asks the writer what is missing, posts the report to the diagnosis group
    function intakeCard() {
      get('case_intake_cfg').then(function (r) { var box = document.getElementById('ci-box'); if (!box) return; if (!r.ok) return fail(box, r); var c = r.cfg, G = r.groups, gk = Object.keys(G);
        box.innerHTML = '<h3>📥 ' + T('Open a case from a group') + '</h3><p class="muted small">' + T('Anyone in the chosen groups writes the keyword with the details. The AI fills the form, asks the writer in the group what is missing, then — answered or not when the time is up — saves the case and posts a copy to the diagnosis group.') + '</p>'
          + '<label class="check"><input type="checkbox" id="ci-on"' + (c.on ? ' checked' : '') + '> ' + T('On') + '</label>'
          + '<label class="check"><input type="checkbox" id="ci-men"' + (c.mention ? ' checked' : '') + '> ' + T('Only when this number is tagged (@ the bot, then the keyword and @ the person)') + '</label>'
          + '<div class="gcf"><label>' + T('Keyword') + '<input id="ci-kw" value="' + esc(c.keyword) + '" dir="auto" maxlength="30"></label>'
          + '<label>' + T('Wait for answers (minutes, 0 = do not ask)') + '<input id="ci-wait" type="number" min="0" max="1440" value="' + (+c.wait_min) + '"></label>'
          + '<label class="gcf-wide">' + T('Diagnosis group (gets the report)') + '<select id="ci-diag"><option value="">' + T('The first group of the case registry') + (r.diag && G[r.diag] ? ' — ' + esc(G[r.diag]) : '') + '</option>' + gk.map(function (j) { return '<option value="' + esc(j) + '"' + (c.diag === j ? ' selected' : '') + '>' + esc(G[j]) + '</option>'; }).join('') + '</select></label></div>'
          + '<h4>' + T('Groups where the keyword works') + ' <small class="muted">' + T('none chosen = every group except the diagnosis group') + '</small></h4><div class="ci-g">' + gk.map(function (j) { return '<label class="check"><input type="checkbox" data-cig="' + esc(j) + '"' + (c.groups.indexOf(j) >= 0 ? ' checked' : '') + '> ' + esc(G[j]) + '</label>'; }).join('') + '</div>'
          + '<h4>' + T('Ask the writer when missing') + '</h4><div class="ci-g">' + Object.keys(r.fields).map(function (k) { return '<label class="check"><input type="checkbox" data-cia="' + k + '"' + (c.ask.indexOf(k) >= 0 ? ' checked' : '') + '> ' + esc(r.fields[k]) + '</label>'; }).join('') + '</div>'
          + '<label class="check"><input type="checkbox" id="ci-conf"' + (c.confirm ? ' checked' : '') + '> ' + T('Tell the writer when the file is opened') + '</label>'
          + (r.waiting ? '<p class="muted small">⏳ ' + fmt(r.waiting) + ' ' + T('waiting for answers') + '</p>' : '')
          + '<p class="muted small">' + T('The wording of the questions and the confirmation is in Settings → Message templates; the AI instructions in AI prompts.') + '</p>'
          + '<div class="row"><button class="btn primary" id="ci-save">💾 ' + T('Save') + '</button></div>';
        box.querySelector('#ci-save').onclick = function () { var v = function (s) { return box.querySelector(s); }, pick = function (a) { return [].map.call(box.querySelectorAll('[' + a + ']:checked'), function (i) { return i.getAttribute(a); }); };
          post('case_intake_cfg', { on: v('#ci-on').checked ? 1 : 0, keyword: v('#ci-kw').value, wait_min: v('#ci-wait').value, diag: v('#ci-diag').value, groups: pick('data-cig'), ask: pick('data-cia'), confirm: v('#ci-conf').checked ? 1 : 0, mention: v('#ci-men').checked ? 1 : 0 })
            .then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); }); }; });
    }
    // Case settings of this number: what a case is called, the stage names, which form fields and their names
    function caseSettings() {
      root.innerHTML = hero('⚙️', 'Case settings', 'What a case means on this number: a patient, a support ticket or any follow-up. The words and fields below are used in the form, the list and the report.') + '<div class="card" id="cc-box"><div class="empty">' + T('Loading…') + '</div></div><div class="card" id="ci-box"></div>';
      intakeCard();
      get('case_cfg').then(function (r) { if (!r.ok) return fail(document.getElementById('cc-box'), r); var C = r.cfg, P = r.presets;
        function draw(c) { document.getElementById('cc-box').innerHTML = '<div class="gcf">'
          + '<label class="gcf-wide">' + T('Start from') + '<select id="cc-pre">' + Object.keys(P).map(function (k) { return '<option value="' + k + '"' + (c.preset === k ? ' selected' : '') + '>' + esc(T(P[k].label)) + '</option>'; }).join('') + '</select></label>'
          + '<label>' + T('One item is called') + '<input id="cc-one" value="' + esc(c.one) + '" dir="auto"></label><label>' + T('Many are called') + '<input id="cc-many" value="' + esc(c.many) + '" dir="auto"></label><label>' + T('Icon') + '<input id="cc-icon" value="' + esc(c.icon) + '" style="width:70px"></label></div>'
          + '<h3>' + T('Stages') + '</h3><div class="gcf">' + Object.keys(c.statuses).map(function (k) { return '<label>' + esc({ new: T('Stage 1 — new'), follow: T('Stage 2 — being worked on'), improving: T('Stage 3 — progressing / waiting'), closed: T('Stage 4 — finished'), postponed: T('Postponed'), removed: T('Removed'), archived: T('Archived') }[k] || k) + '<input data-cs="' + k + '" value="' + esc(c.statuses[k]) + '" dir="auto"></label>'; }).join('') + '</div>'
          + '<h3>' + T('Form fields') + '</h3><div class="cc-fields">' + Object.keys(c.fields).map(function (k) { var x = c.fields[k], fixed = k === 'name' || k === 'phone';
              return '<div class="cc-f"><label class="inv-sw"><input type="checkbox" data-fo="' + k + '"' + (x.on || fixed ? ' checked' : '') + (fixed ? ' disabled' : '') + '><i></i></label><input data-fl="' + k + '" value="' + esc(x.label) + '" dir="auto"></div>'; }).join('') + '</div>'
          + '<div class="row" style="margin-top:12px"><button class="btn primary" id="cc-save">💾 ' + T('Save') + '</button><span class="muted small" id="cc-msg"></span></div>'; }
        draw(C);
        document.getElementById('cc-box').addEventListener('change', function (e) { if (e.target.id === 'cc-pre') { var p = P[e.target.value]; draw({ preset: e.target.value, one: p.one, many: p.many, icon: p.icon, statuses: p.statuses, fields: p.fields }); } });
        document.getElementById('cc-box').addEventListener('click', function (e) { if (!e.target.closest('#cc-save')) return; var q = function (s) { return document.querySelector(s); }, st = {}, fl = {};
          [].forEach.call(document.querySelectorAll('[data-cs]'), function (i) { st[i.dataset.cs] = i.value; });
          [].forEach.call(document.querySelectorAll('[data-fl]'), function (i) { var o = q('[data-fo="' + i.dataset.fl + '"]'); fl[i.dataset.fl] = { label: i.value, on: o && o.checked ? 1 : 0 }; });
          post('case_cfg', { preset: q('#cc-pre').value, one: q('#cc-one').value, many: q('#cc-many').value, icon: q('#cc-icon').value, statuses: st, fields: fl }).then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); if (x.ok) setTimeout(function () { location.href = '?p=cases'; }, 600); }); });
      });
    }
    root.innerHTML = hero(((window.WI_PACK || {}).case_cfg || {}).icon || '🩺', ((window.WI_PACK || {}).case_cfg || {}).many || 'Cases', 'People in the groups you monitor (e.g. a case-reporting group): every message is archived, each person gets a case record, AI analysis and follow-up.',
      '<div class="search-xl"><span>🔎</span><input type="search" id="cs-q" placeholder="' + T('Search name, number, complaint…') + '" dir="auto"></div><button class="chip-btn" id="cs-sync">🔄 ' + T('Update now') + '</button>')
      + '<div class="page-tabs sub"><a href="?p=cases" class="on">' + (((window.WI_PACK || {}).case_cfg || {}).icon || '🩺') + ' ' + (((window.WI_PACK || {}).case_cfg || {}).many || T('Followed cases')) + '</a><a href="?p=cases&tab=registry">📋 ' + T('Case registry') + '</a><a href="?p=cases&tab=settings">⚙️ ' + T('Case settings') + '</a><a href="?p=cases&tab=new">➕ ' + T('New case') + '</a></div>'
      + '<div class="card"><div class="seg" id="cs-st"></div><div id="cs-groups" class="muted small" style="margin:8px 0"></div><div id="cs-fwrap"></div><div id="cs-list" class="cs-list cs-cards"><div class="empty">' + T('Loading…') + '</div></div></div>';
    function newCase() {
      var CF = ((window.WI_PACK || {}).case_cfg || {}).fields || {}, off = function (id) { return CF[id] && !CF[id].on; }, nm = function (id, label) { return CF[id] ? esc(CF[id].label) : T(label); };
      var f = function (id, label, ph, type) { if (off(id)) return ''; return '<label>' + nm(id, label) + '<input id="nc-' + id + '" ' + (type ? 'type="' + type + '"' : '') + ' placeholder="' + esc(ph || '') + '" dir="auto"></label>'; };
      var ta = function (id, label, ph, rows) { if (off(id)) return ''; return '<label class="gcf-wide">' + nm(id, label) + '<textarea id="nc-' + id + '" rows="' + (rows || 3) + '" dir="auto" placeholder="' + esc(T(ph || '')) + '"></textarea></label>'; };
      root.innerHTML = hero('➕', 'New case', 'Fill in the case: it is saved as a case under follow-up, gets its own file, and a copy of the report is posted to the diagnosis group.')
        + '<div class="page-tabs sub"><a href="?p=cases">' + (((window.WI_PACK || {}).case_cfg || {}).icon || '🩺') + ' ' + (((window.WI_PACK || {}).case_cfg || {}).many || T('Followed cases')) + '</a><a href="?p=cases&tab=registry">📋 ' + T('Case registry') + '</a><a href="?p=cases&tab=settings">⚙️ ' + T('Case settings') + '</a><a href="?p=cases&tab=new" class="on">➕ ' + T('New case') + '</a></div>'
        + '<div class="nc-wrap"><div class="card nc-form"><h3>👤 ' + T('Basic information') + '</h3><div class="gcf">'
        + '<div class="gcf-wide nc-pick"><label>📥 ' + T('Reception group') + '<select id="nc-rgroup"><option value="">' + T('Loading…') + '</option></select></label>'
        + '<label>🔎 ' + T('Choose the visitor') + '<input id="nc-vq" type="search" placeholder="' + T('Search name or number…') + '" dir="auto" disabled></label><div class="nc-vlist" id="nc-vlist"></div></div>'
        + '<label>' + T('Name') + '<input id="nc-name" readonly placeholder="' + T('chosen from the reception group') + '" dir="auto"></label>'
        + '<label>' + T('Phone number') + '<input id="nc-phone" readonly placeholder="' + T('chosen from the reception group') + '" dir="ltr"></label>'
        + (off('join') ? '' : '<label>' + nm('join', 'Join date') + '<input type="date" id="nc-join" value="' + new Date().toISOString().slice(0, 10) + '"></label>') + f('age', 'Age', PK('ex_age'))
        + (off('marital') ? '' : '<label>' + nm('marital', 'Marital status') + '<select id="nc-marital"><option value="">—</option>' + PK('marital').split('\n').filter(Boolean).map(function (o) { return '<option>' + esc(o) + '</option>'; }).join('') + '</select></label>')
        + f('kids', 'Number of children', PK('ex_kids')) + (off('country') ? '' : '<label>' + nm('country', 'Country') + '<input id="nc-country" list="nc-countries" dir="auto" placeholder="' + esc(T('Choose or type')) + '">' + countryList('nc-countries') + '</label>') + '</div>'
        + '<h3>👥 ' + T('Follow-up group and follower') + '</h3><div class="gcf">'
        + '<label>' + T('Follow-up group') + '<select id="nc-tgroup"><option value="">' + T('Loading…') + '</option></select></label>'
        + '<label>' + T('Followed by (group admins)') + '<select id="nc-follower" disabled><option value="">' + T('Choose the group first') + '</option></select></label></div>'
        + '<h3>📝 ' + T('Case details') + '</h3><div class="gcf">'
        + ta('story', 'Story / history', 'What they describe, what was done before, notes…', 5)
        + ta('symptoms', 'Confirmed symptoms (one per line)', PK('ex_points'), 6)
        + ta('extra', 'Extra symptoms', '', 2) + '</div>'
        + '<h3>🩺 ' + T('Diagnosis') + '</h3><div class="gcf">' + f('diagnosis', 'Diagnosis', PK('ex_assessment')) + f('diag_by', 'Diagnosed by', '') + f('placement', 'Placement note', T('filled from the group'))
        + '<label>' + T('Status') + '<select id="nc-status"><option value="follow">🩺 ' + T('Under follow-up') + '</option><option value="new">🆕 ' + T('New') + '</option></select></label></div>'
        + '<div class="row wrap nc-send"><label class="check"><input type="checkbox" id="nc-send" checked> 📤 ' + T('Post a copy of the report to the group') + '</label><select id="nc-group"></select></div>'
        + '<div class="row"><button class="btn primary" id="nc-save">💾 ' + T('Save the case') + '</button><span class="muted small" id="nc-msg"></span></div></div>'
        + '<div class="card nc-prev"><h3>👁 ' + T('Report preview') + '</h3><pre id="nc-pre" dir="auto"></pre></div></div>';
      var v = function (k) { var e = document.getElementById('nc-' + k); return e ? e.value.trim() : ''; };
      // Name and number come only from the visitors (non-admins) of the reception group.
      var VIS = [], HAVE = {}, VF = '';
      // Numbers that already have a case (followed or in the registry) are marked, so nobody is entered twice.
      get('cases').then(function (r) { (r.cases || []).forEach(function (c) { HAVE[c.phone] = { st: c.status || 'new', name: c.name || '' }; (c.lids || []).forEach(function (l) { HAVE[l] = HAVE[c.phone]; }); }); drawV(); });
      get('registry').then(function (r) { (r.entries || []).forEach(function (e) { (e.phones || []).forEach(function (p) { if (!HAVE[p]) HAVE[p] = { st: 'registry', name: e.name_guess || '' }; }); }); drawV(); });
      function drawV() { if (!document.getElementById('nc-vq')) return; var q = (document.getElementById('nc-vq').value || '').toLowerCase(), cur = v('phone').replace(/\D/g, '');
        var all = VIS.filter(function (x) { return !q || (x.name + ' ' + x.phone).toLowerCase().indexOf(q) !== -1; });
        var nOld = all.filter(function (x) { return HAVE[x.phone]; }).length;
        var rows = all.filter(function (x) { return VF === 'old' ? HAVE[x.phone] : VF === 'new' ? !HAVE[x.phone] : true; }).slice(0, 80);
        document.getElementById('nc-vlist').innerHTML = (VIS.length ? '<div class="nc-vf"><button type="button" class="chip-btn sm' + (VF === '' ? ' on' : '') + '" data-vf="">' + T('All') + ' (' + fmt(all.length) + ')</button><button type="button" class="chip-btn sm' + (VF === 'new' ? ' on' : '') + '" data-vf="new">🆕 ' + T('New visitors') + ' (' + fmt(all.length - nOld) + ')</button><button type="button" class="chip-btn sm' + (VF === 'old' ? ' on' : '') + '" data-vf="old">✔ ' + T('Already a case') + ' (' + fmt(nOld) + ')</button></div>' : '')
          + rows.map(function (x) { var h = HAVE[x.phone];
            return '<button type="button" class="chip-btn sm' + (x.phone === cur ? ' on' : '') + (h ? ' nc-old' : '') + '" data-vis="' + esc(x.phone) + '" data-n="' + esc(x.name || '') + '"' + (h ? ' data-have="' + esc(h.st) + '"' : '') + '>' + (h ? '✔ ' : '👤 ') + esc(x.name || h && h.name || T('No name')) + ' <span dir="ltr">+' + esc(x.phone) + '</span>' + (h ? ' <em>' + (h.st === 'registry' ? T('in the registry') : T('has a case')) + '</em>' : '') + '</button>'; }).join('') || (VIS.length ? '<span class="muted small">' + T('No results.') + '</span>' : ''); }
      function loadV(gj) { var vq = document.getElementById('nc-vq'); VIS = []; vq.disabled = !gj; document.getElementById('nc-vlist').innerHTML = gj ? '<span class="muted small">' + T('Loading…') + '</span>' : '';
        try { localStorage.setItem('wi_nc_rgroup', gj); } catch (e) {}
        if (!gj) return; get('meeting_targets', { group: gj, ctx: 'cases' }).then(function (m) { VIS = (m.members || []).filter(function (x) { return !x.admin; }); drawV(); }); }
      document.getElementById('nc-rgroup').onchange = function () { loadV(this.value); };
      document.getElementById('nc-vq').oninput = drawV;
      document.getElementById('nc-vlist').onclick = function (e) { var f = e.target.closest('[data-vf]'); if (f) { VF = f.dataset.vf; drawV(); return; }
        var b = e.target.closest('[data-vis]'); if (!b) return;
        if (b.dataset.have && b.dataset.have !== 'registry' && confirm(T('This number already has a case. Open its record instead?'))) { location.href = '?p=cases&phone=' + encodeURIComponent(b.dataset.vis); return; }
        document.getElementById('nc-name').value = b.dataset.n || ''; document.getElementById('nc-name').readOnly = !!b.dataset.n; document.getElementById('nc-phone').value = '+' + b.dataset.vis; var ncC = document.getElementById('nc-country'); if (ncC && !ncC.value.trim()) ncC.value = countryOf(b.dataset.vis); drawV(); preview(); };
      // Treatment groups → their admins are the people who can follow the case.
      get('meeting_targets', { ctx: 'cases' }).then(function (r) { var rs = document.getElementById('nc-rgroup'), saved = ''; try { saved = localStorage.getItem('wi_nc_rgroup') || ''; } catch (e) {}
        var guess = saved || ((r.groups || []).filter(function (g) { var rp = PK('reception_pattern'); try { return rp && new RegExp(rp, 'i').test(g.name); } catch (e) { return false; } })[0] || {}).jid || '';
        rs.innerHTML = '<option value="">' + T('Choose the reception group…') + '</option>' + (r.groups || []).map(function (g) { return '<option value="' + esc(g.jid) + '"' + (g.jid === guess ? ' selected' : '') + '>' + esc(g.name) + ' (' + g.size + ')</option>'; }).join('');
        if (guess) loadV(guess);
        var sel = document.getElementById('nc-tgroup');
        sel.innerHTML = '<option value="">' + T('Choose the follow-up group…') + '</option>' + (r.groups || []).map(function (g) { return '<option value="' + esc(g.jid) + '">' + esc(g.name) + ' (' + g.size + ')</option>'; }).join(''); });
      document.getElementById('nc-tgroup').onchange = function () { var fs = document.getElementById('nc-follower'), gname = this.options[this.selectedIndex].text.replace(/\s*\(\d+\)$/, '');
        fs.disabled = true; fs.innerHTML = '<option value="">' + (this.value ? T('Loading…') : T('Choose the group first')) + '</option>';
        var pl = document.getElementById('nc-placement'); if (this.value && (!pl.value || pl.dataset.auto)) { var fh = PK('female_hint'), tpl = (fh && document.getElementById('nc-marital').value.indexOf(fh) !== -1 ? PK('placement_f') : PK('placement_m')) || '{group}'; pl.value = tpl.replace('{group}', gname); pl.dataset.auto = 1; }
        if (!this.value) { preview(); return; }
        get('meeting_targets', { group: this.value, ctx: 'cases' }).then(function (m) { var ad = (m.members || []).filter(function (x) { return x.admin; });
          fs.innerHTML = ad.length ? '<option value="">' + T('Choose who follows the case…') + '</option>' + ad.map(function (x) { return '<option value="' + esc(x.name || ('+' + x.phone)) + '" data-ph="' + esc(x.phone) + '">' + esc(x.name || '') + ' · +' + esc(x.phone) + '</option>'; }).join('') : '<option value="">' + T('No admins found in this group') + '</option>';
          fs.disabled = !ad.length; preview(); }); };
      document.getElementById('nc-placement').oninput = function () { delete this.dataset.auto; };
      get('registry').then(function (r) { var g = r && r.groups ? Object.keys(r.groups) : [], sel = document.getElementById('nc-group');
        sel.innerHTML = g.length ? g.map(function (k) { return '<option value="' + esc(k) + '">' + esc(r.groups[k].name || k) + '</option>'; }).join('') : '<option value="">' + T('No diagnosis group chosen yet') + '</option>'; });
      // Preview = the exact report the server will post (message template "case_report"), asked for after a short pause in typing.
      var pvT = null;
      function preview() { clearTimeout(pvT); pvT = setTimeout(function () { var body = { preview: 1 }; ['name', 'phone', 'join', 'age', 'marital', 'kids', 'country', 'follower', 'story', 'symptoms', 'extra', 'diagnosis', 'diag_by', 'placement'].forEach(function (k) { body[k] = v(k); });
        post('case_new', body).then(function (x) { if (x && x.ok && typeof x.report === 'string') document.getElementById('nc-pre').textContent = x.report; }); }, 500); }
      root.addEventListener('input', preview); root.addEventListener('change', preview); preview();
      document.getElementById('nc-save').onclick = function () { var b = this, msg = document.getElementById('nc-msg');
        if (v('phone').replace(/\D/g, '').length < 8) { msg.textContent = '⚠️ ' + T('Choose the visitor from the reception group.'); return; }
        if (!v('name')) { msg.textContent = '⚠️ ' + T('This visitor has no name on WhatsApp — write the name.'); return; }
        var body = {}; ['name', 'phone', 'join', 'age', 'marital', 'kids', 'country', 'follower', 'tgroup', 'story', 'symptoms', 'extra', 'diagnosis', 'diag_by', 'placement', 'status', 'group'].forEach(function (k) { body[k] = v(k); });
        body.send = document.getElementById('nc-send').checked ? 1 : 0;
        var fo = document.getElementById('nc-follower'); body.follower_phone = fo.selectedIndex > 0 ? fo.options[fo.selectedIndex].dataset.ph || '' : '';
        var tg = document.getElementById('nc-tgroup'); body.tgroup_name = tg.selectedIndex > 0 ? tg.options[tg.selectedIndex].text.replace(/\s*\(\d+\)$/, '') : '';
        if (!body.tgroup || !body.follower) { msg.textContent = '⚠️ ' + T('Choose the follow-up group and who follows the case.'); return; }
        if (body.send && !confirm(T('Post this report to the group now?'))) return;
        b.disabled = true; msg.textContent = T('Saving…');
        post('case_new', body).then(function (x) { b.disabled = false;
          if (!x.ok) { msg.textContent = '⚠️ ' + (x.error || T('Failed')); return; }
          msg.innerHTML = '✓ ' + T('Case saved and its file created') + (x.sent === true ? ' · 📤 ' + T('posted to') + ' ' + esc(x.group) : x.sent === false ? ' · ⚠️ ' + T('could not post to the group') : '') + ' · <a href="?p=cases&phone=' + encodeURIComponent(x.phone) + '">' + T('Open the case') + ' →</a>'; }); };
    }
    function load(sync) {
      get('cases', sync ? { sync: 1 } : {}).then(function (r) {
        if (!r.ok) return fail(document.getElementById('cs-list'), r);
        C.list = r.cases; C.statuses = r.statuses;
        if (!document.getElementById('cs-f')) { var gm = {}; C.list.forEach(function (c) { Object.keys(c.groups || {}).forEach(function (k) { gm[k] = c.groups[k]; }); }); var lb = {}; C.list.forEach(function (c) { (c.labels || []).forEach(function (l) { lb[l] = 1; }); });
          document.getElementById('cs-fwrap').innerHTML = fbar('cs-f', { sort: [['last', 'Last message (newest)'], ['first', 'First seen (newest)'], ['msgs', 'Most messages'], ['name', 'Name'], ['number', 'Number']], groups: gm, dates: true, extra: Object.keys(lb).length ? '<label>🏷️ <select data-f="label"><option value="">' + T('All labels') + '</option>' + Object.keys(lb).map(function (l) { return '<option>' + esc(l) + '</option>'; }).join('') + '</select></label>' : '' }); fwire('cs-f', draw); }
        var g = Object.keys(r.groups || {});
        document.getElementById('cs-groups').innerHTML = g.length ? '👥 ' + T('Monitored groups') + ': ' + g.map(function (k) { return '<b>' + esc(r.groups[k].name || k) + '</b>'; }).join(' · ') + ' — <a href="?p=groups">' + T('change') + '</a>'
          : '⚠️ ' + T('No monitored group yet.') + ' <a href="?p=groups">' + T('Choose the case groups in Groups →') + '</a>';
        var counts = r.counts || {};
        document.getElementById('cs-st').innerHTML = '<button data-s="" class="' + (C.st ? '' : 'on') + '">' + T('All') + ' <i>' + fmt(C.list.length - (counts.archived || 0)) + '</i></button>' + Object.keys(C.statuses).map(function (k) { return '<button data-s="' + k + '" class="' + (C.st === k ? 'on' : '') + '">' + T(C.statuses[k]) + ' <i>' + fmt(counts[k] || 0) + '</i></button>'; }).join('');
        draw();
      });
    }
    function draw() {
      var q = C.q.toLowerCase();
      var f = fval('cs-f'), srt = f.sort || 'last';
      var rows = C.list.filter(function (c) { return (C.st ? (c.status || 'new') === C.st : c.status !== 'archived') && (!f.group || (c.groups || {})[f.group]) && (!f.label || (c.labels || []).indexOf(f.label) !== -1) && inRange(c.last, f.from, f.to) && (!q || JSON.stringify([c.name, c.phone, c.data, c.last_text]).toLowerCase().indexOf(q) !== -1); })
        .sort(function (a, b) { return srt === 'first' ? String(b.first).localeCompare(String(a.first)) : (srt === 'msgs' ? (b.msgs || 0) - (a.msgs || 0) : (srt === 'name' ? String(a.name || '~').localeCompare(String(b.name || '~')) : (srt === 'number' ? String(a.phone).localeCompare(String(b.phone)) : String(b.last).localeCompare(String(a.last))))); });
      var P = pg('cases', rows, draw, 50);
      document.getElementById('cs-list').innerHTML = P.rows.map(function (c) {
        var d = c.data || {};
        // One card per case: the whole card, the name and every label open the case record.
        var dg = d.diagnosis || (c.ai && c.ai.diagnosis) || '';
        return '<a class="cs-card st-' + esc(c.status || 'new') + '" href="?p=cases&phone=' + encodeURIComponent(c.phone) + '" title="' + T('Open the case record') + '">'
          + '<div class="cs-top">' + avatar(c.phone + '@s.whatsapp.net', c.name || c.phone) + '<div class="cs-who"><b dir="auto">' + (c.no ? '<span class="cs-id">ID ' + c.no + '</span> ' : '') + esc(c.name || ('+' + c.phone)) + '</b><span class="muted small" dir="ltr">+' + esc(c.phone) + '</span></div><span class="pill st-' + esc(c.status || 'new') + '">' + T(C.statuses[c.status || 'new'] || '') + '</span></div>'
          + '<div class="cs-lbls">' + (dg ? '<span class="lbl dg">🩺 ' + esc(String(dg).slice(0, 40)) + '</span>' : '') + (c.labels || []).map(function (l) { return '<span class="lbl">🏷️ ' + esc(l) + '</span>'; }).join('') + (c.customer ? '<span class="lbl">⭐ ' + T('Customer') + '</span>' : '') + (c.manual ? '<span class="lbl">📝 ' + T('From the form') + '</span>' : '') + '</div>'
          + '<div class="cs-txt" dir="auto">' + esc(d.complaint || d.story || c.last_text || '') + '</div>'
          + '<div class="cs-meta"><span>💬 ' + fmt(c.msgs || 0) + '</span>' + (c.media ? '<span>📎 ' + fmt(c.media) + '</span>' : '') + '<span>📅 ' + esc(day(c.first || c.created)) + (c.last ? ' → ' + esc(day(c.last)) : '') + '</span>' + (c.ai ? '<span>🤖 ' + esc(c.ai.progress || '') + '</span>' : '') + '<span class="cs-go">' + T('Open') + ' ←</span></div></a>';
      }).join('') || '<div class="empty">' + T('No cases yet.') + '</div>';
      document.getElementById('cs-list').insertAdjacentHTML('beforeend', P.html);
    }
    load(false);
    document.getElementById('cs-sync').onclick = function () { this.disabled = true; load(true); };
    document.getElementById('cs-q').oninput = function () { C.q = this.value; pgReset('cases'); draw(); };
    document.getElementById('cs-st').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; C.st = b.dataset.s; load(false); };
    return;
  }
  function caseDetail(ph) {
    root.innerHTML = '<div class="empty">' + T('Loading…') + '</div>';
    get('case', { phone: ph }).then(function (r) {
      if (!r.ok) return fail(root, r);
      var c = r.case, d = c.data || {}, ai = c.ai, CC = r.case_cfg || {}, CF = CC.fields || {}, st = c.status || 'new', keys = Object.keys(r.statuses);
      // Fields: this number's case form (Cases → Settings) plus older fields that already hold something; never name / phone (they are in the header).
      var long = { symptoms: 1, extra: 1, story: 1, notes: 1, complaint: 1, diagnosis: 1 }, seen = {}, fields = [];
      var add = function (k, label) { if (seen[k] || k === 'name' || k === 'phone') return; seen[k] = 1; fields.push([k, label]); };
      add('complaint', T('Complaint'));
      Object.keys(CF).forEach(function (k) { if (CF[k].on) add(k, CF[k].label || k); });
      [['symptoms', 'Symptoms'], ['diagnosis', 'Assessment'], ['program', 'Program'], ['age', 'Age'], ['country', 'Country'], ['started', 'Follow-up started']].forEach(function (f) { if (String(d[f[0]] || '').trim()) add(f[0], T(f[1])); });
      add('gender', T('Gender')); // optional choice, always offered
      if (!String(d.country || '').trim() && countryOf(ph)) d.country = countryOf(ph); // filled from the phone number until someone changes it
      add('notes', T('Case notes'));
      var shortF = fields.filter(function (f) { return !long[f[0]]; }), longF = fields.filter(function (f) { return long[f[0]]; });
      var dates = { join: 1, started: 1 };
      var inp = function (f) { var k = f[0], v = d[k] || '', el;
        if (long[k]) el = '<textarea data-k="' + k + '" rows="3" dir="auto">' + esc(v) + '</textarea>';
        else if (dates[k]) el = '<input type="date" data-k="' + k + '" value="' + esc(isoDate(v)) + '">';
        else if (k === 'gender') { var g = genderOf(v); el = '<select data-k="gender"><option value="">— ' + T('Not set') + '</option><option value="' + esc(T('Male')) + '"' + (g === 'm' ? ' selected' : '') + '>' + T('Male') + '</option><option value="' + esc(T('Female')) + '"' + (g === 'f' ? ' selected' : '') + '>' + T('Female') + '</option></select>'; }
        else if (k === 'marital') { var ms = PK('marital').split('\n').map(function (x) { return x.trim(); }).filter(Boolean); if (!ms.length) ms = [T('Single'), T('Married'), T('Divorced'), T('Widowed')]; if (v && ms.indexOf(v) === -1) ms.push(v); // choices: Cases → Settings / business profile
          el = '<select data-k="marital"><option value="">— ' + T('Not set') + '</option>' + ms.map(function (o) { return '<option' + (o === v ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') + '</select>'; }
        else if (k === 'country') el = '<input data-k="country" list="cd-countries" value="' + esc(v) + '" dir="auto" placeholder="' + esc(T('Choose or type')) + '">' + countryList('cd-countries');
        else if (k === 'age') el = '<input type="number" min="0" max="120" data-k="age" value="' + esc(String(v).replace(/[^0-9]/g, '')) + '">';
        else el = '<input data-k="' + k + '" value="' + esc(v) + '" dir="auto">';
        return '<label class="cd2-f' + (long[k] ? ' wide' : '') + '"><span>' + esc(f[1]) + '</span>' + el + '</label>'; };
      var noteTxt = function (s) { return String(s || '').replace(/^Case opened from a private chat: /, T('Case opened from a private chat') + ': ').replace(/^From the private chat: /, T('From the private chat') + ': '); };
      var msgs = r.messages || [], nPriv = msgs.filter(function (m) { return m.src !== 'group'; }).length;
      var stepIdx = Math.max(0, keys.indexOf(st));
      var h = '<a href="?p=cases" class="link">← ' + T('All cases') + '</a>'
        + '<section class="card cd2-head st-' + esc(st) + '"><div class="cd2-who">' + avatar(ph + '@s.whatsapp.net', c.name || ph) + '<div><h1 dir="auto">' + (c.no ? '<span class="cs-id">ID ' + c.no + '</span> ' : '') + esc(c.name || ('+' + ph)) + '</h1>'
        + '<div class="cd2-meta"><span dir="ltr">📞 +' + esc(ph) + '</span><span>💬 ' + fmt(c.msgs || msgs.length) + ' ' + T('messages') + '</span>' + (c.first ? '<span>📅 ' + esc(day(c.first)) + (c.last ? ' → ' + esc(day(c.last)) : '') + '</span>' : '') + (c.manual ? '<span>📝 ' + T('From the form') + '</span>' : '') + '</div></div></div>'
        + '<div class="cd2-acts"><button class="btn primary sm" id="cd-ai">🤖 ' + T(ai ? 'Analyse again' : 'Analyse with AI') + '</button><a class="btn ghost sm" href="?p=inbox&jid=' + encodeURIComponent(ph + '@s.whatsapp.net') + '">💬 ' + T('Private chat') + '</a>'
        + '<div class="tool-menu cd2-more"><button type="button" class="btn ghost sm" id="cd-more">⋯</button><div class="menu" hidden><a href="?api=case_export&phone=' + encodeURIComponent(ph) + '">⬇️ ' + T('Export record') + '</a><button type="button" class="link-btn" id="cd-team" title="' + esc(T('Admin / therapist / team member — not a patient')) + '">👤 ' + T('Not a case (team)') + '</button></div></div></div>'
        // the stages as a clickable progress line
        + '<div class="cd2-steps">' + keys.map(function (k, i) { return '<button type="button" class="cd2-step' + (i < stepIdx ? ' done' : '') + (k === st ? ' on' : '') + '" data-st="' + esc(k) + '"><i>' + (i < stepIdx ? '✓' : i + 1) + '</i><span>' + esc(T(r.statuses[k])) + '</span></button>'; }).join('') + '</div></section>';
      if (r.registry && r.registry.length) h += '<details class="card cd2-reg"><summary>📋 ' + T('Registry reports') + ' <b>' + fmt(r.registry.length) + '</b></summary>' + r.registry.map(regEntry).join('') + '</details>';
      h += '<div class="cd2-grid"><div class="cd2-main">'
        + '<section class="card cd2-card"><div class="cd2-h"><h3>📋 ' + T('Case data') + '</h3><span class="muted small" id="cd-msg">⚡ ' + T('Saved automatically') + '</span></div>'
        + (shortF.length ? '<div class="cd2-short">' + shortF.map(inp).join('') + '</div>' : '') + '<div class="cd2-long">' + longF.map(inp).join('') + '</div></section>'
        + ((c.voices || []).length ? '<section class="card cd2-card"><div class="cd2-h"><h3>🎙️ ' + T('Voice recordings') + '</h3><span class="muted small">' + fmt(c.voices.length) + '</span></div>'
          + c.voices.map(function (v) { var p = String(v.file || '').split('/'); return '<div class="cd2-v"><audio controls preload="none" src="?api=media&a=' + encodeURIComponent(p[1] || '') + '&f=' + encodeURIComponent(p[2] || '') + '"></audio><small class="muted">' + esc(v.by || '') + (v.at ? ' · ' + esc(when(v.at)) : '') + '</small></div>'; }).join('') + '</section>' : '')
        + '<section class="card cd2-card"><div class="cd2-h"><h3>📝 ' + T('Follow-up notes') + '</h3><span class="muted small">' + fmt((c.notes || []).length) + '</span></div>'
        + '<div class="cd2-add"><textarea id="cd-note" rows="1" placeholder="' + esc(T('Add a follow-up note…')) + '" dir="auto"></textarea><button class="btn primary sm" id="cd-add">➕ ' + T('Add') + '</button></div>'
        + '<div class="cd2-tl">' + ((c.notes || []).slice().reverse().map(function (n) { var bot = n.by === 'bot'; return '<div class="cd2-n' + (bot ? ' bot' : '') + '"><i>' + (bot ? '🤖' : '👤') + '</i><div><div dir="auto">' + esc(noteTxt(n.text)) + '</div><small class="muted">' + esc(bot ? T('Bot') : n.by) + ' · ' + esc(when(n.at)) + '</small></div></div>'; }).join('') || '<div class="muted small">' + T('No notes yet.') + '</div>') + '</div></section></div>'
        + '<aside class="cd2-side"><section class="card cd2-card cd2-ai"><div class="cd2-h"><h3>🤖 ' + T('AI analysis') + '</h3>' + (ai && ai.at ? '<span class="muted small">' + esc(when(ai.at)) + '</span>' : '') + '</div>'
        + (ai ? aiBox(ai) : '<div class="cd2-empty"><span>🤖</span><b>' + T('Not analysed yet') + '</b><small>' + T('The AI reads all the conversations and writes a summary, the complaint, the symptoms and the progress.') + '</small><button class="btn primary sm" id="cd-ai2">' + T('Analyse with AI') + '</button></div>') + '</section></aside></div>';
      h += '<section class="card cd2-card"><div class="cd2-h"><h3>💬 ' + T('All conversations') + ' <span class="muted small">' + fmt(msgs.length) + (r.snapshot_at ? ' · ' + T('copy saved in the record') + ' ' + esc(when(r.snapshot_at)) : '') + '</span></h3>'
        + '<div class="row wrap"><div class="seg sm" id="cd-mf"><button data-f="" class="on">' + T('All') + ' <i>' + fmt(msgs.length) + '</i></button><button data-f="p">🔒 ' + T('Private chat') + ' <i>' + fmt(nPriv) + '</i></button><button data-f="g">👥 ' + T('Groups') + ' <i>' + fmt(msgs.length - nPriv) + '</i></button></div><div class="search-xl"><span>🔎</span><input type="search" id="cd-mq" placeholder="' + esc(T('Search the conversations…')) + '" dir="auto"></div></div></div>'
        + '<div class="cd-msgs cd2-msgs" id="cd-msgs"></div></section>';
      root.innerHTML = h;
      // conversations: filter by place and text
      var mf = '', mq = '', mbox = document.getElementById('cd-msgs');
      function drawMsgs() { var L = msgs.slice(-800).filter(function (m) { return (!mf || (mf === 'g') === (m.src === 'group')) && (!mq || String(m.text || '').toLowerCase().indexOf(mq) !== -1); });
        mbox.innerHTML = L.map(function (m) { var wl = { own: '', reply: '↩️ ' + T('reply to them'), mention: '@ ' + T('mentions them'), private: '' }[m.why || 'own'] || '', out = m.dir === 'out' || (m.why && m.why !== 'own' && m.why !== 'private');
          return '<div class="cd-m ' + (out ? 'out' : 'in') + '"><small class="muted">' + (m.src === 'group' ? '👥 ' + esc(m.chat) : '🔒 ' + T('Private chat')) + ' · ' + esc(when(m.time)) + (m.why && m.why !== 'own' && m.why !== 'private' ? ' · ' + esc(m.from_name || ('+' + (m.from || ''))) + ' · ' + wl : '') + (m.dir === 'out' && m.by ? ' · ' + esc(m.by) : '') + '</small><div dir="auto">' + (String(m.text || '').trim() ? esc(m.text) : '<i>[' + esc(m.kind || '') + ']</i>') + '</div></div>'; }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>';
        mbox.scrollTop = mbox.scrollHeight; }
      drawMsgs();
      document.getElementById('cd-mf').onclick = function (e) { var b = e.target.closest('[data-f]'); if (!b) return; mf = b.dataset.f; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); drawMsgs(); };
      document.getElementById('cd-mq').oninput = function () { mq = this.value.trim().toLowerCase(); drawMsgs(); };
      function save(extra) { var data = {}; [].forEach.call(root.querySelectorAll('[data-k]'), function (i) { data[i.dataset.k] = i.value; }); return post('case_set', Object.assign({ phone: ph, data: data }, extra || {})); }
      // case data: saved by itself a moment after a change
      var tmr = null, msg = document.getElementById('cd-msg');
      var onEdit = function (e) { if (!e.target.dataset.k) return; clearTimeout(tmr); msg.textContent = T('Saving…');
        tmr = setTimeout(function () { save({}).then(function (x) { msg.textContent = x.ok ? '✓ ' + T('Saved automatically') + ' · ' + new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : '⚠️ ' + x.error; }); }, 800); };
      root.querySelector('.cd2-main').addEventListener('input', onEdit); root.querySelector('.cd2-main').addEventListener('change', onEdit); // selects and date pickers send "change"
      root.querySelector('.cd2-steps').onclick = function (e) { var b = e.target.closest('[data-st]'); if (!b || b.dataset.st === st) return;
        save({ status: b.dataset.st }).then(function (x) { if (x.ok) { toast(x.snapshot != null ? '✓ ' + T('Under follow-up: all conversations copied to the record') + ' (' + fmt(x.snapshot) + ')' : '✓ ' + T('Saved')); caseDetail(ph); } else toast('⚠️ ' + x.error); }); };
      var note = document.getElementById('cd-note'); note.oninput = function () { this.style.height = 'auto'; this.style.height = Math.min(160, this.scrollHeight) + 'px'; };
      note.onkeydown = function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) document.getElementById('cd-add').click(); };
      document.getElementById('cd-add').onclick = function () { var n = note.value.trim(); if (!n) { note.focus(); return; } this.disabled = true; save({ note: n }).then(function () { caseDetail(ph); }); };
      document.getElementById('cd-more').onclick = function () { var m = this.parentNode.querySelector('.menu'); m.hidden = !m.hidden; };
      document.getElementById('cd-team').onclick = function () { if (!confirm(T('Mark this number as a team member? It is removed from cases and never added automatically again (a copy is kept).'))) return; post('case_team', { phone: ph }).then(function (x) { if (x.ok) location.href = '?p=cases'; else toast('⚠️ ' + x.error); }); };
      var runAi = function () { var b = document.getElementById('cd-ai'); b.disabled = true; b.textContent = '⏳ ' + T('AI is analysing…'); post('case_analyze', { phone: ph }).then(function (x) { if (x.ok) caseDetail(ph); else { b.disabled = false; b.textContent = '🤖 ' + T('Analyse with AI'); toast('⚠️ ' + (x.error || T('Failed'))); } }); };
      document.getElementById('cd-ai').onclick = runAi; if (document.getElementById('cd-ai2')) document.getElementById('cd-ai2').onclick = runAi;
    });
  }
  function regEntry(e) {
    var a = e.ai && !e.ai.skipped ? e.ai : null;
    return '<div class="reg-e"><div class="reg-h"><b dir="auto">' + esc((a && a.name) || e.name_guess || T('Unnamed')) + '</b> ' + e.phones.map(function (p) { return '<span class="tag" dir="ltr">+' + esc(p) + '</span>'; }).join(' ')
      + ' <small class="muted">' + esc(when(e.time)) + ' · 👥 ' + esc(e.group_name || '') + (e.poster_name ? ' · ✍️ ' + esc(e.poster_name) : '') + '</small></div>'
      + (a ? '<div class="reg-ai"><span>🩺 <b>' + T('Diagnosis') + ':</b> <span dir="auto">' + esc(a.diagnosis || '—') + '</span></span>' + (a.complaint ? '<span>🗣️ <span dir="auto">' + esc(a.complaint) + '</span></span>' : '') + ([a.gender, a.age, a.country].filter(Boolean).length ? '<span>👤 ' + esc([a.gender, a.age, a.country].filter(Boolean).join(' · ')) + '</span>' : '') + '</div>' : '')
      + '<div class="reg-t" dir="auto">' + esc(String(e.text || '').replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim()) + '</div><div class="reg-items">' + e.items.map(itemHtml).join('') + '</div>'
      + '<div class="reg-l">' + (e.labels || []).map(function (l) { return '<span class="lbl">🏷️ ' + esc(l) + '</span>'; }).join('') + '</div></div>';
  }
  function registry() {
    var RG = { list: [], sel: {}, q: '', label: '', only: '' };
    root.innerHTML = hero('📋', 'Case registry', 'Every case posted in your case-registry groups (name, number, voice note, information, diagnosis) — one entry per case, with labels. Select one or many to follow them.',
      '<div class="search-xl"><span>🔎</span><input type="search" id="rg-q" placeholder="' + T('Search name, number, diagnosis…') + '" dir="auto"></div><button class="chip-btn" id="rg-scan">🔄 ' + T('Read the group again') + '</button><button class="chip-btn" id="rg-ai">🤖 ' + T('Extract details with AI') + '</button><span class="muted small" id="rg-ai-st"></span>')
      + '<div class="page-tabs sub"><a href="?p=cases">' + (((window.WI_PACK || {}).case_cfg || {}).icon || '🩺') + ' ' + (((window.WI_PACK || {}).case_cfg || {}).many || T('Followed cases')) + '</a><a href="?p=cases&tab=registry" class="on">📋 ' + T('Case registry') + '</a></div>'
      + '<div class="card"><div id="rg-groups" class="muted small"></div><div id="rg-fwrap"></div><div class="rg-labels" id="rg-labels"></div>'
      + '<div class="rg-bar"><label class="check"><input type="checkbox" id="rg-all"> ' + T('Select all shown') + '</label><b id="rg-n"></b><button class="btn primary sm" id="rg-follow" disabled>🩺 ' + T('Follow selected') + '</button><button class="chip-btn sm" id="rg-lbl" disabled>🏷️ ' + T('Add label') + '</button><button class="chip-btn sm" id="rg-unlbl" disabled>✂️ ' + T('Remove label') + '</button><button class="chip-btn sm" id="rg-ai-sel" disabled>🤖 ' + T('Re-analyse selected') + '</button>'
      + '<div class="seg sm" id="rg-only"><button data-o="" class="on">' + T('All') + '</button><button data-o="new">' + T('Not followed') + '</button><button data-o="f">🩺 ' + T('Followed') + '</button></div></div><div id="rg-list" class="rg-list"><div class="empty">' + T('Loading…') + '</div></div></div>';
    function load(scan) {
      get('registry', scan ? { scan: 1 } : {}).then(function (r) {
        if (!r.ok) return fail(document.getElementById('rg-list'), r);
        RG.list = r.entries; RG.labels = r.labels;
        if (!document.getElementById('rg-f')) { var gm = {}; r.entries.forEach(function (e) { gm[e.group] = e.group_name || e.group; }); document.getElementById('rg-fwrap').innerHTML = fbar('rg-f', { sort: [['new', 'Newest first'], ['old', 'Oldest first'], ['name', 'Name'], ['number', 'Number']], groups: gm, dates: true }); fwire('rg-f', draw); }
        var g = Object.keys(r.groups || {});
        document.getElementById('rg-groups').innerHTML = g.length ? '📋 ' + T('Registry groups') + ': ' + g.map(function (k) { return '<b>' + esc(r.groups[k].name || k) + '</b>'; }).join(' · ') + ' · ' + fmt(r.entries.length) + ' ' + T('cases') + (r.pending_ai ? ' · 🤖 ' + fmt(r.pending_ai) + ' ' + T('not analysed yet') : '')
          : '⚠️ ' + T('No registry group yet.') + ' <a href="?p=groups">' + T('Open the group in Groups and switch on “Case registry group”.') + '</a>';
        drawL(); draw();
      });
    }
    function drawL() {
      var ks = Object.keys(RG.labels || {});
      document.getElementById('rg-labels').innerHTML = ks.length ? '<button class="lbl' + (RG.label ? '' : ' on') + '" data-l="">' + T('All labels') + '</button>' + ks.map(function (k) { return '<button class="lbl' + (RG.label === k ? ' on' : '') + '" data-l="' + esc(k) + '">🏷️ ' + esc(k) + ' <i>' + fmt(RG.labels[k]) + '</i></button>'; }).join('') : '';
    }
    function shown() {
      var q = RG.q.toLowerCase();
      var f = fval('rg-f'), srt = f.sort || 'new';
      var nm = function (e) { return String((e.ai && e.ai.name) || e.name_guess || '~'); };
      return RG.list.filter(function (e) { return (!f.group || e.group === f.group) && inRange(e.time, f.from, f.to) && (!RG.label || (e.labels || []).indexOf(RG.label) !== -1) && (!RG.only || (RG.only === 'f' ? e.case : !e.case)) && (!q || JSON.stringify([e.text, e.phones, e.ai, e.name_guess, e.labels]).toLowerCase().indexOf(q) !== -1); })
        .sort(function (a, b) { return srt === 'old' ? String(a.time).localeCompare(String(b.time)) : (srt === 'name' ? nm(a).localeCompare(nm(b)) : (srt === 'number' ? String(a.phones[0]).localeCompare(String(b.phones[0])) : String(b.time).localeCompare(String(a.time)))); });
    }
    function bar() { var n = Object.keys(RG.sel).length; document.getElementById('rg-n').textContent = n ? fmt(n) + ' ' + T('selected') : ''; ['rg-follow', 'rg-lbl', 'rg-unlbl', 'rg-ai-sel'].forEach(function (id) { document.getElementById(id).disabled = !n; }); }
    function draw() {
      var P = pg('registry', shown(), draw, 30);
      document.getElementById('rg-list').innerHTML = P.html + P.rows.map(function (e) {
        var a = e.ai && !e.ai.skipped ? e.ai : null;
        return '<div class="rg-row rg-card' + (RG.sel[e.id] ? ' picked' : '') + '" data-open="' + esc(e.case || '') + '" data-oid="' + esc(e.id) + '" title="' + T('Open the case record') + '"><label class="check"><input type="checkbox" data-pick="' + esc(e.id) + '" ' + (RG.sel[e.id] ? 'checked' : '') + '></label><div class="rg-b">' + regEntry(e)
          + (e.case ? '<a class="chip-btn sm on" href="?p=cases&phone=' + encodeURIComponent(e.case) + '">🩺 ' + T('Case page') + (e.case_status ? ' · ' + T((window.WF_ST || {})[e.case_status] || e.case_status) : '') + '</a>' : '<button class="chip-btn sm" data-follow1="' + esc(e.id) + '">🩺 ' + T('Follow') + '</button>') + '</div></div>';
      }).join('') || '<div class="empty">' + T('No cases yet.') + '</div>';
      document.getElementById('rg-list').insertAdjacentHTML('beforeend', P.html);
      bar();
    }
    function act(body, msg) { body.ids = Object.keys(RG.sel); return post('registry_set', body).then(function (x) { if (!x.ok) { toast('⚠️ ' + x.error); return x; } toast('✓ ' + (msg || T('Saved'))); RG.sel = {}; load(false); return x; }); }
    root.addEventListener('change', function (e) {
      var c = e.target.closest('[data-pick]'); if (c) { if (c.checked) RG.sel[c.dataset.pick] = 1; else delete RG.sel[c.dataset.pick]; c.closest('.rg-row').classList.toggle('picked', c.checked); bar(); return; }
      if (e.target.id === 'rg-all') { shown().forEach(function (x) { if (e.target.checked) RG.sel[x.id] = 1; else delete RG.sel[x.id]; }); draw(); }
    });
    root.addEventListener('click', function (e) {
      var l = e.target.closest('[data-l]'); if (l) { RG.label = l.dataset.l; drawL(); draw(); return; }
      var o = e.target.closest('#rg-only [data-o]'); if (o) { RG.only = o.dataset.o; [].forEach.call(o.parentNode.children, function (x) { x.classList.toggle('on', x === o); }); draw(); return; }
      var card = e.target.closest('.rg-card');
      if (card && !e.target.closest('input,button,a,label,[data-pick],audio,video,select,textarea')) {
        if (card.dataset.open) { location.href = '?p=cases&phone=' + encodeURIComponent(card.dataset.open); return; }
        var fb = card.querySelector('[data-follow1]'); if (fb) fb.click(); return;
      }
      var f1 = e.target.closest('[data-follow1]'); if (f1) { RG.sel = {}; RG.sel[f1.dataset.follow1] = 1; f1.disabled = true; f1.textContent = '⏳'; act({ follow: 1 }, T('Followed')).then(function (x) { if (x.ok && x.cases && x.cases[0]) location.href = '?p=cases&phone=' + encodeURIComponent(x.cases[0]); }); }
    });
    document.getElementById('rg-q').oninput = function () { RG.q = this.value; pgReset('registry'); draw(); };
    document.getElementById('rg-scan').onclick = function () { this.disabled = true; load(true); };
    document.getElementById('rg-follow').onclick = function () { if (confirm(T('Follow the selected cases? A case page is made for each number, with all their messages from every group and their private chat.'))) act({ follow: 1 }, T('Followed')); };
    document.getElementById('rg-lbl').onclick = function () { askForm('🏷️ ' + T('Add a label to the selected cases'), [{ key: 'v', label: T('Label'), placeholder: 'عين، سحر، متابعة أسبوعية' }], T('Add')).then(function (f) { if (f && f.v.trim()) act({ add_label: f.v.trim() }); }); };
    document.getElementById('rg-unlbl').onclick = function () { askForm('🏷️ ' + T('Remove a label from the selected cases'), [{ key: 'v', label: T('Label'), value: RG.label || '' }], T('Remove')).then(function (f) { if (f && f.v.trim()) act({ remove_label: f.v.trim() }); }); };
    function aiLoop(ids) {
      var st = document.getElementById('rg-ai-st'), btn = document.getElementById('rg-ai'); btn.disabled = true;
      (function step() {
        st.textContent = T('AI is reading the reports…'); if (window.WI_AI_POLL) window.WI_AI_POLL();
        post('registry_ai', ids ? { ids: ids } : {}).then(function (r) {
          if (!r.ok) { btn.disabled = false; st.textContent = '⚠️ ' + (r.error || T('Failed')); return; }
          st.textContent = '✓ ' + fmt(r.total - r.remaining) + ' / ' + fmt(r.total) + (r.task ? ' · ' + r.task.model + ' · ' + fmt(r.task.tokens_in + r.task.tokens_out) + ' ' + T('tokens') : '');
          if (r.remaining > 0 && !ids) setTimeout(step, 1200); else { btn.disabled = false; load(false); }
        });
      })();
    }
    document.getElementById('rg-ai').onclick = function () { aiLoop(null); };
    document.getElementById('rg-ai-sel').onclick = function () { aiLoop(Object.keys(RG.sel)); };
    load(false);
  }
  function aiBox(ai) {
    var sev = Number(ai.severity || 0);
    return '<div class="ai-an"><p dir="auto">' + esc(ai.summary || '') + '</p>'
      + (ai.complaint ? '<p><b>' + T('Complaint') + ':</b> <span dir="auto">' + esc(ai.complaint) + '</span></p>' : '')
      + (ai.symptoms && ai.symptoms.length ? '<p><b>' + T('Symptoms') + ':</b> ' + ai.symptoms.map(function (s) { return '<span class="tag" dir="auto">' + esc(s) + '</span>'; }).join(' ') + '</p>' : '')
      + (ai.suspected && ai.suspected.length ? '<p><b>' + T('Indications') + ':</b></p><ul>' + ai.suspected.map(function (s) { return '<li dir="auto"><b>' + esc(s.type) + '</b> (' + esc(s.confidence) + ') — ' + esc(s.why) + '</li>'; }).join('') + '</ul>' : '')
      + '<p>' + (sev ? '<b>' + T('Severity') + ':</b> ' + '●'.repeat(sev) + '○'.repeat(Math.max(0, 5 - sev)) + ' · ' : '') + (ai.progress ? '<b>' + T('Progress') + ':</b> ' + esc(ai.progress) : '') + '</p>'
      + (ai.recommended ? '<p><b>' + T('Suggested') + ':</b> <span dir="auto">' + esc(ai.recommended) + '</span></p>' : '')
      + (ai.next_step ? '<p><b>' + T('Next step') + ':</b> <span dir="auto">' + esc(ai.next_step) + '</span></p>' : '')
      + (ai.red_flags ? '<p class="bad-t"><b>⚠️ ' + T('Needs attention') + ':</b> <span dir="auto">' + esc(ai.red_flags) + '</span></p>' : '')
      + (ai.timeline && ai.timeline.length ? '<details><summary>' + T('Timeline') + '</summary><ul>' + ai.timeline.map(function (t) { return '<li dir="auto">' + esc(t.date) + ' — ' + esc(t.event) + '</li>'; }).join('') + '</ul></details>' : '')
      + '<small class="muted">' + esc(when(ai.at)) + ' · ' + esc(ai.model || '') + ' · ' + T('AI notes are a help for the team, not a medical diagnosis.') + '</small></div>';
  }

  /* ===================== Groups ===================== */
  if (page === 'groups') {
    var G = { list: [], q: '', sort: 'activity', view: null, tab: 'people', pq: '', who: '', mode: viewGet('groups', 'icons') };
    var gj = new URLSearchParams(location.search).get('g');
    function shell() {
      root.innerHTML = hero('👥', 'Groups', 'Open a group, pick the person you want to follow, and get a full record of their messages, the replies to them and their diagnosis — kept separate from everyone else.',
        '<div class="search-xl"><span>🔎</span><input type="search" id="gr-q" placeholder="' + T('Search groups…') + '" dir="auto"></div><div class="seg" id="gr-sort" hidden></div><button class="chip-btn" id="gr-ref">🔄 ' + T('Refresh') + '</button>' + viewSwitch('groups', G.mode))
        + fbar('gr-f', { sort: [['activity', 'Last activity (newest)'], ['old', 'Last activity (oldest)'], ['messages', 'Most messages'], ['members', 'Most members'], ['name', 'Name']], dates: true, extra: '<label><select data-f="kind"><option value="">' + T('All groups') + '</option><option value="mon">🩺 ' + T('Case groups') + '</option><option value="admin">⭐ ' + T('I am admin') + '</option><option value="quiet">💤 ' + T('No activity') + '</option><option value="left">⟲ ' + T('Groups I left') + '</option><option value="current">✅ ' + T('Groups I am in') + '</option></select></label>' })
        + '<div id="gr-grid" class="gr-grid"><div class="empty">' + T('Loading…') + '</div></div>';
      document.getElementById('gr-q').oninput = function () { G.q = this.value; pgReset('groups'); drawGrid(); };
      document.getElementById('gr-sort').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; G.sort = b.dataset.s; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); drawGrid(); };
      document.getElementById('gr-ref').onclick = function () { loadGrid(true); };
      fwire('gr-f', drawGrid);
      root.querySelector('[data-vk="groups"]').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; G.mode = b.dataset.v; viewSet('groups', G.mode); [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); drawGrid(); };
    }
    function loadGrid(ref) { get('groups', ref ? { refresh: 1 } : {}).then(function (r) { if (!r.ok) return fail(document.getElementById('gr-grid'), r); G.list = r.groups; drawGrid(); }); }
    function drawGrid() {
      var q = G.q.toLowerCase(), f = fval('gr-f'), srt = f.sort || 'activity';
      var rows = G.list.filter(function (g) { return (!q || String(g.name).toLowerCase().indexOf(q) !== -1) && ((!f.from && !f.to) || inRange(g.last, f.from, f.to)) && (!f.kind || (f.kind === 'mon' ? g.monitored : (f.kind === 'admin' ? g.i_am_admin : (f.kind === 'left' ? g.left : (f.kind === 'current' ? !g.left : !g.messages))))); });
      rows.sort(function (a, b) { return srt === 'members' ? b.members - a.members : (srt === 'name' ? String(a.name).localeCompare(String(b.name)) : (srt === 'messages' ? b.messages - a.messages : (srt === 'old' ? String(a.last || '9').localeCompare(String(b.last || '9')) : String(b.last || '').localeCompare(String(a.last || ''))))); });
      var gridEl = document.getElementById('gr-grid'); gridEl.className = G.mode === 'list' ? 'gr-listv' : 'gr-grid';
      var P = pg('groups', rows, drawGrid, 48); rows = P.rows;
      var pagerEl = document.getElementById('gr-pager'); if (!pagerEl) { pagerEl = document.createElement('div'); pagerEl.id = 'gr-pager'; gridEl.parentNode.insertBefore(pagerEl, gridEl.nextSibling); } pagerEl.innerHTML = P.html;
      if (G.mode === 'list') {
        gridEl.innerHTML = '<table class="tbl"><thead><tr><th>' + T('Group') + '</th><th>' + T('Members') + '</th><th>' + T('Messages (2 months)') + '</th><th>' + T('Last activity') + '</th><th></th></tr></thead><tbody>' + rows.map(function (g) {
          return '<tr class="gr-tr"><td><a class="gr-n" href="?p=groups&g=' + encodeURIComponent(g.jid) + '">' + avatar(g.jid, g.name) + '<b dir="auto">' + esc(g.name || g.jid) + '</b></a></td><td>' + fmt(g.members) + (g.left ? ' <span class="tag left">⟲ ' + T('Left') + '</span>' : '') + '</td><td>' + fmt(g.messages) + '</td><td>' + (g.last ? esc(day(g.last)) : '—') + '</td><td>' + (g.monitored ? '<span class="tag mon">🩺</span>' : '') + (g.i_am_admin ? ' <span class="tag">' + T('Admin') + '</span>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>';
        if (!rows.length) gridEl.innerHTML = '<div class="empty">' + T('Nothing found.') + '</div>';
        return;
      }
      gridEl.innerHTML = rows.map(function (g) {
        return '<a class="gr-card' + (g.monitored ? ' mon' : '') + '" href="?p=groups&g=' + encodeURIComponent(g.jid) + '">' + avatar(g.jid, g.name)
          + '<div class="gr-cb"><b dir="auto">' + esc(g.name || g.jid) + '</b><div class="gr-stats"><span>👤 ' + (g.left ? '—' : fmt(g.members)) + '</span><span>💬 ' + fmt(g.messages) + '</span><span>🕐 ' + (g.last ? esc(day(g.last)) : '—') + '</span></div>'
          + '<div class="gr-tags">' + (g.left ? '<span class="tag left">⟲ ' + T('You left — archive only') + '</span>' : '') + (g.monitored ? '<span class="tag mon">🩺 ' + T('Case group') + '</span>' : '') + (g.i_am_admin ? '<span class="tag">' + T('Admin') + '</span>' : '') + (g.announce ? '<span class="tag">🔒 ' + T('Admins only post') + '</span>' : '') + '</div></div></a>';
      }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>';
    }
    /* ---- one group ---- */
    function openGroup(jid) {
      root.innerHTML = '<div class="empty">' + T('Loading…') + '</div>';
      get('group_view', { jid: jid }).then(function (r) {
        if (!r.ok) return fail(root, r);
        G.view = r;
        var tracked = r.members.filter(function (m) { return m.case; }).length, writers = r.members.filter(function (m) { return m.messages; }).length;
        root.innerHTML = '<a href="?p=groups" class="link">← ' + T('All groups') + '</a>'
          + '<section class="hero gv-hero"><div class="hero-t">' + avatar(jid, r.name) + '<div><h1 dir="auto">' + esc(r.name) + '</h1><p class="muted">👤 ' + fmt(r.members.filter(function (m) { return m.member; }).length) + ' ' + T('members') + ' · ✍️ ' + fmt(writers) + ' ' + T('wrote') + ' · 🩺 ' + fmt(tracked) + ' ' + T('followed') + ' · 💬 ' + fmt(r.total) + '</p></div></div>'
          + '<div class="hero-tools"><a class="chip-btn" href="?p=inbox#' + encodeURIComponent(jid) + '">💬 ' + T('Open chat') + '</a>' + (isAdmin ? '<label class="chip-btn"><input type="checkbox" id="gv-reg" ' + (r.registry ? 'checked' : '') + '> 📋 ' + T('Case registry group') + '</label><label class="chip-btn"><input type="checkbox" id="gv-mon" ' + (r.monitored ? 'checked' : '') + '> 🩺 ' + T('Follow everyone who writes here') + '</label>' : '') + (r.registry ? '<a class="chip-btn" href="?p=cases&tab=registry">📋 ' + T('Open the registry') + '</a>' : '') + '</div></section>'
          + '<div class="gv-help card">① ' + T('Pick a person from the list (or from a message).') + ' ② ' + T('Press 🩺 Follow — a case page is created for that number.') + ' ③ ' + T('Their messages, replies to them and their private chat are collected there, with AI diagnosis.') + '</div>'
          + '<div class="gv"><div class="card gv-people"><div class="gv-ph"><b>' + T('People') + '</b><input type="search" id="gv-pq" placeholder="' + T('Name or number…') + '" dir="auto"><div class="seg sm" id="gv-pf"><button data-f="" class="on">' + T('All') + '</button><button data-f="w">' + T('Wrote') + '</button><button data-f="c">🩺</button></div></div><div id="gv-plist" class="gv-plist"></div></div>'
          + '<div class="card gv-msgs"><div class="gv-ph"><b id="gv-mt">' + T('Messages') + '</b><button class="link-btn" id="gv-all" hidden>✕ ' + T('Show everyone') + '</button></div><div id="gv-mlist" class="gv-mlist"></div></div></div>';
        drawPeople(); drawMsgs();
        document.getElementById('gv-pq').oninput = function () { G.pq = this.value; drawPeople(); };
        document.getElementById('gv-pf').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; G.pf = b.dataset.f; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); drawPeople(); };
        document.getElementById('gv-all').onclick = function () { G.who = ''; drawMsgs(); drawPeople(); };
        var reg = document.getElementById('gv-reg');
        if (reg) reg.onchange = function () {
          if (reg.checked && !confirm(T('Is this the group where each case is posted (name, number, voice note, diagnosis)? Every post with a phone number becomes a registry entry.'))) { reg.checked = false; return; }
          reg.disabled = true; post('registry_group', { jid: jid, name: r.name, on: reg.checked ? 1 : 0 }).then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); if (x.ok && reg.checked) location.href = '?p=cases&tab=registry'; else openGroup(jid); });
        };
        var mon = document.getElementById('gv-mon');
        if (mon) mon.onchange = function () {
          if (mon.checked && !confirm(T('Monitor this group as a case group? Every member who writes there gets a case record (all messages are archived and can be analysed).'))) { mon.checked = false; return; }
          post('group_monitor', { jid: jid, name: r.name, on: mon.checked ? 1 : 0 }).then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); if (x.ok) openGroup(jid); });
        };
      });
    }
    function person(m) { return m.name || ('+' + m.phone); }
    function drawPeople() {
      var r = G.view, q = (G.pq || '').toLowerCase();
      var rows = r.members.filter(function (m) { return (!q || (person(m) + ' ' + m.phone).toLowerCase().indexOf(q) !== -1) && (G.pf !== 'w' || m.messages) && (G.pf !== 'c' || m.case); });
      document.getElementById('gv-plist').innerHTML = rows.slice(0, 600).map(function (m) {
        return '<div class="gv-p' + (G.who === m.phone ? ' on' : '') + '" data-who="' + esc(m.phone) + '">' + avatar(m.phone + '@s.whatsapp.net', person(m))
          + '<div class="gv-pb"><b dir="auto">' + esc(person(m)) + '</b><small class="muted" dir="ltr">+' + esc(m.phone) + (m.admin ? ' · ' + T('Admin') : '') + (!m.member ? ' · ' + T('left / not a member') : '') + '</small>'
          + '<small class="muted">💬 ' + fmt(m.messages) + (m.last ? ' · ' + esc(day(m.last)) : '') + '</small></div>'
          + (m.case ? '<a class="chip-btn sm on" href="?p=cases&phone=' + encodeURIComponent(m.case) + '">🩺 ' + T('Case page') + '</a>' : '<button class="chip-btn sm" data-track="' + esc(m.phone) + '">🩺 ' + T('Follow') + '</button>') + '</div>';
      }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>';
    }
    function drawMsgs() {
      var r = G.view, who = G.who, rows = r.messages;
      if (who) {
        var mine = {}; rows.forEach(function (m) { if (m.dir === 'in' && m.from === who) mine[m.id] = 1; });
        rows = rows.filter(function (m) { return (m.dir === 'in' && m.from === who) || (m.quote && mine[m.quote]) || m.quote_from === who; });
      }
      var p = who ? r.members.filter(function (m) { return m.phone === who; })[0] : null;
      document.getElementById('gv-mt').textContent = who ? T('Messages of') + ' ' + (p ? person(p) : '+' + who) + ' (' + fmt(rows.length) + ')' : T('Latest messages');
      document.getElementById('gv-all').hidden = !who;
      var el = document.getElementById('gv-mlist');
      el.innerHTML = rows.slice(-300).map(function (m) {
        var mp = r.members.filter(function (x) { return x.phone === m.from; })[0], nm = m.dir === 'out' ? (m.by || T('You')) : (mp ? person(mp) : (m.from_name || '+' + m.from));
        var link = who && m.from !== who ? '<span class="why">↩️ ' + T('reply to them') + '</span>' : '';
        return '<div class="gv-m ' + (m.dir === 'out' ? 'out' : 'in') + (m.case ? ' tracked' : '') + '"><div class="gv-mh">' + (m.dir === 'in' ? '<button class="gv-who" data-who="' + esc(m.from) + '">' + esc(nm) + '</button>' : '<b>' + esc(nm) + '</b>') + link + '<small class="muted">' + esc(when(m.time)) + '</small>'
          + (m.dir === 'in' && !m.case ? '<button class="link-btn" data-track="' + esc(m.from) + '" title="' + T('Follow this person') + '">🩺</button>' : (m.case ? '<span class="tag mon">🩺</span>' : '')) + '</div>'
          + '<div dir="auto" class="gv-mt">' + (String(m.text || '').trim() ? esc(m.text) : '<i class="muted">[' + esc(m.kind) + ']</i>') + '</div></div>';
      }).join('') || '<div class="empty">' + T('No messages.') + '</div>';
      el.scrollTop = el.scrollHeight;
    }
    root.addEventListener('click', function (e) {
      var w = e.target.closest('[data-who]:not([data-track])');
      if (w && !e.target.closest('[data-track],a')) { G.who = w.dataset.who === G.who ? '' : w.dataset.who; drawPeople(); drawMsgs(); return; }
      var t = e.target.closest('[data-track]'); if (!t) return;
      var r = G.view, ph = t.dataset.track, p = r.members.filter(function (m) { return m.phone === ph; })[0];
      if (!confirm(T('Create a case page for') + ' ' + (p ? person(p) : '+' + ph) + ' (+' + ph + ')?\n' + T('Their messages in this group, replies to them and their private chat will be collected in one record.'))) return;
      t.disabled = true; t.textContent = '⏳';
      post('case_track', { phone: ph, group: r.jid, group_name: r.name, name: p ? p.name : '' }).then(function (x) {
        if (!x.ok) { toast('⚠️ ' + (x.error || T('Failed'))); t.disabled = false; t.textContent = '🩺'; return; }
        location.href = '?p=cases&phone=' + encodeURIComponent(x.phone);
      });
    });
    if (gj) openGroup(gj); else { shell(); loadGrid(false); }
    return;
  }

  /* ===================== Numbers (extract, de-duplicated, WhatsApp format) ===================== */
  if (page === 'numbers') {
    var NB = { rows: [], groups: {}, q: '', sel: {}, mode: viewGet('numbers', 'list') };
    root.innerHTML = hero('📇', 'Numbers', 'Pull every number from the groups and chats — each number once, in WhatsApp format (country code + number, no + or 0), with its name and groups.', '')
      + '<div class="card nb-opts"><div class="nb-src"><b>' + T('From') + ':</b><label class="check"><input type="checkbox" value="members" checked> 👥 ' + T('Group members') + '</label><label class="check"><input type="checkbox" value="chats" checked> 💬 ' + T('Private chats') + '</label><label class="check"><input type="checkbox" value="written" checked> ✍️ ' + T('Numbers written in messages') + '</label></div>'
      + '<details class="nb-gsel"><summary>👥 ' + T('Groups') + ': <b id="nb-gn">' + T('All groups') + '</b></summary><div class="row"><button class="link-btn" id="nb-gall">' + T('All') + '</button><button class="link-btn" id="nb-gnone">' + T('None') + '</button></div><div id="nb-glist" class="nb-glist"></div></details>'
      + '<div class="row wrap"><button class="btn primary" id="nb-go">📇 ' + T('Extract numbers') + '</button><button class="chip-btn" id="nb-ref">🔄 ' + T('Re-read from WhatsApp') + '</button><span class="muted small" id="nb-st"></span></div></div>'
      + '<div class="card"><div class="row wrap nb-bar"><div class="search-xl"><span>🔎</span><input type="search" id="nb-q" placeholder="' + T('Search number, name, group…') + '" dir="auto"></div><b id="nb-n"></b>'
      + '<a class="chip-btn" id="nb-csv" href="#">⬇️ CSV</a><a class="chip-btn" id="nb-txt" href="#">⬇️ TXT</a><a class="chip-btn" id="nb-vcf" href="#">⬇️ ' + T('Contacts (VCF)') + '</a><button class="chip-btn" id="nb-copy">📋 ' + T('Copy numbers') + '</button>' + viewSwitch('numbers', NB.mode) + '</div>'
      + '<div id="nb-fwrap"></div><div id="nb-list"><div class="empty">' + T('Choose the sources, then press “Extract numbers”.') + '</div></div></div>';
    function params(extra) {
      var src = [].map.call(root.querySelectorAll('.nb-src input:checked'), function (i) { return i.value; }).join(',');
      var gs = [].map.call(root.querySelectorAll('.nb-glist input:checked'), function (i) { return i.value; }), all = root.querySelectorAll('.nb-glist input').length;
      return Object.assign({ src: src, groups: gs.length && gs.length < all ? gs.join(',') : '' }, extra || {});
    }
    function links() { ['csv', 'txt', 'vcf'].forEach(function (f) { document.getElementById('nb-' + f).href = '?' + new URLSearchParams(Object.assign({ api: 'numbers', export: f }, params())).toString(); }); }
    function load(ref) {
      var st = document.getElementById('nb-st'); if (!ref) { var gf = document.querySelector('#nb-f [data-f=group]'); if (gf) gf.value = ''; } st.textContent = T('Reading the groups… (the first time can take a minute)');
      get('numbers', params(ref ? { refresh: 1 } : {})).then(function (r) {
        if (!r.ok) { st.textContent = '⚠️ ' + (r.error || T('Failed')); return; }
        NB.rows = r.rows;
        if (!Object.keys(NB.groups).length) { NB.groups = r.groups; document.getElementById('nb-glist').innerHTML = Object.keys(r.groups).map(function (k) { return '<label class="check"><input type="checkbox" value="' + esc(k) + '" checked> <span dir="auto">' + esc(r.groups[k]) + '</span></label>'; }).join(''); }
        if (!document.getElementById('nb-f')) { document.getElementById('nb-fwrap').innerHTML = fbar('nb-f', { sort: [['groups', 'In most groups'], ['number', 'Number'], ['name', 'Name']], groups: r.groups, extra: '<label><select data-f="src"><option value="">' + T('Any source') + '</option><option value="member">👥 ' + T('Group members') + '</option><option value="admin">⭐ ' + T('Group admins') + '</option><option value="chat">💬 ' + T('Private chats') + '</option><option value="written">✍️ ' + T('Numbers written in messages') + '</option><option value="left_group">⟲ ' + T('Wrote in groups I left') + '</option></select></label><label><input type="text" data-f="cc" placeholder="' + T('Country code e.g. 20') + '" size="10" dir="ltr"></label>' }); fwire('nb-f', draw); document.querySelector('#nb-f [data-f=cc]').oninput = draw; }
        var bs = r.by_source || {};
        st.innerHTML = '✓ ' + T('Read at') + ' ' + esc(when(r.cached_at)) + ' · 👥 ' + fmt((bs.member || 0)) + ' · ⭐ ' + fmt(bs.admin || 0) + ' · 💬 ' + fmt(bs.chat || 0) + ' · ✍️ ' + fmt(bs.written || 0) + (bs.left_group ? ' · ⟲ ' + fmt(bs.left_group) : '')
          + (r.lids ? ' · ' + fmt(r.lids) + ' ' + T('members hide their number (WhatsApp privacy) — skipped') : '')
          + (r.failed && r.failed.length ? '<br>⚠️ ' + T('These groups could not be read now (previous list kept)') + ': ' + esc(r.failed.join('، ')) : '');
        draw(); links();
      });
    }
    function shown() {
      var q = NB.q.toLowerCase(), f = fval('nb-f'), gname = f.group ? NB.groups[f.group] : '';
      var rows = NB.rows.filter(function (r) { return (!q || (r.phone + ' ' + r.name + ' ' + r.groups.join(' ')).toLowerCase().indexOf(q) !== -1) && (!gname || r.groups.indexOf(gname) !== -1) && (!f.src || r.sources.indexOf(f.src) !== -1) && (!f.cc || r.phone.indexOf(f.cc.replace(/\D/g, '')) === 0); });
      var srt = f.sort || 'groups';
      return rows.sort(function (a, b) { return srt === 'number' ? a.phone.localeCompare(b.phone) : (srt === 'name' ? String(a.name || '~').localeCompare(String(b.name || '~')) : (b.groups.length - a.groups.length)); });
    }
    function draw() {
      var rows = shown(); document.getElementById('nb-n').textContent = fmt(rows.length) + ' ' + T('numbers (no duplicates)');
      var P = pg('numbers', rows, draw, 100); var pagerHtml = P.html; rows = P.rows;
      var SRC = { member: '👥', admin: '⭐', chat: '💬', written: '✍️', left_group: '⟲' };
      if (NB.mode === 'icons') {
        document.getElementById('nb-list').innerHTML = pagerHtml + '<div class="nb-cards">' + rows.map(function (r) {
          return '<a class="nb-card" href="?p=customer&jid=' + encodeURIComponent(r.phone + '@s.whatsapp.net') + '">' + avatar(r.phone + '@s.whatsapp.net', r.name || r.phone) + '<b dir="auto">' + esc(r.name || '—') + '</b><span dir="ltr">' + esc(r.phone) + '</span><small class="muted" dir="auto">' + esc(r.groups.slice(0, 2).join(' · ')) + (r.groups.length > 2 ? ' +' + (r.groups.length - 2) : '') + '</small><span>' + r.sources.map(function (s) { return SRC[s] || ''; }).join('') + (r.customer ? ' 🧾' : '') + '</span></a>';
        }).join('') + '</div>' + pagerHtml;
        return;
      }
      document.getElementById('nb-list').innerHTML = pagerHtml + '<table class="tbl nb-t"><thead><tr><th>#</th><th>' + T('Number') + '</th><th>' + T('Name') + '</th><th>' + T('Group') + '</th><th></th></tr></thead><tbody>' + rows.map(function (r, i) {
        return '<tr><td class="muted">' + (P.offset + i + 1) + '</td><td dir="ltr"><b>' + esc(r.phone) + '</b></td><td dir="auto">' + esc(r.name) + (r.customer ? ' <span class="tag">' + T('Customer') + '</span>' : '') + '</td><td dir="auto" class="nb-g">' + r.groups.map(function (g) { return '<span class="tag">' + esc(g) + '</span>'; }).join(' ') + '</td><td>' + r.sources.map(function (s) { return '<span title="' + esc(s) + '">' + (SRC[s] || '') + '</span>'; }).join('') + ' <a href="?p=inbox#' + encodeURIComponent(r.phone + '@s.whatsapp.net') + '" title="' + T('Open chat') + '">💬</a></td></tr>';
      }).join('') + '</tbody></table>' + pagerHtml;
    }
    document.getElementById('nb-go').onclick = function () { load(false); };
    document.getElementById('nb-ref').onclick = function () { load(true); };
    document.getElementById('nb-q').oninput = function () { NB.q = this.value; pgReset('numbers'); draw(); };
    document.getElementById('nb-gall').onclick = function () { [].forEach.call(root.querySelectorAll('.nb-glist input'), function (i) { i.checked = true; }); gname(); };
    document.getElementById('nb-gnone').onclick = function () { [].forEach.call(root.querySelectorAll('.nb-glist input'), function (i) { i.checked = false; }); gname(); };
    function gname() { var n = root.querySelectorAll('.nb-glist input:checked').length, a = root.querySelectorAll('.nb-glist input').length; document.getElementById('nb-gn').textContent = n === a ? T('All groups') : fmt(n) + ' / ' + fmt(a); links(); }
    root.addEventListener('change', function (e) { if (e.target.closest('.nb-glist')) gname(); if (e.target.closest('.nb-src')) links(); });
    root.querySelector('[data-vk="numbers"]').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; NB.mode = b.dataset.v; viewSet('numbers', NB.mode); [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); draw(); };
    document.getElementById('nb-copy').onclick = function () { var t = shown().map(function (r) { return r.phone; }).join('\n'); if (navigator.clipboard) navigator.clipboard.writeText(t); toast('✓ ' + T('Copied') + ' ' + fmt(shown().length)); };
    load(false);
    return;
  }

  /* ===================== Bot: learning & test (nothing is sent to anyone) ===================== */
  if (page === 'bot') {
    var BT = { kb: [], tab: new URLSearchParams(location.search).get('tab') || 'learn', hist: [], topic: '', st: '', q: '' };
    root.innerHTML = hero('🧠', 'Bot: learning & test', 'The bot learns from your past replies and the Library. Test it here as long as you like — nothing is sent to anyone, and the bot stays OFF until you switch it on.', '')
      + '<div class="card bt-acc" id="bt-acc"></div><div class="kpis card" id="bt-k"></div>'
      + '<div class="page-tabs sub" id="bt-tabs"><a data-t="learn">① ' + T('Learn from conversations') + '</a><a data-t="kb">② ' + T('Knowledge') + '</a><a data-t="chat">③ ' + T('Test chat') + '</a><a data-t="eval">④ ' + T('Test on real messages') + '</a><a data-t="cfg">⚙️ ' + T('Bot style & model') + '</a><a data-t="run">🚦 ' + T('Switch on / off') + '</a></div><div id="bt-body"></div>';
    function kpis() {
      get('learn_state').then(function (r) {
        if (!r.ok) return; BT.kb = r.kb; BT.state = r;
        var ready = r.status.approved >= 10 && r.ratings.total >= 10 && r.ratings.good / Math.max(1, r.ratings.total) >= 0.8;
        document.getElementById('bt-acc').innerHTML = '📱 ' + T('Bot of the number') + ': <b>' + esc(r.account) + '</b> — ' + T('each number has its own bot, knowledge and audience. Switch the number from the side menu.') + (r.has_profile ? '' : '<br>⚠️ ' + T('This number has no description yet — open ⚙️ Bot style & model and press “Detect from this number’s chats”.'));
        document.getElementById('bt-k').innerHTML = '<div><b>' + fmt(r.pairs) + '</b><span>' + T('Exchanges collected') + '</span></div><div><b>' + fmt(r.distilled) + ' / ' + fmt(r.pairs) + '</b><span>' + T('Learned from') + '</span></div><div><b>' + fmt(r.kb.length) + '</b><span>' + T('Knowledge items') + ' (✅ ' + fmt(r.status.approved) + ')</span></div><div><b>' + (r.ratings.total ? Math.round(r.ratings.good / r.ratings.total * 100) + '%' : '—') + '</b><span>' + T('Good answers in tests') + ' (' + fmt(r.ratings.total) + ')</span></div><div><b>' + ({ off: '⚪ ' + T('OFF'), draft: '📝 ' + T('Suggest only'), auto: '🤖 ' + T('Automatic') }[r.mode] || '⚪ ' + T('OFF')) + '</b><span>' + (ready ? '✅ ' + T('Ready to switch on') : T('Not ready: approve 10+ items and rate 10+ tests with 80% good')) + '</span></div>';
        if (BT.tab === 'kb') drawKb();
      });
    }
    function tab(t) {
      BT.tab = t; [].forEach.call(document.querySelectorAll('#bt-tabs a'), function (a) { a.classList.toggle('on', a.dataset.t === t); });
      var el = document.getElementById('bt-body');
      if (t === 'learn') {
        el.innerHTML = '<div class="card"><h3>① ' + T('Learn from conversations') + '</h3><p class="muted">' + T('Step 1 reads every private chat and keeps each customer message with the reply your team actually sent. Step 2 lets the AI turn them into reusable knowledge (names and numbers removed). You then review it in “Knowledge”.') + '</p>'
          + '<div class="row wrap"><button class="btn primary" id="bt-col">📥 ' + T('Step 1: collect new exchanges') + '</button><button class="link-btn" id="bt-colall" title="' + T('Read all conversations again (keeps what was learned)') + '">↺ ' + T('Re-read everything') + '</button><button class="btn" id="bt-dis">🧠 ' + T('Step 2: learn (AI)') + '</button><span class="muted small" id="bt-msg"></span></div></div>';
        function collect(full) { var m = document.getElementById('bt-msg'); m.textContent = T('Reading conversations…'); post('learn_build', { step: 'collect', full: full ? 1 : 0 }).then(function (r) { m.textContent = r.ok ? '✓ ' + (r.full ? T('Read everything') : T('Only new ones read')) + ' · ➕ ' + fmt(r.new) + ' ' + T('new') + ' · ' + fmt(r.pairs) + ' ' + T('exchanges') + ' · ' + fmt(r.remaining) + ' ' + T('to learn') : '⚠️ ' + r.error; kpis(); }); }
        // 🌙 Everything in the background: read all chats and groups again, then learn all of it (the page can be closed)
        var bg = document.createElement('button'); bg.className = 'btn'; bg.textContent = '🌙 ' + T('Learn everything in the background (all groups)'); document.getElementById('bt-col').parentNode.appendChild(bg);
        bg.onclick = function () { var m = document.getElementById('bt-msg'); bg.disabled = true; post('learn_build', { step: 'background', collect: 1, full: 1 }).then(function (r) { m.textContent = r.ok ? '✓ ' + T('Started in the background: the bot reads every chat and group, then learns all of it. Follow it in AI activity.') : '⚠️ ' + r.error; }); };
        document.getElementById('bt-col').onclick = function () { collect(false); };
        document.getElementById('bt-colall').onclick = function () { collect(true); };
        if (BT.state && BT.state.collected_at) document.getElementById('bt-msg').textContent = '💾 ' + T('Saved') + ': ' + fmt(BT.state.pairs) + ' ' + T('exchanges') + ' · ' + T('last collected') + ' ' + when(BT.state.collected_at);
        var run = false;
        document.getElementById('bt-dis').onclick = function () {
          var b = this, m = document.getElementById('bt-msg'); if (run) { run = false; b.textContent = '🧠 ' + T('Step 2: learn (AI)'); return; }
          run = true; b.textContent = '⏹ ' + T('Stop');
          (function step() { if (!run) return; if (window.WI_AI_POLL) window.WI_AI_POLL();
            post('learn_build', {}).then(function (r) {
              if (!r.ok) { run = false; b.textContent = '🧠 ' + T('Step 2: learn (AI)'); m.textContent = '⚠️ ' + r.error; return; }
              m.textContent = '✓ ' + fmt(r.total - r.remaining) + ' / ' + fmt(r.total) + (r.task ? ' · ' + r.task.model + ' · ' + fmt(r.task.tokens_in + r.task.tokens_out) + ' ' + T('tokens') : ''); kpis();
              if (r.remaining > 0 && run) setTimeout(step, 1500); else { run = false; b.textContent = '🧠 ' + T('Step 2: learn (AI)'); }
            }); })();
        };
      }
      if (t === 'kb') { el.innerHTML = '<div class="card"><div class="row wrap"><div class="search-xl"><span>🔎</span><input type="search" id="kb-q" placeholder="' + T('Search knowledge…') + '" dir="auto"></div><select id="kb-st"><option value="">' + T('All') + '</option><option value="pending">⏳ ' + T('To review') + '</option><option value="approved">✅ ' + T('Approved') + '</option><option value="disabled">⛔ ' + T('Disabled') + '</option></select><select id="kb-tp"><option value="">' + T('All topics') + '</option></select><button class="chip-btn" id="kb-add">➕ ' + T('Add an answer') + '</button></div>'
          + '<div class="kb-tools"><div class="seg sm" id="kb-view"><button data-v="cards" class="on">▦ ' + T('Cards') + '</button><button data-v="table">☰ ' + T('Question / answers table') + '</button></div><button class="chip-btn" id="kb-merge">🧬 ' + T('Merge duplicates') + '</button><label>✅ ' + T('Auto-approve answers given at least') + ' <select id="kb-auto"><option value="0">' + T('Off') + '</option><option value="2">2×</option><option value="3">3×</option><option value="5">5×</option><option value="10">10×</option></select></label><span class="muted small" id="kb-tmsg"></span></div>'
          + '<div class="rg-bar"><label class="check"><input type="checkbox" id="kb-all"> ' + T('Select all shown') + '</label><b id="kb-n"></b><button class="btn sm" data-kbb="approved" disabled>✅ ' + T('Approve') + '</button><button class="chip-btn sm" data-kbb="pending" disabled>⏳ ' + T('Back to review') + '</button><button class="chip-btn sm" data-kbb="disabled" disabled>⛔ ' + T('Disable') + '</button><button class="chip-btn sm" data-kbb="topic" disabled>🏷️ ' + T('Change topic') + '</button><button class="link-btn bad-t" data-kbb="delete" disabled>🗑 ' + T('Delete') + '</button></div>'
          + '<div id="kb-list" class="kb-list"></div></div>';
        BT.sel = {}; BT.kview = 'cards';
        document.getElementById('kb-view').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; BT.kview = b.dataset.v; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); if (BT.kview === 'table') kbTable(); else drawKb(); };
        if (BT.state) document.getElementById('kb-auto').value = String(BT.state.auto_approve || 0);
        document.getElementById('kb-merge').onclick = function () { var m = document.getElementById('kb-tmsg'); m.textContent = T('Working…'); post('kb_bulk', { do: 'merge' }).then(function (x) { m.textContent = x.ok ? '✓ ' + fmt(x.merged) + ' ' + T('duplicates merged') + ' · ' + fmt(x.left) + ' ' + T('items left') : '⚠️ ' + x.error; kpis(); }); };
        document.getElementById('kb-auto').onchange = function () { var m = document.getElementById('kb-tmsg'), v = this.value; post('kb_bulk', { do: 'auto', min: v }).then(function (x) { m.textContent = x.ok ? (v === '0' ? '✓ ' + T('Auto-approve off') : '✓ ' + fmt(x.approved) + ' ' + T('approved now')) : '⚠️ ' + x.error; kpis(); }); };
        document.getElementById('kb-all').onchange = function () { var on = this.checked; kbShown().forEach(function (k) { if (on) BT.sel[k.id] = 1; else delete BT.sel[k.id]; }); drawKb(); };
        el.querySelector('.rg-bar').onclick = function (e) { var b = e.target.closest('[data-kbb]'); if (!b) return; var ids = Object.keys(BT.sel); if (!ids.length) return; var body = { do: b.dataset.kbb, ids: ids };
          if (body.do === 'delete' && !confirm(T('Delete the selected items?'))) return; if (body.do === 'topic') { return askForm(T('New topic'), [{ key: 't', label: T('Topic') }]).then(function (f) { if (!f || !f.t.trim()) return; body.topic = f.t.trim(); kbBulk(body); }); }
          kbBulk(body); };
        function kbBulk(body) { post('kb_bulk', body).then(function (x) { toast(x.ok ? '✓ ' + fmt(x.changed) : '⚠️ ' + x.error); BT.sel = {}; kpis(); }); }
        document.getElementById('kb-q').oninput = function () { BT.q = this.value; pgReset('kb'); pgReset('kbt'); drawKb(); }; document.getElementById('kb-st').onchange = function () { BT.st = this.value; drawKb(); }; document.getElementById('kb-tp').onchange = function () { BT.topic = this.value; drawKb(); };
        document.getElementById('kb-add').onclick = function () { askForm('➕ ' + T('Add a question and its answer'), [{ key: 'q', label: T('Customer question'), placeholder: EX('question') }, { key: 'a', label: T('The answer'), type: 'textarea', rows: 10 }], T('Add')).then(function (f) { if (!f || !f.q.trim() || !f.a.trim()) return; var q = f.q.trim(), a = f.a; post('kb_set', { do: 'add', q: q }).then(function () { var id = BT.kb.length; kpis(); }).then(function () { return get('learn_state'); }).then(function (r) { var k = r.kb.filter(function (x) { return x.q === '' ; })[0]; if (k) post('kb_set', { id: k.id, q: q, a: a, status: 'approved' }).then(kpis); }); }); };
        drawKb(); }
      if (t === 'chat') { BT.hist = []; el.innerHTML = '<div class="card bt-chat"><div class="muted small">🧪 ' + T('Write as if you were a customer. Nothing is sent on WhatsApp.') + ' <button class="link-btn" id="bt-reset">↺ ' + T('New test') + '</button></div><div class="row wrap small">👤 ' + T('Test as') + ' <select id="bt-who"><option value="new">' + T('a new person (first message)') + '</option><option value="existing">' + T('an existing person (in a group, on a program)') + '</option></select> 🔀 ' + T('Compare with') + ' <select id="bt-cmp"><option value="">' + T('— no comparison —') + '</option></select></div><div id="bt-log" class="bt-log"></div><form id="bt-f" class="row"><textarea id="bt-in" rows="2" dir="auto" placeholder="' + T('e.g.') + ' ' + EX('message') + '" style="flex:1"></textarea><button class="btn primary">➤</button></form></div>';
        document.getElementById('bt-reset').onclick = function () { tab('chat'); };
        document.getElementById('bt-f').onsubmit = function (e) { e.preventDefault(); var i = document.getElementById('bt-in'), t = i.value.trim(); if (!t) return; i.value = ''; say('me', t); var wait = say('bot', '…'); if (window.WI_AI_POLL) window.WI_AI_POLL();
          var cmp = document.getElementById('bt-cmp').value, hist = BT.hist.slice(0, -1);
          var who = document.getElementById('bt-who').value;
          post('bot_test', { text: t, history: hist, who: who }).then(function (r) { wait.remove(); if (!r.ok) { say('bot', '⚠️ ' + r.error); return; } say('bot', r.reply, r); });
          if (cmp) { var w2 = say('bot2', '…'); post('bot_test', { text: t, history: hist, prefer: cmp, who: who }).then(function (r) { w2.remove(); var d = say('bot2', r.ok ? r.reply : '⚠️ ' + r.error, r.ok ? r : null); BT.hist.pop(); }); } };
        get('bot_cfg').then(function (r) { if (!r.ok) return; var sel = document.getElementById('bt-cmp'); if (!sel) return; r.models.forEach(function (m) { var o = document.createElement('option'); o.value = m.id; o.textContent = (m.meta ? '🦙 ' : '') + m.label; sel.appendChild(o); }); }); }
      if (t === 'run') { location.href = '?p=botrun'; return; }
      if (t === 'run_old') { el.innerHTML = '<div class="card"><div class="empty">' + T('Loading…') + '</div></div>';
        get('bot_run').then(function (r) { var c = r.run;
          var opt = function (v, l, d) { return '<label class="br-opt' + (c.mode === v ? ' on' : '') + '"><input type="radio" name="br-mode" value="' + v + '" ' + (c.mode === v ? 'checked' : '') + '><b>' + T(l) + '</b><small class="muted">' + T(d) + '</small></label>'; };
          el.innerHTML = '<div class="card br"><h3>🚦 ' + T('Bot of the number') + ': ' + esc(r.account) + '</h3><div class="seg" id="br-sub"><button type="button" data-v="gen" class="on">⚙️ ' + T('General') + '</button><button type="button" data-v="grp">👥 ' + T('Groups bot') + ' <i>' + Object.keys(c.groups || {}).length + '</i></button></div><div class="br-modes">' + opt('off', '⚪ Off', 'The bot does nothing.') + opt('draft', '📝 Suggest only', 'For every new customer message the bot writes a reply draft in the chat; a person reviews and sends it.') + opt('auto', '🤖 Automatic', 'The bot replies by itself when it is confident; anything else stays as a draft for a person.') + '</div>'
            + '<div class="br-f"><label>' + T('Reply automatically only when confidence is at least') + '<select id="br-conf">' + [0.6, 0.7, 0.8, 0.9].map(function (v) { return '<option value="' + v + '" ' + (Math.abs(c.min_conf - v) < 0.01 ? 'selected' : '') + '>' + Math.round(v * 100) + '%</option>'; }).join('') + '</select></label>'
            + '<label>' + T('Working hours') + '<span class="row"><select id="br-from">' + Array.from({ length: 24 }, function (_, i) { return '<option value="' + i + '" ' + (c.from === i ? 'selected' : '') + '>' + i + ':00</option>'; }).join('') + '</select>→<select id="br-to">' + Array.from({ length: 25 }, function (_, i) { return '<option value="' + i + '" ' + (c.to === i ? 'selected' : '') + '>' + i + ':00</option>'; }).join('') + '</select></span></label>'
            + '<label>' + T('Max automatic replies per hour (0 = no limit)') + '<input type="number" id="br-ph" min="0" max="100000" value="' + c.per_hour + '"></label>'
            + '<label>' + T('The team counts as active in a chat for (hours) after they reply') + '<input type="number" id="br-hp" min="0" max="72" value="' + c.human_pause + '"></label>'
            + '<label>' + T('While the team is active, wait this many minutes for them before the bot answers') + '<input type="number" id="br-hw" min="0" max="240" value="' + (c.human_wait == null ? 10 : c.human_wait) + '"></label>'
            + '<label>' + T('A dream told in pieces: wait this many seconds after the last piece, then answer once (0 = answer each piece)') + '<input type="number" id="br-dw" min="0" max="600" value="' + (c.dream_wait == null ? 120 : c.dream_wait) + '"></label>'
 + '<div class="gcf-wide sub-h">🛡️ ' + T('Protection against repeating and WhatsApp blocks') + '</div>'
            + '<label>' + T('Answer when the customer has stopped typing for (seconds)') + '<input type="number" id="br-qs" min="3" max="120" value="' + (c.quiet_secs == null ? 6 : c.quiet_secs) + '"></label>'
            + '<label>' + T('Longest wait while they keep typing (seconds)') + '<input type="number" id="br-gs" min="5" max="600" value="' + (c.gather_secs == null ? 45 : c.gather_secs) + '"></label>'
            + '<label>' + T('Most bot replies in one chat per hour (0 = no limit)') + '<input type="number" id="br-cph" min="0" max="1000" value="' + (c.chat_per_hour === 0 ? 0 : (c.chat_per_hour || 12)) + '"></label>'
            + '<label>' + T('Most replies to one person in a group per hour (0 = no limit)') + '<input type="number" id="br-gph" min="0" max="1000" value="' + (c.group_person_hour || 0) + '"></label>'
            + '<label>' + T('A reply this similar to an earlier one counts as a repeat (%)') + '<input type="number" id="br-rs" min="40" max="100" value="' + Math.round((c.repeat_sim || 0.7) * 100) + '"></label>'
            + '<label class="check"><input type="checkbox" id="br-rr" ' + (c.repeat_retry !== false ? 'checked' : '') + '> ' + T('On a repeat, try once more with a stronger model, then hand over to the team') + '</label>'
            + '<label>' + T('Clarifying questions in a row before handing over') + '<input type="number" id="br-mc" min="1" max="5" value="' + (c.max_clarify || 1) + '"></label>'
            + '<label>' + T('Groups: seconds between two replies to the same person') + '<input type="number" id="br-gg" min="0" max="3600" value="' + (c.group_gap === undefined ? 120 : c.group_gap) + '"></label>'
            + '<label>' + T('Typing time (seconds) — from / to, by reply length') + '<span class="row"><input type="number" id="br-tmin" min="0.5" max="20" step="0.5" value="' + (c.typing_min || 2) + '" style="width:80px"><input type="number" id="br-tmax" min="1" max="30" value="' + (c.typing_max || 8) + '" style="width:80px"></span></label>'
            + '<label class="gcf-wide">' + T('Words that stop the bot in a chat (the customer sends only this)') + '<input id="br-sw" dir="auto" value="' + esc(c.stop_words == null ? 'وقف، توقف، كفاية، بس كده، stop' : c.stop_words) + '"></label>'
            + '<label>' + T('New number warm-up until (date)') + '<input type="date" id="br-wu" value="' + esc(c.warmup_until || '') + '"></label>'
            + '<label>' + T('Most replies per hour during warm-up') + '<input type="number" id="br-wph" min="1" max="60" value="' + (c.warmup_per_hour || 5) + '"></label>'
            + '<label class="check gcf-wide"><input type="checkbox" id="br-cp" ' + (c.case_private !== false ? 'checked' : '') + '> 🩺 ' + T('Add private chats to the case record (for people who are cases)') + '</label>'
            + '<label class="check gcf-wide"><input type="checkbox" id="br-cpn" ' + (c.case_private_new ? 'checked' : '') + '> ➕ ' + T('Open a new case for a person who describes symptoms in a private chat') + '</label>'
            + '<label>🧪 ' + T('Test numbers (the bot always answers them, even your own / team numbers)') + '<input id="br-test" dir="ltr" placeholder="201xxxxxxxxx, 201yyyyyyyyy" value="' + esc((c.test || []).join(', ')) + '"></label></div>'
            + '<div class="br-pane" data-pane="grp" hidden><h3 class="br-sec">🎯 ' + T('Where the bot answers') + '</h3><div class="seg" id="br-scope">' + [['private', '💬 ' + T('Private chats only')], ['both', '💬 + 👥 ' + T('Private chats and groups')], ['groups', '👥 ' + T('Groups only')]].map(function (o) { return '<button type="button" data-s="' + o[0] + '" class="' + ((c.scope || 'private') === o[0] ? 'on' : '') + '">' + o[1] + '</button>'; }).join('') + '</div>'
            + '<div id="br-groups" ' + ((c.scope || 'private') === 'private' ? 'hidden' : '') + '><div class="seg sm" id="br-gmode" style="margin:10px 0"><button type="button" data-g="selected" class="' + (c.groups_mode !== 'all' ? 'on' : '') + '">' + T('Only the groups I choose') + '</button><button type="button" data-g="all" class="' + (c.groups_mode === 'all' ? 'on' : '') + '">' + T('All groups') + '</button></div>'
            + '<details class="br-team" id="br-team"><summary>👥 ' + T('Team — never answered by the bot; their answers in groups are learned') + '</summary><div id="br-team-b" class="muted small">' + T('Loading…') + '</div></details>'
            + '<div class="br-gdef"><b>' + T('Default for groups') + '</b>' + gForm('def', c.group_default || {}) + '</div><div id="br-glist" class="br-glist"><div class="empty">' + T('Loading groups…') + '</div></div><details class="br-vlist"><summary>👣 ' + T('Visitors of the groups') + '</summary><div id="br-vis-body" class="stack"><div class="empty">' + T('Loading…') + '</div></div></details><details class="br-guide"><summary>🛟 ' + T('Help manual (what the help groups explain)') + '</summary><div id="br-guide-body" class="stack"><div class="empty">' + T('Loading…') + '</div></div></details></div>'
            + '</div><div class="br-pane" data-pane="gen2"><ul class="muted small br-rules"><li>' + T('Only new messages that arrive after switching on are answered — never old ones.') + '</li><li>' + T('Private chats only; groups, team members and your own numbers are never answered.') + '</li><li>' + T('Photos and voice notes (e.g. payment receipts) are left to people and the payment bot.') + '</li><li>' + T('Every reply is paced like the Inbox (typing indicator, limits).') + '</li></ul>'
            + '</div><div class="row br-savebar"><button class="btn primary" id="br-save">💾 ' + T('Save') + '</button><span class="muted small" id="br-msg">' + (c.since && c.mode !== 'off' ? T('On since') + ' ' + esc(when(c.since)) : '') + '</span><span class="muted small br-auto">⚡ ' + T('Changes are saved automatically') + '</span></div></div>'
            + '<div class="card"><h3>🧾 ' + T('What the bot did') + ' (' + fmt(r.log.length) + ')</h3><div class="row wrap"><div class="search-xl"><span>🔎</span><input type="search" id="bl-q" placeholder="' + T('Search the log…') + '" dir="auto"></div><select id="bl-r"><option value="">' + T('All') + '</option><option value="sent">✅ ' + T('Sent') + '</option><option value="handoff">🙋 ' + T('Sent and handed over to a person') + '</option><option value="draft">📝 ' + T('Draft') + '</option><option value="skipped">⏭️ ' + T('Skipped') + '</option></select></div><div id="bl-list"></div></div>';
          function blDraw() { var q = (document.getElementById('bl-q').value || '').toLowerCase(), rf = document.getElementById('bl-r').value;
            var rows = r.log.filter(function (x) { return (!rf || x.result === rf) && (!q || JSON.stringify([x.q, x.reply, x.name, x.why]).toLowerCase().indexOf(q) !== -1); }); var P = pg('botlog', rows, blDraw, 20);
            document.getElementById('bl-list').innerHTML = (P.rows.length ? '<table class="tbl"><thead><tr><th>' + T('When') + '</th><th>' + T('Chat') + '</th><th>' + T('Customer') + '</th><th>' + T('Bot') + '</th><th>' + T('Result') + '</th></tr></thead><tbody>' + P.rows.map(function (x) { return '<tr><td>' + esc(when(x.at)) + '</td><td><a href="?p=inbox#' + encodeURIComponent(x.jid) + '">' + esc(x.name || x.jid.split('@')[0]) + '</a></td><td dir="auto" class="sc-t">' + esc(x.q) + '</td><td dir="auto" class="sc-t">' + esc(x.reply) + '<br><small class="muted">' + Math.round((x.confidence || 0) * 100) + '% · ' + esc(x.model || '') + '</small></td><td>' + (x.result === 'skipped' ? '⏭️ ' + T('Skipped') + ' — ' + esc(x.why || '') : x.result === 'paused' ? '⏸️ /stop · ' + esc(x.why || '') : x.result === 'resumed' ? '▶️ /start · ' + esc(x.why || '') : x.result === 'handoff' ? '🙋 ' + T('Sent and handed over to a person') : x.result === 'sent' ? '✅ ' + T('Sent') : (x.result === 'draft' ? '📝 ' + T('Draft') + (x.why ? ' — ' + T(x.why) : '') : '⚠️ ' + esc(x.error || x.result))) + '</td></tr>'; }).join('') + '</tbody></table>' : '<div class="empty">' + T('Nothing yet.') + '</div>') + P.html; }
          blDraw(); document.getElementById('bl-q').oninput = function () { pgReset('botlog'); blDraw(); }; document.getElementById('bl-r').onchange = function () { pgReset('botlog'); blDraw(); };
          function gForm(k, g) { g = g || {};
            return '<div class="br-gf" data-gk="' + esc(k) + '"><select data-f="mode"><option value="ai" ' + (g.mode !== 'fixed' ? 'selected' : '') + '>✨ ' + T('Interactive (AI answers the message)') + '</option><option value="fixed" ' + (g.mode === 'fixed' ? 'selected' : '') + '>📌 ' + T('Fixed message') + '</option></select>'
              + '<select data-f="trigger"><option value="mention" ' + ((g.trigger || 'mention') === 'mention' ? 'selected' : '') + '>@ ' + T('Only when someone mentions or replies to the number') + '</option><option value="questions" ' + (g.trigger === 'questions' ? 'selected' : '') + '>❓ ' + T('Questions only') + '</option><option value="all" ' + (g.trigger === 'all' ? 'selected' : '') + '>💬 ' + T('Every message') + '</option></select>'
              + '<textarea data-f="text" rows="2" dir="auto" placeholder="' + T('Fixed message ({name} = member name, {group} = group name)') + '" ' + (g.mode === 'fixed' ? '' : 'hidden') + '>' + esc(g.text || '') + '</textarea>'
              + '<label class="small" ' + (g.mode === 'fixed' ? '' : 'hidden') + ' data-cd>' + T('Same member at most once every (hours)') + ' <input type="number" data-f="cooldown" min="1" max="720" value="' + (g.cooldown || 24) + '" style="width:80px"></label>'
              + '<label class="check br-wl"><select data-f="learn"><option value="0" ' + (g.learn ? '' : 'selected') + '>' + T('Do not learn from this group') + '</option><option value="1" ' + (g.learn ? 'selected' : '') + '>📚 ' + T('Learn from this group (questions + answers of the team / admins)') + '</option></select></label>'
              + '<label class="check br-wl"><select data-f="visitors"><option value="0" ' + (g.visitors ? '' : 'selected') + '>' + T('No visitors list') + '</option><option value="1" ' + (g.visitors ? 'selected' : '') + '>👣 ' + T('Keep a visitors list (joins and leaves)') + '</option></select></label>'
              + '<div class="br-vis" ' + (g.visitors ? '' : 'hidden') + '><label class="check br-wl"><select data-f="bye"><option value="0" ' + (g.bye ? '' : 'selected') + '>' + T('No farewell') + '</option><option value="1" ' + (g.bye ? 'selected' : '') + '>👋 ' + T('Farewell to a person who leaves before answering') + '</option></select></label>'
              + '<textarea data-f="bye_text" rows="2" dir="auto" placeholder="' + T('Farewell text — {name} = the person, {group} = group name') + '">' + esc(g.bye_text || '') + '</textarea>'
              + '<label class="check br-wl"><select data-f="revisit"><option value="0" ' + (g.revisit ? '' : 'selected') + '>' + T('No message for a returning person') + '</option><option value="1" ' + (g.revisit ? 'selected' : '') + '>🔁 ' + T('Message to a person who comes back (the group admins are tagged)') + '</option></select></label>'
              + '<textarea data-f="revisit_text" rows="3" dir="auto" placeholder="' + T('Returning text — {mention} = the person, {name}, {group}, {count} = number of visits') + '">' + esc(g.revisit_text || '') + '</textarea>'
              + '<label class="small">' + T('Remove the person on this entry number, after accepting them (0 = never)') + ' <input type="number" data-f="revisit_kick" min="0" max="50" value="' + (g.revisit_kick || 0) + '" style="width:80px"></label>'
              + '<textarea data-f="kick_text" rows="2" dir="auto" placeholder="' + T('Text posted when the person is removed — {name}') + '">' + esc(g.kick_text || '') + '</textarea>'
              + '<label class="small">' + T('Days within which joining another of our groups after leaving one is a move, not a new visit') + ' <input type="number" data-f="revisit_days" min="0" max="365" value="' + (g.revisit_days == null ? 3 : g.revisit_days) + '" style="width:80px"></label></div>'
              + '<label class="check br-wl"><select data-f="guide"><option value="0" ' + (g.guide ? '' : 'selected') + '>' + T('Not a help group') + '</option><option value="1" ' + (g.guide ? 'selected' : '') + '>🛟 ' + T('Explain the system to members, by what their account may open') + '</option></select></label>'
              + '<textarea data-f="guide_text" rows="2" dir="auto" ' + (g.guide ? '' : 'hidden') + ' placeholder="' + T('Extra notes for the help (our own rules, names of our pages, who to ask)') + '">' + esc(g.guide_text || '') + '</textarea>'
              + '<label class="check br-wl"><select data-f="welcome"><option value="0" ' + (g.welcome ? '' : 'selected') + '>' + T('No welcome') + '</option><option value="1" ' + (g.welcome ? 'selected' : '') + '>👋 ' + T('Welcome new members with a mention') + '</option></select></label>'
              + '<textarea data-f="welcome_text" rows="2" dir="auto" ' + (g.welcome ? '' : 'hidden') + ' placeholder="' + T('Welcome message — {mention} = the new member (tagged), {group} = group name') + '">' + esc(g.welcome_text || '') + '</textarea></div>'; }
          function gRead(box) { var o = {}; [].forEach.call(box.querySelectorAll('[data-f]'), function (i) { o[i.dataset.f] = i.value; }); return o; }
          // General / Groups sub-tabs (one form, one Save button).
          var genEls = [el.querySelector('.br-modes'), el.querySelector('.br-f'), el.querySelector('[data-pane=gen2]')];
          document.getElementById('br-sub').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); var g = b.dataset.v === 'grp'; genEls.forEach(function (x) { if (x) x.hidden = g; }); el.querySelector('[data-pane=grp]').hidden = !g; };
          get('visitors').then(function (r) { var box = document.getElementById('br-vis-body'); if (!box || !r || !r.ok) return;
            box.innerHTML = !r.list.length ? '<div class="empty">' + T(r.groups ? 'No visitors yet' : 'Turn on "Keep a visitors list" for a group first') + '</div>' : '<table class="tbl"><thead><tr><th>' + T('Name') + '</th><th>' + T('Phone') + '</th><th>' + T('Visits') + '</th><th>' + T('Last visit') + '</th></tr></thead><tbody>' + r.list.map(function (x) { return '<tr><td>' + esc(x.name || '—') + (x.returning ? ' 🔁' : '') + '</td><td dir="ltr">+' + esc(x.phone) + '</td><td>' + x.visits + '</td><td>' + esc(when(x.last)) + '</td></tr>'; }).join('') + '</tbody></table>'; });
          get('sysguide').then(function (r) { var box = document.getElementById('br-guide-body'); if (!box || !r || !r.ok) return;
            box.innerHTML = '<p class="muted small">' + T('Leave a page empty to use the built-in text. What you write replaces it for this number.') + '</p>' + r.keys.map(function (k) { return '<label>' + esc(r.labels[k] || k) + '<textarea rows="3" dir="auto" data-gk2="' + esc(k) + '" placeholder="' + esc(r.manual[k] || '') + '">' + esc((r.pages || {})[k] || '') + '</textarea></label>'; }).join('') + '<button class="btn primary sm" id="br-guide-save">' + T('Save') + '</button>';
            document.getElementById('br-guide-save').onclick = function () { var o = {}; [].forEach.call(box.querySelectorAll('[data-gk2]'), function (t) { o[t.dataset.gk2] = t.value; }); post('sysguide', { pages: o }).then(function (x) { toast(x && x.ok ? T('Saved') : (x && x.error) || T('Failed'), x && x.ok ? 'ok' : 'bad'); }); }; });
          if (new URLSearchParams(location.search).get('sub') === 'groups') document.querySelector('#br-sub [data-v=grp]').click();
          el.addEventListener('change', function (e) { var w = e.target.closest('.br-gf [data-f=visitors]'); if (w) w.closest('.br-gf').querySelector('.br-vis').hidden = w.value !== '1'; });
          el.addEventListener('change', function (e) { var w = e.target.closest('.br-gf [data-f=guide]'); if (w) w.closest('.br-gf').querySelector('[data-f=guide_text]').hidden = w.value !== '1'; });
          el.addEventListener('change', function (e) { var w = e.target.closest('.br-gf [data-f=welcome]'); if (w) w.closest('.br-gf').querySelector('[data-f=welcome_text]').hidden = w.value !== '1'; });
          el.addEventListener('change', function (e) { var f = e.target.closest('.br-gf [data-f=mode]'); if (f) { var box = f.closest('.br-gf'); box.querySelector('[data-f=text]').hidden = f.value !== 'fixed'; box.querySelector('[data-cd]').hidden = f.value !== 'fixed'; } });
          document.getElementById('br-scope').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); document.getElementById('br-groups').hidden = b.dataset.s === 'private'; };
          document.getElementById('br-gmode').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); };
          function teamLoad(body) {
            (body ? post('bot_team', body) : get('bot_team')).then(function (t) { var b = document.getElementById('br-team-b'); if (!t.ok) { b.textContent = '⚠️ ' + (t.error || ''); return; }
              var person = function (p, extra) { return '<span class="tm-p"><b dir="auto">' + esc(p.name || '—') + '</b> <span dir="ltr">' + (p.hidden ? T('hidden number') : '+' + esc(p.phone)) + '</span>' + (extra || '') + '</span>'; };
              b.innerHTML = t.groups.map(function (g) { return '<div class="tm-g"><b dir="auto">👥 ' + esc(g.name) + '</b> <span class="muted">· ' + fmt(g.admins.length) + ' ' + T('admins') + (g.error ? ' · ⚠️ ' + esc(g.error) : '') + '</span><div class="tm-list">' + g.admins.map(function (a) { return person(a, a.role === 'superadmin' ? ' <span class="tag">' + T('Owner') + '</span>' : ''); }).join('') + '</div></div>'; }).join('')
                + '<div class="tm-g"><b>📱 ' + T('Our numbers') + '</b><div class="tm-list">' + t.own.map(function (p) { return person(p); }).join('') + '</div></div>'
                + '<div class="tm-g"><b>🧑‍💻 ' + T('Inbox users') + '</b><div class="tm-list">' + (t.users.map(function (p) { return person(p); }).join('') || '—') + '</div></div>'
                + '<div class="tm-g"><b>➕ ' + T('Added by hand') + '</b><div class="tm-list">' + (t.manual.map(function (p) { return person(p, ' <button type="button" class="link-btn" data-tm-rm="' + esc(p.phone) + '">✕</button>'); }).join('') || '—') + '</div>'
                + '<div class="row"><input id="tm-ph" placeholder="' + T('Phone number (e.g. 2010…)') + '" dir="ltr"><input id="tm-nm" placeholder="' + T('Name (optional)') + '" dir="auto"><button type="button" class="chip-btn sm" id="tm-add">➕ ' + T('Add to the team') + '</button></div></div>';
            });
          }
          document.getElementById('br-team').addEventListener('toggle', function () { if (this.open && !this.dataset.l) { this.dataset.l = 1; teamLoad(); } });
          el.addEventListener('click', function (e) { var rm = e.target.closest('[data-tm-rm]'); if (rm) { teamLoad({ phone: rm.dataset.tmRm, remove: 1 }); return; } if (e.target.id === 'tm-add') { var p = document.getElementById('tm-ph').value; if (p.replace(/\D/g, '').length < 10) { toast(T('Enter a full number with country code.')); return; } teamLoad({ phone: p, name: document.getElementById('tm-nm').value }); } });
          get('groups').then(function (gr) { var box = document.getElementById('br-glist'); if (!gr.ok) { box.innerHTML = '<div class="empty">⚠️ ' + esc(gr.error || '') + '</div>'; return; }
            var sel = c.groups || {};
            var glist = gr.groups.filter(function (g) { return !g.left; }).sort(function (a, b) { return (sel[b.jid] ? 1 : 0) - (sel[a.jid] ? 1 : 0); });
            box.innerHTML = '<div class="search-xl"><span>🔎</span><input type="search" id="br-gq" placeholder="' + T('Search groups…') + '" dir="auto"></div>' + glist.map(function (g) { var on = !!sel[g.jid];
              return '<div class="br-g' + (on ? ' on' : '') + '" data-jid="' + esc(g.jid) + '" data-name="' + esc(g.name) + '"><label class="check"><input type="checkbox" data-gon ' + (on ? 'checked' : '') + '> <b dir="auto">' + esc(g.name) + '</b> <small class="muted">👤 ' + fmt(g.members) + ' · 💬 ' + fmt(g.messages) + '</small></label><div class="br-gopt" ' + (on ? '' : 'hidden') + '>' + gForm(g.jid, sel[g.jid] || c.group_default || {}) + '</div></div>'; }).join('');
            box.onchange = function (e) { var cb = e.target.closest('[data-gon]'); if (!cb) return; var row = cb.closest('.br-g'); row.classList.toggle('on', cb.checked); row.querySelector('.br-gopt').hidden = !cb.checked; };
            document.getElementById('br-gq').oninput = function () { var q = this.value.toLowerCase(); [].forEach.call(box.querySelectorAll('.br-g'), function (r) { r.hidden = q && r.dataset.name.toLowerCase().indexOf(q) === -1; }); };
          });
          el.querySelector('.br-modes').onchange = function () { [].forEach.call(this.querySelectorAll('.br-opt'), function (l) { l.classList.toggle('on', l.querySelector('input').checked); }); };
          // Save: button + automatic save (1 s after any change). Switching ON automatic replies still asks first.
          var lastMode = c.mode, saveT = null, msgEl = document.getElementById('br-msg');
          function doSave(silent) {
            var mode = el.querySelector('input[name=br-mode]:checked').value;
            if (mode === 'auto' && lastMode !== 'auto' && !confirm(T('Switch on automatic replies? The bot will answer customers by itself when it is confident.'))) { var back = el.querySelector('input[name=br-mode][value="' + lastMode + '"]'); if (back) { back.checked = true; el.querySelector('.br-modes').dispatchEvent(new Event('change')); } return; }
            var scope = document.querySelector('#br-scope .on') ? document.querySelector('#br-scope .on').dataset.s : 'private';
            var gmode = document.querySelector('#br-gmode .on') ? document.querySelector('#br-gmode .on').dataset.g : 'selected';
            var groups = {}; [].forEach.call(document.querySelectorAll('#br-glist .br-g'), function (r) { if (r.querySelector('[data-gon]').checked) groups[r.dataset.jid] = Object.assign(gRead(r.querySelector('.br-gf')), { name: r.dataset.name }); });
            if (!document.querySelector('#br-glist .br-g')) groups = c.groups || {}; // group list not loaded yet: keep what is saved
            var gdef = gRead(document.querySelector('.br-gdef .br-gf'));
            if (scope !== 'private' && gmode === 'selected' && !Object.keys(groups).length) { msgEl.textContent = '⚠️ ' + T('Choose at least one group, or “All groups”.'); return; }
            msgEl.textContent = T('Saving…');
            post('bot_run', { scope: scope, groups_mode: gmode, groups: groups, group_default: gdef, mode: mode, min_conf: document.getElementById('br-conf').value, from: document.getElementById('br-from').value, to: document.getElementById('br-to').value, per_hour: document.getElementById('br-ph').value, group_person_hour: (document.getElementById('br-gph') || {}).value, human_pause: document.getElementById('br-hp').value, human_wait: document.getElementById('br-hw').value, dream_wait: document.getElementById('br-dw').value, gather_secs: document.getElementById('br-gs').value, quiet_secs: document.getElementById('br-qs').value, chat_per_hour: document.getElementById('br-cph').value, repeat_sim: (+document.getElementById('br-rs').value || 70) / 100, repeat_retry: document.getElementById('br-rr').checked ? 1 : 0, max_clarify: document.getElementById('br-mc').value, group_gap: (document.getElementById('br-gg') || {}).value, typing_min: document.getElementById('br-tmin').value, typing_max: document.getElementById('br-tmax').value, stop_words: document.getElementById('br-sw').value, warmup_until: document.getElementById('br-wu').value, warmup_per_hour: document.getElementById('br-wph').value, case_private: document.getElementById('br-cp').checked ? 1 : 0, case_private_new: document.getElementById('br-cpn').checked ? 1 : 0, test: document.getElementById('br-test').value }).then(function (x) {
              if (!x.ok) { msgEl.textContent = '⚠️ ' + (x.error || T('Failed')); return; }
              lastMode = mode; c = x.run || c;
              msgEl.textContent = '✓ ' + (silent ? T('Saved automatically') : T('Saved')) + ' · ' + new Date().toLocaleTimeString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
              var cnt = document.querySelector('#br-sub [data-v=grp] i'); if (cnt) cnt.textContent = Object.keys(groups).length;
              kpis();
            });
          }
          function autoSave() { clearTimeout(saveT); saveT = setTimeout(function () { doSave(true); }, 1000); }
          document.getElementById('br-save').onclick = function () { clearTimeout(saveT); doSave(false); };
          el.querySelector('.card.br').addEventListener('change', function (e) { if (!e.target.closest('#br-gq, #tm-ph, #tm-nm')) autoSave(); });
          el.querySelector('.card.br').addEventListener('input', function (e) { if (e.target.matches('textarea, input[type=number], #br-test')) autoSave(); });
          el.querySelector('.card.br').addEventListener('click', function (e) { if (e.target.closest('#br-scope button, #br-gmode button')) autoSave(); });
        }); }
      if (t === 'cfg') { el.innerHTML = '<div class="card"><div class="empty">' + T('Loading…') + '</div></div>';
        get('bot_cfg').then(function (r) { var c = r.cfg || {};
          el.innerHTML = '<div class="card bt-cfg"><h3>📱 ' + esc(r.account) + '</h3><label>🏷️ ' + T('What this number is (detected from its own chats)') + '<textarea id="bc-prof" rows="6" dir="auto" readonly>' + esc(r.profile || T('Not detected yet.')) + '</textarea></label><div class="row"><button class="chip-btn" id="bc-det">🔎 ' + T('Detect from this number’s chats') + '</button><span class="muted small" id="bc-dmsg">' + (r.profile_at ? T('Detected') + ' ' + esc(when(r.profile_at)) : '') + '</span></div>'
            + '<label>🤖 ' + T('Model for the bot') + '<select id="bc-model"><option value="">' + T('Automatic (best available, with fallback)') + '</option>' + r.models.map(function (m) { return '<option value="' + esc(m.id) + '" ' + (c.model === m.id ? 'selected' : '') + '>' + (m.meta ? '🦙 ' : '') + esc(m.label) + '</option>'; }).join('') + '</select></label>'
            + '<label class="check"><input type="checkbox" id="bc-fast" ' + (c.fast !== false ? 'checked' : '') + '> ⚡ ' + T('Fast replies (light model first, when “Automatic” model is chosen)') + '</label>'
            + '<p class="muted small">' + T('Meta (Llama) models need a key with credit: OpenRouter paid plan, FlexAI or Groq. Add one in AI providers and it appears here with 🦙.') + '</p>'
            + '<label>🎭 ' + T('Tone') + '<select id="bc-tone"><option value="warm">' + T('Warm & reassuring') + '</option><option value="short">' + T('Short & direct') + '</option><option value="formal">' + T('Formal') + '</option></select></label>'
            + '<label>🪪 ' + T('Bot name (optional)') + '<input id="bc-name" value="' + esc(c.name) + '" dir="auto" placeholder="' + T('e.g.') + ' ' + esc(EX('bot_name')) + '"></label>'
            + '<label>ℹ️ ' + T('About the service (overrides the detected description)') + '<textarea id="bc-about" rows="3" dir="auto" placeholder="' + T('Who you are, what you offer, how your service works, opening hours…') + '">' + esc(c.about) + '</textarea></label>'
            + '<label>📏 ' + T('Rules for the bot') + '<textarea id="bc-rules" rows="4" dir="auto" placeholder="' + T('e.g. Never give a diagnosis. Women are added to women groups only. Always ask for the gender first.') + '">' + esc(c.rules) + '</textarea></label>'
            + '<div class="row"><button class="btn primary" id="bc-save">💾 ' + T('Save') + '</button><span class="muted small" id="bc-msg"></span></div></div>';
          document.getElementById('bc-tone').value = c.tone || 'warm';
          document.getElementById('bc-det').onclick = function () { var b = this, m = document.getElementById('bc-dmsg'); b.disabled = true; m.textContent = T('AI is reading this number’s chats…'); if (window.WI_AI_POLL) window.WI_AI_POLL(); post('bot_profile', {}).then(function (x) { b.disabled = false; if (!x.ok) { m.textContent = '⚠️ ' + x.error; return; } document.getElementById('bc-prof').value = x.profile; m.textContent = '✓ ' + x.task.model + ' · ' + fmt(x.task.tokens_in + x.task.tokens_out) + ' ' + T('tokens'); kpis(); }); };
          document.getElementById('bc-save').onclick = function () { post('bot_cfg', { model: document.getElementById('bc-model').value, tone: document.getElementById('bc-tone').value, name: document.getElementById('bc-name').value, fast: document.getElementById('bc-fast').checked ? 1 : 0, about: document.getElementById('bc-about').value, rules: document.getElementById('bc-rules').value }).then(function (x) { document.getElementById('bc-msg').textContent = x.ok ? '✓ ' + T('Saved') + ' — ' + T('try it in ③ Test chat') : '⚠️ ' + x.error; }); };
        }); }
      if (t === 'eval') { el.innerHTML = '<div class="card"><p class="muted">' + T('A random real customer message is shown with the reply your team actually sent and what the bot would say. Rate it; if it is wrong, write the correct answer — it becomes approved knowledge.') + '</p><button class="btn primary" id="ev-go">🎲 ' + T('Next real message') + '</button><div id="ev-out"></div></div>';
        document.getElementById('ev-go').onclick = function () { var o = document.getElementById('ev-out'), b = this; b.disabled = true; o.innerHTML = '<div class="empty">' + T('AI is answering…') + '</div>'; if (window.WI_AI_POLL) window.WI_AI_POLL();
          post('bot_eval', {}).then(function (r) { b.disabled = false; if (!r.question) { o.innerHTML = '<div class="empty">⚠️ ' + esc(r.error || '') + '</div>'; return; }
            o.innerHTML = '<div class="ev"><div class="ev-q"><b>👤 ' + T('Customer') + '</b><div dir="auto">' + esc(r.question) + '</div><small class="muted">' + esc(when(r.time)) + '</small></div><div class="ev-grid"><div class="ev-a"><b>👥 ' + T('Your team replied') + '</b><div dir="auto">' + esc(r.actual) + '</div></div><div class="ev-b"><b>🤖 ' + T('The bot would reply') + '</b>' + botMeta(r) + '<div dir="auto">' + esc(r.reply || r.error || '') + '</div>' + srcList(r) + '</div></div>'
              + '<div class="row wrap"><button class="btn sm" data-rate="good">👍 ' + T('Good') + '</button><button class="btn danger sm" data-rate="bad">👎 ' + T('Wrong') + '</button></div><div id="ev-fix" hidden><textarea id="ev-fixt" rows="3" dir="auto" style="width:100%">' + esc(r.actual) + '</textarea><button class="btn primary sm" id="ev-save">💾 ' + T('Save as the correct answer') + '</button></div></div>';
            o.onclick = function (e) { var x = e.target.closest('[data-rate]'); if (x) { if (x.dataset.rate === 'good') post('bot_rate', { rating: 'good', q: r.question, bot: r.reply }).then(function () { toast('✓'); kpis(); document.getElementById('ev-go').click(); }); else document.getElementById('ev-fix').hidden = false; }
              if (e.target.id === 'ev-save') post('bot_rate', { rating: 'bad', q: r.question, bot: r.reply, fix: document.getElementById('ev-fixt').value }).then(function () { toast('✓ ' + T('Saved')); kpis(); document.getElementById('ev-go').click(); }); };
          }); }; }
    }
    function botMeta(r) { return r.ok === false ? '' : '<div class="bt-meta">' + (r.handoff ? '<span class="pill bad">🙋 ' + T('Hands over to a human') + '</span>' : '') + '<span class="pill">' + T('Confidence') + ' ' + Math.round((r.confidence || 0) * 100) + '%</span>' + (r.task ? '<span class="muted small">' + esc(r.task.model) + ' · ' + fmt(r.task.tokens_in + r.task.tokens_out) + ' ' + T('tokens') + '</span>' : '') + (r.reason ? '<span class="muted small" dir="auto">' + esc(r.reason) + '</span>' : '') + '</div>'; }
    function srcList(r) { if (!r.sources || !r.sources.length) return '<div class="muted small">' + T('No matching knowledge — the bot has nothing to answer from.') + '</div>'; return '<details class="bt-src"><summary>📚 ' + T('Sources') + ' (' + r.sources.length + ')</summary>' + r.sources.map(function (s, i) { return '<div class="' + ((r.used || []).indexOf(i) !== -1 ? 'used' : '') + '"><b>S' + (i + 1) + '</b> ' + (s.kind === 'library' ? '📚' : '🧠') + ' <span dir="auto">' + esc(s.title) + '</span>' + (s.status === 'pending' ? ' <span class="tag">⏳</span>' : '') + '</div>'; }).join('') + '</details>'; }
    function say(who, text, r) { var log = document.getElementById('bt-log'), d = document.createElement('div'); d.className = 'bt-m ' + who; d.innerHTML = (r ? botMeta(r) : '') + '<div dir="auto">' + esc(text) + '</div>' + (r ? srcList(r) : ''); log.appendChild(d); log.scrollTop = log.scrollHeight; if (text !== '…') BT.hist.push({ from: who === 'me' ? 'customer' : 'bot', text: text }); return d; }
    function kbShown() {
      var q = (BT.q || '').toLowerCase(), rows = BT.kb.filter(function (k) { return (!BT.st || k.status === BT.st) && (!BT.topic || k.topic === BT.topic) && (!q || (k.q + ' ' + k.a + ' ' + k.topic).toLowerCase().indexOf(q) !== -1); });
      return rows.sort(function (a, b) { return (b.sources || 0) - (a.sources || 0); });
    }
    function kbTable() {
      var el = document.getElementById('kb-list'); el.innerHTML = '<div class="empty">' + T('Finding the different answers…') + '</div>';
      get('kb_table').then(function (r) {
        if (!r.ok) { el.innerHTML = '<div class="empty">⚠️ ' + esc(r.error) + '</div>'; return; }
        var q = (BT.q || '').toLowerCase();
        var rows = r.rows.filter(function (x) { return (!BT.st || x.status === BT.st) && (!BT.topic || x.topic === BT.topic) && (!q || JSON.stringify([x.q, x.answers]).toLowerCase().indexOf(q) !== -1); });
        var recs = rows.filter(function (x) { return x.recommended; });
        BT.ktRows = rows; var P = pg('kbt', rows, function () { kbTable(); }, 25); rows = P.rows;
        el.innerHTML = '<div class="kbt-top">' + (recs.length ? '⭐ ' + fmt(recs.length) + ' ' + T('questions have a better team answer') + ' <button class="btn sm" id="kbt-applyall">⭐ ' + T('Apply all recommended') + '</button>' : '✓ ' + T('No better team answers found')) + '<span class="muted small"> · ' + T('Score = 50% match with the bot answer + 30% agreement with the other team answers + 20% how often it was said') + '</span></div>'
          + '<table class="tbl kbt"><thead><tr><th></th><th>' + T('Question') + '</th><th>' + T('Different answers (your team’s real replies)') + '</th></tr></thead><tbody>' + rows.map(function (x) {
          return '<tr data-id="' + esc(x.id) + '"><td><input type="checkbox" data-kbsel ' + ((BT.sel || {})[x.id] ? 'checked' : '') + '></td><td class="kbt-q"><span class="tag">' + esc(x.topic || '—') + '</span><div dir="auto"><b>' + esc(x.q) + '</b></div><small class="muted">' + (x.status === 'approved' ? '✅ ' + T('Approved') : (x.status === 'disabled' ? '⛔ ' + T('Disabled') : '⏳ ' + T('To review'))) + ' · ' + fmt(x.variants) + ' ' + T('answers') + '</small></td>'
            + '<td><div class="kbt-as">' + x.answers.map(function (a) { var mc = a.match >= 75 ? 'hi' : (a.match >= 50 ? 'mid' : 'lo'); return '<div class="kbt-a' + (a.current ? ' cur' : '') + (a.recommended ? ' rec' : '') + '"><div class="kbt-ah">' + (a.current ? '🤖 ' + T('Bot uses this') : (a.recommended ? '<button class="btn sm" data-use="' + esc(a.key) + '">⭐ ' + T('Recommended — use it') + '</button>' : '<button class="link-btn" data-use="' + esc(a.key) + '">↪ ' + T('Use this answer') + '</button>')) + (a.current ? '' : '<span class="mt ' + mc + '" title="' + T('Match with the bot answer') + '">' + a.match + '%</span>') + (a.score != null ? '<span class="sc" title="' + T('Score') + '">⚖️ ' + a.score + '</span>' : '') + '<span class="muted small">' + (a.from === 'team' ? '👥 ' + fmt(a.count) + '×' : (a.from === 'learned' ? '🧠' : '')) + (a.last ? ' · ' + esc(day(a.last)) : '') + '</span></div><div dir="auto" class="kbt-at">' + esc(a.text) + '</div></div>'; }).join('') + '</div></td></tr>';
        }).join('') + '</tbody></table>' + P.html;
        BT.ktable = r.rows;
        el.onchange = function (e) { var c = e.target.closest('[data-kbsel]'); if (!c) return; var id = c.closest('tr').dataset.id; if (c.checked) BT.sel[id] = 1; else delete BT.sel[id]; var n = Object.keys(BT.sel).length; document.getElementById('kb-n').textContent = n ? fmt(n) + ' ' + T('selected') : ''; [].forEach.call(document.querySelectorAll('[data-kbb]'), function (b) { b.disabled = !n; }); };
        var ap = document.getElementById('kbt-applyall');
        if (ap) ap.onclick = function () { if (!confirm(T('Replace the bot answer with the recommended team answer for') + ' ' + recs.length + ' ' + T('questions, and approve them?'))) return; ap.disabled = true;
          var list = recs.slice(), done = 0; (function nx() { var x = list.shift(); if (!x) { toast('✓ ' + fmt(done)); kpis(); kbTable(); return; } var a = x.answers.filter(function (v) { return v.key === x.recommended.key; })[0]; post('kb_set', { id: x.id, a: a.text, status: 'approved' }).then(function () { done++; nx(); }); })(); };
        el.onclick = function (e) { var u = e.target.closest('[data-use]'); if (!u) return; var tr = u.closest('tr'), row = BT.ktable.filter(function (x) { return x.id === tr.dataset.id; })[0], ans = row.answers.filter(function (a) { return a.key === u.dataset.use; })[0];
          post('kb_set', { id: row.id, a: ans.text, status: 'approved' }).then(function (x) { toast(x.ok ? '✓ ' + T('Saved and approved') : '⚠️ ' + x.error); kpis(); kbTable(); }); };
      });
    }
    function drawKb() {
      if (BT.kview === 'table') return kbTable();
      var el = document.getElementById('kb-list'); if (!el) return;
      var tp = document.getElementById('kb-tp'); if (tp && tp.options.length < 2) { var ts = {}; BT.kb.forEach(function (k) { if (k.topic) ts[k.topic] = 1; }); Object.keys(ts).sort().forEach(function (t) { var o = document.createElement('option'); o.textContent = t; tp.appendChild(o); }); }
      var rows = kbShown();
      var nSel = Object.keys(BT.sel || {}).length; document.getElementById('kb-n').textContent = nSel ? fmt(nSel) + ' ' + T('selected') : ''; [].forEach.call(document.querySelectorAll('[data-kbb]'), function (b) { b.disabled = !nSel; });
      var P = pg('kb', rows, drawKb, 30);
      el.innerHTML = P.html + P.rows.map(function (k) {
        return '<div class="kb-i st-' + esc(k.status) + ((BT.sel || {})[k.id] ? ' picked' : '') + '" data-id="' + esc(k.id) + '"><div class="kb-h"><label class="check"><input type="checkbox" data-kbsel ' + ((BT.sel || {})[k.id] ? 'checked' : '') + '></label><span class="tag">' + esc(k.topic || '—') + '</span><span class="muted small">🔁 ' + fmt(k.sources || 0) + ' ' + T('times in chats') + (k.merged ? ' · 🧬 ' + fmt(k.merged) + ' ' + T('merged') : '') + (k.auto ? ' · ⚡ ' + T('auto') : '') + '</span><span class="pill st">' + (k.status === 'approved' ? '✅ ' + T('Approved') : (k.status === 'disabled' ? '⛔ ' + T('Disabled') : '⏳ ' + T('To review'))) + '</span></div>'
          + '<label>❓<input class="kb-qi" value="' + esc(k.q) + '" dir="auto"></label><label>💬<textarea class="kb-ai" rows="3" dir="auto">' + esc(k.a) + '</textarea></label>' + (k.alt ? '<details class="small"><summary>' + T('Another version from the chats') + '</summary><div dir="auto">' + esc(k.alt) + '</div><button class="link-btn" data-kb="alt">' + T('Use this one') + '</button></details>' : '')
          + '<div class="row"><button class="btn sm" data-kb="approve">✅ ' + T('Approve') + '</button><button class="chip-btn sm" data-kb="save">💾 ' + T('Save edit') + '</button><button class="chip-btn sm" data-kb="disable">⛔ ' + T('Disable') + '</button><button class="link-btn bad-t" data-kb="delete">🗑</button></div></div>';
      }).join('') || '<div class="empty">' + T('No knowledge yet — run ① Learn from conversations.') + '</div>';
      el.insertAdjacentHTML('beforeend', P.html);
      el.onchange = function (e) { var c = e.target.closest('[data-kbsel]'); if (!c) return; var id = c.closest('.kb-i').dataset.id; BT.sel = BT.sel || {}; if (c.checked) BT.sel[id] = 1; else delete BT.sel[id]; c.closest('.kb-i').classList.toggle('picked', c.checked); var n = Object.keys(BT.sel).length; document.getElementById('kb-n').textContent = n ? fmt(n) + ' ' + T('selected') : ''; [].forEach.call(document.querySelectorAll('[data-kbb]'), function (b) { b.disabled = !n; }); };
      el.onclick = function (e) { var b = e.target.closest('[data-kb]'); if (!b) return; var box = b.closest('.kb-i'), id = box.dataset.id, act = b.dataset.kb, body = { id: id };
        if (act === 'approve' || act === 'save') { body.q = box.querySelector('.kb-qi').value; body.a = box.querySelector('.kb-ai').value; if (act === 'approve') body.status = 'approved'; }
        if (act === 'disable') body.status = 'disabled'; if (act === 'alt') body.use_alt = 1; if (act === 'delete') { if (!confirm(T('Delete this item?'))) return; body.do = 'delete'; }
        post('kb_set', body).then(function (x) { if (x.ok) { toast('✓'); kpis(); } else toast('⚠️ ' + x.error); }); };
    }
    document.getElementById('bt-tabs').onclick = function (e) { var a = e.target.closest('[data-t]'); if (a) { e.preventDefault(); tab(a.dataset.t); } };
    tab(BT.tab); kpis();
    return;
  }

  /* ===================== 🤖 Bot page: general settings + groups table + team + log ===================== */
  function gCfgForm(g, pre) { g = g || {}; pre = pre || '';
    var o = function (v, cur, l) { return '<option value="' + v + '" ' + (String(cur) === String(v) ? 'selected' : '') + '>' + l + '</option>'; };
    return '<div class="gcf">'
      + '<label>' + T('Reply type') + '<select data-f="mode">' + o('ai', g.mode || 'ai', '✨ ' + T('Interactive (AI answers the message)')) + o('fixed', g.mode, '📌 ' + T('Fixed message')) + '</select></label>'
      + '<label>' + T('When') + '<select data-f="trigger">' + o('mention', g.trigger || 'mention', '@ ' + T('Only when someone mentions or replies to the number')) + o('questions', g.trigger, '❓ ' + T('Questions only')) + o('all', g.trigger, '💬 ' + T('Every message')) + '</select></label>'
      + '<label>' + T('Learning') + '<select data-f="learn">' + o('0', g.learn ? 1 : 0, T('Do not learn from this group')) + o('1', g.learn ? 1 : 0, '📚 ' + T('Learn from this group (questions + answers of the team / admins)')) + '</select></label>'
      + '<label>' + T('System help') + '<select data-f="guide">' + o('0', g.guide ? 1 : 0, T('Not a help group')) + o('1', g.guide ? 1 : 0, '🛟 ' + T('Explain the system to members, by what their account may open')) + '</select></label>'
      + '<label class="gcf-wide" data-show="guide" ' + (g.guide ? '' : 'hidden') + '>' + T('Extra notes for the help (our own rules, names of our pages, who to ask)') + '<textarea data-f="guide_text" rows="3" dir="auto">' + esc(g.guide_text || '') + '</textarea></label>'
      + '<label>' + T('Welcome') + '<select data-f="welcome">' + o('0', g.welcome ? 1 : 0, T('No welcome')) + o('1', g.welcome ? 1 : 0, '👋 ' + T('Welcome new members with a mention')) + '</select></label>'
      + '<label class="gcf-wide" data-show="fixed" ' + (g.mode === 'fixed' ? '' : 'hidden') + '>' + T('Fixed message ({name} = member name, {group} = group name)') + '<textarea data-f="text" rows="3" dir="auto">' + esc(g.text || '') + '</textarea></label>'
      + '<label data-show="fixed" ' + (g.mode === 'fixed' ? '' : 'hidden') + '>' + T('Same member at most once every (hours)') + '<input type="number" data-f="cooldown" min="1" max="720" value="' + (g.cooldown || 24) + '"></label>'
      + '<label class="gcf-wide" data-show="welcome" ' + (g.welcome ? '' : 'hidden') + '>' + T('Welcome message — {mention} = the new member (tagged), {group} = group name') + '<textarea data-f="welcome_text" rows="3" dir="auto">' + esc(g.welcome_text || '') + '</textarea></label>'
      + '<label>' + T('Warm-up mode') + '<select data-f="fun">' + o('0', g.fun ? 1 : 0, T('Not here')) + o('1', g.fun ? 1 : 0, '😄 ' + T('Allowed here (the owner turns it on with the warm-up word from the phone)')) + '</select></label>'
      + '<label>' + T('Join requests') + '<select data-f="approve">' + o('0', g.approve ? 1 : 0, T('The admins approve them')) + o('1', g.approve ? 1 : 0, '✅ ' + T('Approve automatically')) + '</select></label>'
      + '<label>' + T('Reception gate') + '<select data-f="gate">' + o('0', g.gate ? 1 : 0, T('Off')) + o('1', g.gate ? 1 : 0, '🎙️ ' + T('Woman / man → ask for a recording → tag the reviewers')) + '</select></label>'
      + '<label class="gcf-wide" data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('Reviewers tagged when a recording arrives (phone numbers, comma separated)') + '<input data-f="gate_review" dir="ltr" value="' + esc(g.gate_review || '') + '" placeholder="2010…, 2011…"></label>'
      + '<label class="gcf-wide" data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('After the move, ask the healer to open the case in this group (group ID, e.g. 1203…@g.us)') + '<input data-f="gate_notify" dir="ltr" value="' + esc(g.gate_notify || '') + '" placeholder="1203…@g.us"></label>'
      + '<label data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('New members who do not answer') + '<select data-f="gate_follow">' + o('1', g.gate_follow === false ? 0 : 1, '⏰ ' + T('Reminder, last notice, then removal')) + o('0', g.gate_follow === false ? 0 : 1, T('Leave them')) + '</select></label>'
      + '<label data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('Reminder after (hours)') + '<input type="number" data-f="gate_remind_h" min="1" max="72" value="' + (g.gate_remind_h || 3) + '"></label>'
      + '<label data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('Last notice after another (hours)') + '<input type="number" data-f="gate_final_h" min="1" max="72" value="' + (g.gate_final_h || 3) + '"></label>'
      + '<label data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('Removed after the last notice (minutes, 0 = never)') + '<input type="number" data-f="gate_kick_m" min="0" max="1440" value="' + (g.gate_kick_m === undefined ? 30 : g.gate_kick_m) + '"></label>'
      + '<label data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('Words that mean a woman (empty = the defaults)') + '<input data-f="gate_f" dir="auto" value="' + esc(g.gate_f || '') + '"></label>'
      + '<label data-show="gate" ' + (g.gate ? '' : 'hidden') + '>' + T('Words that mean a man (empty = the defaults)') + '<input data-f="gate_m" dir="auto" value="' + esc(g.gate_m || '') + '"></label></div>'; }
  function gCfgRead(box) { var o = {}; [].forEach.call(box.querySelectorAll('[data-f]'), function (i) { o[i.dataset.f] = i.value; }); return o; }
  function gCfgWire(box, onChange) {
    var t = null, sync = function () { var m = box.querySelector('[data-f=mode]').value, w = box.querySelector('[data-f=welcome]').value; [].forEach.call(box.querySelectorAll('[data-show=fixed]'), function (x) { x.hidden = m !== 'fixed'; }); var gt = box.querySelector('[data-f=gate]'); [].forEach.call(box.querySelectorAll('[data-show=gate]'), function (x) { x.hidden = !gt || gt.value !== '1'; }); [].forEach.call(box.querySelectorAll('[data-show=welcome]'), function (x) { x.hidden = w !== '1'; }); var gd = box.querySelector('[data-f=guide]'); [].forEach.call(box.querySelectorAll('[data-show=guide]'), function (x) { x.hidden = !gd || gd.value !== '1'; }); };
    box.addEventListener('change', function () { sync(); clearTimeout(t); t = setTimeout(onChange, 700); });
    box.addEventListener('input', function (e) { if (e.target.matches('textarea, input')) { clearTimeout(t); t = setTimeout(onChange, 1200); } });
  }
  var GMODE = { ai: '✨ ' + T('Interactive'), fixed: '📌 ' + T('Fixed message') }, GTRIG = { mention: '@ ' + T('Mentions'), questions: '❓ ' + T('Questions'), all: '💬 ' + T('All') };

  if (page === 'botrun') {
    var BR = { c: null, groups: [], q: '', f: '', sort: 'on' };
    root.innerHTML = hero('🤖', 'Bot', 'Switch the bot on, choose where it answers, and set up each group. Changes are saved automatically.', '<a class="chip-btn" href="?p=bot">🧠 ' + T('Learning & test') + '</a>') + '<div id="brp"><div class="empty">' + T('Loading…') + '</div></div><div class="card" id="br-roles" hidden></div>';
    // 🎭 Roles: healer, emotional support, dream interpreter… on top of the regular bot, and the danger safeguard
    get('bot_roles').then(function (r) { var box = document.getElementById('br-roles'); if (!r || !r.ok || !Object.keys(r.cfg.roles || {}).length) return; var c = r.cfg; box.hidden = false;
      box.innerHTML = '<h3>🎭 ' + T('Bot roles') + '</h3><p class="muted small">' + T('One bot, several hats: a message with a role\'s words is answered in that role, and the chat keeps it for a few hours. Anything else is answered by the regular bot.') + '</p>'
        + '<label class="check"><input type="checkbox" id="ro-on"' + (c.on ? ' checked' : '') + '> ' + T('On') + '</label>'
        + '<div class="gcf"><label>' + T('Keep the role in a chat (hours)') + '<input id="ro-h" type="number" min="1" max="72" value="' + (+c.sticky_h) + '"></label><label>' + T('Strong models, in order (providers)') + '<input id="ro-s" dir="ltr" value="' + esc(c.strong) + '"></label></div>'
        + '<label>' + T('Role in groups') + '<select id="ro-g"><option value="">🤖 ' + T('Regular bot') + '</option>' + Object.keys(c.roles).map(function (k) { return '<option value="' + k + '"' + (c.group_role === k ? ' selected' : '') + '>' + esc((c.roles[k].icon || '') + ' ' + T(c.roles[k].label || k)) + '</option>'; }).join('') + '</select></label>'
        + '<label>' + T('Rules for every role (no invention, no repeating)') + '<textarea rows="4" dir="auto" id="ro-cm">' + esc(c.common || '') + '</textarea></label>'
        + '<p class="muted small">🧠 ' + T('Strong model now') + ': <b dir="ltr">' + esc(r.strong_now || T('none — turn on OpenCode Go or Gemini in AI providers')) + '</b></p>'
        + (function () { var S = r.stats || {}, ks = Object.keys(S); if (!ks.length) return ''; return '<div class="ro-st"><b>📊 ' + T('Last 7 days') + '</b>' + ks.map(function (k) { var x = c.roles[k] || { icon: '🤖', label: 'Regular bot' }; return '<span class="th-chip">' + esc(x.icon || '') + ' ' + esc(T(x.label || k)) + ': ' + fmt(S[k].sent) + ' ' + T('replies sent') + ' · ' + fmt(S[k].handoff) + ' ' + T('to a person') + '</span>'; }).join('') + '</div>'; })()
        + Object.keys(c.roles).map(function (k) { var x = c.roles[k]; return '<details class="ro-r"' + (x.on ? ' open' : '') + '><summary>' + esc(x.icon || '') + ' <b>' + esc(T(x.label || k)) + '</b></summary>'
          + '<label class="check"><input type="checkbox" data-ro="' + k + '" data-f="on"' + (x.on ? ' checked' : '') + '> ' + T('On') + '</label> <label class="check"><input type="checkbox" data-ro="' + k + '" data-f="strong"' + (x.strong ? ' checked' : '') + '> ' + T('Use a strong model') + '</label>'
          + '<label>' + T('Words that call this role (comma)') + '<textarea rows="2" dir="auto" data-ro="' + k + '" data-f="words">' + esc(x.words || '') + '</textarea></label>'
          + '<label>' + T('Instructions') + '<textarea rows="5" dir="auto" data-ro="' + k + '" data-f="rules">' + esc(x.rules || '') + '</textarea></label>'
          + '<label>' + T('Note in the first reply') + '<input dir="auto" data-ro="' + k + '" data-f="intro" value="' + esc(x.intro || '') + '"></label></details>'; }).join('')
        + '<details class="ro-r" open><summary>🔴 <b>' + T('Danger safeguard') + '</b></summary><p class="muted small">' + T('Words that show danger (self-harm, suicide, violence): the bot stops in that chat, sends the fixed support message (Message templates → crisis_reply) and alerts the team at once.') + '</p>'
          + '<label class="check"><input type="checkbox" id="ro-c-on"' + (c.crisis.on ? ' checked' : '') + '> ' + T('On') + '</label>'
          + '<label>' + T('Danger words (comma)') + '<textarea rows="3" dir="auto" id="ro-c-w">' + esc(c.crisis.words || '') + '</textarea></label>'
          + '<label>' + T('Alert to (group or number id; empty = the diagnosis group)') + '<input dir="ltr" id="ro-c-a" value="' + esc(c.crisis.alert || '') + '" placeholder="1203…@g.us"></label></details>'
        + '<div class="row"><button class="btn primary" id="ro-save">💾 ' + T('Save') + '</button></div>';
      box.querySelector('#ro-save').onclick = function () { var q = function (s) { return box.querySelector(s); }, roles = {};
        Object.keys(c.roles).forEach(function (k) { roles[k] = {}; [].forEach.call(box.querySelectorAll('[data-ro="' + k + '"]'), function (i) { roles[k][i.dataset.f] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value; }); });
        post('bot_roles', { on: q('#ro-on').checked ? 1 : 0, sticky_h: q('#ro-h').value, strong: q('#ro-s').value, group_role: q('#ro-g').value, common: q('#ro-cm').value, roles: roles, crisis: { on: q('#ro-c-on').checked ? 1 : 0, words: q('#ro-c-w').value, alert: q('#ro-c-a').value } })
          .then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); }); }; });
    get('bot_run').then(function (r) {
      var c = BR.c = r.run, el = document.getElementById('brp');
      var opt = function (v, l, d) { return '<label class="br-opt' + (c.mode === v ? ' on' : '') + '"><input type="radio" name="br-mode" value="' + v + '" ' + (c.mode === v ? 'checked' : '') + '><b>' + T(l) + '</b><small class="muted">' + T(d) + '</small></label>'; };
      el.innerHTML = '<details class="card fold" open><summary><b>⚙️ ' + T('General') + '</b> <span class="muted small">' + esc(r.account) + '</span></summary>'
        + '<div class="br-modes">' + opt('off', '⚪ Off', 'The bot does nothing.') + opt('draft', '📝 Suggest only', 'For every new customer message the bot writes a reply draft in the chat; a person reviews and sends it.') + opt('auto', '🤖 Automatic', 'The bot replies by itself when it is confident; anything else stays as a draft for a person.') + '</div>'
        + '<div class="seg" id="bp-scope">' + [['private', '💬 ' + T('Private chats only')], ['both', '💬 + 👥 ' + T('Private chats and groups')], ['groups', '👥 ' + T('Groups only')]].map(function (o) { return '<button type="button" data-s="' + o[0] + '" class="' + ((c.scope || 'private') === o[0] ? 'on' : '') + '">' + o[1] + '</button>'; }).join('') + '</div>'
        + '<div class="br-f"><label>' + T('Reply automatically only when confidence is at least') + '<select id="bp-conf">' + [0.6, 0.7, 0.8, 0.9].map(function (v) { return '<option value="' + v + '" ' + (Math.abs(c.min_conf - v) < 0.01 ? 'selected' : '') + '>' + Math.round(v * 100) + '%</option>'; }).join('') + '</select></label>'
        + '<label>' + T('Working hours') + '<span class="row"><select id="bp-from">' + Array.from({ length: 24 }, function (_, i) { return '<option value="' + i + '" ' + (c.from === i ? 'selected' : '') + '>' + i + ':00</option>'; }).join('') + '</select>→<select id="bp-to">' + Array.from({ length: 25 }, function (_, i) { return '<option value="' + i + '" ' + (c.to === i ? 'selected' : '') + '>' + i + ':00</option>'; }).join('') + '</select></span></label>'
        + '<label>' + T('Max automatic replies per hour (0 = no limit)') + '<input type="number" id="bp-ph" min="0" max="100000" value="' + c.per_hour + '"></label>'
        + '<label>' + T('The team counts as active in a chat for (hours) after they reply') + '<input type="number" id="bp-hp" min="0" max="72" value="' + c.human_pause + '"></label>'
            + '<label>' + T('While the team is active, wait this many minutes for them before the bot answers') + '<input type="number" id="bp-hw" min="0" max="240" value="' + (c.human_wait == null ? 10 : c.human_wait) + '"></label>'
            + '<label>' + T('A dream told in pieces: wait this many seconds after the last piece, then answer once (0 = answer each piece)') + '<input type="number" id="bp-dw" min="0" max="600" value="' + (c.dream_wait == null ? 120 : c.dream_wait) + '"></label>'
            + '<div class="gcf-wide sub-h">🛡️ ' + T('Protection against repeating and WhatsApp blocks') + '</div>'
            + '<label>' + T('Answer when the customer has stopped typing for (seconds)') + '<input type="number" id="bp-qs" min="3" max="120" value="' + (c.quiet_secs == null ? 6 : c.quiet_secs) + '"></label>'
            + '<label>' + T('Longest wait while they keep typing (seconds)') + '<input type="number" id="bp-gs" min="5" max="600" value="' + (c.gather_secs == null ? 45 : c.gather_secs) + '"></label>'
            + '<label>' + T('Most bot replies in one chat per hour (0 = no limit)') + '<input type="number" id="bp-cph" min="0" max="1000" value="' + (c.chat_per_hour === 0 ? 0 : (c.chat_per_hour || 12)) + '"></label>'
            + '<label>' + T('Most replies to one person in a group per hour (0 = no limit)') + '<input type="number" id="bp-gph" min="0" max="1000" value="' + (c.group_person_hour || 0) + '"></label>'
            + '<label>' + T('A reply this similar to an earlier one counts as a repeat (%)') + '<input type="number" id="bp-rs" min="40" max="100" value="' + Math.round((c.repeat_sim || 0.7) * 100) + '"></label>'
            + '<label class="check"><input type="checkbox" id="bp-rr" ' + (c.repeat_retry !== false ? 'checked' : '') + '> ' + T('On a repeat, try once more with a stronger model, then hand over to the team') + '</label>'
            + '<label>' + T('Clarifying questions in a row before handing over') + '<input type="number" id="bp-mc" min="1" max="5" value="' + (c.max_clarify || 1) + '"></label>'
            + '<label>' + T('Groups: seconds between two replies to the same person') + '<input type="number" id="bp-gg" min="0" max="3600" value="' + (c.group_gap === undefined ? 120 : c.group_gap) + '"></label>'
            + '<label>' + T('Typing time (seconds) — from / to, by reply length') + '<span class="row"><input type="number" id="bp-tmin" min="0.5" max="20" step="0.5" value="' + (c.typing_min || 2) + '" style="width:80px"><input type="number" id="bp-tmax" min="1" max="30" value="' + (c.typing_max || 8) + '" style="width:80px"></span></label>'
            + '<label class="gcf-wide">' + T('Words that stop the bot in a chat (the customer sends only this)') + '<input id="bp-sw" dir="auto" value="' + esc(c.stop_words == null ? 'وقف، توقف، كفاية، بس كده، stop' : c.stop_words) + '"></label>'
            + '<label>' + T('New number warm-up until (date)') + '<input type="date" id="bp-wu" value="' + esc(c.warmup_until || '') + '"></label>'
            + '<label>' + T('Most replies per hour during warm-up') + '<input type="number" id="bp-wph" min="1" max="60" value="' + (c.warmup_per_hour || 5) + '"></label>'
            + '<label class="check gcf-wide"><input type="checkbox" id="bp-cp" ' + (c.case_private !== false ? 'checked' : '') + '> 🩺 ' + T('Add private chats to the case record (for people who are cases)') + '</label>'
            + '<label class="check gcf-wide"><input type="checkbox" id="bp-cpn" ' + (c.case_private_new ? 'checked' : '') + '> ➕ ' + T('Open a new case for a person who describes symptoms in a private chat') + '</label>'
        + '<label>🧪 ' + T('Test numbers (the bot always answers them, even your own / team numbers)') + '<input id="bp-test" dir="ltr" value="' + esc((c.test || []).join(', ')) + '"></label></div>'
        + '<div class="row br-savebar"><span class="muted small" id="bp-msg">⚡ ' + T('Changes are saved automatically') + '</span></div></details>'
        + '<details class="card fold" open><summary><b>👥 ' + T('Groups') + '</b> <span class="muted small" id="bp-gcount"></span></summary>'
        + '<div class="row wrap gt-bar"><div class="search-xl"><span>🔎</span><input type="search" id="bp-q" placeholder="' + T('Search groups…') + '" dir="auto"></div>'
        + '<select id="bp-f"><option value="">' + T('All groups') + '</option><option value="on">🟢 ' + T('Bot on') + '</option><option value="off">⚪ ' + T('Bot off') + '</option><option value="ai">✨ ' + T('Interactive') + '</option><option value="fixed">📌 ' + T('Fixed message') + '</option><option value="learn">📚 ' + T('Learning') + '</option><option value="welcome">👋 ' + T('Welcome') + '</option></select>'
        + '<select id="bp-sort"><option value="on">' + T('Bot on first') + '</option><option value="activity">' + T('Last activity (newest)') + '</option><option value="messages">' + T('Most messages') + '</option><option value="members">' + T('Most members') + '</option><option value="name">' + T('Name') + '</option></select>'
        + '<div class="seg sm" id="bp-gmode"><button type="button" data-g="selected" class="' + (c.groups_mode !== 'all' ? 'on' : '') + '">' + T('Only the groups I choose') + '</button><button type="button" data-g="all" class="' + (c.groups_mode === 'all' ? 'on' : '') + '">' + T('All groups') + '</button></div></div>'
        + '<details class="gdef"><summary>' + T('Default for groups') + '</summary><div id="bp-gdef">' + gCfgForm(c.group_default || {}) + '</div></details>'
        + '<div id="bp-gt"><div class="empty">' + T('Loading groups…') + '</div></div></details>'
        + '<details class="card fold" id="bp-join" data-key="join"><summary><b>🚪 ' + T('Joining groups') + '</b></summary><div class="pad" id="bp-join-b"><div class="empty">' + T('Loading…') + '</div></div></details>'
        + '<details class="card fold"><summary><b>👥 ' + T('Team — never answered by the bot; their answers in groups are learned') + '</b></summary><div id="bp-team" class="muted small">' + T('Loading…') + '</div></details>'
        + '<details class="card fold"><summary><b>🧾 ' + T('What the bot did') + '</b> <span class="muted small">(' + fmt(r.log.length) + ')</span></summary><div class="row wrap"><div class="search-xl"><span>🔎</span><input type="search" id="bp-lq" placeholder="' + T('Search the log…') + '" dir="auto"></div><select id="bp-lr"><option value="">' + T('All') + '</option><option value="sent">✅ ' + T('Sent') + '</option><option value="handoff">🙋 ' + T('Sent and handed over to a person') + '</option><option value="draft">📝 ' + T('Draft') + '</option><option value="skipped">⏭️ ' + T('Skipped') + '</option></select></div><div id="bp-log"></div></details>';
      // ---- general settings: auto-save
      var lastMode = c.mode, msg = document.getElementById('bp-msg'), st = null;
      function saveGen() {
        var mode = el.querySelector('input[name=br-mode]:checked').value;
        if (mode === 'auto' && lastMode !== 'auto' && !confirm(T('Switch on automatic replies? The bot will answer customers by itself when it is confident.'))) { el.querySelector('input[name=br-mode][value="' + lastMode + '"]').checked = true; syncModes(); return; }
        msg.textContent = T('Saving…');
        post('bot_run', { mode: mode, scope: document.querySelector('#bp-scope .on').dataset.s, groups_mode: document.querySelector('#bp-gmode .on').dataset.g, group_default: gCfgRead(document.getElementById('bp-gdef')),
          min_conf: document.getElementById('bp-conf').value, from: document.getElementById('bp-from').value, to: document.getElementById('bp-to').value, per_hour: document.getElementById('bp-ph').value, group_person_hour: (document.getElementById('bp-gph') || {}).value, human_pause: document.getElementById('bp-hp').value, human_wait: document.getElementById('bp-hw').value, dream_wait: document.getElementById('bp-dw').value, quiet_secs: document.getElementById('bp-qs').value, gather_secs: document.getElementById('bp-gs').value, chat_per_hour: document.getElementById('bp-cph').value, repeat_sim: (+document.getElementById('bp-rs').value || 70) / 100, repeat_retry: document.getElementById('bp-rr').checked ? 1 : 0, max_clarify: document.getElementById('bp-mc').value, group_gap: (document.getElementById('bp-gg') || {}).value, typing_min: document.getElementById('bp-tmin').value, typing_max: document.getElementById('bp-tmax').value, stop_words: document.getElementById('bp-sw').value, warmup_until: document.getElementById('bp-wu').value, warmup_per_hour: document.getElementById('bp-wph').value, case_private: document.getElementById('bp-cp').checked ? 1 : 0, case_private_new: document.getElementById('bp-cpn').checked ? 1 : 0, test: document.getElementById('bp-test').value })
          .then(function (x) { if (!x.ok) { msg.textContent = '⚠️ ' + x.error; return; } lastMode = mode; c = BR.c = x.run; msg.textContent = '✓ ' + T('Saved automatically') + ' · ' + new Date().toLocaleTimeString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' }); drawGroups(); });
      }
      function later() { clearTimeout(st); st = setTimeout(saveGen, 900); }
      function syncModes() { [].forEach.call(el.querySelectorAll('.br-opt'), function (l) { l.classList.toggle('on', l.querySelector('input').checked); }); }
      el.querySelector('.br-modes').addEventListener('change', function () { syncModes(); later(); });
      ['bp-conf', 'bp-from', 'bp-to'].forEach(function (id) { document.getElementById(id).onchange = later; });
      ['bp-ph', 'bp-hp', 'bp-test'].forEach(function (id) { document.getElementById(id).oninput = later; });
      ['bp-scope', 'bp-gmode'].forEach(function (id) { document.getElementById(id).onclick = function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); later(); }; });
      gCfgWire(document.getElementById('bp-gdef'), saveGen);
      // ---- groups table
      get('groups').then(function (gr) { BR.groups = (gr.groups || []).filter(function (g) { return !g.left; }); drawGroups(); });
      function drawGroups() {
        var box = document.getElementById('bp-gt'); if (!BR.groups.length) return;
        var sel = c.groups || {}, all = c.groups_mode === 'all', q = (document.getElementById('bp-q').value || '').toLowerCase(), f = document.getElementById('bp-f').value, srt = document.getElementById('bp-sort').value;
        var rows = BR.groups.map(function (g) { var on = !!sel[g.jid] || all; return Object.assign({}, g, { on: on, explicit: !!sel[g.jid], cfg: sel[g.jid] || c.group_default || {} }); })
          .filter(function (g) { return (!q || String(g.name).toLowerCase().indexOf(q) !== -1) && (!f || (f === 'on' ? g.on : f === 'off' ? !g.on : f === 'learn' ? g.on && g.cfg.learn : f === 'welcome' ? g.on && g.cfg.welcome : g.on && (g.cfg.mode || 'ai') === f)); });
        rows.sort(function (a, b) { return srt === 'on' ? (b.on - a.on) || (b.messages - a.messages) : srt === 'members' ? b.members - a.members : srt === 'messages' ? b.messages - a.messages : srt === 'name' ? String(a.name).localeCompare(String(b.name)) : String(b.last || '').localeCompare(String(a.last || '')); });
        document.getElementById('bp-gcount').textContent = '(' + fmt(BR.groups.filter(function (g) { return sel[g.jid] || all; }).length) + ' ' + T('with the bot on') + ' / ' + fmt(BR.groups.length) + ')';
        var P = pg('botgroups', rows, drawGroups, 20);
        box.innerHTML = '<table class="tbl gt"><thead><tr><th>' + T('Bot') + '</th><th>' + T('Group') + '</th><th>' + T('Members') + '</th><th>' + T('Messages (2 months)') + '</th><th>' + T('Last activity') + '</th><th>' + T('Reply type') + '</th><th>' + T('When') + '</th><th>📚</th><th>👋</th><th></th></tr></thead><tbody>'
          + P.rows.map(function (g) { return '<tr class="' + (g.on ? 'on' : '') + '"><td><label class="sw"><input type="checkbox" data-gt-on="' + esc(g.jid) + '" data-name="' + esc(g.name) + '" ' + (g.on ? 'checked' : '') + (all && !g.explicit ? ' disabled title="' + T('All groups') + '"' : '') + '><i></i></label></td>'
            + '<td><a class="gr-n" href="?p=botgroup&g=' + encodeURIComponent(g.jid) + '">' + avatar(g.jid, g.name) + '<b dir="auto">' + esc(g.name) + '</b></a></td><td>' + fmt(g.members) + '</td><td>' + fmt(g.messages) + '</td><td>' + (g.last ? esc(day(g.last)) : '—') + '</td>'
            + '<td>' + (g.on ? GMODE[g.cfg.mode || 'ai'] : '—') + '</td><td>' + (g.on ? GTRIG[g.cfg.trigger || 'mention'] : '—') + '</td><td>' + (g.on && g.cfg.learn ? '✅' : '') + '</td><td>' + (g.on && g.cfg.welcome ? '✅' : '') + '</td>'
            + '<td><a class="chip-btn sm" href="?p=botgroup&g=' + encodeURIComponent(g.jid) + '">⚙️ ' + T('Open') + '</a></td></tr>'; }).join('') + '</tbody></table>' + P.html;
      }
      ['bp-q'].forEach(function (id) { document.getElementById(id).oninput = function () { pgReset('botgroups'); drawGroups(); }; });
      ['bp-f', 'bp-sort'].forEach(function (id) { document.getElementById(id).onchange = function () { pgReset('botgroups'); drawGroups(); }; });
      document.getElementById('bp-gt').addEventListener('change', function (e) { var cb = e.target.closest('[data-gt-on]'); if (!cb) return;
        post('bot_group_set', { jid: cb.dataset.gtOn, name: cb.dataset.name, on: cb.checked ? 1 : 0 }).then(function (x) { if (!x.ok) { toast('⚠️ ' + x.error); cb.checked = !cb.checked; return; } c.groups = c.groups || {}; if (cb.checked) c.groups[cb.dataset.gtOn] = x.group; else delete c.groups[cb.dataset.gtOn]; if (x.scope) c.scope = x.scope; [].forEach.call(document.querySelectorAll('#bp-scope button'), function (b) { b.classList.toggle('on', b.dataset.s === c.scope); }); toast('✓ ' + T('Saved')); drawGroups(); }); });
      // ---- team (on open)
      el.querySelectorAll('details.fold')[2].addEventListener('toggle', function () { if (!this.open || this.dataset.l) return; this.dataset.l = 1;
        // Joining groups: the bot adds people who ask to join (or rejoin) — which groups, and what to do with people an admin removed. Saved automatically.
        (function joinCard() { var jb = document.getElementById('bp-join-b'); if (!jb) return;
          get('bot_join_cfg').then(function (j) { if (!j.ok) { jb.textContent = j.error || ''; return; } var J = j.cfg;
            if (!j.groups.length) { jb.innerHTML = '<div class="jg-empty"><span>🔒</span><b>' + T('This number is not an admin of any group.') + '</b><small>' + T('Only a group admin can add people. Make this number an admin of a group, then come back.') + '</small></div>'; return; }
            var sel = {}, filter = 'all', q = '', timer = null; J.groups.forEach(function (g) { sel[g] = 1; });
            var nrmq = function (s) { return String(s || '').toLowerCase().replace(/[ً-ٰٟـ]/g, '').replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه'); };
            var order = j.groups.slice().sort(function (a, b) { return (sel[b.jid] ? 1 : 0) - (sel[a.jid] ? 1 : 0) || b.members - a.members; }); // chosen first (fixed while you click)
            jb.innerHTML = '<div class="jg">'
              + '<div class="jg-hero"><span class="jg-ic">🚪</span><div class="jg-ht"><b>' + T('Let the bot add people to groups') + '</b><small>' + T('When someone asks to join or rejoin a group, or says the link does not work, the bot adds them and tells them what happened.') + '</small></div>'
              + '<label class="jg-sw"><input type="checkbox" id="bj-on"' + (J.on ? ' checked' : '') + '><i></i><span id="bj-onl"></span></label></div>'
              + '<div class="jg-warn" id="bj-warn" hidden></div>'
              + '<div class="jg-body" id="bj-body">'
              + '<section class="jg-sec"><div class="jg-sh"><span class="jg-n1">1</span><div><b>' + T('Which groups may the bot add people to?') + '</b><small>' + T('Only groups where this number is an admin are listed.') + '</small></div>'
              + '<div class="jg-stats"><span class="jg-pill" id="bj-cnt"></span><span class="jg-pill" title="' + esc(T('Daily limit that protects the number')) + '">⏱ ' + T('Left today') + ' <b>' + j.left + '</b></span></div></div>'
              + '<div class="jg-bar"><div class="search-xl"><span>🔎</span><input type="search" id="bj-q" placeholder="' + esc(T('Search groups…')) + '" dir="auto"></div>'
              + '<div class="seg sm" id="bj-f"><button type="button" data-f="all" class="on">' + T('All') + ' <i>' + j.groups.length + '</i></button><button type="button" data-f="sel">' + T('Chosen') + ' <i id="bj-fc"></i></button></div>'
              + '<button type="button" class="chip-btn sm" id="bj-all">☑ ' + T('Choose all shown') + '</button><button type="button" class="chip-btn sm" id="bj-none">☐ ' + T('Clear') + '</button></div>'
              + '<div class="jg-list" id="bj-list"></div></section>'
              + '<section class="jg-sec"><div class="jg-sh"><span class="jg-n1">2</span><div><b>' + T('Someone an admin removed from the group asks to come back') + '</b><small>' + T('People who left by themselves are always added like anyone else.') + '</small></div></div>'
              + '<div class="jg-opts"><label class="jg-opt' + (J.removed !== 'team' ? ' on' : '') + '"><input type="radio" name="bj-rm" value="readd"' + (J.removed !== 'team' ? ' checked' : '') + '><span class="jg-oi">↩️</span><b>' + T('Add them back') + '</b><small>' + T('Directly, or with WhatsApp\'s personal invitation if their privacy blocks it.') + '</small></label>'
              + '<label class="jg-opt' + (J.removed === 'team' ? ' on' : '') + '"><input type="radio" name="bj-rm" value="team"' + (J.removed === 'team' ? ' checked' : '') + '><span class="jg-oi">🙋</span><b>' + T('Leave it to the team') + '</b><small>' + T('The bot says the team will add them and stops there.') + '</small></label></div></section>'
              + '<details class="jg-how"><summary>💡 ' + T('How it works') + '</summary><ol><li>' + T('The person writes to the bot that they want to join, or that the link does not work.') + '</li><li>' + T('The bot checks the group history: did they leave by themselves, or did an admin remove them?') + '</li><li>' + T('It adds them directly; if their privacy blocks that, it sends WhatsApp\'s personal invitation. The public link is never sent to someone who was removed — WhatsApp refuses it.') + '</li><li>' + T('Works in automatic mode and working hours only. The wording of the replies: Settings → Message templates (Bot: …).') + '</li></ol></details>'
              + '</div><div class="jg-foot"><span class="muted small" id="bj-msg">⚡ ' + T('Changes are saved automatically') + '</span></div></div>';
            var $ = function (id) { return document.getElementById(id); };
            function chosen() { return Object.keys(sel); }
            function drawList() { var items = order.filter(function (g) { return (filter === 'all' || sel[g.jid]) && (!q || nrmq(g.name || g.jid).indexOf(q) !== -1); });
              $('bj-list').innerHTML = items.length ? items.map(function (g) { return '<label class="jg-g' + (sel[g.jid] ? ' on' : '') + '"><input type="checkbox" value="' + esc(g.jid) + '"' + (sel[g.jid] ? ' checked' : '') + '><i class="jg-ck">✓</i><span class="jg-gn" dir="auto">' + esc(g.name || g.jid) + '</span><small title="' + esc(T('Members')) + '">👤 ' + fmt(g.members) + '</small></label>'; }).join('') : '<div class="empty">' + T('No groups match.') + '</div>';
              $('bj-cnt').innerHTML = '✅ ' + T('Chosen') + ' <b>' + fmt(chosen().length) + '</b> / ' + fmt(j.groups.length); $('bj-fc').textContent = chosen().length; }
            function sync() { var on = $('bj-on').checked; $('bj-onl').textContent = on ? T('On') : T('Off'); $('bj-body').classList.toggle('off', !on);
              var w = $('bj-warn'); w.hidden = !(on && !chosen().length); w.textContent = '⚠️ ' + T('Choose at least one group, otherwise the bot cannot add anyone.'); }
            function save() { clearTimeout(timer); timer = setTimeout(function () { $('bj-msg').textContent = T('Saving…');
              post('bot_join_cfg', { on: $('bj-on').checked ? 1 : 0, removed: (jb.querySelector('input[name=bj-rm]:checked') || {}).value || 'readd', groups: chosen() })
                .then(function (r) { $('bj-msg').textContent = r.ok ? '✓ ' + T('Saved automatically') + ' · ' + new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : '⚠️ ' + (r.error || T('Failed')); }); }, 700); }
            $('bj-on').onchange = function () { sync(); save(); };
            $('bj-q').oninput = function () { q = nrmq(this.value.trim()); drawList(); };
            $('bj-f').onclick = function (e) { var b = e.target.closest('[data-f]'); if (!b) return; filter = b.dataset.f; [].forEach.call($('bj-f').children, function (x) { x.classList.toggle('on', x === b); }); drawList(); };
            $('bj-list').onchange = function (e) { var i = e.target; if (i.type !== 'checkbox') return; if (i.checked) sel[i.value] = 1; else delete sel[i.value]; i.closest('.jg-g').classList.toggle('on', i.checked); $('bj-cnt').innerHTML = '✅ ' + T('Chosen') + ' <b>' + fmt(chosen().length) + '</b> / ' + fmt(j.groups.length); $('bj-fc').textContent = chosen().length; sync(); save(); };
            $('bj-all').onclick = function () { [].forEach.call($('bj-list').querySelectorAll('input'), function (i) { sel[i.value] = 1; }); drawList(); sync(); save(); };
            $('bj-none').onclick = function () { [].forEach.call($('bj-list').querySelectorAll('input'), function (i) { delete sel[i.value]; }); drawList(); sync(); save(); };
            jb.querySelector('.jg-opts').onchange = function () { [].forEach.call(jb.querySelectorAll('.jg-opt'), function (o) { o.classList.toggle('on', o.querySelector('input').checked); }); save(); };
            drawList(); sync(); }); })();
        get('bot_team').then(function (t) { var b = document.getElementById('bp-team'); if (!t.ok) { b.textContent = '⚠️ ' + (t.error || ''); return; }
          var person = function (p) { return '<span class="tm-p"><b dir="auto">' + esc(p.name || '—') + '</b> <span dir="ltr">' + (p.hidden ? T('hidden number') : '+' + esc(p.phone)) + '</span></span>'; };
          b.innerHTML = t.groups.map(function (g) { return '<div class="tm-g"><b dir="auto">👥 ' + esc(g.name) + '</b><div class="tm-list">' + g.admins.map(person).join('') + '</div></div>'; }).join('') + '<div class="tm-g"><b>📱 ' + T('Our numbers') + '</b><div class="tm-list">' + t.own.map(person).join('') + '</div></div><div class="tm-g"><b>➕ ' + T('Added by hand') + '</b><div class="tm-list">' + (t.manual.map(person).join('') || '—') + '</div></div>'; }); });
      // ---- log
      function logDraw() { var q = (document.getElementById('bp-lq').value || '').toLowerCase(), rf = document.getElementById('bp-lr').value;
        var rows = r.log.filter(function (x) { return (!rf || x.result === rf) && (!q || JSON.stringify([x.q, x.reply, x.name, x.why]).toLowerCase().indexOf(q) !== -1); }), P = pg('bplog', rows, logDraw, 20);
        document.getElementById('bp-log').innerHTML = logTable(P.rows) + P.html; }
      document.getElementById('bp-lq').oninput = function () { pgReset('bplog'); logDraw(); }; document.getElementById('bp-lr').onchange = function () { pgReset('bplog'); logDraw(); };
      // History: extra filters — where (private / group / cache), dates, sort
      document.getElementById('bp-lr').insertAdjacentHTML('afterend', '<select id="bp-lt"><option value="">' + T('Everywhere') + '</option><option value="private">💬 ' + T('Private chats') + '</option><option value="group">👥 ' + T('Groups') + '</option><option value="cache">♻️ ' + T('From the cache') + '</option></select>');
      document.getElementById('bp-log').insertAdjacentHTML('beforebegin', fbar('bp-lf', { sort: [['new', 'Newest first'], ['old', 'Oldest first'], ['conf', 'Highest confidence']], dates: true }));
      var logDraw0 = logDraw; logDraw = function () { var q = (document.getElementById('bp-lq').value || '').toLowerCase(), rf = document.getElementById('bp-lr').value, lt = document.getElementById('bp-lt').value, f = fval('bp-lf');
        var rows = r.log.filter(function (x) { return (!rf || x.result === rf) && (!lt || (lt === 'group' ? x.group : lt === 'cache' ? x.model === 'cache' : !x.group)) && inRange(x.at, f.from, f.to) && (!q || JSON.stringify([x.q, x.reply, x.name, x.why]).toLowerCase().indexOf(q) !== -1); });
        if (f.sort === 'old') rows = rows.slice().reverse(); if (f.sort === 'conf') rows = rows.slice().sort(function (a, b) { return (b.confidence || 0) - (a.confidence || 0); });
        var P = pg('bplog', rows, logDraw, 20); document.getElementById('bp-log').innerHTML = '<div class="muted small">' + fmt(rows.length) + ' ' + T('entries') + '</div>' + logTable(P.rows) + P.html; };
      document.getElementById('bp-lt').onchange = function () { pgReset('bplog'); logDraw(); };
      document.getElementById('bp-lf').addEventListener('change', function () { pgReset('bplog'); logDraw(); });
      document.getElementById('bp-lf').addEventListener('click', function (e) { var b = e.target.closest('[data-days]'); if (!b) return; var d = new Date(Date.now() - b.dataset.days * 864e5).toISOString().slice(0, 10); this.querySelector('[data-f=from]').value = d; this.querySelector('[data-f=to]').value = ''; pgReset('bplog'); logDraw(); });
      logDraw();
      // Drafts and paused chats get their own tabs
      el.insertAdjacentHTML('beforeend', '<details class="card fold" data-key="chats"><summary><b>💬 ' + T('Chats') + '</b> <span class="muted small" id="bp-cn"></span></summary><div class="row wrap"><div class="search-xl"><span>🔎</span><input type="search" id="bp-cq" placeholder="' + T('Search name or number…') + '" dir="auto"></div>'
        + '<select id="bp-ct"><option value="">' + T('Private chats and groups') + '</option><option value="p">👤 ' + T('Private chats') + '</option><option value="g">👥 ' + T('Groups') + '</option></select>'
        + '<select id="bp-cf"><option value="">' + T('All') + '</option><option value="on">🟢 ' + T('Bot on') + '</option><option value="off">⏸ ' + T('Bot off') + '</option><option value="wait">✉️ ' + T('Waiting for a reply') + '</option></select>'
        + '<select id="bp-cs"><option value="last">' + T('Last message (newest)') + '</option><option value="count">' + T('Most messages') + '</option><option value="name">' + T('Name') + '</option></select>'
        + '<button class="chip-btn sm" id="bp-call-on">🟢 ' + T('Bot on for shown') + '</button><button class="chip-btn sm" id="bp-call-off">⏸ ' + T('Bot off for shown') + '</button></div><div id="bp-cl"><div class="empty">' + T('Loading…') + '</div></div></details>');
      var CH_SHOWN = [], DR_SHOWN = [], CH = [], SEL = { ch: {}, dr: {}, pa: {} };
      // Multi-select: a checkbox per row, "select all shown", and an action bar for the selected rows.
      function selList(kind) { return kind === 'ch' ? CH_SHOWN : kind === 'dr' ? DR_SHOWN : r.paused_list; }
      function selBar(kind, actions) { var n = Object.keys(SEL[kind]).length, list = selList(kind), inList = list.filter(function (x) { return SEL[kind][x.jid]; }).length, all = list.length > 0 && inList === list.length;
        return '<div class="sel-bar' + (n ? ' on' : '') + '"><label class="check"><input type="checkbox" data-selall="' + kind + '"' + (all ? ' checked' : '') + (inList && !all ? ' data-half="1"' : '') + '> ' + T('Select all shown') + (list.length ? ' (' + fmt(list.length) + ')' : '') + '</label><b>' + (n ? fmt(n) + ' ' + T('selected') : '') + '</b>'
          + actions.map(function (a) { return '<button class="chip-btn sm" data-bulk="' + kind + ':' + a[0] + '"' + (n ? '' : ' disabled') + '>' + a[1] + '</button>'; }).join('') + (n ? '<button class="link-btn" data-selclear="' + kind + '">' + T('Clear') + '</button>' : '') + '</div>'; }
      var selCell = function (kind, id) { return '<td class="sel-c"><input type="checkbox" data-sel="' + kind + '" value="' + esc(id) + '"' + (SEL[kind][id] ? ' checked' : '') + '></td>'; };
      function chShown() { var q = (document.getElementById('bp-cq').value || '').toLowerCase(), f = document.getElementById('bp-cf').value, s = document.getElementById('bp-cs').value, ty = document.getElementById('bp-ct').value;
        var rows = CH.filter(function (x) { return (!ty || (ty === 'g') === !!x.group) && (!f || (f === 'on' ? x.on : f === 'off' ? !x.on : x.last_dir === 'in')) && (!q || (x.name + ' ' + x.phone).toLowerCase().indexOf(q) !== -1); });
        return rows.sort(function (a, b) { return s === 'count' ? b.count - a.count : s === 'name' ? String(a.name || '~').localeCompare(String(b.name || '~')) : String(b.last).localeCompare(String(a.last)); }); }
      function chDraw() { var rows = chShown(), P = pg('bpch', rows, chDraw, 25);
        document.getElementById('bp-cn').textContent = '(' + fmt(CH.filter(function (x) { return x.on; }).length) + ' / ' + fmt(CH.length) + ')';
        CH_SHOWN = rows;
        document.getElementById('bp-cl').innerHTML = selBar('ch', [['on', '🟢 ' + T('Bot on')], ['off', '⏸ ' + T('Bot off')]]) + '<div class="muted small">' + fmt(rows.length) + ' ' + T('chats') + '</div>' + (P.rows.length ? '<table class="tbl"><thead><tr><th></th><th>' + T('Bot') + '</th><th>' + T('Chat') + '</th><th>' + T('Last message') + '</th><th>' + T('Messages') + '</th><th></th></tr></thead><tbody>' + P.rows.map(function (x) {
          return '<tr class="' + (x.on ? '' : 'muted') + (SEL.ch[x.jid] ? ' picked' : '') + '">' + selCell('ch', x.jid) + '<td><label class="inv-sw"><input type="checkbox" data-chat-on="' + esc(x.jid) + '" ' + (x.on ? 'checked' : '') + '><i></i></label></td><td><b dir="auto">' + (x.group ? '👥 ' : '👤 ') + esc(x.name || T('No name')) + '</b>' + (x.group ? ' <span class="tag">' + T('Group') + '</span>' : '') + ((x.jids || []).length > 1 ? ' <span class="tag" title="' + T('merged chats with the same number') + '">🔗 ' + x.jids.length + '</span>' : '') + (x.team ? ' <span class="tag">' + T('team') + '</span>' : '') + (x.test ? ' <span class="tag">🧪</span>' : '') + '<div class="muted small" dir="ltr">' + (x.phone ? '+' + esc(x.phone) : T('Number hidden by WhatsApp')) + '</div></td>'
            + '<td class="small"><span dir="auto">' + (x.last_dir === 'in' ? '⬅️ ' : '➡️ ') + esc(x.last_text) + '</span><div class="muted">' + esc(when(x.last)) + (x.paused_by ? ' · ⏸ ' + esc(T(x.paused_by)) : '') + '</div></td><td>' + fmt(x.count) + '</td><td><a class="chip-btn sm" href="?p=inbox#' + encodeURIComponent(x.jid) + '">💬</a></td></tr>'; }).join('') + '</tbody></table>' + P.html : '<div class="empty">' + T('No chats.') + '</div>'); }
      function chLoad() { get('bot_chats').then(function (x) { if (!x.ok) return fail(document.getElementById('bp-cl'), x); CH = x.chats; chDraw(); }); }
      // a person may have several WhatsApp IDs for one number: the switch applies to all of them
      function chSet(jids, on) { var all = [], groups = []; CH.forEach(function (x) { if (jids.indexOf(x.jid) === -1) return; if (x.group) groups.push(x); else all = all.concat(x.jids || [x.jid]); });
        // groups: switch the bot on/off in the group (Groups settings), one after another
        var gp = groups.reduce(function (p, g) { return p.then(function () { return post('bot_group_set', { jid: g.jid, on: on ? 1 : 0, name: g.name }).then(function (x) { if (!x || !x.ok) throw new Error((x && x.error) || T('Failed')); g.on = on; }); }); }, Promise.resolve());
        if (!all.length) return gp.then(function () { chDraw(); });
        // ONE request for all of them (parallel requests hit the hosting limit and overwrite each other)
        return gp.then(function () { return post('bot_pause', { jids: all, pause: on ? 0 : 1 }); }).then(function (x) { if (!x || !x.ok) throw new Error((x && x.error) || T('Failed'));
          CH.forEach(function (y) { if (jids.indexOf(y.jid) !== -1) { y.on = on; y.paused_by = on ? '' : 'dashboard'; } }); chDraw(); }); }
      ['bp-cq'].forEach(function (id) { document.getElementById(id).oninput = function () { pgReset('bpch'); chDraw(); }; });
      ['bp-cf', 'bp-cs', 'bp-ct'].forEach(function (id) { document.getElementById(id).onchange = function () { pgReset('bpch'); chDraw(); }; });
      var redraw = { ch: function () { chDraw(); }, dr: function () { drDraw(); }, pa: function () { paDraw(); } };
      new MutationObserver(function () { [].forEach.call(el.querySelectorAll('[data-half]'), function (i) { i.indeterminate = true; }); }).observe(el, { childList: true, subtree: true });
      el.addEventListener('change', function (e) { var k;
        if ((k = e.target.closest('[data-sel]'))) { var kd = k.dataset.sel; if (k.checked) SEL[kd][k.value] = 1; else delete SEL[kd][k.value]; redraw[kd](); return; }
        if ((k = e.target.closest('[data-selall]'))) { var kd2 = k.dataset.selall, on = k.checked;
          selList(kd2).forEach(function (x) { if (on) SEL[kd2][x.jid] = 1; else delete SEL[kd2][x.jid]; }); redraw[kd2](); return; } });
      el.addEventListener('click', function (e) { var b;
        if ((b = e.target.closest('[data-selclear]'))) { SEL[b.dataset.selclear] = {}; redraw[b.dataset.selclear](); return; }
        if (!(b = e.target.closest('[data-bulk]'))) return;
        var parts = b.dataset.bulk.split(':'), kind = parts[0], act = parts[1], ids = Object.keys(SEL[kind]); if (!ids.length) return;
        if (!confirm(b.textContent.trim() + ': ' + ids.length + ' ' + T('chats?'))) return;
        b.disabled = true;
        var failed = function (err) { b.disabled = false; toast('⚠️ ' + (err && err.message || T('Failed'))); redraw[kind](); };
        var okd = function (x) { if (!x || !x.ok) throw new Error((x && x.error) || T('Failed')); return x; };
        if (kind === 'ch') return chSet(ids, act === 'on').then(function () { SEL.ch = {}; chDraw(); toast('✓ ' + ids.length); }).catch(failed);
        if (kind === 'dr') return post('bot_draft', { jids: ids }).then(okd).then(function () { r.drafts_list = r.drafts_list.filter(function (x) { return !SEL.dr[x.jid]; }); SEL.dr = {}; drDraw(); toast('✓ ' + ids.length); }).catch(failed);
        if (kind === 'pa') return post('bot_pause', { jids: ids, pause: 0 }).then(okd).then(function () { r.paused_list = r.paused_list.filter(function (x) { return !SEL.pa[x.jid]; }); SEL.pa = {}; paDraw(); CH.forEach(function (x) { if (ids.indexOf(x.jid) !== -1) x.on = true; }); chDraw(); toast('▶ ' + ids.length); }).catch(failed); });
      el.addEventListener('change', function (e) { var c = e.target.closest('[data-chat-on]'); if (!c) return; c.disabled = true; chSet([c.dataset.chatOn], c.checked).catch(function (err) { toast('⚠️ ' + err.message); chDraw(); }).then(function () { toast(c.checked ? '🟢 ' + T('Bot on for this chat') : '⏸ ' + T('Bot off for this chat')); }); });
      el.addEventListener('click', function (e) { var b = e.target.closest('#bp-call-on, #bp-call-off'); if (!b) return; var on = b.id === 'bp-call-on', rows = chShown().filter(function (x) { return x.on !== on; });
        if (!rows.length) return toast(T('Nothing to change'));
        if (confirm((on ? T('Turn the bot ON for') : T('Turn the bot OFF for')) + ' ' + rows.length + ' ' + T('chats?'))) chSet(rows.map(function (x) { return x.jid; }), on).then(function () { toast('✓ ' + rows.length); }).catch(function (err) { toast('⚠️ ' + err.message); chDraw(); }); });
      chLoad();
      el.insertAdjacentHTML('beforeend', '<details class="card fold" data-key="drafts"><summary><b>📝 ' + T('Drafts waiting') + '</b> <span class="muted small">(' + fmt(r.drafts_list.length) + ')</span></summary><div class="row wrap"><div class="search-xl"><span>🔎</span><input type="search" id="bp-dq" placeholder="' + T('Search drafts…') + '" dir="auto"></div></div><div id="bp-dr"></div></details>'
        + '<details class="card fold" data-key="paused"><summary><b>⏸ ' + T('Paused chats') + '</b> <span class="muted small">(' + fmt(r.paused_list.length) + ')</span></summary><div id="bp-pa"></div></details>');
      function drDraw() { var q = (document.getElementById('bp-dq').value || '').toLowerCase(), rows = r.drafts_list.filter(function (x) { return !q || JSON.stringify([x.q, x.reply, x.name]).toLowerCase().indexOf(q) !== -1; }), P = pg('bpdr', rows, drDraw, 15); DR_SHOWN = rows;
        document.getElementById('bp-dr').innerHTML = selBar('dr', [['drop', '✕ ' + T('Dismiss drafts')]]) + (P.rows.length ? '<table class="tbl"><thead><tr><th></th><th>' + T('When') + '</th><th>' + T('Chat') + '</th><th>' + T('Customer') + '</th><th>' + T('Draft') + '</th><th>' + T('Why not sent') + '</th><th></th></tr></thead><tbody>' + P.rows.map(function (x) {
          return '<tr' + (SEL.dr[x.jid] ? ' class="picked"' : '') + '>' + selCell('dr', x.jid) + '<td class="small">' + esc(when(x.at)) + '</td><td dir="auto">' + (x.group ? '👥 ' : '💬 ') + esc(x.name || x.jid) + '</td><td dir="auto" class="small">' + esc(String(x.q).slice(0, 160)) + '</td><td dir="auto" class="small">' + esc(String(x.reply).slice(0, 220)) + '</td><td class="small muted">' + esc(T(x.why || '')) + '</td><td><a class="chip-btn sm" href="?p=inbox#' + encodeURIComponent(x.jid) + '">💬 ' + T('Open chat') + '</a> <button class="link-btn bad-t" data-drop="' + esc(x.jid) + '">✕</button></td></tr>'; }).join('') + '</tbody></table>' + P.html : '<div class="empty">' + T('No drafts waiting.') + '</div>'); }
      function paDraw() { var now = Date.now();
        document.getElementById('bp-pa').innerHTML = selBar('pa', [['resume', '▶ ' + T('Resume the bot')]]) + (r.paused_list.length ? '<table class="tbl"><thead><tr><th></th><th>' + T('Chat') + '</th><th>' + T('Since') + '</th><th>' + T('By') + '</th><th>' + T('Until') + '</th><th></th></tr></thead><tbody>' + r.paused_list.map(function (x) { var over = x.until && Date.parse(x.until) < now;
          return '<tr class="' + (over ? 'muted' : '') + (SEL.pa[x.jid] ? ' picked' : '') + '">' + selCell('pa', x.jid) + '<td><b dir="auto">' + (x.group ? '👥 ' : '👤 ') + esc(x.name || T('No name')) + '</b><div class="muted small" dir="ltr">' + (x.group ? T('Group') : (x.phone ? '+' + esc(x.phone) : T('Number hidden by WhatsApp'))) + '</div></td><td class="small">' + esc(when(x.at)) + '</td><td class="small">' + esc(T(x.by)) + '</td><td class="small">' + (x.until ? esc(when(x.until)) + (over ? ' · ' + T('ended') : '') : T('until /start')) + '</td><td><a class="chip-btn sm" href="?p=inbox#' + encodeURIComponent(x.jid) + '">💬</a> <button class="chip-btn sm" data-resume="' + esc(x.jid) + '">▶ ' + T('Resume the bot') + '</button></td></tr>'; }).join('') + '</tbody></table>' : '<div class="empty">' + T('The bot is not paused in any chat.') + '</div>'); }
      document.getElementById('bp-dq').oninput = function () { pgReset('bpdr'); drDraw(); }; drDraw(); paDraw();
      el.addEventListener('click', function (e) { var b;
        if ((b = e.target.closest('[data-drop]'))) post('bot_draft', { jid: b.dataset.drop }).then(function () { r.drafts_list = r.drafts_list.filter(function (x) { return x.jid !== b.dataset.drop; }); drDraw(); });
        if ((b = e.target.closest('[data-resume]'))) post('bot_pause', { jid: b.dataset.resume, pause: 0 }).then(function () { r.paused_list = r.paused_list.filter(function (x) { return x.jid !== b.dataset.resume; }); paDraw(); toast('▶ ' + T('Resumed')); }); });
      // Waiting chats: the customer wrote, the bot has not answered — with the reason and "Answer now".
      var wt = document.createElement('details'); wt.className = 'fold'; wt.dataset.key = 'waiting';
      wt.innerHTML = '<summary>⏳ ' + T('Waiting for a reply') + ' <span class="muted small" id="bh-n">(…)</span></summary><div id="bh-list"><div class="empty">' + T('Loading…') + '</div></div>';
      el.appendChild(wt);
      function bhLoad() { get('bot_health').then(function (h) { if (!h || !h.ok) return;
        var st = { ok: ['🟢', T('Working')], off: ['⚪', T('Switched off')], disconnected: ['🔴', T('Number not connected')], 'not running': ['🟠', T('Not running — no bot run in the last 3 minutes')], 'hourly limit': ['🟠', T('Hourly limit reached')] }[h.state] || ['⚪', h.state];
        var card = document.getElementById('bh-card'); if (!card) { card = document.createElement('div'); card.id = 'bh-card'; card.className = 'card bh-card'; el.insertBefore(card, el.firstChild); }
        card.innerHTML = '<div class="row wrap"><b class="bh-state">' + st[0] + ' ' + st[1] + '</b><span class="muted small">' + T('Mode') + ': ' + esc(T(h.mode === 'auto' ? 'Automatic' : h.mode === 'draft' ? 'Suggest only' : 'Off')) + '</span>'
          + '<span class="muted small">' + T('Replies this hour') + ': <b>' + fmt(h.sent_hour) + '</b> / ' + (h.per_hour ? fmt(h.per_hour) : '∞') + '</span><span class="muted small">' + T('Last run') + ': ' + (h.last_tick ? esc(when(h.last_tick)) : '—') + '</span><span class="muted small">' + T('Last reply') + ': ' + (h.last_sent ? esc(when(h.last_sent)) : '—') + '</span>'
          + (h.waiting.length ? '<a href="#" class="bh-go small">⏳ ' + fmt(h.waiting.length) + ' ' + T('chats waiting for a reply') + ' →</a>' : '<span class="small">✅ ' + T('No chat is waiting') + '</span>') + '</div>';
        var n = document.getElementById('bh-n'); if (n) n.textContent = '(' + h.waiting.length + ')'; var tb = document.querySelector('.bt-tabs [data-tab="waiting"] .bt-n'); if (tb) tb.textContent = h.waiting.length;
        document.getElementById('bh-list').innerHTML = h.waiting.length ? '<table class="tbl"><thead><tr><th>' + T('Chat') + '</th><th>' + T('Last message') + '</th><th>' + T('Why the bot did not answer') + '</th><th></th></tr></thead><tbody>' + h.waiting.map(function (w) {
            return '<tr><td><b dir="auto">' + esc(w.name || ('+' + w.phone)) + '</b><div class="muted small" dir="ltr">' + esc(w.phone ? '+' + w.phone : w.jid) + '</div></td><td><div dir="auto">' + esc(w.text) + '</div><div class="muted small">' + esc(when(w.at)) + '</div></td>'
              + '<td class="small">' + esc(T(w.why)) + (w.paused_by ? '<div class="muted">' + esc(T(w.paused_by === 'handoff' ? 'handed over to the team' : w.paused_by)) + (w.paused_until ? ' · ' + T('until') + ' ' + esc(when(w.paused_until)) : ' · ' + T('until someone turns it back on')) + '</div>' : '') + '</td><td><button class="btn primary sm" data-bh-retry="' + esc(w.jid) + '">↻ ' + T('Answer now') + '</button> <a class="link-btn" href="?p=inbox#' + encodeURIComponent(w.jid) + '">💬</a></td></tr>'; }).join('') + '</tbody></table>'
          : '<div class="empty">✅ ' + T('No chat is waiting') + '</div>'; }); }
      bhLoad(); setInterval(function () { if (!document.hidden) bhLoad(); }, 30000);
      el.addEventListener('click', function (e) { var b;
        if (e.target.closest('.bh-go')) { e.preventDefault(); var t = document.querySelector('.bt-tabs [data-tab="waiting"]'); if (t) t.click(); return; }
        if ((b = e.target.closest('[data-bh-retry]'))) { b.disabled = true; b.textContent = '⏳ ' + T('Answering…');
          post('bot_retry', { jid: b.dataset.bhRetry }).then(function (x) { toast(x.ok ? (x.result === 'sent' ? '✅ ' + T('Answered') : 'ℹ️ ' + T(x.result || '') + (x.why ? ' — ' + T(x.why) : '') + (x.note ? ' — ' + T(x.note) : '')) : '⚠️ ' + x.error); bhLoad(); }); } });
      // Turn the sections into tabs (remembered in the address: ?p=botrun&tab=…)
      var keys = ['settings', 'groups', 'join', 'team', 'history', 'chats', 'drafts', 'paused', 'waiting'];
      var folds = [].slice.call(el.querySelectorAll(':scope > details.fold'));
      folds.forEach(function (d, i) { d.dataset.key = d.dataset.key || keys[i] || ('t' + i); d.open = true; d.classList.add('bt-pane'); });
      var want = new URLSearchParams(location.search).get('tab') || 'settings';
      var bar = document.createElement('div'); bar.className = 'page-tabs sub bt-tabs';
      bar.innerHTML = folds.map(function (d) { var sm = d.querySelector('summary'); return '<a href="#" data-tab="' + d.dataset.key + '">' + sm.innerHTML.replace(/<span class="muted small"[^>]*>\(([^)]*)\)<\/span>/, '<span class="bt-n">$1</span>') + '</a>'; }).join('');
      el.insertBefore(bar, el.firstChild);
      function show(k) { if (!folds.some(function (d) { return d.dataset.key === k; })) k = 'settings';
        folds.forEach(function (d) { d.hidden = d.dataset.key !== k; if (d.dataset.key === k) d.dispatchEvent(new Event('toggle')); });
        [].forEach.call(bar.children, function (a) { a.classList.toggle('on', a.dataset.tab === k); });
        var u = new URL(location.href); u.searchParams.set('tab', k); history.replaceState(null, '', u); }
      bar.addEventListener('click', function (e) { var a = e.target.closest('[data-tab]'); if (!a) return; e.preventDefault(); show(a.dataset.tab); });
      show(want);
    });
    return;
  }
  function logTable(rows) {
    return rows.length ? '<table class="tbl"><thead><tr><th>' + T('When') + '</th><th>' + T('Chat') + '</th><th>' + T('Customer') + '</th><th>' + T('Bot') + '</th><th>' + T('Result') + '</th></tr></thead><tbody>' + rows.map(function (x) {
      var res = x.result === 'skipped' ? '⏭️ ' + T('Skipped') + ' — ' + esc(x.why || '') : x.result === 'paused' ? '⏸️ /stop' : x.result === 'resumed' ? '▶️ /start' : x.result === 'handoff' ? '🙋 ' + T('Sent and handed over to a person') : x.result === 'sent' ? '✅ ' + T('Sent') : x.result === 'draft' ? '📝 ' + T('Draft') + (x.why ? ' — ' + T(x.why) : '') : '⚠️ ' + esc(x.error || x.result || '');
      return '<tr><td>' + esc(when(x.at)) + '</td><td><a href="?p=inbox#' + encodeURIComponent(x.jid) + '">' + esc(x.name || String(x.jid).split('@')[0]) + '</a></td><td dir="auto" class="sc-t">' + esc(x.q || '') + '</td><td dir="auto" class="sc-t">' + esc(x.reply || '') + (x.reply ? '<br><small class="muted">' + Math.round((x.confidence || 0) * 100) + '% · ' + esc(x.model || '') + '</small>' : '') + '</td><td>' + res + '</td></tr>'; }).join('') + '</tbody></table>' : '<div class="empty">' + T('Nothing yet.') + '</div>';
  }

  /* ===================== One group's bot page: settings header (folds) + chat + log ===================== */
  if (page === 'botgroup') {
    var gjid = new URLSearchParams(location.search).get('g') || '';
    root.innerHTML = '<a href="?p=botrun" class="link">← ' + T('Bot') + '</a><div id="bg"><div class="empty">' + T('Loading…') + '</div></div>';
    get('bot_group', { jid: gjid }).then(function (r) {
      if (!r.ok) return fail(document.getElementById('bg'), r);
      var el = document.getElementById('bg'), on = r.on;
      el.innerHTML = '<section class="hero"><div class="hero-t">' + avatar(gjid, r.name) + '<div><h1 dir="auto">' + esc(r.name) + '</h1><p class="muted">👤 ' + fmt(r.members) + ' · ⭐ ' + fmt(r.admins.length) + ' ' + T('admins') + ' · 🤖 ' + fmt(r.stats.answers) + ' ' + T('bot replies') + ' · 📝 ' + fmt(r.stats.drafts) + ' ' + T('drafts') + '</p></div></div>'
        + '<div class="hero-tools"><label class="sw big"><input type="checkbox" id="bg-on" ' + (on ? 'checked' : '') + (r.groups_mode === 'all' ? ' disabled' : '') + '><i></i><span id="bg-onl">' + (on || r.groups_mode === 'all' ? '🟢 ' + T('Bot on in this group') : '⚪ ' + T('Bot off in this group')) + '</span></label><a class="chip-btn" href="?p=inbox#' + encodeURIComponent(gjid) + '">💬 ' + T('Open chat') + '</a><a class="chip-btn" href="?p=groups&g=' + encodeURIComponent(gjid) + '">👥 ' + T('Group page') + '</a></div></section>'
        + (r.mode === 'off' ? '<div class="alert bad">⚪ ' + T('The bot is OFF for this number — switch it on in the Bot page.') + '</div>' : '') + (r.scope === 'private' ? '<div class="alert bad">' + T('The bot answers private chats only — switching it on here adds groups.') + '</div>' : '')
        + '<details class="card fold" open><summary><b>⚙️ ' + T('Settings of this group') + '</b> <span class="muted small" id="bg-msg">⚡ ' + T('Changes are saved automatically') + '</span></summary><div id="bg-cfg">' + gCfgForm(r.cfg) + '</div>'
        + (r.admins.length ? '<div class="tm-g"><b>⭐ ' + T('Admins (team — tagged when the bot hands over)') + '</b><div class="tm-list">' + r.admins.map(function (a) { return '<span class="tm-p"><b dir="auto">' + esc(a.name || '—') + '</b> <span dir="ltr">+' + esc(a.phone) + '</span></span>'; }).join('') + '</div></div>' : '') + '</details>'
        + '<details class="card fold" open><summary><b>💬 ' + T('Chat') + '</b></summary><div class="row wrap"><div class="search-xl"><span>🔎</span><input type="search" id="bg-q" placeholder="' + T('Search messages…') + '" dir="auto"></div><select id="bg-who"><option value="">' + T('All messages') + '</option><option value="bot">🤖 ' + T('Bot replies') + '</option><option value="team">⭐ ' + T('Team') + '</option><option value="members">👤 ' + T('Members') + '</option><option value="q">❓ ' + T('Questions') + '</option></select><button class="chip-btn sm" id="bg-ref">🔄</button></div><div id="bg-chat" class="bg-chat"></div></details>'
        + '<details class="card fold"><summary><b>🧾 ' + T('What the bot did in this group') + '</b> <span class="muted small">(' + fmt(r.log.length) + ')</span></summary><div id="bg-log"></div></details>';
      var msg = document.getElementById('bg-msg');
      function save() { var cfg = gCfgRead(document.getElementById('bg-cfg')); msg.textContent = T('Saving…');
        post('bot_group_set', { jid: gjid, name: r.name, on: document.getElementById('bg-on').checked ? 1 : 0, cfg: cfg }).then(function (x) { msg.textContent = x.ok ? '✓ ' + T('Saved automatically') + ' · ' + new Date().toLocaleTimeString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '⚠️ ' + x.error; }); }
      gCfgWire(document.getElementById('bg-cfg'), function () { if (document.getElementById('bg-on').checked || r.groups_mode === 'all') save(); else msg.textContent = T('Switch the bot on in this group to use these settings.'); });
      document.getElementById('bg-on').onchange = function () { document.getElementById('bg-onl').textContent = this.checked ? '🟢 ' + T('Bot on in this group') : '⚪ ' + T('Bot off in this group'); save(); };
      var admins = {}; r.admins.forEach(function (a) { admins[a.phone] = 1; });
      var CH = [];
      function loadChat() { get('thread', { jid: gjid }).then(function (t) { CH = (t.rows || []).filter(function (m) { return m.dir === 'in' || m.dir === 'out'; }).reverse(); drawChat(); }); }
      function drawChat() { var q = (document.getElementById('bg-q').value || '').toLowerCase(), w = document.getElementById('bg-who').value;
        var rows = CH.filter(function (m) { var bot = m.dir === 'out' && m.via === 'bot', team = m.dir === 'out' && !bot || admins[String(m.from || '').replace(/\D/g, '')];
          return (!q || String(m.text || '').toLowerCase().indexOf(q) !== -1 || String(m.from_name || '').toLowerCase().indexOf(q) !== -1) && (!w || (w === 'bot' ? bot : w === 'team' ? team : w === 'members' ? !bot && !team : /[؟?]/.test(m.text || ''))); });
        var P = pg('bgchat', rows, drawChat, 50);
        document.getElementById('bg-chat').innerHTML = P.html + P.rows.map(function (m) { var bot = m.dir === 'out' && m.via === 'bot', team = !bot && (m.dir === 'out' || admins[String(m.from || '').replace(/\D/g, '')]);
          return '<div class="bgm ' + (bot ? 'bot' : team ? 'team' : 'in') + '"><div class="bgm-h"><b dir="auto">' + (bot ? '🤖 ' + T('Bot') : m.dir === 'out' ? '📱 ' + T('You') : esc(m.from_name || ('+' + (m.from || '')))) + (team && !bot && m.dir !== 'out' ? ' ⭐' : '') + '</b><small class="muted">' + esc(when(m.time)) + '</small></div><div dir="auto" class="bgm-t">' + (String(m.text || '').trim() ? esc(m.text) : '<i class="muted">[' + esc(m.kind || '') + ']</i>') + '</div></div>'; }).join('') + P.html; }
      document.getElementById('bg-q').oninput = function () { pgReset('bgchat'); drawChat(); }; document.getElementById('bg-who').onchange = function () { pgReset('bgchat'); drawChat(); };
      document.getElementById('bg-ref').onclick = loadChat; loadChat(); setInterval(function () { if (!document.hidden && (PG.bgchat || {}).p === 1) loadChat(); }, 20000);
      function bgLog() { var P = pg('bglog', r.log, bgLog, 20); document.getElementById('bg-log').innerHTML = logTable(P.rows) + P.html; } bgLog();
    });
    return;
  }

  /* ===================== ⚙️ General settings (one page for the whole system) ===================== */
  if (page === 'settings') {
    root.innerHTML = hero('⚙️', 'General settings', 'Everything that runs the system in one place: the Google account, WhatsApp numbers, users, AI, health, daily report and backup.', '') + '<div id="sys-card"></div><div id="menu-card"></div><div id="gwr-card"></div><div id="msg-card"></div><div id="st"><div class="empty">' + T('Loading…') + '</div></div>';
    // System (super admin): brand, address, time zone, country code, sign-in limits, bridge — no longer in the code
    // Menu: section titles, page names and icons, hidden pages
    (function menuCard() { get('menu_cfg').then(function (x) { var el = document.getElementById('menu-card'); if (!x.ok || !el) return;
      el.innerHTML = '<details class="card"><summary><b>🧭 ' + T('Menu') + '</b> <span class="muted small">' + T('Rename sections and pages, change icons, hide pages you do not use') + '</span></summary>'
        + x.menu.map(function (s) { return '<div class="ap-p"><label>' + T('Section') + ' <input data-sec="' + s.id + '" value="' + esc(s.title) + '" style="width:220px"></label>'
          + s.items.map(function (it) { return '<div class="row wrap" style="margin:4px 0"><input data-ic="' + it.key + '" value="' + esc(it.icon) + '" style="width:54px;text-align:center"><input data-lb="' + it.key + '" value="' + esc(it.label) + '" placeholder="' + esc(it.default_label) + '" style="width:240px">'
            + (it.key === 'settings' ? '' : '<label class="check"><input type="checkbox" data-hd="' + it.key + '"' + (it.hidden ? ' checked' : '') + '> ' + T('Hide') + '</label>') + '<span class="muted small">?p=' + esc(it.key) + '</span></div>'; }).join('') + '</div>'; }).join('')
        + '<div class="row"><button class="btn primary sm" id="mn-save">💾 ' + T('Save') + '</button><button class="link-btn" id="mn-reset">' + T('Back to the starting values') + '</button></div></details>';
      el.onclick = function (e) {
        if (e.target.closest('#mn-save')) { var sec = {}, items = {}; [].forEach.call(el.querySelectorAll('[data-sec]'), function (i) { sec[i.dataset.sec] = i.value; });
          [].forEach.call(el.querySelectorAll('[data-lb]'), function (i) { var k = i.dataset.lb, hd = el.querySelector('[data-hd="' + k + '"]'); items[k] = { label: i.value, icon: el.querySelector('[data-ic="' + k + '"]').value, hidden: hd && hd.checked ? 1 : 0 }; });
          post('menu_cfg', { sections: sec, items: items }).then(function (r) { toast(r.ok ? '✓ ' + T('Saved — reload to see the menu') : '⚠️ ' + r.error); }); }
        if (e.target.closest('#mn-reset') && confirm(T('Put the menu back to the starting values?'))) post('menu_cfg', { reset: 1 }).then(function () { location.reload(); }); }; }); })();
    // Gateway content rules: texts never stored, link domains removed, tags given to texts (library, bot checks)
    (function gwrCard() { get('gw_rules').then(function (x) { var el = document.getElementById('gwr-card'); if (!x.ok || !el) return; var R = x.rules;
      var tagRow = function (t, i) { return '<div class="ap-p" data-tag="' + i + '"><div class="row wrap"><label>' + T('Tag') + ' <input class="gt-key" value="' + esc(t.key || '') + '" style="width:110px" dir="ltr"></label><label>' + T('Name') + ' <input class="gt-label" value="' + esc(t.label || '') + '" style="width:140px"></label>'
        + '<label class="check"><input type="checkbox" class="gt-q"' + (t.quran_index ? ' checked' : '') + '> ' + T('Use the Quran text index') + '</label><label>' + T('Diacritics ratio') + ' <input class="gt-r" type="number" step="0.05" min="0" max="1" value="' + (t.tashkeel_ratio || 0) + '" style="width:70px"></label><button type="button" class="link-btn bad-t" data-gtdel="' + i + '">✕</button></div>'
        + '<textarea class="gt-p" rows="3" dir="ltr" style="width:100%" placeholder="' + T('patterns (regular expressions), one per line') + '">' + esc((t.patterns || []).join('\n')) + '</textarea></div>'; };
      el.innerHTML = '<details class="card"><summary><b>🛡️ ' + T('Gateway rules') + '</b> <span class="muted small">' + T('What the WhatsApp gateway never stores, which links it removes, and which tags it gives to texts — read by both numbers within a minute') + '</span></summary>'
        + '<label class="gcf-wide">' + T('Texts never stored (login codes, passwords…) — patterns, one per line') + '<textarea id="gw-sec" rows="2" dir="ltr" style="width:100%">' + esc(R.secret_patterns.join('\n')) + '</textarea></label>'
        + '<label class="gcf-wide">' + T('Links removed from stored messages — domains, one per line') + '<textarea id="gw-dom" rows="3" dir="ltr" style="width:100%">' + esc(R.strip_link_domains.join('\n')) + '</textarea></label>'
        + '<b>' + T('Text tags') + '</b><div id="gw-tags">' + R.tags.map(tagRow).join('') + '</div><button type="button" class="chip-btn sm" id="gw-addtag">➕ ' + T('Add tag') + '</button>'
        + (function () { var L = R.library || {}, ts = L.tag_section || {}, pl = function (a, sec) { return (a || []).map(function (x) { return x.name + ' => ' + x.pattern + (sec && x.body === false ? ' [heading]' : ''); }).join('\n'); };
            return '<div class="ap-p"><b>📚 ' + T('Library sections and topics') + '</b><p class="muted small">' + T('One per line: name => pattern. The first matching section wins; end a section with [heading] when it counts only from the heading or the whole text.') + '</p>'
              + '<label class="gcf-wide">' + T('Sections') + '<textarea id="gl-sec" rows="5" dir="auto" style="width:100%">' + esc(pl(L.sections, true)) + '</textarea></label>'
              + '<label class="gcf-wide">' + T('Topics') + '<textarea id="gl-top" rows="5" dir="auto" style="width:100%">' + esc(pl(L.topics, false)) + '</textarea></label>'
              + '<div class="row wrap"><label>' + T('Default topic') + ' <input id="gl-dt" value="' + esc(L.default_topic || '') + '" style="width:120px"></label><label>' + T('Other section') + ' <input id="gl-os" value="' + esc(L.other_section || '') + '" style="width:120px"></label><label>' + T('Audio section') + ' <input id="gl-as" value="' + esc(L.audio_section || '') + '" style="width:120px"></label>'
              + '<label>' + T('Voice message title') + ' <input id="gl-vt" value="' + esc(L.voice_title || '') + '" style="width:140px"></label><label>' + T('Audio file title') + ' <input id="gl-at" value="' + esc(L.audio_title || '') + '" style="width:140px"></label><label>' + T('Audio tag') + ' <input id="gl-ag" value="' + esc(L.audio_tag || '') + '" style="width:90px" dir="ltr"></label></div>'
              + '<div class="row wrap"><label>' + T('Texts with tag') + ' <input id="gl-tt" value="' + esc(ts.tag || '') + '" style="width:90px" dir="ltr"></label><label>' + T('go to section') + ' <input id="gl-tn" value="' + esc(ts.name || '') + '" style="width:120px"></label><label>' + T('unless they match') + ' <input id="gl-tu" value="' + esc(ts.unless || '') + '" style="width:200px" dir="ltr"></label></div></div>'; })()
        + (function () { var M = R.media || {}, au = M.auto || [], mx = M.max_mb || {}, K = [['image', '🖼️ ' + T('Photos')], ['sticker', '🙂 ' + T('Stickers')], ['audio', '🎙️ ' + T('Voice and audio')], ['video', '🎬 ' + T('Videos')], ['document', '📄 ' + T('Documents (PDF, Word, Excel…)')]];
            return '<div class="ap-p"><b>⬇️ ' + T('Download automatically') + '</b><p class="muted small">' + T('Ticked kinds are saved on the server as they arrive (and from older chats in the background). Larger files are fetched only when someone opens them. The same file is never kept twice.') + '</p>'
              + K.map(function (k) { return '<div class="row wrap"><label class="check" style="min-width:230px"><input type="checkbox" class="gm-a" value="' + k[0] + '"' + (au.indexOf(k[0]) !== -1 ? ' checked' : '') + '> ' + k[1] + '</label><label>' + T('up to') + ' <input type="number" class="gm-m" data-k="' + k[0] + '" min="1" max="500" value="' + (mx[k[0]] || '') + '" style="width:80px"> MB</label></div>'; }).join('') + '</div>'; })()
        + '<div class="ap-p"><b>💳 ' + T('Payment bot') + '</b><label class="gcf-wide">' + T('Commands that approve or reject a receipt (typed in your own chat with the number, followed by its number)') + '<input id="gw-pc" dir="auto" value="' + esc((R.payment_commands || []).join('، ')) + '"></label>'
          + '<label class="gcf-wide">' + T('Money words (to find amounts in messages)') + '<input id="gw-mw" dir="auto" value="' + esc((R.money_words || []).join('، ')) + '"></label></div>'
        + '<div class="row" style="margin-top:8px"><button class="btn primary sm" id="gw-save">💾 ' + T('Save') + '</button></div></details>';
      var read = function () { var v = function (id) { return document.getElementById(id).value; }, mm = {}; [].forEach.call(el.querySelectorAll('.gm-m'), function (i) { mm[i.dataset.k] = i.value; });
        return { payment_commands: v('gw-pc'), money_words: v('gw-mw'), media: { auto: [].filter.call(el.querySelectorAll('.gm-a'), function (i) { return i.checked; }).map(function (i) { return i.value; }), max_mb: mm }, library: { sections: v('gl-sec'), topics: v('gl-top'), default_topic: v('gl-dt'), other_section: v('gl-os'), audio_section: v('gl-as'), voice_title: v('gl-vt'), audio_title: v('gl-at'), audio_tag: v('gl-ag'), tag_tag: v('gl-tt'), tag_name: v('gl-tn'), tag_unless: v('gl-tu') }, secret_patterns: document.getElementById('gw-sec').value, strip_link_domains: document.getElementById('gw-dom').value, tags: [].map.call(el.querySelectorAll('[data-tag]'), function (d) { return { key: d.querySelector('.gt-key').value, label: d.querySelector('.gt-label').value, patterns: d.querySelector('.gt-p').value, quran_index: d.querySelector('.gt-q').checked ? 1 : 0, tashkeel_ratio: d.querySelector('.gt-r').value }; }) }; };
      el.onclick = function (e) { var b;
        if (e.target.closest('#gw-addtag')) { document.getElementById('gw-tags').insertAdjacentHTML('beforeend', tagRow({}, el.querySelectorAll('[data-tag]').length)); return; }
        if ((b = e.target.closest('[data-gtdel]'))) { b.closest('[data-tag]').remove(); return; }
        if (e.target.closest('#gw-save')) post('gw_rules', { rules: read() }).then(function (r) { toast(r.ok ? '✓ ' + T('Saved — the gateways use it within a minute') : '⚠️ ' + T(r.error)); }); }; }); })();
    // Message templates: every text the system sends on WhatsApp by itself
    (function msgCard() { get('msg_templates').then(function (x) { var el = document.getElementById('msg-card'); if (!x.ok || !el) return;
      el.innerHTML = '<details class="card"><summary><b>✉️ ' + T('Message templates') + '</b> <span class="muted small">' + T('Alerts, login code, reminders, meeting links and the daily report — the words in {braces} are filled when sending') + '</span></summary>'
        + x.templates.map(function (t) { return '<div class="ap-p"><div class="row"><b>' + esc(T(t.title)) + '</b>' + (t.edited ? '<span class="pill">✏️ ' + T('Edited') + '</span>' : '') + '<span class="muted small">' + t.vars.map(function (v) { return '{' + v + '}'; }).join(' ') + '</span></div>'
          + '<textarea dir="auto" rows="' + Math.min(6, Math.max(1, (t.text.match(/\n/g) || []).length + 1)) + '" data-mt="' + t.key + '" style="width:100%">' + esc(t.text) + '</textarea>'
          + '<div class="row"><button class="btn primary sm" data-mtsave="' + t.key + '">💾 ' + T('Save') + '</button>' + (t.edited ? '<button class="link-btn" data-mtreset="' + t.key + '">' + T('Back to the starting text') + '</button>' : '') + '</div></div>'; }).join('') + '</details>';
      el.onclick = function (e) { var b;
        if ((b = e.target.closest('[data-mtsave]'))) post('msg_templates', { key: b.dataset.mtsave, text: el.querySelector('[data-mt="' + b.dataset.mtsave + '"]').value }).then(function (r) { toast(r.ok ? '✓ ' + T('Saved') : '⚠️ ' + r.error); });
        if ((b = e.target.closest('[data-mtreset]'))) post('msg_templates', { key: b.dataset.mtreset, reset: 1 }).then(function () { msgCard(); }); }; }); })();
    (function sysCard() { get('sys_settings').then(function (x) { var el = document.getElementById('sys-card'); if (!x.ok || !el) return;
      el.innerHTML = '<details class="card"><summary><b>🏗️ ' + T('System') + '</b> <span class="muted small">' + T('Brand, address, time zone, country code, sign-in limits — values for this installation') + '</span></summary><div class="gcf" style="margin-top:10px">'
        + x.fields.map(function (f) { var v = f.value == null ? '' : f.value;
          return '<label>' + esc(T(f.label)) + (f.type === 'tz' ? '<input list="sys-tz" data-sys="' + f.key + '" value="' + esc(v) + '">' : '<input data-sys="' + f.key + '" type="' + (f.type === 'int' ? 'number' : 'text') + '" dir="auto" value="' + esc(v) + '" placeholder="' + esc(f.default) + '">') + '</label>'; }).join('')
        + '</div><datalist id="sys-tz">' + x.timezones.map(function (z) { return '<option value="' + z + '">'; }).join('') + '</datalist>'
        + '<p class="muted small">' + T('Changing the dashboard address or cookie path signs everyone out; set them to where the dashboard really is.') + '</p><div class="row"><button class="btn primary sm" id="sys-save">💾 ' + T('Save') + '</button></div></details>';
      document.getElementById('sys-save').onclick = function () { var v = {}; [].forEach.call(el.querySelectorAll('[data-sys]'), function (i) { v[i.dataset.sys] = i.value; });
        post('sys_settings', { sys: v }).then(function (r) { toast(r.ok ? '✓ ' + T('Saved') : '⚠️ ' + T(r.error)); }); }; }); })();
    var L2 = function (u, t) { return '<a href="' + u + '" target="_blank" rel="noopener">' + t + ' ↗</a>'; };
    root.addEventListener('change', function (e) { var c = e.target.closest('[data-inv-acc]'); if (!c) return; c.disabled = true;
      post('acc_invoices', { acc: c.dataset.invAcc, on: c.checked ? 1 : 0 }).then(function (x) { c.disabled = false; if (!x.ok) { c.checked = !c.checked; toast('⚠️ ' + x.error); return; } c.parentNode.querySelector('b').textContent = x.on ? T('On') : T('Off'); toast(x.on ? '🧾 ' + T('Invoices on') : '⏸ ' + T('Invoices off')); }); });
    root.addEventListener('click', function (e) { var b = e.target.closest('.sw'); if (b) { [].forEach.call(b.parentNode.children, function (x) { x.classList.toggle('on', x === b); }); var dm = document.documentElement; if (b.dataset.acc !== undefined) { dm.style.setProperty('--acc', b.querySelectorAll('i')[0].style.background); dm.style.setProperty('--acc2', b.querySelectorAll('i')[1].style.background); } if (b.dataset.cv !== undefined) dm.style.setProperty('--canvas', b.querySelector('i').style.background); return; }
      if (e.target.closest('#sw-save')) { var a = document.querySelector('#sw-acc .sw.on'), c = document.querySelector('#sw-cv .sw.on'); post('ui_colors', { accent: a ? +a.dataset.acc : 0, canvas: c ? +c.dataset.cv : 0, apply: 1 }).then(function (x) { if (x.ok) location.reload(); else toast('⚠️ ' + x.error); }); } });
    root.addEventListener('change', function (e) { var o = e.target.closest('input[name=uitheme]'); if (!o) return;
      post('ui_theme', { theme: o.value }).then(function (x) { if (x.ok) location.reload(); else toast('⚠️ ' + x.error); }); });
    function loadSt() { get('settings_overview').then(function (r) {
      if (!r.ok) return fail(document.getElementById('st'), r);
      var g = r.google, card = function (ic, title, body, link, label) { return '<div class="st-c"><div class="st-h"><span class="st-i">' + ic + '</span><b>' + T(title) + '</b></div><div class="st-b">' + body + '</div>' + (link ? '<a class="chip-btn sm" href="' + link + '">' + T(label || 'Open') + ' →</a>' : '') + '</div>'; };
      var gBody = g.connected ? '<span class="pill good">✅ ' + T('Connected') + '</span> <b dir="ltr">' + esc(g.email) + '</b><div class="small">' + (g.drive ? '✅' : '⚠️') + ' Google Drive · ' + (g.drive_read ? '✅' : '❌') + ' ' + T('Read Drive files (learning)') + ' · ' + (g.meet ? '✅' : '❌') + ' ' + T('Meet recording') + ' · ' + (g.calendar ? '✅' : '⚠️') + ' ' + T('Calendar (Meet links)') + (g.missing && g.missing.length ? '<div class="pp-view small" style="margin-top:8px">⚠️ ' + T('Google did not grant') + ': <b dir="ltr">' + g.missing.join(', ') + '</b>. ' + T('Google drops a permission that is not added to your Google Cloud project, even if you press Allow. Add it once:') + '<ol style="margin:6px 0"><li>' + L2('https://console.cloud.google.com/auth/scopes', T('Google Cloud → Data access')) + ' → <b>' + T('Add or remove scopes') + '</b></li><li>' + T('Paste these in “Manually add scopes”, press Add to table, then Update and Save:') + '<code dir="ltr" class="gscope">https://www.googleapis.com/auth/drive.readonly<br>https://www.googleapis.com/auth/meetings.space.created</code></li><li>' + T('Here: press Disconnect, then Connect, and tick every box on Google\'s screen (or “Select all”).') + '</li></ol></div>' : '')+ '</div>' + (!g.calendar ? '<div class="small bad-t">' + T('Calendar access is missing — disconnect and connect again to allow it.') + '</div>' : '') + '<div class="row"><button class="link-btn" id="g-dis">' + T('Disconnect') + '</button></div>'
        : (g.pending ? '<div class="small bad-t">⚠️ ' + T('The last attempt did not finish. If Google said “Access blocked”, add your Gmail under Test users, then connect again.') + ' ' + L2('https://console.cloud.google.com/auth/audience', 'Audience → Test users') + '</div>' : '')
          + '<ol class="small gsteps"><li>' + T('Enable the APIs:') + ' ' + L2('https://console.cloud.google.com/apis/library/drive.googleapis.com', 'Google Drive API') + ' · ' + L2('https://console.cloud.google.com/apis/library/calendar-json.googleapis.com', 'Google Calendar API') + '</li>'
          + '<li>' + L2('https://console.cloud.google.com/auth/audience', 'Audience') + ': ' + T('add your Gmail under “Test users”.') + '</li><li>' + L2('https://console.cloud.google.com/apis/credentials/oauthclient', T('Create an OAuth client ID')) + ' → ' + T('type “Web application”.') + ' ' + T('Authorised redirect URI:') + ' <code dir="ltr">' + esc(g.redirect) + '</code> <button type="button" class="link-btn" data-copy-link="' + esc(g.redirect) + '">📋</button></li></ol>'
          + '<div class="row wrap"><input id="g-id" placeholder="Client ID" value="' + esc(g.client_id) + '" dir="ltr" style="flex:2;min-width:220px"><input id="g-sec" type="password" placeholder="' + (g.has_secret ? '•••••• ' + T('saved') : 'Client secret') + '" dir="ltr" style="flex:1;min-width:160px"><button class="btn primary" id="g-con">🔗 ' + T('Connect Google') + '</button></div>';
      document.getElementById('st').innerHTML = '<div class="card st-google"><div class="st-h"><span class="st-i">🔑</span><b>' + T('Google account') + '</b> <span class="muted small">' + T('used for Drive backups and automatic Google Meet links') + '</span></div>' + gBody + '</div>'
        + '<div class="card st-theme"><div class="st-h"><span class="st-i">🎨</span><b>' + T('Dashboard theme') + '</b> <span class="muted small">' + T('for everyone; each person can still switch light / dark from the top bar') + '</span></div><div class="th-pick">'
          + [['aurora', T('Aurora'), 'New home: a gradient hero with your numbers as live cards, large figures, glass cards', ['#1e1b4b', '#4338ca', '#0d9488', '#ffffff']], ['google', T('Google (Material)'), 'Pale canvas, pill menu and search, white rounded cards, Google blue', ['#f8fafd', '#d3e3fd', '#0b57d0', '#ffffff']], ['classic', T('Classic'), 'Off-white with a sky hint, sky and amber accents', ['#fcfdfd', '#e7f5f9', '#0e8fa8', '#b7791f']], ['orchid', T('Orchid'), 'Floating menu and top bar, lavender canvas, deep violet and teal', ['#f4f1fb', '#ffffff', '#6d4ad8', '#0f9a8a']], ['studio', T('Studio'), 'No side menu: page tabs across the top, full width — in the colours you pick below', [(r.ui_colors || {}).canvas || '#f7f8fb', '#ffffff', (r.ui_colors || {}).acc || '#2563eb', (r.ui_colors || {}).acc2 || '#d97706']]].map(function (t) {
            return '<label class="th-opt' + (r.ui_theme === t[0] ? ' on' : '') + '"><input type="radio" name="uitheme" value="' + t[0] + '" ' + (r.ui_theme === t[0] ? 'checked' : '') + '><span class="th-sw">' + t[3].map(function (c) { return '<i style="background:' + c + '"></i>'; }).join('') + '</span><b>' + esc(t[1]) + '</b><small>' + esc(T(t[2])) + '</small></label>'; }).join('')
          + '</div><div class="th-colors"><h4>🎨 ' + T('Studio colours') + '</h4><div class="muted small">' + T('Pick the main colour; its chart partner is chosen for you so charts stay readable for everyone (checked for colour blindness).') + '</div>'
          + '<div class="sw-row" id="sw-acc">' + r.palette.map(function (p, i) { var on = (r.ui_colors || {}).accent_i === i || (!r.ui_colors && i === 0); return '<button type="button" class="sw' + (on ? ' on' : '') + '" data-acc="' + i + '" title="' + esc(T(p[0])) + '"><i style="background:' + p[1] + '"></i><i style="background:' + p[2] + '"></i><span>' + esc(T(p[0])) + '</span></button>'; }).join('') + '</div>'
          + '<div class="muted small" style="margin-top:8px">' + T('Background tone') + '</div><div class="sw-row" id="sw-cv">' + r.canvases.map(function (c, i) { var on = (r.ui_colors || {}).canvas_i === i || (!r.ui_colors && i === 0); return '<button type="button" class="sw cv' + (on ? ' on' : '') + '" data-cv="' + i + '"><i style="background:' + c[1] + '"></i><span>' + esc(T(c[0])) + '</span></button>'; }).join('') + '</div>'
          + '<div class="row" style="margin-top:10px"><button class="btn primary sm" id="sw-save">✔ ' + T('Use these colours (Studio theme)') + '</button></div></div>'
          + '</div></div>'
        + '<div class="st-grid">'
        + card('📱', 'WhatsApp numbers', r.accounts.map(function (a) { return '<div>' + (a.connected ? '🟢' : '🔴') + ' <b dir="auto">' + esc(a.name) + '</b> <span class="muted small">· ' + T('payments') + ' ' + (a.payments ? '✅' : '⚪') + ' · ' + T('bot') + ' ' + ({ off: '⚪', draft: '📝', auto: '🤖' }[a.bot] || '') + '</span></div>'; }).join(''), '?p=accounts')
        + card('🧾', 'Invoices', '<div class="muted small">' + T('Payment-receipt bot, sending invoices from a chat, customer invoices and overdue reminders — per number.') + '</div>' + r.accounts.map(function (a) { return '<label class="inv-sw"><span dir="auto">' + (a.connected ? '🟢' : '🔴') + ' ' + esc(a.name) + '</span><input type="checkbox" data-inv-acc="' + esc(a.id) + '" ' + (a.payments ? 'checked' : '') + '><i></i><b>' + (a.payments ? T('On') : T('Off')) + '</b></label>'; }).join(''), '', '')
        + card('👥', 'Users', fmt(r.users) + ' ' + T('users'), '?p=users')
        + card('🤖', 'AI providers', fmt(r.ai.active) + ' / ' + fmt(r.ai.total) + ' ' + T('in use') + (r.ai.mode ? ' · ' + (r.ai.mode === 'parallel' ? '⚡ ' + T('All at once') : '🔁 ' + T('One after another')) : ''), '?p=ai')
        + card('🤖', 'Bot', T('On/off, groups, team and log per number.'), '?p=botrun')
        + card('❤️', 'Number health', T('Connection, disconnects and alerts.'), '?p=health')
        + card('📊', 'Daily report', r.report.enabled ? '✅ ' + T('every day at') + ' ' + r.report.hour + ':00' : '⚪ ' + T('Off'), '?p=report')
        + card('☁️', 'Google Drive backup', (r.backup.enabled ? '✅ ' + T('daily') : '⚪ ' + T('Off')) + (r.backup.last ? '<div class="small muted">' + T('Last') + ': ' + esc(when(r.backup.last.at)) + ' ' + (r.backup.last.ok ? '✅' : '⚠️') + '</div>' : ''), '?p=backup')
        + card('🎥', 'Meetings', T('Fixed links, passwords, hosts.'), '?p=meetings')
        + card('🆕', 'Version', '<b>' + esc(r.version) + '</b>', '?p=changes', 'What’s new')
        + '</div>';
      var con = document.getElementById('g-con'); if (con) con.onclick = function () { post('backup_cfg', { do: 'connect', client_id: document.getElementById('g-id').value, client_secret: document.getElementById('g-sec').value }).then(function (x) { if (x.ok) location.href = x.url; else toast('⚠️ ' + x.error); }); };
      var dis = document.getElementById('g-dis'); if (dis) dis.onclick = function () { if (confirm(T('Disconnect Google? Backups and automatic Meet links stop until you connect again.'))) post('backup_cfg', { do: 'disconnect' }).then(loadSt); };
    }); }
    root.addEventListener('click', function (e) { var c = e.target.closest('[data-copy-link]'); if (c) { if (navigator.clipboard) navigator.clipboard.writeText(c.dataset.copyLink); toast('✓ ' + T('Copied')); } });
    loadSt();
    return;
  }

  /* ===================== 🎥 Meetings (gateway in front of Google Meet) ===================== */
  if (page === 'meetings') {
    var MT = { list: [], users: [], base: '' };
    root.innerHTML = hero('🎥', 'Meetings', 'A fixed link for each meeting: people wait until the host opens it, then enter (automatically or with a password) and are sent to Google Meet. The real Meet link is never shown before that.', isAdmin ? '<button class="btn primary" id="mt-new">➕ ' + T('New meeting') + '</button>' : '')
      + '<div id="mt-edit"></div><div class="card"><div class="row wrap gt-bar"><div class="search-xl"><span>🔎</span><input type="search" id="mt-q" placeholder="' + T('Search meetings…') + '" dir="auto"></div><select id="mt-f"><option value="">' + T('All') + '</option><option value="open">🟢 ' + T('Open now') + '</option><option value="closed">⚪ ' + T('Closed') + '</option></select></div><div id="mt-list"><div class="empty">' + T('Loading…') + '</div></div></div>'
      + '<div class="card muted small mt-tip">💡 ' + T('In Google Meet → Host controls: turn ON “Host management” and turn OFF “Quick access”, so anyone who has the real Meet link must still be admitted by you.') + '</div>';
    function load() { get('meetings').then(function (r) { if (!r.ok) return fail(document.getElementById('mt-list'), r); MT.list = r.meetings; MT.users = r.users; MT.base = r.base; draw(); }); }
    function draw() {
      var q = (document.getElementById('mt-q').value || '').toLowerCase(), f = document.getElementById('mt-f').value;
      var rows = MT.list.filter(function (m) { return (!f || m.status === f) && (!q || (m.title + ' ' + m.slug).toLowerCase().indexOf(q) !== -1); }), P = pg('meetings', rows, draw, 20);
      document.getElementById('mt-list').innerHTML = P.rows.map(function (m) { var link = MT.base + m.slug;
        return '<div class="mt-c ' + m.status + '"><div class="mt-h"><b dir="auto">🎥 ' + esc(m.title) + '</b><span class="pill ' + (m.status === 'open' ? 'good' : '') + '">' + (m.status === 'open' ? '🟢 ' + T('Open now') + ' · ' + fmt(m.joined_now) + (m.limit ? ' / ' + fmt(m.limit) : '') + ' ' + T('joined') : '⚪ ' + T('Closed')) + '</span>'
          + '<span class="tag">' + (m.mode === 'password' ? '🔒 ' + T('Password') : '⚡ ' + T('Automatic join')) + '</span>' + (m.limit ? '<span class="tag">👥 ' + T('max') + ' ' + fmt(m.limit) + '</span>' : '') + (m.record ? '<span class="tag' + (m.rec_note ? ' bad-t' : '') + '">⏺ ' + T('Auto-record') + (m.rec_note ? ' ⚠️' : '') + '</span>' : '') + (m.ask_phone ? '<span class="tag">📱 ' + T('asks for a phone') + '</span>' : '') + (!m.has_link ? '<span class="tag bad-t">⚠️ ' + T('No Meet link yet') + '</span>' : '') + '</div>'
          + '<div class="mt-link"><code dir="ltr">' + esc(link) + '</code><button class="chip-btn sm" data-copy-link="' + esc(link) + '">📋 ' + T('Copy') + '</button><button class="chip-btn sm" data-send="' + esc(m.id) + '">📤 ' + T('Send on WhatsApp') + '</button></div>'
          + '<div class="muted small">' + T('Hosts') + ': ' + ((m.hosts || []).concat((m.wa_hosts || []).map(function (h) { return '📱 ' + (h.name || '+' + h.phone); })).join(', ') || T('admins')) + (m.opened_at ? ' · ' + T('last opened') + ' ' + esc(when(m.opened_at)) + (m.opened_by ? ' (' + esc(m.opened_by) + ')' : '') : '') + ' · ' + fmt(m.joins) + ' ' + T('joins in total') + '</div>'
          + '<div class="row wrap">' + (m.can_host ? (m.status === 'open' ? '<button class="btn sm danger" data-st="close" data-id="' + esc(m.id) + '">⏹ ' + T('Close the meeting') + '</button><a class="btn sm" href="' + esc(m.url) + '" target="_blank" rel="noopener">🎥 ' + T('Go to Meet') + '</a>' : '<button class="btn sm primary" data-st="open" data-id="' + esc(m.id) + '">▶️ ' + T('Open the meeting (I am the host)') + '</button>') + '<button class="chip-btn sm' + (m.pending ? ' on' : '') + '" data-joins="' + esc(m.id) + '">' + (m.pending ? '🙋 ' + fmt(m.pending) + ' ' + T('waiting for approval') : '🧾 ' + T('Who joined')) + '</button>' : '') + (isAdmin && (m.wa_hosts || []).length ? '<button class="chip-btn sm" data-hl="' + esc(m.id) + '">🎙️ ' + T('Host links') + '</button>' : '') + (isAdmin ? '<button class="chip-btn sm" data-edit="' + esc(m.id) + '">✏️ ' + T('Edit') + '</button>' : '') + '</div><div class="mt-j" id="mtj-' + esc(m.id) + '"></div></div>'; }).join('') + P.html || '<div class="empty">' + T('No meetings yet.') + '</div>';
    }
    function editor(m) { m = m || { mode: 'auto', hosts: [], limit: 0 };
      var el = document.getElementById('mt-edit');
      el.innerHTML = '<div class="card mt-f"><h3>' + (m.id ? '✏️ ' + T('Edit meeting') : '➕ ' + T('New meeting')) + '</h3><div class="gcf">'
        + '<label>' + T('Title') + '<input id="mf-title" value="' + esc(m.title || '') + '" dir="auto"></label>'
        + '<label>' + T('Link name (the fixed link)') + '<input id="mf-slug" value="' + esc(m.slug || '') + '" dir="ltr" placeholder="quran-class"><small class="muted" dir="ltr">' + esc(MT.base) + '<b id="mf-slugv">' + esc(m.slug || '…') + '</b></small></label>'
        + '<label class="gcf-wide">' + T('Google Meet link') + ' <small class="muted">(' + T('open meet.new, copy its link and paste it here') + ')</small><span class="row"><input id="mf-url" dir="ltr" placeholder="' + (m.has_link ? T('saved — paste a new one to replace') : 'https://meet.google.com/abc-defg-hij') + '"><button type="button" class="chip-btn" id="mf-auto">✨ ' + T('Create automatically') + '</button></span><small class="muted" id="mf-amsg"></small></label>'
        + '<label>' + T('How people join') + '<select id="mf-mode"><option value="auto" ' + (m.mode !== 'password' ? 'selected' : '') + '>⚡ ' + T('Automatic join (once the host opens it)') + '</option><option value="password" ' + (m.mode === 'password' ? 'selected' : '') + '>🔒 ' + T('Join with a password') + '</option></select></label>'
        + '<label id="mf-passw" ' + (m.mode === 'password' ? '' : 'hidden') + '>' + T('Password') + '<input id="mf-pass" type="text" autocomplete="off" placeholder="' + (m.has_password ? T('saved — type to change') : '') + '"></label>'
        + '<label>' + T('Maximum participants (0 = no limit)') + '<input id="mf-limit" type="number" min="0" max="1000" value="' + (m.limit || 0) + '"></label>'
 + '<label class="check"><input type="checkbox" id="mf-ap" ' + (m.approve !== false ? 'checked' : '') + '> 🙋 ' + T('Guests wait until a host approves them') + '</label>'
        + '<label class="check"><input type="checkbox" id="mf-ah" ' + (m.auto_after_host ? 'checked' : '') + '> ✅ ' + T('Accept guests automatically once any host has entered') + '</label>'
        + '<label>' + T('Close automatically after (hours)') + '<input id="mf-ac" type="number" min="1" max="24" value="' + (m.auto_close || 3) + '"></label>'
        + '<label class="check"><input type="checkbox" id="mf-rec" ' + (m.record ? 'checked' : '') + '> ⏺ ' + T('Record automatically (starts by itself, no host needed)') + '</label>'
        + '<label class="check"><input type="checkbox" id="mf-tr" ' + (m.transcript ? 'checked' : '') + '> 📝 ' + T('Save a transcript automatically') + '</label>'
        + (m.rec_note ? '<div class="gcf-wide small bad-t">⚠️ ' + esc(m.rec_note) + '</div>' : '')
        + '<label class="check"><input type="checkbox" id="mf-phone" ' + (m.ask_phone ? 'checked' : '') + '> 📱 ' + T('Ask for a phone number too') + '</label>'
        + '<label class="gcf-wide">' + T('Who opens the meeting (hosts)') + '<div class="mf-hosts">' + MT.users.map(function (u) { return '<label class="check"><input type="checkbox" value="' + esc(u.email) + '" ' + ((m.hosts || []).indexOf(u.email) !== -1 ? 'checked' : '') + '> ' + esc(u.name) + '</label>'; }).join('') + '</div><small class="muted">' + T('None checked = admins only.') + '</small></label>'
        + '<div class="gcf-wide"><b>📱 ' + T('Hosts from WhatsApp groups') + '</b> <small class="muted">' + T('Each one gets a private link to open the meeting — no dashboard login needed.') + '</small>'
        + '<div class="mf-wah" id="mf-wah"></div><div class="row wrap"><select id="mf-g"><option value="">' + T('Choose a group…') + '</option></select><input type="search" id="mf-gq" placeholder="' + T('Search members…') + '" dir="auto"></div><div class="mf-mem" id="mf-mem"></div></div>'
       
        + '<details class="gcf-wide mf-ui"><summary><b>🎨 ' + T('Customize the pages (waiting, details, approval)') + '</b></summary><div class="gcf mf-uig">'
        + [['waiting_text', 'Waiting page — text', 'The meeting has not started yet — waiting for the host to open it.'], ['waiting_note', 'Waiting page — small note', 'This page refreshes by itself every 15 seconds.'],
          ['form_text', 'Details page — text above the form', ''], ['name_label', 'Details page — name field title', 'Name'], ['phone_label', 'Details page — phone field title', 'Mobile number'], ['pass_label', 'Details page — password field title', 'Password'], ['form_btn', 'Details page — button', 'Join the meeting'],
          ['pending_text', 'Approval page — text', 'Your request was sent ✅ — wait until the host lets you in.'], ['pending_note', 'Approval page — small note', 'This page refreshes by itself; you join as soon as the host agrees.'],
          ['approved_text', 'Accepted — text', ''], ['rejected_text', 'Rejected — text', 'The host did not let you into this meeting.'], ['full_text', 'Full meeting — text', 'The meeting is full.'], ['missing_text', 'Wrong link — text', 'This link is wrong or the meeting was cancelled.'], ['host_text', 'Host page — text', '']
        ].map(function (f) { return '<label class="gcf-wide">' + T(f[1]) + '<input data-ui="' + f[0] + '" dir="auto" maxlength="400" value="' + esc((m.ui || {})[f[0]] || '') + '" placeholder="' + esc(f[2] ? T(f[2]) : '') + '"></label>'; }).join('')
        + [['accent', 'Button colour'], ['accent2', 'Button colour (end)'], ['bg1', 'Background colour'], ['bg2', 'Background colour (end)']].map(function (f) { return '<label>' + T(f[1]) + '<input type="color" data-ui="' + f[0] + '" value="' + esc((m.ui || {})[f[0]] || (f[0] === 'accent' || f[0] === 'accent2' ? '#12b886' : f[0] === 'bg1' ? '#ecfdf5' : '#eef2ff')) + '"></label>'; }).join('')
        + '<label class="gcf-wide">' + T('Logo (https link to an image)') + '<input data-ui="logo" dir="ltr" maxlength="300" value="' + esc((m.ui || {}).logo || '') + '" placeholder="https://…"></label>'
        + (m.slug ? '<div class="gcf-wide small">' + T('Preview') + ': ' + [['waiting', 'Waiting page'], ['form', 'Details page'], ['pending', 'Approval page'], ['rejected', 'Rejected'], ['full', 'Full meeting']].map(function (k) { return '<a href="?m=' + esc(m.slug) + '&preview=' + k[0] + '" target="_blank" rel="noopener">' + T(k[1]) + '</a>'; }).join(' · ') + '</div>' : '')
        + '</div></details>'
        + '<label class="gcf-wide">' + T('Description (shown on the join page)') + '<textarea id="mf-desc" rows="2" dir="auto">' + esc(m.desc || '') + '</textarea></label></div>'
        + '<div class="row"><button class="btn primary" id="mf-save">💾 ' + T('Save') + '</button>' + (m.id ? '<button class="link-btn bad-t" id="mf-del">🗑 ' + T('Delete') + '</button>' : '') + '<button class="link-btn" id="mf-x">' + T('Close') + '</button><span class="muted small" id="mf-msg"></span></div></div>';
      var MSPACE = ''; var WAH = (m.wa_hosts || []).map(function (h) { return { phone: h.phone, name: h.name }; }), MEM = [];
      function drawWah() { document.getElementById('mf-wah').innerHTML = WAH.length ? WAH.map(function (h, i) { return '<span class="tag">📱 ' + esc(h.name || '') + ' <span dir="ltr">+' + esc(h.phone) + '</span> <button type="button" class="link-btn bad-t" data-rmh="' + i + '">✕</button></span>'; }).join(' ') : '<span class="muted small">' + T('None yet.') + '</span>'; }
      function drawMem() { var q = (document.getElementById('mf-gq').value || '').toLowerCase(), have = WAH.map(function (h) { return h.phone; });
        var rows = MEM.filter(function (x) { return !q || (x.name + ' ' + x.phone).toLowerCase().indexOf(q) !== -1; }).slice(0, 80);
        document.getElementById('mf-mem').innerHTML = rows.map(function (x) { var on = have.indexOf(x.phone) !== -1; return '<button type="button" class="chip-btn sm' + (on ? ' on' : '') + '" data-addh="' + esc(x.phone) + '" data-n="' + esc(x.name) + '">' + (on ? '✓ ' : '➕ ') + (x.admin ? '⭐ ' : '') + esc(x.name || '') + ' <span dir="ltr">+' + esc(x.phone) + '</span></button>'; }).join(' '); }
      drawWah();
      get('meeting_targets').then(function (r) { if (r.ok) document.getElementById('mf-g').innerHTML += r.groups.map(function (g) { return '<option value="' + esc(g.jid) + '">' + esc(g.name) + ' (' + g.size + ')</option>'; }).join(''); });
      document.getElementById('mf-g').onchange = function () { MEM = []; drawMem(); if (!this.value) return; document.getElementById('mf-mem').textContent = T('Loading…');
        get('meeting_targets', { group: this.value }).then(function (r) { MEM = r.members || []; drawMem(); }); };
      document.getElementById('mf-gq').oninput = drawMem;
      el.addEventListener('click', function (e) { var a = e.target.closest('[data-addh]'); if (a) { var i = WAH.map(function (h) { return h.phone; }).indexOf(a.dataset.addh); if (i === -1) WAH.push({ phone: a.dataset.addh, name: a.dataset.n }); else WAH.splice(i, 1); drawWah(); drawMem(); return; }
        var r = e.target.closest('[data-rmh]'); if (r) { WAH.splice(+r.dataset.rmh, 1); drawWah(); drawMem(); } });
      document.getElementById('mf-mode').onchange = function () { document.getElementById('mf-passw').hidden = this.value !== 'password'; };
      document.getElementById('mf-slug').oninput = function () { document.getElementById('mf-slugv').textContent = this.value.toLowerCase().replace(/[^a-z0-9-]+/g, '-') || '…'; };
      document.getElementById('mf-x').onclick = function () { el.innerHTML = ''; };
      document.getElementById('mf-auto').onclick = function () { var b = this, msg = document.getElementById('mf-amsg'); b.disabled = true; msg.textContent = T('Creating a Google Meet link…');
        post('meeting_create_link', { title: document.getElementById('mf-title').value }).then(function (x) { b.disabled = false;
          if (x.ok) { document.getElementById('mf-url').value = x.url; MSPACE = x.space || ''; msg.textContent = '✓ ' + T('Created in your Google Calendar — press Save'); }
          else msg.innerHTML = '⚠️ ' + esc(x.error) + (x.need ? ' <a href="?p=backup">' + T('Open the Google connection') + ' →</a>' : ''); }); };
      document.getElementById('mf-save').onclick = function () { var hosts = [].map.call(el.querySelectorAll('.mf-hosts input:checked'), function (i) { return i.value; });
        post('meeting_save', { id: m.id || '', title: document.getElementById('mf-title').value, slug: document.getElementById('mf-slug').value, url: document.getElementById('mf-url').value, mode: document.getElementById('mf-mode').value, pass: document.getElementById('mf-pass').value, limit: document.getElementById('mf-limit').value, ask_phone: document.getElementById('mf-phone').checked ? 1 : 0, hosts: hosts, wa_hosts: WAH, record: document.getElementById('mf-rec').checked ? 1 : 0, approve: document.getElementById('mf-ap').checked ? 1 : 0, auto_after_host: document.getElementById('mf-ah').checked ? 1 : 0, ui: (function () { var o = {}; [].forEach.call(el.querySelectorAll('[data-ui]'), function (i) { if (i.value) o[i.dataset.ui] = i.value; }); return o; })(), auto_close: document.getElementById('mf-ac').value, transcript: document.getElementById('mf-tr').checked ? 1 : 0, space: MSPACE, desc: document.getElementById('mf-desc').value })
          .then(function (x) { if (x.ok) { el.innerHTML = ''; toast('✓ ' + T('Saved')); load(); setTimeout(function () { var mm = MT.list.filter(function (q) { return q.id === m.id; })[0]; if (mm && mm.rec_note) toast('⚠️ ' + mm.rec_note); }, 1500); } else document.getElementById('mf-msg').textContent = '⚠️ ' + x.error; }); };
      if (m.id) document.getElementById('mf-del').onclick = function () { if (confirm(T('Delete this meeting? Its link will stop working.'))) post('meeting_save', { id: m.id, do: 'delete' }).then(function () { el.innerHTML = ''; load(); }); };
      el.scrollIntoView({ behavior: 'smooth' }); }
    if (document.getElementById('mt-new')) document.getElementById('mt-new').onclick = function () { editor(null); };
    document.getElementById('mt-q').oninput = function () { pgReset('meetings'); draw(); }; document.getElementById('mt-f').onchange = function () { pgReset('meetings'); draw(); };
    root.addEventListener('click', function (e) {
      var b = e.target.closest('[data-st]'); if (b) { var w = b.dataset.st === 'open' ? window.open('about:blank', '_blank') : null;
        post('meeting_state', { id: b.dataset.id, do: b.dataset.st }).then(function (x) { if (!x.ok) { if (w) w.close(); toast('⚠️ ' + x.error); return; } if (w && x.url) w.location = x.url; toast(b.dataset.st === 'open' ? '🟢 ' + T('The meeting is open — people can join now') : '⏹ ' + T('Closed')); load(); }); return; }
      var ed = e.target.closest('[data-edit]'); if (ed) { editor(MT.list.filter(function (m) { return m.id === ed.dataset.edit; })[0]); return; }
      var cp = e.target.closest('[data-copy-link]'); if (cp) { if (navigator.clipboard) navigator.clipboard.writeText(cp.dataset.copyLink); toast('✓ ' + T('Copied')); return; }
      var jn = e.target.closest('[data-joins]'); if (jn) { var box = document.getElementById('mtj-' + jn.dataset.joins); if (box.innerHTML) { box.innerHTML = ''; return; }
        get('meeting_joins', { id: jn.dataset.joins }).then(function (x) { box.innerHTML = x.ok ? (x.joins.length ? '<table class="tbl"><thead><tr><th>' + T('When') + '</th><th>' + T('Name') + '</th><th>' + T('Phone') + '</th></tr></thead><tbody>' + x.joins.slice(0, 300).map(function (j) { return '<tr' + (x.opened_at && j.at >= x.opened_at ? ' class="now"' : '') + '><td>' + esc(when(j.at)) + '</td><td dir="auto">' + esc(j.name) + '</td><td dir="ltr">' + (j.phone ? '+' + esc(j.phone) : '—') + '</td><td>' + (j.state === 'pending' && x.opened_at && j.at >= x.opened_at ? '<button class="chip-btn sm" data-apv="' + esc(jn.dataset.joins) + '" data-j="' + esc(j.id) + '" data-do="ok">✔ ' + T('Approve') + '</button> <button class="chip-btn sm bad-t" data-apv="' + esc(jn.dataset.joins) + '" data-j="' + esc(j.id) + '" data-do="no">✖</button>' : j.state === 'rejected' ? '🚫' : j.state === 'pending' ? '⌛' : '✔') + '</td></tr>'; }).join('') + '</tbody></table>' : '<div class="empty">' + T('Nobody yet.') + '</div>') : '⚠️ ' + esc(x.error); }); return; }
      var av = e.target.closest('[data-apv]'); if (av) { post('meeting_approve', { id: av.dataset.apv, j: av.dataset.j, do: av.dataset.do }).then(function (x) { if (!x.ok) return toast('⚠️ ' + x.error); var bx = document.getElementById('mtj-' + av.dataset.apv); bx.innerHTML = ''; load(); var t = root.querySelector('[data-joins="' + av.dataset.apv + '"]'); if (t) setTimeout(function () { t.click(); }, 300); }); return; }
      var hl = e.target.closest('[data-hl]'); if (hl) { var hb = document.getElementById('mtj-' + hl.dataset.hl); if (hb.innerHTML) { hb.innerHTML = ''; return; }
        post('meeting_host_links', { id: hl.dataset.hl }).then(function (x) { if (!x.ok) { hb.textContent = '⚠️ ' + x.error; return; }
          hb.innerHTML = '<div class="muted small">🎙️ ' + T('Private host links — each opens the meeting without a login.') + '</div><table class="tbl"><tbody>' + x.hosts.map(function (h) { return '<tr><td dir="auto">' + esc(h.name || '') + ' <span dir="ltr">+' + esc(h.phone) + '</span></td><td><code dir="ltr" class="small">' + esc(h.link) + '</code></td><td><button class="chip-btn sm" data-copy-link="' + esc(h.link) + '">📋</button> <button class="chip-btn sm" data-hsend="' + esc(hl.dataset.hl) + '" data-ph="' + esc(h.phone) + '">📤 ' + T('Send to him on WhatsApp') + '</button></td></tr>'; }).join('') + '</tbody></table>'; }); return; }
      var hs = e.target.closest('[data-hsend]'); if (hs) { hs.disabled = true; post('meeting_host_links', { id: hs.dataset.hsend, send: 1, phone: hs.dataset.ph }).then(function (x) { toast(x.sent ? '✓ ' + T('Sent') : '⚠️ ' + T('Failed')); }); return; }
      var sd = e.target.closest('[data-send]'); if (sd) { var m = MT.list.filter(function (x) { return x.id === sd.dataset.send; })[0], box = document.getElementById('mtj-' + m.id);
        if (box.innerHTML) { box.innerHTML = ''; return; }
        var text = '🎥 ' + m.title + '\n' + MT.base + m.slug + (m.mode === 'password' ? '\n🔒 ' + T('A password is needed to join.') : '');
        box.innerHTML = '<div class="card mt-send"><b>📤 ' + T('Send the meeting link') + '</b><textarea id="ms-t" rows="3" dir="auto">' + esc(text) + '</textarea>'
          + '<div class="row wrap"><select id="ms-k"><option value="g">👥 ' + T('Groups') + '</option><option value="p">👤 ' + T('People (contacts & chats)') + '</option></select><input type="search" id="ms-q" placeholder="' + T('Search by name or number…') + '" dir="auto"><label class="check"><input type="checkbox" id="ms-all"> ' + T('Select all') + '</label><input id="ms-num" dir="ltr" placeholder="' + T('Numbers too (comma separated)') + '"></div>'
          + '<div class="ms-g" id="ms-g">' + T('Loading…') + '</div><div class="row"><button class="btn primary" id="ms-go">📤 ' + T('Send') + ' (<span id="ms-n">0</span>)</button><span class="muted small" id="ms-msg"></span></div></div>';
        var G = [], P = [], sel = {};
        function cnt() { var nums = (document.getElementById('ms-num').value || '').split(/[,\s]+/).filter(function (v) { return v.replace(/\D/g, '').length >= 8; }); document.getElementById('ms-n').textContent = Object.keys(sel).length + nums.length; return nums; }
        function drawG() { var q = (document.getElementById('ms-q').value || '').toLowerCase(), k = document.getElementById('ms-k').value;
          var list = k === 'p' ? P.filter(function (p) { return !q || (p.name + ' ' + p.phone).toLowerCase().indexOf(q) !== -1; }).slice(0, 400) : G.filter(function (g) { return !q || g.name.toLowerCase().indexOf(q) !== -1; });
          document.getElementById('ms-g').innerHTML = list.map(function (g) { return '<label class="check"><input type="checkbox" data-gj="' + esc(g.jid) + '" ' + (sel[g.jid] ? 'checked' : '') + '> ' + (k === 'p' ? '👤 ' + esc(g.name || '') + ' <span class="muted small" dir="ltr">' + (g.phone ? '+' + esc(g.phone) : '') + '</span>' : esc(g.name) + ' <span class="muted small">(' + g.size + ')</span>') + '</label>'; }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>'; cnt(); }
        get('meeting_targets').then(function (r) { G = r.groups || []; P = r.people || []; drawG(); });
        document.getElementById('ms-k').onchange = function () { document.getElementById('ms-all').checked = false; drawG(); };
        document.getElementById('ms-q').oninput = drawG; document.getElementById('ms-num').oninput = cnt;
        document.getElementById('ms-g').onchange = function (ev) { var c = ev.target; if (c.dataset.gj) { if (c.checked) sel[c.dataset.gj] = 1; else delete sel[c.dataset.gj]; cnt(); } };
        document.getElementById('ms-all').onchange = function () { var on = this.checked; [].forEach.call(document.querySelectorAll('#ms-g [data-gj]'), function (c) { c.checked = on; if (on) sel[c.dataset.gj] = 1; else delete sel[c.dataset.gj]; }); cnt(); };
        document.getElementById('ms-go').onclick = function () { var b = this, jids = Object.keys(sel).concat(cnt().map(function (v) { return v.replace(/\D/g, '') + '@s.whatsapp.net'; }));
          if (!jids.length) { toast('⚠️ ' + T('Choose at least one group or number')); return; }
          if (!confirm(T('Send the link to') + ' ' + jids.length + ' ' + T('chats?'))) return;
          b.disabled = true; document.getElementById('ms-msg').textContent = T('Sending…');
          post('meeting_send', { jids: jids, text: document.getElementById('ms-t').value }).then(function (x) { b.disabled = false; document.getElementById('ms-msg').textContent = x.ok ? '✓ ' + T('Sent') + ': ' + x.sent + (x.failed && x.failed.length ? ' · ⚠️ ' + x.failed.length + ' ' + T('failed') : '') : '⚠️ ' + (x.error || T('Failed')); }); }; }
    });
    load(); setInterval(function () { if (!document.hidden && !document.getElementById('mt-edit').innerHTML) load(); }, 30000);
    return;
  }

  /* ===================== Scheduled messages ===================== */
  /* ===================== Learning: guide (concepts + linked words) and answer cache ===================== */
  if (page === 'learning') {
    var LG = { tab: new URLSearchParams(location.search).get('tab') || 'guide', rel: {}, concepts: [], open: {}, f: (function () { try { return localStorage.getItem('wi_lgf') || 'review'; } catch (e) { return 'review'; } })() };
    root.innerHTML = hero('🎓', 'Learning', 'What the bot learns from: a guide of concepts and the words linked to them, and a cache of good answers that are reused without spending tokens.', '')
      + '<div class="page-tabs sub">' + [['guide', '🧭 ' + T('Guide')], ['files', '📚 ' + T('File library')], ['rules', '📏 ' + T('Rules')], ['cache', '♻️ ' + T('Answer cache')], ['ai', '🧠 ' + T('AI instructions')], ['savings', '📉 ' + T('Savings')]].map(function (t) { return '<a href="?p=learning&tab=' + t[0] + '"' + (LG.tab === t[0] ? ' class="on"' : '') + '>' + t[1] + '</a>'; }).join('') + '<a href="?p=bot">🧠 ' + T('Knowledge base') + ' ↗</a></div><div id="lg"><div class="empty">' + T('Loading…') + '</div></div>';
    var box = document.getElementById('lg');
    var relSel = function (v) { return '<select class="lg-rel">' + Object.keys(LG.rel).map(function (k) { return '<option value="' + k + '"' + (k === v ? ' selected' : '') + '>' + esc(T(LG.rel[k])) + '</option>'; }).join('') + '</select>'; };
    var termRow = function (t) { return '<div class="lg-term"><input type="text" class="lg-word" autocomplete="off" value="' + esc(t.term || '') + '" placeholder="' + T('word or phrase, e.g.') + ' ' + EX('word') + '" dir="auto">' + relSel(t.relation || 'related') + '<button type="button" class="link-btn bad-t lg-rm">✕</button></div>'; };
    function guide() {
      get('learn_guide').then(function (r) {
        if (!r.ok) return fail(box, r); LG.rel = r.relations; LG.concepts = r.concepts;
        var sug = r.concepts.filter(function (c) { return c.status === 'suggested'; }).length;
        setTimeout(growCard, 0);
        box.innerHTML = '<div class="card"><div class="row wrap"><button class="btn primary sm" id="lg-new">➕ ' + T('New concept') + '</button><button class="chip-btn sm" id="lg-sug">✨ ' + T('Suggest concepts with AI') + '</button><span class="muted small" id="lg-msg"></span></div>'
          + '<div class="lg-try"><b>🧪 ' + T('Try a message') + '</b><div class="row"><input id="lg-t" dir="auto" placeholder="' + T('e.g.') + ' ' + EX('try') + '"><button class="chip-btn sm" id="lg-tb">' + T('Which concepts?') + '</button></div><div id="lg-tr" class="small"></div></div></div>'
          + '<div class="card lg-grow" id="lg-grow"></div>'
          + '<div id="lg-ed"></div>'
          + (sug ? '<div class="pp-view">✨ ' + fmt(sug) + ' ' + T('concepts suggested by AI wait for your approval') + '</div>' : '')
          + (function () { var cnt = { review: 0, active: 0, off: 0, all: r.concepts.length }; r.concepts.forEach(function (c) { if (c.status === 'suggested' || (c.pending || []).length) cnt.review++; if (c.status === 'active') cnt.active++; if (c.status === 'off') cnt.off++; });
              return '<div class="tabs lg-filter">' + [['review', '✨ ' + T('Needs review')], ['active', '✅ ' + T('Approved')], ['off', '⏸ ' + T('Off')], ['all', T('All')]].map(function (f) { return '<button type="button" class="chip-btn sm' + (LG.f === f[0] ? ' on' : '') + '" data-lgf="' + f[0] + '">' + f[1] + ' <b>' + fmt(cnt[f[0]]) + '</b></button>'; }).join('') + '</div>'; })()
          + '<div class="lg-grid">' + (r.concepts.filter(function (c) { return LG.f === 'all' || (LG.f === 'review' ? c.status === 'suggested' || (c.pending || []).length : c.status === LG.f); }).map(function (c) {
            return '<div class="card lg-c st-' + c.status + (c.status === 'active' && !LG.open[c.id] ? ' lg-closed' : '') + '" data-cid="' + c.id + '"><div class="row lg-head" title="' + T('Open / close') + '"><span class="lg-tw">▾</span><b>🧭 ' + esc(c.name) + '</b><span class="pill">' + (c.status === 'active' ? '✅ ' + T('In use') : c.status === 'suggested' ? '✨ ' + T('Suggested') : '⏸ ' + T('Off')) + '</span><span class="muted small">' + fmt(c.hits) + ' ' + T('uses') + '</span></div>'
              + ((c.pending || []).length ? '<div class="lg-pend"><div class="row small"><b>✨ ' + fmt(c.pending.length) + ' ' + T('new items found in chats') + '</b><button class="link-btn" data-gall="' + c.id + '" data-acc="1">✔ ' + T('Accept all') + '</button><button class="link-btn bad-t" data-gall="' + c.id + '">✕ ' + T('Reject all') + '</button></div>' + c.pending.map(function (t) { return '<span class="lbl lg-pchip" dir="auto">"' + esc(t.term) + '" <small>' + esc(T(LG.rel[t.relation] || t.relation)) + (t.seen > 1 ? ' · 🔁' + t.seen : '') + '</small><button type="button" class="link-btn" data-gterm="' + t.id + '" data-acc="1" title="' + T('Accept') + '">✔</button><button type="button" class="link-btn bad-t" data-gterm="' + t.id + '" title="' + T('Reject') + '">✕</button></span>'; }).join('') + '</div>' : '')
              + (c.explain ? '<p class="small" dir="auto">' + esc(c.explain) + '</p>' : '')
              + '<div class="lg-links">' + c.terms.map(function (t) { return '<button type="button" class="lbl lg-word-chip" data-edterm="' + c.id + '" data-term="' + esc(t.term) + '" dir="auto" title="' + T('Edit') + '">"' + esc(t.term) + '" <small>' + esc(T(LG.rel[t.relation] || t.relation)) + '</small> ✏️</button>'; }).join('') + '</div>'
              + '<div class="row">' + (c.status === 'suggested' ? '<button class="chip-btn sm" data-st="active" data-id="' + c.id + '">✔ ' + T('Approve') + '</button>' : '') + (c.status === 'active' ? '<button class="chip-btn sm" data-st="off" data-id="' + c.id + '">⏸ ' + T('Turn off') + '</button>' : '') + (c.status === 'off' ? '<button class="chip-btn sm" data-st="active" data-id="' + c.id + '">▶ ' + T('Turn on') + '</button>' : '')
              + '<button class="chip-btn sm" data-ed="' + c.id + '">✏️ ' + T('Edit') + '</button><button class="link-btn bad-t" data-del="' + c.id + '">🗑</button></div></div>'; }).join('') || '<div class="empty">' + T('No concepts yet. Add one, e.g.') + ' «' + EX('concept') + '» — ' + EX('word') + '…' + '</div>') + '</div>';
      });
    }
    // approved cards stay closed; a click on the title opens them (and Edit opens them too)
    document.addEventListener('click', function (e) { var h = e.target.closest('.lg-c .lg-head'); if (!h) return; var card = h.parentNode; card.classList.toggle('lg-closed'); LG.open[card.dataset.cid] = !card.classList.contains('lg-closed'); });
    document.addEventListener('click', function (e) { var b = e.target.closest('.lg-c [data-ed], .lg-c [data-edterm]'); if (b) { var card = b.closest('.lg-c'); card.classList.remove('lg-closed'); LG.open[card.dataset.cid] = true; } }, true);
    // Automatic growth of the guide: settings, run now, last result
    function growCard() { var el = document.getElementById('lg-grow'); if (!el) return;
      get('learn_grow').then(function (x) { if (!x.ok || !document.getElementById('lg-grow')) return; var G = x.grow, L = G.last || {};
        var hours = ''; for (var h = 0; h < 24; h++) hours += '<option value="' + h + '"' + (+G.hour === h ? ' selected' : '') + '>' + (h < 10 ? '0' : '') + h + ':00</option>';
        el.innerHTML = '<div class="row wrap"><b>🌱 ' + T('Grow the guide from chats') + '</b><span class="muted small">' + T('AI reads the new chats, cache answers and cleaned files, and finds new symptoms, related words, remedies… for the approved concepts.') + '</span></div>'
          + '<div class="row wrap" style="margin-top:8px"><label class="check"><input type="checkbox" id="gg-auto"' + (G.auto ? ' checked' : '') + '> ⏰ ' + T('Automatic every day at') + '</label><select id="gg-hour">' + hours + '</select>'
          + '<span class="muted small">' + T('Read from:') + '</span><label class="check"><input type="checkbox" id="gg-chats"' + (G.chats ? ' checked' : '') + '> 💬 ' + T('Chats') + '</label><label class="check"><input type="checkbox" id="gg-cache"' + (G.cache ? ' checked' : '') + '> ♻️ ' + T('Answer cache') + '</label><label class="check"><input type="checkbox" id="gg-files"' + (G.files ? ' checked' : '') + '> 📄 ' + T('Files') + '</label></div>'
          + '<div class="row wrap" style="margin-top:6px"><label class="check"><input type="checkbox" id="gg-aa"' + (G.auto_approve ? ' checked' : '') + '> ✅ ' + T('Approve a new item by itself when it appears in at least') + '</label><input type="number" id="gg-min" min="2" max="20" value="' + G.min_seen + '" style="width:64px"><span class="small">' + T('chats at least') + '</span><span class="muted small">' + T('(otherwise it waits for your approval)') + '</span></div>'
          + '<div class="row wrap" style="margin-top:8px"><button class="btn primary sm" id="gg-save">💾 ' + T('Save') + '</button><button class="chip-btn sm" id="gg-run"' + (G.running ? ' disabled' : '') + '>▶ ' + T('Collect now') + '</button><span class="small">'
          + (G.running ? '⏳ ' + T('Collecting… within a minute or two') : L.error ? '⚠️ ' + esc(L.error) : L.at ? '✅ ' + T('Last run') + ' ' + esc(when(L.at)) + ' · ' + T('texts read') + ' ' + fmt(L.read || 0) + ' · ✨ ' + fmt(L.terms || 0) + ' ' + T('new items') + (L.auto ? ' (' + fmt(L.auto) + ' ' + T('approved automatically') + ')' : '') + ' · 🧭 ' + fmt(L.concepts || 0) + ' ' + T('new concepts') : T('Not run yet')) + '</span>'
          + (x.review ? '<a class="small bad-t" href="?p=learning&tab=cache">⚠️ ' + fmt(x.review) + ' ' + T('cache answers need review (their concept meaning changed)') + '</a>' : '') + '</div>';
        clearTimeout(window.__ggT); if (G.running) window.__ggT = setTimeout(function () { if (document.getElementById('lg-grow')) guide(); }, 15000); }); }
    document.addEventListener('click', function (e) { var b;
      if (e.target.closest('#gg-save')) { post('learn_grow', { do: 'settings', auto: document.getElementById('gg-auto').checked ? 1 : 0, hour: document.getElementById('gg-hour').value, chats: document.getElementById('gg-chats').checked ? 1 : 0, cache: document.getElementById('gg-cache').checked ? 1 : 0, files: document.getElementById('gg-files').checked ? 1 : 0, auto_approve: document.getElementById('gg-aa').checked ? 1 : 0, min_seen: document.getElementById('gg-min').value }).then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); }); return; }
      if (e.target.closest('#gg-run')) { e.target.closest('#gg-run').disabled = true; post('learn_grow', { do: 'run' }).then(function (x) { toast(x.ok ? '🌱 ' + T('Collecting started — within a minute or two') : '⚠️ ' + x.error); growCard(); }); return; }
      if ((b = e.target.closest('[data-gterm]'))) { post('learn_grow', { do: 'term', id: b.dataset.gterm, accept: b.dataset.acc ? 1 : 0 }).then(function (x) { if (x.ok) { LG.open[b.closest('.lg-c').dataset.cid] = true; guide(); } else toast('⚠️ ' + x.error); }); return; }
      if ((b = e.target.closest('[data-gall]'))) { post('learn_grow', { do: 'all', concept: b.dataset.gall, accept: b.dataset.acc ? 1 : 0 }).then(function (x) { if (x.ok) guide(); else toast('⚠️ ' + x.error); }); return; } });
    document.addEventListener('click', function (e) { var b = e.target.closest('[data-lgf]'); if (!b) return; LG.f = b.dataset.lgf; try { localStorage.setItem('wi_lgf', LG.f); } catch (x) {} guide(); });
    function editor(c) { c = c || { name: '', explain: '', hint: '', terms: [{}] };
      var ed = document.getElementById('lg-ed');
      ed.innerHTML = '<div class="card lg-form"><h3>' + (c.id ? '✏️ ' + T('Edit concept') : '➕ ' + T('New concept')) + '</h3><div class="gcf">'
        + '<label>' + T('Concept') + '<input id="lc-name" dir="auto" value="' + esc(c.name) + '" placeholder="' + esc(EX('concept')) + '"></label>'
        + '<label class="gcf-wide">⭐ ' + T('What it means (short) — the core of the bot\'s reply') + '<textarea id="lc-ex" rows="3" dir="auto">' + esc(c.explain) + '</textarea></label>'
        + '<label class="gcf-wide">' + T('Extra guidance for the reply (optional)') + '<small class="muted">' + T('Only adds to the meaning above: what to advise, what to avoid, which program to point to. Leave empty if the meaning is enough.') + '</small><textarea id="lc-hint" rows="2" dir="auto" placeholder="' + T('e.g. advise keeping the morning and evening adhkar') + '">' + esc(c.hint) + '</textarea></label></div>'
        + '<b>🔗 ' + T('Linked words') + '</b> <span class="muted small">' + T('edit any word in place, change its relation, or remove it') + '</span><div id="lc-terms">' + (c.terms.length ? c.terms : [{}]).map(termRow).join('') + '</div>'
        + '<div class="lg-bulk"><textarea id="lc-bulk" rows="2" dir="auto" placeholder="' + T('Add several words at once — one per line or separated by commas') + '"></textarea><span class="row">' + relSel('related').replace('class="lg-rel"', 'class="lg-rel" id="lc-bulkrel"') + '<button type="button" class="chip-btn sm" id="lc-addmany">➕ ' + T('Add these words') + '</button></span></div>'
        + '<div class="row" style="margin-top:10px"><button class="btn primary sm" id="lc-save">💾 ' + T('Save') + '</button><button class="link-btn" id="lc-x">' + T('Close') + '</button><span class="muted small" id="lc-msg"></span></div></div>';
      document.getElementById('lc-addmany').onclick = function () { var rel = document.getElementById('lc-bulkrel').value, box2 = document.getElementById('lc-terms'), have = [].map.call(box2.querySelectorAll('.lg-word'), function (i) { return i.value.trim(); });
        document.getElementById('lc-bulk').value.split(/[\n,،]+/).map(function (w) { return w.trim(); }).filter(function (w) { return w && have.indexOf(w) === -1; }).forEach(function (w) { box2.insertAdjacentHTML('beforeend', termRow({ term: w, relation: rel })); });
        [].forEach.call(box2.querySelectorAll('.lg-term'), function (row) { if (!row.querySelector('.lg-word').value.trim() && box2.children.length > 1) row.remove(); }); document.getElementById('lc-bulk').value = ''; };
      if (c.focusTerm) { var fi = [].filter.call(ed.querySelectorAll('.lg-word'), function (i) { return i.value === c.focusTerm; })[0]; if (fi) setTimeout(function () { fi.focus(); fi.select(); }, 350); }
      document.getElementById('lc-x').onclick = function () { ed.innerHTML = ''; };
      document.getElementById('lc-save').onclick = function () { var terms = [].map.call(document.querySelectorAll('#lc-terms .lg-term'), function (row) { return { term: row.querySelector('.lg-word').value, relation: row.querySelector('.lg-rel').value }; }).filter(function (t) { return t.term.trim(); });
        post('learn_guide', { do: 'save', id: c.id || 0, name: document.getElementById('lc-name').value, explain: document.getElementById('lc-ex').value, hint: document.getElementById('lc-hint').value, terms: terms }).then(function (x) { if (x.ok) { ed.innerHTML = ''; toast('✓ ' + T('Saved')); guide(); } else document.getElementById('lc-msg').textContent = '⚠️ ' + x.error; }); };
      ed.scrollIntoView({ behavior: 'smooth' }); }
    function cache() {
      get('learn_cache').then(function (r) {
        if (!r.ok) return fail(box, r); var S = r.settings;
        box.innerHTML = '<div class="card"><div class="row wrap"><label class="check"><input type="checkbox" id="lc-on" ' + (S.cache ? 'checked' : '') + '> ♻️ ' + T('Reuse saved answers') + '</label><label class="check"><input type="checkbox" id="lg-on" ' + (S.guide ? 'checked' : '') + '> 🧭 ' + T('Use the guide') + '</label>'
          + '<label>' + T('Match needed') + ' <select id="lc-sim">' + [0.85, 0.9, 0.95].map(function (v) { return '<option value="' + v + '"' + (Math.abs(S.min_sim - v) < 0.001 ? ' selected' : '') + '>' + Math.round(v * 100) + '%</option>'; }).join('') + '</select></label>'
          + '<label>' + T('Keep answers for (days)') + ' <input type="number" id="lc-ttl" min="1" max="365" value="' + S.ttl_days + '" style="width:80px"></label><button class="btn primary sm" id="lc-set">💾 ' + T('Save') + '</button><button class="chip-btn sm" id="lc-clr">🧹 ' + T('Remove expired') + '</button></div>'
          + '<p class="muted small">' + T('Personal messages (names, numbers, appointments, diagnosis, payments, photos, voice notes) are never answered from the cache.') + '</p></div>'
          + '<div class="card"><div class="search-xl"><span>🔎</span><input type="search" id="lc-q" placeholder="' + T('Search questions and answers…') + '" dir="auto"></div><div id="lc-list"></div></div>';
        var draw = function () { var q = (document.getElementById('lc-q').value || '').toLowerCase(), rows = r.rows.filter(function (x) { return !q || (x.question + ' ' + x.answer).toLowerCase().indexOf(q) !== -1; }), P = pg('lcache', rows, draw, 30);
          document.getElementById('lc-list').innerHTML = P.rows.map(function (x) { return '<div class="lc-row' + (x.status === 'off' ? ' off' : '') + '"><div class="lc-q" dir="auto">❓ ' + esc(x.question) + '</div><div class="lc-a" dir="auto" id="lca' + x.id + '">' + esc(x.answer) + '</div>' + (+x.review ? '<div class="small bad-t">⚠️ ' + T('Needs review — the meaning of its concept was changed. Edit or switch it off.') + '</div>' : '') + '<div class="row small muted"><span>♻️ ' + fmt(x.hits) + ' ' + T('reuses') + '</span><span>📉 ' + fmt(x.tokens_saved) + ' ' + T('tokens saved') + '</span><span>' + esc(T(x.source)) + '</span><span>⏳ ' + esc(day(x.expires_at)) + '</span>'
            + '<button class="chip-btn sm" data-ce="' + x.id + '">✏️</button><button class="chip-btn sm" data-co="' + x.id + '" data-s="' + (x.status === 'off' ? 'on' : 'off') + '">' + (x.status === 'off' ? '▶' : '⏸') + '</button><button class="link-btn bad-t" data-cd="' + x.id + '">🗑</button></div></div>'; }).join('') + P.html || '<div class="empty">' + T('No saved answers yet — confident general answers are saved automatically.') + '</div>'; };
        draw(); document.getElementById('lc-q').oninput = function () { pgReset('lcache'); draw(); };
        document.getElementById('lc-set').onclick = function () { post('learn_cache', { do: 'settings', cache: document.getElementById('lc-on').checked ? 1 : 0, guide: document.getElementById('lg-on').checked ? 1 : 0, min_sim: document.getElementById('lc-sim').value, ttl_days: document.getElementById('lc-ttl').value }).then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); }); };
        document.getElementById('lc-clr').onclick = function () { post('learn_cache', { do: 'clear_expired' }).then(function (x) { toast('🧹 ' + fmt(x.removed || 0)); cache(); }); };
        box.onclick = function (e) { var b;
          if ((b = e.target.closest('[data-cd]')) && confirm(T('Delete this saved answer?'))) post('learn_cache', { do: 'delete', id: b.dataset.cd }).then(cache);
          if ((b = e.target.closest('[data-co]'))) post('learn_cache', { do: b.dataset.s, id: b.dataset.co }).then(cache);
          if ((b = e.target.closest('[data-ce]'))) { var x = r.rows.filter(function (y) { return y.id == b.dataset.ce; })[0], slot = document.getElementById('lca' + x.id);
            // edit in place: a full-size box with the whole answer, Save / Cancel (no small browser prompt)
            if (slot.querySelector('textarea')) return;
            slot.innerHTML = '<textarea class="lc-edit" dir="auto">' + esc(x.answer) + '</textarea><div class="row"><button class="btn primary sm" data-cs="' + x.id + '">💾 ' + T('Save') + '</button><button class="link-btn" data-cc="' + x.id + '">' + T('Cancel') + '</button><span class="muted small">' + T('Ctrl + Enter to save') + '</span></div>';
            var ta = slot.querySelector('textarea'); ta.style.height = Math.min(window.innerHeight * 0.7, ta.scrollHeight + 4) + 'px'; ta.focus();
            ta.oninput = function () { ta.style.height = 'auto'; ta.style.height = Math.min(window.innerHeight * 0.7, ta.scrollHeight + 4) + 'px'; };
            ta.onkeydown = function (ev) { if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) slot.querySelector('[data-cs]').click(); if (ev.key === 'Escape') slot.querySelector('[data-cc]').click(); }; return; }
          if ((b = e.target.closest('[data-cc]'))) { var x2 = r.rows.filter(function (y) { return y.id == b.dataset.cc; })[0]; document.getElementById('lca' + x2.id).textContent = x2.answer; return; }
          if ((b = e.target.closest('[data-cs]'))) { var x3 = r.rows.filter(function (y) { return y.id == b.dataset.cs; })[0], val = document.getElementById('lca' + x3.id).querySelector('textarea').value;
            if (!val.trim()) return toast('⚠️ ' + T('Empty answer.')); b.disabled = true;
            post('learn_cache', { do: 'edit', id: x3.id, answer: val }).then(function (y) { if (!y.ok) { b.disabled = false; return toast('⚠️ ' + y.error); } x3.answer = val; x3.source = 'edited'; document.getElementById('lca' + x3.id).textContent = val; toast('✓ ' + T('Saved')); }); } };
      });
    }
    function savings() {
      get('learn_cache').then(function (r) {
        if (!r.ok) return fail(box, r); var S = r.today.by_source, all = S.cache + S.guide + S.ai || 1;
        box.innerHTML = '<div class="h-kpis">'
          + '<div class="hk k1"><div class="hk-ic">📉</div><div class="hk-b"><small>' + T('Tokens saved (all time)') + '</small><b>' + fmt(r.total.saved) + '</b><em>' + fmt(r.total.hits) + ' ' + T('answers from the cache') + '</em></div></div>'
          + '<div class="hk k2"><div class="hk-ic">♻️</div><div class="hk-b"><small>' + T('Saved answers') + '</small><b>' + fmt(r.total.entries) + '</b><em>' + T('about') + ' ' + fmt(r.per_answer) + ' ' + T('tokens each') + '</em></div></div>'
          + '<div class="hk k3"><div class="hk-ic">🤖</div><div class="hk-b"><small>' + T('AI calls today') + '</small><b>' + fmt(r.today.ai_calls) + '</b><em>' + fmt(r.today.ai_tokens) + ' ' + T('tokens') + '</em></div></div></div>'
          + '<div class="card"><h3>' + T('Where today\'s replies came from') + '</h3>' + [['cache', '♻️ ' + T('Cache (no tokens)')], ['guide', '🧭 ' + T('Guide + AI (short context)')], ['ai', '🤖 ' + T('AI only')]].map(function (k) { var p = Math.round(S[k[0]] / all * 100); return '<div class="lg-src"><span>' + k[1] + '</span><div class="bk-bar"><i style="width:' + p + '%"></i></div><b>' + fmt(S[k[0]]) + '</b></div>'; }).join('') + '</div>';
      });
    }
    function files() {
      get('learn_docs').then(function (r) {
        if (!r.ok) return fail(box, r);
        var kb = function (n) { n = +n; return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; };
        var LK = r.linked;
        var linkedHtml = LK ? '<div class="lf-link">🔗 ' + T('Linked Drive folder') + ': <b dir="auto">📁 ' + esc(LK.name) + '</b> <span class="muted small">' + (LK.last ? T('last pulled') + ' ' + esc(when(LK.last.at)) + ' · ➕ ' + fmt(LK.last.added) + ' · 🔄 ' + fmt(LK.last.updated) + ' · ⏸ ' + fmt(LK.last.removed) + ((LK.last.errors || []).length ? ' · ⚠️ ' + LK.last.errors.length : '') : T('first pull within a minute')) + ' · ' + T('pulled every hour') + '</span> <button class="chip-btn sm" id="ld-sync">🔄 ' + T('Pull now') + '</button> <button class="link-btn bad-t" id="ld-unlink">' + T('Unlink') + '</button></div>' : '';
        var IM = r.import, imHtml = IM ? '<div class="lf-link">⬇️ ' + T('Folder import') + ': <b dir="auto">📁 ' + esc(IM.folder) + '</b> <span class="muted small">' + (IM.running ? '⏳ ' + (IM.total ? fmt(IM.done) + ' / ' + fmt(IM.total) : T('starting…')) : '✅ ' + esc(when(IM.at)) + ' · ' + T('found') + ' ' + fmt(IM.found) + ' · ➕ ' + fmt(IM.added) + ' · ⏭ ' + fmt(IM.skipped) + ' ' + T('already imported') + ((IM.errors || []).length ? ' · ⚠️ ' + IM.errors.length : '')) + '</span></div>' : '';
        clearTimeout(window.__lfT); if (IM && IM.running) window.__lfT = setTimeout(function () { if ((!document.getElementById('ld-panel') || !document.getElementById('ld-panel').innerHTML) && !(function () { var a = document.activeElement; return a && box.contains(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName); })()) files(); }, 5000);
        box.innerHTML = linkedHtml + imHtml + '<div class="card"><div class="row" style="justify-content:flex-end;margin-bottom:8px"><button class="chip-btn sm" id="ld-open">☁️ ' + T('From Google Drive') + '</button></div><div id="ld-panel"></div><label class="lg-drop" id="lf-drop"><input type="file" id="lf-in" multiple accept=".docx,.txt,.md,.csv,.html,.htm,.json,.pdf" hidden><span>📤</span><b>' + T('Drop files here or click to choose') + '</b><small class="muted">' + T('Word (.docx), text (.txt / .md), .csv, .html, .json — up to 20 MB each. Arabic PDFs are best uploaded as Word.') + '</small></label><div id="lf-up"></div>'
          + '<div class="lg-try"><b>🔎 ' + T('Search what the bot would find') + '</b><div class="row"><input id="lf-q" dir="auto" placeholder="' + T('e.g.') + ' ' + EX('search') + '"><button class="chip-btn sm" id="lf-qb">' + T('Search') + '</button></div><div id="lf-qr" class="small"></div></div></div>'
          + '<div class="card lf-clean"><div class="row wrap"><b>🧹 ' + T('Clean & refine files') + '</b><span class="muted small">' + T('AI keeps only the useful paragraphs and topics, and removes names of people outside the team (see the business profile), references, groups, sites, links and phone numbers. Protected texts are never changed. The original is kept — you can undo.') + '</span></div>'
            + '<div class="row wrap" style="margin-top:8px"><button class="btn primary sm" id="lc-run">🧹 ' + T('Clean files not cleaned yet') + '</button><button class="chip-btn sm" id="lc-names">👥 ' + T('Names allowed to stay') + '</button><span class="small" id="lc-state"></span></div><div id="lc-npanel"></div></div>'
          + '<div class="card">' + (r.docs.length ? '<table class="tbl"><thead><tr><th>' + T('File') + '</th><th>' + T('Passages') + '</th><th>' + T('Size') + '</th><th>' + T('Used') + '</th><th>' + T('Status') + '</th><th></th></tr></thead><tbody>' + r.docs.map(function (d) {
            return '<tr class="' + (d.status === 'active' ? '' : 'muted') + '"><td><b dir="auto">📄 ' + esc(d.name) + '</b><div class="muted small">' + esc(when(d.created_at)) + ' · ' + esc(d.created_by) + (d.note ? '<br><span class="bad-t">' + esc(T(d.note)) + '</span>' : '') + '</div></td><td>' + fmt(d.chunks) + '</td><td>' + kb(d.size) + '</td><td>' + fmt(d.hits) + '</td>'
              + '<td>' + (d.status === 'unreadable' ? '⚠️ ' + T('Unreadable') : '<label class="inv-sw"><input type="checkbox" data-doc-on="' + d.id + '" ' + (d.status === 'active' ? 'checked' : '') + '><i></i></label>') + '</td>'
              +  '<td>' + ((+d.v_agree || +d.v_conflict || +d.v_wait) ? '<span class="pill" title="' + esc(T('Checked against the team\'s answers in the groups')) + '">✅ ' + fmt(d.v_agree) + ' · ⛔ ' + fmt(d.v_conflict) + (+d.v_wait ? ' · ⏳ ' + fmt(d.v_wait) : '') + '</span> ' : '') + (d.cleaned_at ? '<span class="pill" title="' + esc(when(d.cleaned_at)) + '">🧹 ' + fmt(d.clean_kept) + ' ✓ · ' + fmt(d.clean_dropped) + ' ✕</span> <button class="link-btn" data-cl-undo="' + d.id + '" title="' + T('Undo the clean-up') + '">↩</button> <button class="link-btn" data-cl-one="' + d.id + '" data-redo="1" title="' + T('Clean again') + '">🔁</button> ' : (d.status !== 'unreadable' ? '<button class="chip-btn sm" data-cl-one="' + d.id + '">🧹 ' + T('Clean this file') + '</button> ' : '')) + '<button class="chip-btn sm" data-doc-view="' + d.id + '">👁 ' + T('Passages') + '</button> <button class="link-btn bad-t" data-doc-del="' + d.id + '" data-n="' + esc(d.name) + '">🗑</button></td></tr><tr hidden id="lf-v' + d.id + '"><td colspan="6"><div class="lf-chunks"></div></td></tr>'; }).join('') + '</tbody></table>' : '<div class="empty">' + T('No files yet — upload the team\'s guides, programs or answers and the bot will learn from them.') + '</div>') + '</div>';
        // ---- Google Drive: browse folders, search, import chosen files, or link a folder to pull from automatically
        // files() runs again after every upload / poll: drop the listeners of the previous run first (they kept an old DV and fired twice).
        if (box._lf) box._lf.forEach(function (p) { box.removeEventListener(p[0], p[1]); }); box._lf = [];
        var lfOn = function (t, f) { box._lf.push([t, f]); box.addEventListener(t, f); };
        var DV = { stack: [{ id: '', name: T('My Drive') }], pick: {}, files: [] };
        function clState() { get('learn_clean').then(function (x) { var el = document.getElementById('lc-state'); if (!el || !x.ok) return; var S = x.state;
          if (!S) { el.innerHTML = ''; return; }
          el.innerHTML = S.running ? '⏳ ' + T('Cleaning…') + ' ' + fmt(S.done || 0) + ' / ' + fmt(S.total || 0) + ' ' + T('passages') + '<div class="bk-bar"><i style="width:' + Math.round((S.done || 0) / Math.max(1, S.total || 1) * 100) + '%"></i></div>'
            : (S.error ? '⚠️ ' + esc(S.error) : '✅ ' + T('Last clean-up') + ' ' + esc(when(S.at)) + ' · ' + fmt(S.kept || 0) + ' ' + T('kept') + ' · ' + fmt(S.dropped || 0) + ' ' + T('removed'));
          document.getElementById('lc-run').disabled = !!S.running;
          clearTimeout(window.__lcT); if (S.running) window.__lcT = setTimeout(function () { if (document.getElementById('lc-state')) { clState(); if (Math.random() < 0.25 && !(function () { var a = document.activeElement; return a && box.contains(a) && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName); })()) files(); } }, 6000); }); }
        clState();
        function clStart(ids, redo) { post('learn_clean', { do: 'start', ids: ids, redo: redo ? 1 : 0 }).then(function (x) { toast(x.ok ? '🧹 ' + T('Clean-up started — in the background, within a minute') : '⚠️ ' + x.error); clState(); }); }
        lfOn('click', function (e) { var b;
          if (e.target.closest('#lc-run')) { if (confirm(T('Clean every file that was not cleaned yet? This uses AI tokens (about one call per 6 passages).'))) clStart([], false); return; }
          if ((b = e.target.closest('[data-cl-one]'))) { clStart([+b.dataset.clOne], !!b.dataset.redo); return; }
          if ((b = e.target.closest('[data-cl-undo]'))) { if (confirm(T('Put back the original text of this file?'))) post('learn_clean', { do: 'undo', id: b.dataset.clUndo }).then(files); return; }
          if (e.target.closest('#lc-names')) { var np = document.getElementById('lc-npanel'); if (np.innerHTML) { np.innerHTML = ''; return; } np.innerHTML = '<div class="muted small">⏳</div>';
            get('learn_clean', { names: 1 }).then(function (x) { np.innerHTML = '<div class="lg-bulk"><div class="small">' + T('Kept automatically (group admins, users, team):') + ' <span dir="auto">' + esc((x.names || []).filter(function (n) { return (x.manual || []).indexOf(n) === -1; }).join('، ') || '—') + '</span></div>'
              + '<label class="small">' + T('Extra names to keep — one per line or separated by commas') + '<textarea id="lc-nm" rows="2" dir="auto">' + esc((x.manual || []).join('، ')) + '</textarea></label><div class="row"><button class="btn primary sm" id="lc-nms">💾 ' + T('Save') + '</button></div></div>'; }); return; }
          if (e.target.closest('#lc-nms')) { post('learn_clean', { do: 'names', names: document.getElementById('lc-nm').value }).then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); }); return; } });
        function dvLoad(q) { var pnl = document.getElementById('ld-panel'), cur = DV.stack[DV.stack.length - 1]; pnl.innerHTML = '<div class="empty">' + T('Loading…') + '</div>';
          get('learn_drive', q ? { q: q } : { folder: cur.id }).then(function (x) {
            if (!x.ok) { pnl.innerHTML = '<div class="pp-view">⚠️ ' + esc(T(x.error)) + (x.need ? ' <a href="?p=settings">' + T('Open the Google connection') + ' →</a>' : '') + '</div>'; return; }
            DV.files = x.files;
            pnl.innerHTML = '<div class="ld-box"><div class="row wrap"><div class="ld-crumbs">' + DV.stack.map(function (c, i) { return '<a href="#" data-crumb="' + i + '">' + (i ? '📁 ' : '☁️ ') + esc(c.name) + '</a>'; }).join(' › ') + '</div>'
              + '<div class="search-xl" style="flex:1"><span>🔎</span><input type="search" id="ld-q" placeholder="' + T('Search Drive by file name…') + '" dir="auto" value="' + esc(q || '') + '"></div></div>'
              + (x.files.some(function (f) { return !f.folder; }) ? '<div class="sel-bar"><label class="check"><input type="checkbox" id="ld-all"' + (x.files.filter(function (f) { return !f.folder; }).every(function (f) { return DV.pick[f.id]; }) ? ' checked' : '') + '> ' + T('Select all files shown') + ' (' + fmt(x.files.filter(function (f) { return !f.folder; }).length) + ')</label></div>' : '')
              + '<div class="ld-list">' + (x.files.length ? x.files.map(function (f) { return f.folder ? '<div class="ld-row ld-f" data-folder="' + esc(f.id) + '" data-n="' + esc(f.name) + '"><span>📁</span><b dir="auto">' + esc(f.name) + '</b><button type="button" class="chip-btn sm" data-impfolder="' + esc(f.id) + '" data-n="' + esc(f.name) + '">⬇️ ' + T('Import whole folder') + '</button><span class="muted small">' + T('Open') + ' ›</span></div>'
                : '<label class="ld-row"><input type="checkbox" data-dfile="' + esc(f.id) + '"' + (DV.pick[f.id] ? ' checked' : '') + '><span>' + (f.gdoc ? '📝' : '📄') + '</span><b dir="auto">' + esc(f.name) + '</b><span class="muted small">' + (f.size ? (f.size / 1024).toFixed(0) + ' KB · ' : (f.gdoc ? T('Google document') + ' · ' : '')) + esc(day(f.modified)) + '</span></label>'; }).join('') : '<div class="empty">' + T('No readable files here.') + '</div>') + '</div>'
              + '<div class="row wrap"><button class="btn primary sm" id="ld-imp">⬇️ ' + T('Import selected') + ' (<span id="ld-n">' + Object.keys(DV.pick).length + '</span>)</button>'
              + (cur.id && !q ? '<button class="chip-btn sm" data-impfolder="' + esc(cur.id) + '" data-n="' + esc(cur.name) + '">⬇️ ' + T('Import this whole folder') + '</button><button class="chip-btn sm" id="ld-link">🔗 ' + T('Link this folder (pull from it every hour)') + '</button>' : '') + '<span class="muted small" id="ld-msg"></span></div></div>';
          }); }
        lfOn('click', function (e) { var b;
          if (e.target.closest('#ld-open')) { var pnl = document.getElementById('ld-panel'); if (pnl.innerHTML) { pnl.innerHTML = ''; return; } dvLoad(''); return; }
          if ((b = e.target.closest('[data-impfolder]'))) { e.stopPropagation();
            var deep = confirm(T('Import every readable file in') + ' 📁 «' + b.dataset.n + '»?\n\n' + T('OK = including its subfolders · Cancel = choose again')) ;
            if (!deep && !confirm(T('Import only the files directly in this folder (without subfolders)?'))) return;
            post('learn_drive', { do: 'import_folder', id: b.dataset.impfolder, name: b.dataset.n, deep: deep ? 1 : 0 }).then(function (x) { toast(x.ok ? '⬇️ ' + T('Folder import started — within a minute, in the background') : '⚠️ ' + T(x.error)); if (x.ok) files(); }); return; }
          if ((b = e.target.closest('[data-folder]'))) { DV.stack.push({ id: b.dataset.folder, name: b.dataset.n }); dvLoad(''); return; }
          if ((b = e.target.closest('[data-crumb]'))) { e.preventDefault(); DV.stack = DV.stack.slice(0, +b.dataset.crumb + 1); dvLoad(''); return; }
          if (e.target.closest('#ld-imp')) { var list = Object.keys(DV.pick).map(function (id) { return DV.pick[id]; }); if (!list.length) return toast(T('Choose files first'));
            var msg = document.getElementById('ld-msg'); msg.textContent = '⏳ ' + T('Importing…'); e.target.closest('#ld-imp').disabled = true;
            post('learn_drive', { files: list }).then(function (x) { if (!x.ok) { msg.textContent = '⚠️ ' + x.error; return; } DV.pick = {}; toast('✓ ' + x.results.filter(function (y) { return y.ok; }).length + ' / ' + x.results.length);
              files(); }); return; }
          if (e.target.closest('#ld-link')) { var cur = DV.stack[DV.stack.length - 1]; if (confirm(T('Link this folder? Its files are added now and pulled again every hour (new files added, changed ones updated).') + '\n📁 ' + cur.name)) post('learn_drive', { do: 'link', id: cur.id, name: cur.name }).then(function (x) { toast(x.ok ? '🔗 ' + T('Folder linked — first pull within a minute') : '⚠️ ' + x.error); files(); }); return; }
          if (e.target.closest('#ld-sync')) { post('learn_drive', { do: 'sync' }).then(function (x) { toast(x.ok ? '🔄 ' + T('Pull queued — within a minute') : '⚠️ ' + x.error); }); return; }
          if (e.target.closest('#ld-unlink')) { if (confirm(T('Unlink the folder? Files already pulled stay in the library.'))) post('learn_drive', { do: 'unlink' }).then(files); return; } });
        lfOn('change', function (e) {
          if (e.target.id === 'ld-all') { var on = e.target.checked; DV.files.forEach(function (f) { if (f.folder) return; if (on) DV.pick[f.id] = { id: f.id, mime: f.mime, name: f.name, modified: f.modified }; else delete DV.pick[f.id]; });
            [].forEach.call(box.querySelectorAll('[data-dfile]'), function (c2) { c2.checked = on; }); document.getElementById('ld-n').textContent = Object.keys(DV.pick).length; return; }
          var c = e.target.closest('[data-dfile]'); if (!c) return; var f = DV.files.filter(function (y) { return y.id === c.dataset.dfile; })[0];
          if (c.checked) DV.pick[f.id] = { id: f.id, mime: f.mime, name: f.name, modified: f.modified }; else delete DV.pick[f.id]; document.getElementById('ld-n').textContent = Object.keys(DV.pick).length; });
        lfOn('keydown', function (e) { if (e.target.id === 'ld-q' && e.key === 'Enter') { e.preventDefault(); dvLoad(e.target.value.trim()); } });
        var inp = document.getElementById('lf-in'), drop = document.getElementById('lf-drop'), upb = document.getElementById('lf-up');
        function send(list) { var arr = [].slice.call(list); if (!arr.length) return; var i = 0;
          (function next() { if (i >= arr.length) { files(); return; } var f = arr[i++], fd = new FormData(); fd.append('file', f);
            upb.insertAdjacentHTML('beforeend', '<div class="small" id="lfu' + i + '">⏳ ' + esc(f.name) + '…</div>');
            fetch('?api=learn_docs', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': csrf }, body: fd }).then(safeJson, netFail).then(function (x) {
              document.getElementById('lfu' + i).innerHTML = (x.ok ? (x.status === 'active' ? '✅ ' : '⚠️ ') : '❌ ') + esc(f.name) + ' — ' + (x.ok ? fmt(x.chunks) + ' ' + T('passages') + (x.note ? ' · ' + esc(T(x.note)) : '') : esc(x.error)); next(); }); })(); }
        inp.onchange = function () { send(inp.files); };
        ['dragover', 'dragenter'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('on'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('on'); if (ev === 'drop') send(e.dataTransfer.files); }); });
        document.getElementById('lf-qb').onclick = function () { post('learn_docs', { do: 'search', text: document.getElementById('lf-q').value }).then(function (x) {
          document.getElementById('lf-qr').innerHTML = x.ok && x.hits.length ? x.hits.map(function (h) { return '<div class="lc-row"><b>📄 ' + esc(h.title) + '</b><div class="lc-a" dir="auto">' + esc(h.text) + '</div></div>'; }).join('') : '— ' + T('Nothing found in the files.'); }); };
      });
    }
    function rules() {
      get('learn_rules').then(function (r) {
        if (!r.ok) return fail(box, r); var RL = r.rules.slice();
        function draw() { if (document.getElementById('st-pon')) stRead();
          box.innerHTML = '<div class="card" id="st-card"></div><div class="card"><p class="muted small">' + T('The bot reads the switched-on rules first, in this order, and they override everything else. The same rules appear in the Bot settings box.') + '</p><div id="rl-list">'
            + RL.map(function (x, i) { return '<div class="rl-row' + (x.on ? '' : ' off') + '"><span class="rl-n">' + (i + 1) + '</span><textarea rows="1" data-rl="' + i + '" dir="auto">' + esc(x.text) + '</textarea><label class="inv-sw" title="' + T('On / off') + '"><input type="checkbox" data-rlon="' + i + '" ' + (x.on ? 'checked' : '') + '><i></i></label>'
              + '<button class="chip-btn sm" data-rlup="' + i + '"' + (i ? '' : ' disabled') + '>▲</button><button class="chip-btn sm" data-rldn="' + i + '"' + (i < RL.length - 1 ? '' : ' disabled') + '>▼</button><button class="link-btn bad-t" data-rldel="' + i + '">🗑</button></div>'; }).join('')
            + '</div><div class="row" style="margin-top:10px"><input id="rl-new" dir="auto" placeholder="' + T('New rule, e.g. لا تذكر أسعار') + '" style="flex:1"><button class="chip-btn sm" id="rl-add">➕ ' + T('Add') + '</button></div>'
            + '<div class="row" style="margin-top:10px"><button class="btn primary sm" id="rl-save">💾 ' + T('Save rules') + '</button><span class="muted small" id="rl-msg"></span></div></div>';
          [].forEach.call(box.querySelectorAll('textarea[data-rl]'), function (t) { t.style.height = 'auto'; t.style.height = t.scrollHeight + 'px'; t.oninput = function () { RL[+t.dataset.rl].text = t.value; t.style.height = 'auto'; t.style.height = t.scrollHeight + 'px'; dirty(); }; });
          if (SS) stDraw();
        }
        // Message style (prefix, first-message intro, ending emojis, removed words, style lines for the AI) — all editable
        var SS = JSON.parse(JSON.stringify(r.style));
        function stDraw() { var el = document.getElementById('st-card'); if (!el) return;
          el.innerHTML = '<h3>🎨 ' + T('Message style') + '</h3><p class="muted small">' + T('How every bot message looks. Applies to this number only.') + '</p><div class="gcf">'
            + '<label class="check"><input type="checkbox" id="st-pon"' + (SS.prefix_on ? ' checked' : '') + '> ' + T('Start every message with') + ' <input id="st-pre" value="' + esc(SS.prefix) + '" style="width:70px"></label>'
            + '<label class="check"><input type="checkbox" id="st-ion"' + (SS.intro_on ? ' checked' : '') + '> ' + T('Introduce the bot in its first message in a chat') + '</label><label class="check"><input type="checkbox" id="st-ig"' + (SS.intro_groups ? ' checked' : '') + '> ' + T('also in groups') + '</label>'
            + '<label class="gcf-wide">' + T('Introduction text') + '<textarea id="st-it" rows="2" dir="auto">' + esc(SS.intro_text) + '</textarea></label>'
            + '<label class="gcf-wide">' + T('Emoji at the end of a message') + '<select id="st-em">' + [['fit', T('Only when it fits the words (list below)')], ['none', T('Never')], ['any', T('Leave as the AI wrote it')]].map(function (o) { return '<option value="' + o[0] + '"' + (SS.end_mode === o[0] ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></label></div>'
            + '<div id="st-ems">' + SS.end_emojis.map(function (f, i) { return '<div class="lg-term"><input class="st-e" data-i="' + i + '" value="' + esc(f.e) + '" style="width:60px;flex:0 0 auto"><input class="st-w lg-word" data-i="' + i + '" dir="auto" value="' + esc(f.words) + '" placeholder="' + T('words that make it fit, separated by commas') + '"><button type="button" class="link-btn bad-t" data-stdel="' + i + '">✕</button></div>'; }).join('') + '</div><button type="button" class="chip-btn sm" id="st-add">➕ ' + T('Add emoji') + '</button>'
            + '<div class="gcf" style="margin-top:10px"><label class="check"><input type="checkbox" id="st-sr"' + (SS.strip_romantic ? ' checked' : '') + '> ' + T('Remove hearts, flowers and romantic emojis') + '</label><label class="check"><input type="checkbox" id="st-sa"' + (SS.strip_affection ? ' checked' : '') + '> ' + T('Remove these words of endearment') + '</label>'
            + '<label class="gcf-wide"><textarea id="st-aw" rows="2" dir="auto">' + esc(SS.affection_words) + '</textarea></label>'
            + '<label class="gcf-wide">' + T('Style instructions for the AI — one per line') + '<textarea id="st-pr" rows="5" dir="auto">' + esc(SS.prompt_rules.join('\n')) + '</textarea></label></div>'
            + '<div class="row"><button class="btn primary sm" id="st-save">💾 ' + T('Save style') + '</button><button class="link-btn" id="st-reset">' + T('Back to the starting values') + '</button><span class="muted small" id="st-msg"></span></div>'; }
        function stRead() { var g = function (id) { return document.getElementById(id); };
          SS = { prefix_on: g('st-pon').checked, prefix: g('st-pre').value, intro_on: g('st-ion').checked, intro_groups: g('st-ig').checked, intro_text: g('st-it').value, end_mode: g('st-em').value,
            end_emojis: [].map.call(document.querySelectorAll('#st-ems .lg-term'), function (row) { return { e: row.querySelector('.st-e').value, words: row.querySelector('.st-w').value }; }),
            strip_romantic: g('st-sr').checked, strip_affection: g('st-sa').checked, affection_words: g('st-aw').value, prompt_rules: g('st-pr').value.split('\n') }; }
        function stSave(extra) { stRead(); post('learn_rules', { do: 'style', style: Object.assign({}, SS, extra || {}) }).then(function (x) { if (x.ok) { SS = x.style; stDraw(); toast('✓ ' + T('Saved')); } else document.getElementById('st-msg').textContent = '⚠️ ' + x.error; }); }
        setTimeout(stDraw, 0);
        function dirty() { var m = document.getElementById('rl-msg'); if (m) m.textContent = '● ' + T('Not saved yet'); }
        draw();
        box.onclick = function (e) { var b;
          if (e.target.closest('#st-add')) { stRead(); SS.end_emojis.push({ e: '', words: '' }); stDraw(); return; }
          if ((b = e.target.closest('[data-stdel]'))) { stRead(); SS.end_emojis.splice(+b.dataset.stdel, 1); stDraw(); return; }
          if (e.target.closest('#st-save')) return stSave();
          if (e.target.closest('#st-reset')) { if (confirm(T('Put the message style back to the starting values?'))) stSave({ reset: 1 }); return; }
          if ((b = e.target.closest('[data-rlup]'))) { var i = +b.dataset.rlup; RL.splice(i - 1, 0, RL.splice(i, 1)[0]); draw(); dirty(); }
          else if ((b = e.target.closest('[data-rldn]'))) { var k = +b.dataset.rldn; RL.splice(k + 1, 0, RL.splice(k, 1)[0]); draw(); dirty(); }
          else if ((b = e.target.closest('[data-rldel]'))) { if (confirm(T('Delete this rule?'))) { RL.splice(+b.dataset.rldel, 1); draw(); dirty(); } }
          else if (e.target.closest('#rl-add')) { var v = document.getElementById('rl-new').value.trim(); if (v) { RL.push({ text: v, on: true }); draw(); dirty(); } }
          else if (e.target.closest('#rl-save')) { post('learn_rules', { rules: RL }).then(function (x) { var m = document.getElementById('rl-msg'); if (x.ok) { RL = x.rules.slice(); draw(); document.getElementById('rl-msg').textContent = '✓ ' + T('Saved — the bot uses them from the next reply'); } else m.textContent = '⚠️ ' + x.error; }); } };
        box.onchange = function (e) { var c = e.target.closest('[data-rlon]'); if (c) { RL[+c.dataset.rlon].on = c.checked; c.closest('.rl-row').classList.toggle('off', !c.checked); dirty(); } };
        box.onkeydown = function (e) { if (e.target.id === 'rl-new' && e.key === 'Enter') { e.preventDefault(); document.getElementById('rl-add').click(); } };
      });
    }
    root.addEventListener('change', function (e) { var c = e.target.closest('[data-doc-on]'); if (!c) return; post('learn_docs', { do: c.checked ? 'on' : 'off', id: c.dataset.docOn }).then(function (x) { if (!x.ok) { c.checked = !c.checked; toast('⚠️ ' + x.error); } }); });
    root.addEventListener('click', function (e) { var b;
      if ((b = e.target.closest('[data-doc-del]'))) { if (confirm(T('Delete this file and what was learned from it?') + ' «' + b.dataset.n + '»')) post('learn_docs', { do: 'delete', id: b.dataset.docDel }).then(files); return; }
      if ((b = e.target.closest('[data-ch-orig]'))) { var o = b.closest('.lc-row').querySelector('.lc-a[hidden], .lc-a.muted'); if (o) o.hidden = !o.hidden; return; }
      if ((b = e.target.closest('[data-ch-keep], [data-ch-drop]'))) { var keepIt = !!b.dataset.chKeep; post('learn_clean', { do: keepIt ? 'restore_one' : 'drop_one', chunk: b.dataset.chKeep || b.dataset.chDrop }).then(function () { b.closest('.lc-row').classList.toggle('off', !keepIt); b.outerHTML = keepIt ? '<span class="small">✓</span>' : '<span class="bad-t small">✕</span>'; }); return; }
      if ((b = e.target.closest('[data-doc-view]'))) { var row = document.getElementById('lf-v' + b.dataset.docView); if (!row.hidden) { row.hidden = true; return; } row.hidden = false; var cbox = row.querySelector('.lf-chunks'); cbox.textContent = T('Loading…');
        post('learn_docs', { do: 'chunks', id: b.dataset.docView }).then(function (x) { cbox.innerHTML = x.ok ? x.chunks.map(function (c) { var dr = +c.dropped; return '<div class="lc-row' + (dr ? ' off' : '') + '"><div class="row small"><span class="muted">#' + (+c.idx + 1) + '</span>' + (c.topic ? '<b dir="auto">' + esc(c.topic) + '</b>' : '') + (dr ? '<span class="bad-t">✕ ' + T('Removed') + '</span><button class="link-btn" data-ch-keep="' + c.id + '">↩ ' + T('Keep it') + '</button>' : (c.orig ? '<span class="pill">🧹 ' + T('Cleaned') + '</span>' : '') + '<button class="link-btn bad-t" data-ch-drop="' + c.id + '">✕ ' + T('Remove') + '</button>') + (c.orig && !dr ? '<button class="link-btn" data-ch-orig>' + T('Original') + '</button>' : '') + '</div><div class="lc-a" dir="auto">' + esc(c.text) + '</div>' + (c.orig && !dr ? '<div class="lc-a muted small" dir="auto" hidden>' + esc(c.orig) + '</div>' : '') + '</div>'; }).join('') : '⚠️ ' + esc(x.error); }); return; }
      if (e.target.closest('#lg-new')) return editor(null);
      if ((b = e.target.closest('[data-ed]'))) return editor(LG.concepts.filter(function (c) { return c.id == b.dataset.ed; })[0]);
      if ((b = e.target.closest('[data-edterm]'))) { var cc = LG.concepts.filter(function (c) { return c.id == b.dataset.edterm; })[0]; return editor(Object.assign({}, cc, { focusTerm: b.dataset.term })); }
      if ((b = e.target.closest('[data-del]')) && confirm(T('Delete this concept and its words?'))) return post('learn_guide', { do: 'delete', id: b.dataset.del }).then(guide);
      if ((b = e.target.closest('[data-st]'))) return post('learn_guide', { do: 'status', id: b.dataset.id, status: b.dataset.st }).then(guide);
      if ((b = e.target.closest('.lg-rm'))) return b.closest('.lg-term').remove();
      if (e.target.closest('#lg-sug')) { var m = document.getElementById('lg-msg'); m.textContent = T('AI is reading the knowledge base…'); e.target.closest('#lg-sug').disabled = true; return post('learn_suggest', {}).then(function (x) { m.textContent = x.ok ? '✨ ' + fmt(x.added) + ' ' + T('concepts suggested') : '⚠️ ' + x.error; guide(); }); }
      if (e.target.closest('#lg-tb')) { var t = document.getElementById('lg-t').value; post('learn_guide', { do: 'test', text: t }).then(function (x) { document.getElementById('lg-tr').innerHTML = x.concepts.length ? x.concepts.map(function (c) { return '✅ <b>' + esc(c.name) + '</b> ← ' + c.terms.map(function (t) { return '"' + esc(t.term) + '" ' + esc(T(LG.rel[t.relation] || '')); }).join('، '); }).join('<br>') : '— ' + T('no concept matched'); }); }
    });
    // AI instructions: the business profile (fills the {{placeholders}}) and every instruction template, editable per number
    function aiTab() {
      get('ai_prompts').then(function (r) {
        if (!r.ok) return fail(box, r); var PK = r.pack, labels = { ack_words: T('Short replies that end a talk (the bot stays out of them) — separated by |'), lib_tags: T('Section tags — one per line: #tag | library, audio, video, images, documents or programs | title (empty = off)'), industry: T('What the business is'), language: T('Language of the content'), dialect_rule: T('Language / dialect rule for replies'), output_language: T('Language for notes and summaries'), staff: T('What the team is called'), client: T('What a customer is called'), sacred_rule: T('Texts that must never be changed'), outside_people: T('People whose names are removed from files'), case_types: T('Case types (separated by |)'), case_scope: T('Limit for case analysis'), concept_example: T('Example concept'), term_example: T('Example linked word'), choices_example: T('Example numbered choices'), newcomer_rule: T('Rule for a new person') };
        box.innerHTML = '<div class="card"><h3>🏷️ ' + T('Business profile') + '</h3><p class="muted small">' + T('These values fill the {{placeholders}} in every instruction below. Start from a ready profile, then adjust.') + '</p>'
          + '<div class="row wrap"><label>' + T('Ready profile') + ' <select id="ap-pre">' + Object.keys(r.presets).map(function (k) { return '<option value="' + k + '"' + (PK.preset === k ? ' selected' : '') + '>' + esc(T(r.presets[k].label)) + '</option>'; }).join('') + '</select></label><button class="chip-btn sm" id="ap-load">↺ ' + T('Fill from this profile') + '</button></div>'
          + '<div class="gcf">' + r.common.map(function (k) { return '<label class="gcf-wide">' + esc(labels[k] || k) + ' <small class="muted">{{' + k + '}}</small><textarea data-pk="' + k + '" rows="' + ((PK[k] || '').length > 90 ? 2 : 1) + '" dir="auto">' + esc(PK[k] || '') + '</textarea></label>'; }).join('') + '</div>'
          + '<div class="row"><button class="btn primary sm" id="ap-save">💾 ' + T('Save profile') + '</button></div></div>'
          + '<div class="card"><h3>🧠 ' + T('AI instructions') + '</h3><p class="muted small">' + T('What the AI is told for each task. Edit any of them for this number; empty = the starting text. {{name}} is filled automatically.') + '</p>'
          + r.prompts.map(function (p) { return '<details class="ap-p"><summary><b>' + esc(T(p.title)) + '</b> ' + (p.edited ? '<span class="pill">✏️ ' + T('Edited') + '</span>' : '<span class="muted small">' + T('Starting text') + '</span>') + '</summary>'
            + '<div class="muted small">' + T('Filled by the system:') + ' ' + p.vars.map(function (v) { return '{{' + v + '}}'; }).join(' ') + ' · ' + T('and every profile value above') + '</div>'
            + '<textarea class="ap-t" data-key="' + p.key + '" dir="ltr" rows="14">' + esc(p.text || p.default) + '</textarea>'
            + '<div class="row"><button class="btn primary sm" data-apsave="' + p.key + '">💾 ' + T('Save') + '</button>' + (p.edited ? '<button class="link-btn" data-apreset="' + p.key + '">' + T('Back to the starting text') + '</button>' : '') + '</div></details>'; }).join('') + '</div>';
        box.onclick = function (e) { var b;
          if (e.target.closest('#ap-load')) { var pr = r.presets[document.getElementById('ap-pre').value]; [].forEach.call(box.querySelectorAll('[data-pk]'), function (t) { t.value = pr[t.dataset.pk] || ''; }); return; }
          if (e.target.closest('#ap-save')) { var pk = {}; [].forEach.call(box.querySelectorAll('[data-pk]'), function (t) { pk[t.dataset.pk] = t.value; }); post('ai_prompts', { do: 'pack', preset: document.getElementById('ap-pre').value, pack: pk }).then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); }); return; }
          if ((b = e.target.closest('[data-apsave]'))) { var ta = box.querySelector('textarea[data-key="' + b.dataset.apsave + '"]'), def = r.prompts.filter(function (p) { return p.key === b.dataset.apsave; })[0].default;
            post('ai_prompts', { do: 'prompt', key: b.dataset.apsave, text: ta.value.trim() === def.trim() ? '' : ta.value }).then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); if (x.ok) aiTab(); }); return; }
          if ((b = e.target.closest('[data-apreset]'))) { if (confirm(T('Go back to the starting text for this instruction?'))) post('ai_prompts', { do: 'prompt', key: b.dataset.apreset, reset: 1 }).then(aiTab); } };
      });
    }
    var TABS = { guide: guide, files: files, rules: rules, cache: cache, ai: aiTab, savings: savings }; (TABS[LG.tab] || guide)();
    return;
  }

  // Business settings: the type of business, the names used everywhere, and any word of the interface — per number, by the client themself.
  if (page === 'business') {
    root.innerHTML = hero('🏢', 'Business settings', 'What your business is and the words the system uses: what a customer and a record are called, the stages, and any word of the interface. Changes apply to this number only.', '') + '<div id="bz"><div class="empty">' + T('Loading…') + '</div></div>';
    var bz = document.getElementById('bz');
    function bzLoad() { get('business_cfg').then(function (r) { if (!r.ok) return fail(bz, r); var N = r.names, rows = r.terms.slice();
      bz.innerHTML = '<section class="card bz-sec"><div class="bz-h"><span class="jg-n1">1</span><div><b>' + T('Type of business') + '</b><small>' + T('Choosing a type puts back its ready names, stages and words (your own changes on this page are replaced).') + '</small></div></div>'
        + '<div class="bz-types">' + r.types.map(function (x) { return '<button type="button" class="bz-type' + (x.key === r.type ? ' on' : '') + '" data-type="' + x.key + '"><span>' + x.icon + '</span><b>' + esc(T(x.label)) + '</b><small>' + esc(T(x.desc)) + '</small>' + (x.key === r.type ? '<em>✓ ' + T('Current') + '</em>' : '') + '</button>'; }).join('') + '</div></section>'
        + '<section class="card bz-sec"><div class="bz-h"><span class="jg-n1">2</span><div><b>' + T('Names') + '</b><small>' + T('Used in the menu, the pages, the reports and by the AI.') + '</small></div></div>'
        + '<div class="bz-grid">'
        + '<label class="wide">' + T('What your business does') + '<input id="bz-industry" value="' + esc(N.industry) + '" dir="auto" placeholder="' + esc(T('e.g. a shop that sells phones and accessories')) + '"></label>'
        + '<label>' + T('A customer is called') + '<input id="bz-client" value="' + esc(N.client) + '" dir="auto"></label>'
        + '<label>' + T('Your team is called') + '<input id="bz-staff" value="' + esc(N.staff) + '" dir="auto"></label>'
        + '<label>' + T('One record is called') + '<input id="bz-one" value="' + esc(N.one) + '" dir="auto"></label>'
        + '<label>' + T('Many records are called') + '<input id="bz-many" value="' + esc(N.many) + '" dir="auto"></label>'
        + '<label>' + T('Icon') + '<input id="bz-icon" value="' + esc(N.icon) + '" maxlength="8" style="max-width:90px"></label>'
        + '<label>' + T('Follow-up plans are called') + '<input id="bz-program_label" value="' + esc(N.program_label) + '" dir="auto"></label></div>'
        + '<div class="row wrap"><button class="btn primary sm" id="bz-save-n">💾 ' + T('Save names') + '</button>' + (r.can_cases ? '<a class="chip-btn sm" href="?p=cases&tab=settings">🗂️ ' + T('Stages and form fields') + ' →</a>' : '') + '</div></section>'
        + '<section class="card bz-sec"><div class="bz-h"><span class="jg-n1">3</span><div><b>' + T('Words of the interface') + '</b><small>' + T('Any word or sentence of the system can be renamed. Search for it, then write your word.') + '</small></div><span class="jg-pill" id="bz-cnt"></span></div>'
        + '<div class="search-xl bz-find"><span>🔎</span><input type="search" id="bz-q" placeholder="' + esc(T('Search a word of the system…')) + '" dir="auto"></div><div class="bz-res" id="bz-res" hidden></div>'
        + '<div class="bz-terms" id="bz-terms"></div><div class="row"><button class="btn primary sm" id="bz-save-t">💾 ' + T('Save the words') + '</button><span class="muted small" id="bz-msg"></span></div></section>'
        + (r.library ? '<section class="card bz-sec"><div class="bz-h"><span class="jg-n1">4</span><div><b>' + T('Library sections') + '</b><small>' + T('Messages your team sends are sorted into these sections by their words. Write the words separated by commas.') + '</small></div></div>'
          + '<div id="bz-lib" class="bz-lib"></div><div class="row"><button type="button" class="chip-btn sm" id="bz-lib-add">➕ ' + T('Add section') + '</button><button class="btn primary sm" id="bz-save-l">💾 ' + T('Save sections') + '</button><span class="muted small" id="bz-lmsg"></span></div></section>' : '')
        + (r.can_ai ? '<p class="muted small">🧠 ' + T('Advanced: what the AI is told about your business —') + ' <a href="?p=learning&tab=ai">' + T('Learning → AI instructions') + '</a></p>' : '');
      function drawT() { document.getElementById('bz-cnt').textContent = rows.length + ' ' + T('changed');
        document.getElementById('bz-terms').innerHTML = rows.length ? '<div class="bz-tr bz-th"><span>' + T('In the system') + '</span><span></span><span>' + T('Your word') + '</span><span></span></div>' + rows.map(function (x, i) { return '<div class="bz-tr">' + (x.ar ? '<span class="bz-from" dir="auto" title="' + esc(x.from) + '">' + esc(x.ar) + '</span><span class="muted">→</span>' : '<span></span><span></span>') + '<input data-ti="' + i + '" value="' + esc(x.to) + '" dir="auto"><button type="button" class="link-btn bad-t" data-tx="' + i + '" title="' + esc(T('Back to the system word')) + '">✕</button></div>'; }).join('') : '<div class="muted small">' + T('No word changed yet.') + '</div>'; }
      drawT();
      var LIB = r.library || [];
      function drawL() { var el = document.getElementById('bz-lib'); if (!el) return; el.innerHTML = LIB.map(function (s, i) { return '<div class="bz-lr"><input class="bz-li" data-li="' + i + '" value="' + esc(s.icon) + '" maxlength="4"><input data-ln="' + i + '" value="' + esc(s.name) + '" dir="auto" placeholder="' + esc(T('Section name')) + '"><input data-lw="' + i + '" value="' + esc(s.words) + '" dir="auto" placeholder="' + esc(T('words, separated by commas')) + '"><button type="button" class="link-btn bad-t" data-lx="' + i + '">✕</button></div>'; }).join('') || '<div class="muted small">' + T('No sections — everything goes to «Other».') + '</div>'; }
      function readL() { [].forEach.call(bz.querySelectorAll('[data-ln]'), function (i) { var k = +i.dataset.ln; LIB[k].name = i.value; LIB[k].words = bz.querySelector('[data-lw="' + k + '"]').value; LIB[k].icon = bz.querySelector('[data-li="' + k + '"]').value; }); }
      drawL();
      var qt = null; document.getElementById('bz-q').oninput = function () { var v = this.value.trim(), res = document.getElementById('bz-res'); clearTimeout(qt); if (v.length < 2) { res.hidden = true; return; }
        qt = setTimeout(function () { get('business_cfg', { q: v }).then(function (x) { res.hidden = false; res.innerHTML = (x.items || []).length ? x.items.map(function (it) { return '<button type="button" class="bz-it" data-from="' + esc(it.from) + '" data-ar="' + esc(it.ar) + '"><span dir="auto">' + esc(it.ar) + '</span><small dir="ltr">' + esc(it.from) + '</small></button>'; }).join('') : '<div class="muted small pad">' + T('Nothing found.') + '</div>'; }); }, 300); };
      bz.onclick = function (e) { var b;
        if ((b = e.target.closest('[data-type]'))) { if (b.classList.contains('on')) return; if (!confirm(T('Switch to this type of business? Its ready names, stages and words replace the current ones on this number.'))) return;
          post('business_cfg', { do: 'type', type: b.dataset.type }).then(function (x) { if (x.ok) location.reload(); else toast('⚠️ ' + x.error); }); return; }
        if ((b = e.target.closest('.bz-it'))) { var f = b.dataset.from; if (!rows.some(function (x) { return x.from === f; })) rows.unshift({ from: f, ar: b.dataset.ar, to: b.dataset.ar }); document.getElementById('bz-res').hidden = true; document.getElementById('bz-q').value = ''; drawT(); var i0 = bz.querySelector('[data-ti="0"]'); if (i0) { i0.focus(); i0.select(); } return; }
        if ((b = e.target.closest('[data-tx]'))) { rows.splice(+b.dataset.tx, 1); drawT(); return; }
        if (e.target.closest('#bz-lib-add')) { readL(); LIB.push({ name: '', icon: '📁', words: '' }); drawL(); return; }
        if ((b = e.target.closest('[data-lx]'))) { readL(); LIB.splice(+b.dataset.lx, 1); drawL(); return; }
        if (e.target.closest('#bz-save-l')) { readL(); post('business_cfg', { do: 'library', sections: LIB }).then(function (x) { document.getElementById('bz-lmsg').textContent = x.ok ? '✓ ' + T('Saved — new messages are sorted with it within a minute') : '⚠️ ' + x.error; }); return; }
        if (e.target.closest('#bz-save-n')) { var body = { do: 'names' }; ['industry', 'client', 'staff', 'one', 'many', 'icon', 'program_label'].forEach(function (k) { body[k] = document.getElementById('bz-' + k).value; });
          post('business_cfg', body).then(function (x) { if (x.ok) { toast('✓ ' + T('Saved')); setTimeout(function () { location.reload(); }, 600); } else toast('⚠️ ' + x.error); }); return; }
        if (e.target.closest('#bz-save-t')) { [].forEach.call(bz.querySelectorAll('[data-ti]'), function (i) { rows[+i.dataset.ti].to = i.value; });
          post('business_cfg', { do: 'terms', terms: rows.map(function (x) { return [x.from, x.to]; }) }).then(function (x) { document.getElementById('bz-msg').textContent = x.ok ? '✓ ' + T('Saved — the new words show after the page reloads') : '⚠️ ' + x.error; if (x.ok) setTimeout(function () { location.reload(); }, 900); }); } };
    }); }
    bzLoad();
    return;
  }
  if (page === 'schedule') {
    root.innerHTML = hero('⏰', 'Scheduled messages', 'Messages and statuses waiting to be sent at their time (checked every minute; paced to protect the number).', '')
      + '<div class="card"><div class="row wrap"><div class="search-xl"><span>🔎</span><input type="search" id="sc-q" placeholder="' + T('Search messages…') + '" dir="auto"></div><select id="sc-s"><option value="">' + T('All') + '</option><option value="pending">⏳ ' + T('pending') + '</option><option value="sent">✅ ' + T('sent') + '</option><option value="failed">⚠️ ' + T('failed') + '</option><option value="cancelled">✖️ ' + T('cancelled') + '</option></select></div><div id="sc-list"><div class="empty">' + T('Loading…') + '</div></div><p class="muted small">' + T('To schedule a message: open the chat, write it, then press ⏰ Schedule.') + '</p></div>';
    function loadS() {
      get('schedule').then(function (r) {
        if (!r.ok) return fail(document.getElementById('sc-list'), r);
        var icon = { pending: '⏳', sent: '✅', failed: '⚠️', cancelled: '✖️', expired: '⌛' };
        SC = r.items; scDraw();
      });
    }
    var SC = [];
    function scDraw() {
        var icon = { pending: '⏳', sent: '✅', failed: '⚠️', cancelled: '✖️', expired: '⌛' }, q = (document.getElementById('sc-q').value || '').toLowerCase(), sf = document.getElementById('sc-s').value;
        var items = SC.filter(function (s) { return (!sf || s.state === sf) && (!q || (s.text + ' ' + (s.name || '')).toLowerCase().indexOf(q) !== -1); }); var P = pg('sched', items, scDraw, 20); var r = { items: P.rows };
        document.getElementById('sc-list').innerHTML = r.items.length ? '<table class="tbl"><thead><tr><th>' + T('When') + '</th><th>' + T('To') + '</th><th>' + T('Message') + '</th><th>' + T('State') + '</th><th></th></tr></thead><tbody>' + r.items.map(function (s) {
          return '<tr><td>' + esc(when(s.at)) + '</td><td>' + (s.jid === 'status@broadcast' ? '🟢 ' + T('Status') : '<a href="?p=inbox#' + encodeURIComponent(s.jid) + '">' + esc(s.name || s.jid.split('@')[0]) + '</a>') + '</td><td dir="auto" class="sc-t">' + esc(s.text) + '</td><td>' + (icon[s.state] || '') + ' ' + T(s.state) + (s.error ? '<br><small class="muted">' + esc(s.error) + '</small>' : '') + '</td><td>' + (s.state === 'pending' ? '<button class="link-btn" data-cancel="' + esc(s.id) + '">' + T('Cancel') + '</button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>' + P.html : '<div class="empty">' + T('Nothing scheduled.') + '</div>';
    }
    document.getElementById('sc-q').oninput = function () { pgReset('sched'); scDraw(); }; document.getElementById('sc-s').onchange = function () { pgReset('sched'); scDraw(); };
    root.addEventListener('click', function (e) { var b = e.target.closest('[data-cancel]'); if (!b) return; post('schedule', { do: 'cancel', id: b.dataset.cancel }).then(loadS); });
    loadS();
    return;
  }

  /* ===================== Treatment programs ===================== */
  if (page === 'programs') {
    var PR = { programs: [], enr: [] };
    root.innerHTML = hero('💊', 'Programs', 'A program is a list of daily messages. Enrol a person and each day’s message is sent to them at their hour; replies are tracked as adherence.', isAdmin ? '<button class="btn primary" id="pr-new">➕ ' + T('New program') + '</button>' : '')
      + '<div id="pr-edit"></div><div class="card"><h3>💊 ' + T('Programs') + '</h3><div id="pr-list"></div></div><div class="card"><h3>👤 ' + T('Enrolled people') + '</h3><div class="pr-enrol row wrap"><input id="pe-jid" placeholder="' + T('Phone number (e.g. 2010…)') + '" dir="ltr"><input id="pe-name" placeholder="' + T('Name (optional)') + '" dir="auto"><select id="pe-prog"></select><input type="date" id="pe-start"><select id="pe-hour">' + Array.from({ length: 16 }, function (_, i) { return '<option value="' + (i + 7) + '"' + (i + 7 === 10 ? ' selected' : '') + '>' + (i + 7) + ':00</option>'; }).join('') + '</select><button class="btn sm" id="pe-add">➕ ' + T('Enrol') + '</button></div><div id="pe-list"></div></div>';
    document.getElementById('pe-start').value = new Date().toISOString().slice(0, 10);
    function loadP() {
      get('programs').then(function (r) {
        if (!r.ok) return fail(document.getElementById('pr-list'), r);
        PR.programs = r.programs; PR.enr = r.enrollments;
        document.getElementById('pe-prog').innerHTML = PR.programs.map(function (p) { return '<option value="' + esc(p.id) + '">' + esc(p.name) + '</option>'; }).join('');
        document.getElementById('pr-list').innerHTML = PR.programs.map(function (p) { var n = PR.enr.filter(function (e) { return e.program_id === p.id && e.status === 'active'; }).length; return '<div class="pr-row"><div><b dir="auto">' + esc(p.name) + '</b>' + (p.code ? ' <span class="lc-code" dir="auto">🔑 ' + esc(p.code) + '</span>' : '') + ' <span class="muted small">' + fmt(p.days.length) + ' ' + T('days') + ' · ' + fmt(n) + ' ' + T('active') + '</span><div class="muted small" dir="auto">' + esc(p.desc || '') + '</div>' + (n ? '<div class="muted small" dir="auto">👥 ' + PR.enr.filter(function (e) { return e.program_id === p.id && e.status === 'active'; }).slice(0, 6).map(function (e) { return esc(e.name || String(e.jid || '').split('@')[0]); }).join(', ') + (n > 6 ? ' +' + fmt(n - 6) : '') + '</div>' : '') + '</div>' + (isAdmin ? '<button class="link-btn" data-edit="' + esc(p.id) + '">✏️ ' + T('Edit') + '</button>' : '') + '</div>'; }).join('') || '<div class="empty">' + T('No programs yet.') + '</div>';
        var progName = {}; PR.programs.forEach(function (p) { progName[p.id] = p; });
        document.getElementById('pe-list').innerHTML = PR.enr.length ? '<table class="tbl"><thead><tr><th>' + T('Person') + '</th><th>' + T('Program') + '</th><th>' + T('Progress') + '</th><th>' + T('Replies') + '</th><th>' + T('State') + '</th><th></th></tr></thead><tbody>' + PR.enr.slice().reverse().map(function (e) {
          var p = progName[e.program_id], total = p ? p.days.length : 0, sent = Object.keys(e.sent || {}).length;
          return '<tr><td><a href="?p=customer&jid=' + encodeURIComponent(e.jid) + '">' + esc(e.name || e.jid.split('@')[0]) + '</a><br><small class="muted">' + T('from') + ' ' + esc(e.start) + ' · ' + e.hour + ':00</small></td><td dir="auto">' + esc(p ? p.name : '—') + '</td><td><div class="prog"><i style="width:' + (total ? Math.round(sent / total * 100) : 0) + '%"></i></div><small>' + fmt(sent) + ' / ' + fmt(total) + '</small></td><td>' + fmt(e.replies || 0) + (sent ? ' (' + Math.round((e.replies || 0) / sent * 100) + '%)' : '') + '</td><td>' + T(e.status) + (e.last_error ? '<br><small class="bad-t">' + esc(e.last_error) + '</small>' : '') + '</td><td>'
            + (e.status === 'active' ? '<button class="link-btn" data-es="paused" data-id="' + esc(e.id) + '">⏸ ' + T('Pause') + '</button>' : (e.status === 'paused' ? '<button class="link-btn" data-es="active" data-id="' + esc(e.id) + '">▶️ ' + T('Resume') + '</button>' : '')) + (e.status !== 'stopped' && e.status !== 'done' ? ' <button class="link-btn" data-es="stopped" data-id="' + esc(e.id) + '">⏹ ' + T('Stop') + '</button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div class="empty">' + T('Nobody enrolled yet.') + '</div>';
      });
    }
    function editor(p) {
      p = p || { id: '', name: '', code: '', desc: '', days: [{ day: 1, text: '' }] };
      var el = document.getElementById('pr-edit');
      el.innerHTML = '<div class="card"><h3>✏️ ' + T('Program') + '</h3><div class="row wrap"><input id="pg-name" value="' + esc(p.name) + '" placeholder="' + T('Program name') + '" dir="auto" style="flex:1"><input id="pg-code" value="' + esc(p.code || '') + '" placeholder="' + T('Tag — write it in any chat or group and the program is sent (#program-name)') + '" dir="auto" maxlength="60" style="flex:1"></div><textarea id="pg-desc" rows="2" placeholder="' + T('Description (optional)') + '" dir="auto">' + esc(p.desc || '') + '</textarea><div id="pg-days"></div>'
        + '<div class="row"><button class="chip-btn sm" id="pg-add">➕ ' + T('Add day') + '</button><button class="btn primary sm" id="pg-save">💾 ' + T('Save') + '</button>' + (p.id ? '<button class="link-btn bad-t" id="pg-del">🗑 ' + T('Delete program') + '</button>' : '') + '<button class="link-btn" id="pg-close">' + T('Close') + '</button></div></div>';
      function dayRow(d) { return '<div class="pg-day"><label>' + T('Day') + ' <input type="number" min="1" max="365" class="pg-n" value="' + d.day + '"></label><textarea class="pg-t" rows="3" dir="auto" placeholder="' + T('Message for this day (paste the dhikr/ruqyah text from the Library)') + '">' + esc(d.text) + '</textarea><button class="link-btn pg-rm">✕</button></div>'; }
      document.getElementById('pg-days').innerHTML = p.days.map(dayRow).join('');
      document.getElementById('pg-add').onclick = function () { var n = document.querySelectorAll('.pg-day').length + 1; document.getElementById('pg-days').insertAdjacentHTML('beforeend', dayRow({ day: n, text: '' })); };
      el.onclick = function (e) { if (e.target.closest('.pg-rm')) e.target.closest('.pg-day').remove(); };
      document.getElementById('pg-close').onclick = function () { el.innerHTML = ''; };
      document.getElementById('pg-save').onclick = function () {
        var days = [].map.call(document.querySelectorAll('.pg-day'), function (x) { return { day: Number(x.querySelector('.pg-n').value), text: x.querySelector('.pg-t').value }; });
        post('program_save', { id: p.id, name: document.getElementById('pg-name').value, code: document.getElementById('pg-code').value, desc: document.getElementById('pg-desc').value, days: days }).then(function (r) { if (r.ok) { el.innerHTML = ''; loadP(); toast('✓ ' + T('Saved')); } else toast('⚠️ ' + r.error); });
      };
      if (p.id) document.getElementById('pg-del').onclick = function () { if (confirm(T('Delete this program?'))) post('program_save', { do: 'delete', id: p.id }).then(function () { el.innerHTML = ''; loadP(); }); };
      el.scrollIntoView({ behavior: 'smooth' });
    }
    if (document.getElementById('pr-new')) document.getElementById('pr-new').onclick = function () { editor(null); };
    root.addEventListener('click', function (e) {
      var ed = e.target.closest('[data-edit]'); if (ed) { editor(PR.programs.filter(function (p) { return p.id === ed.dataset.edit; })[0]); return; }
      var es = e.target.closest('[data-es]'); if (es) { post('program_enroll', { id: es.dataset.id, status: es.dataset.es }).then(loadP); }
    });
    document.getElementById('pe-add').onclick = function () {
      var v = document.getElementById('pe-jid').value.trim(), jid = /@/.test(v) ? v : v.replace(/\D/g, '') + '@s.whatsapp.net';
      if (!v || !document.getElementById('pe-prog').value) { toast(T('Choose a chat and a program.')); return; }
      if (!confirm(T('The program messages will be sent to this person automatically every day. Continue?'))) return;
      post('program_enroll', { jid: jid, name: document.getElementById('pe-name').value, program_id: document.getElementById('pe-prog').value, start: document.getElementById('pe-start').value, hour: document.getElementById('pe-hour').value }).then(function (r) { if (r.ok) { loadP(); toast('✓ ' + T('Enrolled')); } else toast('⚠️ ' + r.error); });
    };
    var pre = new URLSearchParams(location.search).get('jid'); if (pre) document.getElementById('pe-jid').value = pre;
    loadP();
    return;
  }

  /* ===================== WhatsApp Status ===================== */
  if (page === 'status') {
    root.innerHTML = hero('🟢', 'WhatsApp Status', 'Post a text, photo or video status now or at a time — shown to the contacts you choose (max 500, one post per minute).', '')
      + '<div class="card st-form"><label>' + T('Text / caption') + '<textarea id="st-text" rows="4" maxlength="700" dir="auto"></textarea></label>'
      + '<label>' + T('Photo or video (optional)') + '<input type="file" id="st-file" accept="image/jpeg,image/png,image/webp,video/mp4"></label>'
      + '<label>' + T('Background colour (text status)') + '<input type="color" id="st-color" value="#0f766e"></label>'
      + '<label>' + T('Who sees it') + '<select id="st-aud"><option value="recent">' + T('People who chatted in the last 3 months') + '</option><option value="customers">' + T('Customers only') + '</option><option value="all">' + T('Everyone in the archive (2 years)') + '</option></select></label>'
      + '<label>' + T('When (empty = now)') + '<input type="datetime-local" id="st-at"></label>'
      + '<div class="row"><button class="btn primary" id="st-go">🟢 ' + T('Post status') + '</button><span class="muted small" id="st-msg"></span></div></div>';
    document.getElementById('st-go').onclick = function () {
      var b = this, msg = document.getElementById('st-msg'), f = document.getElementById('st-file').files[0];
      if (!document.getElementById('st-text').value.trim() && !f) { msg.textContent = T('Write a text or choose a photo/video.'); return; }
      b.disabled = true; msg.textContent = T('Working…');
      var up = f ? (function () { var fd = new FormData(); fd.append('file', f); return fetch('?api=status_upload', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': csrf }, body: fd }).then(safeJson, netFail); })() : Promise.resolve({ ok: true, attachment: '' });
      up.then(function (u) {
        if (!u.ok) throw new Error(u.error);
        var at = document.getElementById('st-at').value;
        return post('status_post', { text: document.getElementById('st-text').value, attachment: u.attachment, audience: document.getElementById('st-aud').value, color: document.getElementById('st-color').value, at: at ? at : '' });
      }).then(function (r) { b.disabled = false; msg.textContent = r.ok ? (r.scheduled ? '⏰ ' + T('Scheduled') : '✓ ' + T('Posted to') + ' ' + fmt(r.audience) + ' ' + T('contacts')) : '⚠️ ' + (r.error || T('Failed')); })
        .catch(function (e) { b.disabled = false; msg.textContent = '⚠️ ' + e.message; });
    };
    return;
  }

  /* ===================== Number health ===================== */
  if (page === 'health') {
    root.innerHTML = hero('❤️', 'Number health', 'Connection of each WhatsApp number, disconnects and failed sends in the last 24 hours. Admins get a WhatsApp alert when a number is down for 10 minutes or logged out.', '<button class="chip-btn" id="hl-ref">🔄 ' + T('Refresh') + '</button>') + '<div id="hl-list"><div class="empty">' + T('Loading…') + '</div></div>';
    function loadH() {
      get('health').then(function (r) {
        if (!r.ok) return fail(document.getElementById('hl-list'), r);
        var R = r.resources, rs = document.getElementById('hl-res');
        if (!rs) { rs = document.createElement('div'); rs.id = 'hl-res'; rs.className = 'card'; document.getElementById('hl-list').parentNode.insertBefore(rs, document.getElementById('hl-list')); }
        if (R) { var L = R.limits || {}, bar = function (v, max) { var p = Math.min(100, Math.round(v / Math.max(1, max) * 100)); return '<div class="bk-bar"><i style="width:' + p + '%;' + (p > 85 ? 'background:var(--bad)' : '') + '"></i></div><small class="muted">' + p + '%</small>'; };
          rs.innerHTML = '<h3>🖥️ ' + T('Server resources') + ' <small class="muted">' + esc(when(R.at)) + '</small></h3><div class="res-grid">'
            + '<div><b>🧠 ' + T('Memory') + '</b><div class="res-n">' + fmt(R.mem_mb) + ' / ' + fmt(L.pmem_mb) + ' MB</div>' + bar(R.mem_mb, L.pmem_mb) + '</div>'
            + '<div><b>🧵 ' + T('Processes & threads') + '</b><div class="res-n">' + fmt(R.threads) + ' / ' + fmt(L.nproc) + '</div>' + bar(R.threads, L.nproc) + '<small class="muted">' + fmt(R.procs) + ' ' + T('processes') + '</small></div>'
            + '<div><b>💾 ' + T('Disk') + '</b><div class="small">' + T('Inbox data') + ': ' + fmt((R.disk || {}).inbox_data) + ' MB<br>' + T('Message archives') + ': ' + fmt((R.disk || {}).archives) + ' MB<br>' + T('Media') + ': ' + fmt((R.disk || {}).media) + ' MB</div></div>'
            + '<div><b>📌 ' + T('Biggest users of memory') + '</b><div class="small" dir="ltr">' + Object.keys(R.top || {}).map(function (k) { return esc(k) + ': ' + fmt(R.top[k]) + ' MB'; }).join('<br>') + '</div></div></div>'
            + '<p class="muted small">' + T('Checked every minute. The super admin gets a WhatsApp alert when memory or threads pass 85% of the plan.') + '</p>'; } else rs.remove();
        document.getElementById('hl-list').innerHTML = r.accounts.map(function (a) {
          var ok = a.ok && a.connected, d = a.day || {};
          var score = !a.ok ? 0 : (!a.connected ? 20 : Math.max(40, 100 - (d.disconnects || 0) * 10 - (d.send_failed || 0) * 3 - (a.restarts || 0) * 5));
          return '<div class="card hl-card"><div class="hl-h"><b>📱 ' + esc(a.name) + '</b><span class="pill ' + (ok ? 'good' : 'bad') + '">' + (ok ? '✅ ' + T('Connected') : (a.logged_out ? '⛔ ' + T('Logged out — link again') : '⚠️ ' + T('Not connected'))) + '</span><span class="hl-score" style="--s:' + score + '">' + score + '</span></div>'
            + '<div class="kpis"><div><b>' + fmt(d.disconnects || 0) + '</b><span>' + T('Disconnects (24h)') + '</span></div><div><b>' + fmt(d.send_failed || 0) + '</b><span>' + T('Failed sends (24h)') + '</span></div><div><b>' + fmt(a.restarts || 0) + '</b><span>' + T('Guard restarts (24h)') + '</span></div><div><b>' + (a.uptime_s ? Math.round(a.uptime_s / 3600) + 'h' : '—') + '</b><span>' + T('Running for') + '</span></div></div>'
            + (a.last_error ? '<div class="muted small">' + T('Last error') + ': ' + esc(a.last_error) + '</div>' : '')
            + (a.events && a.events.length ? '<details><summary>' + T('Recent events') + '</summary>' + a.events.map(function (e) { return '<div class="small">' + esc(when(e.at)) + ' · ' + esc(e.type) + ' ' + esc(e.detail) + '</div>'; }).join('') + '</details>' : '') + '</div>';
        }).join('');
      });
    }
    document.getElementById('hl-ref').onclick = loadH; loadH(); setInterval(loadH, WIR(30000));
    return;
  }

  /* ===================== Daily report ===================== */
  if (page === 'report') {
    root.innerHTML = hero('📊', 'Daily report', 'Every day at the chosen hour, admins get a WhatsApp summary: active chats, chats waiting for a reply, new cases, program messages, disconnects and overdue invoices.', '') + '<div class="card" id="rp"><div class="empty">' + T('Loading…') + '</div></div>';
    get('report_cfg').then(function (r) {
      var c = r.cfg || {};
      document.getElementById('rp').innerHTML = '<label class="check"><input type="checkbox" id="rp-on" ' + (c.enabled ? 'checked' : '') + '> ' + T('Send the daily report') + '</label>'
        + '<label>' + T('Hour') + ' <select id="rp-hour">' + Array.from({ length: 24 }, function (_, i) { return '<option value="' + i + '" ' + (Number(c.hour) === i ? 'selected' : '') + '>' + i + ':00</option>'; }).join('') + '</select></label>'
        + '<p class="muted small">' + T('Sent to') + ': ' + (r.recipients.length ? esc(r.recipients.join(', ')) : '⚠️ ' + T('No admin has a WhatsApp number in Users.')) + (c.last_date ? ' · ' + T('Last sent') + ': ' + esc(c.last_date) : '') + '</p>'
        + '<div class="row"><button class="btn primary sm" id="rp-save">💾 ' + T('Save') + '</button><button class="chip-btn sm" id="rp-prev">👁 ' + T('Preview') + '</button><button class="chip-btn sm" id="rp-send">📤 ' + T('Send now') + '</button><span class="muted small" id="rp-msg"></span></div><pre id="rp-out" class="rp-out" dir="auto" hidden></pre>';
      document.getElementById('rp-save').onclick = function () { post('report_cfg', { enabled: document.getElementById('rp-on').checked ? 1 : 0, hour: document.getElementById('rp-hour').value }).then(function (x) { document.getElementById('rp-msg').textContent = x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error; }); };
      document.getElementById('rp-prev').onclick = function () { document.getElementById('rp-msg').textContent = T('Working…'); post('report_cfg', { preview: 1 }).then(function (x) { document.getElementById('rp-msg').textContent = ''; var o = document.getElementById('rp-out'); o.hidden = false; o.textContent = x.text || x.error; }); };
      document.getElementById('rp-send').onclick = function () { document.getElementById('rp-msg').textContent = T('Working…'); post('report_cfg', { send_now: 1 }).then(function (x) { document.getElementById('rp-msg').textContent = x.ok ? '✓ ' + T('Sent to') + ' ' + x.sent : '⚠️ ' + x.error; }); };
    });
    return;
  }

  /* ===================== Google Drive backup ===================== */
  if (page === 'backup') {
    root.innerHTML = hero('☁️', 'Google Drive backup', 'A daily copy of the Inbox data and every number’s message archive (login keys are never included) is uploaded to your Google Drive, in a folder “WhatsApp Inbox Backups”.', '') + '<div class="card" id="bk"><div class="empty">' + T('Loading…') + '</div></div>';
    function loadB() {
      get('backup_cfg').then(function (r) {
        if (!r.ok) return fail(document.getElementById('bk'), r);
        var h = '';
        if (!r.connected) {
          var L = function (u, t) { return '<a href="' + u + '" target="_blank" rel="noopener">' + t + ' ↗</a>'; };
          h += '<h3>1️⃣ ' + T('Connect Google Drive') + '</h3><ol class="small gsteps">'
            + '<li>' + T('Enable the APIs:') + ' ' + L('https://console.cloud.google.com/apis/library/drive.googleapis.com', 'Google Drive API') + ' · ' + L('https://console.cloud.google.com/apis/library/calendar-json.googleapis.com', 'Google Calendar API') + ' <span class="muted">(' + T('press “Enable” on each') + ')</span></li>'
            + '<li>' + L('https://console.cloud.google.com/apis/credentials/consent', T('OAuth consent screen')) + ': ' + T('choose “External”, fill the app name and your email, then add your Gmail under “Test users”.') + '</li>'
            + '<li>' + L('https://console.cloud.google.com/apis/credentials/oauthclient', T('Create an OAuth client ID')) + ' → ' + T('type “Web application”.') + '</li>'
            + '<li>' + T('Authorised redirect URI:') + ' <code dir="ltr">' + esc(r.redirect) + '</code> <button type="button" class="link-btn" data-copy-link="' + esc(r.redirect) + '">📋 ' + T('Copy') + '</button></li>'
            + '<li>' + T('Paste the Client ID and secret here, then Connect.') + ' ' + L('https://console.cloud.google.com/apis/credentials', T('Your credentials')) + '</li></ol>'
            + '<div class="row wrap"><input id="bk-id" placeholder="Client ID" value="' + esc(r.client_id) + '" dir="ltr" style="flex:2"><input id="bk-sec" type="password" placeholder="' + (r.has_secret ? '•••••• ' + T('saved') : 'Client secret') + '" dir="ltr" style="flex:1"><button class="btn primary" id="bk-con">🔗 ' + T('Connect Google Drive') + '</button></div>';
        } else {
          var mb = function (b) { b = Number(b || 0); return b > 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : (b / 1048576).toFixed(1) + ' MB'; };
          var P = r.progress || {}, run = r.busy || P.running;
          h += '<div class="row"><span class="pill good">✅ ' + T('Connected') + (r.email ? ' · ' + esc(r.email) : '') + '</span><button class="link-btn" id="bk-dis">' + T('Disconnect') + '</button></div>';
          // progress
          if (run) { var pct = P.bytes_total ? Math.round(P.bytes_done / P.bytes_total * 100) : (P.total ? Math.round(P.done / P.total * 100) : 0);
            h += '<div class="bk-prog"><div class="row"><b>⏳ ' + (P.phase === 'package' ? '📦 ' + T('Building the package') + ' — ' + T(({ database: 'database dump', packing: 'packing files', upload: 'uploading to Drive' })[P.step] || P.step || '') + '…' : P.phase === 'archive' ? T('Archiving deleted files…') : (P.running ? T('Backing up…') : T('Waiting for the worker (within a minute)…'))) + '</b><span class="muted small">' + (P.total ? fmt(P.done) + ' / ' + fmt(P.total) + ' ' + T('files') + ' · ' + mb(P.bytes_done) + ' / ' + mb(P.bytes_total) : '') + '</span></div><div class="bk-bar"><i style="width:' + pct + '%"></i></div><div class="muted small" dir="ltr">' + esc(P.current || '') + '</div></div>'; }
          // types
          h += '<h3>🧩 ' + T('What to back up') + '</h3><div class="bk-types">' + Object.keys(r.types_all).map(function (k) { var t = r.types_all[k], d = (r.on_drive || {})[k];
            return '<label class="bk-type"><input type="checkbox" value="' + k + '" ' + (r.types.indexOf(k) !== -1 ? 'checked' : '') + '><span><b>' + esc(T(t[0])) + '</b><small>' + esc(T(t[1])) + '</small>' + (d ? '<em>☁️ ' + fmt(d.files) + ' ' + T('files') + ' · ' + mb(d.bytes) + '</em>' : '<em>' + T('not on Drive yet') + '</em>') + '</span></label>'; }).join('')
            + '</div><label class="check"><input type="checkbox" id="bk-full" ' + (r.types.length === Object.keys(r.types_all).length ? 'checked' : '') + '> 🗄️ ' + T('Full backup (all of the above)') + '</label>'
            + '<p class="muted small">♻️ ' + T('Incremental: only new or changed files are uploaded. Files deleted on the server are not lost — they move on Drive into a new dated archive.') + '</p>'
            + '<div class="row wrap"><label class="check"><input type="checkbox" id="bk-on" ' + (r.enabled ? 'checked' : '') + '> ' + T('Back up every day') + '</label> <label>' + T('Hour') + ' <select id="bk-hour">' + Array.from({ length: 24 }, function (_, i) { return '<option value="' + i + '" ' + (Number(r.hour) === i ? 'selected' : '') + '>' + i + ':00</option>'; }).join('') + '</select></label></div>'
            + '<div class="row"><button class="btn primary sm" id="bk-save">💾 ' + T('Save') + '</button><button class="chip-btn sm" id="bk-run" ' + (run ? 'disabled' : '') + '>☁️ ' + (run ? T('Backing up…') : T('Back up now')) + '</button><span class="muted small" id="bk-msg"></span></div>';
          if (r.last) h += '<p class="small">' + (r.last.ok ? '✅' : '⚠️') + ' ' + T('Last backup') + ': ' + esc(when(r.last.at)) + (r.last.uploaded !== undefined ? ' · ⬆️ ' + fmt(r.last.uploaded) + ' ' + T('uploaded') + ' · ✔️ ' + fmt(r.last.unchanged) + ' ' + T('unchanged') + (r.last.archived ? ' · 🗃️ ' + fmt(r.last.archived) + ' ' + T('archived') : '') + (r.last.partial ? ' · ' + T('continues automatically') : '') : '') + (r.last.error ? ' · <span class="bad-t">' + esc(r.last.error) + '</span>' : '') + '</p>';
          // archives
          h += '<h3>🗃️ ' + T('Archives of deleted files') + '</h3>' + (r.archives.length ? '<table class="tbl"><thead><tr><th>' + T('Archive') + '</th><th>' + T('Files') + '</th><th>' + T('Size') + '</th><th></th></tr></thead><tbody>' + r.archives.map(function (a) { return '<tr><td><b>' + esc(a.name) + '</b><div class="muted small" dir="ltr">' + esc((a.paths || []).slice(0, 3).join(' · ')) + ((a.paths || []).length > 3 ? ' …' : '') + '</div></td><td>' + fmt(a.files) + '</td><td>' + mb(a.size) + '</td><td><a class="chip-btn sm" target="_blank" rel="noopener" href="https://drive.google.com/drive/folders/' + esc(a.id) + '">↗ ' + T('Open') + '</a> <button class="chip-btn sm bad-t" data-del-arc="' + esc(a.id) + '" data-n="' + esc(a.name) + '">🗑 ' + T('Delete') + '</button></td></tr>'; }).join('') + '</tbody></table>' : '<div class="empty small">' + T('No archives yet — one is made only when files that were backed up are deleted from the server.') + '</div>');
          // packages: one file per package, downloadable and movable to another server
          if (r.can_restore) {
            h += '<h3>📦 ' + T('Backup packages') + '</h3><p class="muted small">' + T('One compressed file you can download and move to another server. WhatsApp login keys are never included — numbers are linked again with their QR code.') + '</p><div class="bk-types">'
              + Object.keys(r.package_kinds).map(function (k) { var t = r.package_kinds[k]; return '<div class="bk-type bk-pk"><span><b>' + esc(T(t[0])) + '</b><small>' + esc(T(t[1])) + '</small></span><button class="chip-btn sm" data-pkg="' + k + '" ' + (run ? 'disabled' : '') + '>📦 ' + T('Make it') + '</button></div>'; }).join('') + '</div>';
            h += r.packages.length ? '<table class="tbl"><thead><tr><th>' + T('Package') + '</th><th>' + T('Size') + '</th><th>' + T('When') + '</th><th></th></tr></thead><tbody>' + r.packages.map(function (p) { return '<tr><td><b>' + esc(T((r.package_kinds[p.kind] || [p.kind])[0])) + '</b><div class="muted small" dir="ltr">' + esc(p.name) + '</div></td><td>' + mb(p.size) + '</td><td>' + esc(when(p.at)) + '</td><td><a class="chip-btn sm" target="_blank" rel="noopener" href="https://drive.google.com/file/d/' + esc(p.id) + '/view">⬇️ ' + T('Download') + '</a> <button class="chip-btn sm bad-t" data-del-pkg="' + esc(p.id) + '" data-n="' + esc(p.name) + '">🗑</button></td></tr>'; }).join('') + '</tbody></table>' : '<div class="empty small">' + T('No packages yet.') + '</div>';
          }
          // restore
          if (r.can_restore) {
            h += '<h3>♻️ ' + T('Restore from Google Drive') + '</h3><div class="bk-rs"><div class="row wrap">'
              + '<label>' + T('From') + ' <select id="rs-src"><option value="current">☁️ ' + T('Latest copy (Current)') + '</option>' + r.archives.map(function (a) { return '<option value="' + esc(a.id) + '">🗃️ ' + esc(a.name) + '</option>'; }).join('') + '</select></label>'
              + '<label>' + T('Mode') + ' <select id="rs-mode"><option value="missing">🛟 ' + T('Only files missing on the server (safe)') + '</option><option value="overwrite">⚠️ ' + T('Replace with the Drive copy') + '</option></select></label></div>'
              + '<div class="row wrap">' + Object.keys(r.types_all).map(function (k) { return '<label class="check"><input type="checkbox" class="rs-type" value="' + k + '"> ' + esc(T(r.types_all[k][0])) + '</label>'; }).join('') + '<button type="button" class="link-btn" id="rs-pick">📄 ' + T('or choose files…') + '</button></div>'
              + '<div id="rs-files" class="rs-files" hidden></div>'
              + '<div class="row"><button class="btn sm" id="rs-go">♻️ ' + T('Restore') + '</button><span class="muted small" id="rs-msg"></span></div>'
              + '<p class="muted small">' + T('Before any file is replaced, the server keeps its own copy in backups/restore-safety, so a restore can be undone. Data that lives in the database is updated to match.') + '</p>'
              + (r.last_restore ? '<p class="small">' + (r.last_restore.ok ? '✅' : '⚠️') + ' ' + T('Last restore') + ': ' + esc(when(r.last_restore.at)) + ' · ♻️ ' + fmt(r.last_restore.restored || 0) + ' ' + T('restored') + ' · ⏭ ' + fmt(r.last_restore.skipped || 0) + ' ' + T('skipped') + (r.last_restore.safety_folder ? ' · 🛟 ' + esc(r.last_restore.safety_folder) : '') + ((r.last_restore.errors || []).length ? ' · <span class="bad-t">' + esc(r.last_restore.errors.slice(0, 2).join(' · ')) + '</span>' : '') + '</p>' : '') + '</div>';
          }
          if (r.jobs && r.jobs.length) h += '<h3>' + T('Recent backups') + '</h3><table class="tbl"><tbody>' + r.jobs.map(function (j) { var x = j.result || {}; return '<tr><td>' + (j.kind === 'restore' ? '♻️ ' : j.kind === 'package' ? '📦 ' : '☁️ ') + esc(when(j.started_at || j.run_at)) + '</td><td>' + ({ queued: '⏳ ' + T('waiting'), running: '🔄 ' + T('running'), done: '✅', failed: '⚠️' })[j.status] + '</td><td class="small">' + (x.kind && x.size ? esc(T(((r.package_kinds || {})[x.kind] || [x.kind])[0])) + ' · ' + mb(x.size) : '') + (x.restored !== undefined ? '♻️ ' + fmt(x.restored) + ' · ⏭ ' + fmt(x.skipped) : '') + (x.uploaded !== undefined ? '⬆️ ' + fmt(x.uploaded) + ' · ✔️ ' + fmt(x.unchanged) + (x.archived ? ' · 🗃️ ' + fmt(x.archived) : '') + ' · ' + mb(x.bytes) : esc(x.error || '')) + '</td><td class="small muted">' + esc(j.by_user || '') + '</td></tr>'; }).join('') + '</tbody></table>';
        }
        document.getElementById('bk').innerHTML = h;
        var q = function (id) { return document.getElementById(id); };
        if (q('bk-con')) q('bk-con').onclick = function () { post('backup_cfg', { do: 'connect', client_id: q('bk-id').value, client_secret: q('bk-sec').value }).then(function (x) { if (x.ok) location.href = x.url; else toast('⚠️ ' + x.error); }); };
        if (q('bk-dis')) q('bk-dis').onclick = function () { if (confirm(T('Disconnect Google Drive?'))) post('backup_cfg', { do: 'disconnect' }).then(loadB); };
        var types = function () { return [].map.call(document.querySelectorAll('.bk-type input:checked'), function (i) { return i.value; }); };
        if (q('bk-full')) q('bk-full').onchange = function () { var on = this.checked; [].forEach.call(document.querySelectorAll('.bk-type input'), function (i) { i.checked = on; }); };
        [].forEach.call(document.querySelectorAll('.bk-type input'), function (i) { i.onchange = function () { q('bk-full').checked = types().length === document.querySelectorAll('.bk-type input').length; }; });
        [].forEach.call(document.querySelectorAll('[data-del-arc]'), function (b) { b.onclick = function () { if (confirm(T('Delete the archive') + ' «' + b.dataset.n + '»? ' + T('It goes to the Google Drive trash (kept there 30 days).'))) post('backup_cfg', { do: 'delete_archive', id: b.dataset.delArc }).then(function (x) { if (!x.ok) toast('⚠️ ' + x.error); loadB(); }); }; });
        if (q('bk-save')) q('bk-save').onclick = function () { if (!types().length) { q('bk-msg').textContent = '⚠️ ' + T('Choose at least one type'); return; } post('backup_cfg', { do: 'types', types: types() }); post('backup_cfg', { do: 'settings', enabled: q('bk-on').checked ? 1 : 0, hour: q('bk-hour').value, media: types().indexOf('media') !== -1 ? 1 : 0 }).then(function (x) { q('bk-msg').textContent = x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error; }); };
        if (q('bk-run')) q('bk-run').onclick = function () { if (!types().length) { q('bk-msg').textContent = '⚠️ ' + T('Choose at least one type'); return; } this.disabled = true; post('backup_cfg', { do: 'types', types: types() }).then(function () { return post('backup_cfg', { do: 'run', types: types() }); }).then(loadB); };
        var RS = { files: null, picked: {} };
        if (q('rs-pick')) q('rs-pick').onclick = function () { var box = q('rs-files'); box.hidden = false; box.innerHTML = T('Loading…');
          post('backup_cfg', { do: 'restore_list', source: q('rs-src').value }).then(function (x) { if (!x.ok) { box.textContent = '⚠️ ' + x.error; return; } RS.files = x.files; RS.picked = {};
            box.innerHTML = '<input type="search" id="rs-q" placeholder="' + T('Search files…') + '" dir="ltr"><div id="rs-list"></div>'; drawRS(); q('rs-q').oninput = drawRS; }); };
        function drawRS() { var qq = (q('rs-q').value || '').toLowerCase();
          q('rs-list').innerHTML = RS.files.filter(function (f) { return !qq || f.path.toLowerCase().indexOf(qq) !== -1; }).slice(0, 300).map(function (f) { return '<label class="check small" dir="ltr"><input type="checkbox" data-rsf="' + esc(f.path) + '" ' + (RS.picked[f.path] ? 'checked' : '') + '> ' + esc(f.path) + ' <span class="muted">' + (f.size / 1048576).toFixed(2) + ' MB' + (f.exists ? '' : ' · ' + T('missing on server')) + '</span></label>'; }).join('') || '<div class="muted small">' + T('No files.') + '</div>'; }
        if (q('rs-files')) q('rs-files').onchange = function (e) { var c = e.target.closest('[data-rsf]'); if (c) { if (c.checked) RS.picked[c.dataset.rsf] = 1; else delete RS.picked[c.dataset.rsf]; } };
        if (q('rs-src')) q('rs-src').onchange = function () { q('rs-files').hidden = true; RS.files = null; RS.picked = {}; };
        if (q('rs-go')) q('rs-go').onclick = function () { var files = Object.keys(RS.picked), types = [].map.call(document.querySelectorAll('.rs-type:checked'), function (i) { return i.value; }), mode = q('rs-mode').value;
          if (!files.length && !types.length) { q('rs-msg').textContent = '⚠️ ' + T('Choose what to restore.'); return; }
          var what = files.length ? files.length + ' ' + T('files') : types.map(function (k) { return T(r.types_all[k][0]); }).join('، ');
          if (!confirm(T('Restore') + ': ' + what + '\n' + (mode === 'overwrite' ? T('Files on the server will be replaced by the Drive copy (the server keeps a safety copy).') : T('Only files missing on the server will be brought back.')))) return;
          this.disabled = true; post('backup_cfg', { do: 'restore', source: q('rs-src').value, types: files.length ? [] : types, files: files, mode: mode }).then(function (x) { q('rs-msg').textContent = x.ok ? '✓ ' + T('Restore queued — starts within a minute') : '⚠️ ' + x.error; loadB(); }); };
        [].forEach.call(document.querySelectorAll('[data-pkg]'), function (b) { b.onclick = function () { b.disabled = true; post('backup_cfg', { do: 'package', kind: b.dataset.pkg }).then(function (x) { toast(x.ok ? '📦 ' + T('Package queued — starts within a minute') : '⚠️ ' + x.error); loadB(); }); }; });
        [].forEach.call(document.querySelectorAll('[data-del-pkg]'), function (b) { b.onclick = function () { if (confirm(T('Delete the package') + ' «' + b.dataset.n + '»? ' + T('It goes to the Google Drive trash (kept there 30 days).'))) post('backup_cfg', { do: 'delete_package', id: b.dataset.delPkg }).then(function (x) { if (!x.ok) toast('⚠️ ' + x.error); loadB(); }); }; });
        clearTimeout(window.__bkT); if (r.busy || (r.progress && r.progress.running)) window.__bkT = setTimeout(loadB, 3000); // live progress
      });
    }
    root.addEventListener('click', function (e) { var c = e.target.closest('[data-copy-link]'); if (c) { if (navigator.clipboard) navigator.clipboard.writeText(c.dataset.copyLink); toast('✓ ' + T('Copied')); } });
    loadB();
    return;
  }

  /* ===================== Customer profile ===================== */
  if (page === 'customer') {
    var jid = root.dataset.jid;
    get('customer', { jid: jid }).then(function (r) {
      if (!r.ok) return fail(root, r);
      var c = r.customer, s = r.stats || {}, name = c ? c.name : (r.name || ('+' + r.phone));
      var h = '<section class="hero"><div class="hero-t">' + avatar(jid, name) + '<div><h1 dir="auto">' + esc(name) + '</h1><p class="muted" dir="ltr">' + (r.phone ? '+' + esc(r.phone) : esc(jid)) + (c ? ' · 🧾 ' + T('Customer') : '') + (r.case ? ' · 🩺 ' + T('Case') : '') + '</p></div></div>'
        + '<div class="hero-tools"><a class="chip-btn" href="?p=inbox#' + encodeURIComponent(jid) + '">💬 ' + T('Open chat') + '</a><a class="chip-btn" href="?p=programs&jid=' + encodeURIComponent(jid) + '">💊 ' + T('Enrol in a program') + '</a>' + (r.case ? '<a class="chip-btn" href="?p=cases&phone=' + encodeURIComponent(r.phone) + '">🩺 ' + T('Case record') + '</a>' : '') + '</div></section>';
      h += '<div class="kpis card"><div><b>' + fmt(s.in) + '</b><span>' + T('Messages from them') + '</span></div><div><b>' + fmt(s.out) + '</b><span>' + T('Messages to them') + '</span></div><div><b>' + (s.first ? esc(day(s.first)) : '—') + '</b><span>' + T('First message') + '</span></div><div><b>' + (s.last ? esc(day(s.last)) : '—') + '</b><span>' + T('Last message') + '</span></div>' + (c ? '<div><b>' + fmt(c.due) + '</b><span>' + T('Amount due') + '</span></div>' : '') + '</div>';
      h += '<div class="cd-grid">';
      if (r.summary) h += '<div class="card"><h3>🧠 ' + T('AI summary') + '</h3><p dir="auto">' + esc(r.summary.summary) + '</p><p class="small"><span class="tag">' + esc(r.summary.category) + '</span> ' + esc(r.summary.next_action || '') + '</p></div>';
      if (r.case) h += '<div class="card"><h3>🩺 ' + T('Case') + '</h3><p dir="auto">' + esc((r.case.data || {}).complaint || r.case.last_text || '') + '</p>' + (r.case.ai ? '<p dir="auto" class="small">' + esc(r.case.ai.summary || '') + '</p>' : '') + '</div>';
      if (r.invoices && r.invoices.length) h += '<div class="card"><h3>🧾 ' + T('Invoices') + '</h3><table class="tbl"><tbody>' + r.invoices.slice(0, 10).map(function (i) { return '<tr><td>' + esc(i.reference) + '</td><td>' + esc(i.date) + '</td><td>' + fmt(i.total) + ' ' + esc(i.currency) + '</td><td>' + esc(i.status) + (Number(i.total_due) > 0 ? ' · ' + T('due') + ' ' + fmt(i.total_due) : '') + '</td></tr>'; }).join('') + '</tbody></table></div>';
      if (r.enrollments.length) h += '<div class="card"><h3>💊 ' + T('Programs') + '</h3>' + r.enrollments.map(function (e) { return '<div dir="auto">' + esc(e.program_name) + ' · ' + T(e.status) + ' · ' + fmt(Object.keys(e.sent || {}).length) + ' ' + T('sent') + ' · ' + fmt(e.replies || 0) + ' ' + T('replies') + '</div>'; }).join('') + '</div>';
      if (r.notes.length) h += '<div class="card"><h3>📝 ' + T('Notes') + '</h3>' + r.notes.map(function (n) { return '<div class="cd-note"><div dir="auto">' + esc(n.text) + '</div><small class="muted">' + esc(n.by || '') + ' · ' + esc(when(n.at)) + '</small></div>'; }).join('') + '</div>';
      if (r.reminders.length || r.scheduled.length) h += '<div class="card"><h3>⏰ ' + T('Reminders & scheduled') + '</h3>' + r.reminders.map(function (x) { return '<div>🔔 ' + esc(when(x.at)) + ' ' + esc(x.note || '') + (x.done ? ' ✅' : '') + '</div>'; }).join('') + r.scheduled.map(function (x) { return '<div>⏳ ' + esc(when(x.at)) + ' — <span dir="auto">' + esc(String(x.text).slice(0, 80)) + '</span></div>'; }).join('') + '</div>';
      var k = s.kinds || {}; h += '<div class="card"><h3>📎 ' + T('Shared content') + '</h3>' + Object.keys(k).map(function (x) { return '<span class="tag">' + esc(x) + ' · ' + fmt(k[x]) + '</span>'; }).join(' ') + '</div>';
      h += '</div>';
      root.innerHTML = h;
    });
  }
  if (page === 'facebook') {
    var FB = { status: '', q: '', rows: [], count: {} };
    root.innerHTML = hero('📘', 'Facebook comments', 'The comments on your page\'s posts in one list: reply in public, reply in private, hide, delete or like. The bot can answer them too.', '')
      + '<div class="card" id="fb-cfg"></div>'
      + '<div class="card"><div class="row wrap gt-bar"><div class="search-xl"><span>🔎</span><input type="search" id="fb-q" placeholder="' + T('Search comments…') + '" dir="auto"></div>'
      + '<div class="seg" id="fb-st"><button data-v="" class="on">' + T('All') + '</button><button data-v="new">' + T('New') + '</button><button data-v="draft">' + T('Bot drafts') + '</button><button data-v="replied">' + T('Replied') + '</button><button data-v="hidden">' + T('Hidden') + '</button></div></div>'
      + '<div id="fb-list"><div class="empty">' + T('Loading…') + '</div></div></div>';
    var fbCfg = document.getElementById('fb-cfg');
    function fbCfgDraw(c) {
      var o = function (v, cur, l) { return '<option value="' + v + '" ' + (cur === v ? 'selected' : '') + '>' + l + '</option>'; };
      fbCfg.innerHTML = '<div class="gcf"><label>' + T('Bot answers comments') + '<select id="fb-bot">' + o('off', c.comment_bot, T('Off')) + o('draft', c.comment_bot, '📝 ' + T('Suggest only (a draft waits for a person)')) + o('auto', c.comment_bot, '⚡ ' + T('Automatic')) + '</select></label>'
        + '<label class="check"><input type="checkbox" id="fb-pub" ' + (c.comment_public ? 'checked' : '') + '> ' + T('Reply under the comment') + '</label>'
        + '<label class="check"><input type="checkbox" id="fb-prv" ' + (c.comment_private ? 'checked' : '') + '> ' + T('Also send a private message to the commenter') + '</label>'
        + '<button class="btn primary sm" id="fb-save">' + T('Save') + '</button></div>';
      document.getElementById('fb-save').onclick = function () {
        post('fb_cfg', { comment_bot: document.getElementById('fb-bot').value, comment_public: document.getElementById('fb-pub').checked ? 1 : 0, comment_private: document.getElementById('fb-prv').checked ? 1 : 0 }).then(function (r) { toast(r && r.ok ? T('Saved') : ((r && r.error) || T('Failed'))); });
      };
    }
    function fbDraw() {
      var box = document.getElementById('fb-list');
      if (!FB.rows.length) { box.innerHTML = '<div class="empty">' + T('No comments here yet.') + '</div>'; return; }
      box.innerHTML = FB.rows.map(function (c) {
        var p = c.post || {}, st = { new: '🆕', replied: '✅', hidden: '🙈' }[c.status] || '';
        return '<div class="card fb-c" data-id="' + esc(c.id) + '"><div class="row wrap"><b dir="auto">' + st + ' ' + esc(c.from_name || c.from_id) + '</b><span class="muted small">' + esc(when(c.time)) + '</span></div>'
          + (p.text || p.url ? '<div class="muted small" dir="auto">📌 ' + esc(p.text || '') + (p.url ? ' <a href="' + esc(p.url) + '" target="_blank" rel="noopener">↗</a>' : '') + '</div>' : '')
          + '<p dir="auto" style="white-space:pre-wrap">' + esc(c.text) + '</p>'
          + (c.reply ? '<div class="small" dir="auto">↩️ ' + esc(c.reply) + '</div>' : '') + (c.private ? '<div class="small" dir="auto">✉️ ' + esc(c.private) + '</div>' : '')
          + (c.draft && c.status === 'new' ? '<div class="small" dir="auto">📝 ' + T('Bot draft') + ': ' + esc(c.draft) + ' <button class="btn ghost sm" data-act="usedraft">' + T('Use') + '</button></div>' : '')
          + '<div class="row wrap fb-acts"><button class="btn sm" data-act="reply">💬 ' + T('Reply') + '</button><button class="btn ghost sm" data-act="private">✉️ ' + T('Private reply') + '</button>'
          + '<button class="btn ghost sm" data-act="like">👍</button><button class="btn ghost sm" data-act="' + (c.status === 'hidden' ? 'unhide' : 'hide') + '">' + (c.status === 'hidden' ? '👁 ' + T('Show') : '🙈 ' + T('Hide')) + '</button><button class="btn danger sm" data-act="delete">🗑</button></div>'
          + '<div class="fb-box" hidden></div></div>';
      }).join('');
    }
    function fbLoad() {
      get('fb_comments', { status: FB.status, q: FB.q }).then(function (r) {
        if (!r.ok) { fail(document.getElementById('fb-list'), r); return; }
        FB.rows = r.rows; FB.count = r.count || {}; fbDraw();
      });
    }
    get('fb_cfg').then(function (r) { if (r.ok) fbCfgDraw(r.cfg); else fail(fbCfg, r); });
    document.getElementById('fb-st').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); FB.status = b.dataset.v; fbLoad(); };
    var fbT = null; document.getElementById('fb-q').oninput = function () { var v = this.value; clearTimeout(fbT); fbT = setTimeout(function () { FB.q = v; fbLoad(); }, 300); };
    function fbAct(id, act, text, done) { post('fb_comment_act', { id: id, act: act, text: text || '' }).then(function (r) { if (r && r.ok) { toast(T('Done')); fbLoad(); if (done) done(); } else toast((r && r.error) || T('Failed')); }); }
    document.getElementById('fb-list').addEventListener('click', function (e) {
      var b = e.target.closest('[data-act]'); if (!b) return; var card = b.closest('.fb-c'), id = card.dataset.id, act = b.dataset.act, box = card.querySelector('.fb-box');
      var row = FB.rows.filter(function (x) { return x.id === id; })[0] || {};
      if (act === 'reply' || act === 'private' || act === 'usedraft') {
        box.hidden = false; var pre = act === 'usedraft' ? row.draft : '';
        box.innerHTML = '<textarea rows="3" dir="auto" placeholder="' + T(act === 'private' ? 'Private message to the commenter (one only, within 7 days)' : 'Reply under the comment') + '">' + esc(pre) + '</textarea><div class="row"><button class="btn primary sm" data-go="' + (act === 'private' ? 'private' : 'reply') + '">' + T('Send') + '</button></div>';
        box.querySelector('textarea').focus(); return;
      }
      if (act === 'delete' && !confirm(T('Delete this comment from Facebook?'))) return;
      fbAct(id, act);
    });
    document.getElementById('fb-list').addEventListener('click', function (e) {
      var g = e.target.closest('[data-go]'); if (!g) return; var card = g.closest('.fb-c'), t = card.querySelector('textarea').value.trim(); if (!t) return;
      g.disabled = true; fbAct(card.dataset.id, g.dataset.go, t);
    });
    fbLoad();
  }
})();

/* ---------- Page permissions: hide pages the user can't open, view-only banner, share button ---------- */
(function () {
  var P = window.WI_PERM; if (!P) return;
  var T = window.T || function (x) { return (window.WI_T || {})[x] || x; };
  var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var alias = { link: 'accounts', gateway: 'accounts', media: 'library' }; // the media page goes with the library permission
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
