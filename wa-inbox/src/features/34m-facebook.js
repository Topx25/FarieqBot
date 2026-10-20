  if (page === 'facebook') {
    var FB = { status: '', q: '', rows: [], count: {} };
    root.innerHTML = hero('📘', 'Facebook', 'Your page\'s comments, posts and numbers in one place: reply in public or in private, hide, delete or like comments; write, schedule, edit and delete posts; and follow the page\'s numbers.', '')
      + '<div class="seg" id="fb-tabs"><button data-t="c" class="on">💬 ' + T('Comments') + '</button><button data-t="p">📰 ' + T('Posts') + '</button><button data-t="i">📈 ' + T('Numbers') + '</button></div>'
      + '<div id="fb-pane-c"><div class="card" id="fb-cfg"></div>'
      + '<div class="card"><div class="row wrap gt-bar"><div class="search-xl"><span>🔎</span><input type="search" id="fb-q" placeholder="' + T('Search comments…') + '" dir="auto"></div>'
      + '<div class="seg" id="fb-st"><button data-v="" class="on">' + T('All') + '</button><button data-v="new">' + T('New') + '</button><button data-v="draft">' + T('Bot drafts') + '</button><button data-v="replied">' + T('Replied') + '</button><button data-v="hidden">' + T('Hidden') + '</button></div></div>'
      + '<div id="fb-list"><div class="empty">' + T('Loading…') + '</div></div></div></div>'
      + '<div id="fb-pane-p" hidden></div><div id="fb-pane-i" hidden></div>';
    var fbCfg = document.getElementById('fb-cfg');
    function fbCfgDraw(c) {
      var o = function (v, cur, l) { return '<option value="' + v + '" ' + (cur === v ? 'selected' : '') + '>' + l + '</option>'; };
      fbCfg.innerHTML = '<div class="gcf"><label>' + T('Bot answers comments') + '<select id="fb-bot">' + o('off', c.comment_bot, T('Off')) + o('draft', c.comment_bot, '📝 ' + T('Suggest only (a draft waits for a person)')) + o('auto', c.comment_bot, '⚡ ' + T('Automatic')) + '</select></label>'
        + '<label class="check"><input type="checkbox" id="fb-pub" ' + (c.comment_public ? 'checked' : '') + '> ' + T('Reply under the comment') + '</label>'
        + '<label class="check"><input type="checkbox" id="fb-prv" ' + (c.comment_private ? 'checked' : '') + '> ' + T('Also send a private message to the commenter') + '</label>'
        + '<button class="btn primary sm" id="fb-save">' + T('Save') + '</button></div>';
      document.getElementById('fb-save').onclick = function () {
        post('fb_cfg', { comment_bot: document.getElementById('fb-bot').value, comment_public: document.getElementById('fb-pub').checked ? 1 : 0, comment_private: document.getElementById('fb-prv').checked ? 1 : 0 }).then(function (r) { toast(r && r.ok ? T('Saved') : ((r && r.error) || T('Failed'))); });
      };
    }
    function fbDraw() {
      var box = document.getElementById('fb-list');
      if (!FB.rows.length) { box.innerHTML = '<div class="empty">' + T('No comments here yet.') + '</div>'; return; }
      box.innerHTML = FB.rows.map(function (c) {
        var p = c.post || {}, st = { new: '🆕', replied: '✅', hidden: '🙈' }[c.status] || '';
        return '<div class="card fb-c" data-id="' + esc(c.id) + '"><div class="row wrap"><b dir="auto">' + st + ' ' + esc(c.from_name || c.from_id) + '</b><span class="muted small">' + esc(when(c.time)) + '</span></div>'
          + (p.text || p.url ? '<div class="muted small" dir="auto">📌 ' + esc(p.text || '') + (p.url ? ' <a href="' + esc(p.url) + '" target="_blank" rel="noopener">↗</a>' : '') + '</div>' : '')
          + '<p dir="auto" style="white-space:pre-wrap">' + esc(c.text) + '</p>'
          + (c.reply ? '<div class="small" dir="auto">↩️ ' + esc(c.reply) + '</div>' : '') + (c.private ? '<div class="small" dir="auto">✉️ ' + esc(c.private) + '</div>' : '')
          + (c.draft && c.status === 'new' ? '<div class="small" dir="auto">📝 ' + T('Bot draft') + ': ' + esc(c.draft) + ' <button class="btn ghost sm" data-act="usedraft">' + T('Use') + '</button></div>' : '')
          + '<div class="row wrap fb-acts"><button class="btn sm" data-act="reply">💬 ' + T('Reply') + '</button><button class="btn ghost sm" data-act="private">✉️ ' + T('Private reply') + '</button>'
          + '<button class="btn ghost sm" data-act="like">👍</button><button class="btn ghost sm" data-act="' + (c.status === 'hidden' ? 'unhide' : 'hide') + '">' + (c.status === 'hidden' ? '👁 ' + T('Show') : '🙈 ' + T('Hide')) + '</button><button class="btn danger sm" data-act="delete">🗑</button></div>'
          + '<div class="fb-box" hidden></div></div>';
      }).join('');
    }
    function fbLoad() {
      get('fb_comments', { status: FB.status, q: FB.q }).then(function (r) {
        if (!r.ok) { fail(document.getElementById('fb-list'), r); return; }
        FB.rows = r.rows; FB.count = r.count || {}; fbDraw();
      });
    }
    get('fb_cfg').then(function (r) { if (r.ok) fbCfgDraw(r.cfg); else fail(fbCfg, r); });
    document.getElementById('fb-st').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); FB.status = b.dataset.v; fbLoad(); };
    var fbT = null; document.getElementById('fb-q').oninput = function () { var v = this.value; clearTimeout(fbT); fbT = setTimeout(function () { FB.q = v; fbLoad(); }, 300); };
    function fbAct(id, act, text, done) { post('fb_comment_act', { id: id, act: act, text: text || '' }).then(function (r) { if (r && r.ok) { toast(T('Done')); fbLoad(); if (done) done(); } else toast((r && r.error) || T('Failed')); }); }
    document.getElementById('fb-list').addEventListener('click', function (e) {
      var b = e.target.closest('[data-act]'); if (!b) return; var card = b.closest('.fb-c'), id = card.dataset.id, act = b.dataset.act, box = card.querySelector('.fb-box');
      var row = FB.rows.filter(function (x) { return x.id === id; })[0] || {};
      if (act === 'reply' || act === 'private' || act === 'usedraft') {
        box.hidden = false; var pre = act === 'usedraft' ? row.draft : '';
        box.innerHTML = '<textarea rows="3" dir="auto" placeholder="' + T(act === 'private' ? 'Private message to the commenter (one only, within 7 days)' : 'Reply under the comment') + '">' + esc(pre) + '</textarea><div class="row"><button class="btn primary sm" data-go="' + (act === 'private' ? 'private' : 'reply') + '">' + T('Send') + '</button></div>';
        box.querySelector('textarea').focus(); return;
      }
      if (act === 'delete' && !confirm(T('Delete this comment from Facebook?'))) return;
      fbAct(id, act);
    });
    document.getElementById('fb-list').addEventListener('click', function (e) {
      var g = e.target.closest('[data-go]'); if (!g) return; var card = g.closest('.fb-c'), t = card.querySelector('textarea').value.trim(); if (!t) return;
      g.disabled = true; fbAct(card.dataset.id, g.dataset.go, t);
    });
    fbLoad();
    // ----- posts -----
    var fbPostsDone = false, fbInsDone = false;
    function fbPosts() {
      var pane = document.getElementById('fb-pane-p');
      pane.innerHTML = '<div class="card"><h3>✍️ ' + T('New post') + '</h3><label>' + T('Text') + '<textarea id="fp-text" rows="4" dir="auto"></textarea></label>'
        + '<label>' + T('Link (optional)') + '<input id="fp-link" dir="ltr" placeholder="https://"></label><label>' + T('Photo or video (optional)') + '<input type="file" id="fp-file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4"></label>'
        + '<label class="check"><input type="checkbox" id="fp-story"> ' + T('Publish as a story (a photo or an MP4 video, shown for 24 hours)') + '</label>'
        + '<label>' + T('When (empty = now; at least 10 minutes ahead)') + '<input type="datetime-local" id="fp-at"></label><div class="row"><button class="btn primary" id="fp-go">📘 ' + T('Publish') + '</button><span class="muted small" id="fp-msg"></span></div></div>'
        + '<div id="fp-list"><div class="empty">' + T('Loading…') + '</div></div>';
      document.getElementById('fp-go').onclick = function () {
        var b = this, msg = document.getElementById('fp-msg'), f = document.getElementById('fp-file').files[0], at = document.getElementById('fp-at').value;
        var send = function (att) { post('fb_post_act', { act: document.getElementById('fp-story').checked ? 'story' : 'create', text: document.getElementById('fp-text').value, link: document.getElementById('fp-link').value, attachment: att || '', at: at ? Math.floor(new Date(at).getTime() / 1000) : 0 }).then(function (r) {
          b.disabled = false; msg.textContent = r && r.ok ? T('Done') : ((r && r.error) || T('Failed'));
          if (r && r.ok) { document.getElementById('fp-text').value = ''; document.getElementById('fp-link').value = ''; document.getElementById('fp-file').value = ''; document.getElementById('fp-at').value = ''; fbPostList(); } }); };
        b.disabled = true; msg.textContent = T('Working…');
        if (!f) { send(''); return; }
        var fd = new FormData(); fd.append('file', f); void 0;
        fetch('?api=fb_upload', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': csrf }, body: fd }).then(function (x) { return x.json(); }).then(function (u) { if (u && u.ok) send(u.attachment); else { b.disabled = false; msg.textContent = (u && u.error) || T('Failed'); } }).catch(function () { b.disabled = false; msg.textContent = T('Failed'); });
      };
      fbPostList();
    }
    function fbPostList() {
      get('fb_posts').then(function (r) {
        var box = document.getElementById('fp-list'); if (!r.ok) { fail(box, r); return; }
        var row = function (p) { return '<div class="card fb-c" data-id="' + esc(p.id) + '"><div class="row wrap"><b>' + (p.scheduled ? '⏰ ' + T('Scheduled') : '📰') + '</b><span class="muted small">' + esc(when(p.time)) + '</span>' + (p.url ? '<a class="muted small" href="' + esc(p.url) + '" target="_blank" rel="noopener">↗</a>' : '') + '</div>'
          + (p.pic ? '<img src="' + esc(p.pic) + '" alt="" style="max-width:220px;border-radius:10px">' : '') + '<p dir="auto" style="white-space:pre-wrap">' + esc(p.text) + '</p>'
          + (p.scheduled ? '' : '<div class="muted small">👍 ' + fmt(p.reactions) + ' · 💬 ' + fmt(p.comments) + ' · ↪️ ' + fmt(p.shares) + '</div>')
          + '<div class="row wrap fb-acts"><button class="btn ghost sm" data-pa="edit">✏️ ' + T('Edit') + '</button><button class="btn danger sm" data-pa="delete">🗑</button></div><div class="fb-box" hidden></div></div>'; };
        box.innerHTML = (r.scheduled.length ? '<h3>' + T('Scheduled') + '</h3>' + r.scheduled.map(row).join('') : '') + (r.posts.length ? r.posts.map(row).join('') : '<div class="empty">' + T('No posts yet.') + '</div>');
      });
    }
    document.getElementById('fb-pane-p').addEventListener('click', function (e) {
      var b = e.target.closest('[data-pa]'), g = e.target.closest('[data-pgo]');
      if (b) { var card = b.closest('.fb-c'), id = card.dataset.id;
        if (b.dataset.pa === 'delete') { if (!confirm(T('Delete this post from Facebook?'))) return; post('fb_post_act', { act: 'delete', id: id }).then(function (r) { toast(r && r.ok ? T('Done') : ((r && r.error) || T('Failed'))); fbPostList(); }); return; }
        var box = card.querySelector('.fb-box'); box.hidden = false; box.innerHTML = '<textarea rows="4" dir="auto">' + esc(card.querySelector('p').textContent) + '</textarea><div class="row"><button class="btn primary sm" data-pgo="1">' + T('Save') + '</button></div>'; return; }
      if (g) { var c2 = g.closest('.fb-c'); post('fb_post_act', { act: 'edit', id: c2.dataset.id, text: c2.querySelector('textarea').value }).then(function (r) { toast(r && r.ok ? T('Done') : ((r && r.error) || T('Failed'))); fbPostList(); }); }
    });
    // ----- numbers -----
    function fbIns() {
      var pane = document.getElementById('fb-pane-i'); pane.innerHTML = '<div class="empty">' + T('Loading…') + '</div>';
      get('fb_insights').then(function (r) {
        if (!r.ok) { fail(pane, r); return; }
        var spark = function (a) { var m = Math.max.apply(null, a.concat([1])); return '<svg viewBox="0 0 ' + a.length * 6 + ' 28" width="100%" height="36" preserveAspectRatio="none">' + a.map(function (v, i) { var h = Math.round(v / m * 26); return '<rect x="' + i * 6 + '" y="' + (28 - h) + '" width="4" height="' + h + '" rx="1" fill="currentColor" opacity=".55"/>'; }).join('') + '</svg>'; };
        pane.innerHTML = '<div class="kpis card"><div><b>' + fmt(r.page.followers) + '</b><span>' + T('Followers') + '</span></div><div><b>' + fmt(r.page.likes) + '</b><span>' + T('Page likes') + '</span></div></div>'
          + '<div class="card"><h3>' + T('Last 28 days') + '</h3>' + r.metrics.map(function (m) { return m.ok ? '<div class="fb-m"><div class="row wrap"><b>' + T(m.label) + '</b><span class="muted small">' + fmt(m.total) + ' · ' + T('last 7 days') + ' ' + fmt(m.last7) + '</span></div>' + spark(m.series) + '</div>' : '<div class="fb-m muted small">' + T(m.label) + ': ' + T('not available from Facebook for this page') + '</div>'; }).join('') + '</div>';
      });
    }
    document.getElementById('fb-tabs').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); });
      ['c', 'p', 'i'].forEach(function (k) { document.getElementById('fb-pane-' + k).hidden = k !== b.dataset.t; });
      if (b.dataset.t === 'p' && !fbPostsDone) { fbPostsDone = true; fbPosts(); } if (b.dataset.t === 'i') fbIns(); };
      var fbT0 = new URLSearchParams(location.search).get('tab'); if (fbT0) { var fbB0 = document.querySelector('#fb-tabs [data-t="' + fbT0 + '"]'); if (fbB0) fbB0.click(); } // the Facebook menu opens a tab directly
  }
