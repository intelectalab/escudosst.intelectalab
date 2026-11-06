// Bitácora (AUD): nombres legibles de cada acción y un resumen de una línea por evento.

import type { ChipTone } from "@/components/ui/Display";
import { ACTION, formatDate, formatMoney, PAYMENT_METHODS, PLAN_NAMES } from "./accounts";

export type AuditEvent = {
  id: string;
  chain_seq: number;
  occurred_at: string;
  action: string;
  module_code: string | null;
  entity_type: string;
  entity_id: string | null;
  actor: string | null;
  actor_type: string;
  reason: string | null;
  changes: Record<string, unknown> | null;
};

export const AUDIT_ACTIONS: Record<string, { label: string; icon: string; tone: ChipTone }> = {
  ...Object.fromEntries(Object.entries(ACTION).map(([k, v]) => [k, { ...v, tone: "brand" as ChipTone }])),
  plan_updated: { label: "Plan editado", icon: "tag", tone: "brand" },
  plan_module_included: { label: "Módulo agregado a un plan", icon: "plus", tone: "ok" },
  plan_module_removed: { label: "Módulo quitado de un plan", icon: "close", tone: "amber" },
  addon_updated: { label: "Complemento editado", icon: "box", tone: "brand" },
  account_created: { label: "Cuenta creada", icon: "building", tone: "ok" },
  payment_registered: { label: "Pago registrado", icon: "check", tone: "ok" },
  subscription_suspended: { label: "Suscripción suspendida", icon: "pause", tone: "bad" },
  subscription_blocked: { label: "Bloqueada por falta de pago", icon: "lock", tone: "bad" },
  subscription_past_due: { label: "Venció: pago pendiente", icon: "clock", tone: "amber" },
  subscription_trial_expired: { label: "Terminó la prueba", icon: "clock", tone: "amber" },
  subscription_reactivated: { label: "Suscripción reactivada", icon: "play", tone: "ok" },
  subscription_reminder: { label: "Aviso de vencimiento enviado", icon: "mail", tone: "na" },
  daily_process_run: { label: "Vencimientos revisados a mano", icon: "refresh", tone: "brand" },
  support_access_requested: { label: "Soporte pidió acceso", icon: "shield", tone: "amber" },
  support_access_authorized: { label: "Acceso de soporte autorizado", icon: "check", tone: "ok" },
  support_access_rejected: { label: "Acceso de soporte rechazado", icon: "close", tone: "bad" },
  support_access_revoked: { label: "Acceso de soporte terminado", icon: "lock", tone: "na" },
  support_access_cancelled: { label: "Solicitud de soporte cancelada", icon: "close", tone: "na" },
  legal_parameter_versioned: { label: "Parámetro legal: nueva versión", icon: "shield", tone: "amber" },
  legal_parameter_confirmed: { label: "Parámetro legal confirmado", icon: "check", tone: "ok" },
  legal_norm_created: { label: "Norma agregada", icon: "file", tone: "ok" },
  legal_norm_updated: { label: "Norma editada", icon: "file", tone: "brand" },
  hazard_created: { label: "Peligro base agregado", icon: "plus", tone: "ok" },
  hazard_updated: { label: "Peligro base editado", icon: "pen", tone: "brand" },
  sector_pack_status: { label: "Paquete sectorial: cambio de estado", icon: "box", tone: "brand" },
  holiday_created: { label: "Feriado agregado", icon: "clock", tone: "ok" },
  holiday_updated: { label: "Feriado actualizado", icon: "clock", tone: "brand" },
  holiday_deleted: { label: "Feriado quitado", icon: "close", tone: "na" },
  site_settings_updated: { label: "Ajustes del sitio web guardados", icon: "globe", tone: "brand" },
  legal_draft_saved: { label: "Borrador de política guardado", icon: "pen", tone: "brand" },
  legal_version_created: { label: "Versión nueva de política", icon: "plus", tone: "brand" },
  legal_version_published: { label: "Política publicada", icon: "globe", tone: "ok" },
};

export const ENTITY_LABELS: Record<string, string> = {
  plans: "Plan",
  plan_modules: "Módulos del plan",
  addons: "Complemento",
  accounts: "Cuenta",
  subscriptions: "Suscripción",
  memberships: "Acceso de usuario",
  support_access_grants: "Acceso de soporte",
  legal_parameters: "Parámetro legal",
  legal_norms: "Norma legal",
  hazard_catalog: "Peligro base",
  sector_packs: "Paquete sectorial",
  holidays: "Feriado",
  site_settings: "Sitio web",
  legal_document_versions: "Política del sitio",
};

