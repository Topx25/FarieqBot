  if (page === 'accounts') {
    function loadAcc() {
      api('accounts').then(function (r) {
        (r.accounts || []).forEach(function (a) {
          var card = document.querySelector('form.acc[data-acc="' + a.id + '"]'); if (!card) return;
          var st = card.querySelector('[data-state]');
          st.textContent = !a.enabled ? T('⏸ Off') : (!a.running ? T('⏳ Starting…') : (a.connected ? T('🟢 Connected') : T('🟡 Scan QR')));
          st.className = 'badge ' + (a.connected ? 'ok' : (a.running ? 'warn' : ''));
          card.querySelector('[data-num]').textContent = a.number ? '+' + a.number + (a.wa_name ? ' · ' + a.wa_name : '') : T('Not linked yet');
          card.querySelector('[data-msgs]').textContent = fmt(a.messages);
          card.querySelector('[data-chats]').textContent = fmt(a.chats);
        });
      });
    }
    loadAcc(); setInterval(loadAcc, WIR(15000));
    var rb = document.querySelector('[data-reload-acc]'); if (rb) rb.addEventListener('click', loadAcc);
  }
