  if (page === 'ai') {
    var acsrf = app.getAttribute('data-csrf'), AP = [];
    var SL = { ok: ['✓', 'Working', 'good'], not_configured: ['○', 'No key', 'muted'], unchecked: ['?', 'Not checked', 'muted'] };
    function apPost(b) { return WI.post('ai_providers', b); }
    function apDraw() {
      document.getElementById('ai-list').innerHTML = AP.map(function (p, i) {
        var s = SL[p.status] || ['⚠️', p.status, 'bad'], h = p.health || {};
        var cool = p.cooldowns || {}, now = Date.now() / 1000;
        function opt(m) { var id = m.id || m; return '<option value="' + esc(id) + '" ' + (p.model_setting === id ? 'selected' : '') + '>' + (m.vision ? '👁 ' : '') + esc(id) + (m.free === false ? ' 💲' : '') + (m.avoid ? ' ⚠️' : '') + (cool[id] > now ? ' ⏳' : '') + '</option>'; }
        var ms = p.models || [], models = '<optgroup label="' + T('Read images (receipts)') + '">' + ms.filter(function (m) { return m.vision; }).map(opt).join('') + '</optgroup><optgroup label="' + T('Text only') + '">' + ms.filter(function (m) { return !m.vision; }).map(opt).join('') + '</optgroup>';
        var ck = (p.checks && p.checks.items) || [];
        var checks = ck.length ? '<ul class="ai-checks">' + ck.map(function (c) { return '<li class="' + (c.ok === true ? 'ok' : (c.ok === false ? 'no' : 'na')) + '"><b>' + (c.ok === true ? '✓' : (c.ok === false ? '✗' : '–')) + '</b><span><span dir="auto">' + esc(c.label) + '</span>' + (c.detail ? '<small dir="auto">' + esc(c.detail) + '</small>' : '') + '</span></li>'; }).join('') + '</ul>' + (p.checks.at ? '<div class="muted small">' + T('Last check') + ': ' + esc(when(p.checks.at, true)) + ' ' + esc(String(p.checks.at).slice(11, 16)) + ' · ' + fmt(ms.length) + ' ' + T('models') + '</div>' : '') : '<div class="muted small">' + T('Not checked yet — press “Test & refresh models”.') + '</div>';
        var cl = Object.keys(cool).filter(function (k) { return cool[k] > now; });
        if (cl.length) checks += '<div class="muted small">⏳ ' + T('Resting (limit reached, other models still work)') + ': ' + cl.map(function (k) { return esc(k) + ' → ' + new Date(cool[k] * 1000).toLocaleTimeString(AR ? ((window.WI_SYS || {}).locale || 'ar') : 'en-GB', { hour: '2-digit', minute: '2-digit' }); }).join(' · ') + '</div>';
        var win = (p.windows || []).map(function (w) { return '<span>' + esc(w.label) + ': ' + (w.remaining != null ? fmt(w.remaining) : '?') + ' / ' + (w.limit != null ? fmt(w.limit) : '?') + '</span>'; }).join('');
        var u = p.usage || {};
        return '<div class="card ai-card" data-id="' + esc(p.id) + '" style="--c:' + esc(p.color) + '"><div class="ai-h"><span class="ai-n">' + (i + 1) + '</span><div class="ai-name"><b>' + esc(p.name) + '</b><div class="muted small">' + esc(p.tagline) + '</div></div>'
          + '<span class="pill ' + s[2] + '">' + s[0] + ' ' + T(s[1]) + '</span>' + (p.active ? '<span class="pill good">' + T('In use') + '</span>' : (p.skip_reason ? '<span class="pill muted" title="' + esc(p.skip_reason) + '">' + T('Skipped') + '</span>' : ''))
          + '<button class="ai-tg' + (p.enabled ? ' on' : '') + '" data-toggle title="' + T(p.enabled ? 'Deactivate' : 'Activate') + '"><i></i><span>' + T(p.enabled ? 'Active' : 'Inactive') + '</span></button>'
          + '<div class="ai-move"><button class="link-btn" data-up ' + (i ? '' : 'disabled') + '>▲</button><button class="link-btn" data-down ' + (i < AP.length - 1 ? '' : 'disabled') + '>▼</button></div></div>'
          + checks + (h.reason && !h.ok ? '<div class="small bad-t">⏳ ' + esc(h.reason) + '</div>' : '')
          + '<div class="ai-f"><input type="checkbox" data-f="enabled" hidden ' + (p.enabled ? 'checked' : '') + '>'
          + (p.base_editable ? '<label class="ai-url">' + T('Workspace ID — needed when the key belongs to an organisation (starts with wrkspc_)') + '<input data-f="base_url" data-ws="1" dir="ltr" value="' + esc(((p.base_url || '').match(/workspace=([A-Za-z0-9_]+)/) || [])[1] || '') + '" placeholder="wrkspc_…"></label>' : '')
          + (p.custom ? '<label>' + T('Name') + '<input data-f="name" maxlength="40" value="' + esc(p.name && p.name.indexOf('Custom') !== 0 ? p.name : '') + '" placeholder="FlexAI"></label><label class="ai-url">Base URL<input data-f="base_url" dir="ltr" value="' + esc(p.base_url || '') + '" placeholder="https://…/v1"></label>' : '')
          + '<label>' + T('API key') + '<input type="password" data-f="api_key" autocomplete="off" placeholder="' + (p.has_key ? '•••••••• ' + T('saved — type to replace') : esc(p.key_hint)) + '"></label>'
          + '<label>' + T('Extra keys (one per line) — used in turn when a key reaches its limit. Start a line with paid: or free: to say which plan that key is on') + (p.extra_keys ? ' <small class="muted">🔑 ' + fmt(p.extra_keys) + ' ' + T('extra keys saved') + ' · <button type="button" class="link-btn" data-clear-extra>' + T('remove them') + '</button></small>' : '') + '<textarea data-f="extra_keys" rows="2" dir="ltr" autocomplete="off" placeholder="' + esc(p.key_hint || '') + '"></textarea></label>'
          + '<label>' + T('Model') + '<select data-f="model"><option value="auto">' + T('Auto (best free)') + (p.model ? ' — ' + esc(p.model) : '') + '</option>' + models + '</select></label>'
          + '<label>' + T('Plan') + '<select data-f="plan">' + ['auto', 'free', 'paid'].map(function (x) { return '<option value="' + x + '" ' + (p.plan_setting === x ? 'selected' : '') + '>' + T(x === 'auto' ? 'Auto-detect' : (x === 'free' ? 'Free' : 'Paid')) + '</option>'; }).join('') + '</select></label>'
          + '<label>' + T('Daily limit (0 = none)') + '<input type="number" min="0" data-f="daily_limit" value="' + (p.daily_limit || 0) + '"></label></div>'
          + '<div class="ai-stats muted small">' + (p.latency_ms ? '<span>⚡ ' + fmt(p.latency_ms) + ' ms</span>' : '') + (u.today != null ? '<span>📊 ' + T('Today') + ': ' + fmt(u.today) + '</span>' : '') + (p.last_used_at ? '<span>🕐 ' + esc(when(p.last_used_at, true)) + '</span>' : '') + win + '</div>'
          + '<div class="row wrap"><button class="btn sm" data-save>💾 ' + T('Save') + '</button><button class="chip-btn sm" data-refresh>🔄 ' + T('Test & refresh models') + '</button>'+ '<button class="chip-btn sm" data-models title="' + esc(T('Reload only the list of models — quick')) + '">↻ ' + T('Reload models') + '</button>'
          + (p.has_key ? '<button class="link-btn" data-clear>🗑 ' + T('Remove key') + '</button>' : '') + '<a class="link small" href="' + esc(p.signup_url) + '" target="_blank" rel="noopener">🔑 ' + T('Get a free key') + '</a><span class="muted small" data-st></span></div></div>';
      }).join('') || '<div class="empty">' + T('Nothing found.') + '</div>';
    }
    function apMode(m) { [].forEach.call(document.querySelectorAll('#ai-mode button'), function (b) { b.classList.toggle('on', b.dataset.m === m); }); }
    function apLoad() { api('ai_providers').then(function (r) { if (!r.ok) { document.getElementById('ai-list').innerHTML = '<div class="empty">' + esc(r.error || '') + '</div>'; return; }
      var eb = document.getElementById('ai-engine'); if (!eb) { eb = document.createElement('div'); eb.id = 'ai-engine'; eb.className = 'pp-view'; document.getElementById('ai-list').parentNode.insertBefore(eb, document.getElementById('ai-list')); }
      eb.innerHTML = (r.engine === 'local' ? '🔐 ' + T('Engine: this system (keys are stored here, encrypted).') : '🔗 ' + T('Engine: through the invoice system (its keys). To use this system\'s own keys, set the AI engine to "local" in General settings → System, then add the keys here.')) + ' <a href="?p=settings">' + T('General settings') + ' →</a>'; AP = r.providers; apMode(r.mode); apDraw(); }); }
    document.getElementById('ai-mode').addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return; var st = document.getElementById('ai-mode-st'); st.textContent = T('Working…');
      apPost({ do: 'mode', mode: b.dataset.m }).then(function (r) { if (!r.ok) { st.textContent = '⚠️ ' + (r.error || T('Failed')); return; } apMode(r.mode); st.textContent = '✓ ' + T('Saved'); });
    });
    document.getElementById('ai-list').addEventListener('click', function (e) {
      var card = e.target.closest('.ai-card'); if (!card) return; var id = card.dataset.id, st = card.querySelector('[data-st]'), b = e.target.closest('button'); if (!b) return;
      var order = AP.map(function (p) { return p.id; }), i = order.indexOf(id);
      function f(n) { return card.querySelector('[data-f="' + n + '"]'); }
      var body = { id: id, enabled: f('enabled').checked ? 1 : 0, api_key: f('api_key').value.trim(), extra_keys: (f('extra_keys') || { value: '' }).value.trim(), model: f('model').value, plan: f('plan').value, daily_limit: Number(f('daily_limit').value || 0), order: order };
      if (f('base_url')) { var bu = f('base_url').value.trim(); if (f('base_url').dataset.ws) bu = !bu ? 'https://api.anthropic.com' : (/^wrkspc_[A-Za-z0-9]+$/.test(bu) ? 'https://api.anthropic.com?workspace=' + bu : bu); body.base_url = bu; if (f('name')) body.name = f('name').value.trim(); } // Anthropic: the workspace id alone is enough
      if (b.hasAttribute('data-up') || b.hasAttribute('data-down')) { var k = b.hasAttribute('data-up') ? i - 1 : i + 1; order.splice(k, 0, order.splice(i, 1)[0]); body.order = order; delete body.api_key; }
      else if (b.hasAttribute('data-toggle')) { var cur = AP[i]; if (cur.enabled && !confirm(T('Deactivate this provider? It will no longer be used.'))) return; if (!cur.enabled && !cur.has_key && !cur.key_optional && !body.api_key) { st.textContent = '⚠️ ' + T('Add an API key first.'); f('api_key').focus(); return; } body.enabled = cur.enabled ? 0 : 1; delete body.api_key; }
      else if (b.hasAttribute('data-clear-extra')) { if (!confirm(T('Remove the extra keys?'))) return; body.clear_extra = 1; }
      else if (b.hasAttribute('data-clear')) { if (!confirm(T('Remove the saved key?'))) return; body.clear_key = 1; body.enabled = 0; }
      else if (b.hasAttribute('data-models')) body = { do: 'models', id: id };
      else if (b.hasAttribute('data-refresh')) { if (body.api_key) body.do = 'save'; else if (f('base_url')) { var sv = body, rf = { do: 'refresh', id: id }; b.disabled = true; st.textContent = T('Checking key, plan and models… (up to 2 minutes)'); apPost(Object.assign({ do: 'save' }, sv)).then(function () { return apPost(rf); }).then(function (r) { if (!r.ok) { st.textContent = '⚠️ ' + (r.error || T('Failed')); b.disabled = false; return; } AP = r.providers; apDraw(); }).catch(function () { st.textContent = '⚠️ ' + T('Failed'); b.disabled = false; }); return; } else body = { do: 'refresh', id: id }; } // an address typed in the card is saved before the test
      else if (!b.hasAttribute('data-save')) return;
      b.disabled = true; st.textContent = b.hasAttribute('data-refresh') ? T('Checking key, plan and models… (up to 2 minutes)') : T('Working…');
      apPost(body).then(function (r) { if (!r.ok) { st.textContent = '⚠️ ' + (r.error || T('Failed')); b.disabled = false; return; } AP = r.providers; apDraw(); }).catch(function () { st.textContent = '⚠️ ' + T('Failed'); b.disabled = false; });
    });
    apLoad();
  }
