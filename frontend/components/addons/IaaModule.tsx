"use client";

// Asistente IA (complemento IAA): pestañas «IPERC sugerido», «Investigación guiada», «Consultas rápidas» (controles,
// causas y guion de charla de 5 minutos), «Historial» y «Consumo» (cupo del mes, por caso de uso y aceptación; sin precios). Son propuestas: la persona revisa y decide qué usar.
// Responde con la biblioteca que entrena el equipo de EscudoSST.

import { useEffect, useState } from "react";
import { IaaHistory, InvestigationGuide, IpercSuggest } from "@/components/addons/IaaGuided";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { api, ApiError } from "@/lib/api";

type Item = { title: string; detail?: string; group?: string };
const FEATURES: [string, string, string, string][] = [
  ["controls", "Controles para un peligro", "Describe el peligro o la tarea", "Ej.: trabajo en altura sobre andamio para pintar fachada"],
  ["causes", "Guía para investigar un accidente", "Cuenta brevemente qué pasó", "Ej.: trabajador se cortó la mano al cambiar el disco de la amoladora"],
  ["talk", "Guion de charla de 5 minutos", "Tema de la charla", "Ej.: orden y limpieza en el almacén"],
];

const TABS: [string, string][] = [["iperc", "IPERC sugerido"], ["inv", "Investigación guiada"], ["quick", "Consultas rápidas"], ["history", "Historial"], ["usage", "Consumo"]];

export function IaaModule() {
  const [tab, setTab] = useState("iperc");
  const [preset, setPreset] = useState<{ investigation?: string; position?: string }>({});
  useEffect(() => {
    const q = new URLSearchParams(window.location.search);
    const t = q.get("t");
    if (t && TABS.some((x) => x[0] === t)) setTab(t);
    setPreset({ investigation: q.get("investigacion") ?? undefined, position: q.get("puesto") ?? undefined });
  }, []);
  const go = (t: string) => {
    setTab(t);
    const url = new URL(window.location.href);
    url.searchParams.set("t", t);
    window.history.replaceState(null, "", url);
  };
  return (
    <div className="space-y-4">
      <div role="tablist" aria-label="Asistente" className="flex gap-1 overflow-x-auto rounded-xl border border-line bg-surface p-1">
        {TABS.map(([t, l]) => (
          <button key={t} role="tab" aria-selected={tab === t} onClick={() => go(t)}
            className={`whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold ${tab === t ? "bg-brand text-white shadow-sm" : "text-muted hover:bg-bg hover:text-fg"}`}>
            {l}
          </button>
        ))}
      </div>
      {tab === "iperc" && <IpercSuggest key={preset.position ?? "-"} positionId={preset.position} />}
      {tab === "inv" && <InvestigationGuide key={preset.investigation ?? "-"} investigationId={preset.investigation} />}
      {tab === "quick" && <QuickAssistant />}
      {tab === "history" && <IaaHistory />}
      {tab === "usage" && <IaaUsage />}
    </div>
  );
}

function QuickAssistant() {
  const [feature, setFeature] = useState("controls");
  const [text, setText] = useState("");
  const [res, setRes] = useState<{ topic: string | null; items: Item[]; note: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const f = FEATURES.find((x) => x[0] === feature)!;

  async function ask() {
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ data: { topic: string | null; items: Item[]; note: string } }>("/iaa/suggest", { method: "POST", withCompany: true, body: { feature, text } });
      setRes(r.data);
    } catch (e) {
      const ae = e as ApiError;
      setError(Object.values(ae.errors ?? {})[0]?.[0] ?? ae.detail ?? ae.title);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-4">
      <section className="space-y-4 rounded-2xl border border-line bg-surface p-5 shadow-sm">
        <div className="flex flex-wrap gap-2" role="tablist">
          {FEATURES.map(([k, label]) => (
            <button key={k} role="tab" aria-selected={feature === k} onClick={() => { setFeature(k); setRes(null); }}
              className={`rounded-full px-4 py-2 text-sm font-semibold ${feature === k ? "bg-brand text-white" : "border border-line hover:border-brand"}`}>
              {label}
            </button>
          ))}
        </div>
        <FormField label={f[2]} error={error ?? undefined}>
          <textarea className="input min-h-[90px]" maxLength={1500} value={text} placeholder={f[3]} onChange={(e) => setText(e.target.value)} />
        </FormField>
        <p className="text-xs text-muted">No escribas datos de salud ni nombres completos: el asistente no los necesita.</p>
        <Button variant="primary" icon="sparkle" loading={busy} onClick={ask} disabled={text.trim().length < 5}>Sugerir</Button>
      </section>
      {res && (
        <section className="space-y-3 rounded-2xl border border-line bg-surface p-5 shadow-sm">
          <p className={`text-sm ${res.topic ? "text-ok" : "text-amber-ink"}`}>{res.note}</p>
          <ul className="space-y-2">
            {res.items.map((it, i) => (
              <li key={i} className="rounded-xl bg-bg px-4 py-3 text-sm">
                {it.group && <span className="mb-1 block text-xs font-semibold uppercase tracking-wide text-brand">{it.group}</span>}
                <b>{it.title}</b>
                {it.detail && <span className="block text-muted">{it.detail}</span>}
              </li>
            ))}
          </ul>
          <p className="text-xs text-muted">Revisa y adapta antes de usarlas en tu IPERC, investigación o charla.</p>
        </section>
      )}
    </div>
  );
}

