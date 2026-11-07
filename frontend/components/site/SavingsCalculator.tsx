"use client";

// Calculadora de ahorro (SCR-WEB-82): trabajadores, sedes, registros al mes y horas dedicadas → horas (y soles, si hay
// costo por hora) que se ahorrarían al mes. Supuestos visibles y rótulo «Estimación referencial, no garantizada».
// El resultado completo por correo es opcional, con privacidad obligatoria y casilla comercial aparte.

import Link from "next/link";
import { FormEvent, useState } from "react";
import { Check, Field, Honeypot } from "@/components/site/PublicForms";
import type { SavingsCalculator as Calc } from "@/lib/site";
import { rememberUtm, track } from "@/lib/track";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";
type Errors = Record<string, string[]>;
type Result = { result_hours_month: number; result_cost_month: number | null; emailed: boolean };

export function SavingsCalculator({ calc }: { calc: NonNullable<Calc> }) {
  const [f, setF] = useState({ workers: "", sites: "1", records_per_month: "", hours_per_month: "", email: "", full_name: "", company_name: "", website: "" });
  const [privacy, setPrivacy] = useState(false);
  const [marketing, setMarketing] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const [message, setMessage] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<Result | null>(null);
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));
  const err = (k: string) => errors[k]?.[0];
  const digits = (v: string) => v.replace(/\D/g, "").slice(0, 7);
  const a = calc.assumptions;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setMessage(null);
    setErrors({});
    const body = {
      workers: Number(f.workers) || null,
      sites: Number(f.sites) || 1,
      records_per_month: f.records_per_month === "" ? null : Number(f.records_per_month),
      hours_per_month: f.hours_per_month === "" ? null : Number(f.hours_per_month),
      email: f.email.trim() || null,
      full_name: f.full_name.trim() || null,
      company_name: f.company_name.trim() || null,
      privacy_consent: privacy,
      marketing_consent: marketing,
      website: f.website,
      ...rememberUtm(),
    };
    try {
      const res = await fetch(`${API}/api/v1/public/tools/savings-calculator`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json" }, body: JSON.stringify(body) });
      const j = await res.json().catch(() => ({}));
      if (res.ok) {
        setResult(j.data);
        track("savings_calculated");
      } else {
        setErrors(j.errors ?? {});
        setMessage(res.status === 429 ? "Hiciste varios cálculos seguidos. Espera unos minutos." : j.errors ? "Revisa los campos marcados." : (j.detail ?? "No pudimos calcular."));
      }
    } catch {
      setMessage("No hay conexión. Revisa tu internet e inténtalo otra vez.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
      <form onSubmit={submit} noValidate className="site-card relative space-y-4 p-6 sm:p-8">
        <Honeypot value={f.website} onChange={(v) => set("website", v)} />
        {message && <p role="alert" className="rounded-xl border-l-4 border-[var(--site-red)] bg-[#E2453C]/8 px-4 py-3 text-sm font-semibold text-[#A3271F]">{message}</p>}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <Field label="Trabajadores" error={err("workers")}>
            <input className="input tabular-nums" inputMode="numeric" value={f.workers} onChange={(e) => set("workers", digits(e.target.value))} />
          </Field>
          <Field label="Sedes" error={err("sites")}>
            <input className="input tabular-nums" inputMode="numeric" value={f.sites} onChange={(e) => set("sites", digits(e.target.value))} />
          </Field>
          <Field label="Registros SST al mes" hint="Capacitaciones, entregas de EPP, inspecciones, charlas… aprox." error={err("records_per_month")}>
            <input className="input tabular-nums" inputMode="numeric" value={f.records_per_month} onChange={(e) => set("records_per_month", digits(e.target.value))} />
          </Field>
          <Field label="Horas al mes en tareas SST manuales" optional hint="Armar reportes, buscar papeles, pasar a Excel…" error={err("hours_per_month")}>
            <input className="input tabular-nums" inputMode="numeric" value={f.hours_per_month} onChange={(e) => set("hours_per_month", digits(e.target.value))} />
          </Field>
        </div>
        <fieldset className="space-y-3 rounded-xl border border-[var(--site-border)] p-4">
          <legend className="px-1 text-sm font-semibold text-[var(--site-navy-title)]">Recibir el resultado por correo (opcional)</legend>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Correo" optional error={err("email")}>
              <input className="input" type="email" maxLength={160} autoComplete="email" value={f.email} onChange={(e) => set("email", e.target.value)} />
            </Field>
            <Field label="Nombre" optional error={err("full_name")}>
              <input className="input" maxLength={120} autoComplete="name" value={f.full_name} onChange={(e) => set("full_name", e.target.value)} />
            </Field>
          </div>
          {f.email.trim() && (
            <>
              <Check checked={privacy} onChange={setPrivacy} error={err("privacy_consent")}>
                Acepto que usen mis datos para enviarme el resultado (ver{" "}
                <Link href="/legal/politica-de-privacidad" target="_blank" className="font-semibold text-[var(--site-action)] underline">
                  Política de privacidad
                </Link>
                ).
              </Check>
              <Check checked={marketing} onChange={setMarketing}>
                Quiero recibir novedades y contenidos de EscudoSST (opcional).
              </Check>
            </>
          )}
        </fieldset>
        <button type="submit" disabled={saving} className="site-btn site-btn-primary w-full disabled:opacity-60">
          {saving ? "Calculando…" : "Calcular"}
        </button>
      </form>

      <aside className="space-y-4">
        <div className={`site-card p-6 ${result ? "border-[#22A35A]/40" : ""}`} aria-live="polite">
          <p className="text-sm font-semibold uppercase tracking-wide text-[var(--site-text-2)]">Estimación</p>
          {result ? (
            <>
              <p className="mt-2 font-display text-4xl font-bold text-[var(--site-navy-title)]">{result.result_hours_month.toLocaleString("es-PE", { maximumFractionDigits: 1 })} h</p>
              <p className="text-[15px] text-[var(--site-text-2)]">que podrías ahorrar al mes</p>
              {result.result_cost_month !== null && (
                <p className="mt-2 text-[15px] text-[var(--site-text)]">
                  Aprox. <b>S/ {result.result_cost_month.toLocaleString("es-PE", { minimumFractionDigits: 2 })}</b> al mes
                </p>
              )}
              {result.emailed && <p className="mt-3 text-sm font-semibold text-[#167A42]">Te enviamos el detalle a tu correo.</p>}
              <Link href="/demo" className="site-btn site-btn-outline mt-4 w-full">
                Ver cómo funciona en una demo
              </Link>
            </>
          ) : (
            <p className="mt-2 text-[15px] text-[var(--site-text-2)]">Completa los datos y presiona «Calcular».</p>
          )}
        </div>
        <div className="site-card space-y-2 p-5 text-sm">
          <p className="font-semibold text-[var(--site-navy-title)]">Supuestos que usamos{calc.to_validate ? " (a validar)" : ""}</p>
          <ul className="space-y-1 text-[var(--site-text-2)]">
            <li>• {a.minutes_saved_per_record} minutos ahorrados por cada registro.</li>
            <li>• {a.manual_hours_reduction_pct} % menos de horas en tareas manuales.</li>
            {a.hourly_cost_pen !== null && <li>• S/ {a.hourly_cost_pen} como costo referencial por hora.</li>}
          </ul>
          <p className="rounded-lg bg-[#F4B90F]/15 px-3 py-2 text-xs text-[#6B5200]">{calc.disclaimer}</p>
        </div>
      </aside>
    </div>
  );
}
