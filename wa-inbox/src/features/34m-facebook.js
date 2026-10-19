  if (page === 'facebook') {
    var FB = { status: '', q: '', rows: [], count: {} };
    root.innerHTML = hero('📘', 'Facebook comments', 'The comments on your page\'s posts in one list: reply in public, reply in private, hide, delete or like. The bot can answer them too.', '')
      + '<div class="card" id="fb-cfg"></div>'
      + '<div class="card"><div class="row wrap gt-bar"><div class="search-xl"><span>🔎</span><input type="search" id="fb-q" placeholder="' + T('Search comments…') + '" dir="auto"></div>'
      + '<div class="seg" id="fb-st"><button data-v="" class="on">' + T('All') + '</button><button data-v="new">' + T('New') + '</button><button data-v="draft">' + T('Bot drafts') + '</button><button data-v="replied">' + T('Replied') + '</button><button data-v="hidden">' + T('Hidden') + '</button></div></div>'
      + '<div id="fb-list"><div class="empty">' + T('Loading…') + '</div></div></div>';
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
  }
