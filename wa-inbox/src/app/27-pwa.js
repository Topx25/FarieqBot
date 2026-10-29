/* The installable app (PWA): registers the service worker, offers "Install the app" and turns the phone notifications on (Web Push).
   One button in the top bar opens a small panel with both; the state is read from the browser each time it opens. */
(function () {
  // inside the phone app (Android / iPhone): its own notifications (Firebase) instead of the browser's; no install button
  var C = window.Capacitor;
  if (C && C.isNativePlatform && C.isNativePlatform() && window.WI && document.querySelector('.app')) {
    var PN = C.Plugins && C.Plugins.PushNotifications; if (!PN) return;
    PN.addListener('registration', function (t) { WI.post('push_fcm', { token: t.value, os: C.getPlatform() }); });
    PN.addListener('pushNotificationActionPerformed', function (a) { var u = a && a.notification && a.notification.data && a.notification.data.url; if (u) location.href = u; });
    if (PN.createChannel) PN.createChannel({ id: 'fariek', name: 'Fariek', importance: 5, visibility: 1, vibration: true }).catch(function () {});
    // only once the server has the app's Firebase project (an app built without it closes on register)
    WI.get('push_key').then(function (k) { if (k && k.fcm) PN.requestPermissions().then(function (p) { if (p.receive === 'granted') PN.register(); }); });
    return;
  }
  if (!('serviceWorker' in navigator) || !window.WI || !document.querySelector('.app')) return;
  var T = function (x) { return (window.WI_T || {})[x] || x; };
  var reg = null, deferred = null;
  navigator.serviceWorker.register('sw.js', { scope: './' }).then(function (r) { reg = r; }).catch(function () {});
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; });
  var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  var ios = /iphone|ipad|ipod/i.test(navigator.userAgent);
  var bar = document.querySelector('.topbar .tb-title') || document.querySelector('.topbar'); if (!bar) return;
  var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'pwa-btn'; btn.title = T('App and notifications');
  btn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2.5" width="12" height="19" rx="2.5"/><path d="M10.5 18.5h3"/><path d="M12 7v6M9.5 10.5 12 13l2.5-2.5"/></svg>';
  bar.appendChild(btn);
  var pop = document.createElement('div'); pop.className = 'pwa-pop'; pop.hidden = true; document.body.appendChild(pop);
  function b64(s) { var p = '='.repeat((4 - s.length % 4) % 4), r = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')), o = new Uint8Array(r.length); for (var i = 0; i < r.length; i++) o[i] = r.charCodeAt(i); return o; }
  function sub() { return (reg ? Promise.resolve(reg) : navigator.serviceWorker.ready).then(function (r) { reg = r; return r.pushManager ? r.pushManager.getSubscription() : null; }); }
  function draw() {
    sub().then(function (s) {
      var perm = window.Notification ? Notification.permission : 'denied', on = !!s && perm === 'granted';
      var inst = standalone ? '<p class="ok">✅ ' + T('Installed on this device') + '</p>'
        : deferred ? '<button type="button" class="btn primary" data-a="install">📲 ' + T('Install the app') + '</button>'
        : '<p class="muted small">' + (ios ? T('On iPhone: press Share ⬆️ then «Add to Home Screen».') : T('From the browser menu ⋮ choose «Install app» or «Add to Home screen».')) + '</p>';
      var noti = !('PushManager' in window) ? '<p class="muted small">' + (ios && !standalone ? T('On iPhone the notifications work after the app is added to the Home Screen.') : T('This browser has no notifications.')) + '</p>'
        : perm === 'denied' ? '<p class="muted small">⚠️ ' + T('Notifications are blocked for this site in the browser settings.') + '</p>'
        : on ? '<p class="ok">🔔 ' + T('Notifications are on for this device') + '</p><div class="row"><button type="button" class="btn ghost sm" data-a="test">' + T('Send a test') + '</button><button type="button" class="btn ghost sm" data-a="off">' + T('Turn off') + '</button></div>'
        : '<button type="button" class="btn primary" data-a="on">🔔 ' + T('Turn on the notifications') + '</button><p class="muted small">' + T('New chats handed to you, customers asking for a person and questions waiting in the groups reach this phone even when the site is closed.') + '</p>';
      pop.innerHTML = '<b>📱 ' + T('The app on your phone') + '</b>' + inst + '<hr>' + noti;
    });
  }
  btn.addEventListener('click', function (e) { e.stopPropagation(); if (pop.hidden) { var r = btn.getBoundingClientRect(); pop.style.top = (r.bottom + 6) + 'px'; pop.style.left = Math.max(8, Math.min(window.innerWidth - 300, r.left - 130)) + 'px'; draw(); } pop.hidden = !pop.hidden; });
  document.addEventListener('click', function (e) { if (!pop.hidden && !e.target.closest('.pwa-pop')) pop.hidden = true; });
  pop.addEventListener('click', function (e) {
    var a = (e.target.closest('[data-a]') || {}).dataset; if (!a || !a.a) return;
    if (a.a === 'install' && deferred) { deferred.prompt(); deferred.userChoice.then(function () { deferred = null; draw(); }); return; }
    if (a.a === 'on') {
      Notification.requestPermission().then(function (p) { if (p !== 'granted') return draw();
        return WI.get ? WI.get('push_key') : fetch('?api=push_key', { credentials: 'same-origin' }).then(function (x) { return x.json(); }); })
        .then(function (k) { if (!k || !k.key) return; return sub().then(function (old) { return old || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(k.key) }); }); })
        .then(function (s) { if (!s) return; return WI.post('push_sub', { sub: s.toJSON() }); })
        .then(function (r) { if (r && r.ok) WI.post('push_test', {}); draw(); })
        .catch(function (x) { alert(T('Could not turn the notifications on') + ': ' + (x && x.message || x)); });
      return;
    }
    if (a.a === 'test') { WI.post('push_test', {}).then(function (r) { if (!r.ok) alert(T(r.error || 'Failed')); }); return; }
    if (a.a === 'off') { sub().then(function (s) { if (!s) return draw(); return WI.post('push_unsub', { endpoint: s.endpoint }).then(function () { return s.unsubscribe(); }).then(draw); }); }
  });
})();
