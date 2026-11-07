"use client";

// Ventanas de acción sobre una suscripción (SCR-SUS-02): cambiar plan, suspender, reactivar,
// agregar o quitar complementos. Cada una llama a /internal/subscriptions/{id}/… y avisa al terminar.

import { FormEvent, useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { CheckCards, ChoiceCards, FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { api, ApiError } from "@/lib/api";
import { type CompanyDetail, companyName, formatMoney, PAYMENT_METHODS, PLAN_NAMES } from "@/lib/accounts";
import { PLAN_ACCENT } from "@/lib/plans";
import type { Addon, PlanMatrix } from "@/lib/types";

type Done = (message: string) => void;

/** Envía la acción y deja el error (general o por campo) listo para mostrar. */
function useAction() {
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

  async function run(fn: () => Promise<unknown>): Promise<boolean> {
    setSaving(true);
    setError(null);
    setFieldErrors({});
    try {
      await fn();
      return true;
    } catch (e) {
      if (e instanceof ApiError) {
        setFieldErrors(e.errors ?? {});
        setError(e.errors ? null : e.message);
      } else setError("No se pudo completar la acción.");
      return false;
    } finally {
      setSaving(false);
    }
  }

  return { saving, error, fieldErrors, run };
}

function ErrorBox({ text }: { text: string | null }) {
  return text ? <p className="rounded-lg border border-bad/30 bg-bad-soft px-4 py-3 text-sm text-bad">{text}</p> : null;
}

function Footer({ onClose, form, label, icon, variant = "primary", saving, disabled }: { onClose: () => void; form: string; label: string; icon: string; variant?: "primary" | "danger"; saving: boolean; disabled?: boolean }) {
  return (
    <>
      <Button variant="secondary" onClick={onClose}>
        Cancelar
      </Button>
      <Button type="submit" form={form} variant={variant} icon={icon} loading={saving} disabled={disabled}>
        {label}
      </Button>
    </>
  );
}

function ReasonField({ value, onChange, required, error, hint, label = "Motivo" }: { value: string; onChange: (v: string) => void; required?: boolean; error?: string; hint?: string; label?: string }) {
  return (
    <FormField label={required ? label : `${label} (opcional)`} counter={`${value.length}/300`} hint={hint ?? "Queda en la bitácora de auditoría."} error={error}>
      <textarea className="input min-h-20" maxLength={300} value={value} aria-invalid={!!error || undefined} onChange={(e) => onChange(e.target.value)} />
    </FormField>
  );
}

export function ChangePlanModal({ company, onClose, onDone }: { company: CompanyDetail; onClose: () => void; onDone: Done }) {
  const sub = company.subscription!;
  const [plan, setPlan] = useState<"E" | "P" | "C" | null>(null);
  const [reason, setReason] = useState("");
  const { saving, error, fieldErrors, run } = useAction();
  const level = { E: 1, P: 2, C: 3 } as Record<string, number>;
  const down = plan !== null && level[plan] < level[sub.plan_code];

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (!plan) return;
    const ok = await run(() => api(`/internal/subscriptions/${sub.id}/change-plan`, { method: "POST", body: { plan, reason: reason.trim() || null } }));
    if (ok) onDone(`${companyName(company)} ahora tiene el plan ${PLAN_NAMES[plan]}.`);
  }

  return (
    <Modal
      title="Cambiar plan"
      subtitle={`${companyName(company)} · hoy en ${sub.plan_name}`}
      icon="swap"
      onClose={onClose}
      footer={<Footer onClose={onClose} form="plan-change" label="Cambiar plan" icon="check" saving={saving} disabled={!plan} />}
    >
      <form id="plan-change" onSubmit={submit} className="space-y-5" noValidate>
        <ErrorBox text={error ?? fieldErrors.plan?.[0] ?? null} />
        <ChoiceCards
          label="Nuevo plan"
          columns={3}
          value={plan}
          onChange={setPlan}
          options={(["E", "P", "C"] as const).map((c) => ({
            value: c,
            title: PLAN_NAMES[c],
            accent: PLAN_ACCENT[c].bar,
            disabled: c === sub.plan_code,
            badge: c === sub.plan_code ? "Actual" : level[c] > level[sub.plan_code] ? "Subir" : "Bajar",
          }))}
        />
        {plan && (
          <p className={`rounded-lg px-4 py-3 text-sm ${down ? "bg-amber-soft text-amber-ink" : "bg-ok-soft text-ok"}`}>
            {down
              ? "Se aplica al momento. Los módulos que no están en el nuevo plan pasan a solo lectura: la empresa ve sus datos pero no puede registrar nada nuevo. No se borra nada."
              : "Se aplica al momento: los módulos del nuevo plan quedan habilitados para la empresa."}
          </p>
        )}
        <ReasonField value={reason} onChange={setReason} error={fieldErrors.reason?.[0]} />
      </form>
    </Modal>
  );
}

export function SuspendModal({ company, onClose, onDone }: { company: CompanyDetail; onClose: () => void; onDone: Done }) {
  const sub = company.subscription!;
  const [reason, setReason] = useState("");
  const { saving, error, fieldErrors, run } = useAction();

  async function submit(e: FormEvent) {
    e.preventDefault();
    const ok = await run(() => api(`/internal/subscriptions/${sub.id}/suspend`, { method: "POST", body: { reason: reason.trim() } }));
    if (ok) onDone(`${companyName(company)} quedó suspendida (solo lectura).`);
  }

  return (
    <Modal
      title="Suspender suscripción"
      subtitle={companyName(company)}
      icon="pause"
      size="sm"
      onClose={onClose}
      footer={<Footer onClose={onClose} form="suspend" label="Suspender" icon="pause" variant="danger" saving={saving} />}
    >
      <form id="suspend" onSubmit={submit} className="space-y-5" noValidate>
        <ErrorBox text={error} />
        <p className="rounded-lg bg-amber-soft px-4 py-3 text-sm text-amber-ink">
          Toda la empresa pasa a solo lectura: sus usuarios ven y exportan sus registros, pero no pueden crear ni editar. El cumplimiento legal del cliente no depende del pago.
        </p>
        <ReasonField value={reason} onChange={setReason} required error={fieldErrors.reason?.[0]} hint="Por ejemplo: pago vencido. Queda en la bitácora de auditoría." />
      </form>
    </Modal>
  );
}

export function ReactivateModal({ company, onClose, onDone }: { company: CompanyDetail; onClose: () => void; onDone: Done }) {
  const sub = company.subscription!;
  const [reason, setReason] = useState("");
  const { saving, error, fieldErrors, run } = useAction();

  async function submit(e: FormEvent) {
    e.preventDefault();
    const ok = await run(() => api(`/internal/subscriptions/${sub.id}/reactivate`, { method: "POST", body: { reason: reason.trim() || null } }));
    if (ok) onDone(`${companyName(company)} está activa otra vez.`);
  }

  return (
    <Modal
      title="Reactivar suscripción"
      subtitle={companyName(company)}
      icon="play"
      size="sm"
      onClose={onClose}
      footer={<Footer onClose={onClose} form="reactivate" label="Reactivar" icon="play" saving={saving} />}
    >
      <form id="reactivate" onSubmit={submit} className="space-y-5" noValidate>
        <ErrorBox text={error} />
        <p className="rounded-lg bg-ok-soft px-4 py-3 text-sm text-ok">
          La suscripción vuelve a estar activa con el plan {sub.plan_name} y los módulos se habilitan otra vez. Si el periodo ya venció, empieza uno nuevo desde hoy.
        </p>
        <ReasonField value={reason} onChange={setReason} error={fieldErrors.reason?.[0]} hint="Por ejemplo: pago confirmado. Queda en la bitácora de auditoría." />
      </form>
    </Modal>
  );
}

export function AddAddonModal({ company, onClose, onDone }: { company: CompanyDetail; onClose: () => void; onDone: Done }) {
  const sub = company.subscription!;
  const [addons, setAddons] = useState<Addon[] | null>(null);
  const [codes, setCodes] = useState<string[]>([]);
  const { saving, error, fieldErrors, run } = useAction();
  const owned = new Set(company.addons.map((a) => a.code));

  useEffect(() => {
    api<PlanMatrix>("/internal/plans")
      .then((m) => setAddons(m.addons.filter((a) => a.is_active)))
      .catch(() => setAddons([]));
  }, []);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (codes.length === 0) return;
    const ok = await run(() => api(`/internal/subscriptions/${sub.id}/addons`, { method: "POST", body: { addons: codes } }));
    if (ok) onDone(codes.length === 1 ? `Complemento agregado a ${companyName(company)}.` : `${codes.length} complementos agregados a ${companyName(company)}.`);
  }

  const available = (addons ?? []).filter((a) => !owned.has(a.code));

  return (
    <Modal
      title="Agregar complementos"
      subtitle={`${companyName(company)} · plan ${sub.plan_name}`}
      icon="box"
      onClose={onClose}
      footer={
        <Footer
          onClose={onClose}
          form="add-addon"
          label={codes.length > 1 ? `Agregar ${codes.length}` : "Agregar"}
          icon="plus"
          saving={saving}
          disabled={codes.length === 0}
        />
      }
    >
      <form id="add-addon" onSubmit={submit} className="space-y-5" noValidate>
        <ErrorBox text={error ?? fieldErrors.addons?.[0] ?? null} />
        {addons === null ? (
          <div className="h-40 animate-pulse rounded-xl bg-line/40" />
        ) : addons.length === 0 ? (
          <p className="text-sm text-muted">No hay complementos activos.</p>
        ) : (
          <>
            <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
              <span className="text-muted">Marca uno o varios. {codes.length > 0 && <b className="text-fg">{codes.length} elegidos</b>}</span>
              {available.length > 1 && (
                <button
                  type="button"
                  className="font-semibold text-brand hover:underline"
                  onClick={() => setCodes(codes.length === available.length ? [] : available.map((a) => a.code))}
                >
                  {codes.length === available.length ? "Quitar todos" : "Marcar todos"}
                </button>
              )}
            </div>
            <CheckCards
              label="Complementos"
              value={codes}
              onChange={setCodes}
              options={addons.map((a) => ({
                value: a.code,
                title: a.name,
                detail: a.description ?? undefined,
                disabled: owned.has(a.code),
                badge: owned.has(a.code) ? "Ya lo tiene" : a.code,
              }))}
            />
          </>
        )}
        <p className="text-xs text-muted">Se habilitan al momento. El precio sigue «Por definir» hasta configurarlo en Planes y módulos.</p>
      </form>
    </Modal>
  );
}

