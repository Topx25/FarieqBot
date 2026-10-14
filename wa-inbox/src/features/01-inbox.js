  if (page === 'inbox') {
    var composer = document.getElementById('composer'), box = document.getElementById('reply'), msgs = document.getElementById('msgs') || document.querySelector('.msgs');
    function openJid() { return decodeURIComponent((location.hash || '').slice(1)); }
    if (composer && box) {
      var bar = document.createElement('div'); bar.className = 'fx-cbar';
      bar.innerHTML = '<button type="button" class="chip-btn sm" id="fx-suggest">🤖 ' + T('Suggest a reply') + '</button><button type="button" class="chip-btn sm" id="fx-sched">⏰ ' + T('Schedule') + '</button><a class="chip-btn sm" id="fx-prof" href="#">👤 ' + T('Customer profile') + '</a><span class="muted small" id="fx-cst"></span>';
      composer.parentNode.insertBefore(bar, composer);
      var qbar = document.createElement('div'); qbar.className = 'fx-quote'; qbar.hidden = true; composer.parentNode.insertBefore(qbar, composer);
      function syncBar() { var j = openJid(); bar.hidden = !j || composer.hidden; document.getElementById('fx-prof').href = '?p=customer&jid=' + encodeURIComponent(j); }
      new MutationObserver(syncBar).observe(composer, { attributes: true }); window.addEventListener('hashchange', function () { window.WI_QUOTE = null; qbar.hidden = true; syncBar(); }); syncBar();
      document.addEventListener('wi-sent', function () { qbar.hidden = true; if (dbar.dataset.jid) post('bot_draft', { jid: dbar.dataset.jid }); dbar.hidden = true; });
      // The bot's draft for this chat (suggest-only mode, or when it was not confident enough to send).
      var dbar = document.createElement('div'); dbar.className = 'fx-draft'; dbar.hidden = true; composer.parentNode.insertBefore(dbar, bar);
      function loadDraft() { var j = openJid(); dbar.hidden = true; if (!j) return; get('bot_draft', { jid: j }).then(function (r) { if (!r.ok || !r.draft || openJid() !== j) return; var d = r.draft; dbar.dataset.jid = j;
        dbar.innerHTML = '<div class="fd-h">🤖 ' + T('Bot draft') + ' <span class="muted small">' + Math.round((d.confidence || 0) * 100) + '% · ' + esc(T(d.why || '')) + '</span></div><div class="fd-t" dir="auto">' + esc(d.reply) + '</div><div class="row"><button type="button" class="btn sm primary" id="fd-use">✍️ ' + T('Put in the reply box') + '</button><button type="button" class="link-btn" id="fd-x">✕ ' + T('Dismiss') + '</button></div>';
        dbar.hidden = false;
        document.getElementById('fd-use').onclick = function () { box.value = d.reply; box.dispatchEvent(new Event('input')); box.focus(); };
        document.getElementById('fd-x').onclick = function () { post('bot_draft', { jid: j }); dbar.hidden = true; }; }); }
      window.addEventListener('hashchange', loadDraft); loadDraft();
      // Bot status for this chat: /stop – /start (also a button).
      var pb = document.createElement('button'); pb.type = 'button'; pb.className = 'chip-btn sm'; pb.hidden = true; bar.insertBefore(pb, bar.firstChild);
      function loadPause() { var j = openJid(); pb.hidden = true; if (!j || /@g\.us$/.test(j)) return; get('bot_pause', { jid: j }).then(function (r) { if (!r.ok || r.mode === 'off' || openJid() !== j) return; pb.hidden = false; pb.dataset.p = r.paused ? '1' : ''; pb.textContent = r.paused ? '⏸️ ' + T('Bot paused here — /start') : '🤖 ' + T('Bot on here — /stop'); pb.title = T('Type /stop or /start in the chat (from here or the phone) to pause or resume the bot in this chat.'); }); }
      pb.onclick = function () { post('bot_pause', { jid: openJid(), pause: pb.dataset.p ? 0 : 1 }).then(loadPause); };
      window.addEventListener('hashchange', loadPause); loadPause();
      document.addEventListener('wi-sent', function () { setTimeout(loadPause, 300); });
      document.getElementById('fx-suggest').addEventListener('click', function () {
        var b = this, st = document.getElementById('fx-cst'); b.disabled = true; st.textContent = T('AI is writing a reply…');
        post('suggest_reply', { jid: openJid() }).then(function (r) {
          b.disabled = false;
          if (!r.ok) { st.textContent = '⚠️ ' + (r.error || T('Failed')); return; }
          box.value = r.reply; box.dispatchEvent(new Event('input')); box.focus();
          st.textContent = '✓ ' + T('Review it, then send') + (r.used ? ' · 📚 ' + T('from the library') : '') + (r.note ? ' · ' + r.note : '');
        }).catch(function () { b.disabled = false; st.textContent = '⚠️ ' + T('Failed'); });
      });
      document.getElementById('fx-sched').addEventListener('click', function () {
        var text = box.value.trim(), st = document.getElementById('fx-cst');
        if (!text) { st.textContent = T('Write the message first, then choose ⏰.'); box.focus(); return; }
        var d = new Date(Date.now() + 3600000);
        askForm('⏰ ' + T('Schedule this message'), [{ key: 'at', label: T('Send at'), type: 'datetime-local', value: localInput(d).slice(0, 16) }], T('Schedule')).then(function (f) { if (!f || !f.at) return; var v = f.at;
          post('schedule', { jid: openJid(), text: text, at: v }).then(function (r) { if (r.ok) { box.value = ''; st.textContent = '⏰ ' + T('Scheduled') + ' · ' + v.replace('T', ' '); } else st.textContent = '⚠️ ' + (r.error || T('Failed')); }); });
      });
      // Hover actions on a message: reply (quote) and react.
      document.addEventListener('click', function (e) {
        var act = e.target.closest('[data-fx-act]');
        if (act) {
          var bub = act.closest('.b[data-id]'); if (!bub) return;
          if (act.dataset.fxAct === 'del') {
            var out = bub.classList.contains('out'), q = out ? T('Delete this message for everyone? (WhatsApp allows it for about two days; after that it is deleted from this number only.)') : T('Delete this message? It is deleted from this number and the dashboard; the other person keeps their copy.');
            if (!confirm(q)) return;
            post('delete_msg', { jid: openJid(), id: bub.dataset.id }).then(function (r) { if (!r.ok) { toast('⚠️ ' + (r.error || T('Failed'))); return; } bub.remove(); toast('🗑️ ' + (r.everyone ? T('Deleted for everyone') : T('Deleted'))); });
            return;
          }
          if (act.dataset.fxAct === 'quote') {
            window.WI_QUOTE = bub.dataset.id; var t = (bub.querySelector('.t') || bub).textContent.trim().slice(0, 140);
            qbar.innerHTML = '<span>↩️ ' + esc(t) + '</span><button type="button" class="link-btn">✕</button>'; qbar.hidden = false; box.focus();
            qbar.querySelector('button').onclick = function () { window.WI_QUOTE = null; qbar.hidden = true; };
          } else {
            post('react', { jid: openJid(), id: bub.dataset.id, emoji: act.dataset.e }).then(function (r) { if (!r.ok) toast('⚠️ ' + (r.error || T('Failed'))); else { var x = bub.querySelector('.b-react'); if (!x) { x = document.createElement('span'); x.className = 'b-react'; bub.prepend(x); } x.textContent = act.dataset.e; } });
          }
          return;
        }
      });
      document.addEventListener('mouseover', function (e) {
        var bub = e.target.closest('.b[data-id]'); if (!bub || bub.querySelector('.fx-acts') || bub.classList.contains('pending')) return;
        var a = document.createElement('div'); a.className = 'fx-acts';
        var C = window.WI_CAN || {};
        a.innerHTML = (C.reply === false ? '' : '<button type="button" data-fx-act="quote" title="' + T('Reply to this message') + '">↩️</button>' + ['👍', '❤️', '🤲', '🙏', '✅'].map(function (x) { return '<button type="button" data-fx-act="react" data-e="' + x + '">' + x + '</button>'; }).join(''))
          + (C.delete_msg ? '<button type="button" data-fx-act="del" title="' + T('Delete message') + '">🗑️</button>' : '');
        if (!a.innerHTML) return;
        bub.appendChild(a);
      });
    }
    return;
  }
