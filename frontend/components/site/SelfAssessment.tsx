"use client";

// Autodiagnóstico Ley 29783 (SCR-WEB-29): preguntas por sección con barra de avance, datos de contacto y resultado
// con puntaje total, puntaje por sección, nivel y lo que falta trabajar. El informe llega por correo.

import Link from "next/link";
import { FormEvent, useEffect, useMemo, useState } from "react";
import { Alert, Check, Field, Honeypot, Submit } from "@/components/site/PublicForms";
import { Icon } from "@/components/ui/Icon";
import { track } from "@/lib/track";

const API = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8000";

type Question = { id: string; question_text: string; help_text: string | null; answer_type: "yes_no" | "yes_partial_no"; legal_reference: string | null };
type Questionnaire = { id: string; title: string; version: number; description: string | null; to_validate: boolean; sections: { section: string; questions: Question[] }[] };
type Result = { score_total: number; score_by_section: Record<string, number>; level: "low" | "medium" | "high"; level_label: string; pending: Record<string, { question: string; answer: string }[]>; to_validate?: boolean; report_url?: string | null };
type Answer = "yes" | "partial" | "no";

const LEVEL_STYLE = { low: ["bg-[#E2453C]", "Tienes brechas importantes: conviene empezar pronto."], medium: ["bg-[#F4B90F]", "Vas avanzando, pero hay puntos que una inspección observaría."], high: ["bg-[#22A35A]", "Buen nivel. Mantén los registros al día."] } as const;

