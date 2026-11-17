"use client";

// Estadísticas SST (M-EST): SCR-EST-01 horas-hombre del mes (sugeridas por turno, importación desde Excel y validación), SCR-EST-04
// cierre del mes (conciliación con los eventos, comparación entre sedes y ajustes de un mes cerrado con motivo) y SCR-EST-03 fórmulas
// y parámetros vigentes con su marca «a confirmar».

import { useCallback, useEffect, useRef, useState } from "react";
import { api, API_URL, ApiError } from "@/lib/api";
import { session } from "@/lib/session";
import { Button } from "@/components/ui/Button";
import { Card } from "./Summary";

type Suggest = { site_id: string; site: string; workers: number; shift: string | null; hours_per_day: number | null; days: number | null; suggested: number | null; note: string | null };
type Rec = { events: { id: string; code: string | null; event_type: string; status: string; lost_days: number | null; occurred_at: string; site: string | null; investigation: string | null; pending: boolean }[];
  sites: { id: string; site: string; hours_worked: number | null; lost_time: number; lost_days: number; frequency_index: number | null; severity_index: number | null; accident_rate_index: number | null; is_closed: boolean }[];
  man_hours: { id: string; site: string; hours_worked: number; contractor_hours: number | null; source: string | null; validated_at: string | null; validated_by: string | null }[] };
type Param = { key: string; description: string | null; reference: string | null; default: string | null; value: string | null; own: boolean; own_since: string | null; to_confirm: boolean };
const EVENT: Record<string, string> = { fatal_accident: "Accidente mortal", disabling_accident: "Accidente incapacitante", minor_accident: "Accidente leve", dangerous_incident: "Incidente peligroso", incident: "Incidente", occupational_disease: "Enfermedad ocupacional" };
const STATUS: Record<string, string> = { reported: "Reportado", registered: "Registrado", notified: "Notificado", under_investigation: "En investigación", closed: "Cerrado" };
const n = (v: number | null | undefined) => (v == null ? "—" : Number(v).toLocaleString("es-PE", { maximumFractionDigits: 2 }));