type Usage = {
  used: number; limit: number | null; months: { month: string; feature: string; n: number }[];
  people: { name: string; n: number; accepted: number }[];
  suggestions: { accepted: number; rejected: number; pending: number; acceptance_pct: number | null };
};
const FEATURE_NAME: Record<string, string> = { iperc_suggestion: "IPERC y controles", investigation_guide: "Investigación", document_reading: "Lectura de documentos", training_quiz: "Preguntas de examen", report_classification: "Clasificación de reportes", other: "Charlas y otros" };

/** SCR-IAA-06: consumo del mes contra el cupo del plan, por mes y caso de uso, por persona y tasa de aceptación. */
function IaaUsage() {
  const [u, setU] = useState<Usage | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: Usage }>("/iaa/usage", { withCompany: true }).then((r) => setU(r.data)).catch((e) => setError(e instanceof ApiError ? e.detail ?? e.title : "No se pudo cargar el consumo."));
  }, []);
  if (error) return <p className="rounded-xl bg-bad-soft p-4 text-sm text-bad">{error}</p>;
  if (!u) return <div className="h-32 animate-pulse rounded-xl border border-line bg-surface" />;
  const months = [...new Set(u.months.map((m) => m.month))];
  const features = [...new Set(u.months.map((m) => m.feature))];
  const pct = u.limit ? Math.min(100, Math.round((100 * u.used) / u.limit)) : null;
  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-3">
        <div className="rounded-xl border border-line bg-surface p-4">
          <p className="text-sm text-muted">Solicitudes este mes</p>
          <p className="text-2xl font-bold">{u.used}{u.limit !== null && <span className="text-base font-semibold text-muted"> de {u.limit}</span>}</p>
          {pct !== null ? <div className="mt-2 h-2 rounded bg-bg"><div className={`h-2 rounded ${pct >= 90 ? "bg-bad" : "bg-brand"}`} style={{ width: `${pct}%` }} /></div> : <p className="text-xs text-muted">Tu plan no tiene límite mensual.</p>}
        </div>
        <div className="rounded-xl border border-line bg-surface p-4">
          <p className="text-sm text-muted">Propuestas aceptadas (6 meses)</p>
          <p className="text-2xl font-bold">{u.suggestions.acceptance_pct === null ? "—" : `${u.suggestions.acceptance_pct} %`}</p>
          <p className="text-xs text-muted">{u.suggestions.accepted} aceptadas · {u.suggestions.rejected} descartadas</p>
        </div>
        <div className="rounded-xl border border-line bg-surface p-4">
          <p className="text-sm text-muted">Propuestas por revisar</p>
          <p className="text-2xl font-bold">{u.suggestions.pending}</p>
        </div>
      </div>
      <div className="overflow-x-auto rounded-xl border border-line bg-surface">
        <table className="w-full text-sm">
          <thead><tr className="border-b border-line text-left text-muted"><th className="p-3">Mes</th>{features.map((f) => <th key={f} className="p-3">{FEATURE_NAME[f] ?? f}</th>)}<th className="p-3">Total</th></tr></thead>
          <tbody>
            {months.length === 0 ? <tr><td className="p-3 text-muted" colSpan={features.length + 2}>Aún no hay solicitudes.</td></tr> : months.map((m) => {
              const row = u.months.filter((x) => x.month === m);
              return <tr key={m} className="border-b border-line last:border-0"><td className="p-3 font-semibold">{m}</td>{features.map((f) => <td key={f} className="p-3">{row.find((x) => x.feature === f)?.n ?? 0}</td>)}<td className="p-3 font-semibold">{row.reduce((a, x) => a + x.n, 0)}</td></tr>;
            })}
          </tbody>
        </table>
      </div>
      {u.people.length > 0 && (
        <div className="overflow-x-auto rounded-xl border border-line bg-surface">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-line text-left text-muted"><th className="p-3">Persona</th><th className="p-3">Solicitudes</th><th className="p-3">Con propuesta aceptada</th></tr></thead>
            <tbody>{u.people.map((p) => <tr key={p.name} className="border-b border-line last:border-0"><td className="p-3">{p.name}</td><td className="p-3">{p.n}</td><td className="p-3">{p.accepted}</td></tr>)}</tbody>
          </table>
        </div>
      )}
    </div>
  );
}