export function RemoveAddonModal({ company, addon, onClose, onDone }: { company: CompanyDetail; addon: CompanyDetail["addons"][number]; onClose: () => void; onDone: Done }) {
  const sub = company.subscription!;
  const [reason, setReason] = useState("");
  const { saving, error, fieldErrors, run } = useAction();

  async function submit(e: FormEvent) {
    e.preventDefault();
    const ok = await run(() => api(`/internal/subscriptions/${sub.id}/addons/${addon.code}`, { method: "DELETE", body: { reason: reason.trim() || null } }));
    if (ok) onDone(`${addon.name} se quitó de ${companyName(company)}.`);
  }

  return (
    <Modal
      title={`Quitar ${addon.name}`}
      subtitle={companyName(company)}
      icon="close"
      size="sm"
      onClose={onClose}
      footer={<Footer onClose={onClose} form="remove-addon" label="Quitar complemento" icon="close" variant="danger" saving={saving} />}
    >
      <form id="remove-addon" onSubmit={submit} className="space-y-5" noValidate>
        <ErrorBox text={error} />
        <p className="rounded-lg bg-amber-soft px-4 py-3 text-sm text-amber-ink">El módulo del complemento pasa a solo lectura: la empresa conserva sus datos y puede exportarlos.</p>
        <ReasonField value={reason} onChange={setReason} error={fieldErrors.reason?.[0]} />
      </form>
    </Modal>
  );
}

