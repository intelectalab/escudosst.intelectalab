"use client";

// Portal del contratista, piezas adicionales (M-CTR SCR-CTR-04, RN-CTR-08 → M-SCT):
//  - «Cargar trabajadores desde Excel»: plantilla CTR-W1 → subir → revisión fila por fila → importa las válidas.
//    Endpoints: GET /ctr/portal/workers/template, POST /ctr/portal/workers/preview y /ctr/portal/workers/import.
//  - SCTR: pólizas de salud y pensión del contratista y la constancia de cobertura de cada trabajador por periodo.
//    Endpoints: GET /ctr/portal/sctr, POST /ctr/portal/sctr/policies y /ctr/portal/sctr/coverages.
// Todo queda en el contratista del usuario (RN-CTR-04); no se piden datos médicos.

import { useCallback, useEffect, useRef, useState } from "react";
import { api, API_URL, ApiError } from "@/lib/api";
import { downloadFile, uploadFile } from "@/lib/records";
import { session } from "@/lib/session";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Card, Chip, Kpi, type Tone } from "./Summary";
import { fmtDate } from "./CtrShared";
import { SummaryRow, Wizard, type WizardStep } from "./Wizard";

// ── Carga desde Excel ──

type Row = { row: number; document: string; name: string; position: string; action: "create" | "error"; errors: string[]; result?: string };
type Preview = { total: number; valid: number; with_errors: number; rows: Row[] };
type ImportData = { file_name: string; preview: Preview | null };

async function postFile<T>(path: string, file: File): Promise<T> {
  const body = new FormData();
  body.append("file", file);
  const headers: Record<string, string> = { Accept: "application/json" };
  const token = session.getToken();
  const company = session.getCompanyId();
  if (token) headers.Authorization = `Bearer ${token}`;
  if (company) headers["X-Company-Id"] = company;
  const res = await fetch(`${API_URL}/api/v1${path}`, { method: "POST", headers, body });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(res.status, data.type ?? "error", data.title ?? "Error", data.detail, data.errors);
  return data.data as T;
}

function downloadErrors(rows: Row[]) {
  const esc = (v: string) => `"${v.replace(/"/g, '""')}"`;
  const lines = [["Fila", "Documento", "Nombre", "Puesto", "Error"].map(esc).join(";")];
  rows.filter((r) => r.errors.length).forEach((r) => lines.push([String(r.row), r.document, r.name, r.position, r.errors.join(" ")].map(esc).join(";")));
  const url = URL.createObjectURL(new Blob(["﻿" + lines.join("\r\n")], { type: "text/csv;charset=utf-8" }));
  const a = document.createElement("a");
  a.href = url;
  a.download = "trabajadores-con-error.csv";
  a.click();
  URL.revokeObjectURL(url);
}

