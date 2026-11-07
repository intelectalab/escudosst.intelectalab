// Centro de ayuda (SCR-WEB-32 a 34): datos públicos y el formato simple del cuerpo de los artículos.

const API = process.env.API_INTERNAL_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export type HelpCategory = { slug: string; name: string; icon: string | null; description: string | null; articles_count: number };
export type HelpListItem = { slug: string; title: string; category_slug: string; excerpt: string; video_url: string | null; screen_code: string | null; published_at: string };
export type HelpArticle = {
  slug: string;
  title: string;
  body: string;
  video_url: string | null;
  published_at: string;
  updated_at: string;
  seo_title: string;
  seo_description: string | null;
  category: { slug: string; name: string };
  related: { slug: string; title: string }[];
};

async function get<T>(path: string, fallback: T): Promise<T> {
  try {
    const res = await fetch(`${API}/api/v1/public/${path}`, process.env.NODE_ENV === "production" ? { next: { revalidate: 60 } } : { cache: "no-store" });
    if (!res.ok) return fallback;
    return ((await res.json()) as { data: T }).data;
  } catch {
    return fallback;
  }
}

export const getHelpCategories = () => get<HelpCategory[]>("help/categories", []);
export const getHelpArticle = (slug: string) => get<HelpArticle | null>(`help/articles/${encodeURIComponent(slug)}`, null);
export function getHelpArticles(opts: { categoria?: string; q?: string } = {}) {
  const p = new URLSearchParams();
  if (opts.categoria) p.set("categoria", opts.categoria);
  if (opts.q) p.set("q", opts.q);
  const qs = p.toString();
  return get<HelpListItem[]>(`help/articles${qs ? `?${qs}` : ""}`, []);
}

export type HelpNode = { t: "h"; text: string } | { t: "p"; text: string } | { t: "ul"; items: string[] };

/**
 * El cuerpo es texto simple: párrafos separados por una línea vacía, «## » para un subtítulo y «- » para cada punto
 * de una lista. Se dibuja sin HTML (nadie puede colar código).
 */
export function parseHelpBody(body: string): HelpNode[] {
  const out: HelpNode[] = [];
  for (const block of body.replace(/\r\n/g, "\n").split(/\n\s*\n/)) {
    const lines = block.split("\n").map((l) => l.trim()).filter(Boolean);
    let para: string[] = [];
    let list: string[] = [];
    const flush = () => {
      if (para.length) out.push({ t: "p", text: para.join(" ") });
      if (list.length) out.push({ t: "ul", items: list });
      para = [];
      list = [];
    };
    for (const l of lines) {
      if (/^#{1,3}\s+/.test(l)) {
        flush();
        out.push({ t: "h", text: l.replace(/^#+\s+/, "") });
      } else if (/^[-•*]\s+/.test(l)) {
        if (para.length) flush();
        list.push(l.replace(/^[-•*]\s+/, ""));
      } else {
        if (list.length) flush();
        para.push(l);
      }
    }
    flush();
  }
  return out;
}

/** Códigos de pantalla de la aplicación que ya existen (para la ayuda contextual). */
export const APP_SCREENS: Record<string, string> = {
  "SCR-DSH-01": "Inicio de la empresa",
  "SCR-USR-05": "Mi perfil y seguridad",
  "SCR-USR-06": "Selector de empresa",
  "SCR-USR-01": "Lista de usuarios",
  "SCR-USR-02": "Invitar usuario",
  "SCR-SUS-01": "Mi suscripción",
  "SCR-SUS-03": "Facturas y pagos",
};
