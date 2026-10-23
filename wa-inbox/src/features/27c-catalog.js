  /* ===================== Product catalog ===================== */
  if (page === 'catalog') {
    root.innerHTML = hero('🛍️', 'Product catalog', 'Your products with price, photo and description. The bot answers prices from here and sends the photo of the product asked about; the team sends any product from a chat.', '<button class="btn primary" id="cat-new">＋ ' + T('New product') + '</button>')
      + '<div class="card row wrap" style="gap:10px"><input id="cat-q" placeholder="🔎 ' + esc(T('Search')) + '" style="flex:1;min-width:200px"><span class="muted small" id="cat-n"></span></div><div class="cat-grid" id="cat-list"><div class="empty">' + T('Loading…') + '</div></div><div id="cat-form"></div><div class="card mk" id="mk"><div class="empty">' + T('Loading…') + '</div></div>';
    var CT = { items: [], cur: '' };
    var draw = function () { var q = (document.getElementById('cat-q').value || '').trim().toLowerCase(), L = CT.items.filter(function (p) { return !q || (p.name + ' ' + (p.desc || '') + ' ' + (p.cat || '')).toLowerCase().indexOf(q) >= 0; });
      document.getElementById('cat-n').textContent = fmt(L.length) + ' / ' + fmt(CT.items.length);
      document.getElementById('cat-list').innerHTML = L.length ? L.map(function (p) { return '<div class="cat-it' + (p.on ? '' : ' off') + '"><div class="cat-img">' + (p.img ? '<img loading="lazy" src="?api=catalog_img&id=' + p.id + '&v=' + encodeURIComponent(p.img) + '" alt="">' : '🛍️') + '</div>'
        + '<div class="cat-b"><b dir="auto">' + esc(p.name) + '</b>' + (p.cat ? '<span class="pill">' + esc(p.cat) + '</span>' : '') + '<div class="cat-p">' + (p.price > 0 ? esc(fmt(p.price)) + ' ' + esc(p.cur || CT.cur) : '') + '</div><p class="muted small" dir="auto">' + esc((p.desc || '').slice(0, 140)) + '</p>'
        + '<div class="row"><button class="chip-btn sm" data-ed="' + p.id + '">✏️ ' + T('Edit') + '</button><button class="link-btn" data-del="' + p.id + '">🗑️</button>' + (p.on ? '' : '<span class="muted small">' + T('Hidden from the bot') + '</span>') + '</div></div></div>'; }).join('')
        : '<div class="empty">' + T('No products yet. Add the first one.') + '</div>'; };
    var load = function () { get('catalog').then(function (x) { if (!x.ok) return fail(document.getElementById('cat-list'), x); CT.items = x.items; CT.cur = x.currency; draw(); }); };
    var form = function (p) { p = p || { on: 1 }; var el = document.getElementById('cat-form');
      el.innerHTML = '<div class="modal-bg" id="cat-bg"><form class="card cat-f" id="cat-fm"><h3>' + (p.id ? '✏️ ' + T('Edit product') : '＋ ' + T('New product')) + '</h3><input type="hidden" name="id" value="' + esc(p.id || '') + '">'
        + '<label>' + T('Name') + '<input name="name" required dir="auto" value="' + esc(p.name || '') + '"></label><div class="row"><label style="flex:2">' + T('Price') + '<input name="price" type="number" step="0.01" min="0" value="' + esc(p.price || '') + '"></label><label style="flex:1">' + T('Currency') + '<input name="cur" value="' + esc(p.cur || '') + '" placeholder="' + esc(CT.cur) + '"></label></div>'
        + '<label>' + T('Category') + '<input name="cat" dir="auto" value="' + esc(p.cat || '') + '"></label><label>' + T('Description') + '<textarea name="desc" rows="4" dir="auto">' + esc(p.desc || '') + '</textarea></label><label>' + T('Link (optional)') + '<input name="link" dir="ltr" value="' + esc(p.link || '') + '"></label>'
        + '<label>' + T('Photo') + ' (JPG / PNG / WEBP)<input type="file" name="file" accept="image/jpeg,image/png,image/webp"></label><label class="check"><input type="checkbox" name="on" value="1"' + (p.on ? ' checked' : '') + '> ' + T('The bot may offer it') + '</label>'
        + '<div class="row"><button class="btn primary" type="submit">💾 ' + T('Save') + '</button><button class="btn" type="button" id="cat-x">' + T('Cancel') + '</button></div></form></div>';
      document.getElementById('cat-x').onclick = function () { el.innerHTML = ''; };
      document.getElementById('cat-fm').onsubmit = function (e) { e.preventDefault(); var fd = new FormData(e.target);
        fetch('?api=catalog_save', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': csrf }, body: fd }).then(safeJson, netFail).then(function (r) { if (!r.ok) return toast('⚠️ ' + T(r.error)); toast('✓ ' + T('Saved')); el.innerHTML = ''; load(); }); }; };
    document.getElementById('cat-new').onclick = function () { form(); };
    document.getElementById('cat-q').oninput = draw;
    document.getElementById('cat-list').onclick = function (e) { var b;
      if ((b = e.target.closest('[data-ed]'))) form(CT.items.filter(function (p) { return p.id === b.dataset.ed; })[0]);
      if ((b = e.target.closest('[data-del]')) && confirm(T('Delete this product?'))) post('catalog_del', { id: b.dataset.del }).then(function (r) { if (r.ok) load(); else toast('⚠️ ' + T(r.error)); }); };
    // market: what sets the business apart, an offer for the hesitant, and the competitors — the bot uses them when a customer compares
    var mkRow = function (r) { r = r || { on: 1 }; return '<tr><td><input type="checkbox" data-m="on"' + (r.on ? ' checked' : '') + '></td><td><input data-m="name" dir="auto" value="' + esc(r.name || '') + '" placeholder="' + esc(T('Competitor name')) + '"></td><td><input data-m="prices" dir="auto" value="' + esc(r.prices || '') + '" placeholder="' + esc(T('Their prices')) + '"></td><td><input data-m="weak" dir="auto" value="' + esc(r.weak || '') + '" placeholder="' + esc(T('Where we do better')) + '"></td><td><button type="button" class="link-btn" data-mdel>🗑️</button></td></tr>'; };
    var mkDraw = function (m) { var el = document.getElementById('mk');
      el.innerHTML = '<h3>🏁 ' + T('Market and competitors') + '</h3><p class="muted small">' + T('When a customer compares, names a competitor or hesitates, the bot answers politely with what sets you apart. It never speaks badly of anyone and never invents a discount.') + '</p>'
        + '<label>' + T('What sets us apart') + '<textarea id="mk-edge" rows="4" dir="auto" placeholder="' + esc(T('e.g. original products with a guarantee, delivery in a day, return within 14 days')) + '">' + esc(m.edge || '') + '</textarea></label>'
        + '<label>' + T('What the bot may offer a hesitant customer (empty = nothing)') + '<textarea id="mk-offer" rows="2" dir="auto" placeholder="' + esc(T('e.g. free delivery on the first order')) + '">' + esc(m.offer || '') + '</textarea></label>'
        + '<div class="tbl-wrap"><table class="tbl" id="mk-rv"><thead><tr><th>' + T('On') + '</th><th>' + T('Competitor') + '</th><th>' + T('Their prices') + '</th><th>' + T('Where we do better') + '</th><th></th></tr></thead><tbody>' + (m.rivals || []).map(mkRow).join('') + '</tbody></table></div>'
        + '<div class="row"><button type="button" class="btn sm" id="mk-add">＋ ' + T('Add competitor') + '</button><button type="button" class="btn primary sm" id="mk-save">💾 ' + T('Save') + '</button></div>'
        + '<div class="mk-ai"><h4>🧠 ' + T('AI market analysis') + '</h4><p class="muted small">' + T('Reads your products, what sets you apart, your competitors and what customers wrote in the last 30 days: price objections, competitors they named and why they hesitated. It also runs by itself once a week.') + '</p>'
        + '<div class="row wrap"><input id="mk-focus" dir="auto" style="flex:1;min-width:220px" placeholder="' + esc(T('Optional: what to look at, e.g. why customers say we are expensive')) + '"><button type="button" class="btn primary sm" id="mk-run">🧠 ' + T('Analyse now') + '</button></div><div id="mk-res"></div></div>'; };
    var mkRes = function (a) { var el = document.getElementById('mk-res'); if (!el) return; if (!a) { el.innerHTML = '<p class="muted small">' + T('No analysis yet.') + '</p>'; return; }
      var li = function (arr, f) { return (arr || []).length ? '<ul>' + arr.map(f).join('') + '</ul>' : '<p class="muted small">—</p>'; };
      el.innerHTML = '<div class="muted small">' + esc(when(a.at)) + ' · ' + fmt(a.messages || 0) + ' ' + T('customer messages read') + '</div><p dir="auto">' + esc(a.summary || '') + '</p>'
        + '<h5>' + T('Objections') + '</h5>' + li(a.objections, function (o) { return '<li dir="auto"><b>' + esc(o.what || '') + '</b>' + (o.count ? ' (' + fmt(o.count) + ')' : '') + (o.answer ? '<br><span class="muted">↩ ' + esc(o.answer) + '</span>' : '') + '</li>'; })
        + '<h5>' + T('Competitors customers named') + '</h5>' + li(a.competitors_seen, function (c) { return '<li dir="auto"><b>' + esc(c.name || '') + '</b>' + (c.mentions ? ' (' + fmt(c.mentions) + ')' : '') + ' — ' + esc(c.what || '') + ' <button type="button" class="link-btn" data-mkadd="' + esc(c.name || '') + '">＋ ' + T('Add') + '</button></li>'; })
        + (a.price_notes ? '<h5>' + T('Prices') + '</h5><p dir="auto">' + esc(a.price_notes) + '</p>' : '')
        + (a.edge_suggestion ? '<h5>' + T('Suggested: what sets us apart') + '</h5><p dir="auto">' + esc(a.edge_suggestion) + '</p><button type="button" class="btn sm" data-mkuse="edge">' + T('Use it') + '</button>' : '')
        + (a.offer_suggestion ? '<h5>' + T('Suggested offer') + '</h5><p dir="auto">' + esc(a.offer_suggestion) + '</p><button type="button" class="btn sm" data-mkuse="offer">' + T('Use it') + '</button>' : '')
        + '<h5>' + T('What to do') + '</h5>' + li(a.actions, function (x) { return '<li dir="auto">' + esc(x) + '</li>'; });
      el._a = a; };
    document.getElementById('mk').addEventListener('click', function (e) { var b;
      if (e.target.closest('#mk-add')) document.querySelector('#mk-rv tbody').insertAdjacentHTML('beforeend', mkRow());
      if ((b = e.target.closest('[data-mdel]'))) b.closest('tr').remove();
      if (e.target.closest('#mk-run')) { var bt = e.target.closest('#mk-run'); bt.disabled = true; bt.textContent = '⏳ ' + T('Analysing…'); post('market_ai', { focus: document.getElementById('mk-focus').value }).then(function (r) { bt.disabled = false; bt.textContent = '🧠 ' + T('Analyse now'); if (!r.ok) return toast('⚠️ ' + T(r.error)); mkRes(r.analysis); }); }
      if ((b = e.target.closest('[data-mkuse]'))) { var A = document.getElementById('mk-res')._a || {}; document.getElementById(b.dataset.mkuse === 'edge' ? 'mk-edge' : 'mk-offer').value = b.dataset.mkuse === 'edge' ? A.edge_suggestion : A.offer_suggestion; toast(T('Copied in — press Save')); }
      if ((b = e.target.closest('[data-mkadd]'))) { document.querySelector('#mk-rv tbody').insertAdjacentHTML('beforeend', mkRow({ name: b.dataset.mkadd, on: 1 })); toast(T('Added — press Save')); }
      if (e.target.closest('#mk-save')) { var rv = [].map.call(document.querySelectorAll('#mk-rv tbody tr'), function (tr) { var o = {}; [].forEach.call(tr.querySelectorAll('[data-m]'), function (i) { o[i.dataset.m] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value; }); return o; });
        post('market', { edge: document.getElementById('mk-edge').value, offer: document.getElementById('mk-offer').value, rivals: rv }).then(function (r) { toast(r.ok ? '✓ ' + T('Saved') : '⚠️ ' + T(r.error)); }); } });
    get('market').then(function (m) { if (!m.ok) return fail(document.getElementById('mk'), m); mkDraw(m); get('market_ai').then(function (x) { mkRes(x.ok ? x.analysis : null); }); });
    load();
  }
