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
