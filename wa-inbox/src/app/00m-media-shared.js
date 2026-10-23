  /* ---------------- Media library (shared): pick a media item, choose chats, send / forward with a caption ---------------- */
  // Used by the Media page, the 🖼️ button of the chat box and the ↪ forward action on a chat's media.
  var ML = { items: null, vkeys: {} };
  // a short message that fades (app.js has no toast of its own)
  function mlToast(msg) { var x = document.createElement('div'); x.className = 'fx-toast'; x.textContent = msg; document.body.appendChild(x); setTimeout(function () { x.remove(); }, 3000); }
  // the library's kinds, in the order they are shown
  var ML_KINDS = [['sticker', '🙂', 'Stickers'], ['image', '🖼️', 'Photos'], ['gif', '🎞️', 'GIFs'], ['video', '🎬', 'Videos'], ['voice', '🎙️', 'Voice notes'], ['audio', '🎧', 'Audio'], ['document', '📄', 'Documents']];
  function mlUrl(key) { if (ML.vkeys && ML.vkeys[key]) return '?api=media_vault_file&k=' + encodeURIComponent(key); var p = String(key).split('/'); return p[0] === 'inbox_uploads' ? '?api=media&f=' + encodeURIComponent(p[1]) : '?api=media&a=' + encodeURIComponent(p[1]) + '&f=' + encodeURIComponent(p.slice(2).join('/')); }
  function mlThumb(it) {
    var u = mlUrl(it.key);
    if (it.kind === 'image' || it.kind === 'sticker') return '<img src="' + u + '" alt="" loading="lazy"' + (it.kind === 'sticker' ? ' class="ml-stk"' : '') + '>';
    // videos and GIFs: only a still frame, and only once on screen (a phone on mobile data does not download whole videos)
    if (it.kind === 'gif') return '<video data-lz="' + u + '#t=0.1" preload="none" muted playsinline></video><i class="ml-badge">GIF</i>';
    if (it.kind === 'video') return '<span class="mc-vplay" aria-hidden="true"><span>▶</span></span><i class="ml-badge">🎬</i>'; // no download until it is played
    return '<span class="ml-ic">' + (it.kind === 'voice' ? '🎙️' : it.kind === 'audio' ? '🎧' : '📄') + '</span>';
  }
  var mlLz = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) { es.forEach(function (e) { if (!e.isIntersecting) return; mlLz.unobserve(e.target); e.target.preload = 'metadata'; e.target.src = e.target.dataset.lz; }); }, { rootMargin: '150px' }) : null;
  new MutationObserver(function () { [].forEach.call(document.querySelectorAll('video[data-lz]:not([data-lzq])'), function (v) { v.setAttribute('data-lzq', '1'); if (mlLz) mlLz.observe(v); else { v.preload = 'metadata'; v.src = v.dataset.lz; } }); }).observe(document.documentElement, { childList: true, subtree: true });
  function mlSize(b) { b = +b || 0; if (!b) return '0 KB'; return b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  function mlLoad(force) { if (ML.items && !force) return Promise.resolve(ML.items); return WI.get('media_lib').then(function (r) { ML.items = r.ok ? r.items : []; ML.folders = r.folders || []; ML.bin = r.bin || null; ML.vaultN = r.vault_count || 0; return ML.items; }); }
  function mlDialog(html) { var d = document.getElementById('ml-dlg'); if (!d) { d = document.createElement('dialog'); d.id = 'ml-dlg'; d.className = 'dlg ml-dlg'; document.body.appendChild(d); }
    d.innerHTML = html; if (!d.open) d.showModal(); d.onclose = function () { d.innerHTML = ''; }; return d; }
  function mlFilter(items, q, kind, folder) { q = String(q || '').toLowerCase();
    return items.filter(function (it) { return (!kind || it.kind === kind) && (!folder || it.folder === folder) && (!q || (it.title + ' ' + (it.name || '') + ' ' + it.caption + ' ' + it.tags.join(' ') + ' ' + it.folder + ' ' + it.key).toLowerCase().indexOf(q) !== -1); }); }
  // the name people know a file by: its title, else the name it was sent with, else its caption
  function mlName(it) { return it.title || it.name || it.caption || ''; }
  function mlFileLike(it) { return it.kind === 'voice' || it.kind === 'audio' || it.kind === 'document'; }
  function mlGridHtml(items) { return items.length ? items.slice(0, 300).map(function (it) { var nm = mlName(it), file = mlFileLike(it);
    return '<button type="button" class="ml-it' + (file ? ' ml-file' : '') + '" data-key="' + esc(it.key) + '" title="' + esc(nm) + '">' + mlThumb(it) + (it.fav ? '<i class="ml-fav">★</i>' : '')
      + (nm || file ? '<span class="ml-t' + (it.title || it.name ? '' : ' cap') + '" dir="auto">' + esc(nm || (T(file ? (it.kind === 'document' ? 'Document' : 'Audio') : '') + (file && it.at ? ' · ' + when(it.at) : ''))) + '</span>' : '')
      + (it.size ? '<span class="ml-sz">' + mlSize(it.size) + '</span>' : '') + (it.youtube && it.youtube.state === 'done' ? '<i class="ml-yt" title="YouTube">▶</i>' : '') + '</button>'; }).join('') : '<div class="empty">' + T('Nothing found.') + '</div>'; }
  /** Pick one item, then send it to the given chat(s) with its caption (editable). */
  function mlPick(jids, done) {
    mlLoad().then(function (items) {
      var d = mlDialog('<div class="ml-head"><b>🖼️ ' + T('Send from the media library') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div>'
        + '<div class="ml-bar"><div class="search-xl"><span>🔎</span><input type="search" id="mlp-q" placeholder="' + esc(T('Search title, caption, tags…')) + '" dir="auto"></div>'
        + '<div class="seg sm" id="mlp-k"><button class="on" data-k="">' + T('All') + '</button>' + ML_KINDS.map(function (k) { return '<button data-k="' + k[0] + '" title="' + esc(T(k[2])) + '">' + k[1] + '</button>'; }).join('') + '</div>'
        + (ML.folders.length ? '<select id="mlp-f"><option value="">📁 ' + T('All folders') + '</option>' + ML.folders.map(function (f) { return '<option>' + esc(f) + '</option>'; }).join('') + '</select>' : '') + '</div>'
        + '<div class="ml-grid" id="mlp-g"></div><div class="ml-send" id="mlp-s" hidden></div>');
      var k = '', draw = function () { document.getElementById('mlp-g').innerHTML = mlGridHtml(mlFilter(items, document.getElementById('mlp-q').value, k, (document.getElementById('mlp-f') || {}).value)); };
      draw(); document.getElementById('mlp-q').oninput = draw; if (document.getElementById('mlp-f')) document.getElementById('mlp-f').onchange = draw;
      document.getElementById('mlp-k').onclick = function (e) { var b = e.target.closest('[data-k]'); if (!b) return; k = b.dataset.k; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); draw(); };
      d.onclick = function (e) { if (e.target.closest('[data-mlx]')) { d.close(); return; }
        var it = e.target.closest('.ml-it'); if (it) { var x = items.filter(function (i) { return i.key === it.dataset.key; })[0], s = document.getElementById('mlp-s'); s.hidden = false;
          s.innerHTML = '<div class="ml-prev">' + (x.kind === 'voice' || x.kind === 'audio' ? '<audio controls preload="none" src="' + mlUrl(x.key) + '"></audio>' : mlThumb(x)) + (mlName(x) ? '<b class="ml-pn" dir="auto">' + esc(mlName(x)) + '</b>' : '') + '</div><label>' + T('Caption') + '<textarea id="mlp-c" rows="3" dir="auto">' + esc(x.caption) + '</textarea></label><div class="row"><button type="button" class="btn primary" id="mlp-go">📤 ' + T('Send') + '</button><span class="muted small" id="mlp-m"></span></div>';
          document.getElementById('mlp-go').onclick = function () { this.disabled = true; document.getElementById('mlp-m').textContent = '⏳';
            WI.post('media_send', { key: x.key, jids: jids, caption: document.getElementById('mlp-c').value }).then(function (r) { if (r.ok) { d.close(); mlToast('📤 ' + T('Sending from the server — you can close this page')); if (done) setTimeout(function () { done(r); }, 5000); } else document.getElementById('mlp-m').textContent = '⚠️ ' + (r.error || T('Failed')); }); }; } };
    });
  }
  /** One chat's media and files, by kind: open, download or forward any of them. */
  function mlChatMedia(jid, title) {
    var d = mlDialog('<div class="ml-head"><b>📁 ' + T('Media of this chat') + ' <small class="muted" dir="auto">' + esc(title || '') + '</small></b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-cm muted">' + T('Loading…') + '</div>');
    d.onclick = function (e) { if (e.target.closest('[data-mlx]')) d.close(); };
    WI.get('chat_media', { jid: jid }).then(function (r) { var box = d.querySelector('.ml-cm'); if (!r.ok) { box.textContent = '⚠️ ' + (r.error || ''); return; }
      var items = r.items || [], k = '', cnt = {}; items.forEach(function (i) { cnt[i.kind] = (cnt[i.kind] || 0) + 1; });
      if (!items.length) { box.textContent = T('No photos, recordings or files in this chat yet.'); return; }
      box.className = 'ml-cm'; box.innerHTML = '<div class="seg sm" id="mlc-k"><button class="on" data-k="">' + T('All') + ' ' + items.length + '</button>' + ML_KINDS.filter(function (x) { return cnt[x[0]]; }).map(function (x) { return '<button data-k="' + x[0] + '" title="' + esc(T(x[2])) + '">' + x[1] + ' ' + cnt[x[0]] + '</button>'; }).join('') + '</div>'
        + '<div class="ml-grid" id="mlc-g"></div><div class="ml-send" id="mlc-s" hidden></div>';
      var draw = function () { document.getElementById('mlc-g').innerHTML = mlGridHtml(items.filter(function (i) { return !k || i.kind === k; })); }; draw();
      document.getElementById('mlc-k').onclick = function (e) { var b = e.target.closest('[data-k]'); if (!b) return; k = b.dataset.k; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); draw(); };
      document.getElementById('mlc-g').onclick = function (e) { var it = e.target.closest('.ml-it'); if (!it) return; var x = items.filter(function (i) { return i.key === it.dataset.key; })[0], s = document.getElementById('mlc-s'); s.hidden = false;
        s.innerHTML = '<div class="ml-prev">' + (x.kind === 'voice' || x.kind === 'audio' ? '<audio controls preload="none" src="' + mlUrl(x.key) + '"></audio>' : mlThumb(x)) + (mlName(x) ? '<b class="ml-pn" dir="auto">' + esc(mlName(x)) + '</b>' : '') + '<small class="muted">' + mlSize(x.size) + '</small></div>'
          + '<div class="row"><a class="btn sm" href="' + mlUrl(x.key) + '&dl=1' + (x.name ? '&name=' + encodeURIComponent(x.name.replace(/\.[^.]+$/, '')) : '') + '">⬇️ ' + T('Download') + '</a><a class="btn sm" href="' + mlUrl(x.key) + '" target="_blank" rel="noopener">👁️ ' + T('Open') + '</a><button type="button" class="btn primary sm" id="mlc-fw">↪ ' + T('Forward') + '</button></div>';
        document.getElementById('mlc-fw').onclick = function () { mlForward(x.key, x.caption || ''); }; };
    });
  }
  /** YouTube: one or several videos → the channel of the connected Google account (uploaded in the background). */
  function mlYoutube(keys, it, done) {
    var one = keys.length === 1 && it;
    var d = mlDialog('<div class="ml-head"><b>▶️ ' + T('Upload to YouTube') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-fw">'
      + (one ? '<label>' + T('Title') + '<input id="yt-t" maxlength="100" dir="auto" value="' + esc(it.title || '') + '"></label><label>' + T('Description') + '<textarea id="yt-d" rows="4" dir="auto">' + esc(it.caption || '') + '</textarea></label>'
        : '<div class="ml-many">🎬 ' + keys.length + ' ' + T('videos — each with its own title and caption from the library') + '</div>')
      + '<label>' + T('Tags') + '<input id="yt-g" dir="auto" placeholder="' + esc(T('separated by commas')) + '" value="' + esc(one ? (it.tags || []).join(', ') : '') + '"></label>'
      + '<label>' + T('Who can watch') + '<select id="yt-p"><option value="private">🔒 ' + T('Private') + '</option><option value="unlisted">🔗 ' + T('Unlisted (anyone with the link)') + '</option><option value="public">🌍 ' + T('Public') + '</option></select></label>'
      + '<p class="muted small">' + T('Uploaded in the background to the YouTube channel of the connected Google account. Until Google reviews your app, YouTube keeps uploads private — make them public from YouTube Studio.') + '</p>'
      + '<div class="row"><button type="button" class="btn primary" id="yt-go">▶️ ' + T('Upload') + '</button><span class="muted small" id="yt-m"></span></div></div>');
    d.onclick = function (e) { if (e.target.closest('[data-mlx]')) { d.close(); return; }
      if (e.target.closest('#yt-go')) { var b = document.getElementById('yt-go'); b.disabled = true; document.getElementById('yt-m').textContent = '⏳';
        WI.post('media_youtube', { keys: keys, privacy: document.getElementById('yt-p').value, tags: document.getElementById('yt-g').value, title: one ? document.getElementById('yt-t').value : '', desc: one ? document.getElementById('yt-d').value : undefined })
          .then(function (r) { if (!r.ok && r.need_yt) { b.disabled = false; document.getElementById('yt-m').innerHTML = '⚠️ ' + esc(r.error || '') + ' <button type="button" class="btn primary sm" id="yt-con">▶️ ' + T('Connect YouTube') + '</button>'; document.getElementById('yt-con').onclick = function () { WI.post('youtube_connect', {}).then(function (x) { if (x.ok) location.href = x.url; else document.getElementById('yt-m').textContent = '⚠️ ' + (x.error || T('Failed')); }); }; return; }
            if (!r.ok) { b.disabled = false; document.getElementById('yt-m').textContent = '⚠️ ' + (r.error || T('Failed')); return; } document.getElementById('yt-m').textContent = '✓ ' + T('Queued') + ' (' + r.queued + ')'; setTimeout(function () { d.close(); if (done) done(r); }, 900); }); } };
  }
  /** Choose up to 10 chats and send / forward one media file to them. */
  function mlForward(key, caption, done) {
    var isText = key === null; // a text message: sent as text
    var keys = isText ? [] : (Array.isArray(key) ? key : [key]), many = keys.length > 1, maxChats = Math.max(1, Math.min(10, Math.floor(30 / Math.max(1, keys.length)))); key = keys[0]; // several files: each goes with its own caption
    var kindOf = function (k) { return /\.webp$/i.test(k) ? 'sticker' : /\.(jpe?g|png)$/i.test(k) ? 'image' : (/\.(mp4|3gp|mov)$/i.test(k) ? 'video' : (/\.(ogg|opus|mp3|m4a|aac|wav)$/i.test(k) ? 'audio' : 'document')); };
    Promise.all([WI.get('chats'), isText ? Promise.resolve([]) : mlLoad().catch(function () { return []; })]).then(function (res) {
      var chats = (res[0].chats || []).slice(0, 400), lib = res[1] || [], sel = {}, gt = '';
      var meta = function (k) { return lib.filter(function (i) { return i.key === k; })[0] || { key: k, kind: kindOf(k) }; };
      var it = isText ? null : meta(key), size = 0; keys.forEach(function (k) { size += +meta(k).size || 0; });
      var label = function (c) { return c.name || ('+' + (c.phone || String(c.jid).split('@')[0])); };
      // what is sent: a compact card (file thumbnail, name, kind and size — or the text)
      var what = isText ? '<div class="fw-item"><span class="fw-ic">💬</span><div class="grow"><b>' + T('Message') + '</b><textarea id="mlf-c" rows="6" dir="auto">' + esc(caption || '') + '</textarea></div></div>'
        : many ? '<div class="fw-item"><span class="fw-ic">📦</span><div class="grow"><b>' + keys.length + ' ' + T('files') + '</b><small class="muted">' + (size ? mlSize(size) + ' · ' : '') + T('files — each is sent with its own caption') + '</small></div></div>'
        : '<div class="fw-item"><div class="fw-th">' + (it.kind === 'voice' || it.kind === 'audio' ? '<span class="fw-ic">' + (it.kind === 'voice' ? '🎙️' : '🎧') + '</span>' : mlThumb(it)) + '</div><div class="grow"><b dir="auto">' + esc(mlName(it) || T(it.kind === 'document' ? 'Document' : 'Audio')) + '</b><small class="muted">' + (it.size ? mlSize(it.size) : '') + '</small>'
          + (it.kind === 'voice' || it.kind === 'audio' ? '<audio controls preload="none" src="' + mlUrl(key) + '"></audio>' : '') + '<textarea id="mlf-c" rows="2" dir="auto" placeholder="' + esc(T('Caption')) + '">' + esc(caption || '') + '</textarea></div></div>';
      var d = mlDialog('<div class="ml-head"><b>↪ ' + T('Forward to chats') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div>'
        + '<div class="fw"><div class="fw-left">' + what + (isText ? '<div class="fw-len muted small" id="mlf-len"></div>' : '') + '</div><div class="fw-right">'
        + '<div class="fw-to"><div class="search-xl"><span>🔎</span><input type="search" id="mlf-q" placeholder="' + esc(T('Search name or number…')) + '" dir="auto"></div>'
        + '<div class="seg sm" id="mlf-t"><button class="on" data-t="">' + T('All') + '</button><button data-t="p">👤 ' + T('Chats') + '</button><button data-t="g">👥 ' + T('Groups') + '</button></div></div>'
        + '<div class="fw-sel" id="mlf-s" hidden></div><div class="fw-list" id="mlf-l"></div>'
        + '<div class="fw-foot"><span class="muted small" id="mlf-m">' + T('Up to') + ' ' + maxChats + ' ' + T('chats, sent one by one.') + '</span><button type="button" class="btn primary" id="mlf-go" disabled>📤 ' + T('Send') + ' <span id="mlf-n"></span></button></div></div></div>');
      d.classList.add('fw-dlg');
      var cta = document.getElementById('mlf-c'), len = document.getElementById('mlf-len'); // the whole message is shown: the box grows with the text
      var grow = function () { if (!cta) return; cta.style.height = 'auto'; cta.style.height = Math.min(cta.scrollHeight + 2, Math.round(window.innerHeight * 0.55)) + 'px'; if (len) len.textContent = fmt(cta.value.length) + ' ' + T('characters'); };
      if (cta) { cta.addEventListener('input', grow); setTimeout(grow, 30); }
      var drawSel = function () { var ks = Object.keys(sel), box = document.getElementById('mlf-s'); box.hidden = !ks.length;
        box.innerHTML = ks.map(function (j) { var c = chats.filter(function (x) { return x.jid === j; })[0] || { jid: j }; return '<span class="fw-chip" data-un="' + esc(j) + '" dir="auto">' + (c.group ? '👥 ' : '') + esc(label(c)) + ' ✕</span>'; }).join('');
        document.getElementById('mlf-n').textContent = ks.length ? '(' + ks.length + ')' : ''; document.getElementById('mlf-go').disabled = !ks.length; };
      var draw = function () { var q = document.getElementById('mlf-q').value.toLowerCase();
        var rows = chats.filter(function (c) { return (!gt || (gt === 'g') === !!c.group) && (!q || ((c.name || '') + ' ' + (c.phone || '')).toLowerCase().indexOf(q) !== -1); }).slice(0, 150);
        document.getElementById('mlf-l').innerHTML = rows.length ? rows.map(function (c) { var on = !!sel[c.jid]; return '<button type="button" class="fw-row' + (on ? ' on' : '') + '" data-j="' + esc(c.jid) + '">' + av(c.jid, label(c))
          + '<span class="grow"><b dir="auto">' + esc(label(c)) + '</b><small class="muted" dir="ltr">' + (c.group ? '👥 ' + T('Group') : (c.phone ? '+' + esc(c.phone) : '')) + '</small></span><i class="fw-ck' + (on ? ' on' : '') + '">' + (on ? '✓' : '') + '</i></button>'; }).join('') : '<div class="empty">' + T('Nothing found.') + '</div>'; };
      var toggle = function (j) { if (sel[j]) delete sel[j]; else { if (Object.keys(sel).length >= maxChats) { mlToast(T('Up to') + ' ' + maxChats + ' ' + T('chats, sent one by one.')); return; } sel[j] = 1; } draw(); drawSel(); };
      draw(); document.getElementById('mlf-q').oninput = draw;
      document.getElementById('mlf-t').onclick = function (e) { var b = e.target.closest('[data-t]'); if (!b) return; gt = b.dataset.t; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); draw(); };
      d.onclick = function (e) { if (e.target.closest('[data-mlx]')) { d.close(); return; }
        var r = e.target.closest('[data-j]'); if (r) { toggle(r.dataset.j); return; }
        var u = e.target.closest('[data-un]'); if (u) { toggle(u.dataset.un); return; }
        if (e.target.closest('#mlf-go')) { var b = document.getElementById('mlf-go'); b.disabled = true; document.getElementById('mlf-m').textContent = '⏳ ' + T('Sending…'); var cap = document.getElementById('mlf-c');
          (isText ? WI.post('forward_text', { text: cap.value, jids: Object.keys(sel) }) : WI.post('media_send', many ? { keys: keys, jids: Object.keys(sel) } : { key: key, jids: Object.keys(sel), caption: cap ? cap.value : '' })).then(function (x) { document.getElementById('mlf-m').textContent = x.ok ? '✓ ' + (x.queued ? T('Sending from the server — you can close this page') + ' (' + x.of + ')' : T('Sent') + ' ' + x.sent + ' / ' + x.of) : '⚠️ ' + (x.error || T('Failed')); if (x.ok) setTimeout(function () { d.close(); if (done) done(x); }, 1200); else b.disabled = false; }); } };
    });
  }

