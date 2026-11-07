"use client";

// «Nueva cuenta» (SCR-SUS-02): POST /internal/accounts.
// Crea la cuenta, su primera empresa, la suscripción (prueba o contratada) e invita al administrador de cuenta.

import { FormEvent, useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { ChoiceCards, FormField, FormSection } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { api, ApiError } from "@/lib/api";
import { formatMoney, isValidRuc, SECTORS } from "@/lib/accounts";
import type { LegalParameter } from "@/lib/catalogs";
import { PLAN_ACCENT } from "@/lib/plans";
import type { PlanMatrix } from "@/lib/types";


type Form = {
  ruc: string;
  legal_name: string;
  trade_name: string;
  economic_sector: string;
  headcount: string;
  start: "trial" | "active";
  plan: "E" | "P" | "C" | null;
  billing_period: "monthly" | "annual";
  admin_name: string;
  admin_email: string;
  account_type: "direct" | "group";
  account_name: string;
  billing_email: string;
};

const INITIAL: Form = {
  ruc: "",
  legal_name: "",
  trade_name: "",
  economic_sector: "",
  headcount: "",
  start: "trial",
  plan: null,
  billing_period: "monthly",
  admin_name: "",
  admin_email: "",
  account_type: "direct",
  account_name: "",
  billing_email: "",
};

export function CreateAccountModal({ onClose, onCreated }: { onClose: () => void; onCreated: (accountId: string) => void }) {
  const [form, setForm] = useState<Form>(INITIAL);
  // Umbral de Comité SST (parámetro legal vigente en Catálogos) y planes activos (Planes y módulos):
  // se leen de la API para no repetir valores en el código.
  const [committeeFrom, setCommitteeFrom] = useState<number | null>(null);
  const [plans, setPlans] = useState<PlanMatrix["plans"]>([]);
  const [annualDiscount, setAnnualDiscount] = useState(0);

  useEffect(() => {
    api<{ data: LegalParameter[] }>("/internal/catalogs/legal-parameters")
      .then((r) => {
        const p = r.data.find((x) => x.key === "committee_threshold_workers" && x.is_current);
        if (p) setCommitteeFrom(Number(p.value));
        const d = r.data.find((x) => x.key === "sus.annual_discount_pct" && x.is_current);
        if (d && Number(d.value) > 0) setAnnualDiscount(Number(d.value));
      })
      .catch(() => undefined);
    api<PlanMatrix>("/internal/plans")
      .then((m) => setPlans(m.plans.filter((p) => ["E", "P", "C"].includes(p.code) && p.is_active)))
      .catch(() => undefined);
  }, []);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const set = <K extends keyof Form>(k: K, v: Form[K]) => setForm((f) => ({ ...f, [k]: v }));
  const err = (k: string) => errors[k]?.[0];

  const rucHint =
    form.ruc.length === 11 ? (isValidRuc(form.ruc) ? "RUC válido." : undefined) : "11 dígitos. Empieza con 10, 15, 17 o 20.";
  const rucError = err("ruc") ?? (form.ruc.length === 11 && !isValidRuc(form.ruc) ? "El dígito verificador no coincide: revisa el RUC." : undefined);
  const headcount = Number(form.headcount);
  const sstHint =
    form.headcount && committeeFrom !== null
      ? headcount >= committeeFrom
        ? `Le corresponde Comité SST paritario (desde ${committeeFrom} trabajadores).`
        : `Le corresponde Supervisor SST (menos de ${committeeFrom} trabajadores).`
      : "Se usa para proponer Comité o Supervisor SST.";

  async function save(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setError(null);
    try {
      const res = await api<{ data: { account_id: string } }>("/internal/accounts", {
        method: "POST",
        body: {
          ...form,
          headcount: form.headcount === "" ? null : Number(form.headcount),
          plan: form.start === "trial" ? null : form.plan,
          trade_name: form.trade_name.trim() || null,
          economic_sector: form.economic_sector || null,
          account_name: form.account_name.trim() || null,
          billing_email: form.billing_email.trim() || null,
        },
      });
      onCreated(res.data.account_id);
    } catch (e) {
      if (e instanceof ApiError) {
        setErrors(e.errors ?? {});
        setError(e.type === "validation_failed" ? "Revisa los campos marcados." : e.message);
      } else setError("No se pudo crear la cuenta.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      title="Nueva cuenta"
      subtitle="Empresa, suscripción y administrador de cuenta"
      icon="building"
      onClose={onClose}
      size="lg"
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" form="account-form" variant="primary" icon="check" loading={saving}>
            Crear cuenta e invitar
          </Button>
        </>
      }
    >
      <form id="account-form" onSubmit={save} className="space-y-4" noValidate>
        {error && <p className="rounded-lg border border-bad/30 bg-bad-soft px-4 py-3 text-sm text-bad">{error}</p>}

        <FormSection icon="building" title="Empresa" description="La unidad que se suscribe: cada empresa con RUC tiene su propio plan.">
          <div className="grid gap-4 sm:grid-cols-[12rem_minmax(0,1fr)]">
            <FormField label="RUC" hint={rucHint} error={rucError}>
              <input
                className="input tabular-nums"
                inputMode="numeric"
                maxLength={11}
                value={form.ruc}
                aria-invalid={!!rucError || undefined}
                onChange={(e) => set("ruc", e.target.value.replace(/\D/g, ""))}
                autoFocus
              />
            </FormField>
            <FormField label="Razón social" error={err("legal_name")}>
              <input className="input" maxLength={160} value={form.legal_name} aria-invalid={!!err("legal_name") || undefined} onChange={(e) => set("legal_name", e.target.value)} />
            </FormField>
          </div>
          <div className="grid gap-4 sm:grid-cols-3">
            <FormField label="Nombre comercial" hint="Opcional." error={err("trade_name")}>
              <input className="input" maxLength={120} value={form.trade_name} onChange={(e) => set("trade_name", e.target.value)} />
            </FormField>
            <FormField label="Sector" hint="Opcional." error={err("economic_sector")}>
              <select className="input" value={form.economic_sector} onChange={(e) => set("economic_sector", e.target.value)}>
                <option value="">Sin indicar</option>
                {SECTORS.map(([v, l]) => (
                  <option key={v} value={v}>
                    {l}
                  </option>
                ))}
              </select>
            </FormField>
            <FormField label="Trabajadores" hint={sstHint} error={err("headcount")}>
              <input
                className="input tabular-nums"
                inputMode="numeric"
                value={form.headcount}
                aria-invalid={!!err("headcount") || undefined}
                onChange={(e) => set("headcount", e.target.value.replace(/\D/g, "").slice(0, 7))}
              />
            </FormField>
          </div>
        </FormSection>

        <FormSection icon="star" title="Suscripción">
          <ChoiceCards
            label="Cómo empieza"
            value={form.start}
            onChange={(v) => set("start", v)}
            options={[
              { value: "trial", title: "Prueba gratuita", detail: "Plan Profesional completo, sin complementos. Una sola prueba por RUC." },
              { value: "active", title: "Plan contratado", detail: "Empieza activa con el plan que elijas." },
            ]}
          />
          {form.start === "active" && (
            <div className="space-y-1.5">
              <p className="text-sm font-semibold">Plan</p>
              <ChoiceCards
                label="Plan"
                columns={3}
                value={form.plan}
                onChange={(v) => set("plan", v)}
                options={plans.map((p) => ({
                  value: p.code as "E" | "P" | "C",
                  title: p.name,
                  detail: p.description ?? undefined,
                  accent: PLAN_ACCENT[p.code]?.bar,
                }))}
              />
              {err("plan") && <p className="text-xs font-medium text-bad">● {err("plan")}</p>}
            </div>
          )}
          <div className="space-y-2">
            <p className="text-sm font-semibold">
              ¿Cómo pagará la empresa? {form.start === "trial" && <span className="font-normal text-muted">· se aplica cuando contrate</span>}
            </p>
            <ChoiceCards
              label="Periodo de cobro"
              value={form.billing_period}
              onChange={(v) => set("billing_period", v)}
              options={(["monthly", "annual"] as const).map((v) => {
                const monthly = plans.find((p) => p.code === (form.start === "trial" ? "P" : form.plan))?.price_reference;
                const amount = monthly == null ? null : v === "annual" ? Number(monthly) * 12 * (1 - annualDiscount / 100) : Number(monthly);
                return {
                  value: v,
                  title: v === "monthly" ? "Mensual" : "Anual",
                  detail:
                    amount == null
                      ? v === "monthly"
                        ? "Un pago cada mes."
                        : `Un solo pago por el año${annualDiscount ? ` (−${annualDiscount}%)` : ""}.`
                      : v === "monthly"
                        ? `${formatMoney(amount)} cada mes.`
                        : `${formatMoney(amount)} al año${annualDiscount ? ` (−${annualDiscount}%)` : " (12 meses)"}.`,
                };
              })}
            />
            {err("billing_period") && <p className="text-xs font-medium text-bad">● {err("billing_period")}</p>}
          </div>
        </FormSection>

        <FormSection icon="user" title="Administrador de cuenta" description="Recibirá un correo para crear su contraseña. Al ingresar por primera vez configurará la verificación en dos pasos.">
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Nombre completo" error={err("admin_name")}>
              <input className="input" maxLength={120} value={form.admin_name} aria-invalid={!!err("admin_name") || undefined} onChange={(e) => set("admin_name", e.target.value)} />
            </FormField>
            <FormField label="Correo" error={err("admin_email")}>
              <input
                className="input"
                type="email"
                maxLength={160}
                value={form.admin_email}
                aria-invalid={!!err("admin_email") || undefined}
                onChange={(e) => set("admin_email", e.target.value)}
              />
            </FormField>
          </div>
        </FormSection>

        <FormSection icon="file" title="Cuenta y facturación" description="Opcional. Si lo dejas vacío, la cuenta toma la razón social y el correo del administrador.">
          <div className="grid gap-4 sm:grid-cols-3">
            <FormField label="Tipo de cuenta">
              <select className="input" value={form.account_type} onChange={(e) => set("account_type", e.target.value as Form["account_type"])}>
                <option value="direct">Empresa directa</option>
                <option value="group">Grupo empresarial</option>
              </select>
            </FormField>
            <FormField label="Nombre de la cuenta" error={err("account_name")}>
              <input className="input" maxLength={120} placeholder={form.legal_name || "Igual a la razón social"} value={form.account_name} onChange={(e) => set("account_name", e.target.value)} />
            </FormField>
            <FormField label="Correo de facturación" error={err("billing_email")}>
              <input className="input" type="email" maxLength={160} placeholder={form.admin_email || "Igual al del administrador"} value={form.billing_email} onChange={(e) => set("billing_email", e.target.value)} />
            </FormField>
          </div>
        </FormSection>
      </form>
    </Modal>
  );
}
