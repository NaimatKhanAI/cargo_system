const STATIC_CACHE = "cargo-static-v3";
const STATIC_ASSETS = [
  "./assets/mobile.css",
  "./assets/pwa.js",
  "./manifest.json",
  "./assets/icons/icon-192.png",
  "./assets/icons/icon-512.png"
];

function isStaticAsset(url) {
  if (url.origin !== self.location.origin) return false;
  if (url.pathname.startsWith("/cargo_system/assets/")) return true;
  return url.pathname.endsWith(".css") || url.pathname.endsWith(".js") || url.pathname.endsWith(".json") || url.pathname.endsWith(".png");
}

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => cache.addAll(STATIC_ASSETS)).catch(() => Promise.resolve())
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== STATIC_CACHE)
          .map((key) => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  const req = event.request;
  if (req.method !== "GET") return;

  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;

  // Keep dynamic PHP navigation requests network-first (no cache).
  if (req.mode === "navigate" || url.pathname.endsWith(".php")) {
    event.respondWith(fetch(req));
    return;
  }

  if (!isStaticAsset(url)) return;

  event.respondWith(
    caches.match(req).then((cached) => {
      if (cached) return cached;
      return fetch(req)
        .then((response) => {
          if (!response || response.status !== 200 || response.type !== "basic") return response;
          const copy = response.clone();
          caches.open(STATIC_CACHE).then((cache) => cache.put(req, copy));
          return response;
        });
    })
  );
});
