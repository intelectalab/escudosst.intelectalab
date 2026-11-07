"use client";

// Calculadora de multas SUNAFIL (SCR-WEB-28): tamaño de empresa, trabajadores afectados y gravedad → rango referencial
// en UIT y en soles con la UIT vigente. Los montos salen de los parámetros legales del panel (no del código).
// Con correo, la persona recibe el detalle y entra a «Interesados».

import Link from "next/link";
import { FormEvent, useEffect, useState } from "react";
import { Alert, Check, Field, Honeypot, Submit } from "@/components/site/PublicForms";
import { Icon } from "@/components/ui/Icon";
import { track } from "@/lib/track";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

type Params = { available: boolean; to_confirm: boolean; uit_value: number | null; uit_valid_from: string | null; disclaimer: string };
type Result = {
  uit_value: number;
  result_min_uit: number;
  result_max_uit: number;
  result_min_pen: number;
  result_max_pen: number;
  with_correction_discount?: { pct: number; min_pen: number; max_pen: number } | null;
  lead_registered?: boolean;
  disclaimer?: string;
};

const SIZES = [
  ["micro", "Microempresa", "Registrada en REMYPE como micro"],
  ["small", "Pequeña empresa", "Registrada en REMYPE como pequeña"],
  ["non_mype", "Mediana o grande", "No está en el régimen MYPE"],
] as const;

// Ejemplos orientativos (a validar con un especialista); la clasificación exacta la define la norma.
const SEVERITIES = [
  ["minor", "Leve", "Faltas formales o de documentación que no afectan directamente a los trabajadores."],
  ["serious", "Grave", "No tener elementos del sistema de gestión: por ejemplo, no evaluar los riesgos (IPERC) o no capacitar."],
  ["very_serious", "Muy grave", "Incumplimientos que ponen en riesgo grave la vida o la salud de los trabajadores."],
] as const;

