// Keeps a copy of Tally on the phone so it opens, and camera sync reads timecode,
// with no Wi-Fi or signal on set.
//
// The page itself is fetched network-first, so a deploy reaches people the next
// time they open it with a connection. Everything else (fonts, icons, the text
// reader) is cache-first: it only changes when VERSION does. Bump VERSION
// whenever a file in PRECACHE is added, removed or replaced.

const VERSION = 'v1';
const CACHE = `tally-${VERSION}`;

// index.html's offline check (VENDOR_FILES) looks for exactly these vendor files.
const PRECACHE = [
  './',
  'index.html',
  'manifest.webmanifest',
  'icons/icon-180.png',
  'icons/icon-192.png',
  'icons/icon-512.png',
  'fonts/archivo-latin-wdth-normal.woff2',
  'fonts/archivo-latin-ext-wdth-normal.woff2',
  'fonts/martian-mono-latin-wdth-normal.woff2',
  'vendor/tesseract/tesseract.min.js',
  'vendor/tesseract/worker.min.js',
  'vendor/tesseract-core/tesseract-core-simd-lstm.wasm.js',
  'vendor/tesseract-core/tesseract-core-lstm.wasm.js',
  'vendor/lang/eng.traineddata.gz',
];

// On a bad signal a request can hang rather than fail; past this, use the copy.
const NETWORK_TIMEOUT_MS = 3500;

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    // 'reload' skips the HTTP cache, so a new VERSION never stores a stale file.
    await cache.addAll(PRECACHE.map(p => new Request(p, { cache: 'reload' })));
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

  const isPage = req.mode === 'navigate' || url.pathname.endsWith('/index.html');
  event.respondWith(isPage ? pageFirst(req) : cacheFirst(req));
});

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
