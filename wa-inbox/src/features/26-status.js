  if (page === 'status') {
    root.innerHTML = hero('🟢', 'WhatsApp Status', 'Post a text, photo or video status now or at a time — shown to the contacts you choose (max 500, one post per minute).', '')
      + '<div class="card st-form"><label>' + T('Text / caption') + '<textarea id="st-text" rows="4" maxlength="700" dir="auto"></textarea></label>'
      + '<label>' + T('Photo or video (optional)') + '<input type="file" id="st-file" accept="image/jpeg,image/png,image/webp,video/mp4"></label>'
      + '<label>' + T('Background colour (text status)') + '<input type="color" id="st-color" value="#0f766e"></label>'
      + '<label>' + T('Who sees it') + '<select id="st-aud"><option value="recent">' + T('People who chatted in the last 3 months') + '</option><option value="customers">' + T('Customers only') + '</option><option value="all">' + T('Everyone in the archive (2 years)') + '</option></select></label>'
      + '<label>' + T('When (empty = now)') + '<input type="datetime-local" id="st-at"></label>'
      + '<div class="row"><button class="btn primary" id="st-go">🟢 ' + T('Post status') + '</button><span class="muted small" id="st-msg"></span></div></div>';
    document.getElementById('st-go').onclick = function () {
      var b = this, msg = document.getElementById('st-msg'), f = document.getElementById('st-file').files[0];
      if (!document.getElementById('st-text').value.trim() && !f) { msg.textContent = T('Write a text or choose a photo/video.'); return; }
      b.disabled = true; msg.textContent = T('Working…');
      var up = f ? (function () { var fd = new FormData(); fd.append('file', f); return fetch('?api=status_upload', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': csrf }, body: fd }).then(safeJson, netFail); })() : Promise.resolve({ ok: true, attachment: '' });
      up.then(function (u) {
        if (!u.ok) throw new Error(u.error);
        var at = document.getElementById('st-at').value;
        return post('status_post', { text: document.getElementById('st-text').value, attachment: u.attachment, audience: document.getElementById('st-aud').value, color: document.getElementById('st-color').value, at: at ? at : '' });
      }).then(function (r) { b.disabled = false; msg.textContent = r.ok ? (r.scheduled ? '⏰ ' + T('Scheduled') : '✓ ' + T('Posted to') + ' ' + fmt(r.audience) + ' ' + T('contacts')) : '⚠️ ' + (r.error || T('Failed')); })
        .catch(function (e) { b.disabled = false; msg.textContent = '⚠️ ' + e.message; });
    };
    return;
  }
