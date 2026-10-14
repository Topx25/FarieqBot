  if (page === 'programs') {
    var PR = { programs: [], enr: [] };
    root.innerHTML = hero('💊', 'Programs', 'A program is a list of daily messages. Enrol a person and each day’s message is sent to them at their hour; replies are tracked as adherence.', isAdmin ? '<button class="btn primary" id="pr-new">➕ ' + T('New program') + '</button>' : '')
      + '<div id="pr-edit"></div><div class="card"><h3>💊 ' + T('Programs') + '</h3><div id="pr-list"></div></div><div class="card"><h3>👤 ' + T('Enrolled people') + '</h3><div class="pr-enrol row wrap"><input id="pe-jid" placeholder="' + T('Phone number (e.g. 2010…)') + '" dir="ltr"><input id="pe-name" placeholder="' + T('Name (optional)') + '" dir="auto"><select id="pe-prog"></select><input type="date" id="pe-start"><select id="pe-hour">' + Array.from({ length: 16 }, function (_, i) { return '<option value="' + (i + 7) + '"' + (i + 7 === 10 ? ' selected' : '') + '>' + (i + 7) + ':00</option>'; }).join('') + '</select><button class="btn sm" id="pe-add">➕ ' + T('Enrol') + '</button></div><div id="pe-list"></div></div>';
    document.getElementById('pe-start').value = new Date().toISOString().slice(0, 10);
    function loadP() {
      get('programs').then(function (r) {
        if (!r.ok) return fail(document.getElementById('pr-list'), r);
        PR.programs = r.programs; PR.enr = r.enrollments;
        document.getElementById('pe-prog').innerHTML = PR.programs.map(function (p) { return '<option value="' + esc(p.id) + '">' + esc(p.name) + '</option>'; }).join('');
        document.getElementById('pr-list').innerHTML = PR.programs.map(function (p) { var n = PR.enr.filter(function (e) { return e.program_id === p.id && e.status === 'active'; }).length; return '<div class="pr-row"><div><b dir="auto">' + esc(p.name) + '</b> <span class="muted small">' + fmt(p.days.length) + ' ' + T('days') + ' · ' + fmt(n) + ' ' + T('active') + '</span><div class="muted small" dir="auto">' + esc(p.desc || '') + '</div></div>' + (isAdmin ? '<button class="link-btn" data-edit="' + esc(p.id) + '">✏️ ' + T('Edit') + '</button>' : '') + '</div>'; }).join('') || '<div class="empty">' + T('No programs yet.') + '</div>';
        var progName = {}; PR.programs.forEach(function (p) { progName[p.id] = p; });
        document.getElementById('pe-list').innerHTML = PR.enr.length ? '<table class="tbl"><thead><tr><th>' + T('Person') + '</th><th>' + T('Program') + '</th><th>' + T('Progress') + '</th><th>' + T('Replies') + '</th><th>' + T('State') + '</th><th></th></tr></thead><tbody>' + PR.enr.slice().reverse().map(function (e) {
          var p = progName[e.program_id], total = p ? p.days.length : 0, sent = Object.keys(e.sent || {}).length;
          return '<tr><td><a href="?p=customer&jid=' + encodeURIComponent(e.jid) + '">' + esc(e.name || e.jid.split('@')[0]) + '</a><br><small class="muted">' + T('from') + ' ' + esc(e.start) + ' · ' + e.hour + ':00</small></td><td dir="auto">' + esc(p ? p.name : '—') + '</td><td><div class="prog"><i style="width:' + (total ? Math.round(sent / total * 100) : 0) + '%"></i></div><small>' + fmt(sent) + ' / ' + fmt(total) + '</small></td><td>' + fmt(e.replies || 0) + (sent ? ' (' + Math.round((e.replies || 0) / sent * 100) + '%)' : '') + '</td><td>' + T(e.status) + (e.last_error ? '<br><small class="bad-t">' + esc(e.last_error) + '</small>' : '') + '</td><td>'
            + (e.status === 'active' ? '<button class="link-btn" data-es="paused" data-id="' + esc(e.id) + '">⏸ ' + T('Pause') + '</button>' : (e.status === 'paused' ? '<button class="link-btn" data-es="active" data-id="' + esc(e.id) + '">▶️ ' + T('Resume') + '</button>' : '')) + (e.status !== 'stopped' && e.status !== 'done' ? ' <button class="link-btn" data-es="stopped" data-id="' + esc(e.id) + '">⏹ ' + T('Stop') + '</button>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div class="empty">' + T('Nobody enrolled yet.') + '</div>';
      });
    }
    function editor(p) {
      p = p || { id: '', name: '', desc: '', days: [{ day: 1, text: '' }] };
      var el = document.getElementById('pr-edit');
      el.innerHTML = '<div class="card"><h3>✏️ ' + T('Program') + '</h3><div class="row wrap"><input id="pg-name" value="' + esc(p.name) + '" placeholder="' + T('Program name') + '" dir="auto" style="flex:1"></div><textarea id="pg-desc" rows="2" placeholder="' + T('Description (optional)') + '" dir="auto">' + esc(p.desc || '') + '</textarea><div id="pg-days"></div>'
        + '<div class="row"><button class="chip-btn sm" id="pg-add">➕ ' + T('Add day') + '</button><button class="btn primary sm" id="pg-save">💾 ' + T('Save') + '</button>' + (p.id ? '<button class="link-btn bad-t" id="pg-del">🗑 ' + T('Delete program') + '</button>' : '') + '<button class="link-btn" id="pg-close">' + T('Close') + '</button></div></div>';
      function dayRow(d) { return '<div class="pg-day"><label>' + T('Day') + ' <input type="number" min="1" max="365" class="pg-n" value="' + d.day + '"></label><textarea class="pg-t" rows="3" dir="auto" placeholder="' + T('Message for this day (paste the dhikr/ruqyah text from the Library)') + '">' + esc(d.text) + '</textarea><button class="link-btn pg-rm">✕</button></div>'; }
      document.getElementById('pg-days').innerHTML = p.days.map(dayRow).join('');
      document.getElementById('pg-add').onclick = function () { var n = document.querySelectorAll('.pg-day').length + 1; document.getElementById('pg-days').insertAdjacentHTML('beforeend', dayRow({ day: n, text: '' })); };
      el.onclick = function (e) { if (e.target.closest('.pg-rm')) e.target.closest('.pg-day').remove(); };
      document.getElementById('pg-close').onclick = function () { el.innerHTML = ''; };
      document.getElementById('pg-save').onclick = function () {
        var days = [].map.call(document.querySelectorAll('.pg-day'), function (x) { return { day: Number(x.querySelector('.pg-n').value), text: x.querySelector('.pg-t').value }; });
        post('program_save', { id: p.id, name: document.getElementById('pg-name').value, desc: document.getElementById('pg-desc').value, days: days }).then(function (r) { if (r.ok) { el.innerHTML = ''; loadP(); toast('✓ ' + T('Saved')); } else toast('⚠️ ' + r.error); });
      };
      if (p.id) document.getElementById('pg-del').onclick = function () { if (confirm(T('Delete this program?'))) post('program_save', { do: 'delete', id: p.id }).then(function () { el.innerHTML = ''; loadP(); }); };
      el.scrollIntoView({ behavior: 'smooth' });
    }
    if (document.getElementById('pr-new')) document.getElementById('pr-new').onclick = function () { editor(null); };
    root.addEventListener('click', function (e) {
      var ed = e.target.closest('[data-edit]'); if (ed) { editor(PR.programs.filter(function (p) { return p.id === ed.dataset.edit; })[0]); return; }
      var es = e.target.closest('[data-es]'); if (es) { post('program_enroll', { id: es.dataset.id, status: es.dataset.es }).then(loadP); }
    });
    document.getElementById('pe-add').onclick = function () {
      var v = document.getElementById('pe-jid').value.trim(), jid = /@/.test(v) ? v : v.replace(/\D/g, '') + '@s.whatsapp.net';
      if (!v || !document.getElementById('pe-prog').value) { toast(T('Choose a chat and a program.')); return; }
      if (!confirm(T('The program messages will be sent to this person automatically every day. Continue?'))) return;
      post('program_enroll', { jid: jid, name: document.getElementById('pe-name').value, program_id: document.getElementById('pe-prog').value, start: document.getElementById('pe-start').value, hour: document.getElementById('pe-hour').value }).then(function (r) { if (r.ok) { loadP(); toast('✓ ' + T('Enrolled')); } else toast('⚠️ ' + r.error); });
    };
    var pre = new URLSearchParams(location.search).get('jid'); if (pre) document.getElementById('pe-jid').value = pre;
    loadP();
    return;
  }
