// Asistente (complemento IAA): IPERC sugerido, investigación guiada e historial.
// Las sugerencias quedan pendientes hasta que una persona las acepta o descarta.

import { api } from "./api";
import type { Criteria } from "./ipc";

export type IaaControl = { hierarchy: string; description: string };
export type IpercPayload = {
  process: string | null;
  activity: string;
  task: string;
  position_id: string;
  hazard: string;
  risk: string;
  consequence: string | null;
  hazard_catalog_id: string | null;
  probability_value?: number | null;
  probability_detail?: Record<string, number> | null;
  severity_value: number | null;
  controls: IaaControl[];
};
export type CausePayload = { description: string; cause_level: string; cause_category: string | null; group: string | null };
export type Suggestion<P> = { id: string; type: string; target_id: string | null; status: string; rationale: string | null; confidence: number | null; payload: P };

export type IpercSuggestResult = {
  request_id: string;
  position: { id: string; name: string };
  matrix: { id: string; code: string; title: string } | null;
  draft: { id: string; version_number: number } | null;
  criteria: Criteria | null;
  note: string | null;
  suggestions: Suggestion<IpercPayload>[];
};

export type GuideResult = {
  request_id: string;
  investigation: { id: string; code: string | null; method: string; event_code: string; event_type: string };
  questions: string[];
  note: string;
  topic: string | null;
  suggestions: Suggestion<CausePayload>[];
};

export type HistoryRow = {
  id: string;
  feature: string;
  module_code: string | null;
  subject_type: string | null;
  subject_id: string | null;
  input_summary: string | null;
  status: string;
  created_at: string;
  user_name: string | null;
  suggestions: number;
  accepted: number;
  rejected: number;
  pending: number;
};

export const CAUSE_LEVEL: Record<string, string> = { immediate: "Causa inmediata", basic: "Causa básica", root: "Causa raíz", lack_of_control: "Falta de control" };

export type MinutesAgreement = { description: string; responsible_position: string | null; due_on: string | null; existing?: boolean };
export type MinutesPayload = { minutes_text: string; agreements: MinutesAgreement[]; quorum_reached: boolean | null };
export type RequestStatus = {
  id: string; feature: string; status: "queued" | "running" | "completed" | "failed" | "blocked"; error_code: string | null; message: string | null;
  suggestions: Suggestion<Record<string, unknown>>[];
};

export const iaa = {
  ipercSuggest: (body: { position_id: string; matrix_id?: string; context?: string }) =>
    api<{ data: IpercSuggestResult }>("/iaa/iperc/suggest", { method: "POST", body, withCompany: true }),
  guide: (body: { investigation_id: string; answers?: string[] }) => api<{ data: GuideResult }>("/iaa/investigation/guide", { method: "POST", body, withCompany: true }),
  accept: (id: string, body: Record<string, unknown> = {}) =>
    api<{ data: { status: string; row_id?: string; row_number?: number; controls?: number; cause_id?: string } }>(`/iaa/suggestions/${id}/accept`, { method: "POST", body, withCompany: true }),
  reject: (id: string, notes?: string) => api<{ data: { status: string } }>(`/iaa/suggestions/${id}/reject`, { method: "POST", body: { notes }, withCompany: true }),
  history: (page = 1) => api<{ data: HistoryRow[]; meta: { page: number; pages: number; total: number } }>(`/iaa/history?page=${page}`, { withCompany: true }),
  /** IA-04: borrador del acta (201 con la sugerencia, o 202 «en cola» cuando lo redacta el proveedor de IA). */
  minutesDraft: (body: { meeting_id: string; notes?: string; agreements?: string[] }) =>
    api<{ data: { request_id: string; status: string; message?: string; suggestion?: Suggestion<MinutesPayload> } }>("/iaa/minutes/draft", { method: "POST", body, withCompany: true }),
  requestStatus: (id: string) => api<{ data: RequestStatus }>(`/iaa/requests/${id}`, { withCompany: true }),
  requestSuggestions: (id: string) => api<{ data: Suggestion<IpercPayload | CausePayload>[] }>(`/iaa/requests/${id}/suggestions`, { withCompany: true }),
};

/** Espera a que termine una solicitud que fue a la cola (docs/17 §2): consulta su estado cada pocos segundos (hasta ~3 minutos). */
export async function waitForRequest(id: string, signal?: { cancelled: boolean }): Promise<RequestStatus> {
  for (let i = 0; i < 60; i++) {
    await new Promise((r) => setTimeout(r, i < 5 ? 2000 : 4000));
    if (signal?.cancelled) break;
    const r = await iaa.requestStatus(id);
    if (!["queued", "running"].includes(r.data.status)) return r.data;
  }
  throw new Error("El asistente está tardando más de lo normal. Revisa el resultado más tarde en «Historial».");
}
