// Centro de confianza (SCR-WEB-37) y estado del servicio (SCR-WEB-38).

const API = process.env.API_INTERNAL_URL ?? process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

export type Subprocessor = { name: string; purpose: string; country: string; data_categories: string; transfer_basis: string; website: string | null; to_validate: boolean };
export type TrustData = { subprocessors: Subprocessor[]; documents: { slug: string; title: string; version: string | null; published_at: string }[] };
export type ComponentStatus = "operational" | "degraded" | "partial_outage" | "major_outage" | "maintenance";
export type StatusData = {
  overall_status: ComponentStatus;
  components: { code: string; name: string; description: string | null; current_status: ComponentStatus }[];
  incidents: {
    id: string;
    title: string;
    impact: "minor" | "major" | "critical" | "maintenance";
    status: "investigating" | "identified" | "monitoring" | "resolved";
    started_at: string;
    resolved_at: string | null;
    components: string[];
    updates: { status: string; message: string; published_at: string }[];
  }[];
};

export const STATUS_LABEL: Record<ComponentStatus, string> = { operational: "Operativo", degraded: "Degradado", partial_outage: "Caída parcial", major_outage: "Caída total", maintenance: "Mantenimiento" };
export const STATUS_TONE: Record<ComponentStatus, { dot: string; soft: string; text: string; icon: string }> = {
  operational: { dot: "bg-[#22A35A]", soft: "bg-[#E8F7EE]", text: "text-[#17703e]", icon: "check" },
  maintenance: { dot: "bg-[#1F7FD1]", soft: "bg-[#EAF3FC]", text: "text-[#176bb3]", icon: "clock" },
  degraded: { dot: "bg-[#F4B90F]", soft: "bg-[#FFF5D6]", text: "text-[#8a6a00]", icon: "bell" },
  partial_outage: { dot: "bg-[#F08A24]", soft: "bg-[#FDEBD9]", text: "text-[#9a4d05]", icon: "bell" },
  major_outage: { dot: "bg-[#E2453C]", soft: "bg-[#FDECEB]", text: "text-[#a3261f]", icon: "close" },
};
export const OVERALL_TEXT: Record<ComponentStatus, string> = {
  operational: "Todos los sistemas funcionan con normalidad",
  maintenance: "Hay un mantenimiento en curso",
  degraded: "Algunos servicios van más lentos de lo normal",
  partial_outage: "Hay una interrupción parcial",
  major_outage: "Hay una interrupción importante",
};
export const INCIDENT_LABEL: Record<string, string> = { investigating: "Investigando", identified: "Causa identificada", monitoring: "En observación", resolved: "Resuelto" };
export const IMPACT_LABEL: Record<string, string> = { minor: "Menor", major: "Mayor", critical: "Crítico", maintenance: "Mantenimiento" };

const COUNTRIES: Record<string, string> = { PE: "Perú", US: "Estados Unidos", DE: "Alemania", CA: "Canadá", FR: "Francia", NL: "Países Bajos", GB: "Reino Unido", IE: "Irlanda", BR: "Brasil", CL: "Chile", MX: "México", ES: "España", FI: "Finlandia", SG: "Singapur" };
export const countryName = (c: string) => COUNTRIES[c?.trim()] ?? c;

async function get<T>(path: string, fallback: T, revalidate: number): Promise<T> {
  try {
    const res = await fetch(`${API}/api/v1/public/${path}`, process.env.NODE_ENV === "production" ? { next: { revalidate } } : { cache: "no-store" });
    if (!res.ok) return fallback;
    return ((await res.json()) as { data: T }).data;
  } catch {
    return fallback;
  }
}

export const getTrust = () => get<TrustData>("trust", { subprocessors: [], documents: [] }, 300);
/** null si la API no responde: la página lo dice en vez de mostrar «todo operativo». */
export const getStatus = () => get<StatusData | null>("status", null, 30);
export const when = (d: string) => new Date(d).toLocaleString("es-PE", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit", timeZone: "America/Lima" });
