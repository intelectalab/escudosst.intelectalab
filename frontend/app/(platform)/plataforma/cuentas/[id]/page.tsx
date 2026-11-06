"use client";

// SCR-SUS-03 · Detalle de la cuenta y sus suscripciones (docs/modulos/M-SUS.md §6):
// empresas con plan, complementos, módulos resultantes y administradores; línea de tiempo de cambios.

import { useParams, useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { AddAddonModal, ChangePlanModal, ReactivateModal, RegisterPaymentModal, RemoveAddonModal, SuspendModal } from "@/components/sus/SubscriptionActions";
import { Button } from "@/components/ui/Button";
import { Chip, Code, EmptyState, PageHeader } from "@/components/ui/Display";
import { Icon } from "@/components/ui/Icon";
import { Toast } from "@/components/ui/Toast";
import { api, ApiError } from "@/lib/api";
import {
  ACCOUNT_TYPE,
  ACTION,
  type AccountDetail,
  type CompanyDetail,
  companyName,
  daysUntil,
  formatDate,
  formatMoney,
  PAYMENT_METHODS,
  periodEnd,
  REMINDER_DAYS,
  MODE,
  type ModuleMode,
  PERIOD,
  PLAN_NAMES,
  SST_MODEL,
  STATUS,
  type TimelineEvent,
} from "@/lib/accounts";
import { PLAN_ACCENT } from "@/lib/plans";

type Action =
  | { kind: "plan" | "suspend" | "reactivate" | "addon" | "payment"; company: CompanyDetail }
  | { kind: "remove"; company: CompanyDetail; addon: CompanyDetail["addons"][number] };

export default function CuentaDetallePage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();
  const [isNew, setIsNew] = useState(false);
  const [account, setAccount] = useState<AccountDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [action, setAction] = useState<Action | null>(null);
  const [toast, setToast] = useState<{ text: string; tone: "ok" | "bad" } | null>(null);
  const closeToast = useCallback(() => setToast(null), []);

  const load = useCallback(() => {
    api<{ data: AccountDetail }>(`/internal/accounts/${id}`)
      .then((r) => setAccount(r.data))
      .catch((e) => setError(e instanceof ApiError ? e.message : "No pudimos cargar la cuenta."));
  }, [id]);

  useEffect(load, [load]);
  useEffect(() => setIsNew(new URLSearchParams(window.location.search).get("nueva") === "1"), []);

  function done(text: string) {
    setAction(null);
    setToast({ text, tone: "ok" });
    load();
  }

  async function resend(company: CompanyDetail, userId: string, email: string) {
    try {
      await api(`/internal/accounts/${id}/companies/${company.id}/admins/${userId}/resend`, { method: "POST" });
      setToast({ text: `Invitación reenviada a ${email}.`, tone: "ok" });
      load();
    } catch (e) {
      setToast({ text: e instanceof ApiError ? e.message : "No se pudo reenviar.", tone: "bad" });
    }
  }

  if (error) return <p className="rounded-xl border border-bad/30 bg-bad-soft p-4 text-bad">{error}</p>;
  if (!account) return <Skeleton />;

  const admin = account.companies.flatMap((c) => c.admins)[0];

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <PageHeader
        crumbs={[["Panel de la plataforma"], ["Cuentas y suscripciones", "/plataforma/cuentas"], [account.name]]}
        icon="building"
        title={account.name}
        aside={
          <Button variant="secondary" icon="back" onClick={() => router.push("/plataforma/cuentas")}>
            Volver
          </Button>
        }
      >
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <Chip tone="brand">{ACCOUNT_TYPE[account.account_type] ?? account.account_type}</Chip>
          {account.tax_id && <Code>RUC {account.tax_id}</Code>}
          <span className="text-sm text-muted">Cliente desde {formatDate(account.created_at)}</span>
        </div>
      </PageHeader>

      {isNew && admin && (
        <div className="flex items-start gap-3 rounded-2xl border border-ok/30 bg-ok-soft px-4 py-3 text-sm text-ok">
          <Icon name="mail" size={18} className="mt-0.5 shrink-0" />
          <p>
            Cuenta creada. {admin.status === "invited" ? `Enviamos la invitación a ${admin.email} para que cree su contraseña.` : `${admin.email} ya tenía usuario: le avisamos que tiene acceso.`}
          </p>
        </div>
      )}

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <section aria-label="Empresas" className="min-w-0 space-y-5">
          {account.companies.length === 0 ? (
            <div className="rounded-2xl border border-line bg-surface">
              <EmptyState icon="building" text="Esta cuenta aún no tiene empresas." />
            </div>
          ) : (
            account.companies.map((c) => <CompanyCard key={c.id} company={c} onAction={setAction} onResend={resend} />)
          )}
        </section>

        <aside className="grid content-start gap-5 lg:grid-cols-2 xl:grid-cols-1">
          <AccountInfo account={account} />
          <Timeline events={account.timeline} />
        </aside>
      </div>

      {action?.kind === "plan" && <ChangePlanModal company={action.company} onClose={() => setAction(null)} onDone={done} />}
      {action?.kind === "suspend" && <SuspendModal company={action.company} onClose={() => setAction(null)} onDone={done} />}
      {action?.kind === "reactivate" && <ReactivateModal company={action.company} onClose={() => setAction(null)} onDone={done} />}
      {action?.kind === "addon" && <AddAddonModal company={action.company} onClose={() => setAction(null)} onDone={done} />}
      {action?.kind === "payment" && <RegisterPaymentModal company={action.company} onClose={() => setAction(null)} onDone={done} />}
      {action?.kind === "remove" && <RemoveAddonModal company={action.company} addon={action.addon} onClose={() => setAction(null)} onDone={done} />}
      {toast && <Toast message={toast.text} tone={toast.tone} onClose={closeToast} />}
    </div>
  );
}

