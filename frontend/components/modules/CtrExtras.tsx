"use client";

// Contratistas (M-CTR, M-SCT, M-ING) para la empresa principal:
//  - CtrSummary: % de documentos aprobados y trabajadores acreditados por contratista, con reportes en Excel (SCR-CTR-01, §11).
//  - AccreditationScope: sedes y trabajadores que cubre una acreditación y lo que le falta a cada uno (SCR-CTR-06, RN-CTR-02/06).
//  - ReportButton: descarga de un reporte en Excel (garita por día, punto o contratista; cobertura del SCTR).

import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { downloadFile, type RecordRow } from "@/lib/records";

const errText = (e: unknown) => (e instanceof ApiError ? Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title : "No se pudo completar.");

export function ReportButton({ path, file, label }: { path: string; file: string; label: string }) {
  const [msg, setMsg] = useState<string | null>(null);
  return (
    <span className="inline-flex items-center gap-2">
      <button type="button" className="rounded-lg border border-line bg-surface px-3 py-1.5 text-sm font-semibold hover:border-brand" onClick={() => downloadFile(path, file).catch((e) => setMsg(errText(e)))}>{label}</button>
      {msg && <span className="text-xs text-bad">{msg}</span>}
    </span>
  );
}

type Row = { id: string; ruc: string; legal_name: string; status: string; documents: number; approved_pct: number | null; expired: number; workers: number; accredited_workers: number };

export function CtrSummary() {
  const [rows, setRows] = useState<Row[] | null>(null);
  useEffect(() => { api<{ data: Row[] }>("/ctr/summary", { withCompany: true }).then((r) => setRows(r.data)).catch(() => setRows(null)); }, []);
  if (!rows) return null;
  const today = new Date().toISOString().slice(0, 10);
  return (
    <section className="space-y-2 rounded-xl border border-line bg-surface p-4 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-semibold">Contratistas</p>
        <span className="flex flex-wrap gap-2">
          <ReportButton path="/ctr/reports.xlsx?by=contractor" file={`contratistas-${today}.xlsx`} label="Reporte por contratista" />
          <ReportButton path="/ctr/reports.xlsx?by=worker" file={`trabajadores-contratistas-${today}.xlsx`} label="Reporte por trabajador" />
        </span>
      </div>
      {rows.length === 0 ? <p className="text-muted">Aún no hay contratistas.</p> : (
        <div className="overflow-x-auto">
          <table className="w-full text-left">
            <thead className="text-xs text-muted"><tr><th className="py-1">Contratista</th><th>RUC</th><th>Documentos aprobados</th><th>Vencidos</th><th>Trabajadores acreditados</th></tr></thead>
            <tbody>{rows.map((r) => (
              <tr key={r.id} className="border-t border-line">
                <td className="py-1.5">{r.legal_name}</td><td>{r.ruc}</td>
                <td>{r.approved_pct === null ? "Sin documentos" : `${r.approved_pct} %`}</td>
                <td className={r.expired ? "text-bad" : ""}>{r.expired}</td>
                <td>{r.accredited_workers} de {r.workers}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      )}
    </section>
  );
}

type Scope = { sites: string[]; all_workers: boolean; workers: { id: string; name: string; document: string; position: string | null; missing: string[] }[] };

export function AccreditationScope({ row, canEdit, onChanged }: { row: RecordRow; canEdit: boolean; onChanged: () => void }) {
  const [d, setD] = useState<Scope | null>(null);
  const [sites, setSites] = useState<{ id: string; name: string }[]>([]);
  const [workers, setWorkers] = useState<{ id: string; first_name: string; paternal_last_name: string }[]>([]);
  const [selSites, setSelSites] = useState<string[]>([]);
  const [selWorkers, setSelWorkers] = useState<string[]>([]);
  const [edit, setEdit] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const editable = canEdit && ["draft", "submitted", "under_review", "observed"].includes(String(row.status));
  useEffect(() => {
    api<{ data: Scope }>(`/ctr/accreditations/${row.id}/workers`, { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null));
  }, [row.id, row.status]);
  const open = () => {
    setEdit(true);
    const parse = (v: unknown) => (Array.isArray(v) ? (v as string[]) : String(v ?? "").replace(/[{}]/g, "").split(",").filter(Boolean));
    setSelSites(parse(row.site_ids));
    setSelWorkers(parse(row.contractor_worker_ids));
    api<{ data: { id: string; name: string }[] }>("/records/TEN/sites?per_page=200", { withCompany: true }).then((r) => setSites(r.data)).catch(() => setSites([]));
    api<{ data: { id: string; first_name: string; paternal_last_name: string }[] }>(`/records/CTR/contractor_workers?f[contractor_id]=${row.contractor_id}&per_page=500`, { withCompany: true })
      .then((r) => setWorkers(r.data)).catch(() => setWorkers([]));
  };
  const save = () => api<{ data: Scope }>(`/ctr/accreditations/${row.id}/scope`, { method: "PUT", withCompany: true, body: { site_ids: selSites, contractor_worker_ids: selWorkers } })
    .then((r) => { setD(r.data); setEdit(false); setMsg({ ok: true, text: "Alcance guardado." }); onChanged(); }).catch((e) => setMsg({ ok: false, text: errText(e) }));
  const toggle = (list: string[], set: (v: string[]) => void, id: string) => set(list.includes(id) ? list.filter((x) => x !== id) : [...list, id]);
  if (!d) return null;
  return (
    <section className="space-y-2 rounded-xl border border-line px-4 py-3 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-semibold">Sedes y trabajadores de la acreditación</p>
        {editable && !edit && <button type="button" className="text-sm font-semibold text-brand underline" onClick={open}>Elegir sedes y trabajadores</button>}
      </div>
      <p className="text-muted">Sedes: {d.sites.length ? d.sites.join(", ") : "todas"} · {d.all_workers ? "todos los trabajadores activos" : `${d.workers.length} trabajador(es) elegidos`}</p>
      {edit && (
        <div className="space-y-2 rounded-lg bg-bg p-3">
          <p className="text-xs font-semibold">Sedes (sin marcar = todas)</p>
          <div className="flex flex-wrap gap-3">{sites.map((s) => <label key={s.id} className="flex items-center gap-1"><input type="checkbox" checked={selSites.includes(s.id)} onChange={() => toggle(selSites, setSelSites, s.id)} />{s.name}</label>)}</div>
          <p className="text-xs font-semibold">Trabajadores (sin marcar = todos)</p>
          <div className="flex max-h-40 flex-wrap gap-3 overflow-auto">{workers.map((w) => <label key={w.id} className="flex items-center gap-1"><input type="checkbox" checked={selWorkers.includes(w.id)} onChange={() => toggle(selWorkers, setSelWorkers, w.id)} />{w.first_name} {w.paternal_last_name}</label>)}</div>
          <div className="flex gap-2"><button type="button" className="rounded-lg bg-brand px-3 py-1.5 font-semibold text-white" onClick={save}>Guardar</button><button type="button" className="text-muted underline" onClick={() => setEdit(false)}>Cancelar</button></div>
        </div>
      )}
      <ul className="divide-y divide-line">{d.workers.map((w) => (
        <li key={w.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
          <span>{w.name} · {w.document}{w.position ? ` · ${w.position}` : ""}</span>
          {w.missing.length ? <span className="text-bad">Le falta: {w.missing.join(", ")}</span> : <span className="text-ok">Requisitos completos</span>}
        </li>
      ))}</ul>
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </section>
  );
}
