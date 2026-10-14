  if (page === 'botgroup') {
    var gjid = new URLSearchParams(location.search).get('g') || '';
    root.innerHTML = '<a href="?p=botrun" class="link">← ' + T('Bot') + '</a><div id="bg"><div class="empty">' + T('Loading…') + '</div></div>';
    get('bot_group', { jid: gjid }).then(function (r) {
      if (!r.ok) return fail(document.getElementById('bg'), r);
      var el = document.getElementById('bg'), on = r.on;
      el.innerHTML = '<section class="hero"><div class="hero-t">' + avatar(gjid, r.name) + '<div><h1 dir="auto">' + esc(r.name) + '</h1><p class="muted">👤 ' + fmt(r.members) + ' · ⭐ ' + fmt(r.admins.length) + ' ' + T('admins') + ' · 🤖 ' + fmt(r.stats.answers) + ' ' + T('bot replies') + ' · 📝 ' + fmt(r.stats.drafts) + ' ' + T('drafts') + '</p></div></div>'
        + '<div class="hero-tools"><label class="sw big"><input type="checkbox" id="bg-on" ' + (on ? 'checked' : '') + (r.groups_mode === 'all' ? ' disabled' : '') + '><i></i><span id="bg-onl">' + (on || r.groups_mode === 'all' ? '🟢 ' + T('Bot on in this group') : '⚪ ' + T('Bot off in this group')) + '</span></label><a class="chip-btn" href="?p=inbox#' + encodeURIComponent(gjid) + '">💬 ' + T('Open chat') + '</a><a class="chip-btn" href="?p=groups&g=' + encodeURIComponent(gjid) + '">👥 ' + T('Group page') + '</a></div></section>'
        + (r.mode === 'off' ? '<div class="alert bad">⚪ ' + T('The bot is OFF for this number — switch it on in the Bot page.') + '</div>' : '') + (r.scope === 'private' ? '<div class="alert bad">' + T('The bot answers private chats only — switching it on here adds groups.') + '</div>' : '')
        + '<details class="card fold" open><summary><b>⚙️ ' + T('Settings of this group') + '</b> <span class="muted small" id="bg-msg">⚡ ' + T('Changes are saved automatically') + '</span></summary><div id="bg-cfg">' + gCfgForm(r.cfg) + '</div>'
        + (r.admins.length ? '<div class="tm-g"><b>⭐ ' + T('Admins (team — tagged when the bot hands over)') + '</b><div class="tm-list">' + r.admins.map(function (a) { return '<span class="tm-p"><b dir="auto">' + esc(a.name || '—') + '</b> <span dir="ltr">+' + esc(a.phone) + '</span></span>'; }).join('') + '</div></div>' : '') + '</details>'
        + '<details class="card fold" open><summary><b>💬 ' + T('Chat') + '</b></summary><div class="row wrap"><div class="search-xl"><span>🔎</span><input type="search" id="bg-q" placeholder="' + T('Search messages…') + '" dir="auto"></div><select id="bg-who"><option value="">' + T('All messages') + '</option><option value="bot">🤖 ' + T('Bot replies') + '</option><option value="team">⭐ ' + T('Team') + '</option><option value="members">👤 ' + T('Members') + '</option><option value="q">❓ ' + T('Questions') + '</option></select><button class="chip-btn sm" id="bg-ref">🔄</button></div><div id="bg-chat" class="bg-chat"></div></details>'
        + '<details class="card fold"><summary><b>🧾 ' + T('What the bot did in this group') + '</b> <span class="muted small">(' + fmt(r.log.length) + ')</span></summary><div id="bg-log"></div></details>';
      var msg = document.getElementById('bg-msg');
      function save() { var cfg = gCfgRead(document.getElementById('bg-cfg')); msg.textContent = T('Saving…');
        post('bot_group_set', { jid: gjid, name: r.name, on: document.getElementById('bg-on').checked ? 1 : 0, cfg: cfg }).then(function (x) { msg.textContent = x.ok ? '✓ ' + T('Saved automatically') + ' · ' + new Date().toLocaleTimeString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '⚠️ ' + x.error; }); }
      gCfgWire(document.getElementById('bg-cfg'), function () { if (document.getElementById('bg-on').checked || r.groups_mode === 'all') save(); else msg.textContent = T('Switch the bot on in this group to use these settings.'); });
      document.getElementById('bg-on').onchange = function () { document.getElementById('bg-onl').textContent = this.checked ? '🟢 ' + T('Bot on in this group') : '⚪ ' + T('Bot off in this group'); save(); };
      var admins = {}; r.admins.forEach(function (a) { admins[a.phone] = 1; });
      var CH = [];
      function loadChat() { get('thread', { jid: gjid }).then(function (t) { CH = (t.rows || []).filter(function (m) { return m.dir === 'in' || m.dir === 'out'; }).reverse(); drawChat(); }); }
      function drawChat() { var q = (document.getElementById('bg-q').value || '').toLowerCase(), w = document.getElementById('bg-who').value;
        var rows = CH.filter(function (m) { var bot = m.dir === 'out' && m.via === 'bot', team = m.dir === 'out' && !bot || admins[String(m.from || '').replace(/\D/g, '')];
          return (!q || String(m.text || '').toLowerCase().indexOf(q) !== -1 || String(m.from_name || '').toLowerCase().indexOf(q) !== -1) && (!w || (w === 'bot' ? bot : w === 'team' ? team : w === 'members' ? !bot && !team : /[؟?]/.test(m.text || ''))); });
        var P = pg('bgchat', rows, drawChat, 50);
        document.getElementById('bg-chat').innerHTML = P.html + P.rows.map(function (m) { var bot = m.dir === 'out' && m.via === 'bot', team = !bot && (m.dir === 'out' || admins[String(m.from || '').replace(/\D/g, '')]);
          return '<div class="bgm ' + (bot ? 'bot' : team ? 'team' : 'in') + '"><div class="bgm-h"><b dir="auto">' + (bot ? '🤖 ' + T('Bot') : m.dir === 'out' ? '📱 ' + T('You') : esc(m.from_name || ('+' + (m.from || '')))) + (team && !bot && m.dir !== 'out' ? ' ⭐' : '') + '</b><small class="muted">' + esc(when(m.time)) + '</small></div><div dir="auto" class="bgm-t">' + (String(m.text || '').trim() ? esc(m.text) : '<i class="muted">[' + esc(m.kind || '') + ']</i>') + '</div></div>'; }).join('') + P.html; }
      document.getElementById('bg-q').oninput = function () { pgReset('bgchat'); drawChat(); }; document.getElementById('bg-who').onchange = function () { pgReset('bgchat'); drawChat(); };
      document.getElementById('bg-ref').onclick = loadChat; loadChat(); setInterval(function () { if (!document.hidden && (PG.bgchat || {}).p === 1) loadChat(); }, 20000);
      function bgLog() { var P = pg('bglog', r.log, bgLog, 20); document.getElementById('bg-log').innerHTML = logTable(P.rows) + P.html; } bgLog();
    });
    return;
  }
