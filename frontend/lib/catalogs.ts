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
  /** Dónde lo usa el sistema, en lenguaje simple. */
  used_in: string[];
};

/** Parámetros cuyo valor es una lista de familias de peligro del catálogo base (se editan con casillas). */
export const FAMILY_LIST_PARAMS = ["minor_forbidden_hazard_families"];

/** Valor propio de una empresa para un parámetro legal. */
export type ParamOverride = { company_id: string; company_name: string; id: string; value: string; valid_from: string };

/** Requisito del catálogo de una norma (lo usa «Agregar desde el catálogo» de la matriz legal). */
export type NormRequirement = {
  id: string;
  article: string | null;
  requirement: string;
  topic: string | null;
  applicability: string | null;
  evidence_expected: string | null;
  frequency: string | null;
  sort_order: number;
  to_confirm: boolean;
  confirmed_by: string | null;
  confirmed_at: string | null;
  archived: boolean;
};

export type ScatLevel = "contact" | "immediate_act" | "immediate_condition" | "basic_personal" | "basic_job" | "nac";

export type ScatItem = {
  id: string;
  level: ScatLevel;
  code: string;
  label: string;
  explanation: string | null;
  category: string | null;
  sort_order: number;
  is_active: boolean;
  to_confirm: boolean;
  confirmed_by: string | null;
  confirmed_at: string | null;
};

/** Niveles del catálogo SCAT: nombre, prefijo del código y para qué sirve. */
export const SCAT_LEVELS: Record<ScatLevel, { label: string; prefix: string; hint: string }> = {
  contact: { label: "Tipo de contacto", prefix: "C", hint: "Cómo ocurrió el contacto con la energía o sustancia (paso 1 del asistente)." },
  immediate_act: { label: "Actos subestándar", prefix: "A", hint: "Causas inmediatas: lo que hizo la persona." },
  immediate_condition: { label: "Condiciones subestándar", prefix: "S", hint: "Causas inmediatas: cómo estaba el lugar, el equipo o el entorno." },
  basic_personal: { label: "Factores personales", prefix: "FP", hint: "Causas básicas que explican el acto o la condición desde la persona." },
  basic_job: { label: "Factores del trabajo", prefix: "FT", hint: "Causas básicas que vienen de la organización del trabajo." },
  nac: { label: "Necesidades de acción de control", prefix: "NAC", hint: "Elemento del sistema de gestión que falló (programa, estándar o cumplimiento)." },
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
  requirements_count?: number;
  requirements_to_confirm?: number;
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
