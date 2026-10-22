  if (page === 'cases') {
    var C = { list: [], q: '', st: '', statuses: {} };
    var ph = root.dataset.phone;
    if (ph) { caseDetail(ph); return; }
    if (new URLSearchParams(location.search).get('tab') === 'registry') { registry(); return; }
    if (new URLSearchParams(location.search).get('tab') === 'follow') { followBoard(); return; }
    if (new URLSearchParams(location.search).get('tab') === 'new') { newCase(); return; }
    if (new URLSearchParams(location.search).get('tab') === 'settings') { caseSettings(); return; }
    // Opening a case from a group: "#keyword" + details → AI fills the form, asks the writer what is missing, posts the report to the diagnosis group
    function intakeCard() {
      get('case_intake_cfg').then(function (r) { var box = document.getElementById('ci-box'); if (!box) return; if (!r.ok) return fail(box, r); var c = r.cfg, G = r.groups, gk = Object.keys(G);
        box.innerHTML = '<h3>📥 ' + T('Open a case from a group') + '</h3><p class="muted small">' + T('Anyone in the chosen groups writes the keyword with the details. The AI fills the form, asks the writer in the group what is missing, then — answered or not when the time is up — saves the case and posts a copy to the diagnosis group.') + '</p>'
          + '<label class="check"><input type="checkbox" id="ci-on"' + (c.on ? ' checked' : '') + '> ' + T('On') + '</label>'
          + '<label class="check"><input type="checkbox" id="ci-men"' + (c.mention ? ' checked' : '') + '> ' + T('Only when this number is tagged (@ the bot, then the keyword and @ the person)') + '</label>'
          + '<div class="gcf"><label>' + T('Keyword') + '<input id="ci-kw" value="' + esc(c.keyword) + '" dir="auto" maxlength="30"></label>'
          + '<label>' + T('Wait for answers (minutes, 0 = do not ask)') + '<input id="ci-wait" type="number" min="0" max="1440" value="' + (+c.wait_min) + '"></label>'
          + '<label class="gcf-wide">' + T('Diagnosis group (gets the report)') + '<select id="ci-diag"><option value="">' + T('The first group of the case registry') + (r.diag && G[r.diag] ? ' — ' + esc(G[r.diag]) : '') + '</option>' + gk.map(function (j) { return '<option value="' + esc(j) + '"' + (c.diag === j ? ' selected' : '') + '>' + esc(G[j]) + '</option>'; }).join('') + '</select></label></div>'
          + '<h4>' + T('Groups where the keyword works') + ' <small class="muted">' + T('none chosen = every group except the diagnosis group') + '</small></h4><div class="ci-g">' + gk.map(function (j) { return '<label class="check"><input type="checkbox" data-cig="' + esc(j) + '"' + (c.groups.indexOf(j) >= 0 ? ' checked' : '') + '> ' + esc(G[j]) + '</label>'; }).join('') + '</div>'
          + '<h4>' + T('Ask the writer when missing') + '</h4><div class="ci-g">' + Object.keys(r.fields).map(function (k) { return '<label class="check"><input type="checkbox" data-cia="' + k + '"' + (c.ask.indexOf(k) >= 0 ? ' checked' : '') + '> ' + esc(r.fields[k]) + '</label>'; }).join('') + '</div>'
          + '<label class="check"><input type="checkbox" id="ci-conf"' + (c.confirm ? ' checked' : '') + '> ' + T('Tell the writer when the file is opened') + '</label>'
          + (r.waiting ? '<p class="muted small">⏳ ' + fmt(r.waiting) + ' ' + T('waiting for answers') + '</p>' : '')
          + '<p class="muted small">' + T('The wording of the questions and the confirmation is in Settings → Message templates; the AI instructions in AI prompts.') + '</p>'
          + '<div class="row"><button class="btn primary" id="ci-save">💾 ' + T('Save') + '</button></div>';
        box.querySelector('#ci-save').onclick = function () { var v = function (s) { return box.querySelector(s); }, pick = function (a) { return [].map.call(box.querySelectorAll('[' + a + ']:checked'), function (i) { return i.getAttribute(a); }); };
          post('case_intake_cfg', { on: v('#ci-on').checked ? 1 : 0, keyword: v('#ci-kw').value, wait_min: v('#ci-wait').value, diag: v('#ci-diag').value, groups: pick('data-cig'), ask: pick('data-cia'), confirm: v('#ci-conf').checked ? 1 : 0, mention: v('#ci-men').checked ? 1 : 0 })
            .then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); }); }; });
    }
    // Case settings of this number: what a case is called, the stage names, which form fields and their names
    function caseSettings() {
      root.innerHTML = hero('⚙️', 'Case settings', 'What a case means on this number: a patient, a support ticket or any follow-up. The words and fields below are used in the form, the list and the report.') + '<div class="card" id="cc-box"><div class="empty">' + T('Loading…') + '</div></div><div class="card" id="ci-box"></div>';
      intakeCard();
      get('case_cfg').then(function (r) { if (!r.ok) return fail(document.getElementById('cc-box'), r); var C = r.cfg, P = r.presets;
        function draw(c) { document.getElementById('cc-box').innerHTML = '<div class="gcf">'
          + '<label class="gcf-wide">' + T('Start from') + '<select id="cc-pre">' + Object.keys(P).map(function (k) { return '<option value="' + k + '"' + (c.preset === k ? ' selected' : '') + '>' + esc(T(P[k].label)) + '</option>'; }).join('') + '</select></label>'
          + '<label>' + T('One item is called') + '<input id="cc-one" value="' + esc(c.one) + '" dir="auto"></label><label>' + T('Many are called') + '<input id="cc-many" value="' + esc(c.many) + '" dir="auto"></label><label>' + T('Icon') + '<input id="cc-icon" value="' + esc(c.icon) + '" style="width:70px"></label></div>'
          + '<h3>' + T('Stages') + '</h3><div class="gcf">' + Object.keys(c.statuses).map(function (k) { return '<label>' + esc({ new: T('Stage 1 — new'), follow: T('Stage 2 — being worked on'), improving: T('Stage 3 — progressing / waiting'), closed: T('Stage 4 — finished'), postponed: T('Postponed'), removed: T('Removed'), archived: T('Archived') }[k] || k) + '<input data-cs="' + k + '" value="' + esc(c.statuses[k]) + '" dir="auto"></label>'; }).join('') + '</div>'
          + '<h3>' + T('Form fields') + '</h3><div class="cc-fields">' + Object.keys(c.fields).map(function (k) { var x = c.fields[k], fixed = k === 'name' || k === 'phone';
              return '<div class="cc-f"><label class="inv-sw"><input type="checkbox" data-fo="' + k + '"' + (x.on || fixed ? ' checked' : '') + (fixed ? ' disabled' : '') + '><i></i></label><input data-fl="' + k + '" value="' + esc(x.label) + '" dir="auto"></div>'; }).join('') + '</div>'
          + '<div class="row" style="margin-top:12px"><button class="btn primary" id="cc-save">💾 ' + T('Save') + '</button><span class="muted small" id="cc-msg"></span></div>'; }
        draw(C);
        document.getElementById('cc-box').addEventListener('change', function (e) { if (e.target.id === 'cc-pre') { var p = P[e.target.value]; draw({ preset: e.target.value, one: p.one, many: p.many, icon: p.icon, statuses: p.statuses, fields: p.fields }); } });
        document.getElementById('cc-box').addEventListener('click', function (e) { if (!e.target.closest('#cc-save')) return; var q = function (s) { return document.querySelector(s); }, st = {}, fl = {};
          [].forEach.call(document.querySelectorAll('[data-cs]'), function (i) { st[i.dataset.cs] = i.value; });
          [].forEach.call(document.querySelectorAll('[data-fl]'), function (i) { var o = q('[data-fo="' + i.dataset.fl + '"]'); fl[i.dataset.fl] = { label: i.value, on: o && o.checked ? 1 : 0 }; });
          post('case_cfg', { preset: q('#cc-pre').value, one: q('#cc-one').value, many: q('#cc-many').value, icon: q('#cc-icon').value, statuses: st, fields: fl }).then(function (x) { toast(x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error); if (x.ok) setTimeout(function () { location.href = '?p=cases'; }, 600); }); });
      });
    }
    root.innerHTML = hero(((window.WI_PACK || {}).case_cfg || {}).icon || '🩺', ((window.WI_PACK || {}).case_cfg || {}).many || 'Cases', 'People in the groups you monitor (e.g. a case-reporting group): every message is archived, each person gets a case record, AI analysis and follow-up.',
      '<div class="search-xl"><span>🔎</span><input type="search" id="cs-q" placeholder="' + T('Search name, number, complaint…') + '" dir="auto"></div><button class="chip-btn" id="cs-sync">🔄 ' + T('Update now') + '</button>')
      + '<div class="page-tabs sub"><a href="?p=cases" class="on">' + (((window.WI_PACK || {}).case_cfg || {}).icon || '🩺') + ' ' + (((window.WI_PACK || {}).case_cfg || {}).many || T('Followed cases')) + '</a><a href="?p=cases&tab=registry">📋 ' + T('Case registry') + '</a><a href="?p=cases&tab=follow">📊 ' + T('Follow-up & analysis') + '</a><a href="?p=cases&tab=settings">⚙️ ' + T('Case settings') + '</a><a href="?p=cases&tab=new">➕ ' + T('New case') + '</a></div>'
      + '<div class="card"><div class="seg" id="cs-st"></div><div id="cs-groups" class="muted small" style="margin:8px 0"></div><div id="cs-fwrap"></div><div id="cs-list" class="cs-list cs-cards"><div class="empty">' + T('Loading…') + '</div></div></div>';
    // 📊 Follow-up & analysis: every case with its last analysis by angle, stage, progress and trend
    function followBoard() { var CC = (window.WI_PACK || {}).case_cfg || {};
      root.innerHTML = hero('📊', 'Follow-up & analysis', 'Each case looked at from every angle, the stage of the treatment and the progress over time. Press Analyse, or write the analysis command with the case ID in a group.')
        + '<div class="page-tabs sub"><a href="?p=cases">' + (CC.icon || '🩺') + ' ' + (CC.many || T('Followed cases')) + '</a><a href="?p=cases&tab=registry">📋 ' + T('Case registry') + '</a><a href="?p=cases&tab=follow" class="on">📊 ' + T('Follow-up & analysis') + '</a></div>'
        + '<div class="fu-k" id="fu-k"></div><div class="card"><div class="row wrap gt-bar"><div class="search-xl"><span>🔎</span><input type="search" id="fu-q" placeholder="' + T('Search name, number…') + '" dir="auto"></div><div class="mt2-chips" id="fu-f"><button type="button" class="chip-btn on" data-ff="">' + T('All') + '</button><button type="button" class="chip-btn" data-ff="worse">📉 ' + T('Getting worse') + '</button><button type="button" class="chip-btn" data-ff="flags">⚠️ ' + T('Needs attention') + '</button><button type="button" class="chip-btn" data-ff="none">❔ ' + T('Not analysed yet') + '</button></div></div><div id="fu-list" class="fu-grid"><div class="empty">' + T('Loading…') + '</div></div></div>';
      var F = { rows: [], axes: [], f: '' };
      function spark(h) { if (!h || h.length < 2) return ''; var w = 90, ht = 24, mx = 100, pts = h.map(function (v, i) { return (i * w / (h.length - 1)).toFixed(1) + ',' + (ht - v / mx * ht).toFixed(1); }).join(' '); return '<svg class="fu-sp" viewBox="0 0 ' + w + ' ' + ht + '" width="' + w + '" height="' + ht + '"><polyline fill="none" stroke="currentColor" stroke-width="2" points="' + pts + '"/></svg>'; }
      function draw() { var q = (document.getElementById('fu-q').value || '').toLowerCase();
        var rows = F.rows.filter(function (r) { return (!q || (r.name + ' ' + r.phone + ' ' + r.no).toLowerCase().indexOf(q) !== -1) && (!F.f || (F.f === 'worse' ? r.trend === 'worse' : F.f === 'flags' ? !!r.flags : r.progress === null)); });
        var an = F.rows.filter(function (r) { return r.progress !== null; }), avg = an.length ? Math.round(an.reduce(function (a, r) { return a + r.progress; }, 0) / an.length) : 0;
        document.getElementById('fu-k').innerHTML = [['🗂️', F.rows.length, T('Cases')], ['🔬', an.length, T('Analysed')], ['📈', avg + '%', T('Average progress')], ['📉', F.rows.filter(function (r) { return r.trend === 'worse'; }).length, T('Getting worse')], ['⚠️', F.rows.filter(function (r) { return r.flags; }).length, T('Needs attention')]].map(function (k) { return '<div class="mt2-k"><span>' + k[0] + '</span><b>' + k[1] + '</b><small>' + k[2] + '</small></div>'; }).join('');
        document.getElementById('fu-list').innerHTML = rows.length ? rows.map(function (r) { var tr = { better: '📈', same: '➖', worse: '📉' }[r.trend] || '❔', pc = r.progress === null ? '' : r.progress;
          return '<div class="fu-c' + (r.trend === 'worse' ? ' worse' : '') + (r.flags ? ' flag' : '') + '"><div class="fu-h"><b dir="auto">' + esc(r.name || '+' + r.phone) + '</b><small class="muted">ID ' + esc(r.no) + ' · ' + esc(r.status) + '</small></div>'
            + (r.progress === null ? '<div class="muted small">' + T('Not analysed yet') + '</div>' : '<div class="fu-p"><div class="fu-bar"><i style="width:' + pc + '%"></i></div><b>' + pc + '%</b> ' + tr + spark(r.history) + '</div><div class="small">📍 ' + esc(r.stage) + '</div>'
              + '<div class="fu-ax">' + (r.axes || []).map(function (a) { return '<span title="' + esc(a.state) + '">' + esc(a.name) + ' <b>' + a.score + '/10</b></span>'; }).join('') + '</div>'
              + (r.flags ? '<div class="small bad-t">⚠️ ' + esc(r.flags) + '</div>' : '') + (r.next ? '<div class="small">👣 ' + esc(r.next) + '</div>' : '') + '<details class="small"><summary>' + T('Summary') + '</summary><p dir="auto">' + esc(r.summary) + '</p></details><small class="muted">' + T('Analysed') + ' ' + esc(when(r.at)) + '</small>')
            + '<div class="row"><button class="btn sm primary" data-an="' + esc(r.phone) + '">🔬 ' + T('Analyse now') + '</button><button type="button" class="chip-btn sm" data-tr="' + esc(r.phone) + '">🧭 ' + T('Follow-up trail') + '</button><a class="chip-btn sm" href="?p=cases&q=' + encodeURIComponent(r.phone) + '">' + T('Open') + '</a></div></div>'; }).join('') : '<div class="empty">' + T('Nothing found.') + '</div>'; }
      function load() { get('case_follow').then(function (r) { if (!r.ok) return fail(document.getElementById('fu-list'), r); F.rows = r.rows; F.axes = r.axes; if (!r.axes.length) { document.getElementById('fu-list').innerHTML = '<div class="empty">' + T('Analysis by angle is not set for this business type (Business profile → analysis_axes).') + '</div>'; return; } draw(); }); }
      document.getElementById('fu-q').oninput = draw;
      root.addEventListener('click', function (e) { var f = e.target.closest('[data-ff]'); if (f) { F.f = f.dataset.ff; [].forEach.call(document.querySelectorAll('[data-ff]'), function (b) { b.classList.toggle('on', b === f); }); draw(); return; }
        var tb = e.target.closest('[data-tr]'); if (tb) { WI.trailPanel(tb.dataset.tr); return; }
        var b = e.target.closest('[data-an]'); if (b) { b.disabled = true; b.textContent = '⏳ ' + T('AI is analysing…'); post('case_deep', { phone: b.dataset.an }).then(function (x) { if (!x.ok) { b.disabled = false; b.textContent = '⚠️ ' + T(x.error || 'Failed'); return; } load(); }); } });
      load(); }
    function newCase() {
      var CF = ((window.WI_PACK || {}).case_cfg || {}).fields || {}, off = function (id) { return CF[id] && !CF[id].on; }, nm = function (id, label) { return CF[id] ? esc(CF[id].label) : T(label); };
      var f = function (id, label, ph, type) { if (off(id)) return ''; return '<label>' + nm(id, label) + '<input id="nc-' + id + '" ' + (type ? 'type="' + type + '"' : '') + ' placeholder="' + esc(ph || '') + '" dir="auto"></label>'; };
      var ta = function (id, label, ph, rows) { if (off(id)) return ''; return '<label class="gcf-wide">' + nm(id, label) + '<textarea id="nc-' + id + '" rows="' + (rows || 3) + '" dir="auto" placeholder="' + esc(T(ph || '')) + '"></textarea></label>'; };
      root.innerHTML = hero('➕', 'New case', 'Fill in the case: it is saved as a case under follow-up, gets its own file, and a copy of the report is posted to the diagnosis group.')
        + '<div class="page-tabs sub"><a href="?p=cases">' + (((window.WI_PACK || {}).case_cfg || {}).icon || '🩺') + ' ' + (((window.WI_PACK || {}).case_cfg || {}).many || T('Followed cases')) + '</a><a href="?p=cases&tab=registry">📋 ' + T('Case registry') + '</a><a href="?p=cases&tab=follow">📊 ' + T('Follow-up & analysis') + '</a><a href="?p=cases&tab=settings">⚙️ ' + T('Case settings') + '</a><a href="?p=cases&tab=new" class="on">➕ ' + T('New case') + '</a></div>'
        + '<div class="nc-wrap"><div class="card nc-form"><h3>👤 ' + T('Basic information') + '</h3><div class="gcf">'
        + '<div class="gcf-wide nc-pick"><label>📥 ' + T('Reception group') + '<select id="nc-rgroup"><option value="">' + T('Loading…') + '</option></select></label>'
        + '<label>🔎 ' + T('Choose the visitor') + '<input id="nc-vq" type="search" placeholder="' + T('Search name or number…') + '" dir="auto" disabled></label><div class="nc-vlist" id="nc-vlist"></div></div>'
        + '<label>' + T('Name') + '<input id="nc-name" readonly placeholder="' + T('chosen from the reception group') + '" dir="auto"></label>'
        + '<label>' + T('Phone number') + '<input id="nc-phone" readonly placeholder="' + T('chosen from the reception group') + '" dir="ltr"></label>'
        + (off('join') ? '' : '<label>' + nm('join', 'Join date') + '<input type="date" id="nc-join" value="' + new Date().toISOString().slice(0, 10) + '"></label>') + f('age', 'Age', PK('ex_age'))
        + (off('marital') ? '' : '<label>' + nm('marital', 'Marital status') + '<select id="nc-marital"><option value="">—</option>' + PK('marital').split('\n').filter(Boolean).map(function (o) { return '<option>' + esc(o) + '</option>'; }).join('') + '</select></label>')
        + f('kids', 'Number of children', PK('ex_kids')) + (off('country') ? '' : '<label>' + nm('country', 'Country') + '<input id="nc-country" list="nc-countries" dir="auto" placeholder="' + esc(T('Choose or type')) + '">' + countryList('nc-countries') + '</label>') + '</div>'
        + '<h3>👥 ' + T('Follow-up group and follower') + '</h3><div class="gcf">'
        + '<label>' + T('Follow-up group') + '<select id="nc-tgroup"><option value="">' + T('Loading…') + '</option></select></label>'
        + '<label>' + T('Followed by (group admins)') + '<select id="nc-follower" disabled><option value="">' + T('Choose the group first') + '</option></select></label></div>'
        + '<h3>📝 ' + T('Case details') + '</h3><div class="gcf">'
        + ta('story', 'Story / history', 'What they describe, what was done before, notes…', 5)
        + ta('symptoms', 'Confirmed symptoms (one per line)', PK('ex_points'), 6)
        + ta('extra', 'Extra symptoms', '', 2) + '</div>'
        + '<h3>🩺 ' + T('Diagnosis') + '</h3><div class="gcf">' + f('diagnosis', 'Diagnosis', PK('ex_assessment')) + f('diag_by', 'Diagnosed by', '') + f('placement', 'Placement note', T('filled from the group'))
        + '<label>' + T('Status') + '<select id="nc-status"><option value="follow">🩺 ' + T('Under follow-up') + '</option><option value="new">🆕 ' + T('New') + '</option></select></label></div>'
        + '<div class="row wrap nc-send"><label class="check"><input type="checkbox" id="nc-send" checked> 📤 ' + T('Post a copy of the report to the group') + '</label><select id="nc-group"></select></div>'
        + '<div class="row"><button class="btn primary" id="nc-save">💾 ' + T('Save the case') + '</button><span class="muted small" id="nc-msg"></span></div></div>'
        + '<div class="card nc-prev"><h3>👁 ' + T('Report preview') + '</h3><pre id="nc-pre" dir="auto"></pre></div></div>';
      var v = function (k) { var e = document.getElementById('nc-' + k); return e ? e.value.trim() : ''; };
      // Name and number come only from the visitors (non-admins) of the reception group.
      var VIS = [], HAVE = {}, VF = '';
      // Numbers that already have a case (followed or in the registry) are marked, so nobody is entered twice.
      get('cases').then(function (r) { (r.cases || []).forEach(function (c) { HAVE[c.phone] = { st: c.status || 'new', name: c.name || '' }; (c.lids || []).forEach(function (l) { HAVE[l] = HAVE[c.phone]; }); }); drawV(); });
      get('registry').then(function (r) { (r.entries || []).forEach(function (e) { (e.phones || []).forEach(function (p) { if (!HAVE[p]) HAVE[p] = { st: 'registry', name: e.name_guess || '' }; }); }); drawV(); });
      function drawV() { if (!document.getElementById('nc-vq')) return; var q = (document.getElementById('nc-vq').value || '').toLowerCase(), cur = v('phone').replace(/\D/g, '');
        var all = VIS.filter(function (x) { return !q || (x.name + ' ' + x.phone).toLowerCase().indexOf(q) !== -1; });
        var nOld = all.filter(function (x) { return HAVE[x.phone]; }).length;
        var rows = all.filter(function (x) { return VF === 'old' ? HAVE[x.phone] : VF === 'new' ? !HAVE[x.phone] : true; }).slice(0, 80);
        document.getElementById('nc-vlist').innerHTML = (VIS.length ? '<div class="nc-vf"><button type="button" class="chip-btn sm' + (VF === '' ? ' on' : '') + '" data-vf="">' + T('All') + ' (' + fmt(all.length) + ')</button><button type="button" class="chip-btn sm' + (VF === 'new' ? ' on' : '') + '" data-vf="new">🆕 ' + T('New visitors') + ' (' + fmt(all.length - nOld) + ')</button><button type="button" class="chip-btn sm' + (VF === 'old' ? ' on' : '') + '" data-vf="old">✔ ' + T('Already a case') + ' (' + fmt(nOld) + ')</button></div>' : '')
          + rows.map(function (x) { var h = HAVE[x.phone];
            return '<button type="button" class="chip-btn sm' + (x.phone === cur ? ' on' : '') + (h ? ' nc-old' : '') + '" data-vis="' + esc(x.phone) + '" data-n="' + esc(x.name || '') + '"' + (h ? ' data-have="' + esc(h.st) + '"' : '') + '>' + (h ? '✔ ' : '👤 ') + esc(x.name || h && h.name || T('No name')) + ' <span dir="ltr">+' + esc(x.phone) + '</span>' + (h ? ' <em>' + (h.st === 'registry' ? T('in the registry') : T('has a case')) + '</em>' : '') + '</button>'; }).join('') || (VIS.length ? '<span class="muted small">' + T('No results.') + '</span>' : ''); }
      function loadV(gj) { var vq = document.getElementById('nc-vq'); VIS = []; vq.disabled = !gj; document.getElementById('nc-vlist').innerHTML = gj ? '<span class="muted small">' + T('Loading…') + '</span>' : '';
        try { localStorage.setItem('wi_nc_rgroup', gj); } catch (e) {}
        if (!gj) return; get('meeting_targets', { group: gj, ctx: 'cases' }).then(function (m) { VIS = (m.members || []).filter(function (x) { return !x.admin; }); drawV(); }); }
      document.getElementById('nc-rgroup').onchange = function () { loadV(this.value); };
      document.getElementById('nc-vq').oninput = drawV;
      document.getElementById('nc-vlist').onclick = function (e) { var f = e.target.closest('[data-vf]'); if (f) { VF = f.dataset.vf; drawV(); return; }
        var b = e.target.closest('[data-vis]'); if (!b) return;
        if (b.dataset.have && b.dataset.have !== 'registry' && confirm(T('This number already has a case. Open its record instead?'))) { location.href = '?p=cases&phone=' + encodeURIComponent(b.dataset.vis); return; }
        document.getElementById('nc-name').value = b.dataset.n || ''; document.getElementById('nc-name').readOnly = !!b.dataset.n; document.getElementById('nc-phone').value = '+' + b.dataset.vis; var ncC = document.getElementById('nc-country'); if (ncC && !ncC.value.trim()) ncC.value = countryOf(b.dataset.vis); drawV(); preview(); };
      // Treatment groups → their admins are the people who can follow the case.
      get('meeting_targets', { ctx: 'cases' }).then(function (r) { var rs = document.getElementById('nc-rgroup'), saved = ''; try { saved = localStorage.getItem('wi_nc_rgroup') || ''; } catch (e) {}
        var guess = saved || ((r.groups || []).filter(function (g) { var rp = PK('reception_pattern'); try { return rp && new RegExp(rp, 'i').test(g.name); } catch (e) { return false; } })[0] || {}).jid || '';
        rs.innerHTML = '<option value="">' + T('Choose the reception group…') + '</option>' + (r.groups || []).map(function (g) { return '<option value="' + esc(g.jid) + '"' + (g.jid === guess ? ' selected' : '') + '>' + esc(g.name) + ' (' + g.size + ')</option>'; }).join('');
        if (guess) loadV(guess);
        var sel = document.getElementById('nc-tgroup');
        sel.innerHTML = '<option value="">' + T('Choose the follow-up group…') + '</option>' + (r.groups || []).map(function (g) { return '<option value="' + esc(g.jid) + '">' + esc(g.name) + ' (' + g.size + ')</option>'; }).join(''); });
      document.getElementById('nc-tgroup').onchange = function () { var fs = document.getElementById('nc-follower'), gname = this.options[this.selectedIndex].text.replace(/\s*\(\d+\)$/, '');
        fs.disabled = true; fs.innerHTML = '<option value="">' + (this.value ? T('Loading…') : T('Choose the group first')) + '</option>';
        var pl = document.getElementById('nc-placement'); if (this.value && (!pl.value || pl.dataset.auto)) { var fh = PK('female_hint'), tpl = (fh && document.getElementById('nc-marital').value.indexOf(fh) !== -1 ? PK('placement_f') : PK('placement_m')) || '{group}'; pl.value = tpl.replace('{group}', gname); pl.dataset.auto = 1; }
        if (!this.value) { preview(); return; }
        get('meeting_targets', { group: this.value, ctx: 'cases' }).then(function (m) { var ad = (m.members || []).filter(function (x) { return x.admin; });
          fs.innerHTML = ad.length ? '<option value="">' + T('Choose who follows the case…') + '</option>' + ad.map(function (x) { return '<option value="' + esc(x.name || ('+' + x.phone)) + '" data-ph="' + esc(x.phone) + '">' + esc(x.name || '') + ' · +' + esc(x.phone) + '</option>'; }).join('') : '<option value="">' + T('No admins found in this group') + '</option>';
          fs.disabled = !ad.length; preview(); }); };
      document.getElementById('nc-placement').oninput = function () { delete this.dataset.auto; };
      get('registry').then(function (r) { var g = r && r.groups ? Object.keys(r.groups) : [], sel = document.getElementById('nc-group');
        sel.innerHTML = g.length ? g.map(function (k) { return '<option value="' + esc(k) + '">' + esc(r.groups[k].name || k) + '</option>'; }).join('') : '<option value="">' + T('No diagnosis group chosen yet') + '</option>'; });
      // Preview = the exact report the server will post (message template "case_report"), asked for after a short pause in typing.
      var pvT = null;
      function preview() { clearTimeout(pvT); pvT = setTimeout(function () { var body = { preview: 1 }; ['name', 'phone', 'join', 'age', 'marital', 'kids', 'country', 'follower', 'story', 'symptoms', 'extra', 'diagnosis', 'diag_by', 'placement'].forEach(function (k) { body[k] = v(k); });
        post('case_new', body).then(function (x) { if (x && x.ok && typeof x.report === 'string') document.getElementById('nc-pre').textContent = x.report; }); }, 500); }
      root.addEventListener('input', preview); root.addEventListener('change', preview); preview();
      document.getElementById('nc-save').onclick = function () { var b = this, msg = document.getElementById('nc-msg');
        if (v('phone').replace(/\D/g, '').length < 8) { msg.textContent = '⚠️ ' + T('Choose the visitor from the reception group.'); return; }
        if (!v('name')) { msg.textContent = '⚠️ ' + T('This visitor has no name on WhatsApp — write the name.'); return; }
        var body = {}; ['name', 'phone', 'join', 'age', 'marital', 'kids', 'country', 'follower', 'tgroup', 'story', 'symptoms', 'extra', 'diagnosis', 'diag_by', 'placement', 'status', 'group'].forEach(function (k) { body[k] = v(k); });
        body.send = document.getElementById('nc-send').checked ? 1 : 0;
        var fo = document.getElementById('nc-follower'); body.follower_phone = fo.selectedIndex > 0 ? fo.options[fo.selectedIndex].dataset.ph || '' : '';
        var tg = document.getElementById('nc-tgroup'); body.tgroup_name = tg.selectedIndex > 0 ? tg.options[tg.selectedIndex].text.replace(/\s*\(\d+\)$/, '') : '';
        if (!body.tgroup || !body.follower) { msg.textContent = '⚠️ ' + T('Choose the follow-up group and who follows the case.'); return; }
        if (body.send && !confirm(T('Post this report to the group now?'))) return;
        b.disabled = true; msg.textContent = T('Saving…');
        post('case_new', body).then(function (x) { b.disabled = false;
          if (!x.ok) { msg.textContent = '⚠️ ' + (x.error || T('Failed')); return; }
          msg.innerHTML = '✓ ' + T('Case saved and its file created') + (x.sent === true ? ' · 📤 ' + T('posted to') + ' ' + esc(x.group) : x.sent === false ? ' · ⚠️ ' + T('could not post to the group') : '') + ' · <a href="?p=cases&phone=' + encodeURIComponent(x.phone) + '">' + T('Open the case') + ' →</a>'; }); };
    }
    function load(sync) {
      get('cases', sync ? { sync: 1 } : {}).then(function (r) {
        if (!r.ok) return fail(document.getElementById('cs-list'), r);
        C.list = r.cases; C.statuses = r.statuses;
        if (!document.getElementById('cs-f')) { var gm = {}; C.list.forEach(function (c) { Object.keys(c.groups || {}).forEach(function (k) { gm[k] = c.groups[k]; }); }); var lb = {}; C.list.forEach(function (c) { (c.labels || []).forEach(function (l) { lb[l] = 1; }); });
          document.getElementById('cs-fwrap').innerHTML = fbar('cs-f', { sort: [['last', 'Last message (newest)'], ['first', 'First seen (newest)'], ['msgs', 'Most messages'], ['name', 'Name'], ['number', 'Number']], groups: gm, dates: true, extra: Object.keys(lb).length ? '<label>🏷️ <select data-f="label"><option value="">' + T('All labels') + '</option>' + Object.keys(lb).map(function (l) { return '<option>' + esc(l) + '</option>'; }).join('') + '</select></label>' : '' }); fwire('cs-f', draw); }
        var g = Object.keys(r.groups || {});
        document.getElementById('cs-groups').innerHTML = g.length ? '👥 ' + T('Monitored groups') + ': ' + g.map(function (k) { return '<b>' + esc(r.groups[k].name || k) + '</b>'; }).join(' · ') + ' — <a href="?p=groups">' + T('change') + '</a>'
          : '⚠️ ' + T('No monitored group yet.') + ' <a href="?p=groups">' + T('Choose the case groups in Groups →') + '</a>';
        var counts = r.counts || {};
        document.getElementById('cs-st').innerHTML = '<button data-s="" class="' + (C.st ? '' : 'on') + '">' + T('All') + ' <i>' + fmt(C.list.length - (counts.archived || 0)) + '</i></button>' + Object.keys(C.statuses).map(function (k) { return '<button data-s="' + k + '" class="' + (C.st === k ? 'on' : '') + '">' + T(C.statuses[k]) + ' <i>' + fmt(counts[k] || 0) + '</i></button>'; }).join('');
        draw();
      });
    }
    function draw() {
      var q = C.q.toLowerCase();
      var f = fval('cs-f'), srt = f.sort || 'last';
      var rows = C.list.filter(function (c) { return (C.st ? (c.status || 'new') === C.st : c.status !== 'archived') && (!f.group || (c.groups || {})[f.group]) && (!f.label || (c.labels || []).indexOf(f.label) !== -1) && inRange(c.last, f.from, f.to) && (!q || JSON.stringify([c.name, c.phone, c.data, c.last_text]).toLowerCase().indexOf(q) !== -1); })
        .sort(function (a, b) { return srt === 'first' ? String(b.first).localeCompare(String(a.first)) : (srt === 'msgs' ? (b.msgs || 0) - (a.msgs || 0) : (srt === 'name' ? String(a.name || '~').localeCompare(String(b.name || '~')) : (srt === 'number' ? String(a.phone).localeCompare(String(b.phone)) : String(b.last).localeCompare(String(a.last))))); });
      var P = pg('cases', rows, draw, 50);
      document.getElementById('cs-list').innerHTML = P.rows.map(function (c) {
        var d = c.data || {};
        // One card per case: the whole card, the name and every label open the case record.
        var dg = d.diagnosis || (c.ai && c.ai.diagnosis) || '';
        return '<a class="cs-card st-' + esc(c.status || 'new') + '" href="?p=cases&phone=' + encodeURIComponent(c.phone) + '" title="' + T('Open the case record') + '">'
          + '<div class="cs-top">' + avatar(c.phone + '@s.whatsapp.net', c.name || c.phone) + '<div class="cs-who"><b dir="auto">' + (c.no ? '<span class="cs-id">ID ' + c.no + '</span> ' : '') + esc(c.name || ('+' + c.phone)) + '</b><span class="muted small" dir="ltr">+' + esc(c.phone) + '</span></div><span class="pill st-' + esc(c.status || 'new') + '">' + T(C.statuses[c.status || 'new'] || '') + '</span></div>'
          + '<div class="cs-lbls">' + (dg ? '<span class="lbl dg">🩺 ' + esc(String(dg).slice(0, 40)) + '</span>' : '') + (c.labels || []).map(function (l) { return '<span class="lbl">🏷️ ' + esc(l) + '</span>'; }).join('') + (c.customer ? '<span class="lbl">⭐ ' + T('Customer') + '</span>' : '') + (c.manual ? '<span class="lbl">📝 ' + T('From the form') + '</span>' : '') + '</div>'
          + '<div class="cs-txt" dir="auto">' + esc(d.complaint || d.story || c.last_text || '') + '</div>'
          + '<div class="cs-meta"><span>💬 ' + fmt(c.msgs || 0) + '</span>' + (c.media ? '<span>📎 ' + fmt(c.media) + '</span>' : '') + '<span>📅 ' + esc(day(c.first || c.created)) + (c.last ? ' → ' + esc(day(c.last)) : '') + '</span>' + (c.ai ? '<span>🤖 ' + esc(c.ai.progress || '') + '</span>' : '') + '<span class="cs-go">' + T('Open') + ' ←</span></div></a>';
      }).join('') || '<div class="empty">' + T('No cases yet.') + '</div>';
      document.getElementById('cs-list').insertAdjacentHTML('beforeend', P.html);
    }
    load(false);
    document.getElementById('cs-sync').onclick = function () { this.disabled = true; load(true); };
    document.getElementById('cs-q').oninput = function () { C.q = this.value; pgReset('cases'); draw(); }; (function () { var q0 = new URLSearchParams(location.search).get('q'); if (q0) { var b = document.getElementById('cs-q'); b.value = q0; b.oninput(); } })(); // opened from the search suggestions
    document.getElementById('cs-st').onclick = function (e) { var b = e.target.closest('button'); if (!b) return; C.st = b.dataset.s; load(false); };
    return;
  }
