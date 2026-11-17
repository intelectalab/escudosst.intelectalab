"use client";

// Paneles de campo encima de la lista de registros:
//  - EPP: matriz puesto × EPP con el cumplimiento de cada trabajador (RN-EPP-04) y solicitudes de reposición desde la app.
//  - INS: tablero del año por área con % de inspecciones ejecutadas, puntaje promedio y críticas.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Card, Kpi } from "./Summary";

type W = { worker_id: string; name: string; position_id: string; compliant: boolean; pct: number; items: { ppe_item_id: string; item: string; state: "ok" | "expired" | "missing" }[] };
type Matrix = { positions: { position_id: string; position: string; items: { ppe_item_id: string; item: string; quantity: number; mandatory: boolean }[] }[]; workers: W[]; compliance: number | null };
type Req = { id: string; reason: string; notes: string | null; status: string; created_at: string; worker: string; item: string; resolution_note: string | null };
const REASON: Record<string, string> = { worn: "Desgastado", damaged: "Dañado", lost: "Perdido", size: "Talla", other: "Otro" };
const STATE: Record<string, [string, string]> = { ok: ["Vigente", "text-ok"], expired: ["Para reponer", "text-amber-ink"], missing: ["Sin entregar", "text-bad"] };
const RSTATUS: Record<string, string> = { pending: "Pendiente", attended: "Atendida", rejected: "Rechazada" };

export function EppPanel({ readOnly }: { readOnly: boolean }) {
  const [m, setM] = useState<Matrix | null>(null);
  const [reqs, setReqs] = useState<Req[]>([]);
  const [only, setOnly] = useState(true);
  const [note, setNote] = useState<Record<string, string>>({});
  const [msg, setMsg] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ data: Matrix }>("/epp/matrix", { withCompany: true }).then((r) => setM(r.data)).catch(() => setM(null));
    api<{ data: Req[] }>("/epp/replacement-requests", { withCompany: true }).then((r) => setReqs(r.data)).catch(() => setReqs([]));
  }, []);
  useEffect(() => { load(); }, [load]);
  const reject = async (id: string) => {
    try { await api(`/epp/replacement-requests/${id}/resolve`, { method: "POST", withCompany: true, body: { status: "rejected", note: note[id] ?? "" } }); setMsg(null); load(); }
    catch (e) { const x = e as ApiError; setMsg(x.errors ? Object.values(x.errors).flat()[0] : (x.detail ?? x.title)); }
  };
  const pending = reqs.filter((r) => r.status === "pending");
  const rows = (m?.workers ?? []).filter((w) => !only || !w.compliant);
  return (
    <div className="space-y-4">
      {pending.length > 0 && (
        <Card title={`Solicitudes de reposición (${pending.length})`}>
          {msg && <p role="alert" className="mb-2 text-sm text-bad">{msg}</p>}
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line text-xs text-muted"><tr><th className="py-1">Trabajador</th><th>EPP</th><th>Motivo</th><th>Pedido el</th><th /></tr></thead>
            <tbody>{pending.map((r) => (
              <tr key={r.id} className="border-b border-line last:border-0 align-top">
                <td className="py-1.5">{r.worker}</td><td>{r.item}</td><td>{REASON[r.reason] ?? r.reason}{r.notes ? <span className="block text-xs text-muted">{r.notes}</span> : null}</td>
                <td>{new Date(r.created_at).toLocaleDateString("es-PE")}</td>
                <td>{!readOnly && <div className="flex flex-wrap gap-1"><input className="input h-8 w-40" placeholder="Motivo si no se repone" value={note[r.id] ?? ""} onChange={(e) => setNote({ ...note, [r.id]: e.target.value })} />
                  <Button size="sm" onClick={() => reject(r.id)}>Rechazar</Button></div>}</td>
              </tr>
            ))}</tbody>
          </table>
          <p className="mt-2 text-xs text-muted">Al registrar la entrega del EPP a ese trabajador, la solicitud queda atendida sola.</p>
        </Card>
      )}
      {m && m.positions.length > 0 && (
        <Card title="Cumplimiento de EPP por trabajador">
          <div className="mb-3 grid gap-3 sm:grid-cols-3">
            <Kpi label="Trabajadores que cumplen" value={m.compliance != null ? `${m.compliance} %` : "—"} color={(m.compliance ?? 0) < 100 ? "text-amber-ink" : "text-ok"} />
            <Kpi label="Puestos con EPP obligatorio" value={String(m.positions.length)} />
            <Kpi label="Trabajadores evaluados" value={String(m.workers.length)} />
          </div>
          <label className="mb-2 flex items-center gap-2 text-sm"><input type="checkbox" checked={only} onChange={(e) => setOnly(e.target.checked)} /> Solo quienes no cumplen</label>
          {rows.length === 0 ? <p className="text-sm text-muted">Todos cumplen con su EPP obligatorio.</p> : (
            <table className="w-full text-left text-sm">
              <thead className="border-b border-line text-xs text-muted"><tr><th className="py-1">Trabajador</th><th>Puesto</th><th>EPP</th><th>Cumple</th></tr></thead>
              <tbody>{rows.map((w) => (
                <tr key={w.worker_id} className="border-b border-line last:border-0 align-top">
                  <td className="py-1.5">{w.name}</td><td>{m.positions.find((p) => p.position_id === w.position_id)?.position ?? "—"}</td>
                  <td>{w.items.map((i) => <span key={i.ppe_item_id} className={`mr-2 inline-block ${STATE[i.state][1]}`}>{i.item}: {STATE[i.state][0]}</span>)}</td>
                  <td>{w.pct} %</td>
                </tr>
              ))}</tbody>
            </table>
          )}
          <details className="mt-3 text-sm"><summary className="cursor-pointer font-semibold">Matriz puesto × EPP</summary>
            <ul className="mt-2 space-y-1">{m.positions.map((p) => <li key={p.position_id}><b>{p.position}:</b> {p.items.map((i) => `${i.item}${i.quantity > 1 ? ` ×${i.quantity}` : ""}${i.mandatory ? "" : " (opcional)"}`).join(", ")}</li>)}</ul>
          </details>
        </Card>
      )}
      {reqs.filter((r) => r.status !== "pending").length > 0 && (
        <details className="rounded-xl border border-line bg-surface px-4 py-3 text-sm"><summary className="cursor-pointer font-semibold">Solicitudes resueltas</summary>
          <ul className="mt-2">{reqs.filter((r) => r.status !== "pending").map((r) => <li key={r.id}>{r.worker} · {r.item} · {RSTATUS[r.status]}{r.resolution_note ? ` · ${r.resolution_note}` : ""}</li>)}</ul>
        </details>
      )}
    </div>
  );
}

