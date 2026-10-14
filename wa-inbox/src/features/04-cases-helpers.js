  function caseDetail(ph) {
    root.innerHTML = '<div class="empty">' + T('Loading…') + '</div>';
    get('case', { phone: ph }).then(function (r) {
      if (!r.ok) return fail(root, r);
      var c = r.case, d = c.data || {}, ai = c.ai, CC = r.case_cfg || {}, CF = CC.fields || {}, st = c.status || 'new', keys = Object.keys(r.statuses);
      // Fields: this number's case form (Cases → Settings) plus older fields that already hold something; never name / phone (they are in the header).
      var long = { symptoms: 1, extra: 1, story: 1, notes: 1, complaint: 1, diagnosis: 1 }, seen = {}, fields = [];
      var add = function (k, label) { if (seen[k] || k === 'name' || k === 'phone') return; seen[k] = 1; fields.push([k, label]); };
      add('complaint', T('Complaint'));
      Object.keys(CF).forEach(function (k) { if (CF[k].on) add(k, CF[k].label || k); });
      [['symptoms', 'Symptoms'], ['diagnosis', 'Assessment'], ['program', 'Program'], ['age', 'Age'], ['country', 'Country'], ['started', 'Follow-up started']].forEach(function (f) { if (String(d[f[0]] || '').trim()) add(f[0], T(f[1])); });
      add('gender', T('Gender')); // optional choice, always offered
      if (!String(d.country || '').trim() && countryOf(ph)) d.country = countryOf(ph); // filled from the phone number until someone changes it
      add('notes', T('Case notes'));
      var shortF = fields.filter(function (f) { return !long[f[0]]; }), longF = fields.filter(function (f) { return long[f[0]]; });
      var dates = { join: 1, started: 1 };
      var inp = function (f) { var k = f[0], v = d[k] || '', el;
        if (long[k]) el = '<textarea data-k="' + k + '" rows="3" dir="auto">' + esc(v) + '</textarea>';
        else if (dates[k]) el = '<input type="date" data-k="' + k + '" value="' + esc(isoDate(v)) + '">';
        else if (k === 'gender') { var g = genderOf(v); el = '<select data-k="gender"><option value="">— ' + T('Not set') + '</option><option value="' + esc(T('Male')) + '"' + (g === 'm' ? ' selected' : '') + '>' + T('Male') + '</option><option value="' + esc(T('Female')) + '"' + (g === 'f' ? ' selected' : '') + '>' + T('Female') + '</option></select>'; }
        else if (k === 'marital') { var ms = PK('marital').split('\n').map(function (x) { return x.trim(); }).filter(Boolean); if (!ms.length) ms = [T('Single'), T('Married'), T('Divorced'), T('Widowed')]; if (v && ms.indexOf(v) === -1) ms.push(v); // choices: Cases → Settings / business profile
          el = '<select data-k="marital"><option value="">— ' + T('Not set') + '</option>' + ms.map(function (o) { return '<option' + (o === v ? ' selected' : '') + '>' + esc(o) + '</option>'; }).join('') + '</select>'; }
        else if (k === 'country') el = '<input data-k="country" list="cd-countries" value="' + esc(v) + '" dir="auto" placeholder="' + esc(T('Choose or type')) + '">' + countryList('cd-countries');
        else if (k === 'age') el = '<input type="number" min="0" max="120" data-k="age" value="' + esc(String(v).replace(/[^0-9]/g, '')) + '">';
        else el = '<input data-k="' + k + '" value="' + esc(v) + '" dir="auto">';
        return '<label class="cd2-f' + (long[k] ? ' wide' : '') + '"><span>' + esc(f[1]) + '</span>' + el + '</label>'; };
      var noteTxt = function (s) { return String(s || '').replace(/^Case opened from a private chat: /, T('Case opened from a private chat') + ': ').replace(/^From the private chat: /, T('From the private chat') + ': '); };
      var msgs = r.messages || [], nPriv = msgs.filter(function (m) { return m.src !== 'group'; }).length;
      var stepIdx = Math.max(0, keys.indexOf(st));
      var h = '<a href="?p=cases" class="link">← ' + T('All cases') + '</a>'
        + '<section class="card cd2-head st-' + esc(st) + '"><div class="cd2-who">' + avatar(ph + '@s.whatsapp.net', c.name || ph) + '<div><h1 dir="auto">' + (c.no ? '<span class="cs-id">ID ' + c.no + '</span> ' : '') + esc(c.name || ('+' + ph)) + '</h1>'
        + '<div class="cd2-meta"><span dir="ltr">📞 +' + esc(ph) + '</span><span>💬 ' + fmt(c.msgs || msgs.length) + ' ' + T('messages') + '</span>' + (c.first ? '<span>📅 ' + esc(day(c.first)) + (c.last ? ' → ' + esc(day(c.last)) : '') + '</span>' : '') + (c.manual ? '<span>📝 ' + T('From the form') + '</span>' : '') + '</div></div></div>'
        + '<div class="cd2-acts"><button class="btn primary sm" id="cd-ai">🤖 ' + T(ai ? 'Analyse again' : 'Analyse with AI') + '</button><a class="btn ghost sm" href="?p=inbox&jid=' + encodeURIComponent(ph + '@s.whatsapp.net') + '">💬 ' + T('Private chat') + '</a>'
        + '<div class="tool-menu cd2-more"><button type="button" class="btn ghost sm" id="cd-more">⋯</button><div class="menu" hidden><a href="?api=case_export&phone=' + encodeURIComponent(ph) + '">⬇️ ' + T('Export record') + '</a><button type="button" class="link-btn" id="cd-team" title="' + esc(T('Admin / therapist / team member — not a patient')) + '">👤 ' + T('Not a case (team)') + '</button></div></div></div>'
        // the stages as a clickable progress line
        + '<div class="cd2-steps">' + keys.map(function (k, i) { return '<button type="button" class="cd2-step' + (i < stepIdx ? ' done' : '') + (k === st ? ' on' : '') + '" data-st="' + esc(k) + '"><i>' + (i < stepIdx ? '✓' : i + 1) + '</i><span>' + esc(T(r.statuses[k])) + '</span></button>'; }).join('') + '</div></section>';
      if (r.registry && r.registry.length) h += '<details class="card cd2-reg"><summary>📋 ' + T('Registry reports') + ' <b>' + fmt(r.registry.length) + '</b></summary>' + r.registry.map(regEntry).join('') + '</details>';
      h += '<div class="cd2-grid"><div class="cd2-main">'
        + '<section class="card cd2-card"><div class="cd2-h"><h3>📋 ' + T('Case data') + '</h3><span class="muted small" id="cd-msg">⚡ ' + T('Saved automatically') + '</span></div>'
        + (shortF.length ? '<div class="cd2-short">' + shortF.map(inp).join('') + '</div>' : '') + '<div class="cd2-long">' + longF.map(inp).join('') + '</div></section>'
        + ((c.voices || []).length ? '<section class="card cd2-card"><div class="cd2-h"><h3>🎙️ ' + T('Voice recordings') + '</h3><span class="muted small">' + fmt(c.voices.length) + '</span></div>'
          + c.voices.map(function (v) { var p = String(v.file || '').split('/'); return '<div class="cd2-v"><audio controls preload="none" src="?api=media&a=' + encodeURIComponent(p[1] || '') + '&f=' + encodeURIComponent(p[2] || '') + '"></audio><small class="muted">' + esc(v.by || '') + (v.at ? ' · ' + esc(when(v.at)) : '') + '</small></div>'; }).join('') + '</section>' : '')
        + '<section class="card cd2-card"><div class="cd2-h"><h3>📝 ' + T('Follow-up notes') + '</h3><span class="muted small">' + fmt((c.notes || []).length) + '</span></div>'
        + '<div class="cd2-add"><textarea id="cd-note" rows="1" placeholder="' + esc(T('Add a follow-up note…')) + '" dir="auto"></textarea><button class="btn primary sm" id="cd-add">➕ ' + T('Add') + '</button></div>'
        + '<div class="cd2-tl">' + ((c.notes || []).slice().reverse().map(function (n) { var bot = n.by === 'bot'; return '<div class="cd2-n' + (bot ? ' bot' : '') + '"><i>' + (bot ? '🤖' : '👤') + '</i><div><div dir="auto">' + esc(noteTxt(n.text)) + '</div><small class="muted">' + esc(bot ? T('Bot') : n.by) + ' · ' + esc(when(n.at)) + '</small></div></div>'; }).join('') || '<div class="muted small">' + T('No notes yet.') + '</div>') + '</div></section></div>'
        + '<aside class="cd2-side"><section class="card cd2-card cd2-ai"><div class="cd2-h"><h3>🤖 ' + T('AI analysis') + '</h3>' + (ai && ai.at ? '<span class="muted small">' + esc(when(ai.at)) + '</span>' : '') + '</div>'
        + (ai ? aiBox(ai) : '<div class="cd2-empty"><span>🤖</span><b>' + T('Not analysed yet') + '</b><small>' + T('The AI reads all the conversations and writes a summary, the complaint, the symptoms and the progress.') + '</small><button class="btn primary sm" id="cd-ai2">' + T('Analyse with AI') + '</button></div>') + '</section></aside></div>';
      h += '<section class="card cd2-card"><div class="cd2-h"><h3>💬 ' + T('All conversations') + ' <span class="muted small">' + fmt(msgs.length) + (r.snapshot_at ? ' · ' + T('copy saved in the record') + ' ' + esc(when(r.snapshot_at)) : '') + '</span></h3>'
        + '<div class="row wrap"><div class="seg sm" id="cd-mf"><button data-f="" class="on">' + T('All') + ' <i>' + fmt(msgs.length) + '</i></button><button data-f="p">🔒 ' + T('Private chat') + ' <i>' + fmt(nPriv) + '</i></button><button data-f="g">👥 ' + T('Groups') + ' <i>' + fmt(msgs.length - nPriv) + '</i></button></div><div class="search-xl"><span>🔎</span><input type="search" id="cd-mq" placeholder="' + esc(T('Search the conversations…')) + '" dir="auto"></div></div></div>'
        + '<div class="cd-msgs cd2-msgs" id="cd-msgs"></div></section>';
      root.innerHTML = h;
      // conversations: filter by place and text
      var mf = '', mq = '', mbox = document.getElementById('cd-msgs');
      function drawMsgs() { var L = msgs.slice(-800).filter(function (m) { return (!mf || (mf === 'g') === (m.src === 'group')) && (!mq || String(m.text || '').toLowerCase().indexOf(mq) !== -1); });
        mbox.innerHTML = L.map(function (m) { var wl = { own: '', reply: '↩️ ' + T('reply to them'), mention: '@ ' + T('mentions them'), private: '' }[m.why || 'own'] || '', out = m.dir === 'out' || (m.why && m.why !== 'own' && m.why !== 'private');
          return '<div class="cd-m ' + (out ? 'out' : 'in') + '"><small class="muted">' + (m.src === 'group' ? '👥 ' + esc(m.chat) : '🔒 ' + T('Private chat')) + ' · ' + esc(when(m.time)) + (m.why && m.why !== 'own' && m.why !== 'private' ? ' · ' + esc(m.from_name || ('+' + (m.from || ''))) + ' · ' + wl : '') + (m.dir === 'out' && m.by ? ' · ' + esc(m.by) : '') + '</small><div dir="auto">' + (String(m.text || '').trim() ? esc(m.text) : '<i>[' + esc(m.kind || '') + ']</i>') + '</div></div>'; }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>';
        mbox.scrollTop = mbox.scrollHeight; }
      drawMsgs();
      document.getElementById('cd-mf').onclick = function (e) { var b = e.target.closest('[data-f]'); if (!b) return; mf = b.dataset.f; [].forEach.call(this.children, function (x) { x.classList.toggle('on', x === b); }); drawMsgs(); };
      document.getElementById('cd-mq').oninput = function () { mq = this.value.trim().toLowerCase(); drawMsgs(); };
      function save(extra) { var data = {}; [].forEach.call(root.querySelectorAll('[data-k]'), function (i) { data[i.dataset.k] = i.value; }); return post('case_set', Object.assign({ phone: ph, data: data }, extra || {})); }
      // case data: saved by itself a moment after a change
      var tmr = null, msg = document.getElementById('cd-msg');
      var onEdit = function (e) { if (!e.target.dataset.k) return; clearTimeout(tmr); msg.textContent = T('Saving…');
        tmr = setTimeout(function () { save({}).then(function (x) { msg.textContent = x.ok ? '✓ ' + T('Saved automatically') + ' · ' + new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : '⚠️ ' + x.error; }); }, 800); };
      root.querySelector('.cd2-main').addEventListener('input', onEdit); root.querySelector('.cd2-main').addEventListener('change', onEdit); // selects and date pickers send "change"
      root.querySelector('.cd2-steps').onclick = function (e) { var b = e.target.closest('[data-st]'); if (!b || b.dataset.st === st) return;
        save({ status: b.dataset.st }).then(function (x) { if (x.ok) { toast(x.snapshot != null ? '✓ ' + T('Under follow-up: all conversations copied to the record') + ' (' + fmt(x.snapshot) + ')' : '✓ ' + T('Saved')); caseDetail(ph); } else toast('⚠️ ' + x.error); }); };
      var note = document.getElementById('cd-note'); note.oninput = function () { this.style.height = 'auto'; this.style.height = Math.min(160, this.scrollHeight) + 'px'; };
      note.onkeydown = function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) document.getElementById('cd-add').click(); };
      document.getElementById('cd-add').onclick = function () { var n = note.value.trim(); if (!n) { note.focus(); return; } this.disabled = true; save({ note: n }).then(function () { caseDetail(ph); }); };
      document.getElementById('cd-more').onclick = function () { var m = this.parentNode.querySelector('.menu'); m.hidden = !m.hidden; };
      document.getElementById('cd-team').onclick = function () { if (!confirm(T('Mark this number as a team member? It is removed from cases and never added automatically again (a copy is kept).'))) return; post('case_team', { phone: ph }).then(function (x) { if (x.ok) location.href = '?p=cases'; else toast('⚠️ ' + x.error); }); };
      var runAi = function () { var b = document.getElementById('cd-ai'); b.disabled = true; b.textContent = '⏳ ' + T('AI is analysing…'); post('case_analyze', { phone: ph }).then(function (x) { if (x.ok) caseDetail(ph); else { b.disabled = false; b.textContent = '🤖 ' + T('Analyse with AI'); toast('⚠️ ' + (x.error || T('Failed'))); } }); };
      document.getElementById('cd-ai').onclick = runAi; if (document.getElementById('cd-ai2')) document.getElementById('cd-ai2').onclick = runAi;
    });
  }
  function regEntry(e) {
    var a = e.ai && !e.ai.skipped ? e.ai : null;
    return '<div class="reg-e"><div class="reg-h"><b dir="auto">' + esc((a && a.name) || e.name_guess || T('Unnamed')) + '</b> ' + e.phones.map(function (p) { return '<span class="tag" dir="ltr">+' + esc(p) + '</span>'; }).join(' ')
      + ' <small class="muted">' + esc(when(e.time)) + ' · 👥 ' + esc(e.group_name || '') + (e.poster_name ? ' · ✍️ ' + esc(e.poster_name) : '') + '</small></div>'
      + (a ? '<div class="reg-ai"><span>🩺 <b>' + T('Diagnosis') + ':</b> <span dir="auto">' + esc(a.diagnosis || '—') + '</span></span>' + (a.complaint ? '<span>🗣️ <span dir="auto">' + esc(a.complaint) + '</span></span>' : '') + ([a.gender, a.age, a.country].filter(Boolean).length ? '<span>👤 ' + esc([a.gender, a.age, a.country].filter(Boolean).join(' · ')) + '</span>' : '') + '</div>' : '')
      + '<div class="reg-t" dir="auto">' + esc(String(e.text || '').replace(/[ \t]+\n/g, '\n').replace(/\n{3,}/g, '\n\n').trim()) + '</div><div class="reg-items">' + e.items.map(itemHtml).join('') + '</div>'
      + '<div class="reg-l">' + (e.labels || []).map(function (l) { return '<span class="lbl">🏷️ ' + esc(l) + '</span>'; }).join('') + '</div></div>';
  }
  function registry() {
    var RG = { list: [], sel: {}, q: '', label: '', only: '' };
    root.innerHTML = hero('📋', 'Case registry', 'Every case posted in your case-registry groups (name, number, voice note, information, diagnosis) — one entry per case, with labels. Select one or many to follow them.',
      '<div class="search-xl"><span>🔎</span><input type="search" id="rg-q" placeholder="' + T('Search name, number, diagnosis…') + '" dir="auto"></div><button class="chip-btn" id="rg-scan">🔄 ' + T('Read the group again') + '</button><button class="chip-btn" id="rg-ai">🤖 ' + T('Extract details with AI') + '</button><span class="muted small" id="rg-ai-st"></span>')
      + '<div class="page-tabs sub"><a href="?p=cases">' + (((window.WI_PACK || {}).case_cfg || {}).icon || '🩺') + ' ' + (((window.WI_PACK || {}).case_cfg || {}).many || T('Followed cases')) + '</a><a href="?p=cases&tab=registry" class="on">📋 ' + T('Case registry') + '</a></div>'
      + '<div class="card"><div id="rg-groups" class="muted small"></div><div id="rg-fwrap"></div><div class="rg-labels" id="rg-labels"></div>'
      + '<div class="rg-bar"><label class="check"><input type="checkbox" id="rg-all"> ' + T('Select all shown') + '</label><b id="rg-n"></b><button class="btn primary sm" id="rg-follow" disabled>🩺 ' + T('Follow selected') + '</button><button class="chip-btn sm" id="rg-lbl" disabled>🏷️ ' + T('Add label') + '</button><button class="chip-btn sm" id="rg-unlbl" disabled>✂️ ' + T('Remove label') + '</button><button class="chip-btn sm" id="rg-ai-sel" disabled>🤖 ' + T('Re-analyse selected') + '</button>'
      + '<div class="seg sm" id="rg-only"><button data-o="" class="on">' + T('All') + '</button><button data-o="new">' + T('Not followed') + '</button><button data-o="f">🩺 ' + T('Followed') + '</button></div></div><div id="rg-list" class="rg-list"><div class="empty">' + T('Loading…') + '</div></div></div>';
    function load(scan) {
      get('registry', scan ? { scan: 1 } : {}).then(function (r) {
        if (!r.ok) return fail(document.getElementById('rg-list'), r);
        RG.list = r.entries; RG.labels = r.labels;
        if (!document.getElementById('rg-f')) { var gm = {}; r.entries.forEach(function (e) { gm[e.group] = e.group_name || e.group; }); document.getElementById('rg-fwrap').innerHTML = fbar('rg-f', { sort: [['new', 'Newest first'], ['old', 'Oldest first'], ['name', 'Name'], ['number', 'Number']], groups: gm, dates: true }); fwire('rg-f', draw); }
        var g = Object.keys(r.groups || {});
        document.getElementById('rg-groups').innerHTML = g.length ? '📋 ' + T('Registry groups') + ': ' + g.map(function (k) { return '<b>' + esc(r.groups[k].name || k) + '</b>'; }).join(' · ') + ' · ' + fmt(r.entries.length) + ' ' + T('cases') + (r.pending_ai ? ' · 🤖 ' + fmt(r.pending_ai) + ' ' + T('not analysed yet') : '')
          : '⚠️ ' + T('No registry group yet.') + ' <a href="?p=groups">' + T('Open the group in Groups and switch on “Case registry group”.') + '</a>';
        drawL(); draw();
      });
    }
    function drawL() {
      var ks = Object.keys(RG.labels || {});
      document.getElementById('rg-labels').innerHTML = ks.length ? '<button class="lbl' + (RG.label ? '' : ' on') + '" data-l="">' + T('All labels') + '</button>' + ks.map(function (k) { return '<button class="lbl' + (RG.label === k ? ' on' : '') + '" data-l="' + esc(k) + '">🏷️ ' + esc(k) + ' <i>' + fmt(RG.labels[k]) + '</i></button>'; }).join('') : '';
    }
    function shown() {
      var q = RG.q.toLowerCase();
      var f = fval('rg-f'), srt = f.sort || 'new';
      var nm = function (e) { return String((e.ai && e.ai.name) || e.name_guess || '~'); };
      return RG.list.filter(function (e) { return (!f.group || e.group === f.group) && inRange(e.time, f.from, f.to) && (!RG.label || (e.labels || []).indexOf(RG.label) !== -1) && (!RG.only || (RG.only === 'f' ? e.case : !e.case)) && (!q || JSON.stringify([e.text, e.phones, e.ai, e.name_guess, e.labels]).toLowerCase().indexOf(q) !== -1); })
        .sort(function (a, b) { return srt === 'old' ? String(a.time).localeCompare(String(b.time)) : (srt === 'name' ? nm(a).localeCompare(nm(b)) : (srt === 'number' ? String(a.phones[0]).localeCompare(String(b.phones[0])) : String(b.time).localeCompare(String(a.time)))); });
    }
    function bar() { var n = Object.keys(RG.sel).length; document.getElementById('rg-n').textContent = n ? fmt(n) + ' ' + T('selected') : ''; ['rg-follow', 'rg-lbl', 'rg-unlbl', 'rg-ai-sel'].forEach(function (id) { document.getElementById(id).disabled = !n; }); }
    function draw() {
      var P = pg('registry', shown(), draw, 30);
      document.getElementById('rg-list').innerHTML = P.html + P.rows.map(function (e) {
        var a = e.ai && !e.ai.skipped ? e.ai : null;
        return '<div class="rg-row rg-card' + (RG.sel[e.id] ? ' picked' : '') + '" data-open="' + esc(e.case || '') + '" data-oid="' + esc(e.id) + '" title="' + T('Open the case record') + '"><label class="check"><input type="checkbox" data-pick="' + esc(e.id) + '" ' + (RG.sel[e.id] ? 'checked' : '') + '></label><div class="rg-b">' + regEntry(e)
          + (e.case ? '<a class="chip-btn sm on" href="?p=cases&phone=' + encodeURIComponent(e.case) + '">🩺 ' + T('Case page') + (e.case_status ? ' · ' + T((window.WF_ST || {})[e.case_status] || e.case_status) : '') + '</a>' : '<button class="chip-btn sm" data-follow1="' + esc(e.id) + '">🩺 ' + T('Follow') + '</button>') + '</div></div>';
      }).join('') || '<div class="empty">' + T('No cases yet.') + '</div>';
      document.getElementById('rg-list').insertAdjacentHTML('beforeend', P.html);
      bar();
    }
    function act(body, msg) { body.ids = Object.keys(RG.sel); return post('registry_set', body).then(function (x) { if (!x.ok) { toast('⚠️ ' + x.error); return x; } toast('✓ ' + (msg || T('Saved'))); RG.sel = {}; load(false); return x; }); }
    root.addEventListener('change', function (e) {
      var c = e.target.closest('[data-pick]'); if (c) { if (c.checked) RG.sel[c.dataset.pick] = 1; else delete RG.sel[c.dataset.pick]; c.closest('.rg-row').classList.toggle('picked', c.checked); bar(); return; }
      if (e.target.id === 'rg-all') { shown().forEach(function (x) { if (e.target.checked) RG.sel[x.id] = 1; else delete RG.sel[x.id]; }); draw(); }
    });
    root.addEventListener('click', function (e) {
      var l = e.target.closest('[data-l]'); if (l) { RG.label = l.dataset.l; drawL(); draw(); return; }
      var o = e.target.closest('#rg-only [data-o]'); if (o) { RG.only = o.dataset.o; [].forEach.call(o.parentNode.children, function (x) { x.classList.toggle('on', x === o); }); draw(); return; }
      var card = e.target.closest('.rg-card');
      if (card && !e.target.closest('input,button,a,label,[data-pick],audio,video,select,textarea')) {
        if (card.dataset.open) { location.href = '?p=cases&phone=' + encodeURIComponent(card.dataset.open); return; }
        var fb = card.querySelector('[data-follow1]'); if (fb) fb.click(); return;
      }
      var f1 = e.target.closest('[data-follow1]'); if (f1) { RG.sel = {}; RG.sel[f1.dataset.follow1] = 1; f1.disabled = true; f1.textContent = '⏳'; act({ follow: 1 }, T('Followed')).then(function (x) { if (x.ok && x.cases && x.cases[0]) location.href = '?p=cases&phone=' + encodeURIComponent(x.cases[0]); }); }
    });
    document.getElementById('rg-q').oninput = function () { RG.q = this.value; pgReset('registry'); draw(); };
    document.getElementById('rg-scan').onclick = function () { this.disabled = true; load(true); };
    document.getElementById('rg-follow').onclick = function () { if (confirm(T('Follow the selected cases? A case page is made for each number, with all their messages from every group and their private chat.'))) act({ follow: 1 }, T('Followed')); };
    document.getElementById('rg-lbl').onclick = function () { askForm('🏷️ ' + T('Add a label to the selected cases'), [{ key: 'v', label: T('Label'), placeholder: 'عين، سحر، متابعة أسبوعية' }], T('Add')).then(function (f) { if (f && f.v.trim()) act({ add_label: f.v.trim() }); }); };
    document.getElementById('rg-unlbl').onclick = function () { askForm('🏷️ ' + T('Remove a label from the selected cases'), [{ key: 'v', label: T('Label'), value: RG.label || '' }], T('Remove')).then(function (f) { if (f && f.v.trim()) act({ remove_label: f.v.trim() }); }); };
    function aiLoop(ids) {
      var st = document.getElementById('rg-ai-st'), btn = document.getElementById('rg-ai'); btn.disabled = true;
      (function step() {
        st.textContent = T('AI is reading the reports…'); if (window.WI_AI_POLL) window.WI_AI_POLL();
        post('registry_ai', ids ? { ids: ids } : {}).then(function (r) {
          if (!r.ok) { btn.disabled = false; st.textContent = '⚠️ ' + (r.error || T('Failed')); return; }
          st.textContent = '✓ ' + fmt(r.total - r.remaining) + ' / ' + fmt(r.total) + (r.task ? ' · ' + r.task.model + ' · ' + fmt(r.task.tokens_in + r.task.tokens_out) + ' ' + T('tokens') : '');
          if (r.remaining > 0 && !ids) setTimeout(step, 1200); else { btn.disabled = false; load(false); }
        });
      })();
    }
    document.getElementById('rg-ai').onclick = function () { aiLoop(null); };
    document.getElementById('rg-ai-sel').onclick = function () { aiLoop(Object.keys(RG.sel)); };
    load(false);
  }
  function aiBox(ai) {
    var sev = Number(ai.severity || 0);
    return '<div class="ai-an"><p dir="auto">' + esc(ai.summary || '') + '</p>'
      + (ai.complaint ? '<p><b>' + T('Complaint') + ':</b> <span dir="auto">' + esc(ai.complaint) + '</span></p>' : '')
      + (ai.symptoms && ai.symptoms.length ? '<p><b>' + T('Symptoms') + ':</b> ' + ai.symptoms.map(function (s) { return '<span class="tag" dir="auto">' + esc(s) + '</span>'; }).join(' ') + '</p>' : '')
      + (ai.suspected && ai.suspected.length ? '<p><b>' + T('Indications') + ':</b></p><ul>' + ai.suspected.map(function (s) { return '<li dir="auto"><b>' + esc(s.type) + '</b> (' + esc(s.confidence) + ') — ' + esc(s.why) + '</li>'; }).join('') + '</ul>' : '')
      + '<p>' + (sev ? '<b>' + T('Severity') + ':</b> ' + '●'.repeat(sev) + '○'.repeat(Math.max(0, 5 - sev)) + ' · ' : '') + (ai.progress ? '<b>' + T('Progress') + ':</b> ' + esc(ai.progress) : '') + '</p>'
      + (ai.recommended ? '<p><b>' + T('Suggested') + ':</b> <span dir="auto">' + esc(ai.recommended) + '</span></p>' : '')
      + (ai.next_step ? '<p><b>' + T('Next step') + ':</b> <span dir="auto">' + esc(ai.next_step) + '</span></p>' : '')
      + (ai.red_flags ? '<p class="bad-t"><b>⚠️ ' + T('Needs attention') + ':</b> <span dir="auto">' + esc(ai.red_flags) + '</span></p>' : '')
      + (ai.timeline && ai.timeline.length ? '<details><summary>' + T('Timeline') + '</summary><ul>' + ai.timeline.map(function (t) { return '<li dir="auto">' + esc(t.date) + ' — ' + esc(t.event) + '</li>'; }).join('') + '</ul></details>' : '')
      + '<small class="muted">' + esc(when(ai.at)) + ' · ' + esc(ai.model || '') + ' · ' + T('AI notes are a help for the team, not a medical diagnosis.') + '</small></div>';
  }

  /* ===================== Groups ===================== */
