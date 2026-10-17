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
