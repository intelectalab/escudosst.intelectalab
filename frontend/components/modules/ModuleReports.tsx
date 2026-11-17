"use client";

// Reportes de listas por módulo (§11 de las fichas): padrón de trabajadores (TRB), usuarios × roles × alcances (USR),
// lista maestra de documentos (DOC), acciones con % de cierre en plazo y antigüedad de abiertas (ACR), causas por
// categoría en Pareto (INV) y firmas pendientes por módulo y responsable (FIR).

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { downloadFile } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Kpi } from "./Summary";

const EXCEL: Record<string, [string, string, string]> = {
  TRB: ["/reports/workers.xlsx", "padron-trabajadores.xlsx", "Padrón de trabajadores activos (Excel)"],
  USR: ["/reports/users.xlsx", "usuarios-roles-alcances.xlsx", "Usuarios, roles y alcances (Excel)"],
  DOC: ["/reports/documents.xlsx", "lista-maestra-documentos.xlsx", "Lista maestra de documentos (Excel)"],
  ACR: ["/reports/corrective_actions.xlsx", "acciones-correctivas.xlsx", "Acciones (Excel)"],
  FIR: ["/reports/signature_requests.xlsx", "firmas-pendientes.xlsx", "Firmas pendientes (Excel)"],
};

export const hasModuleReports = (code: string) => code in EXCEL || code === "INV";

export function ModuleReports({ code }: { code: string }) {
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const x = EXCEL[code];
  const download = async () => {
    setBusy(true);
    setMsg(null);
    try {
      await downloadFile(x[0], x[1]);
    } catch (e) {
      setMsg((e as ApiError).detail ?? (e as ApiError).title);
    } finally {
      setBusy(false);
    }
  };
  return (
    <details className="rounded-xl border border-line bg-surface">
      <summary className="cursor-pointer px-4 py-3 text-sm font-semibold">Reportes</summary>
      <div className="space-y-4 border-t border-line p-4">
        {x && (
          <div className="flex flex-wrap items-center gap-2">
            <Button icon="download" loading={busy} onClick={download}>{x[2]}</Button>
            {msg && <span role="alert" className="text-sm text-bad">{msg}</span>}
          </div>
        )}
        {code === "ACR" && <ActionsReport />}
        {code === "INV" && <CausesPareto />}
      </div>
    </details>
  );
}

type Group = { key: string; total: number; open: number };
type Actions = { total: number; done: number; on_time: number; on_time_pct: number | null; open: number; open_avg_days: number | null; open_max_days: number | null;
  oldest: { code: string; title: string; responsible: string | null; age_days: number; due_on: string | null }[];
  by_source: Group[]; by_status: Group[]; by_site: Group[]; by_responsible: Group[] };

function useData<T>(path: string) {
  const [d, setD] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: T }>(path, { withCompany: true }).then((r) => setD(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [path]);
  return { d, error };
}

function GroupTable({ title, rows }: { title: string; rows: Group[] }) {
  return (
    <div>
      <h4 className="mb-1 text-xs font-semibold text-muted">{title}</h4>
      <table className="w-full text-left text-sm">
        <thead className="border-b border-line text-xs text-muted"><tr><th className="py-1 pr-2" /><th className="py-1 pr-2 text-right">Total</th><th className="py-1 text-right">Abiertas</th></tr></thead>
        <tbody>{rows.map((g) => <tr key={g.key} className="border-b border-line last:border-0"><td className="py-1 pr-2">{g.key}</td><td className="py-1 pr-2 text-right">{g.total}</td><td className="py-1 text-right">{g.open}</td></tr>)}</tbody>
      </table>
    </div>
  );
}

function ActionsReport() {
  const { d, error } = useData<Actions>("/reports/corrective_actions");
  if (error) return <p className="text-sm text-muted">{error}</p>;
  if (!d) return <div className="h-24 animate-pulse rounded-lg bg-bg" />;
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Kpi label="Acciones (sin anuladas)" value={String(d.total)} />
        <Kpi label="Cierre en plazo" value={d.on_time_pct == null ? "—" : `${d.on_time_pct}%`} sub={`${d.on_time} de ${d.done} terminadas hasta su fecha límite`} />
        <Kpi label="Abiertas" value={String(d.open)} />
        <Kpi label="Antigüedad de abiertas" value={d.open_avg_days == null ? "—" : `${d.open_avg_days} días`} sub={d.open_max_days == null ? undefined : `Promedio · la más antigua: ${d.open_max_days} días`} />
      </div>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <GroupTable title="Por origen" rows={d.by_source} />
        <GroupTable title="Por estado" rows={d.by_status} />
        <GroupTable title="Por sede" rows={d.by_site} />
        <GroupTable title="Por responsable" rows={d.by_responsible} />
      </div>
      {d.oldest.length > 0 && (
        <div>
          <h4 className="mb-1 text-xs font-semibold text-muted">Abiertas más antiguas</h4>
          <ul className="space-y-1 text-sm">
            {d.oldest.map((a) => <li key={a.code}><b>{a.code}</b> · {a.title} <span className="text-muted">· {a.responsible ?? "—"} · {a.age_days} días</span></li>)}
          </ul>
        </div>
      )}
    </div>
  );
}

type Pareto = { total: number; categories: { category: string; label: string; count: number; pct: number; cumulative_pct: number }[] };

function CausesPareto() {
  const { d, error } = useData<Pareto>("/reports/investigation_causes");
  if (error) return <p className="text-sm text-muted">{error}</p>;
  if (!d) return <div className="h-24 animate-pulse rounded-lg bg-bg" />;
  if (!d.total) return <p className="text-sm text-muted">Aún no hay causas con categoría en las investigaciones.</p>;
  const max = Math.max(...d.categories.map((c) => c.count));
  return (
    <div>
      <h4 className="mb-2 text-sm font-semibold">Causas por categoría (Pareto) · {d.total} causa(s)</h4>
      <table className="w-full text-left text-sm">
        <thead className="border-b border-line text-xs text-muted">
          <tr><th className="py-1 pr-2">Categoría</th><th className="w-1/2 py-1 pr-2"><span className="sr-only">Barra</span></th><th className="py-1 pr-2 text-right">Causas</th><th className="py-1 pr-2 text-right">%</th><th className="py-1 text-right">% acumulado</th></tr>
        </thead>
        <tbody>
          {d.categories.map((c) => (
            <tr key={c.category} className="border-b border-line last:border-0">
              <td className="py-1.5 pr-2">{c.label}</td>
              <td className="py-1.5 pr-2"><div className="h-3 rounded bg-brand" style={{ width: `${(c.count / max) * 100}%` }} aria-hidden="true" /></td>
              <td className="py-1.5 pr-2 text-right">{c.count}</td>
              <td className="py-1.5 pr-2 text-right">{c.pct}%</td>
              <td className={`py-1.5 text-right ${c.cumulative_pct - c.pct < 80 ? "font-semibold" : "text-muted"}`}>{c.cumulative_pct}%</td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="mt-1 text-xs text-muted">En negrita, las categorías que suman hasta el 80 % de las causas.</p>
    </div>
  );
}
