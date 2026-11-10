// SEO técnico (SCR-WEB-27, RN-WEB-07): datos para sitemap.xml y robots.txt.

const API = process.env.API_INTERNAL_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export type SitemapData = { domain: string | null; entries: { path: string; kind: string; last_modified: string | null }[]; noindex: string[] };

/** Páginas fijas del sitio (no salen de la base). Las de la base (sectores, soluciones, casos, políticas) las da la API. */
export const STATIC_PATHS = ["/", "/producto", "/como-funciona", "/planes", "/complementos", "/sectores", "/casos", "/recursos", "/herramientas/calculadora-multas", "/herramientas/autodiagnostico", "/preguntas-frecuentes", "/ayuda", "/partners", "/boletin", "/confianza", "/estado", "/demo", "/demo/agendar", "/prueba-gratis", "/libro-de-reclamaciones", "/integraciones", "/novedades", "/webinars"];

/** Rutas que no deben aparecer en buscadores: panel, aplicación, acceso, enlaces personales y seguimiento de reclamos. */
export const PRIVATE_PATHS = [
  "/plataforma",
  "/inicio",
  "/m/",
  "/cuenta",
  "/soporte",
  "/select-company",
  "/login",
  "/invitacion",
  "/recuperar",
  "/restablecer",
  "/confirmar-correo",
  "/casos/compartir/",
  "/casos/retirar/",
  "/libro-de-reclamaciones/seguimiento",
  "/boletin/confirmar/",
  "/boletin/baja/",
  "/demo/gestionar/",
  "/webinars/gestionar/",
  "/cotizacion/",
  "/verificar/",
  "/encuesta/",
  "/imprimir/",
  "/l/",
  "/api/",
];

export async function getSitemapData(): Promise<SitemapData> {
  try {
    const res = await fetch(`${API}/api/v1/public/site/sitemap`, process.env.NODE_ENV === "production" ? { next: { revalidate: 300 } } : { cache: "no-store" });
    if (!res.ok) throw new Error();
    return ((await res.json()) as { data: SitemapData }).data;
  } catch {
    return { domain: null, entries: [], noindex: [] };
  }
}

/** Dirección pública del sitio: NEXT_PUBLIC_SITE_URL o el dominio del panel (Sitio web › Contacto). */
export function siteUrl(domain: string | null): string {
  const env = process.env.NEXT_PUBLIC_SITE_URL;
  if (env) return env.replace(/\/$/, "");
  return domain ? `https://${domain.replace(/^https?:\/\//, "").replace(/\/$/, "")}` : "http://localhost:3000";
}