function RowsTable({ rows, result }: { rows: Row[]; result?: boolean }) {
  return (
    <div className="max-h-[46vh] overflow-auto rounded-xl border border-line">
      <table className="w-full text-left text-sm">
        <thead className="sticky top-0 border-b border-line bg-bg text-xs font-semibold text-muted">
          <tr><th className="px-3 py-2">Fila</th><th className="px-3 py-2">Trabajador</th><th className="hidden px-3 py-2 sm:table-cell">Puesto</th><th className="px-3 py-2">Estado</th></tr>
        </thead>
        <tbody>
          {rows.map((r) => {
            const ok = result ? r.result === "created" : r.action === "create";
            return (
              <tr key={r.row} className="border-b border-line align-top last:border-0">
                <td className="px-3 py-2 tabular-nums text-muted">{r.row}</td>
                <td className="px-3 py-2">
                  <b className="block">{r.name || "—"}</b>
                  <span className="text-xs text-muted">{r.document}</span>
                  {r.errors.length > 0 && <ul className="mt-1 space-y-0.5 text-xs text-bad">{r.errors.map((e) => <li key={e}>{e}</li>)}</ul>}
                </td>
                <td className="hidden px-3 py-2 text-xs text-muted sm:table-cell">{r.position || "—"}</td>
                <td className="px-3 py-2"><Chip tone={ok ? "ok" : "bad"}>{ok ? (result ? "Agregado" : "Se agrega") : "Con error"}</Chip></td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

export function CtrWorkersImport({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const file = useRef<File | null>(null);
  const steps: WizardStep<ImportData>[] = [
    {
      title: "Plantilla",
      hint: "Descarga la plantilla, completa una fila por trabajador y guárdala en Excel.",
      render: () => (
        <div className="space-y-4">
          <Button icon="download" onClick={() => downloadFile("/ctr/portal/workers/template", "plantilla-trabajadores-contratista.xlsx").catch(() => undefined)}>
            Descargar plantilla (.xlsx)
          </Button>
          <p className="text-sm text-muted">Columnas: tipo y número de documento, nombres, apellido paterno, apellido materno, puesto u oficio y celular. No incluyas datos médicos: la aptitud se sube como constancia en cada trabajador.</p>
        </div>
      ),
    },
    {
      title: "Subir archivo",
      validate: () => (!file.current ? "Elige el archivo de Excel completado." : null),
      onNext: async () => ({ preview: await postFile<Preview>("/ctr/portal/workers/preview", file.current!) }),
      render: (d, set) => (
        <div className="space-y-3">
          <label className="flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed border-line p-8 text-center hover:border-brand/60">
            <span className="text-sm font-semibold">{file.current ? file.current.name : d.file_name ? `Vuelve a elegir «${d.file_name}»` : "Elige el archivo .xlsx o .csv"}</span>
            <span className="text-xs text-muted">Hasta 5 MB y 1000 trabajadores</span>
            <input type="file" accept=".xlsx,.csv" className="sr-only" onChange={(e) => {
              file.current = e.target.files?.[0] ?? null;
              set({ file_name: file.current?.name ?? "", preview: null });
            }} />
          </label>
          <p className="text-xs text-muted">Al continuar revisamos cada fila; todavía no se guarda nada.</p>
        </div>
      ),
    },
    {
      title: "Revisión",
      validate: (d) => (!d.preview ? "Vuelve a subir el archivo para revisarlo." : d.preview.valid === 0 ? "Ninguna fila es válida: corrige el archivo y vuelve a subirlo." : null),
      render: (d) =>
        d.preview && (
          <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
              <Chip tone="ok">{d.preview.valid} válida(s)</Chip>
              <Chip tone={d.preview.with_errors ? "bad" : "na"}>{d.preview.with_errors} con error</Chip>
              <span className="text-sm text-muted">de {d.preview.total} fila(s)</span>
              {d.preview.with_errors > 0 && <Button size="sm" icon="download" className="ml-auto" onClick={() => downloadErrors(d.preview!.rows)}>Filas con error</Button>}
            </div>
            <RowsTable rows={d.preview.rows} />
            {d.preview.with_errors > 0 && <p className="text-sm text-muted">Las filas con error no se cargan y no bloquean a las demás.</p>}
          </div>
        ),
    },
  ];

  return (
    <Wizard<ImportData>
      id="ctr-import"
      title="Cargar trabajadores desde Excel"
      subtitle="Alta masiva del personal de tu empresa"
      icon="people"
      initial={{ file_name: "", preview: null }}
      steps={steps}
      confirmLabel="Cargar trabajadores"
      onClose={onClose}
      summary={(d, goTo) => (
        <div>
          <SummaryRow label="Archivo" value={d.file_name} onEdit={() => goTo(1)} />
          <SummaryRow label="Se agregarán" value={String(d.preview?.valid ?? 0)} />
          <SummaryRow label="No se cargan (con error)" value={String(d.preview?.with_errors ?? 0)} onEdit={() => goTo(2)} />
        </div>
      )}
      onConfirm={async () => {
        if (!file.current) throw new Error("Vuelve a elegir el archivo para cargarlo.");
        const r = await postFile<{ created: number; failed: number; rows: Row[] }>("/ctr/portal/workers/import", file.current);
        onDone();
        return (
          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <div className="rounded-xl border border-line p-3 text-center"><p className="text-xs text-muted">Agregados</p><p className="font-display text-2xl font-bold text-ok">{r.created}</p></div>
              <div className="rounded-xl border border-line p-3 text-center"><p className="text-xs text-muted">Con error</p><p className="font-display text-2xl font-bold text-bad">{r.failed}</p></div>
            </div>
            {r.failed > 0 && <Button icon="download" onClick={() => downloadErrors(r.rows)}>Descargar filas con error</Button>}
            <RowsTable rows={r.rows} result />
          </div>
        );
      }}
    />
  );
}

// ── SCTR ──

type Policy = {
  id: string;
  coverage_kind: "health" | "pension";
  insurer_type: string;
  insurer_name: string;
  policy_number: string;
  starts_on: string;
  ends_on: string;
  status: "active" | "expiring" | "expired" | "cancelled";
  file_name: string | null;
  covered_workers: number;
};
type Coverage = { state: "covered" | "expiring" | "expired" | "missing"; covered_until: string | null; policy_number: string | null };
type SctrWorker = { id: string; name: string; document_last4: string | null; health: Coverage; pension: Coverage; has_sctr: boolean };
type Sctr = { policies: Policy[]; workers: SctrWorker[]; kpis: { policies_active: number; policies_expiring: number; workers: number; workers_without: number } };

const KIND: Record<string, string> = { health: "Salud", pension: "Pensión" };
const POLICY_STATE: Record<string, [string, Tone]> = { active: ["Vigente", "ok"], expiring: ["Por vencer", "warn"], expired: ["Vencida", "bad"], cancelled: ["Cancelada", "na"] };
const COVER_STATE: Record<string, [string, Tone]> = { covered: ["Cubierto", "ok"], expiring: ["Por vencer", "warn"], expired: ["Vencido", "bad"], missing: ["Sin constancia", "bad"] };

export function CtrSctrCard() {
  const [data, setData] = useState<Sctr | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [policyModal, setPolicyModal] = useState(false);
  const [coverModal, setCoverModal] = useState(false);

  const load = useCallback(() => {
    api<{ data: Sctr }>("/ctr/portal/sctr", { withCompany: true })
      .then((r) => setData(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);
  useEffect(load, [load]);

  if (error) return <Card title="SCTR"><p className="text-sm text-muted">{error}</p></Card>;
  if (!data) return <div className="h-40 animate-pulse rounded-xl bg-surface" />;
  const usable = data.policies.filter((p) => p.status === "active" || p.status === "expiring");

  return (
    <Card
      title="SCTR de tu personal"
      action={
        <div className="flex flex-wrap gap-2">
          <Button size="sm" icon="plus" onClick={() => setPolicyModal(true)}>Registrar póliza</Button>
          <Button size="sm" variant="primary" icon="file" disabled={!usable.length || !data.workers.length} onClick={() => setCoverModal(true)}>Cargar constancia</Button>
        </div>
      }
    >
      <p className="mb-3 text-sm text-muted">Registra tus pólizas SCTR de salud y pensión y la constancia mensual con los trabajadores asegurados. Sin salud y pensión vigentes el trabajador no puede ingresar.</p>
      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Kpi label="Pólizas vigentes" value={String(data.kpis.policies_active)} color={data.kpis.policies_active >= 2 ? "text-ok" : "text-amber-ink"} />
        <Kpi label="Pólizas por vencer" value={String(data.kpis.policies_expiring)} color={data.kpis.policies_expiring ? "text-amber-ink" : "text-ok"} />
        <Kpi label="Trabajadores activos" value={String(data.kpis.workers)} />
        <Kpi label="Sin SCTR vigente" value={String(data.kpis.workers_without)} color={data.kpis.workers_without ? "text-bad" : "text-ok"} />
      </div>

      <h3 className="mb-2 text-sm font-semibold">Pólizas</h3>
      {data.policies.length === 0 ? (
        <p className="mb-4 text-sm text-muted">Aún no registras pólizas. Necesitas una de salud y una de pensión.</p>
      ) : (
        <ul className="mb-4 divide-y divide-line rounded-xl border border-line">
          {data.policies.map((p) => {
            const [l, t] = POLICY_STATE[p.status] ?? [p.status, "na"];
            return (
              <li key={p.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
                <div className="min-w-0 flex-1">
                  <p className="font-medium">{KIND[p.coverage_kind]} · {p.insurer_name} · N.° {p.policy_number}</p>
                  <p className="text-xs text-muted">{fmtDate(p.starts_on)} – {fmtDate(p.ends_on)} · {p.covered_workers} trabajador(es) cubiertos{p.file_name ? ` · ${p.file_name}` : ""}</p>
                </div>
                <Chip tone={t}>{l}</Chip>
              </li>
            );
          })}
        </ul>
      )}

      <h3 className="mb-2 text-sm font-semibold">Cobertura por trabajador</h3>
      {data.workers.length === 0 ? (
        <p className="text-sm text-muted">Agrega a tus trabajadores para cargar su cobertura.</p>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-line">
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line bg-bg text-xs font-semibold text-muted">
              <tr><th className="px-3 py-2">Trabajador</th><th className="px-3 py-2">Salud</th><th className="px-3 py-2">Pensión</th><th className="px-3 py-2">SCTR</th></tr>
            </thead>
            <tbody>
              {data.workers.map((w) => (
                <tr key={w.id} className="border-b border-line last:border-0">
                  <td className="px-3 py-2"><b className="block">{w.name}</b><span className="text-xs text-muted">…{w.document_last4 ?? "—"}</span></td>
                  {[w.health, w.pension].map((c, i) => {
                    const [l, t] = COVER_STATE[c.state];
                    return (
                      <td key={i} className="px-3 py-2">
                        <Chip tone={t}>{l}</Chip>
                        {c.covered_until && <span className="block text-xs text-muted">hasta {fmtDate(c.covered_until)}</span>}
                      </td>
                    );
                  })}
                  <td className="px-3 py-2">{w.has_sctr ? <Chip tone="ok">Vigente</Chip> : <Chip tone="bad">Sin SCTR</Chip>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {policyModal && <PolicyModal onClose={() => setPolicyModal(false)} onDone={() => { setPolicyModal(false); load(); }} />}
      {coverModal && <CoverageModal policies={usable} workers={data.workers} onClose={() => setCoverModal(false)} onDone={() => { setCoverModal(false); load(); }} />}
    </Card>
  );
}

function useSend(fn: () => Promise<void>) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const send = async () => {
    setBusy(true);
    setError(null);
    setErrors({});
    try {
      await fn();
    } catch (e) {
      const err = e as ApiError;
      setError(err.detail ?? err.title ?? (e as Error).message);
      setErrors(err.errors ?? {});
    } finally {
      setBusy(false);
    }
  };
  return { busy, error, errors, send, setError };
}

function PolicyModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const [f, setF] = useState({ coverage_kind: "health", insurer_type: "eps", insurer_name: "", insurer_ruc: "", policy_number: "", starts_on: "", ends_on: "" });
  const [file, setFile] = useState<File | null>(null);
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setF({ ...f, [k]: e.target.value });
  const s = useSend(async () => {
    if (!file) throw new Error("Adjunta la póliza o la constancia de la aseguradora.");
    const up = await uploadFile(file, "CTR");
    await api("/ctr/portal/sctr/policies", { method: "POST", withCompany: true, body: { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v !== "")), file_id: up.id } });
    onDone();
  });
  const err = (k: string) => s.errors[k]?.[0];

  return (
    <Modal title="Registrar póliza SCTR" subtitle="Una póliza por tipo de cobertura: salud y pensión." icon="shield" onClose={onClose}
      footer={<><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={s.busy} onClick={s.send}>Guardar póliza</Button></>}>
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField label="Cobertura" error={err("coverage_kind")}>
          <select className="input" value={f.coverage_kind} onChange={set("coverage_kind")}>
            <option value="health">Salud</option>
            <option value="pension">Pensión</option>
          </select>
        </FormField>
        <FormField label="Tipo de aseguradora" error={err("insurer_type")}>
          <select className="input" value={f.insurer_type} onChange={set("insurer_type")}>
            {[["essalud", "EsSalud"], ["eps", "EPS"], ["private_insurer", "Aseguradora privada"], ["onp", "ONP"]].map(([v, l]) => <option key={v} value={v}>{l}</option>)}
          </select>
        </FormField>
        <FormField label="Aseguradora" error={err("insurer_name")}>
          <input className="input" value={f.insurer_name} onChange={set("insurer_name")} aria-invalid={!!err("insurer_name")} />
        </FormField>
        <FormField label="RUC de la aseguradora" hint="Opcional" error={err("insurer_ruc")}>
          <input className="input" inputMode="numeric" maxLength={11} value={f.insurer_ruc} onChange={set("insurer_ruc")} />
        </FormField>
        <FormField label="N.° de póliza" error={err("policy_number")}>
          <input className="input" value={f.policy_number} onChange={set("policy_number")} aria-invalid={!!err("policy_number")} />
        </FormField>
        <FormField label="Archivo de la póliza" hint="PDF o imagen" error={err("file_id")}>
          <input className="input" type="file" accept="application/pdf,image/*" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        </FormField>
        <FormField label="Vigente desde" error={err("starts_on")}>
          <input className="input" type="date" value={f.starts_on} onChange={set("starts_on")} />
        </FormField>
        <FormField label="Vigente hasta" error={err("ends_on")}>
          <input className="input" type="date" value={f.ends_on} onChange={set("ends_on")} />
        </FormField>
      </div>
      {s.error && !Object.keys(s.errors).length && <p className="mt-4 rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{s.error}</p>}
    </Modal>
  );
}

function CoverageModal({ policies, workers, onClose, onDone }: { policies: Policy[]; workers: SctrWorker[]; onClose: () => void; onDone: () => void }) {
  const [policy, setPolicy] = useState(policies[0]?.id ?? "");
  const [selected, setSelected] = useState<string[]>([]);
  const [from, setFrom] = useState("");
  const [until, setUntil] = useState("");
  const [number, setNumber] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const p = policies.find((x) => x.id === policy);
  const s = useSend(async () => {
    const up = file ? await uploadFile(file, "CTR") : null;
    await api("/ctr/portal/sctr/coverages", {
      method: "POST",
      withCompany: true,
      body: { sctr_policy_id: policy, contractor_worker_ids: selected, covered_from: from, covered_until: until, constancy_number: number || undefined, file_id: up?.id },
    });
    onDone();
  });
  const err = (k: string) => s.errors[k]?.[0];
  const toggle = (id: string) => setSelected((cur) => (cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]));

  return (
    <Modal title="Cargar constancia SCTR" subtitle="Marca a los trabajadores asegurados en la constancia del periodo." icon="file" onClose={onClose}
      footer={<><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={s.busy} onClick={s.send}>Guardar cobertura</Button></>}>
      <div className="space-y-4">
        <FormField label="Póliza" error={err("sctr_policy_id")}>
          <select className="input" value={policy} onChange={(e) => setPolicy(e.target.value)}>
            {policies.map((x) => <option key={x.id} value={x.id}>{KIND[x.coverage_kind]} · {x.insurer_name} · N.° {x.policy_number}</option>)}
          </select>
        </FormField>
        {p && <p className="text-xs text-muted">La cobertura debe estar dentro de la vigencia de la póliza: {fmtDate(p.starts_on)} – {fmtDate(p.ends_on)}.</p>}
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Cubre desde" error={err("covered_from")}>
            <input className="input" type="date" value={from} min={p?.starts_on?.slice(0, 10)} max={p?.ends_on?.slice(0, 10)} onChange={(e) => setFrom(e.target.value)} />
          </FormField>
          <FormField label="Cubre hasta" error={err("covered_until")}>
            <input className="input" type="date" value={until} min={from || p?.starts_on?.slice(0, 10)} max={p?.ends_on?.slice(0, 10)} onChange={(e) => setUntil(e.target.value)} />
          </FormField>
          <FormField label="N.° de constancia" hint="Opcional">
            <input className="input" value={number} onChange={(e) => setNumber(e.target.value)} />
          </FormField>
          <FormField label="Archivo de la constancia" hint="Opcional · PDF o imagen" error={err("file_id")}>
            <input className="input" type="file" accept="application/pdf,image/*" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          </FormField>
        </div>
        <fieldset>
          <legend className="mb-2 flex w-full items-center justify-between text-sm font-semibold">
            <span>Trabajadores asegurados ({selected.length})</span>
            <button type="button" className="text-xs font-semibold text-brand hover:underline" onClick={() => setSelected(selected.length === workers.length ? [] : workers.map((w) => w.id))}>
              {selected.length === workers.length ? "Quitar todos" : "Marcar todos"}
            </button>
          </legend>
          <ul className="max-h-60 divide-y divide-line overflow-auto rounded-xl border border-line">
            {workers.map((w) => (
              <li key={w.id}>
                <label className="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-bg">
                  <input type="checkbox" checked={selected.includes(w.id)} onChange={() => toggle(w.id)} />
                  <span className="flex-1 text-sm">{w.name} <span className="text-xs text-muted">…{w.document_last4 ?? "—"}</span></span>
                </label>
              </li>
            ))}
          </ul>
          {err("contractor_worker_ids") && <p className="mt-1 text-sm text-bad">{err("contractor_worker_ids")}</p>}
        </fieldset>
        {s.error && !Object.keys(s.errors).length && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{s.error}</p>}
      </div>
    </Modal>
  );
}