function CompanyCard({
  company: c,
  onAction,
  onResend,
}: {
  company: CompanyDetail;
  onAction: (a: Action) => void;
  onResend: (c: CompanyDetail, userId: string, email: string) => void;
}) {
  const s = c.subscription;
  const [showModules, setShowModules] = useState(false);
  const canChange = s && ["trialing", "active", "past_due", "pending_cancellation"].includes(s.status) && s.plan_code !== "K";
  const trialDays = s?.status === "trialing" ? daysUntil(s.trial_ends_at) : null;
  const accent = s ? PLAN_ACCENT[s.plan_code] : undefined;

  return (
    <article id={`empresa-${c.id}`} className="relative scroll-mt-24 overflow-hidden rounded-2xl border border-line bg-surface shadow-sm">
      {accent && <div className="absolute inset-x-0 top-0 h-1" style={{ background: accent.bar }} />}
      <div className="space-y-5 p-4 sm:p-6">
        {/* Cabecera */}
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <h2 className="font-display text-lg font-semibold">{companyName(c)}</h2>
            <p className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted">
              <Code>RUC {c.ruc}</Code>
              {c.trade_name && <span className="truncate">{c.legal_name}</span>}
            </p>
          </div>
          {s ? (
            <Chip tone={STATUS[s.status].tone} dot>
              {STATUS[s.status].label}
            </Chip>
          ) : (
            <Chip tone="na">Sin suscripción</Chip>
          )}
        </div>

        {/* Datos de la suscripción */}
        {s && (
          <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-line bg-line text-sm sm:grid-cols-4">
            <Stat label="Plan">
              <span className="inline-flex items-center gap-2">
                <span className="h-2.5 w-2.5 rounded-full" style={{ background: accent?.bar }} />
                {s.plan_name}
              </span>
            </Stat>
            <Stat label="Cobro">{PERIOD[s.billing_period]}</Stat>
            <Stat label="Inicio">{formatDate(s.started_at)}</Stat>
            {s.status === "trialing" ? (
              <Stat label="Fin de la prueba" sub={trialDays !== null ? (trialDays >= 0 ? `Quedan ${trialDays} ${trialDays === 1 ? "día" : "días"}` : "Vencida") : undefined}>
                {formatDate(s.trial_ends_at)}
              </Stat>
            ) : (
              <Stat label="Renovación">{formatDate(s.current_period_end)}</Stat>
            )}
          </dl>
        )}
        <p className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
          {c.headcount_declared !== null && <span>{c.headcount_declared} trabajadores declarados</span>}
          <span>{SST_MODEL[c.sst_model]}</span>
        </p>

        {s && <DueNotice sub={s} />}

        {/* Acciones */}
        {s && (
          <div className="grid grid-cols-1 gap-2 sm:flex sm:flex-wrap">
            <Button
              variant="primary"
              icon="file"
              disabled={["cancelled", "pending_cancellation"].includes(s.status) || s.plan_code === "K"}
              onClick={() => onAction({ kind: "payment", company: c })}
            >
              Registrar pago
            </Button>
            <Button variant="soft" icon="swap" disabled={!canChange} onClick={() => onAction({ kind: "plan", company: c })} title={canChange ? undefined : "Reactiva la suscripción antes de cambiar el plan"}>
              Cambiar plan
            </Button>
            <Button
              variant="secondary"
              icon="plus"
              disabled={s.status !== "active"}
              onClick={() => onAction({ kind: "addon", company: c })}
              title={s.status === "active" ? undefined : "Los complementos requieren un plan base activo"}
            >
              Agregar complemento
            </Button>
            {["read_only", "trial_expired"].includes(s.status) ? (
              <Button variant="ghost" icon="play" onClick={() => onAction({ kind: "reactivate", company: c })} className="sm:ml-auto" title="Desbloquea sin registrar pago (excepción)">
                Reactivar sin pago
              </Button>
            ) : (
              <Button
                variant="danger-ghost"
                icon="pause"
                className="sm:ml-auto"
                disabled={!["trialing", "active", "past_due"].includes(s.status)}
                onClick={() => onAction({ kind: "suspend", company: c })}
              >
                Suspender
              </Button>
            )}
          </div>
        )}

        {/* Pagos */}
        {s && (
          <Block title="Pagos" icon="file" count={c.payments.length}>
            {c.payments.length === 0 ? (
              <p className="text-sm text-muted">Aún no hay pagos registrados.</p>
            ) : (
              <ul className="divide-y divide-line rounded-xl border border-line">
                {c.payments.slice(0, 6).map((p) => (
                  <li key={p.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5">
                    <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-ok-soft text-ok">
                      <Icon name="check" size={15} />
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold tabular-nums">{formatMoney(p.amount, p.currency)}</p>
                      <p className="truncate text-xs text-muted">
                        {PAYMENT_METHODS.find(([v]) => v === p.method)?.[1] ?? p.method}
                        {p.reference && ` · Op. ${p.reference}`}
                        {p.concept && ` · ${p.concept}`}
                      </p>
                    </div>
                    <span className="text-xs text-muted">{formatDate(p.paid_at)}</span>
                  </li>
                ))}
              </ul>
            )}
          </Block>
        )}

        {/* Complementos */}
        <Block title="Complementos" icon="box" count={c.addons.length}>
          {c.addons.length === 0 ? (
            <p className="text-sm text-muted">{s?.status === "trialing" ? "Durante la prueba no hay complementos." : "Sin complementos."}</p>
          ) : (
            <ul className="divide-y divide-line rounded-xl border border-line">
              {c.addons.map((a) => (
                <li key={a.code} className="flex items-center gap-3 px-3 py-2.5">
                  <Code>{a.code}</Code>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{a.name}</p>
                    <p className="text-xs text-muted">Desde {formatDate(a.started_at)}</p>
                  </div>
                  <Button variant="danger-ghost" size="sm" icon="close" onClick={() => onAction({ kind: "remove", company: c, addon: a })} aria-label={`Quitar ${a.name}`}>
                    <span className="hidden sm:inline">Quitar</span>
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </Block>

        {/* Módulos resultantes */}
        <Block title="Módulos de la empresa" icon="grid">
          <div className="flex flex-wrap gap-2">
            {(["enabled", "read_only", "disabled_by_company", "locked"] as ModuleMode[]).map((m) =>
              c.modules.counts[m] ? (
                <Chip key={m} tone={MODE[m].tone} dot>
                  {c.modules.counts[m]} {MODE[m].label.toLowerCase()}
                </Chip>
              ) : null,
            )}
          </div>
          {c.modules.counts.read_only > 0 && (
            <p className="mt-3 flex items-start gap-2 rounded-lg bg-amber-soft/60 px-3 py-2 text-xs text-amber-ink">
              <Icon name="eye" size={14} className="mt-0.5 shrink-0" />
              <span>
                <b>Solo lectura:</b> módulos que la empresa tenía antes (por un plan más alto o un complemento) y ya no tiene. Sus usuarios ven y descargan lo que registraron, pero no pueden crear ni editar. Si vuelve a contratarlos, se habilitan con todos sus datos.
              </span>
            </p>
          )}
          {c.modules.items.length > 0 && (
            <>
              <button type="button" onClick={() => setShowModules((v) => !v)} className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-brand hover:underline" aria-expanded={showModules}>
                <Icon name="chevron" size={15} className={`transition-transform ${showModules ? "rotate-90" : ""}`} />
                {showModules ? "Ocultar módulos" : "Ver módulos habilitados y en solo lectura"}
              </button>
              {showModules && (
                <div className="mt-3 space-y-4">
                  {(["enabled", "read_only", "disabled_by_company"] as ModuleMode[]).map((mode) => {
                    const items = c.modules.items.filter((m) => m.mode === mode);
                    if (items.length === 0) return null;
                    return (
                      <div key={mode}>
                        <p className="mb-1.5 text-xs font-semibold text-muted">
                          {MODE[mode].label} · {items.length}
                        </p>
                        <ul className="flex flex-wrap gap-1.5">
                          {items.map((m) => (
                            <li
                              key={m.code}
                              className={`inline-flex items-center gap-1.5 rounded-lg border px-2 py-1 text-xs ${mode === "read_only" ? "border-dashed border-amber-ink/40 bg-amber-soft/40" : "border-line"}`}
                            >
                              <span className="font-mono font-semibold text-muted">{m.code}</span>
                              {m.name}
                            </li>
                          ))}
                        </ul>
                      </div>
                    );
                  })}
                </div>
              )}
            </>
          )}
        </Block>

        {/* Administradores */}
        <Block title="Administradores de cuenta" icon="people" count={c.admins.length}>
          {c.admins.length === 0 ? (
            <p className="text-sm text-muted">Sin administradores.</p>
          ) : (
            <ul className="divide-y divide-line rounded-xl border border-line">
              {c.admins.map((u) => (
                <li key={u.membership_id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
                  <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-brand-soft text-xs font-bold text-brand-strong">
                    {u.full_name
                      .split(" ")
                      .slice(0, 2)
                      .map((w) => w[0])
                      .join("")
                      .toUpperCase()}
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{u.full_name}</p>
                    <p className="truncate text-xs text-muted">{u.email}</p>
                  </div>
                  {u.status === "invited" ? (
                    <>
                      <Chip tone="amber">Invitación pendiente</Chip>
                      <Button variant="ghost" size="sm" icon="mail" onClick={() => onResend(c, u.user_id, u.email)}>
                        Reenviar
                      </Button>
                    </>
                  ) : (
                    <span className="flex flex-wrap items-center gap-2">
                      <span title={u.mfa_enabled ? "Ya configuró la verificación en dos pasos (app de autenticación)." : "Aún no configura la verificación en dos pasos. Se le pedirá en su primer ingreso; sin ella no puede entrar."}>
                        <Chip tone={u.mfa_enabled ? "ok" : "amber"}>{u.mfa_enabled ? "Verificación en 2 pasos activa" : "Verificación en 2 pasos pendiente"}</Chip>
                      </span>
                      <span className="text-xs text-muted">{u.last_login_at ? `Último ingreso: ${formatDate(u.last_login_at)}` : "Aún no ingresa"}</span>
                    </span>
                  )}
                </li>
              ))}
            </ul>
          )}
        </Block>
      </div>
    </article>
  );
}

function AccountInfo({ account: a }: { account: AccountDetail }) {
  const rows: [string, React.ReactNode][] = [
    ["Razón social", a.legal_name ?? "—"],
    ["Tipo", ACCOUNT_TYPE[a.account_type] ?? a.account_type],
    ["Correo de facturación", a.billing_email ?? "—"],
    ["Teléfono", a.billing_phone ?? "—"],
    ["Prueba gratuita", a.trial_used_at ? `Usada el ${formatDate(a.trial_used_at)}` : "No usada"],
  ];
  return (
    <section className="rounded-2xl border border-line bg-surface p-4 shadow-sm sm:p-5">
      <h2 className="mb-3 flex items-center gap-2 font-display font-semibold">
        <Icon name="file" size={17} className="text-muted" />
        Datos de la cuenta
      </h2>
      <dl className="space-y-2.5 text-sm">
        {rows.map(([k, v]) => (
          <div key={k} className="flex justify-between gap-4">
            <dt className="text-muted">{k}</dt>
            <dd className="min-w-0 truncate text-right font-medium" title={typeof v === "string" ? v : undefined}>
              {v}
            </dd>
          </div>
        ))}
      </dl>
      {a.notes && <p className="mt-3 rounded-lg bg-bg px-3 py-2 text-sm text-muted">{a.notes}</p>}
    </section>
  );
}

function eventDetail(e: TimelineEvent): string {
  const c = (e.changes ?? {}) as Record<string, unknown> & { before?: Record<string, string>; after?: Record<string, string> };
  const plan = (code?: string) => (code ? (PLAN_NAMES[code] ?? code) : "");
  switch (e.action) {
    case "account_created":
      return `Plan ${plan(c.plan as string)} · ${c.status === "trialing" ? "en prueba" : "contratado"} · administrador ${c.admin as string}`;
    case "subscription_plan_changed":
      return `${c.company as string}: ${plan(c.before?.plan)} → ${plan(c.after?.plan)}`;
    case "subscription_addon_added":
    case "subscription_addon_removed":
      return `${c.addon_name as string} · ${c.company as string}`;
    case "payment_registered":
      return `${formatMoney(Number(c.amount))} · ${PAYMENT_METHODS.find(([v]) => v === c.method)?.[1] ?? c.method} · ${c.company as string}`;
    case "subscription_reminder":
      return `${c.company as string}: ${c.days === 0 ? "vence hoy" : `vence en ${c.days as number} días`} (correo y sistema)`;
    case "subscription_past_due":
      return `${c.company as string}: plazo de gracia hasta ${formatDate((c.after as Record<string, string> | undefined)?.grace_until)}`;
    case "invitation_resent":
      return c.admin as string;
    default:
      return (c.company as string) ?? "";
  }
}

function Timeline({ events }: { events: TimelineEvent[] }) {
  return (
    <section className="rounded-2xl border border-line bg-surface p-4 shadow-sm sm:p-5">
      <h2 className="mb-4 flex items-center gap-2 font-display font-semibold">
        <Icon name="history" size={17} className="text-muted" />
        Línea de tiempo
      </h2>
      {events.length === 0 ? (
        <p className="text-sm text-muted">Sin cambios registrados.</p>
      ) : (
        <ol className="relative space-y-4 before:absolute before:inset-y-1 before:left-[15px] before:w-px before:bg-line">
          {events.map((e) => {
            const meta = ACTION[e.action] ?? { label: e.action, icon: "file" };
            return (
              <li key={e.id} className="relative flex gap-3">
                <span className="relative z-10 grid h-8 w-8 shrink-0 place-items-center rounded-full border border-line bg-surface text-brand">
                  <Icon name={meta.icon} size={15} />
                </span>
                <div className="min-w-0 pt-0.5">
                  <p className="text-sm font-semibold">{meta.label}</p>
                  <p className="break-words text-sm text-muted">{eventDetail(e)}</p>
                  {e.reason && <p className="mt-1 rounded-md bg-bg px-2 py-1 text-xs italic text-muted">«{e.reason}»</p>}
                  <p className="mt-1 text-xs text-muted">
                    {formatDate(e.occurred_at, true)}
                    {` · ${e.actor ?? "Sistema (automático)"}`}
                  </p>
                </div>
              </li>
            );
          })}
        </ol>
      )}
    </section>
  );
}

function Stat({ label, sub, children }: { label: string; sub?: string; children: React.ReactNode }) {
  return (
    <div className="min-w-0 bg-surface px-3 py-2.5">
      <dt className="text-[11px] font-semibold uppercase tracking-wide text-muted">{label}</dt>
      <dd className="truncate font-semibold">{children}</dd>
      {sub && <dd className="text-xs text-muted">{sub}</dd>}
    </div>
  );
}

function Block({ title, icon, count, children }: { title: string; icon: string; count?: number; children: React.ReactNode }) {
  return (
    <section className="border-t border-line pt-4">
      <h3 className="mb-2.5 flex items-center gap-2 text-sm font-bold uppercase tracking-wide">
        <Icon name={icon} size={15} className="text-muted" />
        {title}
        {count !== undefined && <span className="rounded-full bg-bg px-2 py-0.5 text-xs text-muted">{count}</span>}
      </h3>
      {children}
    </section>
  );
}

function Skeleton() {
  return (
    <div className="mx-auto max-w-7xl animate-pulse space-y-6" aria-label="Cargando cuenta">
      <div className="h-12 w-80 rounded-xl bg-line/60" />
      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <div className="h-96 rounded-2xl bg-line/50" />
        <div className="h-72 rounded-2xl bg-line/40" />
      </div>
    </div>
  );
}

/** Aviso de vencimiento en la tarjeta de la empresa: por vencer, pago pendiente o bloqueada. */
function DueNotice({ sub }: { sub: NonNullable<CompanyDetail["subscription"]> }) {
  const end = periodEnd(sub);
  const days = daysUntil(end);
  let tone = "";
  let text = "";
  if (sub.status === "past_due") {
    tone = "bg-amber-soft text-amber-ink";
    text = `Venció el ${formatDate(sub.current_period_end)}. Pago pendiente: si no se registra, queda en solo lectura el ${formatDate(sub.grace_until)}.`;
  } else if (sub.status === "read_only") {
    tone = "bg-bad-soft text-bad";
    text = `En solo lectura${sub.cancel_reason ? `: ${sub.cancel_reason}` : ""}. Se desbloquea al registrar el pago.`;
  } else if (sub.status === "trial_expired") {
    tone = "bg-bad-soft text-bad";
    text = `La prueba terminó el ${formatDate(sub.trial_ends_at)}. Registra el pago del plan para desbloquearla.`;
  } else if ((sub.status === "active" || sub.status === "trialing") && days !== null && days >= 0 && days <= REMINDER_DAYS) {
    tone = "bg-amber-soft text-amber-ink";
    text = `${sub.status === "trialing" ? "La prueba" : "El periodo"} vence ${days === 0 ? "hoy" : `en ${days} ${days === 1 ? "día" : "días"}`} (${formatDate(end)}). Se avisa al cliente 5 días antes, 3 días antes y el mismo día.`;
  }
  if (!text) return null;
  return (
    <p className={`flex items-start gap-2 rounded-xl px-3.5 py-2.5 text-sm ${tone}`}>
      <Icon name="clock" size={16} className="mt-0.5 shrink-0" />
      {text}
    </p>
  );
}
