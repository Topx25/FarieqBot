  if (page === 'inbox') {
    var msgById = {}; // the shown messages by id (forwarding a text)
    var hashP = decodeURIComponent(location.hash.slice(1) || '').split('|'), focusMsg = hashP[1] || ''; // #chat or #chat|message (from the Media page)
    var chats = [], filter = '', open = hashP[0];
    var unread = {}, typing = {}, labelFilter = '';
    var meta = { me: '', replies: [], labels: [], chat_labels: {}, assign: {}, reminders: [], viewing: {}, users: [] };
    var CAT = { inquiry: '❓ Inquiry', complaint: '⚠️ Complaint', payment: '💳 Payment', new_order: '🛒 New order', follow_up: '🔁 Follow-up', support: '🛠️ Support', social: '💬 Social', other: '📁 Other' }; Object.assign(CAT, (window.WI_PACK || {}).summary_labels || {}); // extra categories from the business profile
    function labelById(id) { return meta.labels.filter(function (l) { return l.id === id; })[0]; }
    function userName(email) { var u = meta.users.filter(function (x) { return x.email === email; })[0]; return u ? u.name : email; }
    function remindersFor(jid) { return meta.reminders.filter(function (r) { return r.jid === jid; }); }
    function rowMeta(jid) {
      var h = '', ls = (meta.chat_labels[jid] || []).map(labelById).filter(Boolean), a = meta.assign[jid], rm = remindersFor(jid), v = meta.viewing[jid];
      ls.forEach(function (l) { h += '<span class="lbl" style="--c:' + esc(l.color) + '">' + esc(l.name) + '</span>'; });
      if (a) h += '<span class="asg" title="' + T('Assigned to') + ' ' + esc(userName(a.to)) + '">👤 ' + esc(a.to === meta.me ? T('Me') : userName(a.to).split(/[ @]/)[0]) + '</span>';
      if (rm.length) { var due = rm.some(function (r) { return Date.parse(r.at) <= Date.now(); }); h += '<span class="rmd' + (due ? ' due' : '') + '" title="' + esc(rm[0].note || '') + '">⏰ ' + esc(when(rm[0].at)) + '</span>'; }
      if (v && v.length) h += '<span class="viewing">👀 ' + esc(userName(v[0]).split(/[ @]/)[0]) + '</span>';
      var sm = meta.summaries && meta.summaries[jid];
      if (sm) h += '<span class="cat cat-' + esc(sm.category) + '">' + esc(CAT[sm.category] ? T(CAT[sm.category]) : sm.category) + '</span>' + (sm.needs_reply ? '<span class="cat needs">' + T('needs reply') + '</span>' : '');
      return h ? '<div class="c-meta">' + h + '</div>' : '';
    }
    var csrfM = app.getAttribute('data-csrf');
    function setMeta(body) { return WI.post('meta_set', body); }
    // What this user may do inside a conversation (chat permissions from the server; unknown = allowed, the server checks anyway).
    function CAN(k) { return !meta.can || meta.can[k] !== false; }
    function applyCan() { window.WI_CAN = meta.can || null; var comp = document.getElementById('composer'), at = document.getElementById('attach'), qb = document.getElementById('qr-btn');
      if (comp && !CAN('reply')) { comp.classList.add('no-reply'); comp.querySelector('textarea, #reply') && (comp.querySelector('#reply').disabled = true); comp.title = T('You can read this conversation but not reply.'); }
      if (at && !CAN('media')) at.closest('label').hidden = true; if (qb && !CAN('reply')) qb.hidden = true; }
    function loadMeta() {
      return api('meta').then(function (r) {
        if (!r.ok) return;
        meta = r; meta.chat_labels = r.chat_labels || {}; meta.assign = r.assign || {}; meta.viewing = r.viewing || {}; applyCan();
        document.getElementById('label-filter').innerHTML = meta.labels.map(function (l) { return '<button class="' + (labelFilter === l.id ? 'on' : '') + '" data-label="' + esc(l.id) + '" style="--c:' + esc(l.color) + '">● ' + esc(l.name) + '</button>'; }).join('');
        drawDue(); drawList(); if (open) drawTools(open);
      });
    }
    function drawDue() {
      var due = meta.reminders.filter(function (r) { return Date.parse(r.at) <= Date.now(); });
      var box = document.getElementById('due-box');
      box.hidden = !due.length;
      box.innerHTML = due.map(function (r) { return '<div class="due-item"><span>⏰ <b>' + esc(r.name || r.jid.split('@')[0]) + '</b> — ' + esc(r.note || T('Follow up')) + '</span><span class="row"><button class="chip-btn sm" data-open-r="' + esc(r.jid) + '">' + T('Open') + '</button><button class="chip-btn sm" data-done-r="' + esc(r.id) + '">✓ ' + T('Done') + '</button></span></div>'; }).join('');
    }
    try { unread = JSON.parse(localStorage.getItem('wi_unread_' + (app.getAttribute('data-acc') || '')) || '{}') || {}; } catch (e) { unread = {}; }
    function saveUnread() { try { localStorage.setItem('wi_unread_' + (app.getAttribute('data-acc') || ''), JSON.stringify(unread)); } catch (e) {} updateTitle(); }
    function updateTitle() { var n = 0; for (var k in unread) n += unread[k] || 0; document.title = (n ? '(' + n + ') ' : '') + ((window.WI_BRAND || {}).name || ''); }
    document.addEventListener('click', function (e) { if (e.target.closest('[data-hide-chat]') && open) { toggleHidden(open); var b = e.target.closest('[data-hide-chat]'), ic = b.querySelector('span'); (ic || b).textContent = hiddenChats.indexOf(open) !== -1 ? '👁️' : '🙈'; } });
    // 🔒 Who sees this chat: hide it from chosen team members (their lists, search, groups and reports never show it)
    document.addEventListener('click', function (e) {
      var b = e.target.closest('[data-hide-for]'); if (!b || !open) return; var jid = open;
      api('hide_for', { jid: jid }).then(function (r) { // (the name and its parameters are separate: put together they were read as an unknown request)
        if (!r.ok) return alert(r.error || ''); var d = document.createElement('dialog'); d.className = 'dlg';
        d.innerHTML = '<form method="dialog" class="stack pad"><h3>🔒 ' + T('Who sees this chat') + '</h3><p class="muted small">' + T('Ticked people do not see this chat anywhere: list, search, groups, reports.') + '</p>'
          + ((r.users || []).length ? r.users.map(function (u) { return '<label class="check"><input type="checkbox" data-em="' + esc(u.email) + '"' + (u.hidden ? ' checked' : '') + '> ' + T('Hide from') + ' <b dir="auto">' + esc(u.name) + '</b> <small class="muted">' + esc(u.role) + '</small></label>'; }).join('') : '<p class="muted">' + T('No team members on this number.') + '</p>')
          + '<div class="row"><button class="btn primary">' + T('Done') + '</button></div></form>';
        document.body.appendChild(d); d.showModal(); d.addEventListener('close', function () { d.remove(); });
        d.addEventListener('change', function (ev) { var i = ev.target.closest('[data-em]'); if (!i) return; i.disabled = true;
          WI.post('hide_for&jid=' + encodeURIComponent(jid), { email: i.dataset.em, hide: i.checked ? 1 : 0 }).then(function (x) { i.disabled = false; if (!x.ok) { i.checked = !i.checked; alert(x.error || ''); } }); });
      });
    });    // Offline banner: the number is disconnected — chats, files and search keep working, replies wait in the outbox
    function accState() { api('acc_state').then(function (r) { if (!r || !r.ok) return; var b = document.getElementById('off-banner');
      if (!b) { b = document.createElement('div'); b.id = 'off-banner'; b.className = 'off-banner'; var th = document.querySelector('.inbox .thread'); if (th) th.insertBefore(b, th.firstChild); }
      b.hidden = r.connected && !r.waiting; b.innerHTML = !r.connected ? '📴 ' + T('WhatsApp is offline — chats and files stay here; replies wait and go out when the number is back.') + (r.waiting ? ' <b>(' + fmt(r.waiting) + ' ' + T('waiting') + ')</b>' : '') : '⏳ ' + fmt(r.waiting) + ' ' + T('replies are being sent…'); }); }
    accState(); setInterval(accState, 60000);
    var listEl = document.getElementById('chats'), msgsEl = document.getElementById('msgs'), headEl = document.getElementById('thread-h');
    // View options of this browser: full screen, previews, chat list, hidden chats (per number)
    var VK = 'wi_view', HK = 'wi_hidden_' + (app.getAttribute('data-acc') || ''), rd = function (k, d) { try { return JSON.parse(localStorage.getItem(k) || '') || d; } catch (e) { return d; } }, wr = function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} };
    var view = rd(VK, { focus: false, thumbs: true, list: true, showHidden: false }), hiddenChats = rd(HK, []);
    // Full screen hides the top menu: a button that brings it back is always on the screen while it is on (it used to be only in the chat list, which can be hidden too)
    var fx = document.createElement('button'); fx.type = 'button'; fx.id = 'focus-exit'; fx.className = 'focus-exit'; fx.dataset.view = 'focus'; fx.innerHTML = '☰ <span>' + T('Show the menu') + '</span>'; fx.title = T('Leave full screen: show the top menu'); app.appendChild(fx);
    function applyView() { app.classList.toggle('v-focus', !!view.focus); app.classList.toggle('v-nothumbs', !view.thumbs); app.classList.toggle('v-nolist', !view.list);
      [].forEach.call(document.querySelectorAll('#view-bar [data-view]'), function (b) { var k = b.dataset.view; b.classList.toggle('on', k === 'thumbs' ? !view.thumbs : (k === 'list' ? !view.list : (k === 'hidden' ? !!view.showHidden : !!view[k]))); }); }
    document.addEventListener('click', function (e) { var b = e.target.closest('[data-view]'); if (!b) return; var k = b.dataset.view;
      if (k === 'thumbs') view.thumbs = !view.thumbs; else if (k === 'list') view.list = !view.list; else if (k === 'hidden') view.showHidden = !view.showHidden; else view[k] = !view[k];
      wr(VK, view); applyView(); if (k === 'hidden') drawList(); if (k === 'all') loadChats(); });
    function toggleHidden(jid) { var i = hiddenChats.indexOf(jid); if (i === -1) hiddenChats.push(jid); else hiddenChats.splice(i, 1); wr(HK, hiddenChats); drawList(); }
    setTimeout(applyView, 0);
    var chFilter = new URLSearchParams(location.search).get('ch') || '';
    function drawList() {
      var q = (document.getElementById('chat-q').value || '').toLowerCase();
      var html = chats.filter(function (c) {
        if (chFilter === 'fb' && c.channel !== 'fb') return false; // the Facebook menu's Messenger: only the page conversations
        if (filter === 'customers' && !c.customer) return false;
        if (filter === 'others' && (c.customer || c.group)) return false;
        if (filter === 'groups' && !c.group) return false;
        var asg = meta.assign[c.jid];
        if (filter === 'mine' && !(asg && asg.to === meta.me)) return false;
        if (filter === 'unassigned' && (asg || c.group)) return false;
        if (filter === 'remind' && !remindersFor(c.jid).length) return false;
        if (labelFilter && (meta.chat_labels[c.jid] || []).indexOf(labelFilter) === -1) return false;
        if (hiddenChats.indexOf(c.jid) !== -1 && !view.showHidden) return false; // hidden by this user
        return !q || (title(c) + ' ' + (c.phone || '') + ' ' + (c.last ? c.last.text : '')).toLowerCase().indexOf(q) !== -1;
      }).map(function (c) {
        var t = title(c);
        return '<div class="c' + (c.jid === open ? ' on' : '') + (hiddenChats.indexOf(c.jid) !== -1 ? ' c-hid' : '') + '" data-jid="' + esc(c.jid) + '"' + (c.acc ? ' data-acc="' + esc(c.acc) + '" style="border-inline-start:4px solid ' + color(c.acc) + '"' : '') + '>' + av(c.jid, t) + (c.acc ? '<span class="acc-mark" style="background:' + color(c.acc) + '" title="' + esc(c.acc_name || '') + '">' + esc(String(c.acc_name || '').slice(0, 10)) + '</span>' : '') + '<div class="c-b"><div class="c-t"><b>' + esc(t) + (c.customer ? '<span class="tag">' + T('Customer') + '</span>' : '') + (c.group ? '<span class="tag g">' + T('Group') + '</span>' : '') + (c.channel === 'fb' ? '<span class="tag">📘 Messenger</span>' : '') + '</b><small>' + esc(c.last ? when(c.last.time) : '') + '</small></div>' + rowMeta(c.jid) + '<div class="c-row2"><div class="c-p" dir="auto">' + (typing[c.jid] && typing[c.jid] > Date.now() ? '<i class="typing">' + T('typing…') + '</i>' : (c.last && c.last.dir === 'out' ? '✓ ' : '') + (c.last ? (c.last.kind === 'reaction' ? esc(c.last.text) + ' ' + T('reacted') : (c.last.text ? esc(c.last.text) : esc(kindLabel(c.last.kind) || '…'))) : '')) + '</div>' + (c.notes ? '<span class="note-ic" title="' + T('Notes') + '">📝</span>' : '') + (unread[c.jid] ? '<span class="unread">' + unread[c.jid] + '</span>' : '') + '</div></div></div>';
      }).join('');
      listEl.innerHTML = html || '<div class="empty">' + T('No conversations.') + '</div>';
    }
    // Phone: the list and the chat take the whole screen one at a time (like WhatsApp); the phone's back button returns to the list.
    var inboxEl = document.querySelector('.inbox'), isPhone = function () { return window.matchMedia('(max-width: 900px)').matches; };
    function closeChat() { if (inboxEl) inboxEl.classList.remove('has-open'); }
    window.addEventListener('popstate', function () { if (inboxEl && inboxEl.classList.contains('has-open')) closeChat(); });
    // 👥 the members of a group and the card of one member: details, a private chat, mention in the group
    function memberDlg() { var d = document.getElementById('gm-dlg'); if (!d) { d = document.createElement('dialog'); d.id = 'gm-dlg'; d.className = 'dlg gm-dlg'; document.body.appendChild(d); } return d; }
    function memberOpenChat(jid) { var d = memberDlg(); if (d.open) d.close(); openChat(jid); }
    function memberCard(group, phone, name, members) {
      var d = memberDlg(), m = (members || []).filter(function (x) { return x.phone === phone; })[0] || { phone: phone, name: name, jid: phone ? phone + '@s.whatsapp.net' : '' };
      var show = function (r) { var c = r && r.ok ? r : {}, cu = c.customer, cs = c.case;
        d.innerHTML = '<div class="gm-head"><button type="button" class="btn ghost sm" data-gm-back>‹ ' + T('Members') + '</button><button type="button" class="btn ghost sm" data-gm-x>✕</button></div>'
          + '<div class="gm-card">' + av(m.jid || phone, m.name || phone) + '<h3 dir="auto">' + esc(m.name || (phone ? '+' + phone : T('Unknown'))) + (m.admin ? ' <span class="th-badge grp">⭐ ' + T('Group admin') + '</span>' : '') + '</h3>'
          + (phone ? '<div class="muted" dir="ltr">+' + esc(phone) + '</div>' : '<div class="muted small">' + T('This member has a hidden number — a private chat is not possible from here.') + '</div>') + '</div>'
          + '<div class="gm-facts">' + (cu ? '<span class="th-chip">🧾 ' + esc(cu.name) + '</span>' + (cu.due > 0 ? '<span class="th-chip due">💰 ' + T('Due') + ': ' + fmt(cu.due) + '</span>' : '') : '')
          + (cs ? '<a class="th-chip" href="?p=cases&phone=' + encodeURIComponent(phone) + '">🩺 ' + T('Case') + '</a>' : '') + ((c.enrollments || []).filter(function (e) { return e.status === 'active'; }).map(function (e) { return '<span class="th-chip">💊 ' + esc(e.program_name) + '</span>'; }).join(''))
          + (c.stats && c.stats.first ? '<span class="th-chip">📅 ' + T('since') + ' ' + esc(when(c.stats.first, true)) + '</span>' : '') + (c.stats && c.stats.count ? '<span class="th-chip">💬 ' + fmt(c.stats.count) + ' ' + T('messages') + '</span>' : '') + '</div>'
          + '<div class="gm-acts">' + (phone ? '<button type="button" class="btn primary" data-gm-chat="' + esc(m.jid) + '">💬 ' + T(m.chat ? 'Message privately' : 'Start a private chat') + '</button>' : '')
          + (phone ? '<button type="button" class="btn ghost" data-gm-mention="' + esc(phone) + '" data-gm-n="' + esc(m.name || '') + '">@ ' + T('Mention in the group') + '</button><button type="button" class="btn ghost" data-gm-copy="' + esc(phone) + '">📋 ' + T('Copy the number') + '</button><a class="btn ghost" href="https://wa.me/' + esc(phone) + '" target="_blank" rel="noopener">↗ WhatsApp</a>' : '')
          + (phone ? '<a class="btn ghost" href="?p=customer&jid=' + encodeURIComponent(m.jid) + '">👤 ' + T('Profile') + '</a>' : '') + '</div>';
        if (!d.open) d.showModal(); };
      show(null); if (phone) api('customer', { jid: m.jid }).then(show);
      d.onclick = function (e) { if (e.target.closest('[data-gm-x]')) { d.close(); return; } if (e.target.closest('[data-gm-back]')) { memberList(group); return; }
        var c = e.target.closest('[data-gm-chat]'); if (c) { memberOpenChat(c.dataset.gmChat); return; }
        var mn = e.target.closest('[data-gm-mention]'); if (mn) { d.close(); var rep = document.getElementById('reply'); if (rep) { rep.value += (rep.value && !/\s$/.test(rep.value) ? ' ' : '') + '@' + mn.dataset.gmMention + ' '; rep.focus(); } return; }
        var cp = e.target.closest('[data-gm-copy]'); if (cp) { try { navigator.clipboard.writeText('+' + cp.dataset.gmCopy); } catch (x) {} cp.textContent = '✓ ' + T('Copied'); } };
    }
    function memberList(group) {
      var d = memberDlg(); d.innerHTML = '<div class="gm-head"><b>👥 ' + T('Members') + '</b><button type="button" class="btn ghost sm" data-gm-x>✕</button></div><div class="gm-body"><div class="empty">' + T('Loading…') + '</div></div>'; if (!d.open) d.showModal();
      api('group_members', { group: group }).then(function (r) {
        var body = d.querySelector('.gm-body'); if (!r.ok) { body.innerHTML = '<div class="empty">' + esc(r.error || T('Failed')) + '</div>'; return; }
        var all = r.members || [];
        var draw = function () { var q = (document.getElementById('gm-q').value || '').toLowerCase();
          document.getElementById('gm-l').innerHTML = all.filter(function (x) { return !q || ((x.name || '') + ' ' + x.phone).toLowerCase().indexOf(q) !== -1; }).map(function (x, i) {
            return '<button type="button" class="gm-row" data-gm-open="' + esc(x.phone) + '" data-i="' + i + '">' + av(x.jid || x.phone, x.name || x.phone) + '<span class="grow"><b dir="auto">' + esc(x.name || (x.phone ? '+' + x.phone : T('Unknown'))) + '</b><small class="muted" dir="ltr">' + (x.phone ? '+' + esc(x.phone) : T('hidden number')) + '</small></span>'
              + (x.admin ? '<span class="th-badge grp">⭐ ' + T('Admin') + '</span>' : '') + (x.customer ? '<span class="th-badge cust">🧾</span>' : '') + (x.chat ? '<span class="th-badge">💬</span>' : '') + '</button>'; }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>'; };
        body.innerHTML = '<div class="search-xl"><span>🔎</span><input type="search" id="gm-q" placeholder="' + esc(T('Search name or number…')) + '" dir="auto"></div><div class="muted small gm-count">' + fmt(all.length) + ' ' + T('members') + ' · ⭐ ' + fmt(all.filter(function (x) { return x.admin; }).length) + ' ' + T('admins') + '</div><div class="gm-list" id="gm-l"></div>';
        draw(); document.getElementById('gm-q').oninput = draw;
        d.onclick = function (e) { if (e.target.closest('[data-gm-x]')) { d.close(); return; } var o = e.target.closest('[data-gm-open]'); if (o) memberCard(group, o.dataset.gmOpen, '', all); };
      });
    }
    document.addEventListener('click', function (e) {
      var g = e.target.closest('#grp-members'); if (g && open) { memberList(open); return; }
      var w = e.target.closest('.who[data-member]'); if (w && open) { var ph = String(w.dataset.member).replace(/\D/g, ''); memberCard(open, ph, w.dataset.mname || '', null); } });
    function openChat(jid) {
      if (inboxEl) { if (isPhone() && !inboxEl.classList.contains('has-open')) history.pushState({ chat: 1 }, '', '#' + encodeURIComponent(jid)); inboxEl.classList.add('has-open'); }
      open = jid; history.replaceState(history.state, '', '#' + encodeURIComponent(jid)); drawList();
      var ip = document.getElementById('info-panel'); if (ip && !ip.hidden) ip.hidden = true;
      if (unread[jid]) { delete unread[jid]; saveUnread(); }
      var sbx = document.getElementById('sum-box'); if (sbx) sbx.hidden = true;
      api('presence', { jid: jid });
      setTimeout(function () { drawTools(jid); setMeta({ action: 'viewing', jid: jid }); }, 0);
      var c = chats.filter(function (x) { return x.jid === jid; })[0] || { jid: jid };
      var isG = /@g\.us$/.test(jid);
      headEl.innerHTML = '<div class="th-top"><button type="button" class="icon-btn th-back" title="' + T('Back to the chats') + '">‹</button>' + av(jid, title(c))
        + '<div class="th-id grow"><div class="th-name"><b dir="auto">' + esc(title(c)) + '</b>' + (c.customer ? '<span class="th-badge cust">🧾 ' + T('Customer') + '</span>' : '') + (isG ? '<span class="th-badge grp">👥 ' + T('Group') + '</span>' : '') + '<span id="th-badges"></span></div>'
        + '<div class="th-sub"><span dir="ltr">' + (c.phone ? '+' + esc(c.phone) : (isG ? '' : esc(String(jid).split('@')[0]))) + '</span><span>💬 ' + fmt(c.count) + ' ' + T('messages') + '</span>' + (c.last && c.last.time ? '<span>🕐 ' + esc(when(c.last.time)) + '</span>' : '') + '<span class="info-hint">ⓘ ' + T('Contact info') + '</span><span class="typing" id="typing-h" hidden></span></div></div>'
        + '<button type="button" class="icon-btn lbl th-list" data-view="list" title="' + T('Show / hide the chat list') + '"><span>📋</span><em>' + T('Chat list') + '</em></button>'
        + '<button type="button" class="icon-btn th-fold" id="th-fold" title="' + T('Show / hide the header buttons') + '">⌃</button>'
        + '<div class="th-acts"><button type="button" class="icon-btn lbl" data-hide-chat title="' + T('Hide / show this chat in the list') + '"><span>' + (hiddenChats.indexOf(jid) !== -1 ? '👁️' : '🙈') + '</span><em>' + T('Hide chat') + '</em></button>' + (app.getAttribute('data-canusers') ? '<button type="button" class="icon-btn lbl" data-hide-for title="' + T('Who sees this chat') + '"><span>🔒</span><em>' + T('Who sees it') + '</em></button>' : '') + (isG ? '<button type="button" class="icon-btn lbl primary-i" id="grp-members" title="' + T('Members of this group') + '"><span>👥</span><em>' + T('Members') + '</em></button><a class="icon-btn lbl" href="?p=groups&g=' + encodeURIComponent(jid) + '" title="' + T('Group page') + '"><span>⚙️</span><em>' + T('Group settings') + '</em></a>' : '<a class="icon-btn lbl" href="?p=customer&jid=' + encodeURIComponent(jid) + '" title="' + T('Customer profile') + '"><span>👤</span><em>' + T('Profile') + '</em></a><a class="icon-btn lbl" href="?p=programs&jid=' + encodeURIComponent(jid) + '" title="' + T('Enrol in a program') + '"><span>💊</span><em>' + T('Program') + '</em></a>')
        + (!isG && CAN('groups') ? '<button type="button" class="icon-btn lbl" id="add-grp" title="' + T('Add to a group') + '"><span>👥➕</span><em>' + T('Add to group') + '</em></button>' : '')
        + '<button type="button" class="icon-btn lbl" id="chat-media" title="' + T('Media of this chat') + '"><span>📁</span><em>' + T('Media') + '</em></button>'
        + (CAN('delete_chat') ? '<button type="button" class="icon-btn lbl" id="del-chat" title="' + T('Delete this chat') + '"><span>🧹</span><em>' + T('Delete chat') + '</em></button>' : '')
        + (!CAN('export') ? '' : '<div class="export"><button type="button" class="icon-btn lbl" id="exp-btn" title="' + T('Export') + '"><span>⬇️</span><em>' + T('Export') + '</em></button><div class="export-menu" hidden><a href="?api=export&fmt=txt&jid=' + encodeURIComponent(jid) + '">📄 ' + T('Text (.txt)') + '</a><a href="?api=export&fmt=csv&jid=' + encodeURIComponent(jid) + '">📊 ' + T('Excel (.csv)') + '</a><a href="?api=export&fmt=html&jid=' + encodeURIComponent(jid) + '">🌐 ' + T('Web page (.html)') + '</a></div></div>') + '</div></div>'
        + '<div class="th-info" id="th-info"></div>'
        + '<div class="thread-tools" id="thread-tools"></div>';
      var fold = false; try { fold = localStorage.getItem('wi_th_fold') === '1'; } catch (e) {} headEl.classList.toggle('collapsed', fold); var fb = document.getElementById('th-fold'); if (fb) fb.textContent = fold ? '⌄' : '⌃';
      // Customer / case details for the header (filled in when they arrive).
      if (!isG) api('customer', { jid: jid }).then(function (r) {
        if (jid !== open || !r || !r.ok) return;
        var chips = [], b = [];
        if (r.customer) { chips.push('<span class="th-chip">🧾 ' + esc(r.customer.name) + '</span>'); if (r.customer.due > 0) chips.push('<span class="th-chip due">💰 ' + T('Due') + ': ' + fmt(r.customer.due) + '</span>'); chips.push('<span class="th-chip">📄 ' + fmt(r.customer.count) + ' ' + T('invoices') + '</span>'); }
        if (r.case) { b.push('<a class="th-badge case" href="?p=cases&phone=' + encodeURIComponent(r.phone) + '">🩺 ' + T('Case') + '</a>'); var d = r.case.data || {}; if (d.complaint) chips.push('<span class="th-chip" dir="auto">🗣️ ' + esc(String(d.complaint).slice(0, 60)) + '</span>'); (r.case.labels || []).slice(0, 4).forEach(function (l) { chips.push('<span class="lbl">🏷️ ' + esc(l) + '</span>'); }); }
        (r.enrollments || []).filter(function (e) { return e.status === 'active'; }).forEach(function (e) { chips.push('<span class="th-chip">💊 ' + esc(e.program_name) + ' · ' + fmt(Object.keys(e.sent || {}).length) + '</span>'); });
        if (r.summary) chips.push('<span class="th-chip" title="' + esc(r.summary.summary) + '">🧠 ' + esc(r.summary.category) + (r.summary.needs_reply ? ' · ⏳ ' + T('needs a reply') : '') + '</span>');
        if (r.stats && r.stats.first) chips.push('<span class="th-chip">📅 ' + T('since') + ' ' + esc(when(r.stats.first, true)) + '</span>');
        if ((r.scheduled || []).length) chips.push('<span class="th-chip">⏰ ' + fmt(r.scheduled.length) + ' ' + T('scheduled') + '</span>');
        var bb = document.getElementById('th-badges'); if (bb) bb.innerHTML = b.join('');
        var inf = document.getElementById('th-info'); if (inf) inf.innerHTML = chips.join('');
      });
      msgsEl.innerHTML = '<div class="empty">' + T('Loading…') + '</div>';
      shown = PAGE; loadThread(jid, true);
      var comp = document.getElementById('composer'); if (comp) { comp.hidden = false; document.getElementById('safe-note').hidden = false; document.getElementById('reply').focus(); }
    }
    // Add this person to a group the number administers (their number does not have to be saved on the phone).
    function addToGroup(jid) {
      var d = document.getElementById('ag-dlg'); if (!d) { d = document.createElement('dialog'); d.id = 'ag-dlg'; d.className = 'dlg'; document.body.appendChild(d); }
      d.innerHTML = '<div class="pad stack"><h2>👥 ' + T('Add to a group') + '</h2><div id="ag-body" class="muted">' + T('Loading…') + '</div><div class="row"><button type="button" class="btn ghost" data-agx>' + T('Close') + '</button></div></div>'; d.showModal();
      d.querySelector('[data-agx]').onclick = function () { d.close(); };
      var body = d.querySelector('#ag-body');
      api('group_admin_list').then(function (r) {
        if (!r.ok) { body.textContent = r.error || T('Failed'); return; }
        if (!r.groups.length) { body.textContent = T('This number is not an admin of any group. Only a group admin can add people.'); return; }
        body.innerHTML = '<p class="small">' + T('Groups where this number is an admin') + ' · ' + T('left today') + ': <b>' + r.left + '</b></p>' + r.groups.map(function (g) { return '<div class="row" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:6px 0"><span dir="auto">' + esc(g.name || g.jid) + ' <small class="muted">' + g.members + '</small></span><span><button type="button" class="btn primary sm" data-ag="' + esc(g.jid) + '"' + (r.left > 0 ? '' : ' disabled') + '>➕ ' + T('Add') + '</button> <button type="button" class="btn ghost sm" data-agi="' + esc(g.jid) + '">🔗 ' + T('Send invite link') + '</button></span></div>'; }).join('');
      });
      body.onclick = function (e) { var a = e.target.closest('[data-ag]'), i = e.target.closest('[data-agi]'); if (!a && !i) return; (a || i).disabled = true;
        if (i) { api('group_removed', { group: i.dataset.agi, jid: jid }).then(function (rm) { if (rm.removed) { alert(T('This person was removed from this group earlier, so WhatsApp refuses the public link. The add / personal invitation is used instead.')); i.disabled = false; a = i.parentNode.querySelector('[data-ag]'); a.click(); return; }
          sendLink(); }); return; }
        function sendLink() { if (!confirm(T('Send the group invite link to this person in the chat?'))) { i.disabled = false; return; } WI.post('group_invite_send', { jid: jid, group: i.dataset.agi }).then(function (r) { alert(r.result === 'invited' ? '✓ ' + T('Personal invitation sent') : (r.result === 'added' ? '✓ ' + T('Added to the group') : (r.ok ? '✓ ' + T('Invite link sent') : (r.error || T('Failed'))))); }); }
        if (!a) return;
        WI.post('group_add', { jid: jid, group: a.dataset.ag }).then(function (r) {
          var m = { added: '✓ ' + T('Added to the group'), invited: '✓ ' + T('Their privacy settings do not allow adding them directly, so WhatsApp\'s personal group invitation was sent to them in the chat. They join with one tap (it works even if they were removed before).'), already: T('Already in this group'), privacy: T('Their privacy settings do not allow adding them. Send them the invite link instead.'), left_recently: T('They left this group recently; WhatsApp does not allow adding them again yet. Send the invite link.'), blocked: T('WhatsApp refused (they blocked this number).') }[r.result] || (r.error || T('Failed'));
          alert(m); if (r.result === 'privacy' || r.result === 'left_recently') a.parentNode.querySelector('[data-agi]').classList.add('primary'); });
      };
    }
    function drawTools(jid) {
      var el = document.getElementById('thread-tools'); if (!el) return;
      var ls = meta.chat_labels[jid] || [], a = meta.assign[jid], rm = remindersFor(jid), v = meta.viewing[jid];
      var mine = a && a.to === meta.me;
      el.innerHTML = (!CAN('labels') ? '' : '<div class="tool-menu"><button type="button" class="chip-btn sm" data-tool="labels">🏷️ ' + (ls.length ? ls.map(function (id) { var l = labelById(id); return l ? esc(l.name) : ''; }).join(', ') : T('Labels')) + '</button><div class="menu" hidden>'
        + meta.labels.map(function (l) { return '<label class="check"><input type="checkbox" data-label-t="' + esc(l.id) + '" ' + (ls.indexOf(l.id) !== -1 ? 'checked' : '') + '> <span class="lbl" style="--c:' + esc(l.color) + '">' + esc(l.name) + '</span></label>'; }).join('')
        + '<button type="button" class="link-btn" data-tool="manage-labels">⚙️ ' + T('Manage labels') + '</button></div></div>')
        + (!CAN('assign') ? (!a ? '<button type="button" class="chip-btn sm" data-tool="take">✋ ' + T('Take this chat') + '</button>' : (mine ? '<button type="button" class="chip-btn sm" data-tool="giveback">↩️ ' + T('Give it back') + '</button>' : '<span class="chip-btn sm">👤 ' + esc(userName(a.to)) + '</span>')) : '<select class="asg-sel" data-tool="assign"><option value="">👤 ' + T('Unassigned') + '</option>' + meta.users.map(function (u) { return '<option value="' + esc(u.email) + '" ' + (a && a.to === u.email ? 'selected' : '') + '>👤 ' + esc(u.email === meta.me ? T('Me') + ' (' + u.name + ')' : u.name) + '</option>'; }).join('') + '</select>')
        + (CAN('notes') ? '<button type="button" class="chip-btn sm" data-tool="remind">⏰ ' + (rm.length ? esc(when(rm[0].at)) : T('Remind me')) + '</button>' : '')
        + (CAN('invoices') ? '<button type="button" class="chip-btn sm" data-tool="invoices">🧾 ' + T('Invoices') + '</button>' : '')
        + '<button type="button" class="chip-btn sm" data-tool="summary">🧠 ' + T('Summary') + '</button>'
        + (v && v.length ? '<span class="viewing big">👀 ' + esc(v.map(userName).join(', ')) + ' ' + T('is viewing this chat') + '</span>' : '');
    }
    // Only the newest messages are loaded (PAGE at a time); "older messages" at the top loads more, keeping your place.
    var lastSig = '', PAGE = 300, shown = PAGE, keepPos = false;
    function loadThread(jid, scroll) {
      api('thread', { jid: jid, limit: shown }).then(function (r) {
        if (jid !== open) return;
        var rows = r.rows || [], sig = rows.length + ':' + ((rows[rows.length - 1] || {}).id || '') + ':' + ((rows[rows.length - 1] || {}).status || '');
        if (!scroll && !keepPos && sig === lastSig) return;
        var nearBottom = msgsEl.scrollHeight - msgsEl.scrollTop - msgsEl.clientHeight < 120, oldH = msgsEl.scrollHeight, oldTop = msgsEl.scrollTop;
        lastSig = sig;
        var day = '', html = r.more ? '<div class="th-older-w"><button type="button" class="chip-btn sm th-older">⬆️ ' + T('Older messages') + '</button></div>' : '';
        (r.rows || []).forEach(function (m) {
          var d = when(m.time, true); if (d !== day) { html += '<div class="day">' + esc(d) + '</div>'; day = d; }
          var via = m.dir === 'out' && m.via === 'bot' ? T('Bot') : (m.dir === 'out' && m.via === 'alert' ? T('Alert') : (m.dir === 'out' && m.via === 'inbox' ? '💻 ' + (m.by || T('You')) : (m.group && m.from ? (m.from_name || '+' + m.from) : '')));
          if ((m.kind || 'text') === 'text' && !m.text && !m.attachment) return; // empty system message
          var file = '';
          if (m.attachment && /^inbox_uploads\//.test(m.attachment)) file = '?api=media&f=' + encodeURIComponent(m.attachment.split('/').pop());
          else if (m.attachment && /^inbox_media\//.test(m.attachment)) { var pp = m.attachment.split('/'); file = '?api=media&a=' + encodeURIComponent(pp[1]) + '&f=' + encodeURIComponent(pp.slice(2).join('/')); }
          var media = '';
          if (file && m.kind === 'image') media = '<a href="' + file + '" target="_blank" rel="noopener"><img class="thumb" src="' + file + '" alt="" loading="lazy"></a>';
          else if (file && m.kind === 'sticker') media = '<img class="sticker" src="' + file + '" alt="" loading="lazy">';
          else if (file && (m.kind === 'audio' || m.kind === 'voice')) media = '<audio controls preload="metadata" src="' + file + '"></audio>';
          else if (file && m.kind === 'document' && /\.(mp3|m4a|ogg|opus|wav|aac)$/i.test(file)) media = '<audio controls preload="metadata" src="' + file + '"></audio><div class="doc-note">📄 ' + esc(m.file_name || '') + ' · ' + T('sent as a file') + '</div>';
          else if (file && m.kind === 'video') media = '<video controls preload="metadata" class="thumb" src="' + file + '"></video>';
          else if (file && m.kind === 'document') media = '<a class="doc" href="' + file + '" target="_blank" rel="noopener">📄 ' + esc(m.file_name || T('📄 File')) + '</a>';
          if (m.kind === 'reaction') { html += '<div class="reaction ' + (m.dir === 'out' ? 'out' : 'in') + '">' + esc(m.text || '👍') + ' <small>' + T('reacted') + '</small></div>'; return; }
          var label = kindLabel(m.kind);
          var body = media + (m.text ? (label && m.kind !== 'text' && !media ? '<span class="kind">' + esc(label) + '</span>' : '') + '<div class="t" dir="auto">' + esc(m.text) + '</div>' : (media ? '' : (label ? '<span class="kind">' + esc(label) + '</span>' : '<span class="muted">…</span>')));
          if (m.id) msgById[m.id] = m;
          html += '<div class="b ' + (m.dir === 'out' ? 'out' : 'in') + (m.deleted ? ' del' : '') + '"' + (m.id ? ' data-id="' + esc(m.id) + '"' : '') + (m.attachment && /^(inbox_media\/[a-z0-9_-]+|inbox_uploads)\/[A-Za-z0-9_.-]+$/.test(m.attachment) ? ' data-att="' + esc(m.attachment) + '" data-cap="' + esc(m.text || '') + '"' : '') + '>' + (m.reaction ? '<span class="b-react">' + esc(m.reaction) + '</span>' : '') + (via ? (m.group && m.from && m.dir !== 'out' ? '<span class="via"><button type="button" class="who" data-member="' + esc(m.from) + '" data-mname="' + esc(m.from_name || '') + '" title="' + esc(T('Open this member')) + '">' + esc(via) + '</button></span>' : '<span class="via">' + esc(via) + '</span>') : '') + body + (m.deleted ? '<br><i class="muted">' + T('Deleted') + '</i>' : '') + (m.edits && m.edits.length ? '<span class="ed" dir="auto">✏️ ' + T('edited') + ': ' + esc(m.edits[m.edits.length - 1].text) + '</span>' : '') + (m.revoked_at ? '<span class="rev">🗑️ ' + T('Deleted on WhatsApp — kept here') + '</span>' : '') + '<small>' + esc(new Date(m.time).toLocaleTimeString((AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB'), { hour: '2-digit', minute: '2-digit' })) + (m.dir === 'out' ? ' <span class="tick">' + tick(m.status) + '</span>' : '') + '</small></div>';
        });
        msgsEl.innerHTML = html || '<div class="empty">' + T('No messages.') + '</div>';
        if (keepPos) { msgsEl.scrollTop = msgsEl.scrollHeight - oldH + oldTop; keepPos = false; }
        else if (scroll || nearBottom) msgsEl.scrollTop = msgsEl.scrollHeight;
        if (focusMsg) { var fe = msgsEl.querySelector('.b[data-id="' + focusMsg.replace(/"/g, '') + '"]'); if (fe) { fe.scrollIntoView({ block: 'center' }); fe.classList.add('flash'); setTimeout(function () { fe.classList.remove('flash'); }, 2500); } focusMsg = ''; }
      });
    }
    msgsEl.addEventListener('click', function (e) { var b = e.target.closest('.th-older'); if (!b || !open) return; b.disabled = true; b.textContent = '⏳'; shown += PAGE; keepPos = true; loadThread(open, false); });
    var uploading = false;
    // Live: pull new events (messages, typing, ticks) every 2 s (6 s in a background tab).
    var liveSeq = null, lastLive = 0, beepCtx = null;
    function beep() { try { beepCtx = beepCtx || new (window.AudioContext || window.webkitAudioContext)(); var o = beepCtx.createOscillator(), g = beepCtx.createGain(); o.frequency.value = 880; g.gain.value = 0.06; o.connect(g); g.connect(beepCtx.destination); o.start(); o.stop(beepCtx.currentTime + 0.12); } catch (e) {} }
    function chatOf(jid) { return chats.filter(function (x) { return x.jid === jid; })[0]; }
    function live() {
      if (uploading) return;
      api('events', liveSeq === null ? {} : { since: liveSeq }).then(function (r) {
        if (!r || !r.ok) return;
        if (liveSeq === null || r.reset) { liveSeq = r.seq; if (r.reset && open) loadThread(open, false); return; }
        liveSeq = r.seq;
        var redrawList = false, reloadOpen = false, needChats = false, ding = false;
        (r.events || []).forEach(function (ev) {
          if (ev.type === 'msg') {
            var m = ev.row || {}, jid = m.jid;
            if (!jid) return;
            if (jid === open) reloadOpen = true;
            if (m.dir !== 'in' && m.dir !== 'out') return;
            var c = chatOf(jid);
            if (!c) { needChats = true; } else {
              c.last = { time: m.time, dir: m.dir, text: m.text || '', kind: m.kind || 'text' }; c.count = (c.count || 0) + 1;
              chats.splice(chats.indexOf(c), 1); chats.unshift(c); redrawList = true;
            }
            delete typing[jid];
            if (m.dir === 'in' && (jid !== open || document.hidden)) { unread[jid] = (unread[jid] || 0) + 1; saveUnread(); ding = true; }
          } else if (ev.type === 'hidden') { // a colleague deleted a message or the whole chat
            if (ev.jid === open) { if (ev.chat) location.reload(); else { var hb = msgsEl.querySelector('.b[data-id="' + String(ev.id).replace(/"/g, '') + '"]'); if (hb) hb.remove(); } }
          } else if (ev.type === 'presence') {
            var on = ev.state === 'composing' || ev.state === 'recording';
            if (on) typing[ev.jid] = Date.now() + 7000; else delete typing[ev.jid];
            redrawList = true;
            if (ev.jid === open) setTyping(on ? (ev.state === 'recording' ? T('recording…') : T('typing…')) : '');
          } else if (ev.type === 'status' && ev.jid === open) {
            var el = msgsEl.querySelector('.b[data-id="' + ev.id + '"] .tick'); if (el) el.innerHTML = tick(ev.status);
          }
        });
        if (needChats) api(view.all ? 'chats_all' : 'chats').then(function (x) { if (x.ok) { chats = x.chats || []; drawList(); } });
        else if (redrawList) drawList();
        if (reloadOpen) loadThread(open, false);
        if (ding) beep();
      }).catch(function () {});
    }
    function setTyping(txt) { var t = document.getElementById('typing-h'); if (t) { t.textContent = txt; t.hidden = !txt; } }
    setInterval(function () { var now = Date.now(); if (now - lastLive >= (document.hidden ? 15000 : 4000)) { lastLive = now; live(); } }, 1000);
    setInterval(function () { var ch = false; for (var k in typing) if (typing[k] < Date.now()) { delete typing[k]; ch = true; if (k === open) setTyping(''); } if (ch) drawList(); }, 3000);
    updateTitle();
    setInterval(function () { if (open && !document.hidden && !uploading) loadThread(open, false); }, 30000);
    var infoEl = document.getElementById('info-panel');
    function money(v, cur) { return Number(v || 0).toLocaleString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB') + ' ' + (cur || (window.WI_SYS || {}).currency || ''); }
    function showInfo(jid) {
      infoEl.hidden = false;
      infoEl.innerHTML = '<div class="info-h"><b>' + T('Contact info') + '</b><button type="button" class="link-btn" id="info-x">✕</button></div><div class="empty">' + T('Loading…') + '</div>';
      api('contact', { jid: jid }).then(function (c) {
        if (!c.ok) { infoEl.querySelector('.empty').textContent = c.error || T('Could not load'); return; }
        var name = (c.customer && c.customer.name) || c.name || (c.phone ? '+' + c.phone : T('Unknown'));
        var h = '<div class="info-h"><b>' + T('Contact info') + '</b><button type="button" class="link-btn" id="info-x">✕</button></div><div class="info-body">';
        h += '<div class="info-top">' + (c.photo ? '<a href="?api=photo&jid=' + encodeURIComponent(jid) + '" target="_blank" rel="noopener"><img class="info-photo" src="?api=photo&jid=' + encodeURIComponent(jid) + '" alt=""></a>' : '<div class="info-photo av" style="background:' + color(jid) + '">' + initial(name) + '</div>') + '<h3>' + esc(name) + '</h3>';
        h += '<div class="info-actions"><button type="button" class="chip-btn sm" data-copy="' + esc(name) + '">📋 ' + T('Copy name') + '</button>';
        if (c.phone) h += '<button type="button" class="chip-btn sm" data-copy="+' + esc(c.phone) + '">📋 ' + T('Copy number') + '</button>';
        if (c.photo) h += '<a class="chip-btn sm" href="?api=photo&dl=1&jid=' + encodeURIComponent(jid) + '">⬇️ ' + T('Download photo') + '</a>';
        if (c.phone) h += '<a class="chip-btn sm" target="_blank" rel="noopener" href="https://wa.me/' + esc(c.phone) + '">' + T('Open in WhatsApp') + '</a>';
        h += '</div>';
        if (c.phone) h += '<div class="info-phone" dir="ltr">+' + esc(c.phone) + '</div>';
        else if (!c.group) h += '<div class="muted small">' + T('Number hidden by WhatsApp (privacy). It appears once they message you directly.') + '</div>';
        h += '</div><dl class="kv">';
        function row(k, v) { if (v !== undefined && v !== null && v !== '') h += '<dt>' + k + '</dt><dd>' + v + '</dd>'; }
        if (c.group) { row(T('Members'), fmt(c.participants)); row(T('Admins'), fmt(c.admins)); row(T('Created'), c.created_at ? esc(when(c.created_at, true)) : ''); row(T('Description'), c.description ? esc(c.description) : ''); }
        else {
          row(T('About'), c.about ? esc(c.about) : ''); row(T('WhatsApp names'), (c.push_names || []).map(esc).join('، '));
          if (c.business) { row(T('Business'), esc([c.business.category, c.business.description].filter(Boolean).join(' — '))); row(T('Email'), esc(c.business.email)); row(T('Website'), c.business.website ? '<a href="' + esc(safeUrl(c.business.website)) + '" target="_blank" rel="noopener">' + esc(c.business.website) + '</a>' : ''); row(T('Address'), esc(c.business.address)); }
          row(T('Hidden ID'), c.lid ? '<span dir="ltr">' + esc(c.lid) + '</span>' : '');
        }
        row(T('Messages'), fmt(c.messages) + ' (' + fmt(c.received) + ' ' + T('received') + ' · ' + fmt(c.sent) + ' ' + T('sent') + ')');
        row(T('First message'), c.first_at ? esc(when(c.first_at, true)) : ''); row(T('Last message'), c.last_at ? esc(when(c.last_at, true)) : '');
        h += '</dl>';
        if (c.customer) {
          var cu = c.customer;
          h += '<div class="info-cust"><div class="info-cust-h">⭐ ' + T('Customer in the invoice system') + '</div><dl class="kv">';
          row(T('Name'), esc(cu.person)); row(T('Email'), esc(cu.email)); row(T('Invoices'), fmt(cu.count)); row(T('Total billed'), money(cu.total));
          h += '<dt>' + T('Balance due') + '</dt><dd class="' + (cu.due > 0 ? 'due' : 'paid') + '">' + money(cu.due) + '</dd></dl>';
          if (cu.invoices && cu.invoices.length) h += '<ul class="inv-list">' + cu.invoices.map(function (i) { return '<li><b>' + esc(i.reference) + '</b><span>' + esc(i.date) + '</span><span class="badge ' + (i.status === 'paid' ? 'ok' : 'warn') + '">' + esc(i.status) + '</span><span>' + money(i.total_due > 0 ? i.total_due : i.total, i.currency) + '</span></li>'; }).join('') + '</ul>';
          h += '<a class="chip-btn sm" target="_blank" rel="noopener" href="/index.php/billers/view/' + cu.id + '">' + T('Open customer') + ' ↗</a></div>';
        }
        h += '<div class="notes"><div class="info-cust-h">📝 ' + T('Notes') + '</div><div id="notes-list"></div><textarea id="note-text" rows="2" dir="auto" maxlength="1000" placeholder="' + T('Add a note about this number…') + '"></textarea><button type="button" class="btn primary sm" id="note-add">' + T('Save note') + '</button></div>';
        infoEl.innerHTML = h + '</div>';
        loadNotes(jid);
      });
    }
    var csrfN = app.getAttribute('data-csrf');
    function drawNotes(list) {
      var el = document.getElementById('notes-list'); if (!el) return;
      el.innerHTML = (list || []).map(function (n, i) { return '<div class="note"><div dir="auto">' + esc(n.text) + '</div><small>' + esc(n.by) + ' · ' + esc(when(n.time, true)) + ' <button type="button" class="link-btn" data-note-del="' + i + '">✕</button></small></div>'; }).join('') || '<div class="muted small">' + T('No notes yet.') + '</div>';
      var c = chatOf(open); if (c) { c.notes = (list || []).length; drawList(); }
    }
    function loadNotes(jid) { api('notes', { jid: jid }).then(function (r) { if (r.ok) drawNotes(r.notes); }); }
    function postNote(body) {
      return WI.post('notes&jid=' + encodeURIComponent(open), body).then(function (r) { if (r.ok) drawNotes(r.notes); else alert(r.error || ''); });
    }
    headEl.addEventListener('change', function (e) {
      if (e.target.matches('[data-tool="assign"]')) setMeta({ action: 'assign', jid: open, to: e.target.value }).then(function (r) { if (!r.ok) alert(r.error || ''); loadMeta(); });
      if (e.target.matches('[data-label-t]')) setMeta({ action: 'label_toggle', jid: open, id: e.target.getAttribute('data-label-t') }).then(loadMeta);
    });
    // Media: send from the library (🖼️) and forward a chat's photo / video / file (↪ on the message)
    var mlBtn = document.getElementById('ml-btn'); if (mlBtn) mlBtn.addEventListener('click', function () { if (!open) { alert(T('Choose a conversation first.')); return; } mlPick([open], function () { loadThread(open, true); }); });
    // ↪ on a message (hover on a computer, a tap on the phone): media go with their file, text as text
    var fwBtn = function (bub) { if (!bub || bub.querySelector('.b-fw') || bub.classList.contains('del') || !(bub.dataset.att || (msgById[bub.dataset.id] || {}).text)) return; var x = document.createElement('button'); x.type = 'button'; x.className = 'b-fw'; x.title = T('Forward'); x.textContent = '↪'; bub.appendChild(x); };
    msgsEl.addEventListener('click', function (e) { var f = e.target.closest('.b-fw'), bub = e.target.closest('.b');
      if (!f) { if (bub && !e.target.closest('a,button,audio,video,img')) fwBtn(bub); return; }
      if (bub.dataset.att) mlForward(bub.dataset.att, bub.dataset.cap || ''); else mlForward(null, (msgById[bub.dataset.id] || {}).text || ''); });
    msgsEl.addEventListener('mouseover', function (e) { fwBtn(e.target.closest('.b')); });
    headEl.addEventListener('click', function (e) { var fb = e.target.closest('#th-fold'); if (fb) { var on = headEl.classList.toggle('collapsed'); fb.textContent = on ? '⌄' : '⌃'; try { localStorage.setItem('wi_th_fold', on ? '1' : '0'); } catch (x) {} return; } if (e.target.closest('#chat-media')) { var cc = chats.filter(function (x) { return x.jid === open; })[0] || {}; mlChatMedia(open, title(cc)); return; } });
    headEl.addEventListener('click', function (e) { if (e.target.closest('.th-back')) { if (history.state && history.state.chat) history.back(); else closeChat(); } });
    headEl.addEventListener('click', function (e) {
      var tl = e.target.closest('[data-tool]');
      if (tl && tl.getAttribute('data-tool') === 'labels') { var mn = tl.parentNode.querySelector('.menu'); mn.hidden = !mn.hidden; return; }
      if (tl && tl.getAttribute('data-tool') === 'manage-labels') { openLabels(); return; }
      if (tl && tl.getAttribute('data-tool') === 'remind') { openRemind(); return; }
      if (tl && tl.getAttribute('data-tool') === 'invoices') { openInvoices(); return; }
      if (tl && tl.getAttribute('data-tool') === 'summary') { summarize([open], true); return; }
      if (tl && (tl.getAttribute('data-tool') === 'take' || tl.getAttribute('data-tool') === 'giveback')) { setMeta({ action: 'assign', jid: open, to: tl.getAttribute('data-tool') === 'take' ? meta.me : '' }).then(function (r) { if (!r.ok) alert(r.error || ''); loadMeta(); }); return; }
      if (e.target.closest('#add-grp')) { addToGroup(open); return; }
      if (e.target.id === 'del-chat') { if (!confirm(T('Delete this chat? It is deleted from WhatsApp on this number and from the dashboard. New messages from this person will show again.'))) return;
        WI.post('delete_chat', { jid: open }).then(function (r) { if (!r.ok) { alert(r.error || T('Failed')); return; } alert('🧹 ' + T('Chat deleted') + (r.phone ? '' : ' · ' + T('removed from the dashboard only'))); location.reload(); }); return; }
      if (e.target.closest('.thread-tools')) return;
      if (e.target.id === 'exp-btn') { var m = headEl.querySelector('.export-menu'); m.hidden = !m.hidden; return; }
      if (e.target.closest('.export')) return;
      if (open && e.target.closest('.grow, .av')) { if (infoEl.hidden) showInfo(open); else infoEl.hidden = true; }
    });
    infoEl.addEventListener('click', function (e) {
      if (e.target.id === 'info-x') infoEl.hidden = true;
      var cp = e.target.closest('[data-copy]');
      if (cp) { var v = cp.getAttribute('data-copy'), done = function () { var o = cp.innerHTML; cp.innerHTML = '✓ ' + T('Copied'); setTimeout(function () { cp.innerHTML = o; }, 1500); };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(v).then(done); else { var ta = document.createElement('textarea'); ta.value = v; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); done(); } }
      if (e.target.id === 'note-add') { var tx = document.getElementById('note-text'); if (tx.value.trim()) postNote({ text: tx.value.trim() }).then(function () { tx.value = ''; }); }
      var nd = e.target.closest('[data-note-del]'); if (nd && confirm(T('Delete this note?'))) postNote({ delete: Number(nd.getAttribute('data-note-del')) });
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.export')) { var m = headEl.querySelector('.export-menu'); if (m) m.hidden = true; } });
    var comp = document.getElementById('composer');
    if (comp) {
      var box = document.getElementById('reply'), btn = document.getElementById('send-btn'), csrf = app.getAttribute('data-csrf');
      box.addEventListener('input', function () { box.style.height = 'auto'; box.style.height = Math.min(160, box.scrollHeight) + 'px'; });
      // Quick replies: "/" opens the list, arrows + Enter pick one; variables are filled for this chat.
      var qrPop = document.getElementById('qr-pop'), qrIdx = 0, qrItems = [];
      var nrm = function (t) { return String(t || '').replace(/[\u064B-\u065F\u0670\u0640]/g, '').replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim(); };
      // "/" or "#" (easier on an Arabic or phone keyboard) starts a shortcut; it may have several words ("/القسم السليماني").
      var QR_RE = /(?:^|\s)[\/#]([^\/#\n]{0,40})$/;
      function qrQuery() { var m = QR_RE.exec(box.value.slice(0, box.selectionStart)); return m ? m[1] : null; }
      function qrShow() {
        var q0 = qrQuery(); if (q0 === null) { qrPop.hidden = true; return; }
        var q = nrm(q0);
        // typed the whole name of exactly one shortcut → it turns into its text by itself
        // the typed words ("#keyword-name", "#name") point to exactly one reply — by whole words of its name or of a trigger → it is written by itself
        if (q.length >= 3) { var wq = ' ' + q + ' ', hit = function (r) { return [r.title].concat(r.triggers || []).some(function (w) { return (' ' + nrm(w) + ' ').indexOf(wq) !== -1; }); };
          var ex = meta.replies.filter(function (r) { return nrm(r.title) === q; }), whole = meta.replies.filter(hit);
          if (ex.length === 1 && whole.length === 1) { qrUse(ex[0]); return; }
          if (!ex.length && whole.length === 1 && q.length >= 4) { qrUse(whole[0]); return; } }
        qrItems = meta.replies.filter(function (r) { return !q || nrm(r.title + ' ' + (r.triggers || []).join(' ') + ' ' + r.text).indexOf(q) !== -1; })
          .sort(function (a, b) { return (nrm(b.title).indexOf(q) === 0) - (nrm(a.title).indexOf(q) === 0); }).slice(0, 8);
        if (q0 && /\s$/.test(q0) && !qrItems.length) { qrPop.hidden = true; return; } // a normal "/" in a sentence
        if (qrIdx >= qrItems.length) qrIdx = 0;
        qrPop.innerHTML = (qrItems.length ? qrItems.map(function (r, i) { return '<div class="qr-it' + (i === qrIdx ? ' on' : '') + '" data-qr="' + i + '"><b>/' + esc(r.title) + '</b><span dir="auto">' + esc(r.text.slice(0, 90)) + '</span></div>'; }).join('') : '<div class="qr-it muted">' + T('No quick replies match.') + '</div>') + '<div class="qr-it manage" data-qr-manage>⚙️ ' + T('Manage quick replies') + '</div>';
        qrPop.hidden = false;
      }
      var qrBusy = false;
      function qrUse(r) { if (qrBusy) return; qrBusy = true;
        api('reply_fill', { jid: open, text: r.text }).then(function (x) {
          var before = box.value.slice(0, box.selectionStart).replace(/(?:^|\s)[\/#][^\/#\n]{0,40}$/, function (m0) { return /[\/#]/.test(m0.charAt(0)) ? '' : m0.charAt(0); });
          box.value = before + (x.ok ? x.text : r.text) + box.value.slice(box.selectionStart); qrPop.hidden = true; qrBusy = false; box.focus(); box.dispatchEvent(new Event('input'));
        }, function () { qrBusy = false; });
      }
      box.addEventListener('input', qrShow);
      // Smart suggestions: while typing, quick replies whose trigger words appear in the text are offered as chips above the box.
      var sug = document.createElement('div'); sug.className = 'qr-sug'; sug.hidden = true; document.getElementById('composer').parentNode.insertBefore(sug, document.getElementById('composer'));
      var sugHidden = {};
      function sugShow() { var v = box.value; if (!v.trim() || QR_RE.test(v)) { sug.hidden = true; return; }
        var t = ' ' + nrm(v) + ' ', hits = [], tail = nrm(v.slice(-30)).split(' ').slice(-3);
        meta.replies.forEach(function (r) { if (sugHidden[r.id] || v.indexOf(r.text.slice(0, 40)) !== -1) return; var best = 0;
          (r.triggers || []).concat([r.title]).forEach(function (w) { var n = nrm(w); if (n.length >= 3 && t.indexOf(' ' + n + ' ') !== -1 || (n.length >= 5 && t.indexOf(n) !== -1)) best = Math.max(best, n.length);
            // still typing: the last words are the start of a trigger word ("del" → "delivery times")
            for (var k = 0; k < tail.length; k++) { var p = tail.slice(k).join(' '); if (p.replace(/^ال/, '').length >= 3 && (n.indexOf(p) === 0 || n.indexOf(' ' + p) !== -1 || n.indexOf('ال' + p) === 0)) { best = Math.max(best, p.length - 1); break; } } });
          if (best) hits.push({ r: r, s: best }); });
        hits.sort(function (a, b) { return b.s - a.s; }); hits = hits.slice(0, 4);
        if (!hits.length) { sug.hidden = true; return; }
        sug.innerHTML = '<span class="muted small">💡 ' + T('Suggested replies') + '</span>' + hits.map(function (h) { return '<button type="button" class="chip-btn sm" data-sug="' + esc(h.r.id) + '" title="' + esc(h.r.text.slice(0, 300)) + '">⚡ ' + esc(h.r.title) + '</button>'; }).join('') + '<button type="button" class="link-btn" data-sugx>✕</button>';
        sug.hidden = false; }
      box.addEventListener('input', sugShow);
      sug.addEventListener('mousedown', function (e) { e.preventDefault();
        if (e.target.closest('[data-sugx]')) { meta.replies.forEach(function (r) { sugHidden[r.id] = 1; }); sug.hidden = true; return; }
        var b = e.target.closest('[data-sug]'); if (!b) return; var r = meta.replies.filter(function (x) { return x.id === b.getAttribute('data-sug'); })[0];
        api('reply_fill', { jid: open, text: r.text }).then(function (x) { box.value = box.value.replace(/\s+$/, '') + (box.value.trim() ? '\n' : '') + (x.ok ? x.text : r.text); sugHidden[r.id] = 1; sug.hidden = true; box.focus(); box.dispatchEvent(new Event('input')); }); });
      box.addEventListener('keydown', function (e) {
        if (!qrPop.hidden && qrItems.length) {
          if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); qrIdx = (qrIdx + (e.key === 'ArrowDown' ? 1 : qrItems.length - 1)) % qrItems.length; qrShow(); return; }
          if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); qrUse(qrItems[qrIdx]); return; }
        }
        if (e.key === 'Escape') qrPop.hidden = true;
      });
      qrPop.addEventListener('mousedown', function (e) { e.preventDefault(); var it = e.target.closest('[data-qr]'); if (it) qrUse(qrItems[Number(it.getAttribute('data-qr'))]); if (e.target.closest('[data-qr-manage]')) openReplies(); });
      if (document.getElementById('rec-btn')) document.getElementById('rec-btn').addEventListener('click', function () { if (!open) { alert(T('Choose a conversation first.')); return; } WI.recPanel(open); });
      document.getElementById('qr-btn').addEventListener('click', function () { if (!open) { alert(T('Choose a conversation first.')); return; } box.value = (box.value ? box.value + ' ' : '') + '/'; box.focus(); qrShow(); });
      function drawReplies() {
        var q = (document.getElementById('qr-q').value || '').trim().toLowerCase(), cur = document.getElementById('qr-id').value;
        var list = meta.replies.filter(function (r) { return !q || (r.title + ' ' + r.text + ' ' + (r.triggers || []).join(' ')).toLowerCase().indexOf(q) !== -1; });
        document.getElementById('qr-count').textContent = fmt(meta.replies.length) + ' ' + T('quick replies');
        document.getElementById('qr-list').innerHTML = list.map(function (r) { return '<div class="qr-row' + (r.id === cur ? ' on' : '') + '" data-qr-edit="' + esc(r.id) + '"><div class="qr-row-t"><b dir="auto">/' + esc(r.title) + '</b>' + (r.keyword ? '<span class="qr-kwb" title="' + esc(T('Members\' keyword')) + '">#' + esc(r.keyword) + '</span>' : '') + '<button type="button" class="icon-btn qr-del" data-qr-del="' + esc(r.id) + '" title="' + T('Delete') + '">🗑</button></div>'
          + '<div class="qr-prev muted small" dir="auto">' + esc(r.text) + '</div>' + ((r.triggers || []).length ? '<div class="qr-chips">' + r.triggers.slice(0, 6).map(function (t) { return '<span dir="auto">' + esc(t) + '</span>'; }).join('') + '</div>' : '') + '</div>'; }).join('')
          || '<div class="muted small">' + (q ? T('Nothing found.') : T('No quick replies yet.')) + '</div>';
        document.getElementById('qr-mode').textContent = cur ? T('Edit quick reply') : T('New quick reply');
      }
      document.getElementById('qr-q').addEventListener('input', drawReplies);
      if (document.getElementById('qr-import')) document.getElementById('qr-import').addEventListener('click', function () {
        if (!confirm(T('Copy your quick replies to this client\'s number? Replies with the same name are skipped; the client can edit them afterwards.'))) return;
        setMeta({ action: 'reply_import', jid: open || 'x' }).then(function (x) { if (!x.ok) { alert(x.error || T('Failed')); return; } meta.replies = x.replies; drawReplies(); alert('📥 ' + x.added + ' ' + T('quick replies copied')); }); });
      function openReplies() { qrPop.hidden = true; ['qr-id', 'qr-title', 'qr-text', 'qr-trig', 'qr-kw', 'qr-q'].forEach(function (k) { document.getElementById(k).value = ''; }); document.getElementById('qr-mined').innerHTML = ''; drawReplies(); document.getElementById('qr-dlg').showModal(); }
      document.getElementById('qr-list').addEventListener('click', function (e) {
        var dl = e.target.closest('[data-qr-del]'), ed = dl ? null : e.target.closest('[data-qr-edit]');
        if (ed) { var r = meta.replies.filter(function (x) { return x.id === ed.getAttribute('data-qr-edit'); })[0]; document.getElementById('qr-id').value = r.id; document.getElementById('qr-title').value = r.title; document.getElementById('qr-trig').value = (r.triggers || []).join('، '); document.getElementById('qr-kw').value = r.keyword || ''; document.getElementById('qr-text').value = r.text; drawReplies(); document.getElementById('qr-title').focus(); }
        if (dl && confirm(T('Delete this quick reply?'))) setMeta({ action: 'reply_delete', id: dl.getAttribute('data-qr-del') }).then(function (x) { if (x.ok) { meta.replies = x.replies; drawReplies(); } });
      });
      document.getElementById('qr-new').addEventListener('click', function () { document.getElementById('qr-id').value = ''; document.getElementById('qr-kw').value = ''; document.getElementById('qr-title').value = ''; document.getElementById('qr-text').value = ''; document.getElementById('qr-trig').value = ''; drawReplies(); document.getElementById('qr-title').focus(); });
      // Messages the team keeps sending → one-click quick replies (with suggested trigger words)
      var MINED = [];
      document.getElementById('qr-mine').addEventListener('click', function () { var b = this, out = document.getElementById('qr-mined'); b.disabled = true; out.innerHTML = '<div class="muted small">⏳ ' + T('Looking for messages the team sends again and again…') + '</div>';
        api('qr_mine').then(function (x) { b.disabled = false; if (!x.ok) { out.innerHTML = '⚠️ ' + esc(x.error); return; } MINED = x.items;
          out.innerHTML = x.items.length ? '<div class="qr-mined">' + x.items.map(function (m, i) { return '<div class="qr-row"><div><b>⚡ ' + esc(m.title) + '</b> <span class="tag">🔁 ' + m.count + ' ' + T('times') + '</span><div class="muted small" dir="auto">' + esc(m.text.slice(0, 160)) + '…</div><div class="small">💡 ' + esc((m.triggers || []).join('، ')) + '</div></div><button type="button" class="btn ghost sm" data-mine-add="' + i + '">➕ ' + T('Add') + '</button></div>'; }).join('') + '</div>' : '<div class="muted small">' + T('No long message was sent 3 times or more in the last 3 months.') + '</div>'; }, function () { b.disabled = false; out.innerHTML = '⚠️'; }); });
      document.getElementById('qr-mined').addEventListener('click', function (e) { var b = e.target.closest('[data-mine-add]'); if (!b) return; var m = MINED[+b.getAttribute('data-mine-add')];
        document.getElementById('qr-id').value = ''; document.getElementById('qr-title').value = m.title.slice(0, 60); document.getElementById('qr-text').value = m.text.slice(0, 2000); document.getElementById('qr-trig').value = (m.triggers || []).join('، ');
        drawReplies(); document.getElementById('qr-title').focus(); });
      document.getElementById('qr-save').addEventListener('click', function () {
        setMeta({ action: 'reply_save', id: document.getElementById('qr-id').value, title: document.getElementById('qr-title').value, text: document.getElementById('qr-text').value, triggers: document.getElementById('qr-trig').value, keyword: document.getElementById('qr-kw').value })
          .then(function (x) { if (x.ok) { meta.replies = x.replies; drawReplies(); document.getElementById('qr-id').value = ''; document.getElementById('qr-title').value = ''; document.getElementById('qr-text').value = ''; } else alert(x.error || ''); });
      });
      box.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && qrPop.hidden) { e.preventDefault(); comp.requestSubmit(); } });
      var fileIn = document.getElementById('attach'), prev = document.getElementById('attach-preview'), picked = null;
      fileIn.addEventListener('change', function () {
        picked = fileIn.files[0] || null;
        if (picked && picked.size > 70 * 1048576) { alert(T('File is larger than 70 MB.')); picked = null; fileIn.value = ''; }
        prev.hidden = !picked;
        prev.innerHTML = picked ? '📎 <b>' + esc(picked.name) + '</b> · ' + Math.round(picked.size / 1024) + ' KB <button type="button" class="link-btn" id="attach-x">✕</button><span class="muted small"> ' + T('Text is sent as the caption.') + (picked && picked.size > 16 * 1048576 ? ' ' + T(/^audio\//.test(picked.type) ? 'Large audio is tried as audio first; if WhatsApp refuses, it is sent as a file.' : 'Large photos (over 16 MB) are sent as files.') : '') + '</span>' : '';
        box.focus();
      });
      prev.addEventListener('click', function (e) { if (e.target.id === 'attach-x') { picked = null; fileIn.value = ''; prev.hidden = true; } });
      function pick(file) {
        if (!file) return;
        if (!open) { alert(T('Choose a conversation first.')); return; }
        var dt = new DataTransfer(); dt.items.add(file); fileIn.files = dt.files; fileIn.dispatchEvent(new Event('change'));
      }
      var thread = document.querySelector('.thread');
      thread.addEventListener('dragover', function (e) { if (e.dataTransfer && [].indexOf.call(e.dataTransfer.types, 'Files') !== -1) { e.preventDefault(); thread.classList.add('drop'); } });
      thread.addEventListener('dragleave', function (e) { if (!thread.contains(e.relatedTarget)) thread.classList.remove('drop'); });
      thread.addEventListener('drop', function (e) { e.preventDefault(); thread.classList.remove('drop'); pick(e.dataTransfer.files[0]); });
      box.addEventListener('paste', function (e) { var it = [].filter.call((e.clipboardData || {}).items || [], function (x) { return x.kind === 'file'; })[0]; if (it) { e.preventDefault(); pick(it.getAsFile()); } });
      fileIn.addEventListener('click', function (e) { if (!open) { e.preventDefault(); alert(T('Choose a conversation first.')); } });
      comp.addEventListener('submit', function (e) {
        e.preventDefault();
        if (picked && open && !btn.disabled) {
          var fd = new FormData(); fd.append('file', picked); fd.append('jid', open); fd.append('text', box.value.trim()); fd.append('_csrf', csrf);
          btn.disabled = true; btn.textContent = '…';
          var pend = document.createElement('div'); pend.className = 'b out pending'; pend.textContent = '📎 ' + picked.name; pend.insertAdjacentHTML('beforeend', '<small>' + T('Sending…') + '</small>');
          msgsEl.appendChild(pend); msgsEl.scrollTop = msgsEl.scrollHeight;
          var xhr = new XMLHttpRequest();
          uploading = true;
          prev.hidden = false;
          prev.innerHTML = '<div class="up-row"><span>📤 <b>' + esc(picked.name) + '</b></span><span class="up-pct">0%</span></div><div class="up-bar"><span style="width:0%"></span></div><div class="muted small up-msg">' + T('Uploading…') + '</div>';
          xhr.open('POST', '?api=send_media'); xhr.setRequestHeader('X-CSRF', csrf);
          xhr.upload.onprogress = function (ev) {
            if (!ev.lengthComputable) return;
            var pc = Math.round(ev.loaded / ev.total * 100);
            prev.querySelector('.up-bar span').style.width = pc + '%'; prev.querySelector('.up-pct').textContent = pc + '%';
            pend.querySelector('small').textContent = T('Uploading…') + ' ' + pc + '%';
            if (pc >= 100) prev.querySelector('.up-msg').textContent = T('Uploaded — sending on WhatsApp…');
          };
          xhr.onloadend = function () { uploading = false; };
          xhr.onload = function () {
            var r = null; try { r = JSON.parse(xhr.responseText); } catch (err) { r = { ok: false, error: 'HTTP ' + xhr.status + (xhr.status === 403 ? ' — ' + T('blocked before reaching the server (firewall/Cloudflare)') : (xhr.status === 413 ? ' — ' + T('file too large for the server') : '')) }; }
            uploading = false;
            if (r.ok) { pend.remove(); picked = null; fileIn.value = ''; prev.hidden = true; box.value = ''; loadThread(open, true); }
            else { pend.classList.add('failed'); pend.querySelector('small').textContent = T('Not sent:') + ' ' + (r.error || ''); prev.querySelector('.up-msg').textContent = T('Not sent:') + ' ' + (r.error || ''); prev.classList.add('bad'); }
            btn.disabled = false; btn.textContent = '➤';
          };
          xhr.onerror = function () { pend.classList.add('failed'); pend.querySelector('small').textContent = T('Not sent:') + ' ' + T('network error'); btn.disabled = false; btn.textContent = '➤'; };
          xhr.send(fd);
          return;
        }
        var text = box.value.trim(); if (!text || !open || btn.disabled) return;
        btn.disabled = true; btn.textContent = '…';
        var pending = document.createElement('div'); pending.className = 'b out pending'; pending.textContent = text; pending.insertAdjacentHTML('beforeend', '<small>' + T('Sending…') + '</small>');
        msgsEl.appendChild(pending); msgsEl.scrollTop = msgsEl.scrollHeight; box.value = ''; box.style.height = 'auto';
        fetch('?api=send', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: JSON.stringify({ jid: open, text: text, quote_id: window.WI_QUOTE || undefined }) })
          .then(wiSafeJson, wiNetFail)
          .then(function (r) {
            if (r.ok && r.queued) { pending.classList.add('queued'); pending.querySelector('small').textContent = '⏳ ' + T('Waiting — sent when WhatsApp is back'); window.WI_QUOTE = null; accState(); } // offline: kept in the outbox
            else if (r.ok) { pending.remove(); loadThread(open, true); window.WI_QUOTE = null; document.dispatchEvent(new Event('wi-sent')); }
            else { pending.classList.add('failed'); pending.querySelector('small').textContent = T('Not sent:') + ' ' + (r.error || ''); box.value = text; }
          })
          .catch(function () { pending.classList.add('failed'); box.value = text; })
          .finally(function () { btn.disabled = false; btn.textContent = '➤'; });
      });
    }
    var curAcc = app.getAttribute('data-acc') || '';
    function loadChats() { return api(view.all ? 'chats_all' : 'chats').then(function (r) {
      if (!r.ok) { listEl.innerHTML = '<div class="empty">' + esc(r.error || T('Could not load')) + '</div>'; return; }
      chats = r.chats || []; drawList(); return r; }); }
    loadChats().then(function (r) { if (!r) return; if (open) openChat(open);
      else { var dj = new URLSearchParams(location.search).get('jid'); if (dj) openChat(dj); } // links from other pages (team activity, cases…)
    });
    listEl.addEventListener('click', function (e) { var c = e.target.closest('.c'); if (!c) return; var a = c.getAttribute('data-acc');
      if (a && a !== curAcc) { location.href = '?p=inbox&acc=' + encodeURIComponent(a) + '&jid=' + encodeURIComponent(c.getAttribute('data-jid')); return; } // a chat of another number opens on that number
      openChat(c.getAttribute('data-jid')); });
    document.getElementById('chat-q').addEventListener('input', drawList);
    // ---- AI summary & category
    function showSummary(jid, x) {
      var box = document.getElementById('sum-box'); if (jid !== open) return;
      if (!x) { box.hidden = true; return; }
      box.hidden = false; box.classList.remove('open'); box.title = T('Click to open / close the summary'); var th = document.getElementById('thread-h'); if (th) box.style.top = (th.offsetTop + th.offsetHeight + 8) + 'px';
      box.innerHTML = x.ok === false ? '<span class="muted">' + (x.error === T('Summarising…') ? '⏳ ' : '⚠️ ') + esc(x.error || '') + '</span>'
        : '<div class="row" style="justify-content:space-between;align-items:flex-start"><div><b>🧠 ' + T('Summary') + '</b> <span class="cat cat-' + esc(x.category) + '">' + esc(CAT[x.category] ? T(CAT[x.category]) : x.category) + '</span> <span class="cat sent-' + esc(x.sentiment) + '">' + esc(T(x.sentiment)) + '</span></div><button type="button" class="link-btn" id="sum-x">✕</button></div>'
          + '<div class="sum-t" dir="auto">' + esc(x.summary) + '</div>' + (x.next_action ? '<div class="sum-n" dir="auto">➡️ ' + esc(x.next_action) + '</div>' : '') + '<small class="muted">' + esc(when(x.at, true)) + ' · ' + esc(x.model || '') + '</small>';
    }
    var csrfS = app.getAttribute('data-csrf');
    function summarize(jids, show) {
      if (show) showSummary(open, { ok: false, error: T('Summarising…') });
      return fetch('?api=summarize', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrfS }, body: JSON.stringify({ jids: jids }) })
        .then(wiSafeJson, wiNetFail)
        .then(function (r) { if (r.ok) { for (var j in r.results) { if (r.results[j].ok) meta.summaries[j] = { category: r.results[j].category, needs_reply: r.results[j].needs_reply, sentiment: r.results[j].sentiment }; if (show && j === open) showSummary(j, r.results[j]); } drawList(); } return r; });
    }
    document.getElementById('msgs').parentNode.addEventListener('click', function (e) { if (e.target.id === 'sum-x') { document.getElementById('sum-box').hidden = true; return; }
      var sb = e.target.closest('#sum-box'); if (sb && !e.target.closest('a,button')) sb.classList.toggle('open'); }); // one line until clicked
    if (CAN('delete_chat') || CAN('delete_msg')) { // recycle bin: deleted chats and messages can be put back
      var cbar = document.querySelector('.classify-bar'), tb = document.createElement('button'); tb.type = 'button'; tb.className = 'chip-btn sm'; tb.textContent = '🗑️ ' + T('Deleted'); cbar.insertBefore(tb, cbar.firstChild);
      var brief = function (m) { return m ? (m.text ? esc(m.text) : '📎 ' + esc(T(m.kind))) : ''; };
      var trash = function () {
        var d = document.getElementById('trash-dlg'); if (!d) { d = document.createElement('dialog'); d.id = 'trash-dlg'; d.className = 'dlg trash-dlg'; document.body.appendChild(d); }
        d.innerHTML = '<div class="ml-head"><b>🗑️ ' + T('Deleted chats and messages') + '</b><button type="button" class="btn ghost sm" data-tx>✕</button></div><p class="muted small">' + T('Put back shows them in the dashboard again. A delete on the phone cannot be undone by WhatsApp.') + '</p><div class="tr-box muted">' + T('Loading…') + '</div>';
        if (!d.open) d.showModal();
        api('trash').then(function (r) { var box = d.querySelector('.tr-box'); if (!r.ok) { box.textContent = '⚠️ ' + (r.error || ''); return; }
          var row = function (x, isMsg) { var nm = (x.name || (isMsg && x.msg && x.msg.name) || '') + ' ' + String(x.jid).split('@')[0];
            return '<div class="tr-r">' + av(x.jid, nm) + '<div class="grow"><b dir="auto">' + esc(nm.trim()) + '</b>' + (isMsg ? '' : ' <small class="muted">💬 ' + fmt(x.count) + '</small>')
              + '<div class="muted small" dir="auto">' + brief(isMsg ? x.msg : x.last) + '</div><small class="muted">🗑️ ' + esc(when(x.time)) + (x.by ? ' · ' + esc(x.by) : '') + '</small></div>'
              + '<button type="button" class="btn sm" data-rs="' + esc(x.jid) + '" data-id="' + esc(isMsg ? x.id : '') + '">↩️ ' + T('Put back') + '</button></div>'; };
          box.className = 'tr-box';
          box.innerHTML = (r.chats.length || !r.messages.length ? '<h4>🧹 ' + T('Chats') + ' (' + fmt(r.chats.length) + ')</h4>' + (r.chats.map(function (x) { return row(x, false); }).join('') || '<p class="muted">' + T('Nothing deleted.') + '</p>') : '')
            + (r.messages.length ? '<h4>💬 ' + T('Messages') + ' (' + fmt(r.messages.length) + ')</h4>' + r.messages.map(function (x) { return row(x, true); }).join('') : '');
        });
        d.onclick = function (e) { if (e.target.closest('[data-tx]')) { d.close(); return; }
          var b = e.target.closest('[data-rs]'); if (!b) return; b.disabled = true;
          WI.post('trash_restore', { jid: b.dataset.rs, id: b.dataset.id }).then(function (x) { if (!x.ok) { b.disabled = false; alert(x.error || T('Failed')); return; } b.closest('.tr-r').remove(); d.dataset.changed = '1'; });
        };
        d.onclose = function () { if (d.dataset.changed) location.reload(); };
      };
      tb.addEventListener('click', trash);
    }
    document.getElementById('classify-btn').addEventListener('click', function () {
      var b = this, st = document.getElementById('classify-st');
      var todo = chats.filter(function (c) { return !c.group && !(meta.summaries && meta.summaries[c.jid]); }).slice(0, 40).map(function (c) { return c.jid; });
      if (!todo.length) { st.textContent = T('All recent chats are classified.'); return; }
      b.disabled = true; var done = 0;
      (function next() {
        if (!todo.length) { b.disabled = false; st.textContent = '✓ ' + done; return; }
        var batch = todo.splice(0, 4); st.textContent = T('Classifying…') + ' ' + done + ' / ' + (done + batch.length + todo.length);
        summarize(batch, false).then(function () { done += batch.length; next(); }).catch(function () { b.disabled = false; });
      })();
    });
    // ---- Invoices from the chat + overdue reminder for this number
    var invData = null;
    function money2(v, c) { return Number(v || 0).toLocaleString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB') + ' ' + (c || (window.WI_SYS || {}).currency || ''); }
    function openInvoices() {
      var body = document.getElementById('inv-body'); body.innerHTML = '<div class="empty">' + T('Loading…') + '</div>';
      document.getElementById('inv-dlg').showModal();
      api('chat_invoices', { jid: open }).then(function (r) {
        invData = r;
        if (!r.ok) { body.innerHTML = '<div class="empty">' + esc(r.error || '') + '</div>'; return; }
        if (!r.customer) { body.innerHTML = '<div class="empty">' + T('This number is not a customer in the invoice system.') + (r.phone ? '<br><span dir="ltr">+' + esc(r.phone) + '</span>' : '') + '</div>'; return; }
        var c = r.customer, rem = r.reminder || {};
        var h = '<div class="inv-head"><div><b>' + esc(c.name) + '</b><div class="muted small">' + esc(c.person) + ' · <span dir="ltr">+' + esc(r.phone) + '</span></div></div><div class="inv-due ' + (c.due > 0 ? 'bad' : 'ok') + '"><small>' + T('Balance due') + '</small><b>' + money2(c.due) + '</b></div></div>';
        h += '<label>' + T('Message with the invoice (optional)') + '<textarea id="inv-text" rows="2" dir="auto" placeholder="' + T('e.g. Here is your invoice, thank you') + '"></textarea></label>';
        h += '<table class="table zebra"><thead><tr><th>' + T('Invoice') + '</th><th>' + T('Date') + '</th><th>' + T('Total') + '</th><th>' + T('Due') + '</th><th>' + T('Status') + '</th><th></th></tr></thead><tbody>'
          + (r.invoices || []).map(function (i) { return '<tr><td><b>' + esc(i.reference) + '</b></td><td class="small">' + esc(i.date) + (i.date_due && i.date_due !== '0000-00-00' ? '<br><span class="muted">' + T('due') + ' ' + esc(i.date_due) + '</span>' : '') + '</td><td>' + money2(i.total, i.currency) + '</td><td>' + (i.total_due > 0 ? money2(i.total_due, i.currency) : '—') + '</td><td><span class="badge ' + (i.status === 'paid' ? 'ok' : (i.overdue ? 'bad' : 'warn')) + '">' + esc(i.overdue ? T('overdue') : i.status) + '</span></td><td><button class="btn primary sm" data-send-inv="' + i.id + '">📤 ' + T('Send') + '</button></td></tr>'; }).join('') + '</tbody></table>';
        h += '<div class="inv-rem"><h3>⏰ ' + T('Overdue reminder for this number') + '</h3>'
          + '<label class="check"><input type="checkbox" id="ir-on" ' + (rem.enabled ? 'checked' : '') + '> ' + T('Remind automatically when an invoice is overdue') + '</label>'
          + '<div class="row wrap"><label>' + T('Every') + '<select id="ir-days">' + [1, 2, 3, 5, 7, 10, 14, 30].map(function (d) { return '<option value="' + d + '" ' + ((rem.every_days || 3) === d ? 'selected' : '') + '>' + d + ' ' + T('days') + '</option>'; }).join('') + '</select></label>'
          + '<label>' + T('At') + '<select id="ir-hour">' + Array.from({ length: 13 }, function (_, k) { var hh = 9 + k; return '<option value="' + hh + '" ' + ((rem.hour || 11) === hh ? 'selected' : '') + '>' + hh + ':00</option>'; }).join('') + '</select></label>'
          + '<label>' + T('Max reminders') + '<select id="ir-max">' + [1, 2, 3, 5, 10].map(function (d) { return '<option ' + ((rem.max_times || 5) === d ? 'selected' : '') + '>' + d + '</option>'; }).join('') + '</select></label>'
          + '<label class="check" style="align-self:end"><input type="checkbox" id="ir-img" ' + (rem.with_image ? 'checked' : '') + '> ' + T('Attach the invoice image') + '</label></div>'
          + '<label>' + T('Message') + ' <small class="muted">{name} {invoice} {due} {count} {methods} {date}</small><textarea id="ir-msg" rows="4" dir="auto">' + esc(rem.message || '') + '</textarea></label>'
          + (rem.last_sent ? '<div class="muted small">' + T('Last sent') + ': ' + esc(when(rem.last_sent, true)) + ' · ' + fmt(rem.sent_count || 0) + ' ' + T('times') + (rem.last_result ? ' · ' + esc(rem.last_result) : '') + '</div>' : '')
          + '<button class="btn primary" id="ir-save">' + T('Save reminder settings') + '</button></div>';
        body.innerHTML = h;
      });
    }
    document.getElementById('inv-body').addEventListener('click', function (e) {
      var sb = e.target.closest('[data-send-inv]');
      if (sb) {
        if (!confirm(T('Send this invoice to the customer on WhatsApp?'))) return;
        sb.disabled = true; sb.textContent = T('Sending…');
        fetch('?api=send_invoice', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrfS }, body: JSON.stringify({ jid: open, invoice_id: Number(sb.dataset.sendInv), text: document.getElementById('inv-text').value }) })
          .then(wiSafeJson, wiNetFail).then(function (r) { sb.textContent = r.ok ? '✓ ' + T('Sent!') : '✕ ' + (r.error || ''); if (r.ok) loadThread(open, true); });
      }
      if (e.target.id === 'ir-save') {
        fetch('?api=inv_reminder', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrfS }, body: JSON.stringify({ jid: open, enabled: document.getElementById('ir-on').checked, every_days: Number(document.getElementById('ir-days').value), hour: Number(document.getElementById('ir-hour').value), max_times: Number(document.getElementById('ir-max').value), with_image: document.getElementById('ir-img').checked, message: document.getElementById('ir-msg').value }) })
          .then(wiSafeJson, wiNetFail).then(function (r) { e.target.textContent = r.ok ? '✓ ' + T('Saved') : (r.error || ''); if (r.ok && r.reminder) document.getElementById('ir-msg').value = r.reminder.message; });
      }
    });
    document.getElementById('label-filter').addEventListener('click', function (e) {
      var b = e.target.closest('[data-label]'); if (!b) return;
      labelFilter = labelFilter === b.getAttribute('data-label') ? '' : b.getAttribute('data-label');
      [].forEach.call(this.children, function (x) { x.classList.toggle('on', x.getAttribute('data-label') === labelFilter); }); drawList();
    });
    document.getElementById('due-box').addEventListener('click', function (e) {
      var o = e.target.closest('[data-open-r]'), d = e.target.closest('[data-done-r]');
      if (o) openChat(o.getAttribute('data-open-r'));
      if (d) setMeta({ action: 'remind_done', jid: open || 'x', id: d.getAttribute('data-done-r') }).then(loadMeta);
    });
    [].forEach.call(document.querySelectorAll('dialog [data-close]'), function (b) { b.addEventListener('click', function () { b.closest('dialog').close(); }); });
    // Reminders
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function toLocal(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes()); }
    function openRemind() { var d = new Date(Date.now() + 3600000); document.getElementById('rm-at').value = toLocal(d); document.getElementById('rm-note').value = ''; document.getElementById('rm-dlg').showModal(); }
    document.getElementById('rm-quick').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return; var d;
      if (b.dataset.in) d = new Date(Date.now() + Number(b.dataset.in) * 60000); else { d = new Date(); d.setDate(d.getDate() + 1); d.setHours(Number(b.dataset.tomorrow), 0, 0, 0); }
      document.getElementById('rm-at').value = toLocal(d);
    });
    document.getElementById('rm-save').addEventListener('click', function () {
      var c = chatOf(open) || {};
      setMeta({ action: 'remind', jid: open, at: new Date(document.getElementById('rm-at').value).toISOString(), note: document.getElementById('rm-note').value, name: title(c) })
        .then(function (r) { if (r.ok) { document.getElementById('rm-dlg').close(); loadMeta(); } else alert(r.error || ''); });
    });
    // Labels (admin)
    function openLabels() {
      document.getElementById('lb-list').innerHTML = meta.labels.map(function (l) { return '<div class="row" style="justify-content:space-between"><span class="lbl" style="--c:' + esc(l.color) + '">' + esc(l.name) + '</span><button class="btn danger sm" data-lb-del="' + esc(l.id) + '">' + T('Delete') + '</button></div>'; }).join('') || '<div class="muted small">' + T('No labels yet.') + '</div>';
      document.getElementById('lb-dlg').showModal();
    }
    document.getElementById('lb-add').addEventListener('click', function () { setMeta({ action: 'label_def', name: document.getElementById('lb-name').value, color: document.getElementById('lb-color').value }).then(function (r) { if (!r.ok) { alert(r.error || ''); return; } document.getElementById('lb-name').value = ''; loadMeta().then(openLabels); }); });
    document.getElementById('lb-list').addEventListener('click', function (e) { var b = e.target.closest('[data-lb-del]'); if (b && confirm(T('Delete this label?'))) setMeta({ action: 'label_def_delete', id: b.getAttribute('data-lb-del') }).then(function () { loadMeta().then(openLabels); }); });
    loadMeta();
    setInterval(function () { if (!document.hidden) loadMeta(); }, 20000);
    setInterval(function () { if (open && !document.hidden) setMeta({ action: 'viewing', jid: open }); }, 15000);
    document.getElementById('chat-filter').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); filter = b.getAttribute('data-f'); drawList();
    });
  }
