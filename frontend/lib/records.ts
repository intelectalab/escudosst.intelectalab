// Registros de los módulos SST (motor común del backend: /records/{módulo}/{tabla}).
// El backend describe cada tabla (campos, tipos, opciones y permisos) y esta app arma la pantalla.

import { api } from "./api";

export type FieldType = "text" | "longtext" | "int" | "number" | "date" | "datetime" | "time" | "bool" | "enum" | "ref" | "tags" | "json" | "source";

export type RecordField = {
  name: string;
  label: string;
  type: FieldType;
  required?: boolean;
  readonly?: boolean;
  encrypted?: boolean;
  list?: boolean;
  default?: string | number | boolean;
  max?: number;
  options?: { value: string; label: string }[];
  ref?: string;
  item?: string;
};

export type RecordResource = {
  table: string;
  /** Nombre corto para la pestaña. */
  tab: string;
  /** Para qué sirve el registro (de la ficha del módulo). */
  title: string;
  hybrid: boolean;
  immutable: boolean;
  label: string;
  fields: RecordField[];
  can: { read: boolean; create: boolean; update: boolean; delete: boolean; export: boolean };
};

export type ModuleSchema = { module: string; mode: string | null; resources: RecordResource[] };

export type RecordRow = { id: string; own: boolean; updated_at?: string } & Record<string, unknown>;

export type RecordPage = { data: RecordRow[]; meta: { page: number; per_page: number; total: number; pages: number } };

const base = (code: string, table?: string) => `/records/${code.toUpperCase()}${table ? `/${table}` : ""}`;

export const records = {
  schema: (code: string) => api<ModuleSchema>(base(code), { withCompany: true }),
  list: (code: string, table: string, params: { q?: string; page?: number; filters?: Record<string, string> }) => {
    const qs = new URLSearchParams();
    if (params.q) qs.set("q", params.q);
    if (params.page) qs.set("page", String(params.page));
    Object.entries(params.filters ?? {}).forEach(([k, v]) => v && qs.set(`f[${k}]`, v));
    return api<RecordPage>(`${base(code, table)}?${qs}`, { withCompany: true });
  },
  get: (code: string, table: string, id: string) => api<{ data: RecordRow }>(`${base(code, table)}/${id}`, { withCompany: true }),
  create: (code: string, table: string, body: Record<string, unknown>) =>
    api<{ data: RecordRow }>(base(code, table), { method: "POST", body, withCompany: true }),
  update: (code: string, table: string, id: string, body: Record<string, unknown>) =>
    api<{ data: RecordRow }>(`${base(code, table)}/${id}`, { method: "PATCH", body, withCompany: true }),
  remove: (code: string, table: string, id: string) => api<void>(`${base(code, table)}/${id}`, { method: "DELETE", withCompany: true }),
  options: (code: string, table: string, field: string, params: { q?: string; id?: string }) => {
    const qs = new URLSearchParams();
    if (params.q) qs.set("q", params.q);
    if (params.id) qs.set("id", params.id);
    return api<{ data: { id: string; label: string }[] }>(`${base(code, table)}/options/${field}?${qs}`, { withCompany: true });
  },
};

const pad = (n: number) => String(n).padStart(2, "0");

/** Texto para mostrar un valor en la lista o en modo lectura. */
export function displayValue(f: RecordField, row: RecordRow): string {
  const v = row[f.name];
  if (v === null || v === undefined || v === "") return "—";
  switch (f.type) {
    case "enum":
      return f.options?.find((o) => o.value === v)?.label ?? String(v);
    case "bool":
      return v ? "Sí" : "No";
    case "ref":
      return (row[`${f.name}__label`] as string) || "—";
    case "date": {
      const [y, m, d] = String(v).slice(0, 10).split("-");
      return `${d}/${m}/${y}`;
    }
    case "datetime": {
      const d = new Date(String(v));
      return Number.isNaN(d.getTime()) ? String(v) : `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
    }
    case "number":
      return Number(v).toLocaleString("es-PE", { maximumFractionDigits: 2 });
    case "tags":
      return Array.isArray(v) ? v.join(", ") : String(v);
    case "json":
      return Array.isArray(v) ? `${v.length} elemento(s)` : "Datos";
    default:
      return String(v);
  }
}

/** Valor de un <input type="datetime-local"> a partir de una fecha ISO. */
export function toLocalInput(v: unknown): string {
  if (!v) return "";
  const d = new Date(String(v));
  if (Number.isNaN(d.getTime())) return "";
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** Tono del chip según el estado (verde vigente, ámbar en curso, rojo vencido, gris cerrado). */
export function statusTone(value: string): "ok" | "na" | "amber" | "brand" | "bad" {
  if (/^(active|approved|completed|valid|done|closed_ok|published|in_force|current|fit|compliant|accredited|signed|paid|delivered|executed|met)$/.test(value)) return "ok";
  if (/^(overdue|expired|rejected|cancelled|failed|unfit|non_compliant|suspended|blocked|terminated|critical|high|very_high)$/.test(value)) return "bad";
  if (/^(in_progress|pending|scheduled|draft|under_review|open|planned|on_leave|partial|medium|requested|submitted|observed)$/.test(value)) return "amber";
  return "na";
}

export type TraceNode = { module: string; table: string; tab: string; id: string; label: string; via: string };
export type TraceGroup = { module: string; table: string; tab: string; count: number; filter: Record<string, string> };

/** Trazabilidad de un registro: de dónde viene y qué generó (en cualquier módulo). */
export const traceOf = (code: string, table: string, id: string) =>
  api<{ data: { parents: TraceNode[]; children: TraceGroup[] } }>(`/records/${code.toUpperCase()}/${table}/${id}/trace`, { withCompany: true });

/** Enlace a una lista filtrada o a un registro de cualquier módulo. */
export function recordsHref(module: string, table: string, opts: { filters?: Record<string, string>; open?: string } = {}): string {
  const q = new URLSearchParams({ t: table });
  Object.entries(opts.filters ?? {}).forEach(([k, v]) => q.set(`f[${k}]`, v));
  if (opts.open) q.set("open", opts.open);
  return `/m/${module.toLowerCase()}?${q}`;
}
