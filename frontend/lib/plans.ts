// Utilidades del módulo de planes (SUS).

import type { PlanLimits, PlanSummary } from "./types";

export const MATRIX_PLANS = ["E", "P", "C"] as const;

export const CATALOG_GROUPS: Record<number, string> = {
  1: "Núcleo de la plataforma",
  2: "Planificación del sistema de gestión",
  3: "Gestión de riesgos",
  4: "Operación en campo",
  5: "Personas y salud ocupacional",
  6: "Eventos y mejora continua",
  7: "Contratistas y terceros",
  8: "Diferenciadores e integraciones",
};

export const MODULE_KIND: Record<string, string> = { E: "Plan", P: "Plan", C: "Plan", "+": "Complemento", K: "Plan Consultora", I: "Interno" };

export const LIMIT_LABELS: Record<keyof PlanLimits, { label: string; unit?: string }> = {
  max_workers: { label: "Trabajadores" },
  max_companies: { label: "Empresas" },
  max_sites: { label: "Sedes" },
  max_management_users: { label: "Usuarios de gestión" },
  storage_gb: { label: "Almacenamiento", unit: "GB" },
  ai_requests_month: { label: "Consultas de IA al mes" },
  qr_report_points: { label: "Puntos de reporte QR" },
};

/** Colores de acento por plan (paleta del prototipo). */
export const PLAN_ACCENT: Record<string, { bar: string; soft: string; text: string }> = {
  E: { bar: "#22a35a", soft: "var(--ok-soft)", text: "var(--ok)" },
  P: { bar: "#1b6fb7", soft: "var(--brand-soft)", text: "var(--brand-strong)" },
  C: { bar: "#0d3b6e", soft: "var(--na-soft)", text: "var(--fg)" },
  K: { bar: "#f4b90f", soft: "var(--amber-soft)", text: "var(--amber-ink)" },
};

/** «S/ 1 250,00» o «Por definir» (RN-SUS-01). */
export function formatPrice(value: string | null): string {
  if (value === null || value === "") return "Por definir";
  return "S/ " + Number(value).toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function formatLimit(v: number | null, unit?: string): string {
  if (v === null) return "Sin límite";
  return v.toLocaleString("es-PE") + (unit ? ` ${unit}` : "");
}

export function periodsText(p: PlanSummary): string {
  const map = { monthly: "mensual", annual: "anual" } as const;
  return p.billing_periods.map((x) => map[x]).join(" y ");
}
