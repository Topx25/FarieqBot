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
