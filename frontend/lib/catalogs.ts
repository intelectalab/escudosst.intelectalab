// Catálogos globales (SCR-SUS-08): tipos y textos.

export type LegalParameter = {
  id: string;
  key: string;
  value: string;
  value_type: string;
  unit: string | null;
  description: string;
  legal_reference: string | null;
  source: string | null;
  sector: string | null;
  valid_from: string;
  valid_to: string | null;
  to_confirm: boolean;
  confirmed_by: string | null;
  confirmed_at: string | null;
  norm_id: string | null;
  norm_number: string | null;
  norm_title: string | null;
  is_current: boolean;
  area: "sst" | "privacy" | "sales" | "web" | "billing";
  kind: "legal" | "internal" | "pending" | "reference";
};

export const PARAM_AREAS: Record<LegalParameter["area"], string> = {
  sst: "Seguridad y salud en el trabajo",
  privacy: "Datos personales",
  sales: "Venta de cursos",
  web: "Sitio web",
  billing: "Cobro de suscripciones",
};

export type LegalNorm = {
  id: string;
  norm_type: string;
  number: string;
  title: string;
  issuing_entity: string | null;
  published_on: string | null;
  effective_on: string | null;
  repealed_on: string | null;
  scope: "general" | "sector";
  sector: string | null;
  summary: string | null;
  official_url: string | null;
  status: "current" | "amended" | "repealed";
  parameters_count: number;
};

export type Hazard = {
  id: string;
  code: string;
  hazard_family: string;
  hazard: string;
  risk: string;
  possible_consequence: string | null;
  typical_controls: string[];
  sector: string | null;
  source: string | null;
  is_active: boolean;
};

export type SectorPack = {
  id: string;
  code: string;
  name: string;
  sector: string;
  description: string | null;
  legal_references: string | null;
  version_number: number;
  status: "draft" | "published" | "retired";
  items: { item_type: string; code: string; name: string }[];
};

export type Holiday = { id: string; holiday_on: string; name: string; scope: string; is_non_working: boolean; to_confirm: boolean };

export const NORM_TYPES: Record<string, string> = {
  law: "Ley",
  supreme_decree: "Decreto supremo",
  ministerial_resolution: "Resolución ministerial",
  technical_standard: "Norma técnica",
  ordinance: "Ordenanza",
  directive: "Directiva",
  other: "Otra",
};

export const NORM_STATUS: Record<string, { label: string; tone: "ok" | "amber" | "na" }> = {
  current: { label: "Vigente", tone: "ok" },
  amended: { label: "Modificada", tone: "amber" },
  repealed: { label: "Derogada", tone: "na" },
};

export const HAZARD_FAMILIES: Record<string, string> = {
  physical: "Físico",
  chemical: "Químico",
  biological: "Biológico",
  ergonomic: "Ergonómico",
  psychosocial: "Psicosocial",
  mechanical: "Mecánico",
  electrical: "Eléctrico",
  locative: "Locativo",
  fire_explosion: "Incendio y explosión",
  natural: "Natural",
  public_safety: "Seguridad pública",
  vehicular: "Vehicular",
  other: "Otro",
};

export const PACK_STATUS: Record<string, { label: string; tone: "ok" | "amber" | "na" }> = {
  published: { label: "Publicado", tone: "ok" },
  draft: { label: "Borrador", tone: "amber" },
  retired: { label: "Retirado", tone: "na" },
};

export const PACK_ITEM_TYPES: Record<string, string> = {
  hazard: "Peligros",
  inspection_template: "Inspecciones",
  permit_type: "Permisos de trabajo",
  training_topic: "Capacitaciones",
  document_template: "Documentos",
  legal_requirement: "Requisitos legales",
  ppe_item: "EPP",
  kpi: "Indicadores",
  credential_requirement: "Requisitos de fotocheck",
};

export const VALUE_TYPES: Record<string, string> = {
  integer: "número entero",
  decimal: "número decimal",
  boolean: "sí / no",
  text: "texto",
  json: "estructura",
  hours: "horas",
  days: "días",
  business_days: "días hábiles",
  months: "meses",
  years: "años",
};

/** Fecha «2030-01-01» → «01/01/2030» sin cambios de zona horaria. */
export function dateOnly(d: string | null): string {
  if (!d) return "—";
  const [y, m, day] = d.slice(0, 10).split("-");
  return `${day}/${m}/${y}`;
}
