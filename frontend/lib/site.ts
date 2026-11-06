// Sitio web público (M-WEB): lectura de datos desde el servidor de Next (sin sesión) y utilidades.
// Todo sale de la API pública (/public/*), que a su vez lee lo que el dueño edita en el panel.

const API = process.env.API_INTERNAL_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export type SiteSettings = {
  legal_name: string;
  trade_name: string;
  ruc: string;
  legal_address: string | null;
  primary_domain: string | null;
  contact_email: string | null;
  support_email: string | null;
  contact_phone: string | null;
  whatsapp_number: string | null;
  whatsapp_default_message: string | null;
  blog_url: string | null;
  interactive_demo_url: string | null;
  complaints_officer_name: string | null;
  announcement_bar: { text?: string; link?: string; active?: boolean; until?: string | null } | null;
  social_links: Record<string, string>;
  logo_file_id: string | null;
  logo_dark_file_id: string | null;
  favicon_file_id: string | null;
};

export type MenuItem = { label: string; url: string };

export type SiteData = { settings: SiteSettings; products: { name: string; description: string | null; url: string | null }[]; menu?: MenuItem[] };

/** Dirección pública (para el navegador) de un archivo de marca subido en el panel: logo o favicon. */
export function assetUrl(id: string | null | undefined): string | null {
  return id ? `${process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000"}/api/v1/public/site/assets/${id}` : null;
}

export type PublicPlan = {
  code: string;
  name: string;
  description: string | null;
  price: string | null;
  price_label: string | null;
  currency: string;
  billing_periods: string[];
  limits: Record<string, number | null>;
  modules_count: number;
};

export type Pricing = {
  /** % de descuento al pagar el año completo (0 = sin descuento: el sitio no anuncia ninguno). */
  annual_discount_pct?: number;
  plans: PublicPlan[];
  addons: { code: string; name: string; description: string | null; per_sector: boolean; available: boolean; price: string | null; price_label: string | null; currency: string }[];
  comparison: { module_code: string; number: string; name: string; group_number: number; is_addon: boolean; plans: Record<string, boolean> }[];
};

export type PublicModule = { code: string; name: string; solves: string | null; group_number: number; plan_code: string; is_addon: boolean; from_plan: string | null };

async function get<T>(path: string, fallback: T): Promise<T> {
  try {
    // En producción se guarda 30 s para que el sitio sea rápido; en desarrollo se lee siempre lo último.
    const res = await fetch(`${API}/api/v1/public/${path}`, process.env.NODE_ENV === "production" ? { next: { revalidate: 30 } } : { cache: "no-store" });
    if (!res.ok) return fallback;
    return ((await res.json()) as { data: T }).data;
  } catch {
    return fallback;
  }
}

export const getSite = () =>
  get<SiteData>("site", { settings: { legal_name: "GRUPO DE SOLUCIONES CREATIVAS SAC", trade_name: "EscudoSST", ruc: "20607431117" } as SiteSettings, products: [] });
export const getPricing = () => get<Pricing>("pricing", { annual_discount_pct: 0, plans: [], addons: [], comparison: [] });

export type BillingPeriod = "monthly" | "annual";

/** Precio de un periodo a partir del precio mensual de referencia: anual = 12 × mensual − descuento anual. */
export function periodPrice(monthly: number, period: BillingPeriod, discountPct = 0): number {
  return period === "annual" ? Math.round(monthly * 12 * (1 - discountPct / 100) * 100) / 100 : monthly;
}

export const soles = (n: number) => `S/ ${n.toLocaleString("es-PE", { maximumFractionDigits: 2 })}`;
export const getModules = () => get<PublicModule[]>("modules", []);

export type Faq = { topic: string; question: string; answer: string };
export type Testimonial = { author_name: string; author_role: string | null; company_name: string | null; quote: string; rating: number | null; is_example: boolean };
export const getFaqs = () => get<Faq[]>("faqs", []);
export const getTestimonials = () => get<Testimonial[]>("testimonials", []);

export type PageSummary = { slug: string; page_type: string; sector_code: string | null; title: string; summary: string | null };
export const getPages = (tipo: "sector" | "product" | "landing") => get<PageSummary[]>(`pages?tipo=${tipo}`, []);
export const getPage = (slug: string) => get<import("@/lib/pages").PublicPage | null>(`pages/${slug}`, null);

/** Enlace de WhatsApp con mensaje (wa.me). Null si el dueño no configuró el número. */
export function whatsappLink(s: SiteSettings, message?: string | null): string | null {
  if (!s.whatsapp_number) return null;
  const text = message ?? s.whatsapp_default_message ?? "Hola, quiero conocer EscudoSST";
  return `https://wa.me/${s.whatsapp_number}?text=${encodeURIComponent(text)}`;
}

/**
 * Destino de «Prueba gratis», «Agenda una demo» y «Solicitar cotización»: los formularios propios del sitio
 * (/prueba-gratis y /demo). `detail` es el código del plan (E, P, C, K) o el nombre del complemento.
 * El WhatsApp sigue disponible en el botón flotante.
 */
export function contactHref(_s: SiteSettings, intent: "trial" | "demo" | "quote", detail?: string): string {
  if (intent === "trial") return "/prueba-gratis";
  if (intent === "demo") return "/demo";
  const q = new URLSearchParams({ tipo: "cotizacion" });
  if (detail) q.set(["E", "P", "C", "K"].includes(detail) ? "plan" : "interes", detail);
  return `/demo?${q}`;
}

export const PLAN_RANK: Record<string, number> = { E: 1, P: 2, C: 3, K: 4 };

/** Casos de éxito (SCR-WEB-09): solo los publicados, con autorización del cliente o rotulados «Ejemplo». */
export type StoryResult = { value: string; label: string };
export type SuccessStory = {
  slug: string;
  title: string;
  client_name: string;
  sector: string;
  logo_file_id: string | null;
  challenge: string;
  solution: string;
  results: StoryResult[];
  quote: string | null;
  project_year: number | null;
  modules: string[];
  is_example: boolean;
  published_at: string | null;
};
export const STORY_SECTORS: Record<string, string> = {
  construction: "Construcción",
  mining: "Minería",
  agroindustry: "Agroindustria",
  manufacturing: "Manufactura",
  retail: "Retail",
  services: "Servicios",
  office: "Oficinas",
  health: "Salud",
  education: "Educación",
  transport: "Transporte",
  energy: "Energía",
  other: "Otros",
};
export const getStories = (sector?: string) => get<SuccessStory[]>(`success-stories${sector ? `?sector=${encodeURIComponent(sector)}` : ""}`, []);
export const getStory = (slug: string) => get<SuccessStory | null>(`success-stories/${slug}`, null);
