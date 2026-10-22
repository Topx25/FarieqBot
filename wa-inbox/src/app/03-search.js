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
  // One search box for everything: suggestions while typing (chats, numbers, cases, knowledge, files, commands, messages), best match first.
  (function () {
    var inp = document.querySelector('.tb-search input[name="q"]'); if (!inp) return;
    var form = inp.closest('form'), box = document.createElement('div'), timer, items = [], at = -1, last = '';
    box.className = 'sg-box'; box.hidden = true; form.style.position = 'relative'; form.appendChild(box); inp.setAttribute('autocomplete', 'off');
    var kinds = { chat: 'Chats', group: 'Groups', case: 'Cases', knowledge: 'Knowledge', file: 'Files', command: 'Commands', message: 'Messages' };
    function mark(t, q) { var e = esc(t), w = q.trim(); if (!w) return e; try { return e.replace(new RegExp(w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi'), function (x) { return '<mark>' + x + '</mark>'; }); } catch (er) { return e; } }
    function draw(q) {
      if (!items.length) { box.innerHTML = '<div class="sg-empty">' + T('No suggestions — press Enter to search all messages.') + '</div>'; box.hidden = false; return; }
      box.innerHTML = items.map(function (x, i) { var c = x.score >= 85 ? 'hi' : x.score >= 65 ? 'mid' : 'lo';
        return '<a class="sg-it' + (i === at ? ' on' : '') + '" href="' + esc(x.url) + '" data-i="' + i + '"><span class="sg-ic">' + x.icon + '</span><span class="sg-tx"><b dir="auto">' + mark(x.label, q) + '</b><small dir="auto">' + esc(T(kinds[x.type] || '')) + (x.sub ? ' · ' + mark(x.sub, q) : '') + '</small></span><span class="sg-sc ' + c + '" title="' + esc(T('Match')) + '">' + x.score + '%</span></a>'; }).join('')
        + '<div class="sg-foot">↵ ' + T('Enter = search all messages') + ' · ↑↓ ' + T('choose') + ' · Esc</div>';
      box.hidden = false; }
    function run() { var q = inp.value.trim(); if (q.length < 2) { box.hidden = true; items = []; return; } if (q === last && items.length) { box.hidden = false; return; } last = q;
      box.innerHTML = '<div class="sg-empty">' + T('Searching…') + '</div>'; box.hidden = false;
      api('suggest', { q: q }).then(function (r) { if (inp.value.trim() !== q) return; items = (r && r.items) || []; at = -1; draw(q); }); }
    inp.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 350); });
    inp.addEventListener('focus', function () { if (items.length && inp.value.trim().length >= 2) box.hidden = false; });
    inp.addEventListener('keydown', function (e) {
      if (box.hidden) return;
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); at = Math.max(-1, Math.min(items.length - 1, at + (e.key === 'ArrowDown' ? 1 : -1))); draw(inp.value); }
      else if (e.key === 'Enter' && at >= 0 && items[at]) { e.preventDefault(); location.href = items[at].url; }
      else if (e.key === 'Escape') { box.hidden = true; } });
    document.addEventListener('click', function (e) { if (!form.contains(e.target)) box.hidden = true; });
  })();
