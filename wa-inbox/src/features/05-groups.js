  if (page === 'groups') {
    var G = { list: [], q: '', sort: 'activity', view: null, tab: 'people', pq: '', who: '', mode: viewGet('groups', 'icons') };
    var gj = new URLSearchParams(location.search).get('g');
    function shell() {
      root.innerHTML = hero('👥', 'Groups', 'Open a group, pick the person you want to follow, and get a full record of their messages, the replies to them and their diagnosis — kept separate from everyone else.',
        '<div class="search-xl"><span>🔎</span><input type="search" id="gr-q" placeholder="' + T('Search groups…') + '" dir="auto"></div><div class="seg" id="gr-sort" hidden></div><button class="chip-btn" id="gr-ref">🔄 ' + T('Refresh') + '</button>' + viewSwitch('groups', G.mode))
        + fbar('gr-f', { sort: [['activity', 'Last activity (newest)'], ['old', 'Last activity (oldest)'], ['messages', 'Most messages'], ['members', 'Most members'], ['name', 'Name']], dates: true, extra: '<label><select data-f="kind"><option value="">' + T('All groups') + '</option><option value="mon">🩺 ' + T('Case groups') + '</option><option value="admin">⭐ ' + T('I am admin') + '</option><option value="quiet">💤 ' + T('No activity') + '</option><option value="left">⟲ ' + T('Groups I left') + '</option><option value="current">✅ ' + T('Groups I am in') + '</option></select></label>' })
        + '<div id="gr-grid" class="gr-grid"><div class="empty">' + T('Loading…') + '</div></div>';
      document.getElementById('gr-q').oninput = function () { G.q = this.value; pgReset('groups'); drawGrid(); };
      document.getElementById('gr-sort').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; G.sort = b.dataset.s; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); drawGrid(); };
      document.getElementById('gr-ref').onclick = function () { loadGrid(true); };
      fwire('gr-f', drawGrid);
      root.querySelector('[data-vk="groups"]').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; G.mode = b.dataset.v; viewSet('groups', G.mode); [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); drawGrid(); };
    }
    function loadGrid(ref) { get('groups', ref ? { refresh: 1 } : {}).then(function (r) { if (!r.ok) return fail(document.getElementById('gr-grid'), r); G.list = r.groups; drawGrid(); }); }
    function drawGrid() {
      var q = G.q.toLowerCase(), f = fval('gr-f'), srt = f.sort || 'activity';
      var rows = G.list.filter(function (g) { return (!q || String(g.name).toLowerCase().indexOf(q) !== -1) && ((!f.from && !f.to) || inRange(g.last, f.from, f.to)) && (!f.kind || (f.kind === 'mon' ? g.monitored : (f.kind === 'admin' ? g.i_am_admin : (f.kind === 'left' ? g.left : (f.kind === 'current' ? !g.left : !g.messages))))); });
      rows.sort(function (a, b) { return srt === 'members' ? b.members - a.members : (srt === 'name' ? String(a.name).localeCompare(String(b.name)) : (srt === 'messages' ? b.messages - a.messages : (srt === 'old' ? String(a.last || '9').localeCompare(String(b.last || '9')) : String(b.last || '').localeCompare(String(a.last || ''))))); });
      var gridEl = document.getElementById('gr-grid'); gridEl.className = G.mode === 'list' ? 'gr-listv' : 'gr-grid';
      var P = pg('groups', rows, drawGrid, 48); rows = P.rows;
      var pagerEl = document.getElementById('gr-pager'); if (!pagerEl) { pagerEl = document.createElement('div'); pagerEl.id = 'gr-pager'; gridEl.parentNode.insertBefore(pagerEl, gridEl.nextSibling); } pagerEl.innerHTML = P.html;
      if (G.mode === 'list') {
        gridEl.innerHTML = '<table class="tbl"><thead><tr><th>' + T('Group') + '</th><th>' + T('Members') + '</th><th>' + T('Messages (2 months)') + '</th><th>' + T('Last activity') + '</th><th></th></tr></thead><tbody>' + rows.map(function (g) {
          return '<tr class="gr-tr"><td><a class="gr-n" href="?p=groups&g=' + encodeURIComponent(g.jid) + '">' + avatar(g.jid, g.name) + '<b dir="auto">' + esc(g.name || g.jid) + '</b></a></td><td>' + fmt(g.members) + (g.left ? ' <span class="tag left">⟲ ' + T('Left') + '</span>' : '') + '</td><td>' + fmt(g.messages) + '</td><td>' + (g.last ? esc(day(g.last)) : '—') + '</td><td>' + (g.monitored ? '<span class="tag mon">🩺</span>' : '') + (g.i_am_admin ? ' <span class="tag">' + T('Admin') + '</span>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>';
        if (!rows.length) gridEl.innerHTML = '<div class="empty">' + T('Nothing found.') + '</div>';
        return;
      }
      gridEl.innerHTML = rows.map(function (g) {
        return '<a class="gr-card' + (g.monitored ? ' mon' : '') + '" href="?p=groups&g=' + encodeURIComponent(g.jid) + '">' + avatar(g.jid, g.name)
          + '<div class="gr-cb"><b dir="auto">' + esc(g.name || g.jid) + '</b><div class="gr-stats"><span>👤 ' + (g.left ? '—' : fmt(g.members)) + '</span><span>💬 ' + fmt(g.messages) + '</span><span>🕐 ' + (g.last ? esc(day(g.last)) : '—') + '</span></div>'
          + '<div class="gr-tags">' + (g.left ? '<span class="tag left">⟲ ' + T('You left — archive only') + '</span>' : '') + (g.monitored ? '<span class="tag mon">🩺 ' + T('Case group') + '</span>' : '') + (g.i_am_admin ? '<span class="tag">' + T('Admin') + '</span>' : '') + (g.announce ? '<span class="tag">🔒 ' + T('Admins only post') + '</span>' : '') + '</div></div></a>';
      }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>';
    }
    /* ---- one group ---- */
    function openGroup(jid) {
      root.innerHTML = '<div class="empty">' + T('Loading…') + '</div>';
      get('group_view', { jid: jid }).then(function (r) {
        if (!r.ok) return fail(root, r);
        G.view = r;
        var tracked = r.members.filter(function (m) { return m.case; }).length, writers = r.members.filter(function (m) { return m.messages; }).length;
        root.innerHTML = '<a href="?p=groups" class="link">← ' + T('All groups') + '</a>'
          + '<section class="hero gv-hero"><div class="hero-t">' + avatar(jid, r.name) + '<div><h1 dir="auto">' + esc(r.name) + '</h1><p class="muted">👤 ' + fmt(r.members.filter(function (m) { return m.member; }).length) + ' ' + T('members') + ' · ✍️ ' + fmt(writers) + ' ' + T('wrote') + ' · 🩺 ' + fmt(tracked) + ' ' + T('followed') + ' · 💬 ' + fmt(r.total) + '</p></div></div>'
          + '<div class="hero-tools"><a class="chip-btn" href="?p=inbox#' + encodeURIComponent(jid) + '">💬 ' + T('Open chat') + '</a><a class="chip-btn" href="?p=media&chat=' + encodeURIComponent(jid) + '">📚 ' + T('Group library') + '</a>' + (isAdmin ? '<label class="chip-btn"><input type="checkbox" id="gv-reg" ' + (r.registry ? 'checked' : '') + '> 📋 ' + T('Case registry group') + '</label><label class="chip-btn"><input type="checkbox" id="gv-mon" ' + (r.monitored ? 'checked' : '') + '> 🩺 ' + T('Follow everyone who writes here') + '</label>' : '') + (r.registry ? '<a class="chip-btn" href="?p=cases&tab=registry">📋 ' + T('Open the registry') + '</a>' : '') + '</div></section>'
          + '<div class="gv-help card">① ' + T('Pick a person from the list (or from a message).') + ' ② ' + T('Press 🩺 Follow — a case page is created for that number.') + ' ③ ' + T('Their messages, replies to them and their private chat are collected there, with AI diagnosis.') + '</div>'
          + '<div class="gv"><div class="card gv-people"><div class="gv-ph"><b>' + T('People') + '</b><input type="search" id="gv-pq" placeholder="' + T('Name or number…') + '" dir="auto"><div class="seg sm" id="gv-pf"><button data-f="" class="on">' + T('All') + '</button><button data-f="w">' + T('Wrote') + '</button><button data-f="c">🩺</button></div></div><div id="gv-plist" class="gv-plist"></div></div>'
          + '<div class="card gv-msgs"><div class="gv-ph"><b id="gv-mt">' + T('Messages') + '</b><button class="link-btn" id="gv-all" hidden>✕ ' + T('Show everyone') + '</button></div><div id="gv-mlist" class="gv-mlist"></div></div></div>';
        drawPeople(); drawMsgs();
        document.getElementById('gv-pq').oninput = function () { G.pq = this.value; drawPeople(); };
        document.getElementById('gv-pf').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; G.pf = b.dataset.f; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); drawPeople(); };
        document.getElementById('gv-all').onclick = function () { G.who = ''; drawMsgs(); drawPeople(); };
        var reg = document.getElementById('gv-reg');
        if (reg) reg.onchange = function () {
          if (reg.checked && !confirm(T('Is this the group where each case is posted (name, number, voice note, diagnosis)? Every post with a phone number becomes a registry entry.'))) { reg.checked = false; return; }
          reg.disabled = true; post('registry_group', { jid: jid, name: r.name, on: reg.checked ? 1 : 0 }).then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); if (x.ok && reg.checked) location.href = '?p=cases&tab=registry'; else openGroup(jid); });
        };
        var mon = document.getElementById('gv-mon');
        if (mon) mon.onchange = function () {
          if (mon.checked && !confirm(T('Monitor this group as a case group? Every member who writes there gets a case record (all messages are archived and can be analysed).'))) { mon.checked = false; return; }
          post('group_monitor', { jid: jid, name: r.name, on: mon.checked ? 1 : 0 }).then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); if (x.ok) openGroup(jid); });
        };
      });
    }
    function person(m) { return m.name || ('+' + m.phone); }
    function drawPeople() {
      var r = G.view, q = (G.pq || '').toLowerCase();
      var rows = r.members.filter(function (m) { return (!q || (person(m) + ' ' + m.phone).toLowerCase().indexOf(q) !== -1) && (G.pf !== 'w' || m.messages) && (G.pf !== 'c' || m.case); });
      document.getElementById('gv-plist').innerHTML = rows.slice(0, 600).map(function (m) {
        return '<div class="gv-p' + (G.who === m.phone ? ' on' : '') + '" data-who="' + esc(m.phone) + '">' + avatar(m.phone + '@s.whatsapp.net', person(m))
          + '<div class="gv-pb"><b dir="auto">' + esc(person(m)) + '</b><small class="muted" dir="ltr">+' + esc(m.phone) + (m.admin ? ' · ' + T('Admin') : '') + (!m.member ? ' · ' + T('left / not a member') : '') + '</small>'
          + '<small class="muted">💬 ' + fmt(m.messages) + (m.last ? ' · ' + esc(day(m.last)) : '') + '</small></div>'
          + (m.case ? '<a class="chip-btn sm on" href="?p=cases&phone=' + encodeURIComponent(m.case) + '">🩺 ' + T('Case page') + '</a>' : '<button class="chip-btn sm" data-track="' + esc(m.phone) + '">🩺 ' + T('Follow') + '</button>') + '</div>';
      }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>';
    }
    function drawMsgs() {
      var r = G.view, who = G.who, rows = r.messages;
      if (who) {
        var mine = {}; rows.forEach(function (m) { if (m.dir === 'in' && m.from === who) mine[m.id] = 1; });
        rows = rows.filter(function (m) { return (m.dir === 'in' && m.from === who) || (m.quote && mine[m.quote]) || m.quote_from === who; });
      }
      var p = who ? r.members.filter(function (m) { return m.phone === who; })[0] : null;
      document.getElementById('gv-mt').textContent = who ? T('Messages of') + ' ' + (p ? person(p) : '+' + who) + ' (' + fmt(rows.length) + ')' : T('Latest messages');
      document.getElementById('gv-all').hidden = !who;
      var el = document.getElementById('gv-mlist');
      el.innerHTML = rows.slice(-300).map(function (m) {
        var mp = r.members.filter(function (x) { return x.phone === m.from; })[0], nm = m.dir === 'out' ? (m.by || T('You')) : (mp ? person(mp) : (m.from_name || '+' + m.from));
        var link = who && m.from !== who ? '<span class="why">↩️ ' + T('reply to them') + '</span>' : '';
        return '<div class="gv-m ' + (m.dir === 'out' ? 'out' : 'in') + (m.case ? ' tracked' : '') + '"><div class="gv-mh">' + (m.dir === 'in' ? '<button class="gv-who" data-who="' + esc(m.from) + '">' + esc(nm) + '</button>' : '<b>' + esc(nm) + '</b>') + link + '<small class="muted">' + esc(when(m.time)) + '</small>'
          + (m.dir === 'in' && !m.case ? '<button class="link-btn" data-track="' + esc(m.from) + '" title="' + T('Follow this person') + '">🩺</button>' : (m.case ? '<span class="tag mon">🩺</span>' : '')) + '</div>'
          + '<div dir="auto" class="gv-mt">' + (String(m.text || '').trim() ? esc(m.text) : '<i class="muted">[' + esc(m.kind) + ']</i>') + '</div></div>';
      }).join('') || '<div class="empty">' + T('No messages.') + '</div>';
      el.scrollTop = el.scrollHeight;
    }
    root.addEventListener('click', function (e) {
      var w = e.target.closest('[data-who]:not([data-track])');
      if (w && !e.target.closest('[data-track],a')) { G.who = w.dataset.who === G.who ? '' : w.dataset.who; drawPeople(); drawMsgs(); return; }
      var t = e.target.closest('[data-track]'); if (!t) return;
      var r = G.view, ph = t.dataset.track, p = r.members.filter(function (m) { return m.phone === ph; })[0];
      if (!confirm(T('Create a case page for') + ' ' + (p ? person(p) : '+' + ph) + ' (+' + ph + ')?\n' + T('Their messages in this group, replies to them and their private chat will be collected in one record.'))) return;
      t.disabled = true; t.textContent = '⏳';
      post('case_track', { phone: ph, group: r.jid, group_name: r.name, name: p ? p.name : '' }).then(function (x) {
        if (!x.ok) { toast('⚠️ ' + (x.error || T('Failed'))); t.disabled = false; t.textContent = '🩺'; return; }
        location.href = '?p=cases&phone=' + encodeURIComponent(x.phone);
      });
    });
    if (gj) openGroup(gj); else { shell(); loadGrid(false); }
    return;
  }
