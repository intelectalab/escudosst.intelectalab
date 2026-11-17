"use client";

// Conciliación SCTR (M-SCT SCR-SCT-02, RN-SCT-04): se elige la póliza y el periodo, se sube la lista de asegurados (Excel/CSV)
// o se pegan los números de documento, y se revisa: asegurados que son trabajadores activos, asegurados que no están activos o no
// son trabajadores, y trabajadores que requieren SCTR y no están cubiertos. «Registrar cobertura» la guarda para los activos.

import { useEffect, useRef, useState } from "react";
import { api, API_URL, ApiError } from "@/lib/api";
import { session } from "@/lib/session";
import { Button } from "@/components/ui/Button";
import { Card, Kpi } from "./Summary";

type Policy = { id: string; coverage_kind: string; insurer_name: string | null; policy_number: string | null; starts_on: string; ends_on: string; contractor_id: string | null };
type Person = { full_name?: string; document: string; position?: string | null; site?: string | null; status?: string; already?: boolean };
type Result = { period: { from: string; to: string }; listed: number; matched: Person[]; inactive: Person[]; unknown: Person[]; uncovered: Person[]; created: number; applied: boolean };

const KIND: Record<string, string> = { health: "Salud", pension: "Pensión" };
const STATUS: Record<string, string> = { terminated: "Cesado", inactive: "Inactivo", suspended: "Suspendido" };
const dmy = (v: string) => v.split("-").reverse().join("/");
const month = () => {
  const d = new Date();
  const y = d.getFullYear();
  const m = d.getMonth() + 1;
  return { from: `${y}-${String(m).padStart(2, "0")}-01`, to: `${y}-${String(m).padStart(2, "0")}-${String(new Date(y, m, 0).getDate()).padStart(2, "0")}` };
};

async function post(fields: Record<string, string>, file: File | null): Promise<Result> {
  const body = new FormData();
  if (file) body.append("file", file);
  Object.entries(fields).forEach(([k, v]) => body.append(k, v));
  const headers: Record<string, string> = { Accept: "application/json" };
  const token = session.getToken();
  const company = session.getCompanyId();
  if (token) headers.Authorization = `Bearer ${token}`;
  if (company) headers["X-Company-Id"] = company;
  const res = await fetch(`${API_URL}/api/v1/sct/reconcile`, { method: "POST", headers, body });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(res.status, data.type ?? "error", data.title ?? "Error", data.detail, data.errors);
  return data.data as Result;
}

