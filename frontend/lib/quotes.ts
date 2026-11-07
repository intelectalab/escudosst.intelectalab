// Cotizaciones (venta con pago manual): tipos y textos compartidos entre el panel y la página del cliente.

import type { ChipTone } from "@/components/ui/Display";

export type QuoteStatus = "draft" | "sent" | "accepted" | "rejected" | "payment_submitted" | "paid" | "cancelled";

export type QuoteLine = { code: string; name: string; monthly: string | number | null; amount: number | null };

export type QuoteCompany = {
  ruc: string;
  legal_name: string;
  trade_name?: string | null;
  headcount: number | null;
  economic_sector: string | null;
  plan_code: "E" | "P" | "C";
  addon_codes: string[];
  lines?: QuoteLine[];
  monthly?: number;
  amount?: number | null;
};

export type Quote = {
  id: string;
  number: string;
  status: QuoteStatus;
  status_label: string;
  contact_name: string;
  contact_email: string;
  contact_phone: string | null;
  billing_period: "monthly" | "annual";
  companies: QuoteCompany[];
  annual_discount_pct: number;
  subtotal: string;
  discount_amount: string;
  total: string;
  currency: string;
  valid_until: string | null;
  client_notes: string | null;
  sent_at: string | null;
  accepted_at: string | null;
  rejected_at: string | null;
  reject_reason: string | null;
  payment: { method: string | null; method_label: string | null; reference: string | null; paid_on: string | null; amount: string | null; submitted_at: string | null } | null;
  created_at: string;
};

export type QuoteDetail = Quote & {
  internal_notes: string | null;
  demo_request_id: string | null;
  evidence_rejected_reason: string | null;
  has_evidence: boolean;
  has_pdf: boolean;
  sent_count: number;
  confirmed_at: string | null;
  account_id: string | null;
  cancel_reason: string | null;
  editable: boolean;
};

export type PublicQuote = Quote & {
  expired: boolean;
  provider: { legal_name: string | null; trade_name: string | null; ruc: string | null };
  payment_instructions: string | null;
  evidence_rejected_reason: string | null;
  methods: Record<string, string>;
};

export const QUOTE_STATUS: Record<QuoteStatus, { label: string; tone: ChipTone; icon: string }> = {
  draft: { label: "Borrador", tone: "na", icon: "pen" },
  sent: { label: "Enviada", tone: "brand", icon: "mail" },
  accepted: { label: "Aceptada · esperando pago", tone: "amber", icon: "clock" },
  rejected: { label: "No aceptada", tone: "bad", icon: "close" },
  payment_submitted: { label: "Pago por revisar", tone: "amber", icon: "bell" },
  paid: { label: "Pagada · cuenta creada", tone: "ok", icon: "check" },
  cancelled: { label: "Anulada", tone: "na", icon: "close" },
};

export const PLAN_NAMES: Record<string, string> = { E: "Esencial", P: "Profesional", C: "Corporativo" };

export const money = (v: string | number | null | undefined) =>
  v === null || v === undefined || v === "" ? "Por definir" : `S/ ${Number(v).toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export const day = (d: string | null) => (d ? new Intl.DateTimeFormat("es-PE", { dateStyle: "long", timeZone: "America/Lima" }).format(new Date(d.length === 10 ? `${d}T12:00:00-05:00` : d)) : "");

export const when = (d: string | null) => (d ? new Intl.DateTimeFormat("es-PE", { dateStyle: "medium", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(d)) : "");
