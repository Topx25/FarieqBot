  if (page === 'health') {
    root.innerHTML = hero('❤️', 'Number health', 'Connection of each WhatsApp number, disconnects and failed sends in the last 24 hours. Admins get a WhatsApp alert when a number is down for 10 minutes or logged out.', '<button class="chip-btn" id="hl-ref">🔄 ' + T('Refresh') + '</button>') + '<div id="hl-list"><div class="empty">' + T('Loading…') + '</div></div>';
    function loadH() {
      get('health').then(function (r) {
        if (!r.ok) return fail(document.getElementById('hl-list'), r);
        var R = r.resources, rs = document.getElementById('hl-res');
        if (!rs) { rs = document.createElement('div'); rs.id = 'hl-res'; rs.className = 'card'; document.getElementById('hl-list').parentNode.insertBefore(rs, document.getElementById('hl-list')); }
        if (R) { var L = R.limits || {}, bar = function (v, max) { if (!(max > 0)) return '<small class="muted">' + T('No limit') + '</small>'; var p = Math.min(100, Math.round(v / Math.max(1, max) * 100)); return '<div class="bk-bar"><i style="width:' + p + '%;' + (p > 85 ? 'background:var(--bad)' : '') + '"></i></div><small class="muted">' + p + '%</small>'; };
          rs.innerHTML = '<h3>🖥️ ' + T('Server resources') + ' <small class="muted">' + esc(when(R.at)) + '</small></h3><div class="res-grid">'
            + '<div><b>🧠 ' + T('Memory') + '</b><div class="res-n">' + fmt(R.mem_mb) + (L.pmem_mb > 0 ? ' / ' + fmt(L.pmem_mb) : '') + ' MB</div>' + bar(R.mem_mb, L.pmem_mb) + '</div>'
            + '<div><b>🧵 ' + T('Processes & threads') + '</b><div class="res-n">' + fmt(R.threads) + (L.nproc > 0 ? ' / ' + fmt(L.nproc) : '') + '</div>' + bar(R.threads, L.nproc) + '<small class="muted">' + fmt(R.procs) + ' ' + T('processes') + '</small></div>'
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