export function SelfAssessment() {
  const [q, setQ] = useState<Questionnaire | null | undefined>(undefined);
  const [answers, setAnswers] = useState<Record<string, Answer>>({});
  const [step, setStep] = useState(0);
  const [f, setF] = useState({ full_name: "", email: "", company_name: "", website: "" });
  const [privacy, setPrivacy] = useState(false);
  const [marketing, setMarketing] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [alert, setAlert] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<Result | null>(null);

  useEffect(() => {
    fetch(`${API}/api/v1/public/tools/self-assessment`)
      .then((r) => r.json())
      .then((j) => setQ(j.data))
      .catch(() => setQ(null));
  }, []);

  const total = useMemo(() => q?.sections.reduce((a, s) => a + s.questions.length, 0) ?? 0, [q]);
  const done = Object.keys(answers).length;

  if (q === undefined) return <div className="site-card h-96 animate-pulse" />;
  if (q === null)
    return (
      <div className="site-card space-y-4 p-8 text-center">
        <span className="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-[var(--site-surface)] text-[var(--site-action)]">
          <Icon name="clock" size={26} />
        </span>
        <h2 className="font-display text-2xl font-bold text-[var(--site-navy-title)]">Autodiagnóstico en preparación</h2>
        <p className="mx-auto max-w-md text-[var(--site-text-2)]">Estamos terminando el cuestionario con un especialista en SST. Mientras tanto, en una demo te mostramos cómo está tu empresa frente a la Ley 29783.</p>
        <Link href="/demo" className="site-btn site-btn-primary">
          Agenda una demo <Icon name="chevron" size={16} />
        </Link>
      </div>
    );

  if (result) return <ResultView r={result} />;

  const sections = q.sections;
  const isContact = step === sections.length;
  const section = sections[step];
  const sectionDone = section ? section.questions.every((x) => answers[x.id]) : true;

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (!q) return;
    setSaving(true);
    setErrors({});
    setAlert(null);
    try {
      const r = await fetch(`${API}/api/v1/public/tools/self-assessment`, {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json" },
        body: JSON.stringify({ questionnaire_id: q.id, answers, ...f, privacy_consent: privacy, marketing_consent: marketing }),
      });
      const j = await r.json().catch(() => ({}));
      if (r.ok) {
        track("assessment_completed", "autodiagnostico");
        setResult(j.data);
        window.scrollTo({ top: 0, behavior: "smooth" });
      } else if (r.status === 422) {
        setErrors(j.errors ?? {});
        setAlert(j.errors?.answers?.[0] ?? "Revisa los campos marcados.");
      } else if (r.status === 429) setAlert("Hiciste muchos intentos. Espera unos minutos.");
      else setAlert(j.detail ?? "No se pudo enviar.");
    } catch {
      setAlert("No pudimos conectar. Revisa tu internet.");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="space-y-5">
      {q.to_validate && (
        <p className="rounded-xl border border-[#F4B90F]/40 bg-[#FFF8E1] px-4 py-3 text-sm text-[#5C4500]">
          <b>Cuestionario en validación por especialista SST.</b> El resultado es referencial; no es una auditoría ni asesoría legal.
        </p>
      )}
      <div className="site-card p-5">
        <div className="mb-2 flex items-center justify-between text-sm">
          <b className="text-[var(--site-navy-title)]">{isContact ? "Último paso: tus datos" : `Sección ${step + 1} de ${sections.length}: ${section.section}`}</b>
          <span className="tabular-nums text-[var(--site-text-2)]">
            {done} de {total} respondidas
          </span>
        </div>
        <div className="h-2 overflow-hidden rounded-full bg-[var(--site-surface)]" role="progressbar" aria-valuemin={0} aria-valuemax={total} aria-valuenow={done}>
          <div className="h-full rounded-full bg-[var(--site-action)] transition-all" style={{ width: `${total ? (done / total) * 100 : 0}%` }} />
        </div>
      </div>

      {!isContact ? (
        <div className="site-card space-y-5 p-6 sm:p-8">
          {section.questions.map((x, i) => (
            <div key={x.id} className="space-y-2.5 border-b border-[var(--site-border)] pb-5 last:border-0 last:pb-0">
              <p className="font-semibold text-[var(--site-navy-title)]">
                {i + 1}. {x.question_text}
              </p>
              {x.help_text && <p className="text-sm text-[var(--site-text-2)]">{x.help_text}</p>}
              <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={x.question_text}>
                {(
                  [
                    ["yes", "Sí"],
                    ...(x.answer_type === "yes_partial_no" ? [["partial", "En parte"]] : []),
                    ["no", "No"],
                  ] as [Answer, string][]
                ).map(([v, l]) => (
                  <button
                    key={v}
                    type="button"
                    role="radio"
                    aria-checked={answers[x.id] === v}
                    onClick={() => setAnswers({ ...answers, [x.id]: v })}
                    className={`min-w-24 rounded-full border px-5 py-2 text-sm font-bold transition-colors ${answers[x.id] === v ? (v === "yes" ? "border-[#22A35A] bg-[#22A35A] text-white" : v === "partial" ? "border-[#F4B90F] bg-[#F4B90F] text-[#3d2d00]" : "border-[#E2453C] bg-[#E2453C] text-white") : "border-[var(--site-border)] bg-white text-[var(--site-text)] hover:border-[var(--site-action)]"}`}
                  >
                    {l}
                  </button>
                ))}
              </div>
              {x.legal_reference && <p className="text-xs text-[var(--site-text-2)]">Referencia: {x.legal_reference} (a validar)</p>}
            </div>
          ))}
          <div className="flex justify-between gap-3 pt-2">
            <button type="button" disabled={step === 0} onClick={() => setStep(step - 1)} className="site-btn site-btn-outline !py-2.5 disabled:opacity-40">
              Anterior
            </button>
            <button type="button" disabled={!sectionDone} onClick={() => (setStep(step + 1), window.scrollTo({ top: 0, behavior: "smooth" }))} className="site-btn site-btn-primary !py-2.5 disabled:opacity-50">
              {step === sections.length - 1 ? "Ver mi resultado" : "Siguiente"} <Icon name="chevron" size={16} />
            </button>
          </div>
        </div>
      ) : (
        <form onSubmit={submit} className="site-card relative space-y-4 p-6 sm:p-8" noValidate>
          <Honeypot value={f.website} onChange={(v) => setF({ ...f, website: v })} />
          <p className="text-[var(--site-text-2)]">Te mostramos el resultado aquí y te enviamos el informe a tu correo.</p>
          <Field label="Nombre y apellido" error={errors.full_name?.[0]}>
            <input className="input" maxLength={120} autoComplete="name" value={f.full_name} onChange={(e) => setF({ ...f, full_name: e.target.value })} />
          </Field>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Correo de trabajo" error={errors.email?.[0]}>
              <input className="input" type="email" maxLength={160} autoComplete="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} />
            </Field>
            <Field label="Empresa" error={errors.company_name?.[0]}>
              <input className="input" maxLength={160} autoComplete="organization" value={f.company_name} onChange={(e) => setF({ ...f, company_name: e.target.value })} />
            </Field>
          </div>
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
          <Alert text={alert} />
          <div className="flex gap-3">
            <button type="button" onClick={() => setStep(step - 1)} className="site-btn site-btn-outline !py-2.5">
              Anterior
            </button>
            <div className="flex-1">
              <Submit saving={saving}>Ver mi resultado</Submit>
            </div>
          </div>
        </form>
      )}
    </div>
  );
}

