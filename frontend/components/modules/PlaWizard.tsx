"use client";

// Plan Anual SST (M-PLA): SCR-PLA-02 asistente que arma el plan con la línea base aprobada, la política vigente y sus objetivos del
// año, el PASST y el programa de capacitación del año y el presupuesto; SCR-PLA-04 planes del año con su versión, indicadores,
// PDF y «Nueva versión» (RN-PLA-01).

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card, Kpi } from "./Summary";

type Prefill = {
  year: number;
  baselines: { id: string; title: string; assessment_on: string | null; score_pct: number | null }[];
  policy: { id: string; title: string; version_number: number } | null;
  objectives: { id: string; code: string | null; title: string; indicator: string | null; target_value: number | null; unit: string | null; responsible: string | null }[];
  programs: { id: string; title: string; status: string; annual_plan_id: string | null }[];
  training_programs: { id: string; title: string; status: string }[];
  budgets: { id: string; title: string; total_planned: number | null; currency: string | null }[];
  plans: { id: string; title: string; version_number: number; status: string; approved_at: string | null }[];
};
type Ind = { items: number; objectives: { total: number; achieved: number }; passt: { title: string; progress_pct: number | null; status: string } | null;
  training_program: { title: string; status: string } | null; version_number: number };
const STATUS: Record<string, string> = { draft: "Borrador", in_review: "En revisión", approved: "Aprobado", current: "Vigente", closed: "Cerrado" };
const fail = (e: unknown) => { const err = e as ApiError; return err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title); };