/** Etiquetas de campos para la tabla «antes / después». */
export const FIELD_LABELS: Record<string, string> = {
  plan: "Plan",
  status: "Estado",
  name: "Nombre",
  description: "Descripción",
  price_reference: "Precio de referencia",
  is_active: "Activo",
  is_public: "Visible en la web",
  billing_periods: "Periodos de cobro",
  limits: "Límites",
  period_end: "Fin del periodo",
  grace_until: "Gracia hasta",
  cancel_reason: "Motivo",
  plans: "Planes",
  company: "Empresa",
  modules: "Módulos de la empresa",
  direction: "Tipo de cambio",
  addon: "Complemento",
  addon_name: "Complemento",
  addons: "Complementos",
  amount: "Monto",
  method: "Medio de pago",
  reference: "N.° de operación",
  periods: "Periodos pagados",
  admin: "Administrador",
  days: "Días antes del vencimiento",
  end: "Vence",
  module: "Módulo",
  module_name: "Nombre del módulo",
  companies_refreshed: "Empresas actualizadas",
  enabled: "Habilitados",
  read_only: "Solo lectura",
  locked: "No incluidos",
  disabled_by_company: "Desactivados por la empresa",
  billing_period: "Periodo de cobro",
  marker: "Marca",
  hours: "Horas",
  value: "Valor",
  valid_from: "Rige desde",
  confirmed_by: "Confirmado por",
  hazard: "Peligro",
  risk: "Riesgo",
  number: "Número",
  title: "Título",
  date: "Fecha",
  fields: "Campos cambiados",
  document: "Documento",
  version: "Versión",
  reviewed_by: "Revisado por",
  requires_reacceptance: "Pide aceptarla otra vez",
  ticket: "Ticket",
  until: "Hasta",
  by: "Terminado por",
  platform: "Equipo de EscudoSST",
  summary: "Resultado",
  reminders: "Avisos",
  past_due: "Con pago pendiente",
  trial_expired: "Pruebas vencidas",
  blocked: "Bloqueadas",
};

const VALUE_LABELS: Record<string, string> = {
  upgrade: "Subió de plan",
  downgrade: "Bajó de plan",
  trialing: "En prueba",
  active: "Activa",
  past_due: "Pago pendiente",
  read_only: "Solo lectura",
  trial_expired: "Prueba vencida",
  monthly: "Mensual",
  annual: "Anual",
  transfer: "Transferencia",
  deposit: "Depósito",
  yape: "Yape",
  plin: "Plin",
  card: "Tarjeta",
  other: "Otro",
};

/** Campos internos que no se muestran en el detalle. */
export const HIDDEN_FIELDS = ["before", "after", "account_id", "payment_id", "key"];

export function actionLabel(action: string) {
  return AUDIT_ACTIONS[action]?.label ?? action.replace(/_/g, " ");
}

/** Resumen de una línea con lo esencial del evento. */
export function describe(e: AuditEvent): string {
  const c = (e.changes ?? {}) as Record<string, unknown> & { before?: Record<string, unknown>; after?: Record<string, unknown> };
  const plan = (code?: unknown) => (typeof code === "string" ? (PLAN_NAMES[code] ?? code) : "");
  const company = typeof c.company === "string" ? c.company : typeof c.company === "object" && c.company ? String((c.company as Record<string, unknown>).legal_name ?? "") : "";
  switch (e.action) {
    case "account_created":
      return `${company} · plan ${plan(c.plan)} · ${c.status === "trialing" ? "en prueba" : "contratado"}`;
    case "subscription_plan_changed":
      return `${company}: ${plan(c.before?.plan)} → ${plan(c.after?.plan)}`;
    case "subscription_addon_added":
    case "subscription_addon_removed":
      return `${String(c.addon_name ?? c.addon ?? "")} · ${company}`;
    case "payment_registered":
      return `${formatMoney(Number(c.amount))} · ${PAYMENT_METHODS.find(([v]) => v === c.method)?.[1] ?? String(c.method)} · ${company}`;
    case "subscription_reminder":
      return `${company}: ${c.days === 0 ? "vence hoy" : `vence en ${String(c.days)} días`}`;
    case "subscription_past_due":
      return `${company}: gracia hasta ${formatDate(String((c.after as Record<string, unknown> | undefined)?.grace_until ?? ""))}`;
    case "plan_module_included":
    case "plan_module_removed":
      return `${String(c.module ?? "")} · ${String(c.module_name ?? "")}`;
    case "plan_updated":
    case "addon_updated":
      return String(c.name ?? c.plan ?? c.addon ?? c.code ?? "");
    case "invitation_resent":
      return String(c.admin ?? "");
    default:
      return company;
  }
}

/** Muestra cualquier valor guardado en `changes` como texto legible. */
export function formatValue(v: unknown): string {
  if (v === null || v === undefined || v === "") return "—";
  if (typeof v === "boolean") return v ? "Sí" : "No";
  if (Array.isArray(v)) return v.length ? v.map(formatValue).join(", ") : "—";
  if (typeof v === "object") {
    return Object.entries(v as Record<string, unknown>)
      .map(([k, x]) => `${FIELD_LABELS[k] ?? k}: ${formatValue(x)}`)
      .join(" · ");
  }
  if (typeof v === "string" && /^\d{4}-\d{2}-\d{2}T/.test(v)) return formatDate(v, true);
  if (typeof v === "string" && PLAN_NAMES[v] && v.length === 1) return PLAN_NAMES[v];
  if (typeof v === "string" && VALUE_LABELS[v]) return VALUE_LABELS[v];
  return String(v);
}