export function RegisterPaymentModal({ company, onClose, onDone }: { company: CompanyDetail; onClose: () => void; onDone: Done }) {
  const sub = company.subscription!;
  const today = new Date().toLocaleDateString("en-CA", { timeZone: "America/Lima" });
  // Monto sugerido = precio del periodo × periodos que cubre; se puede corregir (p. ej., un precio pactado).
  const suggested = (n: number) => (sub.period_price == null ? "" : String(Math.round(sub.period_price * n * 100) / 100));
  const [form, setForm] = useState({ amount: suggested(1), method: "transfer", paid_at: today, reference: "", periods: "1", note: "" });
  const [amountTouched, setAmountTouched] = useState(false);
  const { saving, error, fieldErrors, run } = useAction();
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }));
  const unit = sub.billing_period === "annual" ? ["año", "años"] : ["mes", "meses"];
  const amountHint =
    sub.period_price == null
      ? "El plan aún no tiene precio: escribe el monto acordado."
      : `Precio: ${formatMoney(sub.period_price)} por ${unit[0]}${sub.addons_count ? " (incluye complementos)" : ""}.${amountTouched ? "" : " Puedes cambiarlo."}`;
  const blocked = ["read_only", "trial_expired", "past_due"].includes(sub.status);

  async function submit(e: FormEvent) {
    e.preventDefault();
    const ok = await run(() =>
      api(`/internal/subscriptions/${sub.id}/payments`, {
        method: "POST",
        body: {
          amount: form.amount === "" ? null : Number(form.amount),
          method: form.method,
          paid_at: form.paid_at,
          reference: form.reference.trim() || null,
          periods: Number(form.periods),
          note: form.note.trim() || null,
        },
      }),
    );
    if (ok) onDone(blocked ? `Pago registrado: ${companyName(company)} quedó desbloqueada.` : `Pago registrado para ${companyName(company)}.`);
  }

  return (
    <Modal
      title="Registrar pago"
      subtitle={`${companyName(company)} · plan ${sub.plan_name} ${sub.billing_period === "annual" ? "anual" : "mensual"}`}
      icon="file"
      onClose={onClose}
      footer={<Footer onClose={onClose} form="payment" label="Registrar pago" icon="check" saving={saving} />}
    >
      <form id="payment" onSubmit={submit} className="space-y-5" noValidate>
        <ErrorBox text={error} />
        <p className={`rounded-lg px-4 py-3 text-sm ${blocked ? "bg-ok-soft text-ok" : "bg-brand-soft text-brand-strong"}`}>
          {blocked
            ? "Al registrarlo, la empresa se desbloquea al momento y vuelve a poder registrar. Le avisamos por correo y en el sistema."
            : "El nuevo periodo empieza cuando termina el actual: pagar antes no hace perder días. Le avisamos por correo y en el sistema."}
        </p>
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Monto pagado" hint={amountHint} error={fieldErrors.amount?.[0]}>
            <span className="relative block">
              <span className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm font-semibold text-muted">S/</span>
              <input
                className="input pl-10 tabular-nums"
                inputMode="decimal"
                value={form.amount}
                aria-invalid={!!fieldErrors.amount || undefined}
                onChange={(e) => {
                  setAmountTouched(true);
                  set("amount", e.target.value.replace(",", ".").replace(/[^\d.]/g, ""));
                }}
                autoFocus
              />
            </span>
          </FormField>
          <FormField label="Cubre" error={fieldErrors.periods?.[0]}>
            <select
              className="input"
              value={form.periods}
              onChange={(e) => setForm((f) => ({ ...f, periods: e.target.value, amount: amountTouched ? f.amount : suggested(Number(e.target.value)) }))}
            >
              {Array.from({ length: sub.billing_period === "annual" ? 3 : 12 }, (_, i) => i + 1).map((n) => (
                <option key={n} value={n}>
                  {n} {n === 1 ? unit[0] : unit[1]}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Medio de pago" error={fieldErrors.method?.[0]}>
            <select className="input" value={form.method} onChange={(e) => set("method", e.target.value)}>
              {PAYMENT_METHODS.map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
          <FormField label="Fecha del pago" error={fieldErrors.paid_at?.[0]}>
            <input className="input" type="date" max={today} value={form.paid_at} onChange={(e) => set("paid_at", e.target.value)} />
          </FormField>
        </div>
        <FormField label="N.° de operación (opcional)" hint="El que figura en el voucher o la captura del pago." error={fieldErrors.reference?.[0]}>
          <input className="input" maxLength={60} value={form.reference} onChange={(e) => set("reference", e.target.value)} />
        </FormField>
        <ReasonField label="Nota" value={form.note} onChange={(v) => set("note", v)} error={fieldErrors.note?.[0]} hint="Nota interna. Queda en la bitácora de auditoría." />
        <p className="text-xs text-muted">El comprobante electrónico (SUNAT) queda pendiente hasta integrar el proveedor OSE.</p>
      </form>
    </Modal>
  );
}
