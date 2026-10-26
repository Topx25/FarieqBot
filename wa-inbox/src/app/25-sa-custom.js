/* System overview: the cards below the numbers can be folded, hidden and reordered (kept in this browser) */
(function () {
  var box = document.querySelector('[data-sa-home]'); if (!box) return;
  var KEY = 'wi_sa_layout', st = {};
  try { st = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { st = {}; }
  st.order = st.order || []; st.hide = st.hide || {}; st.fold = st.fold || {};
  var cards = [].slice.call(box.querySelectorAll('.card')).filter(function (c) { return c.querySelector(':scope>.card-h h2'); });
  cards.forEach(function (c, i) { c.dataset.sk = 'c' + i; c.classList.add('sa-card'); if (c.querySelector('.sa-kpis') || c.id === 'ai-cost' || !c.closest('.sa-row')) c.classList.add('sa-wide'); });
  var save = function () { try { localStorage.setItem(KEY, JSON.stringify(st)); } catch (e) {} };
  var title = function (c) { return c.querySelector(':scope>.card-h h2').textContent.trim(); };
  var keys = function () { var all = cards.map(function (c) { return c.dataset.sk; }); return st.order.filter(function (k) { return all.indexOf(k) > -1; }).concat(all.filter(function (k) { return st.order.indexOf(k) < 0; })); };
  var paint = function () { keys().forEach(function (k, i) { var c = box.querySelector('[data-sk="' + k + '"]'); c.style.order = i; c.hidden = !!st.hide[k]; c.classList.toggle('sa-folded', !!st.fold[k]); }); };
  cards.forEach(function (c) { var h = c.querySelector(':scope>.card-h'); h.classList.add('sa-fold-h');
    h.addEventListener('click', function (e) { if (e.target.closest('a,button,input,select,label')) return; st.fold[c.dataset.sk] = !st.fold[c.dataset.sk]; save(); paint(); }); });
  var bar = document.createElement('div'); bar.className = 'sa-cbar';
  var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'btn ghost sm'; btn.textContent = '⚙ ' + box.dataset.lCustom; bar.appendChild(btn);
  var pane = document.createElement('div'); pane.className = 'card sa-cpane'; pane.hidden = true; bar.appendChild(pane);
  box.parentNode.insertBefore(bar, box);
  var list = function () {
    var h = '<p class="muted small"></p><ul>'; keys().forEach(function (k, i, a) { h += '<li data-k="' + k + '"><label><input type="checkbox"' + (st.hide[k] ? '' : ' checked') + '> <span></span></label><button type="button" class="btn ghost sm" data-mv="-1"' + (i ? '' : ' disabled') + '>↑</button><button type="button" class="btn ghost sm" data-mv="1"' + (i < a.length - 1 ? '' : ' disabled') + '>↓</button></li>'; });
    pane.innerHTML = h + '</ul><div class="row" style="gap:8px"><button type="button" class="btn primary sm" data-done></button><button type="button" class="btn ghost sm" data-reset></button></div>';
    pane.querySelector('p').textContent = box.dataset.lHint; pane.querySelector('[data-done]').textContent = box.dataset.lDone; pane.querySelector('[data-reset]').textContent = box.dataset.lReset;
    [].forEach.call(pane.querySelectorAll('li'), function (li) { li.querySelector('span').textContent = title(box.querySelector('[data-sk="' + li.dataset.k + '"]')); });
  };
  btn.addEventListener('click', function () { pane.hidden = !pane.hidden; if (!pane.hidden) list(); });
  pane.addEventListener('change', function (e) { var li = e.target.closest('li'); if (!li) return; if (e.target.checked) delete st.hide[li.dataset.k]; else st.hide[li.dataset.k] = 1; save(); paint(); });
  pane.addEventListener('click', function (e) {
    if (e.target.closest('[data-done]')) { pane.hidden = true; return; }
    if (e.target.closest('[data-reset]')) { st = { order: [], hide: {}, fold: {} }; save(); paint(); list(); return; }
    var mv = e.target.closest('[data-mv]'); if (!mv) return; var k = mv.closest('li').dataset.k, o = keys(), i = o.indexOf(k), j = i + (+mv.dataset.mv);
    if (j < 0 || j >= o.length) return; o.splice(i, 1); o.splice(j, 0, k); st.order = o; save(); paint(); list();
  });
  paint();
})();
