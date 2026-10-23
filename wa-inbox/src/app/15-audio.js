  if (page === 'audio') {
    var A = { items: [], cat: '', view: 'active', sort: 'copies', sel: {} }, acsrf = app.getAttribute('data-csrf');
    var ACAT = { '': ['❔', 'Uncategorised', '#94a3b8'] }, AKEYS = []; // audio categories come from the business profile
    ((window.WI_PACK || {}).audio_cats || []).forEach(function (c) { ACAT[c.key] = [c.icon, c.label, c.color]; AKEYS.push(c.key); });
    function aUrl(att, name, dl) { if (!att) return ''; var p = att.split('/'), u = p[0] === 'inbox_uploads' ? '?api=media&f=' + encodeURIComponent(p[1]) : '?api=media&a=' + encodeURIComponent(p[1]) + '&f=' + encodeURIComponent(p.slice(2).join('/')); return u + (name ? '&name=' + encodeURIComponent(name) : '') + (dl ? '&dl=1' : ''); }
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
          + '<label>' + T('Name') + '<input id="ln-t" dir="auto" maxlength="80" value="' + esc(x.title || '') + '" placeholder="' + esc(T('e.g. A short name')) + '"></label>'
          + '<label>' + T('Shortcut — write it in any chat or group and the file is sent') + '<input id="ln-c" dir="auto" maxlength="60" value="' + esc(x.code || '') + '" placeholder="' + esc(T('#shortcut-name')) + '"></label>'
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
