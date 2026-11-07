"use client";

// «Editar plan» (SCR-SUS-01): pestaña Datos y límites (PATCH /internal/plans/{plan})
// y pestaña Módulos (PUT /internal/plans/{plan}/modules/{module}, se aplica al momento).

import { FormEvent, useEffect, useMemo, useState } from "react";
import { SearchInput } from "@/components/ui/FilterBar";
import { Button } from "@/components/ui/Button";
import { AffixInput, ChipToggleGroup, FormField, FormSection, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Switch } from "@/components/ui/Switch";
import { formatMoney } from "@/lib/accounts";
import { api, ApiError } from "@/lib/api";
import type { LegalParameter } from "@/lib/catalogs";
import { CATALOG_GROUPS, LIMIT_LABELS } from "@/lib/plans";
import type { PlanLimits, PlanMatrix, PlanMatrixModule, PlanSummary } from "@/lib/types";

type Props = {
  plan: PlanSummary;
  matrix: PlanMatrix;
  onClose: () => void;
  onSaved: (message: string) => void;
  onToggleModule: (module: PlanMatrixModule, plan: PlanSummary, included: boolean) => void;
  busyKey: string | null;
};

export function EditPlanModal({ plan, matrix, onClose, onSaved, onToggleModule, busyKey }: Props) {
  const [tab, setTab] = useState<"datos" | "modulos">("datos");
  const [form, setForm] = useState({
    name: plan.name,
    description: plan.description ?? "",
    price: plan.price_reference ?? "",
    monthly: plan.billing_periods.includes("monthly"),
    annual: plan.billing_periods.includes("annual"),
    is_public: plan.is_public,
    is_active: plan.is_active,
    limits: Object.fromEntries(Object.entries(plan.limits).map(([k, v]) => [k, v === null ? "" : String(v)])) as Record<keyof PlanLimits, string>,
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  // Descuento por pago anual (Catálogos › Parámetros, sus.annual_discount_pct). 0 = sin descuento.
  const [discount, setDiscount] = useState(0);
  useEffect(() => {
    api<{ data: LegalParameter[] }>("/internal/catalogs/legal-parameters")
      .then((r) => setDiscount(Number(r.data.find((x) => x.key === "sus.annual_discount_pct" && x.is_current)?.value ?? 0) || 0))
      .catch(() => undefined);
  }, []);
  const monthlyPrice = form.price === "" || isNaN(Number(form.price)) ? null : Number(form.price);
  const [q, setQ] = useState("");
  const [onlyIncluded, setOnlyIncluded] = useState(false);

  const isMatrixPlan = plan.code !== "K";
  // Los datos del plan vienen siempre actualizados desde la matriz (después de cada cambio).
  const livePlan = matrix.plans.find((p) => p.code === plan.code) ?? plan;

  const modules = useMemo(() => {
    const text = q.trim().toLowerCase();
    return matrix.modules.filter(
      (m) =>
        m.assignable &&
        (!onlyIncluded || m.plans[plan.code as "E" | "P" | "C"] !== null) &&
        (!text || `${m.code} ${m.name}`.toLowerCase().includes(text)),
    );
  }, [matrix, q, onlyIncluded, plan.code]);

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setError(null);
    const periods = [form.monthly && "monthly", form.annual && "annual"].filter(Boolean);
    const limits = Object.fromEntries(Object.entries(form.limits).map(([k, v]) => [k, v === "" ? null : Number(v)]));
    try {
      await api(`/internal/plans/${plan.code}`, {
        method: "PATCH",
        body: {
          name: form.name.trim(),
          description: form.description.trim() || null,
          price_reference: form.price === "" ? null : Number(form.price),
          billing_periods: periods,
          is_public: form.is_public,
          is_active: form.is_active,
          limits,
        },
      });
      onSaved(`Plan ${form.name.trim()} guardado.`);
    } catch (err) {
      if (err instanceof ApiError) {
        setErrors(err.errors ?? {});
        if (err.type !== "validation_failed") setError(err.message);
      } else setError("No se pudo guardar.");
    } finally {
      setSaving(false);
    }
  }

  const fieldError = (k: string) => errors[k]?.[0];

  return (
    <Modal
      title={`Editar plan ${livePlan.name}`}
      subtitle={`${livePlan.modules_count} módulos · ${livePlan.companies_count === 1 ? "1 empresa" : `${livePlan.companies_count} empresas`} con este plan`}
      icon="tag"
      onClose={onClose}
      size="lg"
      footer={
        tab === "datos" ? (
          <>
            <Button variant="secondary" onClick={onClose}>
              Cancelar
            </Button>
            <Button type="submit" form="plan-form" variant="primary" icon="check" loading={saving}>
              {saving ? "Guardando…" : "Guardar cambios"}
            </Button>
          </>
        ) : (
          <Button variant="primary" icon="check" onClick={onClose}>
            Listo
          </Button>
        )
      }
    >
      <div role="tablist" className="mb-6 flex w-full overflow-x-auto rounded-xl border border-line bg-bg p-1 sm:inline-flex sm:w-auto">
        {(
          [
            ["datos", "Datos y límites", "pen"],
            ["modulos", `Módulos (${livePlan.modules_count})`, "grid"],
          ] as const
        ).map(([k, label, icon]) => (
          <button
            key={k}
            role="tab"
            aria-selected={tab === k}
            onClick={() => setTab(k)}
            className={`inline-flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-lg px-4 py-2 text-sm font-semibold transition-colors sm:flex-none ${tab === k ? "bg-surface text-brand-strong shadow-sm" : "text-muted hover:text-fg"}`}
          >
            <Icon name={icon} size={15} />
            {label}
          </button>
        ))}
      </div>

      {tab === "datos" && (
        <form id="plan-form" onSubmit={save} className="space-y-4" noValidate>
          {error && <p className="rounded-lg border border-bad/30 bg-bad-soft px-4 py-3 text-sm text-bad">{error}</p>}

          <FormSection icon="tag" title="Información del plan">
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField label="Nombre" error={fieldError("name")}>
                <input className="input" value={form.name} maxLength={60} aria-invalid={!!fieldError("name") || undefined} onChange={(e) => setForm({ ...form, name: e.target.value })} />
              </FormField>
              <FormField label="Código">
                <input className="input bg-bg text-muted" value={`${plan.code} · no se puede cambiar`} disabled />
              </FormField>
            </div>
            <FormField label="Descripción" counter={`${form.description.length}/500`} error={fieldError("description")}>
              <textarea className="input min-h-20" maxLength={500} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </FormField>
          </FormSection>

          <FormSection icon="star" title="Precio y cobro" description="Los precios no se escriben en el código: si lo dejas vacío, se muestra «Por definir».">
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField
                label={plan.code === "K" ? "Precio de referencia por empresa al mes" : "Precio de referencia mensual (por empresa)"}
                hint="Lo que paga cada empresa al mes. Se usa en «Planes» del sitio, en las cotizaciones y su PDF. Vacío = «Por definir»."
                error={fieldError("price_reference")}
              >
                <AffixInput prefix="S/" inputMode="decimal" placeholder="Por definir" value={form.price} invalid={!!fieldError("price_reference")} onChange={(e) => setForm({ ...form, price: e.target.value.replace(",", ".") })} />
              </FormField>
              <div>
                <p className="mb-1.5 text-sm font-semibold">Formas de pago que se ofrecen</p>
                <ChipToggleGroup
                  label="Formas de pago que se ofrecen"
                  options={[
                    ["monthly", "Mensual"],
                    ["annual", "Anual"],
                  ]}
                  value={[form.monthly && "monthly", form.annual && "annual"].filter(Boolean) as ("monthly" | "annual")[]}
                  onChange={(v) => setForm({ ...form, monthly: v.includes("monthly"), annual: v.includes("annual") })}
                />
                {fieldError("billing_periods") && <p className="mt-1.5 text-xs font-medium text-bad">● {fieldError("billing_periods")}</p>}
              </div>
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              {form.monthly && (
                <div className="rounded-xl border border-line bg-bg/50 px-4 py-3 text-sm">
                  <p className="font-semibold">Si elige mensual</p>
                  <p className="text-muted">{monthlyPrice == null ? "Paga el precio mensual cada mes." : `Paga ${formatMoney(monthlyPrice)} cada mes.`}</p>
                </div>
              )}
              {form.annual && (
                <div className="rounded-xl border border-line bg-bg/50 px-4 py-3 text-sm">
                  <p className="font-semibold">Si elige anual</p>
                  <p className="text-muted">
                    {monthlyPrice == null ? "Paga 12 meses en un solo pago" : `Paga ${formatMoney(monthlyPrice * 12 * (1 - discount / 100))} una vez al año`}
                    {discount > 0 ? ` (12 meses con ${discount}% de descuento).` : " (12 meses, sin descuento)."}
                  </p>
                  <p className="mt-1 text-xs text-muted">El descuento anual se cambia en esta misma página, en «Descuento por pago anual».</p>
                </div>
              )}
            </div>
          </FormSection>

          <FormSection icon="people" title="Límites del plan" description="Déjalo vacío si no tiene límite.">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              {(Object.keys(LIMIT_LABELS) as (keyof PlanLimits)[]).map((k) => (
                <FormField key={k} label={LIMIT_LABELS[k].label} error={fieldError(`limits.${k}`)}>
                  <AffixInput
                    suffix={LIMIT_LABELS[k].unit}
                    inputMode="numeric"
                    placeholder="Sin límite"
                    value={form.limits[k]}
                    invalid={!!fieldError(`limits.${k}`)}
                    onChange={(e) => setForm({ ...form, limits: { ...form.limits, [k]: e.target.value.replace(/\D/g, "") } })}
                  />
                </FormField>
              ))}
            </div>
          </FormSection>

          <FormSection icon="eye" title="Disponibilidad">
            <div className="grid gap-3 sm:grid-cols-2">
              <SwitchRow label="Plan activo" hint="Si lo desactivas, ya no se ofrece en suscripciones nuevas. Las empresas que lo tienen no cambian." checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} />
              <SwitchRow label="Visible en el sitio web" hint="Se muestra en la página de planes del sitio público." checked={form.is_public} onChange={(v) => setForm({ ...form, is_public: v })} />
            </div>
          </FormSection>
        </form>
      )}

      {tab === "modulos" &&
        (isMatrixPlan ? (
          <div className="space-y-4">
            <p className="text-sm text-muted">Cada cambio se guarda al momento y se aplica a las empresas con este plan. Los planes son acumulativos.</p>
            <div className="flex flex-wrap items-end gap-3">
              <SearchInput label="Buscar módulo del plan" placeholder="Nombre o código" value={q} onChange={setQ} />
              <label className="flex h-[2.625rem] items-center gap-2 rounded-[0.625rem] border border-line px-3 text-sm">
                <input type="checkbox" checked={onlyIncluded} onChange={(e) => setOnlyIncluded(e.target.checked)} /> Solo los incluidos
              </label>
              <span className="ml-auto self-center text-sm text-muted">{modules.length} módulos</span>
            </div>
            {Object.entries(CATALOG_GROUPS).map(([n, title]) => {
              const rows = modules.filter((m) => m.group_number === Number(n));
              if (rows.length === 0) return null;
              return (
                <div key={n}>
                  <p className="mb-1 text-xs font-bold uppercase tracking-wide text-muted">{title}</p>
                  <ul className="divide-y divide-line rounded-lg border border-line">
                    {rows.map((m) => {
                      const state = m.plans[plan.code as "E" | "P" | "C"];
                      return (
                        <li key={m.code} className="flex items-center justify-between gap-3 px-3 py-2">
                          <span className="min-w-0 text-sm">
                            <span className="mr-2 rounded bg-bg px-1.5 py-0.5 font-mono text-xs">{m.code}</span>
                            {m.name}
                            {state === "inherited" && <span className="ml-2 text-xs text-muted">(viene de un plan inferior)</span>}
                            {m.core && <span className="ml-2 rounded-full bg-brand-soft px-2 py-0.5 text-xs text-brand-strong">Núcleo</span>}
                          </span>
                          <Switch
                            checked={state !== null}
                            disabled={(m.core && state !== null) || (busyKey !== null && busyKey !== `${m.code}-${plan.code}`)}
                            busy={busyKey === `${m.code}-${plan.code}`}
                            label={`${m.name} en ${plan.name}`}
                            onChange={(v) => onToggleModule(m, livePlan, v)}
                          />
                        </li>
                      );
                    })}
                  </ul>
                </div>
              );
            })}
            {modules.length === 0 && <p className="py-6 text-center text-sm text-muted">Ningún módulo coincide con la búsqueda.</p>}
          </div>
        ) : (
          <div className="space-y-3 text-sm">
            <p>
              El plan <b>Consultora</b> habilita el <b>Modo consultora y marca blanca (CON)</b>. Se cobra por empresa gestionada y cada empresa cliente conserva su propio plan (RN-SUS-04), por eso no lleva la lista de módulos.
            </p>
            <ul className="rounded-lg border border-line px-3 py-2">
              {matrix.modules
                .filter((m) => m.plan_code === "K")
                .map((m) => (
                  <li key={m.code}>
                    <span className="mr-2 rounded bg-bg px-1.5 py-0.5 font-mono text-xs">{m.code}</span>
                    {m.name}
                  </li>
                ))}
            </ul>
          </div>
        ))}
    </Modal>
  );
}