function ResultView({ r }: { r: Result }) {
  const [color, msg] = LEVEL_STYLE[r.level];
  return (
    <div className="space-y-5">
      <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[#0D3B6E] to-[#1b6fb7] p-6 text-white shadow-lg sm:p-8">
        <p className="text-sm text-white/75">Tu nivel de cumplimiento</p>
        <div className="mt-1 flex flex-wrap items-end gap-4">
          <span className="font-display text-[56px] font-bold leading-none">{Math.round(r.score_total)}%</span>
          <span className={`mb-2 rounded-full px-3 py-1 text-sm font-bold ${color} ${r.level === "medium" ? "text-[#3d2d00]" : "text-white"}`}>Nivel {r.level_label.toLowerCase()}</span>
        </div>
        <p className="mt-2 text-white/85">{msg}</p>
        <div className="mt-4 flex flex-wrap items-center gap-3">
          {r.report_url && (
            <a href={r.report_url} className="site-btn site-btn-light !py-2.5">
              <Icon name="file" size={16} /> Descargar mi informe (PDF)
            </a>
          )}
          <span className="text-sm text-white/75">También te lo enviamos a tu correo.</span>
        </div>
      </div>

      <div className="site-card space-y-3 p-6">
        <h2 className="font-display text-lg font-bold text-[var(--site-navy-title)]">Por sección</h2>
        {Object.entries(r.score_by_section).map(([s, pct]) => (
          <div key={s}>
            <div className="mb-1 flex justify-between text-sm">
              <span className="text-[var(--site-text)]">{s}</span>
              <b className="tabular-nums">{Math.round(Number(pct))}%</b>
            </div>
            <div className="h-2.5 overflow-hidden rounded-full bg-[var(--site-surface)]">
              <div className={`h-full rounded-full ${Number(pct) >= 70 ? "bg-[#22A35A]" : Number(pct) >= 40 ? "bg-[#F4B90F]" : "bg-[#E2453C]"}`} style={{ width: `${pct}%` }} />
            </div>
          </div>
        ))}
      </div>

      {Object.keys(r.pending).length > 0 && (
        <div className="site-card space-y-3 p-6">
          <h2 className="font-display text-lg font-bold text-[var(--site-navy-title)]">Lo que te falta trabajar</h2>
          {Object.entries(r.pending).map(([s, items]) => (
            <div key={s}>
              <p className="text-sm font-bold text-[var(--site-action)]">{s}</p>
              <ul className="mt-1 space-y-1">
                {items.map((it) => (
                  <li key={it.question} className="flex gap-2 text-sm text-[var(--site-text)]">
                    <Icon name={it.answer === "partial" ? "clock" : "close"} size={15} className={`mt-0.5 shrink-0 ${it.answer === "partial" ? "text-[#B98700]" : "text-[#E2453C]"}`} />
                    {it.question}
                    {it.answer === "partial" && <span className="text-[var(--site-text-2)]"> (en parte)</span>}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}

      <div className="site-card flex flex-col items-center gap-3 p-6 text-center sm:flex-row sm:text-left">
        <p className="flex-1 text-[var(--site-text)]">EscudoSST te ayuda a cerrar estas brechas: IPERC, capacitaciones, registros y más, en un solo lugar.</p>
        <Link href="/demo" className="site-btn site-btn-primary">
          Agenda una demo <Icon name="chevron" size={16} />
        </Link>
      </div>
      <p className="text-center text-xs text-[var(--site-text-2)]">Resultado referencial{r.to_validate ? "; cuestionario en validación por especialista SST" : ""}. No es una auditoría ni asesoría legal.</p>
    </div>
  );
}
