  if (page === 'customer') {
    var jid = root.dataset.jid;
    get('customer', { jid: jid }).then(function (r) {
      if (!r.ok) return fail(root, r);
      var c = r.customer, s = r.stats || {}, name = c ? c.name : (r.name || ('+' + r.phone));
      var h = '<section class="hero"><div class="hero-t">' + avatar(jid, name) + '<div><h1 dir="auto">' + esc(name) + '</h1><p class="muted" dir="ltr">' + (r.phone ? '+' + esc(r.phone) : esc(jid)) + (c ? ' · 🧾 ' + T('Customer') : '') + (r.case ? ' · 🩺 ' + T('Case') : '') + '</p></div></div>'
        + '<div class="hero-tools"><a class="chip-btn" href="?p=inbox#' + encodeURIComponent(jid) + '">💬 ' + T('Open chat') + '</a><a class="chip-btn" href="?p=programs&jid=' + encodeURIComponent(jid) + '">💊 ' + T('Enrol in a program') + '</a>' + (r.case ? '<a class="chip-btn" href="?p=cases&phone=' + encodeURIComponent(r.phone) + '">🩺 ' + T('Case record') + '</a>' : '') + '</div></section>';
      h += '<div class="kpis card"><div><b>' + fmt(s.in) + '</b><span>' + T('Messages from them') + '</span></div><div><b>' + fmt(s.out) + '</b><span>' + T('Messages to them') + '</span></div><div><b>' + (s.first ? esc(day(s.first)) : '—') + '</b><span>' + T('First message') + '</span></div><div><b>' + (s.last ? esc(day(s.last)) : '—') + '</b><span>' + T('Last message') + '</span></div>' + (c ? '<div><b>' + fmt(c.due) + '</b><span>' + T('Amount due') + '</span></div>' : '') + '</div>';
      h += '<div class="cd-grid">';
      if (r.summary) h += '<div class="card"><h3>🧠 ' + T('AI summary') + '</h3><p dir="auto">' + esc(r.summary.summary) + '</p><p class="small"><span class="tag">' + esc(r.summary.category) + '</span> ' + esc(r.summary.next_action || '') + '</p></div>';
      if (r.case) h += '<div class="card"><h3>🩺 ' + T('Case') + '</h3><p dir="auto">' + esc((r.case.data || {}).complaint || r.case.last_text || '') + '</p>' + (r.case.ai ? '<p dir="auto" class="small">' + esc(r.case.ai.summary || '') + '</p>' : '') + '</div>';
      if (r.invoices && r.invoices.length) h += '<div class="card"><h3>🧾 ' + T('Invoices') + '</h3><table class="tbl"><tbody>' + r.invoices.slice(0, 10).map(function (i) { return '<tr><td>' + esc(i.reference) + '</td><td>' + esc(i.date) + '</td><td>' + fmt(i.total) + ' ' + esc(i.currency) + '</td><td>' + esc(i.status) + (Number(i.total_due) > 0 ? ' · ' + T('due') + ' ' + fmt(i.total_due) : '') + '</td></tr>'; }).join('') + '</tbody></table></div>';
      if (r.enrollments.length) h += '<div class="card"><h3>💊 ' + T('Programs') + '</h3>' + r.enrollments.map(function (e) { return '<div dir="auto">' + esc(e.program_name) + ' · ' + T(e.status) + ' · ' + fmt(Object.keys(e.sent || {}).length) + ' ' + T('sent') + ' · ' + fmt(e.replies || 0) + ' ' + T('replies') + '</div>'; }).join('') + '</div>';
      if (r.notes.length) h += '<div class="card"><h3>📝 ' + T('Notes') + '</h3>' + r.notes.map(function (n) { return '<div class="cd-note"><div dir="auto">' + esc(n.text) + '</div><small class="muted">' + esc(n.by || '') + ' · ' + esc(when(n.at)) + '</small></div>'; }).join('') + '</div>';
      if (r.reminders.length || r.scheduled.length) h += '<div class="card"><h3>⏰ ' + T('Reminders & scheduled') + '</h3>' + r.reminders.map(function (x) { return '<div>🔔 ' + esc(when(x.at)) + ' ' + esc(x.note || '') + (x.done ? ' ✅' : '') + '</div>'; }).join('') + r.scheduled.map(function (x) { return '<div>⏳ ' + esc(when(x.at)) + ' — <span dir="auto">' + esc(String(x.text).slice(0, 80)) + '</span></div>'; }).join('') + '</div>';
      var k = s.kinds || {}; h += '<div class="card"><h3>📎 ' + T('Shared content') + '</h3>' + Object.keys(k).map(function (x) { return '<span class="tag">' + esc(x) + ' · ' + fmt(k[x]) + '</span>'; }).join(' ') + '</div>';
      h += '</div>';
      root.innerHTML = h;
    });
  }
