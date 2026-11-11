// App del trabajador (docs/07 §5.2): credencial del equipo para ingresar con DNI + PIN y tipos de /me/worker/*.
// La credencial (id del equipo y su secreto) se guarda en este navegador al activar la app; el PIN nunca se guarda.

import { api, API_URL, ApiError } from "./api";
import { uuidv7 } from "./offline";
import { session } from "./session";

const DEVICE_KEY = "escudo.worker_device";
const UID_KEY = "escudo.worker_device_uid";

export type WorkerDevice = { company_id: string; company_name: string | null; device_id: string; device_secret: string; first_name: string | null };

export const workerDevice = {
  get(): WorkerDevice | null {
    try {
      const raw = localStorage.getItem(DEVICE_KEY);
      return raw ? (JSON.parse(raw) as WorkerDevice) : null;
    } catch {
      return null;
    }
  },
  set(d: WorkerDevice) {
    try {
      localStorage.setItem(DEVICE_KEY, JSON.stringify(d));
    } catch {
      /* sin almacenamiento: tendrá que activar de nuevo */
    }
  },
  clear() {
    try {
      localStorage.removeItem(DEVICE_KEY);
    } catch {
      /* nada */
    }
  },
  /** Identificador propio de este equipo para la app del trabajador. */
  uid(): string {
    try {
      let id = localStorage.getItem(UID_KEY);
      if (!id) {
        id = `wdev-${uuidv7()}`;
        localStorage.setItem(UID_KEY, id);
      }
      return id;
    } catch {
      return `wdev-${uuidv7()}`;
    }
  },
};

export type WorkerHome = {
  worker: {
    id: string; full_name: string; first_name: string; document_masked: string | null; position: string | null; area: string | null; site: string | null;
    hire_on: string | null; phone: string | null; emergency_contact_name: string | null; emergency_contact_phone: string | null;
  };
  company: { id: string; name: string };
  modules: Record<string, boolean>;
  summary: {
    credential: { status: string; expires_on: string | null } | null;
    pending: number;
    ppe: { total: number; unsigned: number; expiring: number } | null;
    fitness: { status: string; valid_until: string | null } | null;
    certificates: number;
    courses: number;
    reports: number;
    election: { id: string; title: string; voting_closes_at: string | null; on_roll: boolean; has_voted: boolean } | null;
  };
};

export type PendingItem = { kind: "signature" | "document" | "risst" | "ppe" | "course" | "survey" | "election"; id: string; title: string; detail: string; due: string | null; url?: string };

export type SignKind = "signature" | "document" | "risst" | "ppe";

export type SignItem = {
  kind: SignKind; id: string; title: string; subtitle: string | null; signed: boolean; declaration: string;
  content_html: string | null; file_id: string | null; expires_on?: string | null;
};

export const workerApi = <T>(path: string, opts: { method?: "GET" | "POST" | "PATCH"; body?: unknown; headers?: Record<string, string> } = {}) =>
  api<{ data: T }>(`/me/worker${path}`, { ...opts, withCompany: true }).then((r) => r.data);

/** Abre un archivo protegido (con la sesión) en una pestaña nueva. */
export async function openProtectedFile(path: string, headers: Record<string, string> = {}): Promise<void> {
  const win = window.open("", "_blank");
  const h: Record<string, string> = { ...headers };
  const token = session.getToken();
  const company = session.getCompanyId();
  if (token) h.Authorization = `Bearer ${token}`;
  if (company) h["X-Company-Id"] = company;
  const res = await fetch(`${API_URL}/api/v1${path}`, { headers: h });
  if (!res.ok) {
    win?.close();
    const data = await res.json().catch(() => ({}));
    throw new ApiError(res.status, data.type ?? "error", data.title ?? "Error", data.detail);
  }
  const url = URL.createObjectURL(await res.blob());
  if (win) win.location.href = url;
  else window.location.href = url;
}

export const FITNESS: Record<string, string> = { fit: "Apto", fit_with_restrictions: "Apto con restricciones", unfit: "No apto", pending: "Pendiente", expired: "Vencido" };

export const errorText = (e: unknown) => (e instanceof ApiError ? e.detail ?? e.title : "Ocurrió un error. Inténtalo de nuevo.");

export const fmtDate = (v: string | null | undefined, withTime = false) =>
  v ? new Date(v.length === 10 ? `${v}T12:00:00` : v).toLocaleString("es-PE", withTime ? { dateStyle: "short", timeStyle: "short", timeZone: "America/Lima" } : { dateStyle: "medium", timeZone: "America/Lima" }) : "—";

/** Quita scripts, marcos y eventos de un HTML de la empresa antes de mostrarlo. */
export function safeHtml(html: string): string {
  if (typeof window === "undefined") return "";
  const doc = new DOMParser().parseFromString(html, "text/html");
  doc.querySelectorAll("script,style,iframe,object,embed,link,meta,form").forEach((n) => n.remove());
  doc.querySelectorAll("*").forEach((el) => {
    for (const a of Array.from(el.attributes)) {
      if (a.name.startsWith("on") || ((a.name === "href" || a.name === "src") && /^\s*javascript:/i.test(a.value))) el.removeAttribute(a.name);
    }
  });
  return doc.body.innerHTML;
}
