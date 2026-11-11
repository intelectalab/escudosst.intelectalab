"use client";

// Ficha 360° del trabajador (M-TRB, SCR-TRB-02): cabecera con nombre, documento enmascarado, puesto, sede y chip
// «Habilitado / No habilitado» (semáforo de requisitos), y pestañas Datos · Asignaciones · Capacitaciones · EPP ·
// Aptitud (solo el estado, nunca datos clínicos) · SCTR · Eventos · Documentos. Cada pestaña lista los registros del
// trabajador en su módulo (f[worker_id]) y enlaza al módulo de origen.

import Link from "next/link";
import { useParams } from "next/navigation";
import { useEffect, useMemo, useState } from "react";
import { useApp } from "@/components/AppContext";
import { Icon } from "@/components/ui/Icon";
import { EnabledChip, RequirementChips, useRequirements } from "@/components/modules/WorkerChecks";
import { WorkerAppActivation } from "@/components/worker/WorkerAppActivation";
import { displayValue, records, recordsHref, type ModuleSchema, type RecordField, type RecordRow } from "@/lib/records";

type Tab = { key: string; label: string; module: string; table: string; columns?: string[] };

const TABS: Tab[] = [
  { key: "data", label: "Datos", module: "TRB", table: "workers" },
  { key: "assign", label: "Asignaciones", module: "TRB", table: "worker_assignments", columns: ["site_id", "area_id", "position_id", "starts_on", "ends_on", "is_primary"] },
  { key: "cap", label: "Capacitaciones", module: "CAP", table: "training_certificates", columns: ["course_title", "certificate_code", "issued_on", "expires_on", "status"] },
  { key: "epp", label: "EPP", module: "EPP", table: "ppe_deliveries", columns: ["ppe_item_id", "quantity", "delivered_at", "expires_on", "status"] },
  // Solo el estado de aptitud y su vigencia (RN-TRB-09, RN-PTR-02): ningún dato clínico.
  { key: "emo", label: "Aptitud", module: "EMO", table: "fitness_status", columns: ["status", "valid_from", "valid_until", "is_current"] },
  { key: "sct", label: "SCTR", module: "SCT", table: "sctr_coverages", columns: ["sctr_policy_id", "covered_from", "covered_until", "status"] },
  { key: "acc", label: "Eventos", module: "ACC", table: "event_people", columns: ["event_id", "person_role", "injury_type", "lost_days"] },
  { key: "doc", label: "Documentos", module: "TRB", table: "worker_documents", columns: ["title", "document_kind", "issued_on", "expires_on", "status"] },
];

const DATA_FIELDS = ["document_type", "full_name", "birth_on", "sex", "nationality", "phone_e164", "personal_email", "emergency_contact_name", "emergency_contact_phone",
  "employee_code", "hire_on", "employment_type", "contract_type", "current_site_id", "current_area_id", "current_position_id", "termination_on", "status"];

const schemas = new Map<string, Promise<ModuleSchema>>();
const schemaOf = (code: string) => {
  if (!schemas.has(code)) schemas.set(code, records.schema(code).catch((e) => { schemas.delete(code); throw e; }));
  return schemas.get(code)!;
};

