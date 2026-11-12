/* JAHOnline PWA service worker
 * Scopet: /public/ (ajanotto + emit) ja /admin/ (iltarastit).
 * /ahoauto/, /autot/, /ohjaus/ ja /scenic/ jätetään rauhaan.
 * Iltarastit: verkko ensin, muuten viimeisin avattu sivu.
 */
const CACHE = 'jahonline-app-v22';
const SHELL = [
  '/gps/assets/css/gps.css',
  '/gps/assets/icons/icon-192.png',
  '/gps/assets/icons/icon-512.png',
  '/gps/assets/icons/apple-touch-icon.png',
  '/gps/assets/icons/icon-512-maskable.png',
  '/gps/assets/icons/logo-128.png',
  '/public/icon-192.png',
  '/public/icon-512.png',
  '/assets/css/style.css?v=20261106palvelut',
  '/assets/css/emit_lukija.css?v=20261112selite',
  '/assets/css/live_board.css?v=20261112pallo',
  '/assets/css/iltarasti_kuori.css?v=20261112selite',
  '/assets/css/palvelu_valitsin.css?v=20261106mobiili',
  '/assets/js/emit_lukija.js?v=20261112selite',
  '/assets/js/seuranta.js?v=20261112pallo',
  '/assets/js/jah-radat-offline.js?v=20261107radat',
  '/assets/js/emit_proto.js?v=20261024leima2',
  '/assets/js/si_proto.js?v=20261112si',
  '/assets/js/usb_sarja.js?v=20261112si',
  '/assets/js/usb_sarja_boot.js?v=20261112si',
  '/assets/js/wake_lock.js?v=20260718a',
  '/assets/js/admin_nav.js?v=20261016avaus',
  '/assets/js/jah-pwa.js?v=20261112selite',
  '/assets/js/theme.js?v=20260917teema2'
];
const AUTO_PREFIXES = ['/ahoauto', '/autot', '/ohjaus', '/scenic'];

function isAutoPath(pathname) {
  return AUTO_PREFIXES.some(
    (prefix) => pathname === prefix || pathname.startsWith(prefix + '/')
  );
}

function isIltarastiOfflinePath(pathname) {
  return (
    pathname.indexOf('/public/emit_lukija.php') === 0 ||
    pathname.indexOf('/public/automaatti.php') === 0 ||
    pathname.indexOf('/public/iltarastit.php') === 0 ||
    pathname.indexOf('/public/kilpailijat.php') === 0 ||
    pathname.indexOf('/public/iltarasti_') === 0 ||
    pathname.indexOf('/public/radat.php') === 0 ||
    pathname.indexOf('/admin/iltarastit.php') === 0 ||
    pathname.indexOf('/admin/kilpailijat.php') === 0 ||
    pathname.indexOf('/admin/iltarasti_') === 0 ||
    pathname.indexOf('/admin/radat.php') === 0 ||
    pathname.indexOf('/assets/js/emit_') === 0 ||
    pathname.indexOf('/assets/css/emit_') === 0 ||
    pathname.indexOf('/assets/js/usb_') === 0 ||
    pathname.indexOf('/assets/js/wake_lock') === 0 ||
    pathname.indexOf('/assets/js/admin_nav') === 0 ||
    pathname.indexOf('/assets/js/jah-pwa') === 0 ||
    pathname.indexOf('/assets/js/jah-radat') === 0 ||
    pathname.indexOf('/assets/css/style.css') === 0 ||
    pathname.indexOf('/assets/css/iltarasti_') === 0 ||
    pathname.indexOf('/assets/css/palvelu_valitsin') === 0
  );
}

function offlineSivu() {
  return new Response(
    '<!DOCTYPE html><html lang="fi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ei verkkoa</title></head><body><p>Tätä sivua ei ole vielä tallennettu puhelimeen. Avaa tapahtuma verkossa, niin osanottajat, asetukset ja tulospalvelu jäävät muistiin.</p></body></html>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=UTF-8' } }
  );
}

function cacheHit(req) {
  return caches.match(req).then((hit) => hit || caches.match(req, { ignoreSearch: true }));
}

function networkFirst(req) {
  return fetch(req)
    .then((res) => {
      if (res.ok) {
        const copy = res.clone();
        caches.open(CACHE).then((cache) => talletaVaste(cache, req, copy)).catch(() => {});
      }
      return res;
    })
    .catch(() => cacheHit(req).then((hit) => hit || offlineSivu()));
}

function talletaVaste(cache, url, res) {
  return res.clone().blob().then((blob) => {
    const headers = new Headers();
    const ct = res.headers.get('Content-Type');
    if (ct) {
      headers.set('Content-Type', ct);
    }
    return cache.put(
      url,
      new Response(blob, { status: res.status, statusText: res.statusText, headers: headers })
    );
  });
}

function prefetchIltarasti(urls) {
  if (!Array.isArray(urls) || urls.length === 0) {
    return Promise.resolve();
  }
  return caches.open(CACHE).then((cache) =>
    Promise.all(
      urls.map((url) =>
        fetch(url, { credentials: 'include', redirect: 'follow', cache: 'reload' })
          .then((res) => {
            if (!res.ok) {
              return undefined;
            }
            const tasks = [talletaVaste(cache, url, res)];
            if (res.url && res.url !== url) {
              tasks.push(talletaVaste(cache, res.url, res));
            }
            return Promise.all(tasks);
          })
          .catch(() => undefined)
      )
    )
  );
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) =>
        Promise.all(
          SHELL.map((url) =>
            cache.add(url).catch(() => undefined)
          )
        )
      )
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(
          keys
            .filter((k) => k !== CACHE && (k.startsWith('jahonline-gps-') || k.startsWith('jahonline-app-')))
            .map((k) => caches.delete(k))
        )
      )
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (!event.data) {
    return;
  }
  if (event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
    return;
  }
  if (event.data.type === 'ILTA_PREFETCH') {
    event.waitUntil(prefetchIltarasti(event.data.urls));
  }
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (isAutoPath(url.pathname)) return;

  if (
    url.pathname.includes('/gps/api/') ||
    url.pathname.includes('/api/') ||
    url.pathname.includes('/public/api/')
  ) {
    event.respondWith(
      fetch(req).catch(
        () =>
          new Response(JSON.stringify({ status: 'error', message: 'Ei verkkoyhteyttä' }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' }
          })
      )
    );
    return;
  }

  if (isIltarastiOfflinePath(url.pathname)) {
    event.respondWith(networkFirst(req));
    return;
  }

  if (
    url.pathname.endsWith('.php') ||
    url.pathname.endsWith('/') ||
    url.pathname.includes('manifest')
  ) {
    event.respondWith(fetch(req));
    return;
  }

  if (url.pathname.endsWith('.js') || url.pathname.endsWith('.css') || url.pathname.indexOf('/sw-jahonline') === 0) {
    event.respondWith(networkFirst(req));
    return;
  }

  event.respondWith(
    caches.match(req).then((cached) => {
      const network = fetch(req)
        .then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(CACHE).then((cache) => cache.put(req, copy)).catch(() => {});
          }
          return res;
        })
        .catch(() => cached);
      return cached || network;
    })
  );
});
