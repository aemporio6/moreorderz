/* MoreOrderz phone app: the screen shell is kept on the phone so the app opens fast and shows a clear message without network.
   Data (/api/…) always comes from the server – never from the phone's cache, so nothing old or private is kept there. */
const SHELL = 'mo-shell-v2';
const FILES = ['/app/design.css', '/app/app.css', '/app/app.js', '/app/icons.js', '/app/icons/icon-192.png', '/offline.html'];
self.addEventListener('install', e => { e.waitUntil(caches.open(SHELL).then(c => c.addAll(FILES)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== SHELL && k !== 'mo-share').map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const u = new URL(e.request.url);
  // Owner 27-11: Share from WhatsApp, the gallery, e-mail… → MoreOrderz. The phone posts the shared things here; they wait on the phone (never on a
  // server without sign-in) and the app takes them in through Import anything, where the person chooses where they go.
  if (e.request.method === 'POST' && u.origin === location.origin && u.pathname === '/share-in') {
    e.respondWith((async () => {
      const fd = await e.request.formData(); const c = await caches.open('mo-share'); const stamp = Date.now(); const files = [];
      for (const f of fd.getAll('files')) { if (!f || !f.size) continue; const key = `/share-file/${stamp}-${files.length}`; await c.put(key, new Response(f, { headers: { 'content-type': f.type || 'application/octet-stream', 'x-name': encodeURIComponent(f.name || 'shared') } })); files.push(key); }
      await c.put(`/share-meta/${stamp}`, new Response(JSON.stringify({ title: fd.get('title') || '', text: fd.get('text') || '', url: fd.get('url') || '', files })));
      return Response.redirect('/#/import?share=' + stamp, 303);
    })());
    return;
  }
  if (e.request.method !== 'GET' || u.origin !== location.origin || u.pathname.startsWith('/api/') || u.pathname.startsWith('/wa/') || u.pathname.startsWith('/print-agent/')) return;
  if (e.request.mode === 'navigate') { e.respondWith(fetch(e.request).catch(() => caches.match('/offline.html'))); return; }
  if (u.pathname.startsWith('/app/')) e.respondWith(fetch(e.request).then(r => { const c = r.clone(); caches.open(SHELL).then(x => x.put(u.pathname, c)); return r; }).catch(() => caches.match(u.pathname)));
});
