  /* ---------------- Media library page: folders (with sub-folders), kinds, selection with move / favourite / delete,
     a bin to put deleted items back, and an edit panel (title, caption, folder, tags) with "send to chats" ---------------- */
  if (page === 'media') {
    var mroot = document.getElementById('ml-page'), mk = '', mf = '', mq = '', mcur = null, mbin = /[?&]bin=1/.test(location.search), msel = {}, mfolders = [], msort = 'new';
    try { msort = localStorage.getItem('wi_ml_sort') || 'new'; } catch (e) {}
    var mchat = '', mfrom = '', mto = '', mview = 'grid', mdet = false; try { mview = localStorage.getItem('wi_ml_view') || 'grid'; mdet = localStorage.getItem('wi_ml_det') === '1'; } catch (e) {}
    function mlChats(items) { var by = {}; items.forEach(function (i) { if (!i.jid) return; by[i.jid] = by[i.jid] || { n: 0, name: i.chat || i.jid.split('@')[0], g: /@g\.us$/.test(i.jid) }; by[i.jid].n++; });
      var el = document.getElementById('ml-chat'); if (!el) return; el.innerHTML = '<option value="">💬 ' + T('All chats and groups') + '</option>' + Object.keys(by).sort(function (a, b) { return by[b].n - by[a].n; }).map(function (j) { return '<option value="' + esc(j) + '"' + (j === mchat ? ' selected' : '') + '>' + (by[j].g ? '👥 ' : '👤 ') + esc(by[j].name) + ' (' + fmt(by[j].n) + ')</option>'; }).join(''); }
    // storage used: the whole library and each kind, as bars
    function mlSpace(items) { // space used by kind: files, size and share of the total (+ what waits in Deleted)
      var tot = 0, by = {}, n = {}; items.forEach(function (i) { tot += i.size; by[i.kind] = (by[i.kind] || 0) + i.size; n[i.kind] = (n[i.kind] || 0) + 1; });
      var bin = ML.bin || { count: 0, size: 0 };
      return '<div class="ml-space"><div class="ml-sp-h">💾 ' + T('Space used') + ' <b>' + mlSize(tot) + '</b><small class="muted">' + fmt(items.length) + ' ' + T('files') + '</small></div>'
        + ML_KINDS.filter(function (k) { return by[k[0]]; }).sort(function (a, b) { return by[b[0]] - by[a[0]]; }).map(function (k) { var pc = tot ? by[k[0]] / tot * 100 : 0;
          return '<div class="ml-sp-r" title="' + esc(fmt(n[k[0]]) + ' ' + T('files')) + '"><span>' + k[1] + ' ' + T(k[2]) + ' <small class="muted">' + fmt(n[k[0]]) + '</small></span><b>' + mlSize(by[k[0]]) + ' <small class="muted">' + (pc < 1 ? '<1' : fmt(Math.round(pc))) + '%</small></b><i style="width:' + Math.max(2, Math.round(pc)) + '%"></i></div>'; }).join('')
        + (bin.count ? '<div class="ml-sp-r ml-sp-bin"><span>🗑 ' + T('Deleted') + ' <small class="muted">' + fmt(bin.count) + '</small></span><b>' + mlSize(bin.size) + '</b></div>' : '') + '</div>'; }
    function mlFolders() { return WI.get('media_folders').then(function (r) { mfolders = r.ok ? r.folders : []; }); }
    function mlTree() { // folders as a tree: "Parent/Child" → indented, the open one highlighted
      // counts are of every kind (like the space card); the kind buttons above the grid count the open folder
      var all = ML.items || [], inF = function (f) { return all.filter(function (i) { return i.folder === f || i.folder.indexOf(f + '/') === 0; }); },
        cnt = function (f) { var a = inF(f), s = 0; a.forEach(function (i) { s += i.size; }); return fmt(a.length) + (a.length ? ' · ' + mlSize(s) : ''); };
      return mlSpace(ML.items || []) + '<div class="ml-fh">' + T('Show') + '</div><button type="button" class="ml-fd' + (mf === '' && !mbin ? ' on' : '') + '" data-fd="">📚 ' + T('All media') + ' <i>' + fmt(all.length) + '</i></button>'
        + '<button type="button" class="ml-fd' + (mf === '__fav' ? ' on' : '') + '" data-fd="__fav">★ ' + T('Favourites') + ' <i>' + fmt(all.filter(function (i) { return i.fav; }).length) + '</i></button>'
        + '<button type="button" class="ml-fd ml-binb' + (mbin ? ' on' : '') + '" data-bin>🗑 ' + T('Deleted') + ' <i>' + fmt((ML.bin || {}).count || 0) + '</i></button>' // always in sight, next to Favourites
        + '<button type="button" class="ml-fd' + (mf === '__none' ? ' on' : '') + '" data-fd="__none">📥 ' + T('Not in a folder') + ' <i>' + fmt(all.filter(function (i) { return !i.folder; }).length) + '</i></button>'
        + '<div class="ml-fh">' + T('Folders') + '<button type="button" class="link-btn" data-fnew="">＋ ' + T('New folder') + '</button></div>'
        + mfolders.map(function (f) { var depth = f.split('/').length - 1, name = f.split('/').pop();
          return '<div class="ml-fr" style="padding-inline-start:' + (depth * 16) + 'px"><button type="button" class="ml-fd' + (mf === f ? ' on' : '') + '" data-fd="' + esc(f) + '">' + (depth ? '└ ' : '') + '📁 ' + esc(name) + ' <i>' + cnt(f) + '</i></button>'
            + '<span class="ml-fa"><button type="button" title="' + esc(T('New sub-folder')) + '" data-fnew="' + esc(f) + '">＋</button><button type="button" title="' + esc(T('Rename')) + '" data-fren="' + esc(f) + '">✏️</button><button type="button" title="' + esc(T('Delete folder')) + '" data-fdel="' + esc(f) + '">🗑</button></span></div>'; }).join('')
        + '<div class="ml-fh">' + T('Tools') + '</div><button type="button" class="ml-fd" data-dupes>🧬 ' + T('Duplicates') + '</button>'
        + '<button type="button" class="ml-fd" data-inv>📋 ' + T('Inventory') + '</button>'
        ;
    }
    // 📋 Inventory: everything in the library and every shortcut, built now — always the latest; downloadable as a sheet
    function mlInventory() {
      var d = mlDialog('<div class="ml-head"><b>📋 ' + T('Library inventory') + '</b><span class="grow"></span><a class="btn sm" href="?api=media_inventory&fmt=csv">⬇️ ' + T('Download (Excel)') + '</a><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="inv muted">' + T('Loading…') + '</div>');
      d.onclick = function (e) { if (e.target.closest('[data-mlx]')) d.close(); };
      WI.get('media_inventory').then(function (r) { var box = d.querySelector('.inv'); if (!r.ok) { box.textContent = '⚠️ ' + (r.error || ''); return; } var S = r.summary, K = {}; ML_KINDS.forEach(function (k) { K[k[0]] = k[1]; });
        box.className = 'inv';
        var row = function (x) { return '<tr><td>' + (K[x.kind] || '📄') + '</td><td dir="auto">' + (x.code ? '<code>' + esc(x.code) + '</code>' : '<span class="muted">—</span>') + '</td><td dir="auto">' + esc(x.title || x.name || '') + (x.folder ? ' <small class="muted">📁 ' + esc(x.folder) + '</small>' : '') + '</td><td>' + mlSize(x.size) + '</td><td>' + fmt(x.used) + '</td></tr>'; };
        box.innerHTML = '<div class="inv-sum"><span><b>' + fmt(S.files) + '</b> ' + T('files') + '</span><span><b>' + mlSize(S.size) + '</b></span><span><b>' + fmt(S.named) + '</b> ' + T('named') + '</span><span><b>' + fmt(S.with_code) + '</b> ' + T('with a shortcut') + '</span>'
          + Object.keys(S.by_kind || {}).map(function (k) { return '<span>' + (K[k] || '📄') + ' ' + fmt(S.by_kind[k]) + '</span>'; }).join('') + '</div>'
          + '<div class="search-xl"><span>🔎</span><input type="search" id="inv-q" placeholder="' + esc(T('Search title, caption, tags…')) + '" dir="auto"></div>'
          + '<div class="inv-t"><table><thead><tr><th></th><th>' + T('Shortcut') + '</th><th>' + T('Name') + '</th><th>' + T('Size') + '</th><th>' + T('Sent') + '</th></tr></thead><tbody id="inv-b"></tbody></table></div>'
          + (r.quick.length ? '<h4>⚡ ' + T('Quick replies') + ' (' + fmt(r.quick.length) + ')</h4><div class="inv-chips">' + r.quick.map(function (q) { return '<span dir="auto">' + (q.keyword ? '<code>#' + esc(q.keyword.replace(/^[#\/]/, '')) + '</code> ' : '') + esc(q.title) + '</span>'; }).join('') + '</div>' : '')
          + (r.programs.length ? '<h4>📚 ' + T('Programs') + ' (' + fmt(r.programs.length) + ')</h4><div class="inv-chips">' + r.programs.map(function (p) { return '<span dir="auto">' + esc(p.name) + ' · ' + fmt(p.days) + '</span>'; }).join('') + '</div>' : '')
          + '<p class="muted small">💬 ' + T('In WhatsApp: write the list command and the library word to get this list there.') + '</p>';
        var draw = function () { var q = (document.getElementById('inv-q').value || '').toLowerCase(); document.getElementById('inv-b').innerHTML = r.items.filter(function (x) { return !q || (x.code + ' ' + x.title + ' ' + x.name + ' ' + x.folder + ' ' + x.caption).toLowerCase().indexOf(q) !== -1; }).slice(0, 1500).map(row).join(''); };
        draw(); document.getElementById('inv-q').oninput = draw; });
    }
    function mlDupes() { // the same file saved more than once: one healthy copy is kept, the others go to the recycle bin
      var d = mlDialog('<div class="ml-head"><b>🧬 ' + T('Duplicates') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-dp muted">' + T('Looking for copies of the same file…') + '</div>');
      d.onclick = function (e) { if (e.target.closest('[data-mlx]')) d.close(); };
      WI.get('media_dupes').then(function (r) { var box = d.querySelector('.ml-dp'); if (!r.ok) { box.textContent = '⚠️ ' + (r.error || ''); return; }
        if (!r.groups.length) { box.innerHTML = '✓ ' + T('No duplicates: every file is saved once.'); return; }
        var by = {}, th = function (k) { return by[k] ? mlThumb(by[k]) : '📄'; }; (ML.items || []).forEach(function (i) { by[i.key] = i; });
        box.className = 'ml-dp'; box.innerHTML = '<p><b>' + fmt(r.copies) + '</b> ' + T('extra copies') + ' · ' + T('space freed') + ' <b>' + mlSize(r.waste) + '</b></p>'
          + '<p class="muted">' + T('For each file one healthy copy is kept (favourite, most sent, with a caption, else the oldest); its tags, caption and send count are kept. The copies go to Deleted and can be put back.') + '</p>'
          + '<div class="ml-dg">' + r.groups.slice(0, 60).map(function (g) { return '<div class="ml-dr"><span class="ml-dk" title="' + esc(T('Kept')) + '">✅ ' + th(g.keep) + '</span><span class="ml-dx">'
            + g.remove.map(function (k) { return '<span title="' + esc(T('Removed')) + '">' + th(k) + '</span>'; }).join('') + '</span><small class="muted">' + mlSize(g.size) + ' × ' + fmt(g.remove.length) + '</small></div>'; }).join('') + '</div>'
          + (r.groups.length > 60 ? '<p class="muted">+ ' + fmt(r.groups.length - 60) + '</p>' : '')
          + '<div class="row"><button type="button" class="btn danger" id="ml-dgo">🧹 ' + T('Remove the duplicates') + ' (' + fmt(r.copies) + ')</button></div>';
        d.querySelector('#ml-dgo').onclick = function () { if (!confirm(T('Move the extra copies to Deleted and keep one copy of each file?'))) return; this.disabled = true;
          WI.post('media_dupes', {}).then(function (x) { d.close(); mlToast(x.ok ? '✓ ' + fmt(x.removed) + ' · ' + mlSize(x.waste) : '⚠️ ' + (x.error || '')); mlPage(true); }); }; });
    }
    // One file as a card: the media itself (photos open the editor; voice, audio and video play and seek in place),
    // a readable name (never the raw file id), size, date and the chat it came from, and the actions.
    var KN = {}; ML_KINDS.forEach(function (k) { KN[k[0]] = k; });
    function mlNice(it) { var n = mlName(it); if (n && !/^[A-F0-9]{16,}\.[a-z0-9]+$/i.test(n)) return n; var k = KN[it.kind]; return (k ? k[1] + ' ' + T(k[2]).replace(/s$/, '') : '📄') + (it.at ? ' · ' + when(it.at) : ''); }
    function mlCard(it) {
      var u = mlUrl(it.key), media;
      if (it.kind === 'voice' || it.kind === 'audio') media = '<div class="mc-audio"><span class="mc-big">' + (it.kind === 'voice' ? '🎙️' : '🎧') + '</span><audio controls preload="none" src="' + u + '"></audio></div>';
      else if (it.kind === 'video') media = '<video class="mc-vid" controls preload="none" playsinline data-lz="' + u + '#t=0.5"></video>';
      else if (it.kind === 'gif') media = '<button type="button" class="ml-it mc-pic" data-key="' + esc(it.key) + '"><video data-lz="' + u + '" preload="none" muted loop autoplay playsinline></video><i class="ml-badge">GIF</i></button>';
      else if (it.kind === 'image' || it.kind === 'sticker') media = '<button type="button" class="ml-it mc-pic" data-key="' + esc(it.key) + '"><img src="' + u + '" alt="" loading="lazy"' + (it.kind === 'sticker' ? ' class="ml-stk"' : '') + '></button>';
      else media = '<button type="button" class="ml-it mc-pic mc-doc" data-key="' + esc(it.key) + '"><svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h8l5 5v13H6z"/><path d="M14 3v5h5M9 13h7M9 17h7"/></svg><span class="mc-ext">' + esc((it.key.split('.').pop() || '').toUpperCase()) + '</span></button>';
      var chat = it.chat || (it.jid ? it.jid.split('@')[0] : '');
      return '<div class="ml-cell mcard' + (msel[it.key] ? ' sel' : '') + '"><label class="ml-ck"><input type="checkbox" data-sel="' + esc(it.key) + '"' + (msel[it.key] ? ' checked' : '') + '></label>'
        + (it.fav ? '<i class="mc-fav">★</i>' : '') + '<div class="mc-media mk-' + esc(it.kind) + '">' + media + '</div>'
        + '<div class="mc-body"><button type="button" class="ml-it mc-name" data-key="' + esc(it.key) + '" dir="auto" title="' + esc(T('Edit')) + '">' + esc(mlNice(it)) + '</button>'
        + '<div class="mc-meta"><span>' + mlSize(it.size) + '</span>' + (it.at ? '<span>' + esc(String(it.at).slice(0, 10)) + '</span>' : '') + (it.used ? '<span>📤 ' + fmt(it.used) + '</span>' : '') + '</div>'
        + (it.from ? '<div class="mc-from" dir="auto" title="' + esc(T('Who sent it')) + '">👤 ' + esc(it.from === 'You' ? T('You') : it.from) + '</div>' : '')
        + (chat ? '<div class="mc-chat" dir="auto">' + (/@g\.us$/.test(it.jid || '') ? '👥 ' : '💬 ') + esc(chat) + '</div>' : '')
        + (it.folder ? '<div class="mc-chat">📁 ' + esc(it.folder) + '</div>' : '')
        + '</div>' + (mbin ? '<div class="mc-acts"><button type="button" data-bulk-one="restore" data-k1="' + esc(it.key) + '">↩️ ' + T('Put back') + '</button></div>' : '<div class="mc-acts">'
          + '<button type="button" data-rn="' + esc(it.key) + '" aria-label="' + esc(T('Rename')) + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16z"/></svg>' + T('Name') + '</button>'
          + '<button type="button" data-send1="' + esc(it.key) + '" data-cap1="' + esc(it.caption || '') + '" aria-label="' + esc(T('Send to chats')) + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 3 3 10.5l7 3 3 7z"/></svg>' + T('Send') + '</button>'
          + '<a class="mc-dl" href="' + u + '&dl=1" aria-label="' + esc(T('Download')) + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 20h14"/></svg>' + T('Download') + '</a>'
          + '<button type="button" class="ml-it" data-key="' + esc(it.key) + '" aria-label="' + esc(T('Edit')) + '" title="' + esc(T('Edit')) + '"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 0 0-.1-1.2l2-1.5-2-3.4-2.3.9a7 7 0 0 0-2-1.2L14 3h-4l-.6 2.6a7 7 0 0 0-2 1.2l-2.3-.9-2 3.4 2 1.5A7 7 0 0 0 5 12c0 .4 0 .8.1 1.2l-2 1.5 2 3.4 2.3-.9c.6.5 1.3.9 2 1.2L10 21h4l.6-2.6c.7-.3 1.4-.7 2-1.2l2.3.9 2-3.4-2-1.5c.1-.4.1-.8.1-1.2z"/></svg>' + T('Settings') + '</button></div>') + '</div>';
    }
    function mlPage(force) {
      var load = mbin ? WI.get('media_lib', { bin: 1 }).then(function (r) { return r.ok ? r.items : []; }) : mlLoad(force);
      Promise.all([load, force || !mfolders.length ? mlFolders() : null]).then(function (res) { var items = res[0];
        document.getElementById('ml-tree').innerHTML = mlTree(); // also while in Deleted, so the way back is always there
        var inF = items.filter(function (i) { return mbin || mf === '' ? true : mf === '__fav' ? i.fav : mf === '__none' ? !i.folder : i.folder === mf; });
        var cnt = {}; inF.forEach(function (i) { cnt[i.kind] = (cnt[i.kind] || 0) + 1; });
        document.getElementById('ml-k').innerHTML = '<button data-k="" class="' + (mk === '' ? 'on' : '') + '">' + T('All') + ' <i>' + fmt(inF.length) + '</i></button>'
          + ML_KINDS.filter(function (k) { return cnt[k[0]]; }).map(function (k) { return '<button data-k="' + k[0] + '" class="' + (mk === k[0] ? 'on' : '') + '">' + k[1] + ' ' + T(k[2]) + ' <i>' + fmt(cnt[k[0]]) + '</i></button>'; }).join('');
        mlChats(inF); inF = inF.filter(function (i) { var d = String(i.at || '').slice(0, 10); return (!mchat || i.jid === mchat) && (!mfrom || d >= mfrom) && (!mto || d <= mto); }); // chat / group and dates
        var list = mlFilter(inF, mq, mk, '');
        list.sort(function (a, b) { return msort === 'big' ? b.size - a.size : msort === 'used' ? b.used - a.used : msort === 'old' ? (a.at > b.at ? 1 : -1) : (b.fav - a.fav) || (a.at < b.at ? 1 : -1); }); ML.shown = list;
        var tot = 0; list.forEach(function (i) { tot += i.size; }); document.getElementById('ml-tot').textContent = fmt(list.length) + ' ' + T('items') + ' · ' + mlSize(tot);
        document.getElementById('ml-crumb').innerHTML = mbin ? '🗑 ' + T('Deleted — select and press «Put back»') : (mf === '' ? '📚 ' + T('All media') : mf === '__fav' ? '★ ' + T('Favourites') : mf === '__none' ? '📥 ' + T('Not in a folder') : '📁 ' + mf.split('/').map(esc).join(' › '));
        var grid = function (arr) { return arr.length ? arr.slice(0, 300).map(function (it) { return mlCard(it); }).join('') : '<div class="empty">' + T('Nothing found.') + '</div>'; };
        document.getElementById('ml-g').innerHTML = mk || mbin ? '<div class="ml-grid in">' + grid(list) + '</div>' : (ML_KINDS.map(function (k) { var part = list.filter(function (i) { return i.kind === k[0]; }); if (!part.length) return '';
          return '<div class="ml-sec"><h3>' + k[1] + ' ' + T(k[2]) + ' <small class="muted">' + fmt(part.length) + '</small>' + (part.length > 24 ? ' <button type="button" class="link-btn" data-k="' + k[0] + '">' + T('Show all') + ' →</button>' : '') + '</h3><div class="ml-grid in">' + grid(part.slice(0, 24)) + '</div></div>'; }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>');
        [].forEach.call(document.querySelectorAll('#ml-g video[data-lz]'), function (v) { if (mlLz) mlLz.observe(v); else v.src = v.dataset.lz; });
        document.querySelector('.ml-v2 .ml-layout').classList.toggle('ed-on', !document.getElementById('ml-ed').hidden);
        var g = document.getElementById('ml-g'); g.classList.toggle('mv-list', mview === 'list'); g.classList.toggle('mv-big', mview === 'big'); g.classList.toggle('mv-det', mdet || mview === 'list');
        mlBar();
      });
    }
    [['ml-chat', function (v) { mchat = v; }], ['ml-from', function (v) { mfrom = v; }], ['ml-to', function (v) { mto = v; }], ['ml-view', function (v) { mview = v; try { localStorage.setItem('wi_ml_view', v); } catch (e) {} }]].forEach(function (p) { var el = document.getElementById(p[0]); if (el) { if (p[0] === 'ml-view') el.value = mview; el.addEventListener('change', function () { p[1](el.value); mlPage(); }); } });
    function segSync() { [].forEach.call(document.querySelectorAll('[data-mv]'), function (b) { b.classList.toggle('on', b.dataset.mv === mview); }); }
    document.addEventListener('click', function (e) { var b = e.target.closest('[data-mv]'); if (!b) return; var sel = document.getElementById('ml-view'); sel.value = b.dataset.mv; sel.dispatchEvent(new Event('change')); segSync(); }); segSync();
    var detB = document.getElementById('ml-det'); if (detB) { detB.classList.toggle('on', mdet); detB.onclick = function () { mdet = !mdet; detB.classList.toggle('on', mdet); try { localStorage.setItem('wi_ml_det', mdet ? '1' : '0'); } catch (e) {} mlPage(); }; }
    function mlBar() { var n = Object.keys(msel).length, b = document.getElementById('ml-bulk'); b.hidden = !n;
      b.innerHTML = '<b>' + fmt(n) + ' ' + T('selected') + '</b>' + (!mbin ? '<a class="btn sm" href="?api=media_zip&keys=' + encodeURIComponent(Object.keys(msel).join(',')) + '">⬇️ ' + T('Download (.zip)') + '</a>' : '') + (mbin ? '<button type="button" class="btn primary sm" data-bulk="restore">↩️ ' + T('Put back') + '</button>'
        : '<button type="button" class="btn primary sm" data-bulk="send">📤 ' + T('Send to chats…') + '</button><button type="button" class="btn ghost sm" data-bulk="move">📁 ' + T('Move to folder…') + '</button>'
          + '<button type="button" class="btn ghost sm" data-bulk="rename">✏️ ' + T('Rename') + '</button><button type="button" class="btn ghost sm" data-bulk="tag">🏷️ ' + T('Add tags') + '</button><button type="button" class="btn ghost sm" data-bulk="caption">✍️ ' + T('Same caption') + '</button><button type="button" class="btn ghost sm" data-bulk="zip">⬇️ ' + T('Download') + '</button><button type="button" class="btn ghost sm" data-bulk="youtube">▶️ YouTube</button><button type="button" class="btn ghost sm" data-bulk="fav">★ ' + T('Favourite') + '</button><button type="button" class="btn ghost sm" data-bulk="unfav">☆</button><button type="button" class="btn ghost sm bad-t" data-bulk="delete">🗑 ' + T('Delete') + '</button>')
        + '<button type="button" class="link-btn" data-bulk="all">' + T('Select all shown') + '</button><button type="button" class="link-btn" data-bulk="none">' + T('Clear') + '</button>'; }
    function mlBulk(do_, folder, extra) { WI.post('media_bulk', Object.assign({ do: do_, keys: Object.keys(msel), folder: folder || '' }, extra || {})).then(function (r) { mlToast(r.ok ? '✓ ' + fmt(r.count) : '⚠️ ' + (r.error || '')); msel = {}; mlPage(true); }); }
    function mlChooseFolder(cb) { // pick a folder (or make a new one) for "move"
      var d = mlDialog('<div class="ml-head"><b>📁 ' + T('Move to folder') + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-fw">'
        + '<button type="button" class="ml-fd" data-pick="">📥 ' + T('Not in a folder') + '</button>' + mfolders.map(function (f) { return '<button type="button" class="ml-fd" data-pick="' + esc(f) + '" style="padding-inline-start:' + (12 + (f.split('/').length - 1) * 16) + 'px">📁 ' + esc(f.split('/').pop()) + ' <small class="muted">' + esc(f) + '</small></button>'; }).join('')
        + '<div class="row"><input id="mcf-new" placeholder="' + esc(T('or a new folder, e.g. Offers/2026')) + '" dir="auto" style="flex:1"><button type="button" class="btn primary sm" id="mcf-go">' + T('Move') + '</button></div></div>');
      d.onclick = function (e) { var b; if (e.target.closest('[data-mlx]')) { d.close(); return; }
        if ((b = e.target.closest('[data-pick]'))) { d.close(); cb(b.dataset.pick); return; }
        if (e.target.closest('#mcf-go')) { var v = document.getElementById('mcf-new').value.trim(); if (!v) return; d.close(); cb(v); } };
    }
    function mlEdClose() { document.getElementById('ml-ed').hidden = true; var bg = document.getElementById('ml-ed-bg'); if (bg) bg.hidden = true; document.querySelector('.ml-v2 .ml-layout').classList.remove('ed-on'); }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !document.getElementById('ml-ed').hidden) mlEdClose(); });
    document.getElementById('ml-ed-bg').addEventListener('click', mlEdClose);
    function mlEdit(it) { mcur = it; var p = document.getElementById('ml-ed'); p.hidden = false; var bg = document.getElementById('ml-ed-bg'); if (bg) bg.hidden = false;
      p.innerHTML = '<button type="button" class="ml-ed-x" id="me-x" aria-label="' + esc(T('Close')) + '">✕</button><div class="ml-prev big">' + (it.kind === 'audio' || it.kind === 'voice' ? '<audio controls src="' + mlUrl(it.key) + '"></audio>' : it.kind === 'video' || it.kind === 'gif' ? '<video controls src="' + mlUrl(it.key) + '"></video>' : mlThumb(it)) + '</div>'
        + (it.from || it.chat ? '<div class="ml-owner" dir="auto">' + (it.from ? '👤 ' + esc(it.from === 'You' ? T('You') : it.from) : '') + (it.chat ? ' · 💬 ' + esc(it.chat) : '') + '</div>' : '')
        + '<div class="ml-form"><label>' + T('Title') + '<input id="me-t" value="' + esc(it.title) + '" dir="auto" maxlength="80"></label>'
        + '<label>' + T('Shortcut — write it in any chat or group and the file is sent') + '<input id="me-code" value="' + esc(it.code || '') + '" dir="auto" maxlength="60" placeholder="' + esc(T('#ruqyah-name')) + '"></label>'
        + '<label>' + T('Caption (sent with it)') + '<textarea id="me-c" rows="4" dir="auto" maxlength="1000">' + esc(it.caption) + '</textarea></label>'
        + '<div class="row wrap"><label>' + T('Folder') + '<select id="me-f"><option value="">📥 ' + T('Not in a folder') + '</option>' + mfolders.map(function (f) { return '<option value="' + esc(f) + '"' + (it.folder === f ? ' selected' : '') + '>' + esc(f.replace(/\//g, ' › ')) + '</option>'; }).join('') + '</select></label>'
        + '<label>' + T('Tags') + '<input id="me-g" value="' + esc(it.tags.join('، ')) + '" dir="auto" placeholder="' + esc(T('separated by commas')) + '"></label></div>'
        + '<label class="check"><input type="checkbox" id="me-v"' + (it.fav ? ' checked' : '') + '> ★ ' + T('Favourite (shown first)') + '</label>'
        + (it.kind === 'video' ? '<div class="ml-ytb">' + (function (y) { if (!y) return '<button type="button" class="btn ghost sm" id="me-yt">▶️ ' + T('Upload to YouTube') + '</button>';
            if (y.state === 'done') return '▶️ <a href="' + esc(y.url) + '" target="_blank" rel="noopener">' + esc(y.url) + '</a> <small class="muted">' + esc(T({ private: 'Private', unlisted: 'Unlisted', public: 'Public' }[y.privacy] || '')) + '</small> <button type="button" class="link-btn" id="me-ytc">📋 ' + T('Copy link') + '</button>';
            if (y.state === 'failed') return '⚠️ ' + esc(y.error || T('Failed')) + ' <button type="button" class="link-btn" id="me-yt">' + T('Try again') + '</button>';
            return '⏳ ' + T(y.state === 'uploading' ? 'Uploading to YouTube…' : 'Waiting to upload to YouTube…'); })(it.youtube) + '</div>' : '')
        + '<p class="muted small">' + T('Sent') + ' ' + fmt(it.used) + ' · ' + esc(when(it.at)) + ' · ' + Math.round(it.size / 1024) + ' KB</p>'
        + '<div class="row wrap"><button type="button" class="btn primary sm" id="me-save">💾 ' + T('Save') + '</button><button type="button" class="btn ghost sm" id="me-send">📤 ' + T('Send to chats…') + '</button><a class="btn ghost sm" href="' + mlUrl(it.key) + '&dl=1' + (it.title ? '&name=' + encodeURIComponent(it.title) : '') + '">⬇️</a><button type="button" class="link-btn bad-t" id="me-hide">🗑 ' + T('Delete') + '</button><span class="muted small" id="me-m"></span></div></div>'; }
    function mlSave(extra) { var b = Object.assign({ key: mcur.key, title: document.getElementById('me-t').value, code: (document.getElementById('me-code') || { value: '' }).value, caption: document.getElementById('me-c').value, folder: document.getElementById('me-f').value, tags: document.getElementById('me-g').value, fav: document.getElementById('me-v').checked ? 1 : 0 }, extra || {});
      return WI.post('media_meta', b).then(function (r) { document.getElementById('me-m').textContent = r.ok ? '✓ ' + T('Saved') : '⚠️ ' + (r.error || ''); if (r.ok) mlPage(true); return r; }); }
    function mlRename(keys) { // name (and caption) of one file, or of several at once (numbered 1, 2, 3…)
      var one = keys.length === 1 ? (ML.items || []).filter(function (i) { return i.key === keys[0]; })[0] : null;
      var d = mlDialog('<div class="ml-head"><b>✏️ ' + T('Rename') + (one ? '' : ' · ' + fmt(keys.length)) + '</b><button type="button" class="btn ghost sm" data-mlx>✕</button></div><div class="ml-form">'
        + (one ? '<div class="ml-prev">' + mlThumb(one) + '</div>' : '')
        + '<label>' + T('Name') + '<input id="mrn-t" maxlength="70" dir="auto" value="' + esc(one ? one.title : '') + '"></label>' + (one ? '' : '<p class="muted small">' + T('Each file gets the name with its number: name 1, name 2…') + '</p>')
        + '<label>' + T('Caption (sent with it)') + '<textarea id="mrn-c" rows="3" dir="auto" maxlength="1000" placeholder="' + esc(one ? '' : T('Leave empty to keep each file\'s caption')) + '">' + esc(one ? one.caption : '') + '</textarea></label>'
        + '<div class="row"><button type="button" class="btn primary sm" id="mrn-go">💾 ' + T('Save') + '</button></div></div>');
      d.onclick = function (e) { if (e.target.closest('[data-mlx]')) d.close(); };
      var t = d.querySelector('#mrn-t'); t.focus(); t.select();
      d.querySelector('#mrn-go').onclick = function () { var n = t.value.trim(), c = d.querySelector('#mrn-c').value; if (!n && !one) { t.focus(); return; }
        var body = one ? { key: one.key, title: n, caption: c } : Object.assign({ do: 'rename', keys: keys, name: n }, c.trim() ? { caption: c } : {});
        WI.post(one ? 'media_meta' : 'media_bulk', body).then(function (r) { if (!r.ok) { mlToast('⚠️ ' + (r.error || '')); return; } d.close(); mlToast('✓ ' + T('Saved')); if (!one) msel = {}; mlPage(true); }); };
    }
    function folderAsk(title, val) { var v = prompt(title, val || ''); return v === null ? null : v.trim(); }
    mroot.addEventListener('change', function (e) { var c = e.target.closest('[data-sel]'); if (!c) return; if (c.checked) msel[c.dataset.sel] = 1; else delete msel[c.dataset.sel]; c.closest('.ml-cell').classList.toggle('sel', c.checked); mlBar(); });
    mroot.addEventListener('click', function (e) { var b;
      if (e.target.closest('.ml-ck')) return;
      if ((b = e.target.closest('[data-rn]'))) { mlRename([b.dataset.rn]); return; }
      if ((b = e.target.closest('[data-send1]'))) { mlForward(b.dataset.send1, b.dataset.cap1 || '', function () { mlToast('✓ ' + T('Sent')); }); return; }
      if ((b = e.target.closest('[data-bulk-one]'))) { WI.post('media_bulk', { keys: [b.dataset.k1], do: 'restore' }).then(function () { mlPage(true); }); return; }
      if ((b = e.target.closest('#ml-k [data-k], .ml-sec [data-k]'))) { mk = b.dataset.k; mlPage(); window.scrollTo(0, 0); return; }
      if ((b = e.target.closest('[data-fd]'))) { mf = b.dataset.fd; mbin = false; msel = {}; mlPage(); return; }
      if (e.target.closest('[data-dupes]')) { mlDupes(); return; }
      if (e.target.closest('[data-inv]')) { mlInventory(); return; }
      if (e.target.closest('[data-bin]')) { mbin = true; mf = ''; msel = {}; mlPage(); return; }
      if ((b = e.target.closest('[data-fnew]'))) { var base = b.dataset.fnew, n = folderAsk(base ? T('Name of the sub-folder inside') + ' «' + base + '»' : T('Name of the new folder'));
        if (n) WI.post('media_folders', { do: 'create', path: (base ? base + '/' : '') + n }).then(function () { mlPage(true); }); return; }
      if ((b = e.target.closest('[data-fren]'))) { var f = b.dataset.fren, nn = folderAsk(T('New name'), f.split('/').pop());
        if (nn) WI.post('media_folders', { do: 'rename', path: f, to: (f.indexOf('/') > -1 ? f.slice(0, f.lastIndexOf('/') + 1) : '') + nn }).then(function () { if (mf === f) mf = ''; mlPage(true); }); return; }
      if ((b = e.target.closest('[data-fdel]'))) { if (confirm(T('Delete this folder and its sub-folders? Their files move up to the parent folder; nothing is deleted.'))) WI.post('media_folders', { do: 'delete', path: b.dataset.fdel }).then(function () { if (mf.indexOf(b.dataset.fdel) === 0) mf = ''; mlPage(true); }); return; }
      if ((b = e.target.closest('[data-bulk]'))) { var d = b.dataset.bulk; if (d === 'rename') { mlRename(Object.keys(msel)); return; }
        if (d === 'all') { (ML.shown || []).slice(0, 300).forEach(function (i) { msel[i.key] = 1; }); mlPage(); return; }
        if (d === 'none') { msel = {}; mlPage(); return; }
        if (d === 'move') { mlChooseFolder(function (f) { mlBulk('move', f); }); return; }
        if (d === 'send') { var ks = Object.keys(msel); if (ks.length > 30) { alert(T('At most 30 files at once.')); return; } mlForward(ks, null, function () { msel = {}; mlPage(true); }); return; }
        if (d === 'tag') { var tg = folderAsk(T('Tags to add to the selected files (separated by commas)')); if (tg) mlBulk('tag', '', { tags: tg }); return; }
        if (d === 'caption') { var cp = prompt(T('Caption for all the selected files (empty = remove)'), ''); if (cp !== null) mlBulk('caption', '', { caption: cp }); return; }
        if (d === 'youtube') { var vids = Object.keys(msel).filter(function (k) { var i = (ML.items || []).filter(function (x) { return x.key === k; })[0]; return i && i.kind === 'video'; });
          if (!vids.length) { alert(T('Choose videos.')); return; } mlYoutube(vids, null, function () { msel = {}; mlPage(true); }); return; }
        if (d === 'zip') { location.href = '?api=media_zip&keys=' + encodeURIComponent(Object.keys(msel).slice(0, 200).join(',')); return; }
        if (d === 'delete' && !confirm(T('Delete the selected files from the library? They stay in the chats and can be put back from «Deleted».'))) return;
        mlBulk(d); return; }
      if ((b = e.target.closest('.ml-it'))) { var it = (ML.shown || []).filter(function (i) { return i.key === b.dataset.key; })[0]; if (it && !mbin) mlEdit(it); return; }
      if (e.target.closest('#me-x')) { mlEdClose(); return; }
      if (e.target.closest('#me-save')) { mlSave().then(function (r) { if (r && r.ok) mlEdClose(); }); return; }
      if (e.target.closest('#me-yt')) { mlYoutube([mcur.key], mcur, function () { mlPage(true); }); return; }
      if (e.target.closest('#me-ytc')) { try { navigator.clipboard.writeText(mcur.youtube.url); mlToast('✓ ' + T('Copied')); } catch (x) { prompt('', mcur.youtube.url); } return; }
      if (e.target.closest('#me-send')) { mlSave().then(function () { mlForward(mcur.key, document.getElementById('me-c').value, function () { mlPage(true); }); }); return; }
      if (e.target.closest('#me-hide') && confirm(T('Delete this file from the library? It stays in the chats and can be put back from «Deleted».'))) { mlSave({ hidden: 1 }).then(function () { mlEdClose(); }); }
    });
    document.getElementById('ml-q').oninput = function () { mq = this.value; mlPage(); };
    var so = document.getElementById('ml-sort'); so.value = msort; so.onchange = function () { msort = this.value; try { localStorage.setItem('wi_ml_sort', msort); } catch (e) {} mlPage(); };
    mlPage(true);
  }
