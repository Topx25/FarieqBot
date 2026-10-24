/* WhatsApp Inbox — feature pages (cases, groups, scheduling, programs, status, health, report, backup, customer)
   and chat tools (AI reply suggestion, quoted reply, reactions, schedule). All text is escaped before display. */
// Refresh / polling pace: Settings → System → refresh_pct (100 = normal, 200 = half as often on a busy server).
function WIR(ms) { var p = +((window.WI_SYS || {}).refresh_pct) || 100; return Math.round(ms * Math.max(50, Math.min(400, p)) / 100); }
// Example texts and case-form fields come from the number's business profile, never from the code.
function EX(k) { var e = window.WI_EX || {}; return e[k] || ''; }
function FL(k, en) { var f = (((window.WI_PACK || {}).case_cfg || {}).fields || {})[k]; return (f && f.label) || T(en); } // a case field's name for this type of business
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
  // the words that say female / male come from the profile (window.WI_WORDS, seed_gender_f / seed_gender_m)
  var GW = window.WI_WORDS || {}, gRx = function (list, tail) { var w = (list || []).map(function (x) { return String(x).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).filter(Boolean); return new RegExp((w.length ? w.join('|') + '|' : '') + tail, 'i'); };
  var G_F = gRx(GW.gender_f, 'f$'), G_M = gRx(GW.gender_m, '^m$');
  function genderOf(v) { v = String(v || ''); return G_F.test(v) ? 'f' : (G_M.test(v) ? 'm' : ''); }
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
  function mediaUrl(att) { if (!att) return ''; var p = String(att).split('/'); return p[0] === 'inbox_uploads' ? '?api=media&f=' + encodeURIComponent(p[1]) : (p[0] === 'inbox_media' ? '?api=media&a=' + encodeURIComponent(p[1]) + '&f=' + encodeURIComponent(p.slice(2).join('/')) : ''); }
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
    function sync() { if (!dot || !gw) return; var t = gw.textContent || ''; dot.className = 'tb-dot ' + (t.indexOf(T('🟢 Connected')) === 0 ? 'on' : (t.trim() && t.trim() !== '…' ? 'off' : '')); dot.title = t; }
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
