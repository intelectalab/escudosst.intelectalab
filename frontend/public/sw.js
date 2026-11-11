/* EscudoSST · service worker de la app instalable (docs/16 §1).
 * - Guarda el «shell» de la app (pantallas ya visitadas y archivos de Next) para abrirla sin señal.
 * - Guarda una copia de las consultas de catálogos y registros de campo (sesión, empresa, módulos INS, ACT, CH5,
 *   ATS, EPP, CAP, SIM y sus opciones) para seguir trabajando sin conexión. La copia es por sesión y empresa.
 * - Nunca guarda respuestas médicas ni de otras rutas de la API: solo las de la lista de abajo.
 */
const VERSION = "escudo-v1";
const SHELL = `${VERSION}-shell`;
const STATIC = `${VERSION}-static`;
const API_PREFIX = "escudo-api-";
const PRECACHE = ["/inicio", "/login", "/select-company", "/mi", "/mi/ingresar", "/mi/fotocheck", "/mi/reportar", "/manifest.webmanifest", "/icons/escudo.svg"];

// Consultas de la API que se pueden copiar (solo GET). Ninguna de datos médicos.
const API_ALLOW = [
  /^\/api\/v1\/me$/,
  /^\/api\/v1\/me\/(companies|modules)$/,
  /^\/api\/v1\/records\/(INS|ACT|CH5|ATS|EPP|CAP|SIM)(\/[a-z0-9_]+(\/options\/[a-z0-9_]+)?)?$/i,
  /^\/api\/v1\/ins\/inspections\/[0-9a-f-]{36}\/checklist$/,
  // App del trabajador: inicio, fotocheck (para mostrarlo sin señal), pendientes, capacitaciones, EPP y reportes. Nunca salud.
  /^\/api\/v1\/me\/worker(\/(credential|pending|trainings|ppe|reports))?$/,
];
const API_DENY = /\/(vmd|emo|medical|med)\//i;

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(SHELL).then((c) => Promise.all(PRECACHE.map((u) => c.add(u).catch(() => undefined)))).then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION) && !k.startsWith(API_PREFIX)).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("message", (event) => {
  if (event.data && event.data.type === "clear-api") {
    event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith(API_PREFIX)).map((k) => caches.delete(k)))));
  }
});

async function apiCacheName(request) {
  const auth = request.headers.get("Authorization") || "";
  const company = request.headers.get("X-Company-Id") || "";
  if (!auth) return null;
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(auth + "|" + company));
  const hex = Array.from(new Uint8Array(digest).slice(0, 12), (b) => b.toString(16).padStart(2, "0")).join("");
  return API_PREFIX + hex;
}

/** Red primero; sin señal, la última copia. Una sesión nueva descarta las copias de las anteriores. */
async function apiNetworkFirst(request) {
  const name = await apiCacheName(request);
  try {
    const res = await fetch(request);
    if (name && res.ok) {
      const keys = await caches.keys();
      await Promise.all(keys.filter((k) => k.startsWith(API_PREFIX) && k !== name).map((k) => caches.delete(k)));
      const c = await caches.open(name);
      await c.put(request, res.clone());
    }
    return res;
  } catch (err) {
    if (name) {
      const hit = await (await caches.open(name)).match(request);
      if (hit) return hit;
    }
    throw err;
  }
}

async function pageNetworkFirst(request) {
  const c = await caches.open(SHELL);
  try {
    const res = await fetch(request);
    if (res.ok && res.type === "basic") await c.put(request, res.clone());
    return res;
  } catch (err) {
    const hit = (await c.match(request)) || (await c.match(request, { ignoreSearch: true }));
    if (hit) return hit;
    if (request.mode === "navigate") {
      const shell = await c.match("/inicio");
      if (shell) return shell;
    }
    throw err;
  }
}

async function staticCacheFirst(request) {
  const c = await caches.open(STATIC);
  const hit = await c.match(request);
  if (hit) return hit;
  const res = await fetch(request);
  if (res.ok) await c.put(request, res.clone());
  return res;
}

self.addEventListener("fetch", (event) => {
  const request = event.request;
  if (request.method !== "GET") return;
  const url = new URL(request.url);

  if (url.pathname.startsWith("/api/v1/")) {
    if (API_DENY.test(url.pathname) || !API_ALLOW.some((r) => r.test(url.pathname))) return;
    event.respondWith(apiNetworkFirst(request));
    return;
  }
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith("/_next/static/") || url.pathname.startsWith("/icons/")) {
    event.respondWith(staticCacheFirst(request));
    return;
  }
  // Pantallas públicas de verificación, impresión y expediente: siempre en línea.
  if (/^\/(verificar|imprimir|expediente|encuesta|reportar)(\/|$)/.test(url.pathname)) return;
  if (request.mode === "navigate" || request.headers.get("RSC") === "1") {
    event.respondWith(pageNetworkFirst(request));
  }
});