export function EstTools({ readOnly, onSaved }: { readOnly: boolean; onSaved: () => void }) {
  const now = new Date();
  const [ym, setYm] = useState({ y: now.getFullYear(), m: now.getMonth() + 1 });
  const [tab, setTab] = useState<"hours" | "close" | "params">("hours");
  const [sug, setSug] = useState<Suggest[] | null>(null);
  const [rec, setRec] = useState<Rec | null>(null);
  const [params, setParams] = useState<Param[] | null>(null);
  const [adj, setAdj] = useState<{ id: string; lost: string; reason: string } | null>(null);
  const [imp, setImp] = useState<{ total: number; valid: number; applied: boolean; rows: { row: number; site: string; errors: string[] }[] } | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const fail = (e: unknown) => { const err = e as ApiError; setMsg({ ok: false, text: err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title) }); };

  const load = useCallback(() => {
    api<{ data: { sites: Suggest[] } }>(`/est/suggest?year=${ym.y}&month=${ym.m}`, { withCompany: true }).then((r) => setSug(r.data.sites)).catch(() => setSug([]));
    api<{ data: Rec }>(`/est/months/${ym.y}/${ym.m}/reconcile`, { withCompany: true }).then((r) => setRec(r.data)).catch(() => setRec(null));
    api<{ data: Param[] }>("/est/params", { withCompany: true }).then((r) => setParams(r.data)).catch(() => setParams(null));
  }, [ym]);
  useEffect(() => { load(); }, [load]);

  const applySuggested = async (s: Suggest) => {
    setMsg(null);
    try {
      await api("/records/EST/man_hours", { method: "POST", withCompany: true, body: { period_year: ym.y, period_month: ym.m, site_id: s.site_id, worker_count: s.workers, hours_worked: s.suggested, source: "calculated" } });
      setMsg({ ok: true, text: `Horas-hombre de ${s.site} registradas con el cálculo sugerido. Valídalas antes de cerrar el mes.` });
      load(); onSaved();
    } catch (e) { fail(e); }
  };
  const validate = async (id: string) => {
    setMsg(null);
    try { await api(`/est/man-hours/${id}/validate`, { method: "POST", withCompany: true }); load(); } catch (e) { fail(e); }
  };
  const doImport = async (apply: boolean) => {
    if (!file) return;
    setMsg(null);
    const body = new FormData();
    body.append("file", file);
    if (apply) body.append("apply", "1");
    const headers: Record<string, string> = { Accept: "application/json" };
    const t = session.getToken(); const c = session.getCompanyId();
    if (t) headers.Authorization = `Bearer ${t}`;
    if (c) headers["X-Company-Id"] = c;
    const res = await fetch(`${API_URL}/api/v1/est/man-hours/import`, { method: "POST", headers, body });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) { setMsg({ ok: false, text: data.detail ?? data.title ?? "No se pudo importar." }); return; }
    setImp(data.data);
    if (apply) { load(); onSaved(); }
  };
  const saveParam = async (key: string, value: string) => {
    setMsg(null);
    try { await api("/est/params", { method: "POST", withCompany: true, body: { key, value } }); setMsg({ ok: true, text: "Parámetro actualizado para la empresa desde hoy. Los meses cerrados no cambian." }); load(); } catch (e) { fail(e); }
  };
  const saveAdj = async () => {
    if (!adj) return;
    setMsg(null);
    try { await api(`/est/months/${adj.id}/adjust`, { method: "POST", withCompany: true, body: { lost_days: Number(adj.lost), reason: adj.reason } }); setAdj(null); setMsg({ ok: true, text: "Ajuste registrado con su motivo." }); load(); onSaved(); } catch (e) { fail(e); }
  };

  return (
    <Card title="Herramientas de estadísticas">
      <div className="mb-3 flex flex-wrap items-center gap-3">
        <input type="month" className="input w-44" aria-label="Mes" value={`${ym.y}-${String(ym.m).padStart(2, "0")}`} onChange={(e) => { const [y, m] = e.target.value.split("-").map(Number); if (y && m) setYm({ y, m }); }} />
        <div role="tablist" className="flex flex-wrap gap-2">
          {([["hours", "Horas-hombre del mes"], ["close", "Cierre del mes"], ["params", "Fórmulas y parámetros"]] as const).map(([k, l]) => (
            <button key={k} role="tab" aria-selected={tab === k} type="button" onClick={() => setTab(k)} className={`rounded-full px-3 py-1 text-sm font-semibold ${tab === k ? "bg-brand-soft text-brand-strong" : "bg-bg text-muted"}`}>{l}</button>
          ))}
        </div>
      </div>
      {msg && <p role={msg.ok ? "status" : "alert"} className={`mb-2 text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}

      {tab === "hours" && (
        <div className="space-y-4 text-sm">
          <div>
            <h4 className="mb-1 font-semibold">Cálculo sugerido por sede</h4>
            <p className="mb-2 text-xs text-muted">Trabajadores activos de la sede × horas del turno × días del mes según los días del turno.</p>
            <table className="w-full text-left"><thead className="border-b border-line text-xs text-muted"><tr><th className="py-1">Sede</th><th>Trabajadores</th><th>Turno</th><th>Días</th><th className="text-right">Sugerido</th><th /></tr></thead>
              <tbody>{(sug ?? []).map((s) => (
                <tr key={s.site_id} className="border-b border-line last:border-0"><td className="py-1">{s.site}</td><td>{s.workers}</td><td>{s.shift ? `${s.shift} · ${s.hours_per_day} h` : "—"}</td><td>{s.days ?? "—"}</td>
                  <td className="text-right font-semibold">{s.suggested == null ? <span className="text-xs font-normal text-muted">{s.note}</span> : n(s.suggested)}</td>
                  <td className="pl-2 text-right">{!readOnly && s.suggested != null && !rec?.man_hours.some((h) => h.site === s.site) && <button type="button" className="text-xs font-semibold text-brand-strong underline" onClick={() => applySuggested(s)}>Usar sugerido</button>}</td></tr>
              ))}</tbody></table>
          </div>
          <div>
            <h4 className="mb-1 font-semibold">Horas-hombre registradas</h4>
            {(rec?.man_hours ?? []).length === 0 ? <p className="text-muted">Aún no hay horas-hombre del mes.</p> : (
              <ul className="space-y-1">{rec!.man_hours.map((h) => (
                <li key={h.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-line pb-1 last:border-0">
                  <span>{h.site}: <b>{n(h.hours_worked)}</b> h{h.contractor_hours ? ` · contratistas ${n(h.contractor_hours)} h` : ""}</span>
                  {h.validated_at ? <span className="text-xs text-ok">Validado por {h.validated_by} el {new Date(h.validated_at).toLocaleDateString("es-PE")}</span>
                    : !readOnly && <button type="button" className="text-xs font-semibold text-brand-strong underline" onClick={() => validate(h.id)}>Validar</button>}
                </li>
              ))}</ul>
            )}
          </div>
          {!readOnly && (
            <div>
              <h4 className="mb-1 font-semibold">Importar desde Excel</h4>
              <p className="mb-1 text-xs text-muted">Columnas: Código de sede, Año, Mes, Trabajadores, Horas trabajadas, Trabajadores contratistas, Horas contratistas.</p>
              <input ref={fileRef} type="file" accept=".xlsx,.csv" aria-label="Archivo de horas-hombre" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setImp(null); }} />
              <div className="mt-2 flex gap-2"><Button disabled={!file} onClick={() => doImport(false)}>Revisar</Button>
                {imp && !imp.applied && <Button variant="primary" disabled={imp.valid === 0} onClick={() => doImport(true)}>Registrar {imp.valid} fila(s)</Button>}</div>
              {imp && <ul className="mt-2 max-h-40 overflow-y-auto text-xs">{imp.rows.map((r) => <li key={r.row} className={r.errors.length ? "text-bad" : ""}>Fila {r.row}: {r.site}{r.errors.length ? ` — ${r.errors.join(" ")}` : imp.applied ? " — registrada" : " — lista"}</li>)}</ul>}
            </div>
          )}
        </div>
      )}

      {tab === "close" && rec && (
        <div className="space-y-4 text-sm">
          <div>
            <h4 className="mb-1 font-semibold">Eventos del mes</h4>
            {rec.events.length === 0 ? <p className="text-muted">No hay eventos registrados en el mes.</p> : (
              <ul className="space-y-1">{rec.events.map((e) => (
                <li key={e.id} className={e.pending ? "text-amber-ink" : ""}>{e.code ?? "Evento"} · {EVENT[e.event_type] ?? e.event_type} · {e.site ?? "—"} · {STATUS[e.status] ?? e.status}{e.lost_days ? ` · ${e.lost_days} días perdidos` : ""}{e.pending ? " · revisar antes de cerrar" : ""}</li>
              ))}</ul>
            )}
          </div>
          <div>
            <h4 className="mb-1 font-semibold">Comparación entre sedes</h4>
            <table className="w-full text-left text-xs"><thead className="border-b border-line text-muted"><tr><th className="py-1">Sede</th><th className="text-right">Horas-hombre</th><th className="text-right">Accidentes con tiempo perdido</th><th className="text-right">Días perdidos</th><th className="text-right">IF</th><th className="text-right">IG</th><th className="text-right">IA</th><th>Estado</th></tr></thead>
              <tbody>{rec.sites.map((s, i) => <tr key={i} className="border-b border-line last:border-0"><td className="py-1">{s.site}</td><td className="text-right">{n(s.hours_worked)}</td><td className="text-right">{s.lost_time}</td><td className="text-right">{s.lost_days}</td><td className="text-right">{n(s.frequency_index)}</td><td className="text-right">{n(s.severity_index)}</td><td className="text-right">{n(s.accident_rate_index)}</td><td>{s.is_closed ? "Cerrado" : "Provisional"}</td></tr>)}</tbody></table>
            {rec.sites.length === 0 && <p className="text-xs text-muted">Calcula el mes para ver la comparación.</p>}
          </div>
          {!readOnly && rec.sites.some((m) => m.is_closed) && (
            <div>
              <h4 className="mb-1 font-semibold">Ajustar un mes cerrado</h4>
              <select className="input max-w-xs" aria-label="Fila del mes" value={adj?.id ?? ""} onChange={(e) => { const m = rec.sites.find((x) => x.id === e.target.value); setAdj(m ? { id: m.id, lost: String(m.lost_days), reason: "" } : null); }}>
                <option value="">Elige la sede…</option>{rec.sites.filter((m) => m.is_closed).map((m) => <option key={m.id} value={m.id}>{m.site}</option>)}
              </select>
              {adj && <div className="mt-2 grid gap-2 sm:grid-cols-3">
                <input type="number" min={0} className="input" aria-label="Días perdidos" value={adj.lost} onChange={(e) => setAdj({ ...adj, lost: e.target.value })} />
                <input className="input sm:col-span-2" placeholder="Motivo del ajuste" value={adj.reason} onChange={(e) => setAdj({ ...adj, reason: e.target.value })} />
                <Button disabled={adj.reason.trim().length < 5} onClick={saveAdj}>Registrar ajuste</Button></div>}
            </div>
          )}
        </div>
      )}

      {tab === "params" && params && (
        <table className="w-full text-left text-sm"><thead className="border-b border-line text-xs text-muted"><tr><th className="py-1">Parámetro</th><th>Valor</th><th>Origen</th><th /></tr></thead>
          <tbody>{params.map((p) => (
            <tr key={p.key} className="border-b border-line align-top last:border-0">
              <td className="py-1.5 pr-2">{p.description ?? p.key}<span className="block text-xs text-muted">{p.reference}</span></td>
              <td className="pr-2">
                {p.key === "include_contractors_in_stats" ? (
                  <select className="input h-8 py-0" aria-label={p.key} value={p.value ?? "false"} disabled={readOnly} onChange={(e) => saveParam(p.key, e.target.value)}><option value="false">No</option><option value="true">Sí</option></select>
                ) : p.key === "lost_days_allocation" ? (
                  <select className="input h-8 py-0" aria-label={p.key} value={p.value ?? "event_month"} disabled={readOnly} onChange={(e) => saveParam(p.key, e.target.value)}><option value="event_month">Al mes del evento</option><option value="loss_month">Al mes en que se pierden</option></select>
                ) : (
                  <input className="input h-8 w-36 py-0" aria-label={p.key} defaultValue={p.value ?? ""} disabled={readOnly} onBlur={(e) => e.target.value !== p.value && saveParam(p.key, e.target.value)} />
                )}
              </td>
              <td className="text-xs">{p.own ? `De la empresa desde ${p.own_since?.split("-").reverse().join("/")}` : p.to_confirm ? "Referencia · a confirmar" : "Referencia"}</td>
              <td />
            </tr>
          ))}</tbody></table>
      )}
    </Card>
  );
}
