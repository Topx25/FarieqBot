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
