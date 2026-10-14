  if (page === 'broadcast') {
    var bcsrf = app.getAttribute('data-csrf'), lists = [], allChats = [], editing = null, picked = {}, bcFilter = '';
    function post(name, body) { return WI.post(name, body); }
    function drawLists() {
      document.getElementById('bc-lists').innerHTML = lists.length ? lists.map(function (l) { return '<div class="bc-l"><div><b>' + esc(l.name) + '</b><small class="muted">' + fmt(l.members.length) + ' ' + T('people') + '</small></div><div class="row"><button class="btn ghost sm" data-edit-list="' + esc(l.id) + '">' + T('Edit') + '</button><button class="btn danger sm" data-del-list="' + esc(l.id) + '">' + T('Delete') + '</button></div></div>'; }).join('') : '<div class="empty">' + T('No lists yet.') + '</div>';
      document.getElementById('bc-list').innerHTML = lists.map(function (l) { return '<option value="' + esc(l.id) + '">' + esc(l.name) + ' (' + l.members.length + ')</option>'; }).join('');
      est();
    }
    function est() { var l = lists.filter(function (x) { return x.id === document.getElementById('bc-list').value; })[0]; document.getElementById('bc-est').textContent = l ? T('Estimated time:') + ' ~' + Math.ceil(l.members.length * 45 / 60) + ' ' + T('min') + (l.members.length > 60 ? ' · ' + T('over 60 people continues the next day') : '') : ''; }
    function loadLists() { api('lists').then(function (r) { if (r.ok) { lists = r.lists || []; drawLists(); } else document.getElementById('bc-lists').innerHTML = '<div class="empty">' + esc(r.error || '') + '</div>'; }); }
    function drawPick() {
      var q = (document.getElementById('bc-q').value || '').toLowerCase(), n = 0;
      document.getElementById('bc-pick').innerHTML = allChats.filter(function (c) {
        if (c.group) return false;
        if (bcFilter === 'customers' && !c.customer) return false; if (bcFilter === 'others' && c.customer) return false;
        return !q || (title(c) + ' ' + (c.phone || '')).toLowerCase().indexOf(q) !== -1;
      }).map(function (c) { return '<label class="check"><input type="checkbox" data-jid="' + esc(c.jid) + '" ' + (picked[c.jid] ? 'checked' : '') + '> <span dir="auto">' + esc(title(c)) + '</span> <small class="muted" dir="ltr">' + (c.phone ? '+' + esc(c.phone) : '') + '</small>' + (c.customer ? '<span class="tag">' + T('Customer') + '</span>' : '') + '</label>'; }).join('') || '<div class="empty">' + T('No conversations.') + '</div>';
      for (var k in picked) n++; document.getElementById('bc-count').textContent = n + ' ' + T('selected');
    }
    function openDlg(list) {
      editing = list; picked = {};
      (list ? list.members : []).forEach(function (m) { picked[m.jid] = m.name || true; });
      document.getElementById('bc-name').value = list ? list.name : '';
      document.getElementById('bc-dlg-t').textContent = list ? T('Edit list') : T('New list');
      api('chats').then(function (r) { allChats = r.chats || []; drawPick(); });
      document.getElementById('bc-dlg').showModal();
    }
    document.getElementById('bc-new').addEventListener('click', function () { openDlg(null); });
    document.getElementById('bc-lists').addEventListener('click', function (e) {
      var ed = e.target.closest('[data-edit-list]'), dl = e.target.closest('[data-del-list]');
      if (ed) openDlg(lists.filter(function (l) { return l.id === ed.getAttribute('data-edit-list'); })[0]);
      if (dl && confirm(T('Delete this list?'))) post('lists', { delete: dl.getAttribute('data-del-list') }).then(function (r) { if (r.ok) { lists = r.lists; drawLists(); } });
    });
    document.getElementById('bc-q').addEventListener('input', drawPick);
    document.getElementById('bc-f').addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); bcFilter = b.getAttribute('data-f'); drawPick(); });
    document.getElementById('bc-pick').addEventListener('change', function (e) { var cb = e.target; if (!cb.dataset.jid) return; var c = allChats.filter(function (x) { return x.jid === cb.dataset.jid; })[0]; if (cb.checked) picked[cb.dataset.jid] = c ? (c.customer ? c.customer.name : c.name) || '' : ''; else delete picked[cb.dataset.jid]; drawPick(); });
    document.getElementById('bc-all').addEventListener('click', function () { [].forEach.call(document.querySelectorAll('#bc-pick input[data-jid]'), function (cb) { var c = allChats.filter(function (x) { return x.jid === cb.dataset.jid; })[0]; picked[cb.dataset.jid] = c ? (c.customer ? c.customer.name : c.name) || '' : ''; }); drawPick(); });
    document.getElementById('bc-none').addEventListener('click', function () { picked = {}; drawPick(); });
    document.getElementById('bc-save').addEventListener('click', function (e) {
      e.preventDefault();
      var members = []; for (var k in picked) members.push({ jid: k, name: typeof picked[k] === 'string' ? picked[k] : '' });
      post('lists', { id: editing ? editing.id : '', name: document.getElementById('bc-name').value, members: members }).then(function (r) { if (r.ok) { lists = r.lists; drawLists(); document.getElementById('bc-dlg').close(); } else alert(r.error || ''); });
    });
    document.getElementById('bc-list').addEventListener('change', est);
    document.getElementById('bc-send').addEventListener('click', function () {
      var id = document.getElementById('bc-list').value, text = document.getElementById('bc-text').value.trim();
      if (!id || !text) { alert(T('Choose a list and write the message.')); return; }
      var l = lists.filter(function (x) { return x.id === id; })[0];
      if (!confirm(T('Send this message to') + ' ' + l.members.length + ' ' + T('people, slowly (30–60 s apart)?'))) return;
      post('broadcast', { list: id, text: text }).then(function (r) { if (r.ok) { alert(T('Queued:') + ' ' + r.queued + (r.skipped ? ' · ' + T('skipped (no chat yet):') + ' ' + r.skipped : '')); document.getElementById('bc-text').value = ''; loadJobs(); } else alert(r.error || ''); });
    });
    function loadJobs() {
      api('broadcast_status').then(function (r) {
        if (!r.ok) { document.getElementById('bc-jobs').innerHTML = '<div class="empty">' + esc(r.error || '') + '</div>'; return; }
        document.getElementById('bc-today').textContent = T('Sent today') + ': ' + r.sent_today + ' / ' + r.daily_max;
        document.getElementById('bc-jobs').innerHTML = (r.jobs || []).length ? '<table class="table"><thead><tr><th>' + T('Name') + '</th><th>' + T('Progress') + '</th><th>' + T('Status') + '</th><th></th></tr></thead><tbody>' + r.jobs.map(function (j) {
          var pc = j.total ? Math.round((j.sent + j.failed) / j.total * 100) : 0;
          return '<tr><td><b>' + esc(j.name) + '</b><div class="muted small" dir="auto">' + esc(j.text.slice(0, 80)) + '</div></td><td><div class="up-bar"><span style="width:' + pc + '%"></span></div><small>' + j.sent + ' ✓ · ' + j.failed + ' ✕ · ' + j.pending + ' ⏳ / ' + j.total + '</small></td><td><span class="badge">' + esc(T(j.status)) + '</span>' + (j.note ? '<div class="muted small">' + esc(j.note) + '</div>' : '') + '</td><td>'
            + (j.status === 'running' ? '<button class="btn ghost sm" data-bc="pause" data-id="' + j.id + '">⏸ ' + T('Pause') + '</button>' : '') + (j.status === 'paused' ? '<button class="btn ghost sm" data-bc="resume" data-id="' + j.id + '">▶ ' + T('Resume') + '</button>' : '')
            + (['running', 'paused'].indexOf(j.status) !== -1 ? '<button class="btn danger sm" data-bc="cancel" data-id="' + j.id + '">✕ ' + T('Cancel') + '</button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div class="empty">' + T('No broadcasts yet.') + '</div>';
      });
    }
    document.getElementById('bc-jobs').addEventListener('click', function (e) { var b = e.target.closest('[data-bc]'); if (!b) return; if (b.dataset.bc === 'cancel' && !confirm(T('Cancel this broadcast?'))) return; post('broadcast_control', { id: b.dataset.id, action: b.dataset.bc }).then(loadJobs); });
    loadLists(); loadJobs(); setInterval(loadJobs, WIR(15000));
  }