export function PlaWizard({ readOnly, onSaved }: { readOnly: boolean; onSaved: () => void }) {
  const [year, setYear] = useState(new Date().getFullYear());
  const [p, setP] = useState<Prefill | null>(null);
  const [f, setF] = useState({ title: "", baseline: "", program: "", training: "", budget: "", summary: "", resources: "" });
  const [objs, setObjs] = useState<Set<string>>(new Set());
  const [ind, setInd] = useState<Record<string, Ind>>({});
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const load = useCallback(() => api<{ data: Prefill }>(`/pla/wizard?year=${year}`, { withCompany: true }).then((r) => {
    setP(r.data);
    setF((x) => ({ ...x, title: x.title || `Plan Anual de Seguridad y Salud en el Trabajo ${year}`, baseline: r.data.baselines[0]?.id ?? "", program: r.data.programs[0]?.id ?? "", training: r.data.training_programs[0]?.id ?? "" }));
    setObjs(new Set(r.data.objectives.map((o) => o.id)));
    r.data.plans.forEach((pl) => api<{ data: Ind }>(`/pla/plans/${pl.id}/indicators`, { withCompany: true }).then((x) => setInd((m) => ({ ...m, [pl.id]: x.data }))).catch(() => undefined));
  }).catch(() => setP(null)), [year]);
  useEffect(() => { void load(); }, [load]);

  const create = async () => {
    setBusy(true);
    setMsg(null);
    try {
      await api("/pla/wizard", { method: "POST", withCompany: true, body: {
        year, title: f.title, baseline_assessment_id: f.baseline || null, policy_id: p?.policy?.id ?? null, annual_program_id: f.program || null, training_program_id: f.training || null,
        budget_id: f.budget || null, objectives_summary: f.summary || null, resources_text: f.resources || null, objective_ids: [...objs] } });
      setMsg({ ok: true, text: "Plan creado en borrador con sus vínculos y un componente por objetivo. Revísalo en la lista y envíalo a revisión." });
      void load();
      onSaved();
    } catch (e) { setMsg({ ok: false, text: fail(e) }); } finally { setBusy(false); }
  };
  const newVersion = async (id: string) => {
    setMsg(null);
    try {
      await api(`/pla/plans/${id}/new-version`, { method: "POST", withCompany: true });
      setMsg({ ok: true, text: "Nueva versión creada en borrador a partir del plan aprobado." });
      void load();
      onSaved();
    } catch (e) { setMsg({ ok: false, text: fail(e) }); }
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3"><span className="text-sm font-semibold">Año</span>
        <input type="number" className="input w-28" aria-label="Año del plan" value={year} onChange={(e) => setYear(Number(e.target.value))} /></div>
      {msg && <p role={msg.ok ? "status" : "alert"} className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
      {p && p.plans.length > 0 && (
        <Card title={`Planes ${year}`}>
          <ul className="space-y-3">
            {p.plans.map((pl) => {
              const i = ind[pl.id];
              return (
                <li key={pl.id} className="rounded-lg border border-line p-3 text-sm">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <span><b>{pl.title}</b> · versión {pl.version_number} · {STATUS[pl.status] ?? pl.status}{pl.approved_at ? ` · aprobado el ${new Date(pl.approved_at).toLocaleDateString("es-PE")}` : ""}</span>
                    <span className="flex gap-2">
                      <a href={`/imprimir/plan-anual/${pl.id}`} target="_blank" rel="noreferrer" className="text-xs font-semibold text-brand-strong underline">PDF</a>
                      {!readOnly && ["approved", "current"].includes(pl.status) && <button type="button" className="text-xs font-semibold text-brand-strong underline" onClick={() => newVersion(pl.id)}>Nueva versión</button>}
                    </span>
                  </div>
                  {i && (
                    <div className="mt-2 grid grid-cols-2 gap-2 lg:grid-cols-4">
                      <Kpi label="Componentes" value={String(i.items)} />
                      <Kpi label="Objetivos logrados" value={`${i.objectives.achieved} de ${i.objectives.total}`} />
                      <Kpi label="Avance del PASST" value={i.passt?.progress_pct == null ? "—" : `${Math.round(i.passt.progress_pct)}%`} sub={i.passt?.title} />
                      <Kpi label="Programa de capacitación" value={i.training_program ? (STATUS[i.training_program.status] ?? i.training_program.status) : "—"} sub={i.training_program?.title} />
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        </Card>
      )}
      {!readOnly && p && (
        <Card title="Armar el plan con el asistente">
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-sm sm:col-span-2"><span className="mb-1 block text-xs font-semibold text-muted">Título</span>
              <input className="input" value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} /></label>
            <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">1. Diagnóstico de línea base (aprobado)</span>
              <select className="input" value={f.baseline} onChange={(e) => setF({ ...f, baseline: e.target.value })}>
                <option value="">—</option>{p.baselines.map((b) => <option key={b.id} value={b.id}>{b.title}{b.score_pct != null ? ` · ${b.score_pct}%` : ""}</option>)}
              </select>{p.baselines.length === 0 && <span className="text-xs text-bad">No hay línea base aprobada.</span>}</label>
            <div className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">2. Política SST vigente</span>
              {p.policy ? <p className="input flex items-center">{p.policy.title} · v{p.policy.version_number}</p> : <p className="text-xs text-bad">No hay política vigente.</p>}</div>
            <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">4. Programa Anual (PASST) {year}</span>
              <select className="input" value={f.program} onChange={(e) => setF({ ...f, program: e.target.value })}>
                <option value="">—</option>{p.programs.map((x) => <option key={x.id} value={x.id}>{x.title} ({STATUS[x.status] ?? x.status})</option>)}
              </select>{p.programs.length === 0 && <span className="text-xs text-bad">No hay PASST de {year}.</span>}</label>
            <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">5. Programa de capacitación {year}</span>
              <select className="input" value={f.training} onChange={(e) => setF({ ...f, training: e.target.value })}>
                <option value="">—</option>{p.training_programs.map((x) => <option key={x.id} value={x.id}>{x.title}</option>)}
              </select></label>
            <label className="text-sm"><span className="mb-1 block text-xs font-semibold text-muted">6. Presupuesto (opcional)</span>
              <select className="input" value={f.budget} onChange={(e) => setF({ ...f, budget: e.target.value })}>
                <option value="">Recursos descritos sin montos</option>{p.budgets.map((x) => <option key={x.id} value={x.id}>{x.title}</option>)}
              </select></label>
            <div className="sm:col-span-2">
              <span className="mb-1 block text-xs font-semibold text-muted">3. Objetivos SST de {year} (cada uno será un componente del plan)</span>
              {p.objectives.length === 0 ? <p className="text-xs text-bad">No hay objetivos del año: créalos en Política y objetivos.</p> : (
                <ul className="space-y-1 text-sm">{p.objectives.map((o) => (
                  <li key={o.id}><label className="flex items-start gap-2"><input type="checkbox" className="mt-1" checked={objs.has(o.id)}
                    onChange={() => setObjs((s) => { const n = new Set(s); if (n.has(o.id)) n.delete(o.id); else n.add(o.id); return n; })} />
                    <span><b>{o.code}</b> {o.title}<span className="block text-xs text-muted">{[o.indicator, o.target_value != null ? `Meta ${o.target_value} ${o.unit ?? ""}` : null, o.responsible].filter(Boolean).join(" · ")}</span></span></label></li>
                ))}</ul>
              )}
            </div>
            <label className="text-sm sm:col-span-2"><span className="mb-1 block text-xs font-semibold text-muted">Resumen de objetivos y alcance</span>
              <textarea className="input min-h-[64px]" value={f.summary} onChange={(e) => setF({ ...f, summary: e.target.value })} /></label>
            <label className="text-sm sm:col-span-2"><span className="mb-1 block text-xs font-semibold text-muted">Recursos (humanos, materiales y financieros)</span>
              <textarea className="input min-h-[64px]" value={f.resources} onChange={(e) => setF({ ...f, resources: e.target.value })} /></label>
          </div>
          <div className="mt-3"><Button variant="primary" loading={busy} disabled={busy || !f.title.trim()} onClick={create}>Crear plan en borrador</Button></div>
          <p className="mt-2 text-xs text-muted">Para enviarlo a revisión o aprobarlo, el plan debe tener la línea base aprobada, la política vigente, al menos un objetivo y el PASST del mismo año.</p>
        </Card>
      )}
    </div>
  );
}
