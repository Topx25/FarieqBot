/* ---------- WhatsApp Desktop mode on the chat page: one icon in the top bar hides everything else and shows the chats as WhatsApp Desktop does (saved per browser) ---------- */
(function () {
  var app = document.querySelector('.app.shell-v3'); if (!app || app.getAttribute('data-page') !== 'inbox' || !document.querySelector('section.inbox')) return;
  var T = function (x) { return (window.WI_T || {})[x] || x; };
  var on = false; try { on = JSON.parse(localStorage.getItem('wi_wa_mode') || 'false'); } catch (e) {}
  var bar = document.querySelector('.topbar .tb-title') || document.querySelector('.topbar');
  var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'wa-mode-btn'; btn.title = T('WhatsApp Desktop view');
  btn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.6.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4c1.7.7 2.4.8 3.2.7a2.8 2.8 0 0 0 1.8-1.3 2.3 2.3 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3z"/></svg>';
  if (bar) bar.appendChild(btn);
  var exit = document.createElement('button'); exit.type = 'button'; exit.className = 'wa-mode-exit'; exit.textContent = '✕ ' + T('Leave WhatsApp view'); document.body.appendChild(exit);
  function apply() { document.body.classList.toggle('wa-mode', on); btn.classList.toggle('on', on); }
  function set(v) { on = v; try { localStorage.setItem('wi_wa_mode', JSON.stringify(on)); } catch (e) {} apply(); }
  btn.addEventListener('click', function () { set(!on); });
  exit.addEventListener('click', function () { set(false); });
  document.addEventListener('keydown', function (e) { if (on && e.key === 'Escape' && !document.querySelector('dialog[open], .dlg:not([hidden])')) set(false); });
  apply();
})();