type Area = { area: string; site: string | null; due: number; done: number; overdue: string; avg_score: number | null; critical: string; pct: number | null };
type Dash = { year: number; areas: Area[]; total: { due: number; done: number; pct: number | null; avg_score: number | null; critical: number } };

export function InsDashboard() {
  const [d, setD] = useState<Dash | null>(null);
  useEffect(() => { api<{ data: Dash }>("/ins/dashboard", { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null)); }, []);
  if (!d || d.areas.length === 0) return null;
  return (
    <Card title={`Tablero de inspecciones ${d.year}`}>
      <div className="mb-3 grid gap-3 sm:grid-cols-3">
        <Kpi label="Ejecutadas a la fecha" value={d.total.pct != null ? `${d.total.pct} %` : "—"} sub={`${d.total.done} de ${d.total.due}`} />
        <Kpi label="Puntaje promedio" value={d.total.avg_score != null ? `${d.total.avg_score} %` : "—"} />
        <Kpi label="Con ítems críticos incumplidos" value={String(d.total.critical)} color={d.total.critical ? "text-bad" : "text-ok"} />
      </div>
      <table className="w-full text-left text-sm">
        <thead className="border-b border-line text-xs text-muted"><tr><th className="py-1">Sede · área</th><th>Ejecutadas</th><th>Atrasadas</th><th>Puntaje promedio</th><th>Críticas</th></tr></thead>
        <tbody>{d.areas.map((a, k) => (
          <tr key={k} className="border-b border-line last:border-0"><td className="py-1.5">{a.site ? `${a.site} · ` : ""}{a.area}</td><td>{a.done} de {a.due}{a.pct != null ? ` (${a.pct} %)` : ""}</td>
            <td className={Number(a.overdue) ? "text-bad" : ""}>{a.overdue}</td><td>{a.avg_score != null ? `${a.avg_score} %` : "—"}</td><td>{a.critical}</td></tr>
        ))}</tbody>
      </table>
    </Card>
  );
}
