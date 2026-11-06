// Accesos de soporte (docs/05 §4.6): tipos y textos.

import type { ChipTone } from "@/components/ui/Display";

export type SupportStatus = "requested" | "active" | "rejected" | "expired" | "revoked" | "authorized";

export type SupportGrant = {
  id: string;
  company_id: string;
  company_name: string;
  ruc: string;
  account_id: string;
  support_user_id: string;
  support_name: string;
  support_email?: string | null;
  authorized_name: string | null;
  reason: string;
  ticket_reference: string | null;
  access_level: "read_only" | "read_write";
  status: SupportStatus;
  hours: number;
  requested_at: string;
  authorized_at: string | null;
  starts_at: string | null;
  expires_at: string;
  revoked_at: string | null;
};

export const SUPPORT_STATUS: Record<SupportStatus, { label: string; tone: ChipTone }> = {
  requested: { label: "Esperando autorización", tone: "amber" },
  authorized: { label: "Autorizado", tone: "ok" },
  active: { label: "Activo", tone: "ok" },
  rejected: { label: "Rechazado", tone: "bad" },
  expired: { label: "Vencido", tone: "na" },
  revoked: { label: "Terminado", tone: "na" },
};

export const DURATIONS: [number, string][] = [
  [2, "2 horas"],
  [8, "8 horas"],
  [24, "1 día"],
  [72, "3 días"],
];

/** «Quedan 3 h 20 min» hasta el vencimiento. */
export function timeLeft(iso: string): string {
  const ms = new Date(iso).getTime() - Date.now();
  if (ms <= 0) return "vencido";
  const h = Math.floor(ms / 3_600_000);
  const m = Math.floor((ms % 3_600_000) / 60_000);
  if (h >= 24) return `quedan ${Math.floor(h / 24)} d ${h % 24} h`;
  return h ? `quedan ${h} h ${m} min` : `quedan ${m} min`;
}
