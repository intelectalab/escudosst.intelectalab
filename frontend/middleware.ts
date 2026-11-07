// Redirecciones del sitio (SCR-WEB-27): antes de mostrar una página pública, si su ruta tiene una redirección
// activa, se envía al destino con 301 (permanente) o 302 (temporal). La lista se guarda un minuto en memoria; solo
// cuando una ruta coincide se consulta a la API, que además suma la visita.

import { type NextRequest, NextResponse } from "next/server";

const API = process.env.API_INTERNAL_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
const TTL_MS = 60_000;

let cache: { at: number; sources: Set<string> } | null = null;

async function sources(): Promise<Set<string>> {
  if (cache && Date.now() - cache.at < TTL_MS) return cache.sources;
  try {
    const res = await fetch(`${API}/api/v1/public/site/redirects`, { headers: { Accept: "application/json" }, cache: "no-store" });
    const rows = res.ok ? ((await res.json()) as { data: { source_path: string }[] }).data : [];
    cache = { at: Date.now(), sources: new Set(rows.map((r) => r.source_path)) };
  } catch {
    cache = { at: Date.now(), sources: cache?.sources ?? new Set() };   // si la API no responde, el sitio sigue
  }
  return cache.sources;
}

export async function middleware(req: NextRequest) {
  const raw = req.nextUrl.pathname;
  const path = raw.length > 1 ? raw.replace(/\/$/, "").toLowerCase() : raw;
  if (!(await sources()).has(path)) return NextResponse.next();

  try {
    const res = await fetch(`${API}/api/v1/public/site/redirects/resolve?path=${encodeURIComponent(path)}`, { headers: { Accept: "application/json" }, cache: "no-store" });
    if (!res.ok) return NextResponse.next();
    const { data } = (await res.json()) as { data: { target_url: string; status_code: 301 | 302 } };
    const target = data.target_url.startsWith("/") ? new URL(data.target_url + req.nextUrl.search, req.url) : new URL(data.target_url);
    return NextResponse.redirect(target, data.status_code);
  } catch {
    return NextResponse.next();
  }
}

// Solo rutas del sitio público: ni panel, ni aplicación, ni archivos internos o estáticos.
export const config = {
  matcher: ["/((?!_next/|api/|plataforma|inicio|m/|cuenta|soporte|login|invitacion|recuperar|restablecer|confirmar-correo|select-company|favicon|sitemap.xml|robots.txt|.*\\.[a-z0-9]+$).*)"],
};
