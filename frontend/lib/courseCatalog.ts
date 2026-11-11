// Catálogo público de cursos de una empresa (VTA · SCR-VTA-12/15): lectura desde el servidor de Next.

const API = process.env.API_INTERNAL_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export type CatalogOffer = {
  slug: string;
  title: string;
  short_description: string | null;
  list_price: number;
  sale_price: number | null;
  price: number;
  price_includes_igv: boolean;
  currency: "PEN";
  access_days: number | null;
  seats_left: number | null;
  allows_company_purchase: boolean;
  category: string | null;
  hours: number | null;
  delivery_mode: "self_paced" | "instructor_led" | "blended";
  certificate: boolean;
};

export type CatalogCompany = { name: string; catalog: string };

export type CatalogOfferDetail = CatalogOffer & {
  min_company_seats: number | null;
  summary: string | null;
  description: string | null;
  objectives: string | null;
  target_audience: string | null;
  sections: { title: string; description: string | null }[];
};

/** «unavailable»: la empresa existe pero no tiene la venta de cursos activa. */
export type CatalogResult<T> = { ok: true; data: T } | { ok: false; reason: "not_found" | "unavailable" | "error" };

async function get<T>(path: string): Promise<CatalogResult<T>> {
  try {
    const res = await fetch(`${API}/api/v1/public/courses/${path}`, { headers: { Accept: "application/json" }, cache: "no-store" });
    if (res.ok) return { ok: true, data: ((await res.json()) as { data: T }).data };
    const j = (await res.json().catch(() => ({}))) as { type?: string };
    return { ok: false, reason: j.type === "catalog_unavailable" ? "unavailable" : res.status === 404 ? "not_found" : "error" };
  } catch {
    return { ok: false, reason: "error" };
  }
}

export const getCatalog = (catalog: string) => get<{ company: CatalogCompany; offers: CatalogOffer[] }>(encodeURIComponent(catalog));

export const getCatalogOffer = (catalog: string, offer: string) =>
  get<{ company: CatalogCompany; offer: CatalogOfferDetail; others: CatalogOffer[]; payment_instructions: string | null }>(
    `${encodeURIComponent(catalog)}/${encodeURIComponent(offer)}`,
  );

export const soles = (n: number) => new Intl.NumberFormat("es-PE", { style: "currency", currency: "PEN" }).format(n);

export const MODALITY: Record<string, string> = { self_paced: "Virtual a tu ritmo", instructor_led: "Con capacitador", blended: "Mixto" };
