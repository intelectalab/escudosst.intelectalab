// Ayudas de los recorridos E2E-01…E2E-20 (docs/12 §5): sesiones de cualquier usuario (no solo los fijos de
// support/auth.ts), varias personas en la misma prueba (un contexto de navegador por persona), llamadas a la API para
// preparar o comprobar lo que hace «la otra persona», subida de archivos, código TOTP para el segundo factor real y
// sufijos únicos para no chocar con los datos de demostración.

import { createHmac } from "node:crypto";
import { execFileSync } from "node:child_process";
import type { APIRequestContext, Browser, BrowserContext, Page } from "@playwright/test";
import { API_URL } from "../fixtures";
import { API_CONTAINER, DEMO_COMPANY, psql, sessionFor, type UserKey } from "../support/auth";

export { API_URL, DEMO_COMPANY, psql };

export const PASSWORD = process.env.E2E_PASSWORD ?? "EscudoDemo2026";

/** Sufijo único por corrida (los registros de prueba se reconocen por «E2E <sufijo>»). */
export function uniq(prefix = "E2E"): string {
  return `${prefix} ${Date.now().toString(36).toUpperCase()}${Math.floor(Math.random() * 1296).toString(36).toUpperCase()}`;
}

export type Who = { token: string; company: string | null };

/** Sesión de un usuario fijo (support/auth.ts). */
export function who(key: UserKey): Who {
  const s = sessionFor(key);
  return { token: s.token, company: s.company_id };
}

/** Sesión de cualquier usuario por correo (escudo:e2e-token, sin doble factor; solo desarrollo). */
export function whoByEmail(email: string, company?: string | null): Who {
  const args = ["exec", API_CONTAINER, "php", "artisan", "escudo:e2e-token", email];
  if (company) args.push(`--company=${company}`);
  const out = execFileSync("docker", args, { encoding: "utf8" });
  const line = out.trim().split(/\r?\n/).filter((l) => l.startsWith("{")).pop();
  if (!line) throw new Error(`escudo:e2e-token no devolvió un token para ${email}: ${out}`);
  const parsed = JSON.parse(line) as { token: string; company_id: string | null };
  return { token: parsed.token, company: parsed.company_id };
}

/** Comando artisan dentro del contenedor de la API (p. ej. procesar la cola o un comando programado). */
export function artisan(...args: string[]): string {
  return execFileSync("docker", ["exec", API_CONTAINER, "php", "artisan", ...args], { encoding: "utf8" });
}

type Init = { method?: string; data?: unknown; headers?: Record<string, string>; multipart?: Record<string, string | number | boolean | { name: string; mimeType: string; buffer: Buffer }> };

/** Respuesta de la API con su estado (para comprobar también los rechazos). */
export async function apiRaw(req: APIRequestContext, w: Who | null, path: string, init: Init = {}) {
  const headers: Record<string, string> = { Accept: "application/json", ...(init.headers ?? {}) };
  if (w?.token) headers.Authorization = `Bearer ${w.token}`;
  if (w?.company) headers["X-Company-Id"] = w.company;
  const res = await req.fetch(path.startsWith("http") ? path : `${API_URL}${path}`, {
    method: init.method ?? "GET",
    headers,
    data: init.multipart ? undefined : init.data,
    multipart: init.multipart,
    timeout: 120_000,
  });
  const text = await res.text();
  let body: unknown = null;
  try {
    body = text ? JSON.parse(text) : null;
  } catch {
    body = text;
  }
  return { status: res.status(), body: body as any, headers: res.headers() }; // eslint-disable-line @typescript-eslint/no-explicit-any
}

/** Llamada a la API que debe salir bien (2xx); devuelve el cuerpo JSON. */
export async function api<T = any>(req: APIRequestContext, w: Who | null, path: string, init: Init = {}): Promise<T> { // eslint-disable-line @typescript-eslint/no-explicit-any
  const r = await apiRaw(req, w, path, init);
  if (r.status < 200 || r.status >= 300) throw new Error(`${init.method ?? "GET"} ${path} → ${r.status}: ${JSON.stringify(r.body).slice(0, 600)}`);
  return r.body as T;
}

