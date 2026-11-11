// Matriz IPERC (M-IPC): flujo de versiones, cálculo en pantalla y descargas.
// Filas y controles se guardan con el motor de registros (/records/IPC/...); el backend recalcula y valida.

import { api, API_URL, ApiError } from "./api";
import { session } from "./session";

export type Band = { code: string; label: string; min: number; max: number; color: "green" | "amber" | "red" | string };
export type ScaleItem = { value: number; label: string };
export type Factor = { code: string; label: string; levels: ScaleItem[] };

export type Criteria = {
  id?: string;
  name: string;
  method: "pxs" | "pxs_index";
  probability_scale: ScaleItem[];
  severity_scale: ScaleItem[];
  factors: Factor[];
  bands: Band[];
  significant_from: string;
};

export type IpercControl = {
  id: string;
  hierarchy: string;
  description: string;
  is_existing: boolean;
  responsible_user_id: string | null;
  responsible_name: string | null;
  due_on: string | null;
  status: string;
  corrective_action_id: string | null;
};

export type IpercRow = {
  id: string;
  row_number: number;
  process: string | null;
  activity: string;
  task: string;
  is_routine: boolean;
  position_id: string | null;
  position_name: string | null;
  hazard_catalog_id: string | null;
  hazard: string;
  risk: string;
  consequence: string | null;
  exposed_workers: number | null;
  probability_value: number;
  probability_detail: Record<string, number> | null;
  severity_value: number;
  risk_score: number;
  risk_level: string;
  is_significant: boolean;
  residual_probability: number | null;
  residual_severity: number | null;
  residual_score: number | null;
  residual_level: string | null;
  notes: string | null;
  controls: IpercControl[];
};

export type IpercVersion = {
  id: string;
  version_number: number;
  status: "draft" | "in_review" | "approved" | "current" | "under_update" | "obsolete";
  change_reason: string | null;
  participants: { name: string; role?: string }[];
  prepared_at: string | null;
  prepared_by_name: string | null;
  approved_at: string | null;
  approved_by_name: string | null;
  effective_on: string | null;
};

export type Workspace = {
  matrix: {
    id: string;
    code: string;
    title: string;
    process: string | null;
    site_name: string | null;
    area_name: string | null;
    position_name: string | null;
    criteria_name: string;
    next_review_on: string | null;
    current_version_id: string | null;
    status: string;
    position_id?: string | null;
    site_id?: string | null;
    area_id?: string | null;
  };
  criteria: Criteria;
  versions: IpercVersion[];
  version_id: string;
  rows: IpercRow[];
  heatmap: { initial: Record<string, number>; residual: Record<string, number> };
  issues: { row: string | null; text: string }[];
  /** Trabajadores activos del puesto de la matriz (o el mayor «expuestos» de las filas). */
  exposed_workers?: number | null;
  can: { edit: boolean; controls: boolean; submit: boolean; approve: boolean; new_version: boolean; export: boolean };
};

export const VERSION_STATUS: Record<string, string> = {
  draft: "Borrador",
  in_review: "En revisión",
  approved: "Aprobada",
  current: "Vigente",
  under_update: "En actualización",
  obsolete: "Obsoleta",
};

export const HIERARCHY: [string, string][] = [
  ["elimination", "Eliminación"],
  ["substitution", "Sustitución"],
  ["engineering", "Control de ingeniería"],
  ["administrative", "Control administrativo"],
  ["signage", "Señalización"],
  ["ppe", "EPP"],
];

export const CONTROL_STATUS: [string, string][] = [
  ["existing", "Existente"],
  ["proposed", "Propuesto"],
  ["implemented", "Implementado"],
  ["verified", "Verificado"],
  ["cancelled", "Anulado"],
];

export const ipc = {
  workspace: (matrix: string, version?: string) =>
    api<{ data: Workspace }>(`/ipc/matrices/${matrix}/workspace${version ? `?version=${version}` : ""}`, { withCompany: true }),
  newVersion: (matrix: string, change_reason: string) =>
    api<{ data: { id: string } }>(`/ipc/matrices/${matrix}/versions`, { method: "POST", body: { change_reason }, withCompany: true }),
  submit: (version: string, participants: { name: string; role: string }[]) =>
    api<void>(`/ipc/versions/${version}/submit`, { method: "POST", body: { participants }, withCompany: true }),
  sendBack: (version: string, comment: string) => api<void>(`/ipc/versions/${version}/return`, { method: "POST", body: { comment }, withCompany: true }),
  approve: (version: string) =>
    api<{ data: { next_review_on: string; corrective_actions: number } }>(`/ipc/versions/${version}/approve`, { method: "POST", withCompany: true }),
};

/** Descarga la matriz (PDF o Excel) con la sesión y la empresa activas. */
export async function downloadIperc(version: string, ext: "pdf" | "xlsx", filename: string) {
  const headers: Record<string, string> = { Accept: "*/*" };
  const token = session.getToken();
  const company = session.getCompanyId();
  if (token) headers.Authorization = `Bearer ${token}`;
  if (company) headers["X-Company-Id"] = company;
  const res = await fetch(`${API_URL}/api/v1/ipc/versions/${version}/export.${ext}`, { headers });
  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throw new ApiError(res.status, data.type ?? "error", data.title ?? "Error", data.detail);
  }
  const url = URL.createObjectURL(await res.blob());
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

/** Banda de un puntaje (igual que el backend), para el cálculo al instante. */
export function bandOf(c: Criteria, score: number): Band | null {
  return c.bands.find((b) => score >= b.min && score <= b.max) ?? null;
}

export function isSignificant(c: Criteria, level: string): boolean {
  const codes = c.bands.map((b) => b.code);
  const from = codes.indexOf(c.significant_from);
  return from >= 0 && codes.indexOf(level) >= from;
}

export function levelLabel(c: Criteria, level: string | null): string {
  if (!level) return "—";
  return c.bands.find((b) => b.code === level)?.label ?? level;
}

export function levelClass(c: Criteria, level: string | null): string {
  const color = c.bands.find((b) => b.code === level)?.color;
  if (color === "green") return "bg-ok-soft text-ok";
  if (color === "amber") return "bg-amber-soft text-amber-ink";
  if (color === "red") return "bg-bad-soft text-bad";
  return "bg-bg text-muted";
}

/** Valores posibles de probabilidad (para el mapa de calor y el residual). */
export function probabilityValues(c: Criteria): number[] {
  if (c.method === "pxs_index") {
    const vals = c.probability_scale.map((s) => s.value);
    const min = Math.min(...vals);
    const max = Math.max(...vals);
    return Array.from({ length: max - min + 1 }, (_, i) => min + i);
  }
  return c.probability_scale.map((s) => s.value);
}
