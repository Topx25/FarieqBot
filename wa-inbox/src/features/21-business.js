  if (page === 'business') {
    root.innerHTML = hero('🏢', 'Business settings', 'What your business is and the words the system uses: what a customer and a record are called, the stages, and any word of the interface. Changes apply to this number only.', '') + '<div id="bz"><div class="empty">' + T('Loading…') + '</div></div>';
    var bz = document.getElementById('bz');
    function bzLoad() { get('business_cfg').then(function (r) { if (!r.ok) return fail(bz, r); var N = r.names, rows = r.terms.slice();
      bz.innerHTML = '<section class="card bz-sec"><div class="bz-h"><span class="jg-n1">1</span><div><b>' + T('Type of business') + '</b><small>' + T('Choosing a type puts back its ready names, stages and words (your own changes on this page are replaced).') + '</small></div></div>'
        + '<div class="bz-types">' + r.types.map(function (x) { return '<button type="button" class="bz-type' + (x.key === r.type ? ' on' : '') + '" data-type="' + x.key + '"><span>' + x.icon + '</span><b>' + esc(T(x.label)) + '</b><small>' + esc(T(x.desc)) + '</small>' + (x.key === r.type ? '<em>✓ ' + T('Current') + '</em>' : '') + '</button>'; }).join('') + '</div></section>'
        + '<section class="card bz-sec"><div class="bz-h"><span class="jg-n1">2</span><div><b>' + T('Names') + '</b><small>' + T('Used in the menu, the pages, the reports and by the AI.') + '</small></div></div>'
        + '<div class="bz-grid">'
        + '<label class="wide">' + T('What your business does') + '<input id="bz-industry" value="' + esc(N.industry) + '" dir="auto" placeholder="' + esc(T('e.g. a shop that sells phones and accessories')) + '"></label>'
        + '<label>' + T('A customer is called') + '<input id="bz-client" value="' + esc(N.client) + '" dir="auto"></label>'
        + '<label>' + T('Your team is called') + '<input id="bz-staff" value="' + esc(N.staff) + '" dir="auto"></label>'
        + '<label>' + T('One record is called') + '<input id="bz-one" value="' + esc(N.one) + '" dir="auto"></label>'
        + '<label>' + T('Many records are called') + '<input id="bz-many" value="' + esc(N.many) + '" dir="auto"></label>'
        + '<label>' + T('Icon') + '<input id="bz-icon" value="' + esc(N.icon) + '" maxlength="8" style="max-width:90px"></label>'
        + '<label>' + T('Follow-up plans are called') + '<input id="bz-program_label" value="' + esc(N.program_label) + '" dir="auto"></label></div>'
        + '<div class="row wrap"><button class="btn primary sm" id="bz-save-n">💾 ' + T('Save names') + '</button>' + (r.can_cases ? '<a class="chip-btn sm" href="?p=cases&tab=settings">🗂️ ' + T('Stages and form fields') + ' →</a>' : '') + '</div></section>'
        + '<section class="card bz-sec"><div class="bz-h"><span class="jg-n1">3</span><div><b>' + T('Words of the interface') + '</b><small>' + T('Any word or sentence of the system can be renamed. Search for it, then write your word.') + '</small></div><span class="jg-pill" id="bz-cnt"></span></div>'
        + '<div class="search-xl bz-find"><span>🔎</span><input type="search" id="bz-q" placeholder="' + esc(T('Search a word of the system, e.g. Cases, Symptoms…')) + '" dir="auto"></div><div class="bz-res" id="bz-res" hidden></div>'
        + '<div class="bz-terms" id="bz-terms"></div><div class="row"><button class="btn primary sm" id="bz-save-t">💾 ' + T('Save the words') + '</button><span class="muted small" id="bz-msg"></span></div></section>'
        + (r.library ? '<section class="card bz-sec"><div class="bz-h"><span class="jg-n1">4</span><div><b>' + T('Library sections') + '</b><small>' + T('Messages your team sends are sorted into these sections by their words. Write the words separated by commas.') + '</small></div></div>'
          + '<div id="bz-lib" class="bz-lib"></div><div class="row"><button type="button" class="chip-btn sm" id="bz-lib-add">➕ ' + T('Add section') + '</button><button class="btn primary sm" id="bz-save-l">💾 ' + T('Save sections') + '</button><span class="muted small" id="bz-lmsg"></span></div></section>' : '')
        + (r.can_ai ? '<p class="muted small">🧠 ' + T('Advanced: what the AI is told about your business —') + ' <a href="?p=learning&tab=ai">' + T('Learning → AI instructions') + '</a></p>' : '');
      function drawT() { document.getElementById('bz-cnt').textContent = rows.length + ' ' + T('changed');
        document.getElementById('bz-terms').innerHTML = rows.length ? '<div class="bz-tr bz-th"><span>' + T('In the system') + '</span><span></span><span>' + T('Your word') + '</span><span></span></div>' + rows.map(function (x, i) { return '<div class="bz-tr"><span class="bz-from" dir="auto" title="' + esc(x.from) + '">' + esc(x.ar || x.from) + '</span><span class="muted">→</span><input data-ti="' + i + '" value="' + esc(x.to) + '" dir="auto"><button type="button" class="link-btn bad-t" data-tx="' + i + '" title="' + esc(T('Back to the system word')) + '">✕</button></div>'; }).join('') : '<div class="muted small">' + T('No word changed yet.') + '</div>'; }
      drawT();
      var LIB = r.library || [];
      function drawL() { var el = document.getElementById('bz-lib'); if (!el) return; el.innerHTML = LIB.map(function (s, i) { return '<div class="bz-lr"><input class="bz-li" data-li="' + i + '" value="' + esc(s.icon) + '" maxlength="4"><input data-ln="' + i + '" value="' + esc(s.name) + '" dir="auto" placeholder="' + esc(T('Section name')) + '"><input data-lw="' + i + '" value="' + esc(s.words) + '" dir="auto" placeholder="' + esc(T('words, separated by commas')) + '"><button type="button" class="link-btn bad-t" data-lx="' + i + '">✕</button></div>'; }).join('') || '<div class="muted small">' + T('No sections — everything goes to «Other».') + '</div>'; }
      function readL() { [].forEach.call(bz.querySelectorAll('[data-ln]'), function (i) { var k = +i.dataset.ln; LIB[k].name = i.value; LIB[k].words = bz.querySelector('[data-lw="' + k + '"]').value; LIB[k].icon = bz.querySelector('[data-li="' + k + '"]').value; }); }
      drawL();
      var qt = null; document.getElementById('bz-q').oninput = function () { var v = this.value.trim(), res = document.getElementById('bz-res'); clearTimeout(qt); if (v.length < 2) { res.hidden = true; return; }
        qt = setTimeout(function () { get('business_cfg', { q: v }).then(function (x) { res.hidden = false; res.innerHTML = (x.items || []).length ? x.items.map(function (it) { return '<button type="button" class="bz-it" data-from="' + esc(it.from) + '" data-ar="' + esc(it.ar) + '"><span dir="auto">' + esc(it.ar) + '</span><small dir="ltr">' + esc(it.from) + '</small></button>'; }).join('') : '<div class="muted small pad">' + T('Nothing found.') + '</div>'; }); }, 300); };
      bz.onclick = function (e) { var b;
        if ((b = e.target.closest('[data-type]'))) { if (b.classList.contains('on')) return; if (!confirm(T('Switch to this type of business? Its ready names, stages and words replace the current ones on this number.'))) return;
          post('business_cfg', { do: 'type', type: b.dataset.type }).then(function (x) { if (x.ok) location.reload(); else toast('⚠️ ' + x.error); }); return; }
        if ((b = e.target.closest('.bz-it'))) { var f = b.dataset.from; if (!rows.some(function (x) { return x.from === f; })) rows.unshift({ from: f, ar: b.dataset.ar, to: b.dataset.ar }); document.getElementById('bz-res').hidden = true; document.getElementById('bz-q').value = ''; drawT(); var i0 = bz.querySelector('[data-ti="0"]'); if (i0) { i0.focus(); i0.select(); } return; }
        if ((b = e.target.closest('[data-tx]'))) { rows.splice(+b.dataset.tx, 1); drawT(); return; }
        if (e.target.closest('#bz-lib-add')) { readL(); LIB.push({ name: '', icon: '📁', words: '' }); drawL(); return; }
        if ((b = e.target.closest('[data-lx]'))) { readL(); LIB.splice(+b.dataset.lx, 1); drawL(); return; }
        if (e.target.closest('#bz-save-l')) { readL(); post('business_cfg', { do: 'library', sections: LIB }).then(function (x) { document.getElementById('bz-lmsg').textContent = x.ok ? '✓ ' + T('Saved — new messages are sorted with it within a minute') : '⚠️ ' + x.error; }); return; }
        if (e.target.closest('#bz-save-n')) { var body = { do: 'names' }; ['industry', 'client', 'staff', 'one', 'many', 'icon', 'program_label'].forEach(function (k) { body[k] = document.getElementById('bz-' + k).value; });
          post('business_cfg', body).then(function (x) { if (x.ok) { toast('✓ ' + T('Saved')); setTimeout(function () { location.reload(); }, 600); } else toast('⚠️ ' + x.error); }); return; }
        if (e.target.closest('#bz-save-t')) { [].forEach.call(bz.querySelectorAll('[data-ti]'), function (i) { rows[+i.dataset.ti].to = i.value; });
          post('business_cfg', { do: 'terms', terms: rows.map(function (x) { return [x.from, x.to]; }) }).then(function (x) { document.getElementById('bz-msg').textContent = x.ok ? '✓ ' + T('Saved — the new words show after the page reloads') : '⚠️ ' + x.error; if (x.ok) setTimeout(function () { location.reload(); }, 900); }); } };
    }); }
    bzLoad();
    return;
  }