export default function WorkerPage() {
  const { id } = useParams<{ id: string }>();
  const { modules } = useApp();
  const [worker, setWorker] = useState<RecordRow | null>(null);
  const [fields, setFields] = useState<RecordField[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState("data");
  const { data: req } = useRequirements([id]);
  const r = req[id];

  useEffect(() => {
    Promise.all([records.get("TRB", "workers", id), schemaOf("TRB")])
      .then(([w, s]) => {
        setWorker(w.data);
        setFields(s.resources.find((x) => x.table === "workers")?.fields ?? []);
      })
      .catch((e) => setError(e?.detail ?? "No se pudo abrir la ficha del trabajador."));
  }, [id]);

  const usable = (code: string) => {
    const m = modules.find((x) => x.code === code);
    return !!m && m.role_can_use && (m.mode === "enabled" || m.mode === "read_only");
  };
  const tabs = TABS.filter((t) => usable(t.module));
  const current = tabs.find((t) => t.key === tab) ?? tabs[0];

  if (error) {
    return (
      <div className="space-y-3 rounded-xl border border-line bg-surface p-8">
        <h1 className="text-xl font-semibold">Ficha no disponible</h1>
        <p className="text-muted">{error}</p>
        <Link href="/m/trb" className="inline-block rounded-lg border border-line px-4 py-2 hover:bg-bg">Volver a trabajadores</Link>
      </div>
    );
  }
  if (!worker) return <div className="h-48 animate-pulse rounded-xl bg-surface" />;

  const label = (k: string) => (worker[`${k}__label`] as string) || "";
  return (
    <div className="space-y-4">
      <nav aria-label="Migas de pan" className="text-xs text-muted">
        EscudoSST › <Link href="/m/trb" className="hover:text-brand">Trabajadores</Link> › <span className="text-fg">{String(worker.full_name ?? "")}</span>
      </nav>
      <header className="flex flex-wrap items-start gap-4 rounded-xl border border-line bg-surface p-4">
        <span className="grid h-14 w-14 shrink-0 place-items-center rounded-full bg-brand-soft text-brand-strong" aria-hidden="true"><Icon name="user" size={26} /></span>
        <div className="min-w-0 flex-1 space-y-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="font-display text-2xl font-bold leading-tight">{String(worker.full_name ?? "")}</h1>
            {r && <EnabledChip enabled={r.enabled} />}
          </div>
          <p className="text-sm text-muted">
            {String(worker.document_type ?? "DNI")} ****{String(worker.document_last4 ?? "")}
            {label("current_position_id") && ` · ${label("current_position_id")}`}
            {label("current_site_id") && ` · ${label("current_site_id")}`}
          </p>
          {r && <RequirementChips checks={r.checks} />}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <WorkerAppActivation workerId={id} />
          <Link href={recordsHref("TRB", "workers", { open: id })} className="rounded-lg border border-line px-3 py-2 text-sm font-semibold hover:border-brand hover:text-brand-strong">Editar ficha</Link>
        </div>
      </header>

      <div role="tablist" aria-label="Secciones de la ficha" className="flex gap-1 overflow-x-auto border-b border-line">
        {tabs.map((t) => (
          <button key={t.key} role="tab" type="button" aria-selected={current?.key === t.key} onClick={() => setTab(t.key)}
            className={`shrink-0 border-b-2 px-3 py-2 text-sm font-semibold ${current?.key === t.key ? "border-brand text-brand-strong" : "border-transparent text-muted hover:text-fg"}`}>
            {t.label}
          </button>
        ))}
      </div>

      <div role="tabpanel">
        {current?.key === "data" ? (
          <dl className="grid gap-x-6 gap-y-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-2 lg:grid-cols-3">
            {DATA_FIELDS.map((k) => fields.find((f) => f.name === k)).filter((f): f is RecordField => !!f).map((f) => (
              <div key={f.name}>
                <dt className="text-xs text-muted">{f.label}</dt>
                <dd className="text-sm font-medium">{displayValue(f, worker)}</dd>
              </div>
            ))}
          </dl>
        ) : current ? (
          <WorkerTab key={current.key} tab={current} workerId={id} />
        ) : null}
      </div>
    </div>
  );
}

function WorkerTab({ tab, workerId }: { tab: Tab; workerId: string }) {
  const [rows, setRows] = useState<RecordRow[] | null>(null);
  const [total, setTotal] = useState(0);
  const [fields, setFields] = useState<RecordField[]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    Promise.all([schemaOf(tab.module), records.list(tab.module, tab.table, { filters: { worker_id: workerId } })])
      .then(([s, list]) => {
        const res = s.resources.find((x) => x.table === tab.table);
        if (!res) throw new Error("Tu rol no puede ver estos registros.");
        setFields(res.fields);
        setRows(list.data);
        setTotal(list.meta.total);
      })
      .catch((e) => setError(e?.detail ?? e?.message ?? "No disponible para tu rol o tu plan."));
  }, [tab, workerId]);

  const cols = useMemo(() => (tab.columns ?? []).map((c) => fields.find((f) => f.name === c)).filter((f): f is RecordField => !!f), [tab, fields]);
  const all = recordsHref(tab.module, tab.table, { filters: { worker_id: workerId } });

  if (error) return <p className="rounded-xl border border-line bg-surface p-4 text-sm text-muted">{error}</p>;
  if (rows === null) return <div className="h-32 animate-pulse rounded-xl bg-surface" />;
  return (
    <div className="space-y-2">
      <div className="overflow-x-auto rounded-xl border border-line bg-surface">
        {rows.length === 0 ? (
          <p className="p-4 text-sm text-muted">Sin registros de este trabajador.</p>
        ) : (
          <table className="w-full text-left text-sm">
            <thead className="border-b border-line bg-bg text-xs font-semibold text-muted">
              <tr>{cols.map((f) => <th key={f.name} className="px-4 py-2.5">{f.label}</th>)}</tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.id} className="cursor-pointer border-b border-line last:border-0 hover:bg-brand-soft/40" onClick={() => (window.location.href = recordsHref(tab.module, tab.table, { open: row.id }))}>
                  {cols.map((f) => <td key={f.name} className="px-4 py-2.5">{displayValue(f, row)}</td>)}
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
      <p className="flex flex-wrap justify-between gap-2 text-xs text-muted">
        <span>{total} registro(s)</span>
        <Link href={all} className="font-semibold text-brand hover:underline">Ver en el módulo ›</Link>
      </p>
    </div>
  );
}
