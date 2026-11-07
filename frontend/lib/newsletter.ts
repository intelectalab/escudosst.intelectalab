// Boletín normativo SST (SCR-WEB-36): ediciones enviadas y temas.

const API = process.env.API_INTERNAL_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export const NL_TOPICS: Record<string, string> = { regulations: "Cambios en la normativa", sunafil: "SUNAFIL e inspecciones", sectors: "Novedades por sector" };

export type NlIssueItem = { slug: string; subject: string; preheader: string | null; topics_json: string[]; sent_at: string; excerpt: string };
export type NlIssue = { slug: string; subject: string; preheader: string | null; body: string; topics_json: string[]; sent_at: string };

async function get<T>(path: string, fallback: T): Promise<T> {
  try {
    const res = await fetch(`${API}/api/v1/public/${path}`, process.env.NODE_ENV === "production" ? { next: { revalidate: 60 } } : { cache: "no-store" });
    if (!res.ok) return fallback;
    return ((await res.json()) as { data: T }).data;
  } catch {
    return fallback;
  }
}

export const getNlIssues = () => get<NlIssueItem[]>("newsletter/issues", []);
export const getNlIssue = (slug: string) => get<NlIssue | null>(`newsletter/issues/${encodeURIComponent(slug)}`, null);
export const nlDate = (d: string) => new Date(d).toLocaleDateString("es-PE", { day: "numeric", month: "long", year: "numeric", timeZone: "America/Lima" });
