// Cuentas y suscripciones (SCR-SUS-02 / SCR-SUS-03): tipos de /internal/accounts y textos para mostrar.

import type { ChipTone } from "@/components/ui/Display";

export type SubscriptionStatus = "trialing" | "active" | "past_due" | "read_only" | "pending_cancellation" | "cancelled" | "trial_expired";

export type CompanySubscription = {
  id: string;
  status: SubscriptionStatus;
  plan_code: "E" | "P" | "C" | "K";
  plan_name: string;
  billing_period: "monthly" | "annual";
  started_at: string;
  trial_ends_at: string | null;
  current_period_end: string | null;
  grace_until: string | null;
  cancel_reason: string | null;
  addons_count: number;
  /** Monto de un periodo (plan + complementos activos, anual con su descuento). Null si algún precio está por definir. */
  period_price: number | null;
};

export type AccountCompany = {
  id: string;
  ruc: string;
  legal_name: string;
  trade_name: string | null;
  headcount_declared: number | null;
  sst_model: "committee" | "supervisor";
  status: string;
  subscription: CompanySubscription | null;
};

export type AccountSummary = {
  id: string;
  name: string;
  account_type: "direct" | "consultancy" | "group";
  tax_id: string | null;
  status: string;
  created_at: string;
  companies: AccountCompany[];
};

export type ModuleMode = "enabled" | "read_only" | "locked" | "disabled_by_company";

export type CompanyDetail = AccountCompany & {
  addons: { code: string; name: string; module_code: string; started_at: string }[];
  modules: { counts: Record<ModuleMode, number>; items: { code: string; name: string; mode: ModuleMode; source: string }[] };
  payments: Payment[];
  admins: { membership_id: string; user_id: string; full_name: string; email: string; status: string; mfa_enabled: boolean; last_login_at: string | null }[];
};

export type Payment = {
  id: string;
  amount: number;
  currency: string;
  method: string;
  reference: string | null;
  paid_at: string;
  status: string;
  concept: string | null;
  sunat_status: string;
  registered_by: string | null;
};

export type TimelineEvent = {
  id: string;
  occurred_at: string;
  action: string;
  actor: string | null;
  reason: string | null;
  changes: Record<string, unknown> | null;
};

export type AccountDetail = Omit<AccountSummary, "companies"> & {
  legal_name: string | null;
  billing_email: string | null;
  billing_phone: string | null;
  trial_used_at: string | null;
  notes: string | null;
  companies: CompanyDetail[];
  timeline: TimelineEvent[];
};

export const STATUS: Record<SubscriptionStatus, { label: string; tone: ChipTone }> = {
  trialing: { label: "En prueba", tone: "brand" },
  active: { label: "Activa", tone: "ok" },
  past_due: { label: "Pago pendiente", tone: "amber" },
  read_only: { label: "Suspendida", tone: "bad" },
  pending_cancellation: { label: "Por cancelar", tone: "amber" },
  cancelled: { label: "Cancelada", tone: "na" },
  trial_expired: { label: "Prueba vencida", tone: "na" },
};

export const ACCOUNT_TYPE: Record<string, string> = { direct: "Empresa directa", group: "Grupo empresarial", consultancy: "Consultora" };

export const SECTORS: [string, string][] = [
  ["construction", "Construcción"],
  ["mining", "Minería"],
  ["agroindustry", "Agroindustria"],
  ["manufacturing", "Manufactura"],
  ["retail", "Comercio"],
  ["services", "Servicios"],
  ["office", "Oficinas"],
  ["health", "Salud"],
  ["education", "Educación"],
  ["transport", "Transporte"],
  ["energy", "Energía"],
  ["other", "Otro"],
];

export const MODE: Record<ModuleMode, { label: string; tone: ChipTone }> = {
  enabled: { label: "Habilitado", tone: "ok" },
  read_only: { label: "Solo lectura", tone: "amber" },
  disabled_by_company: { label: "Desactivado por la empresa", tone: "na" },
  locked: { label: "No incluido", tone: "na" },
};

export const PERIOD: Record<string, string> = { monthly: "Mensual", annual: "Anual" };

export const SST_MODEL: Record<string, string> = { committee: "Comité SST", supervisor: "Supervisor SST" };

/** Texto de la línea de tiempo para cada acción registrada en audit_logs. */
export const ACTION: Record<string, { label: string; icon: string }> = {
  account_created: { label: "Cuenta creada", icon: "plus" },
  subscription_plan_changed: { label: "Cambio de plan", icon: "swap" },
  subscription_suspended: { label: "Suscripción suspendida", icon: "pause" },
  subscription_reactivated: { label: "Suscripción reactivada", icon: "play" },
  subscription_addon_added: { label: "Complemento agregado", icon: "box" },
  subscription_addon_removed: { label: "Complemento quitado", icon: "close" },
  invitation_resent: { label: "Invitación reenviada", icon: "mail" },
  payment_registered: { label: "Pago registrado", icon: "check" },
  subscription_reminder: { label: "Aviso de vencimiento enviado", icon: "mail" },
  subscription_past_due: { label: "Venció: pago pendiente", icon: "clock" },
  subscription_trial_expired: { label: "Terminó la prueba", icon: "clock" },
  subscription_blocked: { label: "Bloqueada por falta de pago", icon: "lock" },
};

export const PLAN_NAMES: Record<string, string> = { E: "Esencial", P: "Profesional", C: "Corporativo", K: "Consultora" };

export function companyName(c: { trade_name: string | null; legal_name: string }) {
  return c.trade_name || c.legal_name;
}

export function formatDate(value: string | null | undefined, withTime = false): string {
  if (!value) return "—";
  return new Intl.DateTimeFormat("es-PE", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    ...(withTime ? { hour: "2-digit", minute: "2-digit" } : {}),
    timeZone: "America/Lima",
  }).format(new Date(value));
}

/** Días que faltan (negativo si ya pasó). */
export function daysUntil(value: string | null): number | null {
  if (!value) return null;
  return Math.ceil((new Date(value).getTime() - Date.now()) / 86_400_000);
}

/** Dígito verificador del RUC (módulo 11), igual que App\Core\Support\Ruc en la API. */
export function isValidRuc(ruc: string): boolean {
  if (!/^(10|15|17|20)\d{9}$/.test(ruc)) return false;
  const w = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
  const sum = w.reduce((acc, x, i) => acc + Number(ruc[i]) * x, 0);
  const c = 11 - (sum % 11);
  return (c === 10 ? 0 : c === 11 ? 1 : c) === Number(ruc[10]);
}

export const PAYMENT_METHODS: [string, string][] = [
  ["transfer", "Transferencia"],
  ["deposit", "Depósito"],
  ["yape", "Yape"],
  ["plin", "Plin"],
  ["card", "Tarjeta"],
  ["other", "Otro"],
];

export function formatMoney(amount: number, currency = "PEN"): string {
  return new Intl.NumberFormat("es-PE", { style: "currency", currency }).format(amount);
}

/** Días de aviso antes del vencimiento (igual que escudo.subscriptions.reminder_days en la API). */
export const REMINDER_DAYS = 5;

/** Fecha en que vence lo que la empresa tiene hoy (fin de prueba o del periodo pagado). */
export function periodEnd(s: { status: string; trial_ends_at: string | null; current_period_end: string | null }): string | null {
  return s.status === "trialing" ? s.trial_ends_at : s.current_period_end;
}
