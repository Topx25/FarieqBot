  function logTable(rows) {
    return rows.length ? '<table class="tbl"><thead><tr><th>' + T('When') + '</th><th>' + T('Chat') + '</th><th>' + T('Customer') + '</th><th>' + T('Bot') + '</th><th>' + T('Result') + '</th></tr></thead><tbody>' + rows.map(function (x) {
      var res = x.result === 'skipped' ? '⏭️ ' + T('Skipped') + ' — ' + esc(x.why || '') : x.result === 'paused' ? '⏸️ /stop' : x.result === 'resumed' ? '▶️ /start' : x.result === 'handoff' ? '🙋 ' + T('Sent and handed over to a person') : x.result === 'sent' ? '✅ ' + T('Sent') : x.result === 'draft' ? '📝 ' + T('Draft') + (x.why ? ' — ' + T(x.why) : '') : '⚠️ ' + esc(x.error || x.result || '');
      return '<tr><td>' + esc(when(x.at)) + '</td><td><a href="?p=inbox#' + encodeURIComponent(x.jid) + '">' + esc(x.name || String(x.jid).split('@')[0]) + '</a></td><td dir="auto" class="sc-t">' + esc(x.q || '') + '</td><td dir="auto" class="sc-t">' + esc(x.reply || '') + (x.reply ? '<br><small class="muted">' + Math.round((x.confidence || 0) * 100) + '% · ' + esc(x.model || '') + '</small>' : '') + '</td><td>' + res + '</td></tr>'; }).join('') + '</tbody></table>' : '<div class="empty">' + T('Nothing yet.') + '</div>';
  }

  /* ===================== One group's bot page: settings header (folds) + chat + log ===================== */