function List({ title, rows, empty, tone }: { title: string; rows: Person[]; empty: string; tone?: string }) {
  return (
    <div>
      <h4 className={`mb-1 text-sm font-semibold ${tone ?? ""}`}>{title} ({rows.length})</h4>
      {rows.length === 0 ? <p className="text-sm text-muted">{empty}</p> : (
        <ul className="max-h-64 space-y-1 overflow-y-auto text-sm">
          {rows.map((r, i) => (
            <li key={i} className="border-b border-line pb-1 last:border-0">
              <b>{r.full_name ?? "Documento sin trabajador"}</b> <span className="text-muted">{r.document}</span>
              {(r.position || r.site) && <span className="block text-xs text-muted">{[r.position, r.site].filter(Boolean).join(" · ")}</span>}
              {r.status && <span className="block text-xs text-muted">{STATUS[r.status] ?? r.status}</span>}
              {r.already && <span className="block text-xs text-ok">Ya tenía cobertura en el periodo</span>}
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export function SctReconcile({ readOnly }: { readOnly: boolean }) {
  const [policies, setPolicies] = useState<Policy[]>([]);
  const [policy, setPolicy] = useState("");
  const [{ from, to }, setPeriod] = useState(month);
  const [docs, setDocs] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [res, setRes] = useState<Result | null>(null);
  const [busy, setBusy] = useState<"" | "check" | "apply">("");
  const [msg, setMsg] = useState<string | null>(null);
  const input = useRef<HTMLInputElement>(null);

  useEffect(() => {
    api<{ data: Policy[] }>("/records/SCT/sctr_policies?per_page=100", { withCompany: true })
      .then((r) => setPolicies((r.data ?? []).filter((p) => !p.contractor_id)))
      .catch(() => setPolicies([]));
  }, []);

  const run = async (apply: boolean) => {
    setBusy(apply ? "apply" : "check");
    setMsg(null);
    try {
      setRes(await post({ sctr_policy_id: policy, from, to, documents: docs, ...(apply ? { apply: "1" } : {}) }, file));
    } catch (e) {
      const err = e as ApiError;
      setMsg(err.errors ? Object.values(err.errors).flat()[0] : (err.detail ?? err.title));
    } finally {
      setBusy("");
    }
  };
  const pending = res ? res.matched.filter((m) => !m.already).length : 0;

  return (
    <Card title="Conciliar la planilla de asegurados del periodo">
      <div className="grid gap-3 md:grid-cols-4">
        <label className="text-sm md:col-span-2">
          <span className="mb-1 block text-xs font-semibold text-muted">Póliza</span>
          <select className="input" value={policy} onChange={(e) => { setPolicy(e.target.value); setRes(null); }}>
            <option value="">Elige la póliza…</option>
            {policies.map((p) => <option key={p.id} value={p.id}>{KIND[p.coverage_kind] ?? p.coverage_kind} · {p.insurer_name ?? "Aseguradora"}{p.policy_number ? ` · ${p.policy_number}` : ""} ({dmy(p.starts_on)} al {dmy(p.ends_on)})</option>)}
          </select>
        </label>
        <label className="text-sm">
          <span className="mb-1 block text-xs font-semibold text-muted">Desde</span>
          <input type="date" className="input" value={from} onChange={(e) => { setPeriod({ from: e.target.value, to }); setRes(null); }} />
        </label>
        <label className="text-sm">
          <span className="mb-1 block text-xs font-semibold text-muted">Hasta</span>
          <input type="date" className="input" value={to} onChange={(e) => { setPeriod({ from, to: e.target.value }); setRes(null); }} />
        </label>
      </div>
      <div className="mt-3 grid gap-3 md:grid-cols-2">
        <div className="text-sm">
          <span className="mb-1 block text-xs font-semibold text-muted">Lista de asegurados (Excel o CSV)</span>
          <input ref={input} type="file" accept=".xlsx,.csv" className="block w-full text-sm" onChange={(e) => { setFile(e.target.files?.[0] ?? null); setRes(null); }} />
          <p className="mt-1 text-xs text-muted">Se toma, en cada fila, la primera celda con un número de documento (8 a 12 caracteres).</p>
        </div>
        <label className="text-sm">
          <span className="mb-1 block text-xs font-semibold text-muted">O pega los números de documento, uno por línea</span>
          <textarea className="input min-h-[84px]" value={docs} onChange={(e) => { setDocs(e.target.value); setRes(null); }} />
        </label>
      </div>
      <div className="mt-3 flex flex-wrap gap-2">
        <Button variant="primary" icon="search" loading={busy === "check"} disabled={!!busy || !policy || (!file && !docs.trim())} onClick={() => run(false)}>Revisar</Button>
        {res && !res.applied && !readOnly && (
          <Button icon="check" loading={busy === "apply"} disabled={!!busy || pending === 0} onClick={() => run(true)}>Registrar cobertura de {pending} trabajador(es)</Button>
        )}
      </div>
      {msg && <p role="alert" className="mt-2 text-sm text-bad">{msg}</p>}
      {res && (
        <div className="mt-4 space-y-4">
          {res.applied && <p role="status" className="text-sm text-ok">Se registró la cobertura de {res.created} trabajador(es) del {dmy(res.period.from)} al {dmy(res.period.to)}.</p>}
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Kpi label="En la lista" value={String(res.listed)} sub={`Periodo ${dmy(res.period.from)} al ${dmy(res.period.to)}`} />
            <Kpi label="Trabajadores activos" value={String(res.matched.length)} />
            <Kpi label="No activos o no son trabajadores" value={String(res.inactive.length + res.unknown.length)} />
            <Kpi label="Requieren SCTR y no están cubiertos" value={String(res.uncovered.length)} color={res.uncovered.length ? "text-bad" : undefined} />
          </div>
          <div className="grid gap-4 lg:grid-cols-3">
            <List title="Requieren SCTR y no están cubiertos" rows={res.uncovered} empty="Todos los trabajadores que requieren SCTR están cubiertos." tone="text-bad" />
            <List title="Asegurados que son trabajadores activos" rows={res.matched} empty="Ningún asegurado coincide con un trabajador activo." />
            <List title="Asegurados que no están activos o no son trabajadores" rows={[...res.inactive, ...res.unknown]} empty="Todos los asegurados son trabajadores activos." />
          </div>
        </div>
      )}
    </Card>
  );
}
