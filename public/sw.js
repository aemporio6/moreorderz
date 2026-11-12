/* MoreOrderz phone app: the screen shell is kept on the phone so the app opens fast and shows a clear message without network.
   Data (/api/…) always comes from the server – never from the phone's cache, so nothing old or private is kept there. */
const SHELL = 'mo-shell-v1';
const FILES = ['/app/design.css', '/app/app.css', '/app/app.js', '/app/icons.js', '/app/icons/icon-192.png', '/offline.html'];
self.addEventListener('install', e => { e.waitUntil(caches.open(SHELL).then(c => c.addAll(FILES)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== SHELL).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== location.origin || u.pathname.startsWith('/api/') || u.pathname.startsWith('/wa/') || u.pathname.startsWith('/print-agent/')) return;
  if (e.request.mode === 'navigate') { e.respondWith(fetch(e.request).catch(() => caches.match('/offline.html'))); return; }
  if (u.pathname.startsWith('/app/')) e.respondWith(fetch(e.request).then(r => { const c = r.clone(); caches.open(SHELL).then(x => x.put(u.pathname, c)); return r; }).catch(() => caches.match(u.pathname)));
});
