  if (page === 'numbers') {
    var NB = { rows: [], groups: {}, q: '', sel: {}, mode: viewGet('numbers', 'list') };
    root.innerHTML = hero('📇', 'Numbers', 'Pull every number from the groups and chats — each number once, in WhatsApp format (country code + number, no + or 0), with its name and groups.', '')
      + '<div class="card nb-opts"><div class="nb-src"><b>' + T('From') + ':</b><label class="check"><input type="checkbox" value="members" checked> 👥 ' + T('Group members') + '</label><label class="check"><input type="checkbox" value="chats" checked> 💬 ' + T('Private chats') + '</label><label class="check"><input type="checkbox" value="written" checked> ✍️ ' + T('Numbers written in messages') + '</label></div>'
      + '<details class="nb-gsel"><summary>👥 ' + T('Groups') + ': <b id="nb-gn">' + T('All groups') + '</b></summary><div class="row"><button class="link-btn" id="nb-gall">' + T('All') + '</button><button class="link-btn" id="nb-gnone">' + T('None') + '</button></div><div id="nb-glist" class="nb-glist"></div></details>'
      + '<div class="row wrap"><button class="btn primary" id="nb-go">📇 ' + T('Extract numbers') + '</button><button class="chip-btn" id="nb-ref">🔄 ' + T('Re-read from WhatsApp') + '</button><span class="muted small" id="nb-st"></span></div></div>'
      + '<div class="card"><div class="row wrap nb-bar"><div class="search-xl"><span>🔎</span><input type="search" id="nb-q" placeholder="' + T('Search number, name, group…') + '" dir="auto"></div><b id="nb-n"></b>'
      + '<a class="chip-btn" id="nb-csv" href="#">⬇️ CSV</a><a class="chip-btn" id="nb-txt" href="#">⬇️ TXT</a><a class="chip-btn" id="nb-vcf" href="#">⬇️ ' + T('Contacts (VCF)') + '</a><button class="chip-btn" id="nb-copy">📋 ' + T('Copy numbers') + '</button>' + viewSwitch('numbers', NB.mode) + '</div>'
      + '<div id="nb-fwrap"></div><div id="nb-list"><div class="empty">' + T('Choose the sources, then press “Extract numbers”.') + '</div></div></div>';
    function params(extra) {
      var src = [].map.call(root.querySelectorAll('.nb-src input:checked'), function (i) { return i.value; }).join(',');
      var gs = [].map.call(root.querySelectorAll('.nb-glist input:checked'), function (i) { return i.value; }), all = root.querySelectorAll('.nb-glist input').length;
      return Object.assign({ src: src, groups: gs.length && gs.length < all ? gs.join(',') : '' }, extra || {});
    }
    function links() { ['csv', 'txt', 'vcf'].forEach(function (f) { document.getElementById('nb-' + f).href = '?' + new URLSearchParams(Object.assign({ api: 'numbers', export: f }, params())).toString(); }); }
    function load(ref) {
      var st = document.getElementById('nb-st'); if (!ref) { var gf = document.querySelector('#nb-f [data-f=group]'); if (gf) gf.value = ''; } st.textContent = T('Reading the groups… (the first time can take a minute)');
      get('numbers', params(ref ? { refresh: 1 } : {})).then(function (r) {
        if (!r.ok) { st.textContent = '⚠️ ' + (r.error || T('Failed')); return; }
        NB.rows = r.rows;
        if (!Object.keys(NB.groups).length) { NB.groups = r.groups; document.getElementById('nb-glist').innerHTML = Object.keys(r.groups).map(function (k) { return '<label class="check"><input type="checkbox" value="' + esc(k) + '" checked> <span dir="auto">' + esc(r.groups[k]) + '</span></label>'; }).join(''); }
        if (!document.getElementById('nb-f')) { document.getElementById('nb-fwrap').innerHTML = fbar('nb-f', { sort: [['groups', 'In most groups'], ['number', 'Number'], ['name', 'Name']], groups: r.groups, extra: '<label><select data-f="src"><option value="">' + T('Any source') + '</option><option value="member">👥 ' + T('Group members') + '</option><option value="admin">⭐ ' + T('Group admins') + '</option><option value="chat">💬 ' + T('Private chats') + '</option><option value="written">✍️ ' + T('Numbers written in messages') + '</option><option value="left_group">⟲ ' + T('Wrote in groups I left') + '</option></select></label><label><input type="text" data-f="cc" placeholder="' + T('Country code e.g. 20') + '" size="10" dir="ltr"></label>' }); fwire('nb-f', draw); document.querySelector('#nb-f [data-f=cc]').oninput = draw; }
        var bs = r.by_source || {};
        st.innerHTML = '✓ ' + T('Read at') + ' ' + esc(when(r.cached_at)) + ' · 👥 ' + fmt((bs.member || 0)) + ' · ⭐ ' + fmt(bs.admin || 0) + ' · 💬 ' + fmt(bs.chat || 0) + ' · ✍️ ' + fmt(bs.written || 0) + (bs.left_group ? ' · ⟲ ' + fmt(bs.left_group) : '')
          + (r.lids ? ' · ' + fmt(r.lids) + ' ' + T('members hide their number (WhatsApp privacy) — skipped') : '')
          + (r.failed && r.failed.length ? '<br>⚠️ ' + T('These groups could not be read now (previous list kept)') + ': ' + esc(r.failed.join('، ')) : '');
        draw(); links();
      });
    }
    function shown() {
      var q = NB.q.toLowerCase(), f = fval('nb-f'), gname = f.group ? NB.groups[f.group] : '';
      var rows = NB.rows.filter(function (r) { return (!q || (r.phone + ' ' + r.name + ' ' + r.groups.join(' ')).toLowerCase().indexOf(q) !== -1) && (!gname || r.groups.indexOf(gname) !== -1) && (!f.src || r.sources.indexOf(f.src) !== -1) && (!f.cc || r.phone.indexOf(f.cc.replace(/\D/g, '')) === 0); });
      var srt = f.sort || 'groups';
      return rows.sort(function (a, b) { return srt === 'number' ? a.phone.localeCompare(b.phone) : (srt === 'name' ? String(a.name || '~').localeCompare(String(b.name || '~')) : (b.groups.length - a.groups.length)); });
    }
    function draw() {
      var rows = shown(); document.getElementById('nb-n').textContent = fmt(rows.length) + ' ' + T('numbers (no duplicates)');
      var P = pg('numbers', rows, draw, 100); var pagerHtml = P.html; rows = P.rows;
      var SRC = { member: '👥', admin: '⭐', chat: '💬', written: '✍️', left_group: '⟲' };
      if (NB.mode === 'icons') {
        document.getElementById('nb-list').innerHTML = pagerHtml + '<div class="nb-cards">' + rows.map(function (r) {
          return '<a class="nb-card" href="?p=customer&jid=' + encodeURIComponent(r.phone + '@s.whatsapp.net') + '">' + avatar(r.phone + '@s.whatsapp.net', r.name || r.phone) + '<b dir="auto">' + esc(r.name || '—') + '</b><span dir="ltr">' + esc(r.phone) + '</span><small class="muted" dir="auto">' + esc(r.groups.slice(0, 2).join(' · ')) + (r.groups.length > 2 ? ' +' + (r.groups.length - 2) : '') + '</small><span>' + r.sources.map(function (s) { return SRC[s] || ''; }).join('') + (r.customer ? ' 🧾' : '') + '</span></a>';
        }).join('') + '</div>' + pagerHtml;
        return;
      }
      document.getElementById('nb-list').innerHTML = pagerHtml + '<table class="tbl nb-t"><thead><tr><th>#</th><th>' + T('Number') + '</th><th>' + T('Name') + '</th><th>' + T('Group') + '</th><th></th></tr></thead><tbody>' + rows.map(function (r, i) {
        return '<tr><td class="muted">' + (P.offset + i + 1) + '</td><td dir="ltr"><b>' + esc(r.phone) + '</b></td><td dir="auto">' + esc(r.name) + (r.customer ? ' <span class="tag">' + T('Customer') + '</span>' : '') + '</td><td dir="auto" class="nb-g">' + r.groups.map(function (g) { return '<span class="tag">' + esc(g) + '</span>'; }).join(' ') + '</td><td>' + r.sources.map(function (s) { return '<span title="' + esc(s) + '">' + (SRC[s] || '') + '</span>'; }).join('') + ' <a href="?p=inbox#' + encodeURIComponent(r.phone + '@s.whatsapp.net') + '" title="' + T('Open chat') + '">💬</a></td></tr>';
      }).join('') + '</tbody></table>' + pagerHtml;
    }
    document.getElementById('nb-go').onclick = function () { load(false); };
    document.getElementById('nb-ref').onclick = function () { load(true); };
    document.getElementById('nb-q').oninput = function () { NB.q = this.value; pgReset('numbers'); draw(); };
    document.getElementById('nb-gall').onclick = function () { [].forEach.call(root.querySelectorAll('.nb-glist input'), function (i) { i.checked = true; }); gname(); };
    document.getElementById('nb-gnone').onclick = function () { [].forEach.call(root.querySelectorAll('.nb-glist input'), function (i) { i.checked = false; }); gname(); };
    function gname() { var n = root.querySelectorAll('.nb-glist input:checked').length, a = root.querySelectorAll('.nb-glist input').length; document.getElementById('nb-gn').textContent = n === a ? T('All groups') : fmt(n) + ' / ' + fmt(a); links(); }
    root.addEventListener('change', function (e) { if (e.target.closest('.nb-glist')) gname(); if (e.target.closest('.nb-src')) links(); });
    root.querySelector('[data-vk="numbers"]').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; NB.mode = b.dataset.v; viewSet('numbers', NB.mode); [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); draw(); };
    document.getElementById('nb-copy').onclick = function () { var t = shown().map(function (r) { return r.phone; }).join('\n'); if (navigator.clipboard) navigator.clipboard.writeText(t); toast('✓ ' + T('Copied') + ' ' + fmt(shown().length)); };
    load(false);
    return;
  }
