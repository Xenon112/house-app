/* House app service worker: caches static files, shows phone alerts.
   A push has no content: ask push.php for this device's next alert and show it. */
const CACHE = 'house-static-v1';
const ASSETS = ['assets/css/app.css', 'assets/css/fonts.css', 'assets/js/app.js', 'assets/icons/logo.svg', 'assets/icons/icon-192.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(ASSETS)).catch(() => {}).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
    .then(() => self.clients.claim()));
});
// Pages always from the network (live data); fonts and icons from the cache first
self.addEventListener('fetch', (e) => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== location.origin) return;
  if (/\/assets\/(fonts|icons)\//.test(u.pathname)) {
    e.respondWith(caches.match(e.request).then((r) => r || fetch(e.request)));
  }
});

async function sha256hex(s) {
  const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(s));
  return [...new Uint8Array(buf)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

self.addEventListener('push', (e) => {
  e.waitUntil((async () => {
    let it = null;
    try {
      const sub = await self.registration.pushManager.getSubscription();
      const ep = sub ? await sha256hex(sub.endpoint) : '';
      const r = await fetch('push.php?action=next&ep=' + ep, { credentials: 'include', cache: 'no-store' });
      it = (await r.json()).item;
    } catch (err) {}
    return self.registration.showNotification(it ? it.title : 'House', {
      body: it ? it.text : 'Something new in the house app.',
      icon: 'assets/icons/icon-192.png',
      badge: 'assets/icons/badge-96.png',
      tag: it ? 'hs-' + it.id : 'hs-general',
      data: { url: it ? it.url : 'index.php' },
    });
  })());
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = new URL((e.notification.data && e.notification.data.url) || 'index.php', self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((wins) => {
    for (const w of wins) {
      if (w.url.startsWith(self.registration.scope) && 'focus' in w) {
        return w.navigate(url).then((nw) => (nw || w).focus()).catch(() => w.focus());
      }
    }
    return self.clients.openWindow(url);
  }));
});