/** Sube un archivo (POST /files) y devuelve su id. */
export async function uploadFile(req: APIRequestContext, w: Who, module: string, name = "evidencia.pdf", mimeType = "application/pdf", buffer: Buffer = minimalPdf()): Promise<string> {
  const r = await api<{ data: { id: string } }>(req, w, "/files", { method: "POST", multipart: { module, file: { name, mimeType, buffer } } });
  return r.data.id;
}

/** PDF mínimo válido (el servidor revisa el tipo real del contenido). */
export function minimalPdf(text = "Evidencia E2E"): Buffer {
  const body = `%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]/Contents 4 0 R>>endobj\n4 0 obj<</Length ${text.length + 24}>>stream\nBT /F1 12 Tf 10 100 Td (${text}) Tj ET\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n`;
  return Buffer.from(body, "latin1");
}

/** Imagen PNG de 1 × 1 (fotos de evidencia). */
export function pngPixel(): Buffer {
  return Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=", "base64");
}

/** Deja la sesión de la persona en la pestaña (como la fixture «page») antes de abrir la aplicación. */
export async function withSession(target: Page | BrowserContext, w: Who) {
  await target.addInitScript(
    ([token, company]) => {
      if (window.sessionStorage.getItem("escudo.e2e")) return;
      window.sessionStorage.setItem("escudo.e2e", "1");
      window.sessionStorage.setItem("escudo.token", token);
      if (company) window.sessionStorage.setItem("escudo.companyId", company);
      try {
        window.localStorage.setItem("escudo.updates.seen", String(Date.now()));
      } catch {
        /* sin almacenamiento */
      }
    },
    [w.token, w.company ?? ""] as const,
  );
}

/** Otra persona en la misma prueba: su propio contexto de navegador con su sesión (o sin sesión, si w es null). */
export async function openAs(browser: Browser, w: Who | null): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ locale: "es-PE", timezoneId: "America/Lima", viewport: { width: 1366, height: 860 }, serviceWorkers: "block" });
  if (w) await withSession(context, w);
  const page = await context.newPage();
  return { context, page };
}

// ---------------------------------------------------------------- Segundo factor (TOTP, RFC 6238)

function base32(secret: string): Buffer {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  let bits = "";
  for (const c of secret.replace(/=+$/, "").toUpperCase()) {
    const v = alphabet.indexOf(c);
    if (v >= 0) bits += v.toString(2).padStart(5, "0");
  }
  const bytes: number[] = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
  return Buffer.from(bytes);
}

/** Código de 6 dígitos de la app de autenticación para el secreto (ventana de 30 s; offset en ventanas). */
export function totp(secret: string, offset = 0, now = Date.now()): string {
  const counter = Math.floor(now / 1000 / 30) + offset;
  const buf = Buffer.alloc(8);
  buf.writeBigUInt64BE(BigInt(counter));
  const h = createHmac("sha1", base32(secret)).update(buf).digest();
  const o = h[h.length - 1] & 0xf;
  const n = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3];
  return String(n % 1_000_000).padStart(6, "0");
}

/** Espera hasta la siguiente ventana TOTP si quedan menos de `margin` segundos (para no mandar un código por vencer). */
export async function freshTotpWindow(margin = 5) {
  const left = 30 - (Math.floor(Date.now() / 1000) % 30);
  if (left < margin) await new Promise((r) => setTimeout(r, (left + 1) * 1000));
}

/** Fecha local (Lima) en formato AAAA-MM-DD, desplazada en días. */
export function day(offset = 0): string {
  const d = new Date(Date.now() + offset * 86_400_000);
  return new Intl.DateTimeFormat("en-CA", { timeZone: "America/Lima" }).format(d);
}
