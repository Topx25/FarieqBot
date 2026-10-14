  if (page === 'files') {
    var F = { items: [], kind: '', timer: null }, fcsrf = app.getAttribute('data-csrf');
    function fUrl(att, name, dl) { if (!att) return ''; var p = att.split('/'), u = p[0] === 'inbox_uploads' ? '?api=media&f=' + encodeURIComponent(p[1]) : (p[0] === 'inbox_media' ? '?api=media&a=' + encodeURIComponent(p[1]) + '&f=' + encodeURIComponent(p[2]) : ''); return u ? u + (name ? '&name=' + encodeURIComponent(name.replace(/\.[a-z0-9]{2,4}$/i, '')) : '') + (dl ? '&dl=1' : '') : ''; }
    function fIcon(x) { var m = String(x.mimetype || '') + ' ' + (x.file_name || ''); return x.kind === 'image' ? '🖼️' : (x.kind === 'video' ? '🎬' : (/pdf/i.test(m) ? '📕' : (/word|docx?$/i.test(m) ? '📘' : (/sheet|excel|xlsx?$/i.test(m) ? '📗' : (/zip|rar/i.test(m) ? '🗜️' : '📄'))))); }
    function drawF() {
      var rows = F.items.filter(function (x) { return !F.kind || x.kind === F.kind; });
      document.getElementById('fl-count').textContent = fmt(rows.length) + ' ' + T('files') + ' · ' + fmt(rows.reduce(function (a, b) { return a + Math.max(0, b.copies - 1); }, 0)) + ' ' + T('duplicates (counted only)');
      document.getElementById('fl-grid').innerHTML = rows.slice(0, 400).map(function (x) {
        var url = fUrl(x.attachment), nm = x.file_name || x.text || (x.kind === 'image' ? T('🖼️ Photo') : T('📄 File'));
        var prev = x.kind === 'image' && url ? '<a href="' + url + '" target="_blank" rel="noopener"><img src="' + url + '" alt="" loading="lazy"></a>' : '<div class="fl-ic">' + fIcon(x) + '</div>';
        return '<div class="fl-card"><div class="fl-prev">' + prev + '</div><div class="fl-b"><b dir="auto" title="' + esc(nm) + '">' + esc(nm) + '</b>'
          + '<div class="muted small">' + (x.size ? (x.size / 1048576).toFixed(1) + ' MB · ' : '') + esc(when(x.time, true)) + (x.copies > 1 ? ' · 🔁 ' + fmt(x.copies) + '×' : '') + '</div>'
          + '<div class="muted small"><a href="?p=inbox#' + encodeURIComponent(x.jid) + '">' + esc(x.customer ? x.customer.name : (x.name || (x.phone ? '+' + x.phone : ''))) + '</a></div>'
          + '<div class="row wrap">' + (url ? '<a class="chip-btn sm" href="' + fUrl(x.attachment, nm, true) + '">⬇️ ' + T('Download') + '</a>' : (x.fetchable ? '<button class="chip-btn sm" data-fetch="' + esc(x.id) + '">⬇️ ' + T('Fetch from WhatsApp') + '</button>' : '<span class="muted small">' + T('File not kept') + '</span>')) + '</div></div></div>';
      }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>';
    }
    function loadF() { api('files', { q: document.getElementById('fl-q').value || '' }).then(function (r) { if (!r.ok) { document.getElementById('fl-grid').innerHTML = '<div class="empty">' + esc(r.error || '') + '</div>'; return; } F.items = r.items; drawF(); }); }
    document.getElementById('fl-kind').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); F.kind = b.dataset.k; drawF(); });
    document.getElementById('fl-q').addEventListener('input', function () { clearTimeout(F.timer); F.timer = setTimeout(loadF, 350); });
    document.getElementById('fl-grid').addEventListener('click', function (e) {
      var f = e.target.closest('[data-fetch]'); if (!f) return; f.disabled = true; f.textContent = T('Downloading…');
      WI.post('fetch_media', { id: f.dataset.fetch }).then(function (r) { if (r.ok) loadF(); else f.textContent = r.error || T('Failed'); });
    });
    loadF();
  }
