// Keeps a copy of Tally on the phone so it opens, and camera sync reads timecode,
// with no Wi-Fi or signal on set.
//
// The page itself is fetched network-first, so a deploy reaches people the next
// time they open it with a connection. Tally's own files (icons, the text
// reader) are cache-first: they only change when VERSION does. Bump VERSION
// whenever a file in PRECACHE is added, removed or replaced.
//
// The look shared with the other filmmaking tools (../style/brand.css, its
// fonts, ../auth/nav.css) isn't Tally's to version: it changes whenever
// filmmaking-home deploys. So it's served from the copy and refreshed behind
// it, and the next open has the new look. People's photos in the nav bar too.

const VERSION = 'v2';
const CACHE = `tally-${VERSION}`;

// index.html's offline check (VENDOR_FILES) looks for exactly these vendor files.
const PRECACHE = [
  './',
  'index.html',
  'manifest.webmanifest',
  'icons/icon-180.png',
  'icons/icon-192.png',
  'icons/icon-512.png',
  'vendor/tesseract/tesseract.min.js',
  'vendor/tesseract/worker.min.js',
  'vendor/tesseract-core/tesseract-core-simd-lstm.wasm.js',
  'vendor/tesseract-core/tesseract-core-lstm.wasm.js',
  'vendor/lang/eng.traineddata.gz',
];

// The shared look, saved at install too so the first open offline has it. Not
// all-or-nothing like PRECACHE: a font renamed in brand.css mustn't stop Tally
// installing. These are the faces Tally's pages use; others are kept when used.
const SHARED = [
  '../style/brand.css',
  '../auth/nav.css',
  '../style/fonts/ProximaNova-900.woff2',
  '../style/fonts/HelveticaNeue-400.woff2',
  '../style/fonts/HelveticaNeue-700.woff2',
  '../style/fonts/RobotoMono-100-700.woff2',
];

// On a bad signal a request can hang rather than fail; past this, use the copy.
const NETWORK_TIMEOUT_MS = 3500;

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    // 'reload' skips the HTTP cache, so a new VERSION never stores a stale file.
    await cache.addAll(PRECACHE.map(p => new Request(p, { cache: 'reload' })));
    await Promise.all(SHARED.map(p => cache.add(new Request(p, { cache: 'reload' })).catch(() => {})));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter(k => k.startsWith('tally-') && k !== CACHE).map(k => caches.delete(k)));
    await self.clients.claim();
    // index.html shows "Saved to this phone" on the first install, and refreshes
    // the Offline line in Settings if it's open.
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    clients.forEach(c => c.postMessage({ type: 'offline-ready', version: VERSION }));
  })());
});

self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;   // the CDN fallback and anything else external

  const home = new URL('./', self.registration.scope).pathname;
  if (url.pathname === home || url.pathname === home + 'index.html') {
    event.respondWith(pageFirst(req));
  } else if (isShared(url.pathname)) {
    event.respondWith(freshBehind(event, req));
  } else if (url.pathname.startsWith(home) && !url.pathname.endsWith('.php')) {
    event.respondWith(cacheFirst(req));
  }
  // Anything else goes to the network as usual: the account (api.php), Saved
  // sessions and its downloads, sign-in, the other tools.
});

function isShared(path) {
  const tools = new URL('../', self.registration.scope).pathname;
  return path.startsWith(tools + 'style/') || path === tools + 'auth/nav.css' || path === tools + 'auth/photo.php';
}

// The copy now, a fresh one into the cache for next time.
async function freshBehind(event, req) {
  const cache = await caches.open(CACHE);
  // The whole address: a photo's says whose it is (photo.php?u=…).
  const hit = await cache.match(req);
  const fresh = fetch(req).then(res => {
    if (res.ok && res.type === 'basic') cache.put(req, res.clone());
    return res;
  });
  if (hit) {
    event.waitUntil(fresh.catch(() => {}));
    return hit;
  }
  return fresh;
}

async function pageFirst(req) {
  const cache = await caches.open(CACHE);
  try {
    const res = await withTimeout(fetch(req, { cache: 'no-cache' }), NETWORK_TIMEOUT_MS);
    if (res.ok) {
      // Keep both names current: offline, the page may be opened as ./ or as index.html.
      cache.put('./', res.clone());
      cache.put('index.html', res.clone());
    }
    return res;
  } catch (e) {
    return (await cache.match(req, { ignoreSearch: true }))
      || (await cache.match('index.html'))
      || Response.error();
  }
}

async function cacheFirst(req) {
  const hit = await caches.match(req, { ignoreSearch: true });
  if (hit) return hit;
  const res = await fetch(req);
  if (res.ok && res.type === 'basic') (await caches.open(CACHE)).put(req, res.clone());
  return res;
}

function withTimeout(promise, ms) {
  return new Promise((resolve, reject) => {
    const t = setTimeout(() => reject(new Error('timeout')), ms);
    promise.then(v => { clearTimeout(t); resolve(v); }, e => { clearTimeout(t); reject(e); });
  });
}
