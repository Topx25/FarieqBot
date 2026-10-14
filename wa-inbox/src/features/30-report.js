  if (page === 'report') {
    root.innerHTML = hero('📊', 'Daily report', 'Every day at the chosen hour, admins get a WhatsApp summary: active chats, chats waiting for a reply, new cases, program messages, disconnects and overdue invoices.', '') + '<div class="card" id="rp"><div class="empty">' + T('Loading…') + '</div></div>';
    get('report_cfg').then(function (r) {
      var c = r.cfg || {};
      document.getElementById('rp').innerHTML = '<label class="check"><input type="checkbox" id="rp-on" ' + (c.enabled ? 'checked' : '') + '> ' + T('Send the daily report') + '</label>'
        + '<label>' + T('Hour') + ' <select id="rp-hour">' + Array.from({ length: 24 }, function (_, i) { return '<option value="' + i + '" ' + (Number(c.hour) === i ? 'selected' : '') + '>' + i + ':00</option>'; }).join('') + '</select></label>'
        + '<p class="muted small">' + T('Sent to') + ': ' + (r.recipients.length ? esc(r.recipients.join(', ')) : '⚠️ ' + T('No admin has a WhatsApp number in Users.')) + (c.last_date ? ' · ' + T('Last sent') + ': ' + esc(c.last_date) : '') + '</p>'
        + '<div class="row"><button class="btn primary sm" id="rp-save">💾 ' + T('Save') + '</button><button class="chip-btn sm" id="rp-prev">👁 ' + T('Preview') + '</button><button class="chip-btn sm" id="rp-send">📤 ' + T('Send now') + '</button><span class="muted small" id="rp-msg"></span></div><pre id="rp-out" class="rp-out" dir="auto" hidden></pre>';
      document.getElementById('rp-save').onclick = function () { post('report_cfg', { enabled: document.getElementById('rp-on').checked ? 1 : 0, hour: document.getElementById('rp-hour').value }).then(function (x) { document.getElementById('rp-msg').textContent = x.ok ? '✓ ' + T('Saved') : '⚠️ ' + x.error; }); };
      document.getElementById('rp-prev').onclick = function () { document.getElementById('rp-msg').textContent = T('Working…'); post('report_cfg', { preview: 1 }).then(function (x) { document.getElementById('rp-msg').textContent = ''; var o = document.getElementById('rp-out'); o.hidden = false; o.textContent = x.text || x.error; }); };
      document.getElementById('rp-send').onclick = function () { document.getElementById('rp-msg').textContent = T('Working…'); post('report_cfg', { send_now: 1 }).then(function (x) { document.getElementById('rp-msg').textContent = x.ok ? '✓ ' + T('Sent to') + ' ' + x.sent : '⚠️ ' + x.error; }); };
    });
    return;
  }