const pen = (n: number) => `S/ ${Number(n).toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const uit = (n: number) => Number(n).toLocaleString("es-PE", { maximumFractionDigits: 2 });

export function FineCalculator() {
  const [p, setP] = useState<Params | null>(null);
  const [f, setF] = useState({ company_size: "micro", affected_workers: "10", severity: "serious" });
  const [result, setResult] = useState<Result | null>(null);
  const [alert, setAlert] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    fetch(`${API}/api/v1/public/tools/fine-calculator`)
      .then((r) => r.json())
      .then((j) => setP(j.data))
      .catch(() => setP({ available: false, to_confirm: true, uit_value: null, uit_valid_from: null, disclaimer: "" }));
  }, []);

  async function calc(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setAlert(null);
    setResult(null);
    try {
      const r = await fetch(`${API}/api/v1/public/tools/fine-calculator`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json" },
        body: JSON.stringify({ ...f, affected_workers: Number(f.affected_workers) }),
      });
      const j = await r.json().catch(() => ({}));
      if (r.ok) {
        setResult(j.data);
        track("calculator_used", "multas");
      }
      else setAlert(j.errors ? (Object.values(j.errors)[0] as string[])[0] : (j.detail ?? "No se pudo calcular."));
    } catch {
      setAlert("No pudimos conectar. Revisa tu internet.");
    } finally {
      setSaving(false);
    }
  }

  if (p === null) return <div className="site-card h-96 animate-pulse" />;
  if (!p.available)
    return (
      <div className="site-card space-y-4 p-8 text-center">
        <span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-[var(--site-surface)] text-[var(--site-action)]">
          <Icon name="clock" size={26} />
        </span>
        <h2 className="font-display text-2xl font-bold text-[var(--site-navy-title)]">Calculadora en preparación</h2>
        <p className="mx-auto max-w-md text-[var(--site-text-2)]">Estamos cargando las escalas oficiales de multas para darte un resultado confiable. Mientras tanto, te mostramos en una demo cómo evitar las multas más comunes.</p>
        <Link href="/demo" className="site-btn site-btn-primary">
          Agenda una demo <Icon name="chevron" size={16} />
        </Link>
      </div>
    );

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
      <form onSubmit={calc} className="site-card space-y-6 p-6 sm:p-8" noValidate>
        <fieldset className="space-y-3">
          <legend className="mb-1 font-display text-lg font-bold text-[var(--site-navy-title)]">1. Tamaño de tu empresa</legend>
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
            {SIZES.map(([v, t, d]) => (
              <Option key={v} on={f.company_size === v} onClick={() => setF({ ...f, company_size: v })} title={t} detail={d} />
            ))}
          </div>
        </fieldset>
        <Field label="2. ¿Cuántos trabajadores se verían afectados?" hint="Los que están expuestos a la falta (por ejemplo, todos los de la obra).">
          <input className="input tabular-nums sm:w-40" inputMode="numeric" value={f.affected_workers} onChange={(e) => setF({ ...f, affected_workers: e.target.value.replace(/\D/g, "").slice(0, 6) })} />
        </Field>
        <fieldset className="space-y-3">
          <legend className="mb-1 font-display text-lg font-bold text-[var(--site-navy-title)]">3. Tipo de falta</legend>
          <div className="grid grid-cols-1 gap-2">
            {SEVERITIES.map(([v, t, d]) => (
              <Option key={v} on={f.severity === v} onClick={() => setF({ ...f, severity: v })} title={t} detail={d} />
            ))}
          </div>
          <p className="text-xs text-[var(--site-text-2)]">Ejemplos orientativos. La clasificación exacta la define la norma (D.S. 019-2006-TR).</p>
        </fieldset>
        <Alert text={alert} />
        <Submit saving={saving}>Calcular</Submit>
      </form>

      <div className="space-y-4">
        {result ? (
          <ResultCard r={result} p={p} input={f} />
        ) : (
          <div className="site-card space-y-3 p-6 text-[var(--site-text-2)]">
            <Icon name="shield" size={28} className="text-[var(--site-action)]" />
            <p className="font-display text-lg font-bold text-[var(--site-navy-title)]">¿Cuánto podría costarte no cumplir?</p>
            <p className="text-sm">Elige tu caso y te mostramos el rango de la multa en UIT y en soles.</p>
            {p.uit_value && (
              <p className="text-xs">
                UIT usada: <b>{pen(p.uit_value)}</b>
                {p.uit_valid_from && ` (vigente desde ${new Date(p.uit_valid_from + "T12:00").toLocaleDateString("es-PE")})`}
              </p>
            )}
          </div>
        )}
        <p className="rounded-xl border border-[#F4B90F]/40 bg-[#FFF8E1] px-4 py-3 text-xs text-[#5C4500]">
          {p.to_confirm && <b>Valores a confirmar. </b>}Resultado referencial; no es asesoría legal. La multa real depende de lo que determine SUNAFIL en cada caso.
        </p>
      </div>
    </div>
  );
}

function Option({ on, onClick, title, detail }: { on: boolean; onClick: () => void; title: string; detail: string }) {
  return (
    <button type="button" aria-pressed={on} onClick={onClick} className={`rounded-xl border p-3.5 text-left transition-colors ${on ? "border-[var(--site-action)] bg-[var(--site-surface)] ring-1 ring-[var(--site-action)]" : "border-[var(--site-border)] bg-white hover:border-[var(--site-action)]"}`}>
      <b className="block text-[var(--site-navy-title)]">{title}</b>
      <span className="text-xs text-[var(--site-text-2)]">{detail}</span>
    </button>
  );
}

function ResultCard({ r, p, input }: { r: Result; p: Params; input: { company_size: string; affected_workers: string; severity: string } }) {
  const [f, setF] = useState({ full_name: "", email: "", company_name: "", website: "" });
  const [privacy, setPrivacy] = useState(false);
  const [marketing, setMarketing] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [sent, setSent] = useState(false);
  const [saving, setSaving] = useState(false);

  async function send(e: FormEvent) {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    const res = await fetch(`${API}/api/v1/public/tools/fine-calculator`, {
      method: "POST",
      headers: { Accept: "application/json", "Content-Type": "application/json" },
      body: JSON.stringify({ ...input, affected_workers: Number(input.affected_workers), ...f, privacy_consent: privacy, marketing_consent: marketing }),
    }).catch(() => null);
    const j = res ? await res.json().catch(() => ({})) : {};
    if (res?.ok) {
      setSent(true);
      track("form_submit", "calculadora-correo");
    }
    else setErrors(j.errors ?? { email: ["No se pudo enviar. Prueba de nuevo."] });
    setSaving(false);
  }

  return (
    <>
      <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#0D3B6E] to-[#1F7FD1] p-6 text-white shadow-lg">
        <p className="text-sm text-white/75">Multa referencial</p>
        <p className="mt-1 font-display text-[28px] font-bold leading-tight sm:text-[32px]">
          {pen(r.result_min_pen)} <span className="text-white/70">a</span> {pen(r.result_max_pen)}
        </p>
        <p className="mt-1 text-sm text-white/80">
          {uit(r.result_min_uit)} a {uit(r.result_max_uit)} UIT · UIT = {pen(r.uit_value)}
        </p>
        {r.with_correction_discount && (
          <p className="mt-4 rounded-xl bg-white/12 px-4 py-3 text-sm">
            Si subsanas a tiempo (−{r.with_correction_discount.pct} %): <b>{pen(r.with_correction_discount.min_pen)}</b> a <b>{pen(r.with_correction_discount.max_pen)}</b>
          </p>
        )}
        <Link href="/demo" className="site-btn site-btn-light mt-5 !py-2.5">
          Ver cómo evitarlas con EscudoSST <Icon name="chevron" size={16} />
        </Link>
      </div>
      <div className="site-card p-5">
        {sent ? (
          <p className="flex items-center gap-2 text-sm text-[var(--site-text)]">
            <Icon name="check" size={18} className="text-[#22A35A]" /> Te enviamos el detalle a <b>{f.email}</b>.
          </p>
        ) : (
          <form onSubmit={send} className="relative space-y-3" noValidate>
            <Honeypot value={f.website} onChange={(v) => setF({ ...f, website: v })} />
            <p className="font-display font-bold text-[var(--site-navy-title)]">Recibe el detalle por correo</p>
            <Field label="Nombre" error={errors.full_name?.[0]}>
              <input className="input" maxLength={120} autoComplete="name" value={f.full_name} onChange={(e) => setF({ ...f, full_name: e.target.value })} />
            </Field>
            <Field label="Correo" error={errors.email?.[0]}>
              <input className="input" type="email" maxLength={160} autoComplete="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} />
            </Field>
            <Field label="Empresa" optional>
              <input className="input" maxLength={160} value={f.company_name} onChange={(e) => setF({ ...f, company_name: e.target.value })} />
            </Field>
            <Check checked={privacy} onChange={setPrivacy} error={errors.privacy_consent?.[0]}>
              Leí la{" "}
              <Link href="/legal/politica-de-privacidad" target="_blank" className="font-semibold text-[var(--site-action)] underline">
                Política de privacidad
              </Link>
              .
            </Check>
            <Check checked={marketing} onChange={setMarketing}>
              Quiero recibir novedades de SST (opcional).
            </Check>
            <Submit saving={saving}>Enviarme el detalle</Submit>
          </form>
        )}
      </div>
      {p.disclaimer && <span className="sr-only">{p.disclaimer}</span>}
    </>
  );
}
