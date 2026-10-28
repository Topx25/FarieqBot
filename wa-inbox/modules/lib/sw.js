/* The app's service worker: makes the site installable, opens it offline with a short notice, and shows the push notifications. */
var CACHE = 'wi-shell-1';
self.addEventListener('install', function (e) { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
// pages always come from the server (they hold live data); only when there is no connection a small offline page is shown
self.addEventListener('fetch', function (e) {
  if (e.request.mode !== 'navigate') return;
  e.respondWith(fetch(e.request).catch(function () {
    return new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font-family:system-ui;display:grid;place-items:center;height:100vh;margin:0;text-align:center"><div><div style="font-size:48px">📶</div><p>لا يوجد اتصال بالإنترنت<br>No internet connection</p><button onclick="location.reload()">↻</button></div>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
  }));
});
self.addEventListener('push', function (e) {
  var d = {}; try { d = e.data ? e.data.json() : {}; } catch (x) { d = { body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'Fariek', { body: d.body || '', tag: d.tag || undefined, renotify: !!d.tag, icon: 'pwa-192.png', badge: 'pwa-192.png', dir: 'auto', data: { url: d.url || './' } }));
});
self.addEventListener('notificationclick', function (e) {
  e.notification.close(); var url = (e.notification.data && e.notification.data.url) || './';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) { if ('focus' in list[i]) { list[i].navigate(url); return list[i].focus(); } }
    return self.clients.openWindow(url);
  }));
});
