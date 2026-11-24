"use client";

// Presupuesto SST (M-PRE SCR-PRE-01): total aprobado, ejecutado y % de ejecución por categoría, sede y actividad, con su Excel y su PDF (§11);
// y la reasignación entre líneas de un presupuesto aprobado, que pide el Jefe SST y aprueba o rechaza la gerencia (RN-PRE-05).

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { downloadFile } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Card, Kpi } from "./Summary";
import { cell, type Company, Facts, ReportLoading, Section, Sheet, useReport } from "./FieldReports";

type Group = { name: string; planned: number; executed: number; pct: number | null };
type Line = { id: string; category: string; description: string; planned_amount: string; executed_amount: string; site: string | null };
type Transfer = { id: string; amount: string; reason: string; status: string; decision_note: string | null; created_at: string; from_line: string; to_line: string;
  requested_by: string | null; decided_by: string | null };
type Data = { budget: { id: string; title: string; year: number | null; currency: string; status: string; total_approved: number | null }; planned: number; executed: number;
  pct: number | null; by_category: Group[]; by_site: Group[]; by_activity: Group[]; lines: Line[]; transfers: Transfer[]; can_request: boolean; can_approve: boolean };
const money = (n: number | string) => Number(n).toLocaleString("es-PE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const TSTATUS: Record<string, string> = { pending: "Por aprobar", approved: "Aprobada", rejected: "Rechazada" };

function Bars({ title, rows }: { title: string; rows: Group[] }) {
  return (
    <div>
      <p className="mb-1 text-xs font-semibold uppercase text-muted">{title}</p>
      <ul className="space-y-1.5 text-sm">
        {rows.map((r) => (
          <li key={r.name}>
            <div className="flex justify-between gap-2"><span className="truncate">{r.name}</span><span className="shrink-0 text-xs text-muted">{money(r.executed)} de {money(r.planned)} · {r.pct ?? 0} %</span></div>
            <div className="h-1.5 rounded bg-line"><div className={`h-1.5 rounded ${(r.pct ?? 0) > 100 ? "bg-bad" : "bg-brand"}`} style={{ width: `${Math.min(100, r.pct ?? 0)}%` }} /></div>
          </li>
        ))}
      </ul>
    </div>
  );
}

export function PreExecution({ readOnly }: { readOnly: boolean }) {
  const [d, setD] = useState<Data | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [f, setF] = useState({ from: "", to: "", amount: "", reason: "" });
  const [note, setNote] = useState<Record<string, string>>({});
  const load = useCallback(() => api<{ data: Data }>("/pre/execution", { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null)), []);
  useEffect(() => { void load(); }, [load]);
  if (!d) return null;
  const fail = (e: unknown) => { const x = e as ApiError; setMsg({ ok: false, text: x.errors ? Object.values(x.errors).flat()[0] : (x.detail ?? x.title) }); };
  const approved = ["approved", "in_execution"].includes(d.budget.status);

  const request = async () => {
    setMsg(null);
    try {
      await api("/pre/transfers", { method: "POST", withCompany: true, body: { budget_id: d.budget.id, from_line_id: f.from, to_line_id: f.to, amount: Number(f.amount), reason: f.reason } });
      setF({ from: "", to: "", amount: "", reason: "" });
      setMsg({ ok: true, text: "Reasignación enviada a gerencia." });
      void load();
    } catch (e) { fail(e); }
  };
  const decide = async (t: Transfer, decision: "approved" | "rejected") => {
    setMsg(null);
    try {
      await api(`/pre/transfers/${t.id}/decide`, { method: "POST", withCompany: true, body: { decision, note: note[t.id] || null } });
      setMsg({ ok: true, text: decision === "approved" ? "Reasignación aprobada: las líneas se actualizaron." : "Reasignación rechazada." });
      void load();
    } catch (e) { fail(e); }
  };

  return (
    <div className="space-y-4">
      <Card title={`Ejecución · ${d.budget.title}`} action={<div className="flex gap-2">
        <a href={`/imprimir/presupuesto?budget_id=${d.budget.id}`} target="_blank" rel="noreferrer"
          className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-surface px-3 text-sm font-semibold hover:border-brand hover:text-brand-strong">PDF</a>
        <Button size="sm" icon="download" onClick={() => downloadFile(`/pre/execution.xlsx?budget_id=${d.budget.id}`, `ejecucion-presupuesto-${d.budget.year ?? "sst"}.xlsx`)}>Excel</Button>
      </div>}>
        {msg && <p role={msg.ok ? "status" : "alert"} className={`mb-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
        <div className="grid gap-3 sm:grid-cols-3">
          <Kpi label={`Total aprobado (${d.budget.currency})`} value={money(d.budget.total_approved ?? d.planned)} />
          <Kpi label="Ejecutado" value={money(d.executed)} />
          <Kpi label="% de ejecución" value={`${d.pct ?? 0} %`} color={(d.pct ?? 0) > 100 ? "text-bad" : "text-brand"} />
        </div>
        <div className="mt-4 grid gap-4 lg:grid-cols-3">
          <Bars title="Por categoría" rows={d.by_category} />
          <Bars title="Por sede" rows={d.by_site} />
          <Bars title="Por actividad del programa" rows={d.by_activity} />
        </div>
      </Card>

      {(approved || d.transfers.length > 0) && (
        <Card title="Reasignaciones entre líneas">
          {approved && d.can_request && !readOnly && (
            <div className="mb-3 grid gap-2 sm:grid-cols-[1fr_1fr_8rem_1.5fr_auto] sm:items-end">
              <label className="text-xs text-muted">Desde la línea
                <select className="input mt-1" value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })}><option value="">Elige…</option>
                  {d.lines.map((l) => <option key={l.id} value={l.id}>{l.description} (saldo {money(Number(l.planned_amount) - Number(l.executed_amount))})</option>)}</select></label>
              <label className="text-xs text-muted">Hacia la línea
                <select className="input mt-1" value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })}><option value="">Elige…</option>
                  {d.lines.filter((l) => l.id !== f.from).map((l) => <option key={l.id} value={l.id}>{l.description}</option>)}</select></label>
              <label className="text-xs text-muted">Monto<input type="number" min={0} step="0.01" className="input mt-1" value={f.amount} onChange={(e) => setF({ ...f, amount: e.target.value })} /></label>
              <label className="text-xs text-muted">Motivo<input className="input mt-1" value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} /></label>
              <Button variant="primary" disabled={!f.from || !f.to || !f.amount || !f.reason.trim()} onClick={request}>Pedir a gerencia</Button>
            </div>
          )}
          {d.transfers.length === 0 ? <p className="text-sm text-muted">Sin reasignaciones.</p> : (
            <table className="w-full text-left text-sm">
              <thead className="border-b border-line text-xs text-muted"><tr><th className="py-1">Desde</th><th>Hacia</th><th>Monto</th><th>Motivo</th><th>Estado</th><th /></tr></thead>
              <tbody>{d.transfers.map((t) => (
                <tr key={t.id} className="border-b border-line align-top last:border-0">
                  <td className="py-1.5">{t.from_line}</td><td>{t.to_line}</td><td>{money(t.amount)}</td>
                  <td>{t.reason}{t.decision_note && <span className="block text-xs text-muted">Gerencia: {t.decision_note}</span>}</td>
                  <td>{TSTATUS[t.status] ?? t.status}{t.decided_by && <span className="block text-xs text-muted">{t.decided_by}</span>}</td>
                  <td>{t.status === "pending" && d.can_approve && !readOnly && (
                    <div className="flex flex-wrap gap-1">
                      <input className="input h-8 w-36" placeholder="Nota (obligatoria al rechazar)" value={note[t.id] ?? ""} onChange={(e) => setNote({ ...note, [t.id]: e.target.value })} />
                      <Button size="sm" variant="primary" onClick={() => decide(t, "approved")}>Aprobar</Button>
                      <Button size="sm" onClick={() => decide(t, "rejected")}>Rechazar</Button>
                    </div>
                  )}</td>
                </tr>
              ))}</tbody>
            </table>
          )}
        </Card>
      )}
    </div>
  );
}

type ReportLine = { category: string; description: string; site: string | null; activity: string | null; cost_center: string | null; planned: number; executed: number; pct: number | null };
type ReportData = Omit<Data, "lines" | "transfers" | "can_request" | "can_approve"> & { company: Company; generated_at: string; lines: ReportLine[] };
const BSTATUS: Record<string, string> = { draft: "Borrador", in_review: "En revisión", approved: "Aprobado", in_execution: "En ejecución", closed: "Cerrado" };

function GroupTable({ rows, currency }: { rows: Group[]; currency: string }) {
  return (
    <table className="w-full border-collapse text-sm">
      <thead><tr className="bg-gray-50">{["Grupo", `Planificado (${currency})`, "Ejecutado", "% de ejecución", "Saldo"].map((h) => <th key={h} className={`${cell} text-left`}>{h}</th>)}</tr></thead>
      <tbody>{rows.length === 0 ? <tr><td className={cell} colSpan={5}>Sin líneas.</td></tr> : rows.map((r) => (
        <tr key={r.name} style={{ breakInside: "avoid" }}>
          <td className={cell}>{r.name}</td><td className={`${cell} text-right`}>{money(r.planned)}</td><td className={`${cell} text-right`}>{money(r.executed)}</td>
          <td className={`${cell} text-right`}>{r.pct === null ? "—" : `${r.pct} %`}</td><td className={`${cell} text-right`}>{money(r.planned - r.executed)}</td>
        </tr>
      ))}</tbody>
    </table>
  );
}

/** M-PRE §11: informe imprimible de la ejecución presupuestal (los mismos datos del Excel). */
export function BudgetExecutionReport({ budgetId }: { budgetId: string | null }) {
  const { data: d, error } = useReport<ReportData>(`/reports/budget_execution${budgetId ? `?budget_id=${encodeURIComponent(budgetId)}` : ""}`);
  if (!d) return <ReportLoading error={error} />;
  const b = d.budget;
  return (
    <Sheet company={d.company} title={`Ejecución presupuestal SST${b.year ? ` ${b.year}` : ""}`} sub={`${b.title} · ${b.currency}`}>
      <Section n={1} title="Resumen">
        <Facts rows={[["Estado", BSTATUS[b.status] ?? b.status], [`Total aprobado (${b.currency})`, money(b.total_approved ?? d.planned)], ["Planificado en líneas", money(d.planned)],
          ["Ejecutado", money(d.executed)], ["% de ejecución", `${d.pct ?? 0} %`], ["Generado", d.generated_at]]} />
      </Section>
      <Section n={2} title="Por categoría"><GroupTable rows={d.by_category} currency={b.currency} /></Section>
      <Section n={3} title="Por sede"><GroupTable rows={d.by_site} currency={b.currency} /></Section>
      <Section n={4} title="Por actividad del programa"><GroupTable rows={d.by_activity} currency={b.currency} /></Section>
      <Section n={5} title="Detalle por línea">
        <table className="w-full border-collapse text-[11px]">
          <thead><tr className="bg-gray-50">{["Categoría", "Descripción", "Sede", "Actividad", "Centro de costo", "Planificado", "Ejecutado", "%"].map((h) => <th key={h} className={`${cell} text-left`}>{h}</th>)}</tr></thead>
          <tbody>{d.lines.length === 0 ? <tr><td className={cell} colSpan={8}>Sin líneas.</td></tr> : d.lines.map((l, i) => (
            <tr key={i} style={{ breakInside: "avoid" }}>
              <td className={cell}>{l.category}</td><td className={cell}>{l.description}</td><td className={cell}>{l.site ?? "—"}</td><td className={cell}>{l.activity ?? "—"}</td>
              <td className={cell}>{l.cost_center ?? "—"}</td><td className={`${cell} text-right`}>{money(l.planned)}</td><td className={`${cell} text-right`}>{money(l.executed)}</td>
              <td className={`${cell} text-right`}>{l.pct === null ? "—" : `${l.pct} %`}</td>
            </tr>
          ))}</tbody>
        </table>
      </Section>
    </Sheet>
  );
}
