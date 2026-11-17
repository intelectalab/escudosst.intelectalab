"use client";

// RISST (M-RIS SCR-RIS-01/02/03): versión vigente con su acta y % de entregas; en las versiones en trámite, las secciones mínimas del
// art. 74 que deben estar marcadas para enviarla o aprobarla (RN-RIS-02); tabla de trabajadores entregado / pendiente en plazo /
// pendiente fuera de plazo (RN-RIS-06), entrega electrónica a todos, entregas a contratistas (RN-RIS-08) y relación de cargos en Excel.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { downloadFile } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Card, Kpi } from "./Summary";

type Ver = { id: string; version_number: number; title: string; status: string; effective_on: string | null; approved_at: string | null; approved_by: string | null;
  meeting_on: string | null; content_outline: string[] };
type W = { id: string; name: string; document_number: string; hire_on: string | null; site: string | null; status: string | null; method: string | null; delivered_on: string | null;
  state: "delivered" | "in_grace" | "late" };
type Data = { current: Ver | null; stats: { active: number; delivered: number; late: number; pct: number | null } | null; workers: W[];
  contractors: { legal_name: string; workers: number; delivered: number }[]; grace_days: number; drafts: Ver[]; sections: string[]; can_deliver: boolean };
const STATUS: Record<string, string> = { draft: "Borrador", in_review: "Enviada a aprobación", approved: "Aprobada", current: "Vigente", obsolete: "Obsoleta" };
const STATE: Record<W["state"], [string, string]> = { delivered: ["Entregado", "text-ok"], in_grace: ["Pendiente (en plazo)", "text-amber-ink"], late: ["Pendiente (fuera de plazo)", "text-bad"] };
const fmt = (d: string | null) => (d ? d.slice(0, 10).split("-").reverse().join("/") : "—");

export function RisOverview({ readOnly }: { readOnly: boolean }) {
  const [d, setD] = useState<Data | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [filter, setFilter] = useState<"" | W["state"]>("");
  const load = useCallback(() => api<{ data: Data }>("/ris/overview", { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null)), []);
  useEffect(() => { void load(); }, [load]);
  if (!d) return null;
  const fail = (e: unknown) => { const x = e as ApiError; setMsg({ ok: false, text: x.errors ? Object.values(x.errors).flat()[0] : (x.detail ?? x.title) }); };

  const toggle = async (v: Ver, s: string) => {
    setMsg(null);
    const next = v.content_outline.includes(s) ? v.content_outline.filter((x) => x !== s) : [...v.content_outline, s];
    try { await api(`/records/RIS/risst_versions/${v.id}`, { method: "PATCH", withCompany: true, body: { content_outline: next } }); void load(); } catch (e) { fail(e); }
  };
  const deliverAll = async () => {
    setMsg(null);
    try {
      const r = await api<{ data: { created: number } }>("/ris/deliver-all", { method: "POST", withCompany: true });
      setMsg({ ok: true, text: r.data.created ? `Se envió el RISST a ${r.data.created} trabajador(es) para que firmen su recepción en la app.` : "Todos los trabajadores activos ya tienen su entrega; se reenvió el aviso a los pendientes." });
      void load();
    } catch (e) { fail(e); }
  };
  const c = d.current;
  const rows = d.workers.filter((w) => !filter || w.state === filter);

  return (
    <div className="space-y-4">
      <Card title="RISST vigente" action={<Button size="sm" icon="download" onClick={() => downloadFile("/ris/deliveries.xlsx", "cargos-risst.xlsx")}>Relación de cargos (Excel)</Button>}>
        {msg && <p role={msg.ok ? "status" : "alert"} className={`mb-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
        {!c ? <p className="text-sm text-muted">Aún no hay un RISST vigente.</p> : (
          <>
            <p className="text-sm"><span className="font-semibold">{c.title}</span> · versión {c.version_number} · vigente desde {fmt(c.effective_on)}
              {c.approved_by && <> · aprobado por {c.approved_by} el {fmt(c.approved_at)}</>}{c.meeting_on && <> · acta de la sesión del {fmt(c.meeting_on)}</>}</p>
            {d.stats && (
              <div className="mt-3 grid gap-3 sm:grid-cols-3">
                <Kpi label="Trabajadores activos" value={String(d.stats.active)} />
                <Kpi label="Con cargo de entrega" value={`${d.stats.delivered} (${d.stats.pct ?? 0} %)`} color="text-ok" />
                <Kpi label={`Sin cargo pasados ${d.grace_days} días`} value={String(d.stats.late)} color={d.stats.late ? "text-bad" : "text-ok"} />
              </div>
            )}
          </>
        )}
      </Card>

      {d.drafts.length > 0 && (
        <Card title="Versiones en trámite: secciones mínimas del art. 74">
          <ul className="space-y-3">
            {d.drafts.map((v) => (
              <li key={v.id} className="rounded-lg border border-line p-3 text-sm">
                <p className="font-semibold">{v.title} · versión {v.version_number} · {STATUS[v.status] ?? v.status}
                  <span className="ml-2 text-xs font-normal text-muted">{v.content_outline.filter((x) => d.sections.includes(x)).length} de {d.sections.length} secciones</span></p>
                <div className="mt-2 grid gap-1 sm:grid-cols-2">
                  {d.sections.map((s) => (
                    <label key={s} className="flex items-start gap-2"><input type="checkbox" className="mt-1" checked={v.content_outline.includes(s)} disabled={readOnly || v.status !== "draft"} onChange={() => toggle(v, s)} /> {s}</label>
                  ))}
                </div>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {c && (
        <Card title="Entregas a los trabajadores" action={d.can_deliver && !readOnly ? <Button size="sm" variant="primary" icon="check" onClick={deliverAll}>Entregar a todos por la app</Button> : undefined}>
          <div className="mb-2 flex flex-wrap gap-2 text-sm">
            {([["", "Todos"], ["delivered", "Entregados"], ["in_grace", "En plazo"], ["late", "Fuera de plazo"]] as const).map(([k, l]) => (
              <button key={k} type="button" onClick={() => setFilter(k)} className={`rounded-full border px-3 py-0.5 ${filter === k ? "border-brand bg-brand-soft text-brand-strong" : "border-line"}`}>{l}</button>
            ))}
          </div>
          {rows.length === 0 ? <p className="text-sm text-muted">Sin trabajadores en este filtro.</p> : (
            <table className="w-full text-left text-sm">
              <thead className="border-b border-line text-xs text-muted"><tr><th className="py-1">Trabajador</th><th>Documento</th><th>Sede</th><th>Ingreso</th><th>Estado</th><th>Entregado el</th></tr></thead>
              <tbody>{rows.map((w) => (
                <tr key={w.id} className="border-b border-line last:border-0">
                  <td className="py-1.5">{w.name}</td><td>{w.document_number}</td><td>{w.site ?? "—"}</td><td>{fmt(w.hire_on)}</td>
                  <td className={STATE[w.state][1]}>{STATE[w.state][0]}</td><td>{fmt(w.delivered_on)}</td>
                </tr>
              ))}</tbody>
            </table>
          )}
          {d.contractors.length > 0 && (
            <div className="mt-4">
              <p className="mb-1 text-xs font-semibold uppercase text-muted">Contratistas (registran sus cargos en su portal)</p>
              <ul className="text-sm">{d.contractors.map((x) => <li key={x.legal_name}>{x.legal_name}: {x.delivered} de {x.workers} trabajadores con cargo</li>)}</ul>
            </div>
          )}
        </Card>
      )}
    </div>
  );
}
