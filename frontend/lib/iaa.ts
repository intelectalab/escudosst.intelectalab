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

export const iaa = {
  ipercSuggest: (body: { position_id: string; matrix_id?: string; context?: string }) =>
    api<{ data: IpercSuggestResult }>("/iaa/iperc/suggest", { method: "POST", body, withCompany: true }),
  guide: (body: { investigation_id: string; answers?: string[] }) => api<{ data: GuideResult }>("/iaa/investigation/guide", { method: "POST", body, withCompany: true }),
  accept: (id: string, body: Record<string, unknown> = {}) =>
    api<{ data: { status: string; row_id?: string; row_number?: number; controls?: number; cause_id?: string } }>(`/iaa/suggestions/${id}/accept`, { method: "POST", body, withCompany: true }),
  reject: (id: string, notes?: string) => api<{ data: { status: string } }>(`/iaa/suggestions/${id}/reject`, { method: "POST", body: { notes }, withCompany: true }),
  history: (page = 1) => api<{ data: HistoryRow[]; meta: { page: number; pages: number; total: number } }>(`/iaa/history?page=${page}`, { withCompany: true }),
  requestSuggestions: (id: string) => api<{ data: Suggestion<IpercPayload | CausePayload>[] }>(`/iaa/requests/${id}/suggestions`, { withCompany: true }),
};
