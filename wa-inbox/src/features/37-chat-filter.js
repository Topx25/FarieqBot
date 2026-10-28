/* ---------- Chat page: a filter bar over the open chat (period, words, kind) that hides the messages that do not match ---------- */
(function () {
  var app = document.querySelector('.app.shell-v3'); if (!app || app.getAttribute('data-page') !== 'inbox') return;
  var T = function (x) { return (window.WI_T || {})[x] || x; };
  var msgs = document.querySelector('section.inbox .msgs'), head = document.querySelector('section.inbox .thread-h'); if (!msgs || !head) return;
  var bar = document.createElement('div'); bar.className = 'cf-bar'; bar.hidden = true;
  bar.innerHTML = '<input type="search" data-c="q" dir="auto" placeholder="' + T('Search the messages…') + '">'
    + '<select data-c="k"><option value="">' + T('All messages') + '</option><option value="text">💬 ' + T('Text') + '</option><option value="link">🔗 ' + T('Links') + '</option><option value="image">🖼️ ' + T('Photos and videos') + '</option><option value="voice">🎤 ' + T('Voice') + '</option><option value="document">📄 ' + T('Files') + '</option><option value="out">↩️ ' + T('Our replies') + '</option></select>'
    + '<select data-c="d"><option value="">' + T('Any time') + '</option><option value="1">' + T('Today') + '</option><option value="7">7' + T('d') + '</option><option value="30">30' + T('d') + '</option><option value="90">90' + T('d') + '</option></select>'
    + '<span class="cf-n muted small"></span><button type="button" class="link-btn" data-c="x">✕</button>';
  head.parentNode.insertBefore(bar, head.nextSibling);
  var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'chip-btn cf-btn'; btn.title = T('Filter the messages'); btn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 5h16l-6 8v5l-4 2v-7z"/></svg> <span>' + T('Filter') + '</span>';
  // the header is redrawn with every chat: the button is put back each time
  var place = function () { var h = document.querySelector('section.inbox .thread-h'); if (h && !h.contains(btn)) h.appendChild(btn); }; place();
  new MutationObserver(place).observe(head.parentNode, { childList: true, subtree: true });
  var v = function (c) { return (bar.querySelector('[data-c=' + c + ']') || {}).value || ''; };
  function run() {
    var q = v('q').trim().toLowerCase(), k = v('k'), d = v('d'), from = d ? new Date(Date.now() - (d === '1' ? 0 : d * 86400000)).toISOString().slice(0, 10) : '', shown = 0, all = 0;
    [].forEach.call(msgs.querySelectorAll('.b'), function (b) { all++;
      var kind = b.dataset.k || 'text', tx = b.textContent.toLowerCase(), ok = true;
      if (q && tx.indexOf(q) === -1) ok = false;
      if (from && (b.dataset.t || '') < from) ok = false;
      if (k === 'text' && kind !== 'text') ok = false;
      if (k === 'link' && !b.querySelector('a[href^="http"]') && !/https?:\/\/|www\./.test(tx)) ok = false;
      if (k === 'image' && !/image|video|gif|sticker/.test(kind)) ok = false;
      if (k === 'voice' && !/voice|audio/.test(kind)) ok = false;
      if (k === 'document' && kind !== 'document') ok = false;
      if (k === 'out' && !b.classList.contains('out')) ok = false;
      b.classList.toggle('cf-off', !ok); if (ok) shown++; });
    var on = q || k || d; msgs.classList.toggle('cf-on', !!on); btn.classList.toggle('on', !!on);
    bar.querySelector('.cf-n').textContent = on ? shown + ' / ' + all : '';
  }
  btn.addEventListener('click', function () { bar.hidden = !bar.hidden; if (!bar.hidden) bar.querySelector('input').focus(); });
  bar.addEventListener('input', run); bar.addEventListener('change', run);
  bar.querySelector('[data-c=x]').addEventListener('click', function () { [].forEach.call(bar.querySelectorAll('input,select'), function (i) { i.value = ''; }); run(); bar.hidden = true; });
  // the chat redraws its messages (new ones, another chat): the filter is applied again
  var t = null; new MutationObserver(function () { clearTimeout(t); t = setTimeout(run, 60); }).observe(msgs, { childList: true });
})();
