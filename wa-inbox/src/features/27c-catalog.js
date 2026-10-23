  /* ===================== Product catalog ===================== */
  if (page === 'catalog') {
    root.innerHTML = hero('🛍️', 'Product catalog', 'Your products with price, photo and description. The bot answers prices from here and sends the photo of the product asked about; the team sends any product from a chat.', '<button class="btn primary" id="cat-new">＋ ' + T('New product') + '</button>')
      + '<div class="card row wrap" style="gap:10px"><input id="cat-q" placeholder="🔎 ' + esc(T('Search')) + '" style="flex:1;min-width:200px"><span class="muted small" id="cat-n"></span></div><div class="cat-grid" id="cat-list"><div class="empty">' + T('Loading…') + '</div></div><div id="cat-form"></div>';
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
    load();
  }
