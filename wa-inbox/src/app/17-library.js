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
        + (g.code ? '<div class="lc-code" dir="auto">🔑 ' + esc(g.code) + '</div>' : '')
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
          WI.post('media_meta', { key: key, title: document.getElementById('ln-t').value.trim(), code: document.getElementById('ln-c').value.trim() }).then(function (r) {
            if (!r.ok) { document.getElementById('ln-m').textContent = '⚠️ ' + (r.error || T('Failed')); return; } ML.items = null; d.close(); b.textContent = '✏️ ' + T('Saved'); }); }; }); });
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
