  if (page === 'botrun') {
    var BR = { c: null, groups: [], q: '', f: '', sort: 'on' };
    root.innerHTML = hero('🤖', 'Bot', 'Switch the bot on, choose where it answers, and set up each group. Changes are saved automatically.', '<a class="chip-btn" href="?p=bot">🧠 ' + T('Learning & test') + '</a>') + '<div id="brp"><div class="empty">' + T('Loading…') + '</div></div><div class="card" id="br-roles" hidden></div>';
    // 🎭 Roles: healer, emotional support, dream interpreter… on top of the regular bot, and the danger safeguard
    get('bot_roles').then(function (r) { var box = document.getElementById('br-roles'); if (!r || !r.ok || !Object.keys(r.cfg.roles || {}).length) return; var c = r.cfg; box.hidden = false;
      box.innerHTML = '<h3>🎭 ' + T('Bot roles') + '</h3><p class="muted small">' + T('One bot, several hats: a message with a role\'s words is answered in that role, and the chat keeps it for a few hours. Anything else is answered by the regular bot.') + '</p>'
        + '<label class="check"><input type="checkbox" id="ro-on"' + (c.on ? ' checked' : '') + '> ' + T('On') + '</label>'
        + '<div class="gcf"><label>' + T('Keep the role in a chat (hours)') + '<input id="ro-h" type="number" min="1" max="72" value="' + (+c.sticky_h) + '"></label><label>' + T('Strong models, in order (providers)') + '<input id="ro-s" dir="ltr" value="' + esc(c.strong) + '"></label></div>'
        + '<label>' + T('Role in groups') + '<select id="ro-g"><option value="">🤖 ' + T('Regular bot') + '</option>' + Object.keys(c.roles).map(function (k) { return '<option value="' + k + '"' + (c.group_role === k ? ' selected' : '') + '>' + esc((c.roles[k].icon || '') + ' ' + T(c.roles[k].label || k)) + '</option>'; }).join('') + '</select></label>'
        + '<label>' + T('Rules for every role (no invention, no repeating)') + '<textarea rows="4" dir="auto" id="ro-cm">' + esc(c.common || '') + '</textarea></label>'
        + '<p class="muted small">🧠 ' + T('Strong model now') + ': <b dir="ltr">' + esc(r.strong_now || T('none — turn on OpenCode Go or Gemini in AI providers')) + '</b></p>'
        + (function () { var S = r.stats || {}, ks = Object.keys(S); if (!ks.length) return ''; return '<div class="ro-st"><b>📊 ' + T('Last 7 days') + '</b>' + ks.map(function (k) { var x = c.roles[k] || { icon: '🤖', label: 'Regular bot' }; return '<span class="th-chip">' + esc(x.icon || '') + ' ' + esc(T(x.label || k)) + ': ' + fmt(S[k].sent) + ' ' + T('replies sent') + ' · ' + fmt(S[k].handoff) + ' ' + T('to a person') + '</span>'; }).join('') + '</div>'; })()
        + Object.keys(c.roles).map(function (k) { var x = c.roles[k]; return '<details class="ro-r"' + (x.on ? ' open' : '') + '><summary>' + esc(x.icon || '') + ' <b>' + esc(T(x.label || k)) + '</b></summary>'
          + '<label class="check"><input type="checkbox" data-ro="' + k + '" data-f="on"' + (x.on ? ' checked' : '') + '> ' + T('On') + '</label> <label class="check"><input type="checkbox" data-ro="' + k + '" data-f="strong"' + (x.strong ? ' checked' : '') + '> ' + T('Use a strong model') + '</label>'
          + '<label>' + T('Words that call this role (comma)') + '<textarea rows="2" dir="auto" data-ro="' + k + '" data-f="words">' + esc(x.words || '') + '</textarea></label>'
          + '<label>' + T('Instructions') + '<textarea rows="5" dir="auto" data-ro="' + k + '" data-f="rules">' + esc(x.rules || '') + '</textarea></label>'
          + '<label>' + T('Note in the first reply') + '<input dir="auto" data-ro="' + k + '" data-f="intro" value="' + esc(x.intro || '') + '"></label></details>'; }).join('')
        + '<details class="ro-r" open><summary>🔴 <b>' + T('Danger safeguard') + '</b></summary><p class="muted small">' + T('Words that show danger (self-harm, suicide, violence): the bot stops in that chat, sends the fixed support message (Message templates → crisis_reply) and alerts the team at once.') + '</p>'
          + '<label class="check"><input type="checkbox" id="ro-c-on"' + (c.crisis.on ? ' checked' : '') + '> ' + T('On') + '</label>'
          + '<label>' + T('Danger words (comma)') + '<textarea rows="3" dir="auto" id="ro-c-w">' + esc(c.crisis.words || '') + '</textarea></label>'
          + '<label>' + T('Alert to (group or number id; empty = the diagnosis group)') + '<input dir="ltr" id="ro-c-a" value="' + esc(c.crisis.alert || '') + '" placeholder="1203…@g.us"></label></details>'
        + '<div class="row"><button class="btn primary" id="ro-save">💾 ' + T('Save') + '</button></div>';
      box.querySelector('#ro-save').onclick = function () { var q = function (s) { return box.querySelector(s); }, roles = {};
        Object.keys(c.roles).forEach(function (k) { roles[k] = {}; [].forEach.call(box.querySelectorAll('[data-ro="' + k + '"]'), function (i) { roles[k][i.dataset.f] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value; }); });
        post('bot_roles', { on: q('#ro-on').checked ? 1 : 0, sticky_h: q('#ro-h').value, strong: q('#ro-s').value, group_role: q('#ro-g').value, common: q('#ro-cm').value, roles: roles, crisis: { on: q('#ro-c-on').checked ? 1 : 0, words: q('#ro-c-w').value, alert: q('#ro-c-a').value } })
          .then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); }); }; });
    get('bot_run').then(function (r) {
      var c = BR.c = r.run, el = document.getElementById('brp');
      var opt = function (v, l, d) { return '<label class="br-opt' + (c.mode === v ? ' on' : '') + '"><input type="radio" name="br-mode" value="' + v + '" ' + (c.mode === v ? 'checked' : '') + '><b>' + T(l) + '</b><small class="muted">' + T(d) + '</small></label>'; };
      el.innerHTML = '<details class="card fold" open><summary><b>⚙️ ' + T('General') + '</b> <span class="muted small">' + esc(r.account) + '</span></summary>'
        + '<div class="br-modes">' + opt('off', '⚪ Off', 'The bot does nothing.') + opt('draft', '📝 Suggest only', 'For every new customer message the bot writes a reply draft in the chat; a person reviews and sends it.') + opt('auto', '🤖 Automatic', 'The bot replies by itself when it is confident; anything else stays as a draft for a person.') + '</div>'
        + '<div class="seg" id="bp-scope">' + [['private', '💬 ' + T('Private chats only')], ['both', '💬 + 👥 ' + T('Private chats and groups')], ['groups', '👥 ' + T('Groups only')]].map(function (o) { return '<button type="button" data-s="' + o[0] + '" class="' + ((c.scope || 'private') === o[0] ? 'on' : '') + '">' + o[1] + '</button>'; }).join('') + '</div>'
        + '<div class="br-f"><label>' + T('Reply automatically only when confidence is at least') + '<select id="bp-conf">' + [0.6, 0.7, 0.8, 0.9].map(function (v) { return '<option value="' + v + '" ' + (Math.abs(c.min_conf - v) < 0.01 ? 'selected' : '') + '>' + Math.round(v * 100) + '%</option>'; }).join('') + '</select></label>'
        + '<label>' + T('Working hours') + '<span class="row"><select id="bp-from">' + Array.from({ length: 24 }, function (_, i) { return '<option value="' + i + '" ' + (c.from === i ? 'selected' : '') + '>' + i + ':00</option>'; }).join('') + '</select>→<select id="bp-to">' + Array.from({ length: 25 }, function (_, i) { return '<option value="' + i + '" ' + (c.to === i ? 'selected' : '') + '>' + i + ':00</option>'; }).join('') + '</select></span></label>'
        + '<label>' + T('Max automatic replies per hour') + '<input type="number" id="bp-ph" min="1" max="60" value="' + c.per_hour + '"></label>'
        + '<label>' + T('The team counts as active in a chat for (hours) after they reply') + '<input type="number" id="bp-hp" min="0" max="72" value="' + c.human_pause + '"></label>'
            + '<label>' + T('While the team is active, wait this many minutes for them before the bot answers') + '<input type="number" id="bp-hw" min="0" max="240" value="' + (c.human_wait == null ? 10 : c.human_wait) + '"></label>'
            + '<div class="gcf-wide sub-h">🛡️ ' + T('Protection against repeating and WhatsApp blocks') + '</div>'
            + '<label>' + T('Answer when the customer has stopped typing for (seconds)') + '<input type="number" id="bp-qs" min="3" max="120" value="' + (c.quiet_secs == null ? 6 : c.quiet_secs) + '"></label>'
            + '<label>' + T('Longest wait while they keep typing (seconds)') + '<input type="number" id="bp-gs" min="5" max="600" value="' + (c.gather_secs == null ? 45 : c.gather_secs) + '"></label>'
            + '<label>' + T('Most bot replies in one chat per hour') + '<input type="number" id="bp-cph" min="1" max="30" value="' + (c.chat_per_hour || 12) + '"></label>'
            + '<label>' + T('A reply this similar to an earlier one counts as a repeat (%)') + '<input type="number" id="bp-rs" min="40" max="100" value="' + Math.round((c.repeat_sim || 0.7) * 100) + '"></label>'
            + '<label class="check"><input type="checkbox" id="bp-rr" ' + (c.repeat_retry !== false ? 'checked' : '') + '> ' + T('On a repeat, try once more with a stronger model, then hand over to the team') + '</label>'
            + '<label>' + T('Clarifying questions in a row before handing over') + '<input type="number" id="bp-mc" min="1" max="5" value="' + (c.max_clarify || 1) + '"></label>'
            + '<label>' + T('Groups: seconds between two replies to the same person') + '<input type="number" id="bp-gg" min="0" max="3600" value="' + (c.group_gap === undefined ? 120 : c.group_gap) + '"></label>'
            + '<label>' + T('Typing time (seconds) — from / to, by reply length') + '<span class="row"><input type="number" id="bp-tmin" min="0.5" max="20" step="0.5" value="' + (c.typing_min || 2) + '" style="width:80px"><input type="number" id="bp-tmax" min="1" max="30" value="' + (c.typing_max || 8) + '" style="width:80px"></span></label>'
            + '<label class="gcf-wide">' + T('Words that stop the bot in a chat (the customer sends only this)') + '<input id="bp-sw" dir="auto" value="' + esc(c.stop_words == null ? 'وقف، توقف، كفاية، بس كده، stop' : c.stop_words) + '"></label>'
            + '<label>' + T('New number warm-up until (date)') + '<input type="date" id="bp-wu" value="' + esc(c.warmup_until || '') + '"></label>'
            + '<label>' + T('Most replies per hour during warm-up') + '<input type="number" id="bp-wph" min="1" max="60" value="' + (c.warmup_per_hour || 5) + '"></label>'
            + '<label class="check gcf-wide"><input type="checkbox" id="bp-cp" ' + (c.case_private !== false ? 'checked' : '') + '> 🩺 ' + T('Add private chats to the case record (for people who are cases)') + '</label>'
            + '<label class="check gcf-wide"><input type="checkbox" id="bp-cpn" ' + (c.case_private_new ? 'checked' : '') + '> ➕ ' + T('Open a new case for a person who describes symptoms in a private chat') + '</label>'
        + '<label>🧪 ' + T('Test numbers (the bot always answers them, even your own / team numbers)') + '<input id="bp-test" dir="ltr" value="' + esc((c.test || []).join(', ')) + '"></label></div>'
        + '<div class="row br-savebar"><span class="muted small" id="bp-msg">⚡ ' + T('Changes are saved automatically') + '</span></div></details>'
        + '<details class="card fold" open><summary><b>👥 ' + T('Groups') + '</b> <span class="muted small" id="bp-gcount"></span></summary>'
        + '<div class="row wrap gt-bar"><div class="search-xl"><span>🔎</span><input type="search" id="bp-q" placeholder="' + T('Search groups…') + '" dir="auto"></div>'
        + '<select id="bp-f"><option value="">' + T('All groups') + '</option><option value="on">🟢 ' + T('Bot on') + '</option><option value="off">⚪ ' + T('Bot off') + '</option><option value="ai">✨ ' + T('Interactive') + '</option><option value="fixed">📌 ' + T('Fixed message') + '</option><option value="learn">📚 ' + T('Learning') + '</option><option value="welcome">👋 ' + T('Welcome') + '</option></select>'
        + '<select id="bp-sort"><option value="on">' + T('Bot on first') + '</option><option value="activity">' + T('Last activity (newest)') + '</option><option value="messages">' + T('Most messages') + '</option><option value="members">' + T('Most members') + '</option><option value="name">' + T('Name') + '</option></select>'
        + '<div class="seg sm" id="bp-gmode"><button type="button" data-g="selected" class="' + (c.groups_mode !== 'all' ? 'on' : '') + '">' + T('Only the groups I choose') + '</button><button type="button" data-g="all" class="' + (c.groups_mode === 'all' ? 'on' : '') + '">' + T('All groups') + '</button></div></div>'
        + '<details class="gdef"><summary>' + T('Default for groups') + '</summary><div id="bp-gdef">' + gCfgForm(c.group_default || {}) + '</div></details>'
        + '<div id="bp-gt"><div class="empty">' + T('Loading groups…') + '</div></div></details>'
        + '<details class="card fold" id="bp-join" data-key="join"><summary><b>🚪 ' + T('Joining groups') + '</b></summary><div class="pad" id="bp-join-b"><div class="empty">' + T('Loading…') + '</div></div></details>'
        + '<details class="card fold"><summary><b>👥 ' + T('Team — never answered by the bot; their answers in groups are learned') + '</b></summary><div id="bp-team" class="muted small">' + T('Loading…') + '</div></details>'
        + '<details class="card fold"><summary><b>🧾 ' + T('What the bot did') + '</b> <span class="muted small">(' + fmt(r.log.length) + ')</span></summary><div class="row wrap"><div class="search-xl"><span>🔎</span><input type="search" id="bp-lq" placeholder="' + T('Search the log…') + '" dir="auto"></div><select id="bp-lr"><option value="">' + T('All') + '</option><option value="sent">✅ ' + T('Sent') + '</option><option value="handoff">🙋 ' + T('Sent and handed over to a person') + '</option><option value="draft">📝 ' + T('Draft') + '</option><option value="skipped">⏭️ ' + T('Skipped') + '</option></select></div><div id="bp-log"></div></details>';
      // ---- general settings: auto-save
      var lastMode = c.mode, msg = document.getElementById('bp-msg'), st = null;
      function saveGen() {
        var mode = el.querySelector('input[name=br-mode]:checked').value;
        if (mode === 'auto' && lastMode !== 'auto' && !confirm(T('Switch on automatic replies? The bot will answer customers by itself when it is confident.'))) { el.querySelector('input[name=br-mode][value="' + lastMode + '"]').checked = true; syncModes(); return; }
        msg.textContent = T('Saving…');
        post('bot_run', { mode: mode, scope: document.querySelector('#bp-scope .on').dataset.s, groups_mode: document.querySelector('#bp-gmode .on').dataset.g, group_default: gCfgRead(document.getElementById('bp-gdef')),
          min_conf: document.getElementById('bp-conf').value, from: document.getElementById('bp-from').value, to: document.getElementById('bp-to').value, per_hour: document.getElementById('bp-ph').value, human_pause: document.getElementById('bp-hp').value, human_wait: document.getElementById('bp-hw').value, quiet_secs: document.getElementById('bp-qs').value, gather_secs: document.getElementById('bp-gs').value, chat_per_hour: document.getElementById('bp-cph').value, repeat_sim: (+document.getElementById('bp-rs').value || 70) / 100, repeat_retry: document.getElementById('bp-rr').checked ? 1 : 0, max_clarify: document.getElementById('bp-mc').value, group_gap: (document.getElementById('bp-gg') || {}).value, typing_min: document.getElementById('bp-tmin').value, typing_max: document.getElementById('bp-tmax').value, stop_words: document.getElementById('bp-sw').value, warmup_until: document.getElementById('bp-wu').value, warmup_per_hour: document.getElementById('bp-wph').value, case_private: document.getElementById('bp-cp').checked ? 1 : 0, case_private_new: document.getElementById('bp-cpn').checked ? 1 : 0, test: document.getElementById('bp-test').value })
          .then(function (x) { if (!x.ok) { msg.textContent = '⚠️ ' + x.error; return; } lastMode = mode; c = BR.c = x.run; msg.textContent = '✓ ' + T('Saved automatically') + ' · ' + new Date().toLocaleTimeString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' }); drawGroups(); });
      }
      function later() { clearTimeout(st); st = setTimeout(saveGen, 900); }
      function syncModes() { [].forEach.call(el.querySelectorAll('.br-opt'), function (l) { l.classList.toggle('on', l.querySelector('input').checked); }); }
      el.querySelector('.br-modes').addEventListener('change', function () { syncModes(); later(); });
      ['bp-conf', 'bp-from', 'bp-to'].forEach(function (id) { document.getElementById(id).onchange = later; });
      ['bp-ph', 'bp-hp', 'bp-test'].forEach(function (id) { document.getElementById(id).oninput = later; });
      ['bp-scope', 'bp-gmode'].forEach(function (id) { document.getElementById(id).onclick = function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); later(); }; });
      gCfgWire(document.getElementById('bp-gdef'), saveGen);
      // ---- groups table
      get('groups').then(function (gr) { BR.groups = (gr.groups || []).filter(function (g) { return !g.left; }); drawGroups(); });
      function drawGroups() {
        var box = document.getElementById('bp-gt'); if (!BR.groups.length) return;
        var sel = c.groups || {}, all = c.groups_mode === 'all', q = (document.getElementById('bp-q').value || '').toLowerCase(), f = document.getElementById('bp-f').value, srt = document.getElementById('bp-sort').value;
        var rows = BR.groups.map(function (g) { var on = !!sel[g.jid] || all; return Object.assign({}, g, { on: on, explicit: !!sel[g.jid], cfg: sel[g.jid] || c.group_default || {} }); })
          .filter(function (g) { return (!q || String(g.name).toLowerCase().indexOf(q) !== -1) && (!f || (f === 'on' ? g.on : f === 'off' ? !g.on : f === 'learn' ? g.on && g.cfg.learn : f === 'welcome' ? g.on && g.cfg.welcome : g.on && (g.cfg.mode || 'ai') === f)); });
        rows.sort(function (a, b) { return srt === 'on' ? (b.on - a.on) || (b.messages - a.messages) : srt === 'members' ? b.members - a.members : srt === 'messages' ? b.messages - a.messages : srt === 'name' ? String(a.name).localeCompare(String(b.name)) : String(b.last || '').localeCompare(String(a.last || '')); });
        document.getElementById('bp-gcount').textContent = '(' + fmt(BR.groups.filter(function (g) { return sel[g.jid] || all; }).length) + ' ' + T('with the bot on') + ' / ' + fmt(BR.groups.length) + ')';
        var P = pg('botgroups', rows, drawGroups, 20);
        box.innerHTML = '<table class="tbl gt"><thead><tr><th>' + T('Bot') + '</th><th>' + T('Group') + '</th><th>' + T('Members') + '</th><th>' + T('Messages (2 months)') + '</th><th>' + T('Last activity') + '</th><th>' + T('Reply type') + '</th><th>' + T('When') + '</th><th>📚</th><th>👋</th><th></th></tr></thead><tbody>'
          + P.rows.map(function (g) { return '<tr class="' + (g.on ? 'on' : '') + '"><td><label class="sw"><input type="checkbox" data-gt-on="' + esc(g.jid) + '" data-name="' + esc(g.name) + '" ' + (g.on ? 'checked' : '') + (all && !g.explicit ? ' disabled title="' + T('All groups') + '"' : '') + '><i></i></label></td>'
            + '<td><a class="gr-n" href="?p=botgroup&g=' + encodeURIComponent(g.jid) + '">' + avatar(g.jid, g.name) + '<b dir="auto">' + esc(g.name) + '</b></a></td><td>' + fmt(g.members) + '</td><td>' + fmt(g.messages) + '</td><td>' + (g.last ? esc(day(g.last)) : '—') + '</td>'
            + '<td>' + (g.on ? GMODE[g.cfg.mode || 'ai'] : '—') + '</td><td>' + (g.on ? GTRIG[g.cfg.trigger || 'mention'] : '—') + '</td><td>' + (g.on && g.cfg.learn ? '✅' : '') + '</td><td>' + (g.on && g.cfg.welcome ? '✅' : '') + '</td>'
            + '<td><a class="chip-btn sm" href="?p=botgroup&g=' + encodeURIComponent(g.jid) + '">⚙️ ' + T('Open') + '</a></td></tr>'; }).join('') + '</tbody></table>' + P.html;
      }
      ['bp-q'].forEach(function (id) { document.getElementById(id).oninput = function () { pgReset('botgroups'); drawGroups(); }; });
      ['bp-f', 'bp-sort'].forEach(function (id) { document.getElementById(id).onchange = function () { pgReset('botgroups'); drawGroups(); }; });
      document.getElementById('bp-gt').addEventListener('change', function (e) { var cb = e.target.closest('[data-gt-on]'); if (!cb) return;
        post('bot_group_set', { jid: cb.dataset.gtOn, name: cb.dataset.name, on: cb.checked ? 1 : 0 }).then(function (x) { if (!x.ok) { toast('⚠️ ' + x.error); cb.checked = !cb.checked; return; } c.groups = c.groups || {}; if (cb.checked) c.groups[cb.dataset.gtOn] = x.group; else delete c.groups[cb.dataset.gtOn]; if (x.scope) c.scope = x.scope; [].forEach.call(document.querySelectorAll('#bp-scope button'), function (b) { b.classList.toggle('on', b.dataset.s === c.scope); }); toast('✓ ' + T('Saved')); drawGroups(); }); });
      // ---- team (on open)
      el.querySelectorAll('details.fold')[2].addEventListener('toggle', function () { if (!this.open || this.dataset.l) return; this.dataset.l = 1;
        // Joining groups: the bot adds people who ask to join (or rejoin) — which groups, and what to do with people an admin removed. Saved automatically.
        (function joinCard() { var jb = document.getElementById('bp-join-b'); if (!jb) return;
          get('bot_join_cfg').then(function (j) { if (!j.ok) { jb.textContent = j.error || ''; return; } var J = j.cfg;
            if (!j.groups.length) { jb.innerHTML = '<div class="jg-empty"><span>🔒</span><b>' + T('This number is not an admin of any group.') + '</b><small>' + T('Only a group admin can add people. Make this number an admin of a group, then come back.') + '</small></div>'; return; }
            var sel = {}, filter = 'all', q = '', timer = null; J.groups.forEach(function (g) { sel[g] = 1; });
            var nrmq = function (s) { return String(s || '').toLowerCase().replace(/[ً-ٰٟـ]/g, '').replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه'); };
            var order = j.groups.slice().sort(function (a, b) { return (sel[b.jid] ? 1 : 0) - (sel[a.jid] ? 1 : 0) || b.members - a.members; }); // chosen first (fixed while you click)
            jb.innerHTML = '<div class="jg">'
              + '<div class="jg-hero"><span class="jg-ic">🚪</span><div class="jg-ht"><b>' + T('Let the bot add people to groups') + '</b><small>' + T('When someone asks to join or rejoin a group, or says the link does not work, the bot adds them and tells them what happened.') + '</small></div>'
              + '<label class="jg-sw"><input type="checkbox" id="bj-on"' + (J.on ? ' checked' : '') + '><i></i><span id="bj-onl"></span></label></div>'
              + '<div class="jg-warn" id="bj-warn" hidden></div>'
              + '<div class="jg-body" id="bj-body">'
              + '<section class="jg-sec"><div class="jg-sh"><span class="jg-n1">1</span><div><b>' + T('Which groups may the bot add people to?') + '</b><small>' + T('Only groups where this number is an admin are listed.') + '</small></div>'
              + '<div class="jg-stats"><span class="jg-pill" id="bj-cnt"></span><span class="jg-pill" title="' + esc(T('Daily limit that protects the number')) + '">⏱ ' + T('Left today') + ' <b>' + j.left + '</b></span></div></div>'
              + '<div class="jg-bar"><div class="search-xl"><span>🔎</span><input type="search" id="bj-q" placeholder="' + esc(T('Search groups…')) + '" dir="auto"></div>'
              + '<div class="seg sm" id="bj-f"><button type="button" data-f="all" class="on">' + T('All') + ' <i>' + j.groups.length + '</i></button><button type="button" data-f="sel">' + T('Chosen') + ' <i id="bj-fc"></i></button></div>'
              + '<button type="button" class="chip-btn sm" id="bj-all">☑ ' + T('Choose all shown') + '</button><button type="button" class="chip-btn sm" id="bj-none">☐ ' + T('Clear') + '</button></div>'
              + '<div class="jg-list" id="bj-list"></div></section>'
              + '<section class="jg-sec"><div class="jg-sh"><span class="jg-n1">2</span><div><b>' + T('Someone an admin removed from the group asks to come back') + '</b><small>' + T('People who left by themselves are always added like anyone else.') + '</small></div></div>'
              + '<div class="jg-opts"><label class="jg-opt' + (J.removed !== 'team' ? ' on' : '') + '"><input type="radio" name="bj-rm" value="readd"' + (J.removed !== 'team' ? ' checked' : '') + '><span class="jg-oi">↩️</span><b>' + T('Add them back') + '</b><small>' + T('Directly, or with WhatsApp\'s personal invitation if their privacy blocks it.') + '</small></label>'
              + '<label class="jg-opt' + (J.removed === 'team' ? ' on' : '') + '"><input type="radio" name="bj-rm" value="team"' + (J.removed === 'team' ? ' checked' : '') + '><span class="jg-oi">🙋</span><b>' + T('Leave it to the team') + '</b><small>' + T('The bot says the team will add them and stops there.') + '</small></label></div></section>'
              + '<details class="jg-how"><summary>💡 ' + T('How it works') + '</summary><ol><li>' + T('The person writes to the bot that they want to join, or that the link does not work.') + '</li><li>' + T('The bot checks the group history: did they leave by themselves, or did an admin remove them?') + '</li><li>' + T('It adds them directly; if their privacy blocks that, it sends WhatsApp\'s personal invitation. The public link is never sent to someone who was removed — WhatsApp refuses it.') + '</li><li>' + T('Works in automatic mode and working hours only. The wording of the replies: Settings → Message templates (Bot: …).') + '</li></ol></details>'
              + '</div><div class="jg-foot"><span class="muted small" id="bj-msg">⚡ ' + T('Changes are saved automatically') + '</span></div></div>';
            var $ = function (id) { return document.getElementById(id); };
            function chosen() { return Object.keys(sel); }
            function drawList() { var items = order.filter(function (g) { return (filter === 'all' || sel[g.jid]) && (!q || nrmq(g.name || g.jid).indexOf(q) !== -1); });
              $('bj-list').innerHTML = items.length ? items.map(function (g) { return '<label class="jg-g' + (sel[g.jid] ? ' on' : '') + '"><input type="checkbox" value="' + esc(g.jid) + '"' + (sel[g.jid] ? ' checked' : '') + '><i class="jg-ck">✓</i><span class="jg-gn" dir="auto">' + esc(g.name || g.jid) + '</span><small title="' + esc(T('Members')) + '">👤 ' + fmt(g.members) + '</small></label>'; }).join('') : '<div class="empty">' + T('No groups match.') + '</div>';
              $('bj-cnt').innerHTML = '✅ ' + T('Chosen') + ' <b>' + fmt(chosen().length) + '</b> / ' + fmt(j.groups.length); $('bj-fc').textContent = chosen().length; }
            function sync() { var on = $('bj-on').checked; $('bj-onl').textContent = on ? T('On') : T('Off'); $('bj-body').classList.toggle('off', !on);
              var w = $('bj-warn'); w.hidden = !(on && !chosen().length); w.textContent = '⚠️ ' + T('Choose at least one group, otherwise the bot cannot add anyone.'); }
            function save() { clearTimeout(timer); timer = setTimeout(function () { $('bj-msg').textContent = T('Saving…');
              post('bot_join_cfg', { on: $('bj-on').checked ? 1 : 0, removed: (jb.querySelector('input[name=bj-rm]:checked') || {}).value || 'readd', groups: chosen() })
                .then(function (r) { $('bj-msg').textContent = r.ok ? '✓ ' + T('Saved automatically') + ' · ' + new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : '⚠️ ' + (r.error || T('Failed')); }); }, 700); }
            $('bj-on').onchange = function () { sync(); save(); };
            $('bj-q').oninput = function () { q = nrmq(this.value.trim()); drawList(); };
            $('bj-f').onclick = function (e) { var b = e.target.closest('[data-f]'); if (!b) return; filter = b.dataset.f; [].forEach.call($('bj-f').children, function (x) { x.classList.toggle('on', x === b); }); drawList(); };
            $('bj-list').onchange = function (e) { var i = e.target; if (i.type !== 'checkbox') return; if (i.checked) sel[i.value] = 1; else delete sel[i.value]; i.closest('.jg-g').classList.toggle('on', i.checked); $('bj-cnt').innerHTML = '✅ ' + T('Chosen') + ' <b>' + fmt(chosen().length) + '</b> / ' + fmt(j.groups.length); $('bj-fc').textContent = chosen().length; sync(); save(); };
            $('bj-all').onclick = function () { [].forEach.call($('bj-list').querySelectorAll('input'), function (i) { sel[i.value] = 1; }); drawList(); sync(); save(); };
            $('bj-none').onclick = function () { [].forEach.call($('bj-list').querySelectorAll('input'), function (i) { delete sel[i.value]; }); drawList(); sync(); save(); };
            jb.querySelector('.jg-opts').onchange = function () { [].forEach.call(jb.querySelectorAll('.jg-opt'), function (o) { o.classList.toggle('on', o.querySelector('input').checked); }); save(); };
            drawList(); sync(); }); })();
        get('bot_team').then(function (t) { var b = document.getElementById('bp-team'); if (!t.ok) { b.textContent = '⚠️ ' + (t.error || ''); return; }
          var person = function (p) { return '<span class="tm-p"><b dir="auto">' + esc(p.name || '—') + '</b> <span dir="ltr">' + (p.hidden ? T('hidden number') : '+' + esc(p.phone)) + '</span></span>'; };
          b.innerHTML = t.groups.map(function (g) { return '<div class="tm-g"><b dir="auto">👥 ' + esc(g.name) + '</b><div class="tm-list">' + g.admins.map(person).join('') + '</div></div>'; }).join('') + '<div class="tm-g"><b>📱 ' + T('Our numbers') + '</b><div class="tm-list">' + t.own.map(person).join('') + '</div></div><div class="tm-g"><b>➕ ' + T('Added by hand') + '</b><div class="tm-list">' + (t.manual.map(person).join('') || '—') + '</div></div>'; }); });
      // ---- log
      function logDraw() { var q = (document.getElementById('bp-lq').value || '').toLowerCase(), rf = document.getElementById('bp-lr').value;
        var rows = r.log.filter(function (x) { return (!rf || x.result === rf) && (!q || JSON.stringify([x.q, x.reply, x.name, x.why]).toLowerCase().indexOf(q) !== -1); }), P = pg('bplog', rows, logDraw, 20);
        document.getElementById('bp-log').innerHTML = logTable(P.rows) + P.html; }
      document.getElementById('bp-lq').oninput = function () { pgReset('bplog'); logDraw(); }; document.getElementById('bp-lr').onchange = function () { pgReset('bplog'); logDraw(); };
      // History: extra filters — where (private / group / cache), dates, sort
      document.getElementById('bp-lr').insertAdjacentHTML('afterend', '<select id="bp-lt"><option value="">' + T('Everywhere') + '</option><option value="private">💬 ' + T('Private chats') + '</option><option value="group">👥 ' + T('Groups') + '</option><option value="cache">♻️ ' + T('From the cache') + '</option></select>');
      document.getElementById('bp-log').insertAdjacentHTML('beforebegin', fbar('bp-lf', { sort: [['new', 'Newest first'], ['old', 'Oldest first'], ['conf', 'Highest confidence']], dates: true }));
      var logDraw0 = logDraw; logDraw = function () { var q = (document.getElementById('bp-lq').value || '').toLowerCase(), rf = document.getElementById('bp-lr').value, lt = document.getElementById('bp-lt').value, f = fval('bp-lf');
        var rows = r.log.filter(function (x) { return (!rf || x.result === rf) && (!lt || (lt === 'group' ? x.group : lt === 'cache' ? x.model === 'cache' : !x.group)) && inRange(x.at, f.from, f.to) && (!q || JSON.stringify([x.q, x.reply, x.name, x.why]).toLowerCase().indexOf(q) !== -1); });
        if (f.sort === 'old') rows = rows.slice().reverse(); if (f.sort === 'conf') rows = rows.slice().sort(function (a, b) { return (b.confidence || 0) - (a.confidence || 0); });
        var P = pg('bplog', rows, logDraw, 20); document.getElementById('bp-log').innerHTML = '<div class="muted small">' + fmt(rows.length) + ' ' + T('entries') + '</div>' + logTable(P.rows) + P.html; };
      document.getElementById('bp-lt').onchange = function () { pgReset('bplog'); logDraw(); };
      document.getElementById('bp-lf').addEventListener('change', function () { pgReset('bplog'); logDraw(); });
      document.getElementById('bp-lf').addEventListener('click', function (e) { var b = e.target.closest('[data-days]'); if (!b) return; var d = new Date(Date.now() - b.dataset.days * 864e5).toISOString().slice(0, 10); this.querySelector('[data-f=from]').value = d; this.querySelector('[data-f=to]').value = ''; pgReset('bplog'); logDraw(); });
      logDraw();
      // Drafts and paused chats get their own tabs
      el.insertAdjacentHTML('beforeend', '<details class="card fold" data-key="chats"><summary><b>💬 ' + T('Chats') + '</b> <span class="muted small" id="bp-cn"></span></summary><div class="row wrap"><div class="search-xl"><span>🔎</span><input type="search" id="bp-cq" placeholder="' + T('Search name or number…') + '" dir="auto"></div>'
        + '<select id="bp-ct"><option value="">' + T('Private chats and groups') + '</option><option value="p">👤 ' + T('Private chats') + '</option><option value="g">👥 ' + T('Groups') + '</option></select>'
        + '<select id="bp-cf"><option value="">' + T('All') + '</option><option value="on">🟢 ' + T('Bot on') + '</option><option value="off">⏸ ' + T('Bot off') + '</option><option value="wait">✉️ ' + T('Waiting for a reply') + '</option></select>'
        + '<select id="bp-cs"><option value="last">' + T('Last message (newest)') + '</option><option value="count">' + T('Most messages') + '</option><option value="name">' + T('Name') + '</option></select>'
        + '<button class="chip-btn sm" id="bp-call-on">🟢 ' + T('Bot on for shown') + '</button><button class="chip-btn sm" id="bp-call-off">⏸ ' + T('Bot off for shown') + '</button></div><div id="bp-cl"><div class="empty">' + T('Loading…') + '</div></div></details>');
      var CH_SHOWN = [], DR_SHOWN = [], CH = [], SEL = { ch: {}, dr: {}, pa: {} };
      // Multi-select: a checkbox per row, "select all shown", and an action bar for the selected rows.
      function selList(kind) { return kind === 'ch' ? CH_SHOWN : kind === 'dr' ? DR_SHOWN : r.paused_list; }
      function selBar(kind, actions) { var n = Object.keys(SEL[kind]).length, list = selList(kind), inList = list.filter(function (x) { return SEL[kind][x.jid]; }).length, all = list.length > 0 && inList === list.length;
        return '<div class="sel-bar' + (n ? ' on' : '') + '"><label class="check"><input type="checkbox" data-selall="' + kind + '"' + (all ? ' checked' : '') + (inList && !all ? ' data-half="1"' : '') + '> ' + T('Select all shown') + (list.length ? ' (' + fmt(list.length) + ')' : '') + '</label><b>' + (n ? fmt(n) + ' ' + T('selected') : '') + '</b>'
          + actions.map(function (a) { return '<button class="chip-btn sm" data-bulk="' + kind + ':' + a[0] + '"' + (n ? '' : ' disabled') + '>' + a[1] + '</button>'; }).join('') + (n ? '<button class="link-btn" data-selclear="' + kind + '">' + T('Clear') + '</button>' : '') + '</div>'; }
      var selCell = function (kind, id) { return '<td class="sel-c"><input type="checkbox" data-sel="' + kind + '" value="' + esc(id) + '"' + (SEL[kind][id] ? ' checked' : '') + '></td>'; };
      function chShown() { var q = (document.getElementById('bp-cq').value || '').toLowerCase(), f = document.getElementById('bp-cf').value, s = document.getElementById('bp-cs').value, ty = document.getElementById('bp-ct').value;
        var rows = CH.filter(function (x) { return (!ty || (ty === 'g') === !!x.group) && (!f || (f === 'on' ? x.on : f === 'off' ? !x.on : x.last_dir === 'in')) && (!q || (x.name + ' ' + x.phone).toLowerCase().indexOf(q) !== -1); });
        return rows.sort(function (a, b) { return s === 'count' ? b.count - a.count : s === 'name' ? String(a.name || '~').localeCompare(String(b.name || '~')) : String(b.last).localeCompare(String(a.last)); }); }
      function chDraw() { var rows = chShown(), P = pg('bpch', rows, chDraw, 25);
        document.getElementById('bp-cn').textContent = '(' + fmt(CH.filter(function (x) { return x.on; }).length) + ' / ' + fmt(CH.length) + ')';
        CH_SHOWN = rows;
        document.getElementById('bp-cl').innerHTML = selBar('ch', [['on', '🟢 ' + T('Bot on')], ['off', '⏸ ' + T('Bot off')]]) + '<div class="muted small">' + fmt(rows.length) + ' ' + T('chats') + '</div>' + (P.rows.length ? '<table class="tbl"><thead><tr><th></th><th>' + T('Bot') + '</th><th>' + T('Chat') + '</th><th>' + T('Last message') + '</th><th>' + T('Messages') + '</th><th></th></tr></thead><tbody>' + P.rows.map(function (x) {
          return '<tr class="' + (x.on ? '' : 'muted') + (SEL.ch[x.jid] ? ' picked' : '') + '">' + selCell('ch', x.jid) + '<td><label class="inv-sw"><input type="checkbox" data-chat-on="' + esc(x.jid) + '" ' + (x.on ? 'checked' : '') + '><i></i></label></td><td><b dir="auto">' + (x.group ? '👥 ' : '👤 ') + esc(x.name || T('No name')) + '</b>' + (x.group ? ' <span class="tag">' + T('Group') + '</span>' : '') + ((x.jids || []).length > 1 ? ' <span class="tag" title="' + T('merged chats with the same number') + '">🔗 ' + x.jids.length + '</span>' : '') + (x.team ? ' <span class="tag">' + T('team') + '</span>' : '') + (x.test ? ' <span class="tag">🧪</span>' : '') + '<div class="muted small" dir="ltr">' + (x.phone ? '+' + esc(x.phone) : T('Number hidden by WhatsApp')) + '</div></td>'
            + '<td class="small"><span dir="auto">' + (x.last_dir === 'in' ? '⬅️ ' : '➡️ ') + esc(x.last_text) + '</span><div class="muted">' + esc(when(x.last)) + (x.paused_by ? ' · ⏸ ' + esc(T(x.paused_by)) : '') + '</div></td><td>' + fmt(x.count) + '</td><td><a class="chip-btn sm" href="?p=inbox#' + encodeURIComponent(x.jid) + '">💬</a></td></tr>'; }).join('') + '</tbody></table>' + P.html : '<div class="empty">' + T('No chats.') + '</div>'); }
      function chLoad() { get('bot_chats').then(function (x) { if (!x.ok) return fail(document.getElementById('bp-cl'), x); CH = x.chats; chDraw(); }); }
      // a person may have several WhatsApp IDs for one number: the switch applies to all of them
      function chSet(jids, on) { var all = [], groups = []; CH.forEach(function (x) { if (jids.indexOf(x.jid) === -1) return; if (x.group) groups.push(x); else all = all.concat(x.jids || [x.jid]); });
        // groups: switch the bot on/off in the group (Groups settings), one after another
        var gp = groups.reduce(function (p, g) { return p.then(function () { return post('bot_group_set', { jid: g.jid, on: on ? 1 : 0, name: g.name }).then(function (x) { if (!x || !x.ok) throw new Error((x && x.error) || T('Failed')); g.on = on; }); }); }, Promise.resolve());
        if (!all.length) return gp.then(function () { chDraw(); });
        // ONE request for all of them (parallel requests hit the hosting limit and overwrite each other)
        return gp.then(function () { return post('bot_pause', { jids: all, pause: on ? 0 : 1 }); }).then(function (x) { if (!x || !x.ok) throw new Error((x && x.error) || T('Failed'));
          CH.forEach(function (y) { if (jids.indexOf(y.jid) !== -1) { y.on = on; y.paused_by = on ? '' : 'dashboard'; } }); chDraw(); }); }
      ['bp-cq'].forEach(function (id) { document.getElementById(id).oninput = function () { pgReset('bpch'); chDraw(); }; });
      ['bp-cf', 'bp-cs', 'bp-ct'].forEach(function (id) { document.getElementById(id).onchange = function () { pgReset('bpch'); chDraw(); }; });
      var redraw = { ch: function () { chDraw(); }, dr: function () { drDraw(); }, pa: function () { paDraw(); } };
      new MutationObserver(function () { [].forEach.call(el.querySelectorAll('[data-half]'), function (i) { i.indeterminate = true; }); }).observe(el, { childList: true, subtree: true });
      el.addEventListener('change', function (e) { var k;
        if ((k = e.target.closest('[data-sel]'))) { var kd = k.dataset.sel; if (k.checked) SEL[kd][k.value] = 1; else delete SEL[kd][k.value]; redraw[kd](); return; }
        if ((k = e.target.closest('[data-selall]'))) { var kd2 = k.dataset.selall, on = k.checked;
          selList(kd2).forEach(function (x) { if (on) SEL[kd2][x.jid] = 1; else delete SEL[kd2][x.jid]; }); redraw[kd2](); return; } });
      el.addEventListener('click', function (e) { var b;
        if ((b = e.target.closest('[data-selclear]'))) { SEL[b.dataset.selclear] = {}; redraw[b.dataset.selclear](); return; }
        if (!(b = e.target.closest('[data-bulk]'))) return;
        var parts = b.dataset.bulk.split(':'), kind = parts[0], act = parts[1], ids = Object.keys(SEL[kind]); if (!ids.length) return;
        if (!confirm(b.textContent.trim() + ': ' + ids.length + ' ' + T('chats?'))) return;
        b.disabled = true;
        var failed = function (err) { b.disabled = false; toast('⚠️ ' + (err && err.message || T('Failed'))); redraw[kind](); };
        var okd = function (x) { if (!x || !x.ok) throw new Error((x && x.error) || T('Failed')); return x; };
        if (kind === 'ch') return chSet(ids, act === 'on').then(function () { SEL.ch = {}; chDraw(); toast('✓ ' + ids.length); }).catch(failed);
        if (kind === 'dr') return post('bot_draft', { jids: ids }).then(okd).then(function () { r.drafts_list = r.drafts_list.filter(function (x) { return !SEL.dr[x.jid]; }); SEL.dr = {}; drDraw(); toast('✓ ' + ids.length); }).catch(failed);
        if (kind === 'pa') return post('bot_pause', { jids: ids, pause: 0 }).then(okd).then(function () { r.paused_list = r.paused_list.filter(function (x) { return !SEL.pa[x.jid]; }); SEL.pa = {}; paDraw(); CH.forEach(function (x) { if (ids.indexOf(x.jid) !== -1) x.on = true; }); chDraw(); toast('▶ ' + ids.length); }).catch(failed); });
      el.addEventListener('change', function (e) { var c = e.target.closest('[data-chat-on]'); if (!c) return; c.disabled = true; chSet([c.dataset.chatOn], c.checked).catch(function (err) { toast('⚠️ ' + err.message); chDraw(); }).then(function () { toast(c.checked ? '🟢 ' + T('Bot on for this chat') : '⏸ ' + T('Bot off for this chat')); }); });
      el.addEventListener('click', function (e) { var b = e.target.closest('#bp-call-on, #bp-call-off'); if (!b) return; var on = b.id === 'bp-call-on', rows = chShown().filter(function (x) { return x.on !== on; });
        if (!rows.length) return toast(T('Nothing to change'));
        if (confirm((on ? T('Turn the bot ON for') : T('Turn the bot OFF for')) + ' ' + rows.length + ' ' + T('chats?'))) chSet(rows.map(function (x) { return x.jid; }), on).then(function () { toast('✓ ' + rows.length); }).catch(function (err) { toast('⚠️ ' + err.message); chDraw(); }); });
      chLoad();
      el.insertAdjacentHTML('beforeend', '<details class="card fold" data-key="drafts"><summary><b>📝 ' + T('Drafts waiting') + '</b> <span class="muted small">(' + fmt(r.drafts_list.length) + ')</span></summary><div class="row wrap"><div class="search-xl"><span>🔎</span><input type="search" id="bp-dq" placeholder="' + T('Search drafts…') + '" dir="auto"></div></div><div id="bp-dr"></div></details>'
        + '<details class="card fold" data-key="paused"><summary><b>⏸ ' + T('Paused chats') + '</b> <span class="muted small">(' + fmt(r.paused_list.length) + ')</span></summary><div id="bp-pa"></div></details>');
      function drDraw() { var q = (document.getElementById('bp-dq').value || '').toLowerCase(), rows = r.drafts_list.filter(function (x) { return !q || JSON.stringify([x.q, x.reply, x.name]).toLowerCase().indexOf(q) !== -1; }), P = pg('bpdr', rows, drDraw, 15); DR_SHOWN = rows;
        document.getElementById('bp-dr').innerHTML = selBar('dr', [['drop', '✕ ' + T('Dismiss drafts')]]) + (P.rows.length ? '<table class="tbl"><thead><tr><th></th><th>' + T('When') + '</th><th>' + T('Chat') + '</th><th>' + T('Customer') + '</th><th>' + T('Draft') + '</th><th>' + T('Why not sent') + '</th><th></th></tr></thead><tbody>' + P.rows.map(function (x) {
          return '<tr' + (SEL.dr[x.jid] ? ' class="picked"' : '') + '>' + selCell('dr', x.jid) + '<td class="small">' + esc(when(x.at)) + '</td><td dir="auto">' + (x.group ? '👥 ' : '💬 ') + esc(x.name || x.jid) + '</td><td dir="auto" class="small">' + esc(String(x.q).slice(0, 160)) + '</td><td dir="auto" class="small">' + esc(String(x.reply).slice(0, 220)) + '</td><td class="small muted">' + esc(T(x.why || '')) + '</td><td><a class="chip-btn sm" href="?p=inbox#' + encodeURIComponent(x.jid) + '">💬 ' + T('Open chat') + '</a> <button class="link-btn bad-t" data-drop="' + esc(x.jid) + '">✕</button></td></tr>'; }).join('') + '</tbody></table>' + P.html : '<div class="empty">' + T('No drafts waiting.') + '</div>'); }
      function paDraw() { var now = Date.now();
        document.getElementById('bp-pa').innerHTML = selBar('pa', [['resume', '▶ ' + T('Resume the bot')]]) + (r.paused_list.length ? '<table class="tbl"><thead><tr><th></th><th>' + T('Chat') + '</th><th>' + T('Since') + '</th><th>' + T('By') + '</th><th>' + T('Until') + '</th><th></th></tr></thead><tbody>' + r.paused_list.map(function (x) { var over = x.until && Date.parse(x.until) < now;
          return '<tr class="' + (over ? 'muted' : '') + (SEL.pa[x.jid] ? ' picked' : '') + '">' + selCell('pa', x.jid) + '<td><b dir="auto">' + (x.group ? '👥 ' : '👤 ') + esc(x.name || T('No name')) + '</b><div class="muted small" dir="ltr">' + (x.group ? T('Group') : (x.phone ? '+' + esc(x.phone) : T('Number hidden by WhatsApp'))) + '</div></td><td class="small">' + esc(when(x.at)) + '</td><td class="small">' + esc(T(x.by)) + '</td><td class="small">' + (x.until ? esc(when(x.until)) + (over ? ' · ' + T('ended') : '') : T('until /start')) + '</td><td><a class="chip-btn sm" href="?p=inbox#' + encodeURIComponent(x.jid) + '">💬</a> <button class="chip-btn sm" data-resume="' + esc(x.jid) + '">▶ ' + T('Resume the bot') + '</button></td></tr>'; }).join('') + '</tbody></table>' : '<div class="empty">' + T('The bot is not paused in any chat.') + '</div>'); }
      document.getElementById('bp-dq').oninput = function () { pgReset('bpdr'); drDraw(); }; drDraw(); paDraw();
      el.addEventListener('click', function (e) { var b;
        if ((b = e.target.closest('[data-drop]'))) post('bot_draft', { jid: b.dataset.drop }).then(function () { r.drafts_list = r.drafts_list.filter(function (x) { return x.jid !== b.dataset.drop; }); drDraw(); });
        if ((b = e.target.closest('[data-resume]'))) post('bot_pause', { jid: b.dataset.resume, pause: 0 }).then(function () { r.paused_list = r.paused_list.filter(function (x) { return x.jid !== b.dataset.resume; }); paDraw(); toast('▶ ' + T('Resumed')); }); });
      // Waiting chats: the customer wrote, the bot has not answered — with the reason and "Answer now".
      var wt = document.createElement('details'); wt.className = 'fold'; wt.dataset.key = 'waiting';
      wt.innerHTML = '<summary>⏳ ' + T('Waiting for a reply') + ' <span class="muted small" id="bh-n">(…)</span></summary><div id="bh-list"><div class="empty">' + T('Loading…') + '</div></div>';
      el.appendChild(wt);
      function bhLoad() { get('bot_health').then(function (h) { if (!h || !h.ok) return;
        var st = { ok: ['🟢', T('Working')], off: ['⚪', T('Switched off')], disconnected: ['🔴', T('Number not connected')], 'not running': ['🟠', T('Not running — no bot run in the last 3 minutes')], 'hourly limit': ['🟠', T('Hourly limit reached')] }[h.state] || ['⚪', h.state];
        var card = document.getElementById('bh-card'); if (!card) { card = document.createElement('div'); card.id = 'bh-card'; card.className = 'card bh-card'; el.insertBefore(card, el.firstChild); }
        card.innerHTML = '<div class="row wrap"><b class="bh-state">' + st[0] + ' ' + st[1] + '</b><span class="muted small">' + T('Mode') + ': ' + esc(T(h.mode === 'auto' ? 'Automatic' : h.mode === 'draft' ? 'Suggest only' : 'Off')) + '</span>'
          + '<span class="muted small">' + T('Replies this hour') + ': <b>' + fmt(h.sent_hour) + '</b> / ' + fmt(h.per_hour) + '</span><span class="muted small">' + T('Last run') + ': ' + (h.last_tick ? esc(when(h.last_tick)) : '—') + '</span><span class="muted small">' + T('Last reply') + ': ' + (h.last_sent ? esc(when(h.last_sent)) : '—') + '</span>'
          + (h.waiting.length ? '<a href="#" class="bh-go small">⏳ ' + fmt(h.waiting.length) + ' ' + T('chats waiting for a reply') + ' →</a>' : '<span class="small">✅ ' + T('No chat is waiting') + '</span>') + '</div>';
        var n = document.getElementById('bh-n'); if (n) n.textContent = '(' + h.waiting.length + ')'; var tb = document.querySelector('.bt-tabs [data-tab="waiting"] .bt-n'); if (tb) tb.textContent = h.waiting.length;
        document.getElementById('bh-list').innerHTML = h.waiting.length ? '<table class="tbl"><thead><tr><th>' + T('Chat') + '</th><th>' + T('Last message') + '</th><th>' + T('Why the bot did not answer') + '</th><th></th></tr></thead><tbody>' + h.waiting.map(function (w) {
            return '<tr><td><b dir="auto">' + esc(w.name || ('+' + w.phone)) + '</b><div class="muted small" dir="ltr">' + esc(w.phone ? '+' + w.phone : w.jid) + '</div></td><td><div dir="auto">' + esc(w.text) + '</div><div class="muted small">' + esc(when(w.at)) + '</div></td>'
              + '<td class="small">' + esc(T(w.why)) + (w.paused_by ? '<div class="muted">' + esc(T(w.paused_by === 'handoff' ? 'handed over to the team' : w.paused_by)) + (w.paused_until ? ' · ' + T('until') + ' ' + esc(when(w.paused_until)) : ' · ' + T('until someone turns it back on')) + '</div>' : '') + '</td><td><button class="btn primary sm" data-bh-retry="' + esc(w.jid) + '">↻ ' + T('Answer now') + '</button> <a class="link-btn" href="?p=inbox#' + encodeURIComponent(w.jid) + '">💬</a></td></tr>'; }).join('') + '</tbody></table>'
          : '<div class="empty">✅ ' + T('No chat is waiting') + '</div>'; }); }
      bhLoad(); setInterval(function () { if (!document.hidden) bhLoad(); }, 30000);
      el.addEventListener('click', function (e) { var b;
        if (e.target.closest('.bh-go')) { e.preventDefault(); var t = document.querySelector('.bt-tabs [data-tab="waiting"]'); if (t) t.click(); return; }
        if ((b = e.target.closest('[data-bh-retry]'))) { b.disabled = true; b.textContent = '⏳ ' + T('Answering…');
          post('bot_retry', { jid: b.dataset.bhRetry }).then(function (x) { toast(x.ok ? (x.result === 'sent' ? '✅ ' + T('Answered') : 'ℹ️ ' + T(x.result || '') + (x.why ? ' — ' + T(x.why) : '') + (x.note ? ' — ' + T(x.note) : '')) : '⚠️ ' + x.error); bhLoad(); }); } });
      // Turn the sections into tabs (remembered in the address: ?p=botrun&tab=…)
      var keys = ['settings', 'groups', 'join', 'team', 'history', 'chats', 'drafts', 'paused', 'waiting'];
      var folds = [].slice.call(el.querySelectorAll(':scope > details.fold'));
      folds.forEach(function (d, i) { d.dataset.key = d.dataset.key || keys[i] || ('t' + i); d.open = true; d.classList.add('bt-pane'); });
      var want = new URLSearchParams(location.search).get('tab') || 'settings';
      var bar = document.createElement('div'); bar.className = 'page-tabs sub bt-tabs';
      bar.innerHTML = folds.map(function (d) { var sm = d.querySelector('summary'); return '<a href="#" data-tab="' + d.dataset.key + '">' + sm.innerHTML.replace(/<span class="muted small"[^>]*>\(([^)]*)\)<\/span>/, '<span class="bt-n">$1</span>') + '</a>'; }).join('');
      el.insertBefore(bar, el.firstChild);
      function show(k) { if (!folds.some(function (d) { return d.dataset.key === k; })) k = 'settings';
        folds.forEach(function (d) { d.hidden = d.dataset.key !== k; if (d.dataset.key === k) d.dispatchEvent(new Event('toggle')); });
        [].forEach.call(bar.children, function (a) { a.classList.toggle('on', a.dataset.tab === k); });
        var u = new URL(location.href); u.searchParams.set('tab', k); history.replaceState(null, '', u); }
      bar.addEventListener('click', function (e) { var a = e.target.closest('[data-tab]'); if (!a) return; e.preventDefault(); show(a.dataset.tab); });
      show(want);
    });
    return;
  }
