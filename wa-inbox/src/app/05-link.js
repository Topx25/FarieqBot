  if (page === 'link') {
    var csrf = app.getAttribute('data-csrf'), box = document.getElementById('lk-qr'), badge = document.getElementById('lk-badge'), acts = document.getElementById('lk-actions');
    function post(name) { return WI.post(name); }
    function render(r) {
      if (!r || !r.ok) {
        var starting = r && /not running yet/i.test(r.error || '');
        badge.textContent = starting ? T('⏳ Starting…') : T('⚠️ Gateway not responding'); badge.className = 'badge warn';
        box.innerHTML = '<div class="empty big">' + (starting ? '⏳<br>' + T('Starting… the QR will appear within a minute.') : esc(r && r.error)) + '</div>'; acts.innerHTML = ''; return;
      }
      if (r.connected) {
        badge.textContent = T('🟢 Connected'); badge.className = 'badge ok';
        box.innerHTML = '<div class="empty big">✅<br>' + T('Connected') + (r.account ? ' — ' + esc(r.account) : '') + (r.phone ? '<div class="lk-phone" dir="ltr" style="font-size:20px;font-weight:700;margin-top:6px">📱 +' + esc(r.phone) + '</div>' : '') + '</div>';
        acts.innerHTML = '<button class="btn danger" id="lk-relink">' + T('Re-link to pull old chats') + '</button>';
      } else {
        badge.textContent = T('🟡 Waiting for scan'); badge.className = 'badge warn';
        box.innerHTML = r.qr ? '<img class="qr" alt="QR" src="' + esc(r.qr) + '"><p class="muted center small">' + T('WhatsApp → Linked devices → Link a device') + '</p>' : '<div class="empty">' + T('Preparing the QR…') + ' ' + esc(r.error || '') + '</div>';
        acts.innerHTML = '<button class="btn ghost" id="lk-restore">' + T('Restore previous session') + '</button>';
      }
    }
    function poll() { api('qr').then(render).catch(function () {}); }
    poll(); setInterval(poll, 4000);
    acts.addEventListener('click', function (e) {
      if (e.target.id === 'lk-relink') {
        if (!confirm(T('The bot stops until you scan the new QR from your phone. Continue?'))) return;
        e.target.disabled = true; post('relink').then(function () { setTimeout(poll, 1500); });
      }
      if (e.target.id === 'lk-restore') {
        e.target.disabled = true; post('restore').then(function (r) { if (!r.ok) alert(r.error || T('Could not restore')); setTimeout(poll, 3000); });
      }
    });
  }
