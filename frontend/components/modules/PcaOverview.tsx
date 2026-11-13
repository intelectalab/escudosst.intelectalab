"use client";

// Programa de capacitación (M-PCA): SCR-PCA-01 vista anual temas × meses con su estado (ejecutado, programado, pendiente o
// no ejecutado) y el cumplimiento de cada tema (RN-PCA-03), y SCR-PCA-03 matriz puesto × tema con los trabajadores del puesto.

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Card, Kpi } from "./Summary";

type Item = { id: string; topic: string; is_mandatory_legal: boolean; position_id: string | null; position: string | null; planned_month: number | null; original_month: number | null;
  reschedule_reason: string | null; training: string | null; state: "executed" | "scheduled" | "pending" | "not_executed"; target: number; done: number; pct: number | null };
type Data = {
  programs: { id: string; year: number; title: string; status: string }[];
  program: { id: string; year: number; title: string; status: string; pct: number | null } | null;
  items: Item[];
  positions: { id: string; name: string; workers: number }[];
};
const MONTHS = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Set", "Oct", "Nov", "Dic"];
const STATE: Record<Item["state"], { label: string; cls: string; short: string }> = {
  executed: { label: "Ejecutado", cls: "bg-ok-soft text-ok", short: "E" },
  scheduled: { label: "Programado", cls: "bg-brand-soft text-brand-strong", short: "P" },
  pending: { label: "Pendiente", cls: "bg-amber-soft text-amber-ink", short: "·" },
  not_executed: { label: "No ejecutado", cls: "bg-bad-soft text-bad", short: "X" },
};
const STATUS: Record<string, string> = { draft: "Borrador", committee_review: "Revisión del comité", approved: "Aprobado", in_execution: "En ejecución", closed: "Cerrado" };

export function PcaOverview() {
  const [programId, setProgramId] = useState("");
  const [d, setD] = useState<Data | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: Data }>(`/pca/overview${programId ? `?program_id=${programId}` : ""}`, { withCompany: true }).then((r) => setD(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [programId]);

  if (error) return <p className="rounded-xl border border-line bg-surface p-4 text-sm text-muted">{error}</p>;
  if (!d) return <div className="h-28 animate-pulse rounded-xl bg-surface" />;
  if (!d.program) return <p className="rounded-xl border border-line bg-surface p-4 text-sm text-muted">Aún no hay programa de capacitación. Créalo en la pestaña «Programas».</p>;
  const p = d.program;
  const count = (s: Item["state"]) => d.items.filter((i) => i.state === s).length;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <select className="input max-w-sm" aria-label="Programa" value={p.id} onChange={(e) => setProgramId(e.target.value)}>
          {d.programs.map((x) => <option key={x.id} value={x.id}>{x.year} · {x.title} ({STATUS[x.status] ?? x.status})</option>)}
        </select>
      </div>
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Kpi label="Cumplimiento del programa" value={p.pct == null ? "—" : `${p.pct}%`} sub="Trabajadores objetivo con asistencia" />
        <Kpi label="Temas ejecutados" value={`${count("executed")} de ${d.items.length}`} />
        <Kpi label="Temas programados" value={String(count("scheduled") + count("pending"))} />
        <Kpi label="No ejecutados" value={String(count("not_executed"))} color="text-bad" sub="Mes cerrado sin capacitación" />
      </div>

      <Card title={`Programa ${p.year}: temas por mes`}>
        <div className="mb-3 flex flex-wrap gap-2 text-xs">
          {(Object.keys(STATE) as Item["state"][]).map((k) => <span key={k} className={`rounded-full px-2 py-0.5 font-semibold ${STATE[k].cls}`}>{STATE[k].short} {STATE[k].label}</span>)}
        </div>
        <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Temas por mes">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line text-xs font-semibold text-muted">
              <tr><th className="min-w-[220px] px-3 py-2">Tema</th>{MONTHS.map((m) => <th key={m} className="px-1.5 py-2 text-center">{m}</th>)}<th className="min-w-[120px] px-3 py-2">Cumplimiento</th></tr>
            </thead>
            <tbody>
              {d.items.map((i) => (
                <tr key={i.id} className="border-b border-line last:border-0">
                  <td className="px-3 py-2">
                    <b>{i.topic}</b>{i.is_mandatory_legal ? <span className="ml-1 text-xs text-bad">(obligatorio)</span> : null}
                    <span className="block text-xs text-muted">{[i.position ?? "Toda la empresa", i.training].filter(Boolean).join(" · ")}</span>
                    {i.reschedule_reason && <span className="block text-xs text-amber-ink">Reprogramado desde {MONTHS[(i.original_month ?? 1) - 1]}: {i.reschedule_reason}</span>}
                  </td>
                  {MONTHS.map((_, k) => (
                    <td key={k} className="px-1.5 py-2 text-center">
                      {i.planned_month === k + 1 ? <span className={`inline-block min-w-[1.6rem] rounded px-1 py-0.5 text-xs font-bold ${STATE[i.state].cls}`} title={STATE[i.state].label}>{STATE[i.state].short}</span> : null}
                    </td>
                  ))}
                  <td className="px-3 py-2 text-xs">{i.pct == null ? "—" : <><b>{i.pct}%</b> <span className="text-muted">({i.done}/{i.target})</span></>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Card>

      {d.positions.length > 0 && (
        <Card title="Puesto × tema">
          <div className="overflow-x-auto" tabIndex={0} role="region" aria-label="Matriz puesto por tema">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-line text-xs font-semibold text-muted">
                <tr><th className="min-w-[180px] px-3 py-2">Puesto</th>{d.items.filter((i) => i.position_id).map((i) => <th key={i.id} className="min-w-[110px] px-3 py-2"><span className="line-clamp-2">{i.topic}</span></th>)}</tr>
              </thead>
              <tbody>
                {d.positions.map((ps) => (
                  <tr key={ps.id} className="border-b border-line last:border-0">
                    <td className="px-3 py-2"><b>{ps.name}</b><span className="block text-xs text-muted">{ps.workers} trabajador(es)</span></td>
                    {d.items.filter((i) => i.position_id).map((i) => (
                      <td key={i.id} className="px-3 py-2 text-xs">{i.position_id === ps.id ? <span className={`rounded px-2 py-1 font-semibold ${STATE[i.state].cls}`}>{i.pct == null ? STATE[i.state].label : `${i.pct}%`}</span> : <span className="text-muted">—</span>}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  );
}
