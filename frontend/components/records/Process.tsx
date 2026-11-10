"use client";

// Procesos de mejora continua dentro de cada registro:
// - TraceStrip: «Viene de» → este registro → «Generó», con enlaces a cada registro o lista filtrada (cualquier módulo).
// - RecordActions: los pasos propios del proceso según la tabla (acción correctiva, inspección, hallazgo).

import Link from "next/link";
import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { downloadFile, uploadFile, recordsHref, traceOf, type RecordResource, type RecordRow, type TraceGroup, type TraceNode, records } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { RefSelect } from "./ModuleRecords";
import { PsiPanel } from "./PsiPanel";
import { RiskMapEditor } from "./RiskMapEditor";

/** Texto de un filtro que llegó por enlace (chips «Mostrando solo: …»). */
export function describeFilter(res: RecordResource, key: string, value: string): string {
  const [name, op] = key.split("__");
  if (name === "source_id") return "Generados desde el registro de origen";
  const f = res.fields.find((x) => x.name === name);
  const label = f?.label ?? name;
  const val =
    value === "today"
      ? "hoy"
      : f?.type === "enum"
        ? value.split(",").map((v) => f.options?.find((o) => o.value === v)?.label ?? v).join(" o ")
        : f?.type === "ref"
          ? "el registro elegido"
          : /^\d{4}-\d{2}-\d{2}$/.test(value)
            ? `${value.slice(8, 10)}/${value.slice(5, 7)}/${value.slice(0, 4)}`
            : value;
  switch (op) {
    case "lt":
      return `${label} antes de ${val}`;
    case "lte":
      return `${label} hasta ${val}`;
    case "gt":
      return `${label} después de ${val}`;
    case "gte":
      return `${label} desde ${val}`;
    case "null":
      return value === "1" ? `${label} sin completar` : `${label} completado`;
    default:
      return `${label}: ${val}`;
  }
}

/** Registros que ya cerraron su ciclo: se consultan, no se editan (sus pasos siguen en la trazabilidad). */
export function lockedReason(table: string, row: RecordRow): string | null {
  const st = String(row.status ?? "");
  if (table === "inspections" && ["completed", "closed"].includes(st))
    return "Inspección finalizada: no se edita. Sus hallazgos y acciones siguen en la trazabilidad.";
  if (table === "corrective_actions" && ["verified", "cancelled"].includes(st))
    return st === "verified" ? "Acción verificada: queda como registro cerrado." : "Acción anulada: queda como registro cerrado.";
  if (table === "sst_events" && ["closed", "discarded"].includes(st)) return "Evento cerrado: queda como registro.";
  if (table === "committee_meetings" && st === "minutes_signed") return "Acta firmada: la sesión no se modifica.";
  if (table === "ppe_deliveries") return st === "delivered" ? null : "Entrega cerrada: queda como registro.";
  if ((table === "annual_programs" || table === "training_programs") && st === "closed") return "Programa cerrado.";
  if (["sst_policies", "risst_versions", "procedure_versions", "document_versions"].includes(table) && ["current", "obsolete"].includes(st))
    return st === "current" ? "Versión vigente: no se edita. Para cambiarla, crea una nueva versión." : "Versión obsoleta: queda como historial.";
  if (table === "ats_forms" && ["closed", "cancelled"].includes(st)) return "ATS cerrado.";
  return null;
}

export function TraceStrip({ module, table, id, version }: { module: string; table: string; id: string; version: number }) {
  const [trace, setTrace] = useState<{ parents: TraceNode[]; children: TraceGroup[] } | null>(null);

  useEffect(() => {
    traceOf(module, table, id)
      .then((r) => setTrace(r.data))
      .catch(() => setTrace({ parents: [], children: [] }));
  }, [module, table, id, version]);

  if (!trace || (trace.parents.length === 0 && trace.children.length === 0)) return null;

  return (
    <nav aria-label="Trazabilidad" className="rounded-xl border border-brand/20 bg-brand-soft/40 p-3 text-sm">
      <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-brand-strong">Trazabilidad</p>
      <div className="flex flex-wrap items-center gap-2">
        {trace.parents.length > 0 && (
          <>
            <span className="text-xs text-muted">Viene de</span>
            {trace.parents.map((p) => (
              <Link
                key={`${p.table}-${p.id}`}
                href={recordsHref(p.module, p.table, { open: p.id })}
                className="rounded-lg border border-line bg-surface px-2.5 py-1 font-medium shadow-sm hover:border-brand hover:text-brand"
                title={`${p.via} · abrir`}
              >
                <span className="text-xs text-muted">{p.tab}:</span> {p.label || "ver"}
              </Link>
            ))}
            <span aria-hidden="true" className="text-brand">→</span>
          </>
        )}
        <span className="rounded-lg bg-brand px-2.5 py-1 font-semibold text-white">Este registro</span>
        {trace.children.length > 0 && (
          <>
            <span aria-hidden="true" className="text-brand">→</span>
            <span className="text-xs text-muted">Generó</span>
            {trace.children.map((c) => (
              <Link
                key={`${c.table}-${Object.keys(c.filter)[0]}`}
                href={recordsHref(c.module, c.table, { filters: c.filter })}
                className="rounded-lg border border-line bg-surface px-2.5 py-1 font-medium shadow-sm hover:border-brand hover:text-brand"
                title="Ver la lista"
              >
                <b>{c.count}</b> {c.tab.toLowerCase()}
              </Link>
            ))}
          </>
        )}
      </div>
    </nav>
  );
}

export function RecordActions({
  code,
  res,
  row,
  readOnly,
  onChanged,
}: {
  code: string;
  res: RecordResource;
  row: RecordRow;
  readOnly: boolean;
  onChanged: () => void;
}) {
  if (readOnly) return null;
  if (res.table === "corrective_actions") return <CorrectiveFlow row={row} canEdit={res.can.update} onChanged={onChanged} />;
  if (res.table === "inspections") return <InspectionRun row={row} onChanged={onChanged} />;
  if (ACTION_SOURCES[res.table]) return <FindingAction table={res.table} row={row} onChanged={onChanged} />;
  if (res.table === "baseline_assessments") return <BaselineFlow row={row} canEdit={res.can.update} onChanged={onChanged} />;
  if (res.table === "monthly_statistics") return <CloseMonth row={row} onChanged={onChanged} />;
  if (res.table === "inspection_dossiers") return <DossierBuild row={row} onChanged={onChanged} />;
  if (res.table === "api_clients") return <ApiSecret row={row} canEdit={res.can.update} onChanged={onChanged} />;
  if (res.table === "training_certificates")
    return (
      <section className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-line px-4 py-3 text-sm">
        <span>Imprime o guarda en PDF el certificado con su QR. {row.public_verification_enabled ? "La verificación pública está activa." : "Activa «Verificación pública» para que el QR funcione."}</span>
        <a href={`/imprimir/certificado/${row.id}`} target="_blank" rel="noreferrer" className="rounded-lg bg-brand px-4 py-2 font-semibold text-white">Imprimir certificado</a>
      </section>
    );
  if (res.table === "risk_maps")
    return (
      <div className="space-y-3">
        <RiskMapEditor row={row} canEdit={res.can.update} />
        <StepButtons code={code} table={res.table} row={row} canEdit={res.can.update} onChanged={onChanged} />
      </div>
    );
  if (res.table === "psychosocial_surveys")
    return (
      <div className="space-y-3">
        <PsiPanel row={row} />
        <StepButtons code={code} table={res.table} row={row} canEdit={res.can.update} onChanged={onChanged} />
      </div>
    );
  if (STEPS[res.table]) return <StepButtons code={code} table={res.table} row={row} canEdit={res.can.update} onChanged={onChanged} />;
  if (res.table === "event_notifications") return <NotifyNow row={row} canEdit={res.can.update} onChanged={onChanged} />;
  if (res.table === "committee_meetings") return <MeetingFlow row={row} canEdit={res.can.update} onChanged={onChanged} />;
  if (res.table === "annual_programs" || res.table === "training_programs")
    return <ProgramFlow code={code} table={res.table} row={row} canEdit={res.can.update} onChanged={onChanged} />;
  if (res.table === "program_activities") return <ActivityExecution row={row} onChanged={onChanged} />;
  if (res.table === "training_sessions") return <SessionHeld row={row} canEdit={res.can.update} onChanged={onChanged} />;
  if (res.table === "trainings") return <IssueCertificates row={row} onChanged={onChanged} />;
  void code;
  return null;
}

// ─────────────── ACR: ciclo de la acción correctiva ───────────────

const ACR_STEPS: [string, string][] = [
  ["open", "Abierta"],
  ["in_progress", "En curso"],
  ["pending_verification", "Por verificar"],
  ["verified", "Verificada"],
];

function CorrectiveFlow({ row, canEdit, onChanged }: { row: RecordRow; canEdit: boolean; onChanged: () => void }) {
  const status = String(row.status);
  const [evidences, setEvidences] = useState<RecordRow[]>([]);
  const [desc, setDesc] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const [dialog, setDialog] = useState<"verify" | "cancel" | null>(null);
  const [text, setText] = useState("");

  useEffect(() => {
    records.list("ACR", "action_evidences", { filters: { corrective_action_id: row.id } }).then((r) => setEvidences(r.data)).catch(() => setEvidences([]));
  }, [row.id, row.updated_at]);

  const act = async (fn: () => Promise<unknown>) => {
    setBusy(true);
    setMsg(null);
    try {
      await fn();
      setDialog(null);
      setText("");
      onChanged();
    } catch (e) {
      const err = e as ApiError;
      setMsg(Object.values(err.errors ?? {})[0]?.[0] ?? err.detail ?? err.title);
    } finally {
      setBusy(false);
    }
  };
  const move = (body: Record<string, unknown>) => act(() => records.update("ACR", "corrective_actions", row.id, body));
  const today = new Date().toISOString().slice(0, 10);
  const overdue = ["open", "in_progress", "overdue"].includes(status) && String(row.due_on) < today;
  const idx = ACR_STEPS.findIndex(([s]) => s === status);
  const closed = status === "verified" || status === "cancelled";

  return (
    <section aria-label="Proceso de la acción" className="space-y-3 rounded-xl border border-line p-4">
      <ol className="flex flex-wrap items-center gap-2 text-xs font-semibold">
        {ACR_STEPS.map(([s, l], i) => (
          <li key={s} className="flex items-center gap-2">
            <span className={`rounded-full px-3 py-1 ${i < idx || status === "verified" ? "bg-ok-soft text-ok" : i === idx ? "bg-brand text-white" : "bg-bg text-muted"}`}>
              {i + 1}. {l}
            </span>
            {i < ACR_STEPS.length - 1 && <span aria-hidden="true" className="text-muted">→</span>}
          </li>
        ))}
        {status === "cancelled" && <li className="rounded-full bg-bad-soft px-3 py-1 text-bad">Anulada</li>}
        {overdue && <li className="rounded-full bg-bad-soft px-3 py-1 text-bad">Vencida</li>}
      </ol>
      {status === "verified" && (
        <p className="text-sm">{row.effectiveness === "not_effective" ? "Verificada como no eficaz: se abrió una nueva acción con el mismo origen." : "Verificada como eficaz. Su origen quedó atendido."}</p>
      )}

      <div className="space-y-2">
        <p className="text-sm font-semibold">Evidencias ({evidences.length})</p>
        {evidences.map((e) => (
          <p key={e.id} className="rounded-lg bg-bg px-3 py-2 text-sm">
            {String(e.description ?? "Evidencia")} <span className="text-xs text-muted">· {String(e.evidence_type ?? "")}</span>
            {e.file_id ? (
              <button type="button" className="ml-2 text-xs font-semibold text-brand hover:underline" onClick={() => downloadFile(`/files/${e.file_id}`, String(e.file_id__label ?? "evidencia"))}>
                📎 {String(e.file_id__label ?? "archivo")}
              </button>
            ) : null}
          </p>
        ))}
        {!closed && canEdit && (
          <div className="flex gap-2">
            <input className="input" placeholder="Qué se hizo (p. ej. «Se instaló la baranda»)" aria-label="Nueva evidencia" value={desc} onChange={(e) => setDesc(e.target.value)} />
            <input type="file" aria-label="Foto o documento de la evidencia" accept=".pdf,.jpg,.jpeg,.png,.webp" className="input !w-56 text-xs" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            <Button
              variant="soft"
              icon="plus"
              disabled={!desc.trim()}
              loading={busy}
              onClick={() =>
                act(async () => {
                  const up = file ? await uploadFile(file, "ACR") : null;
                  await records.create("ACR", "action_evidences", {
                    corrective_action_id: row.id,
                    evidence_type: up ? (file!.type.startsWith("image/") ? "photo" : "document") : "record",
                    description: desc,
                    ...(up ? { file_id: up.id } : {}),
                  });
                  setDesc("");
                  setFile(null);
                })
              }
            >
              Agregar
            </Button>
          </div>
        )}
      </div>

      {msg && <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{msg}</p>}
      {canEdit && !closed && (
        <div className="flex flex-wrap gap-2">
          {status === "open" && <Button onClick={() => move({ status: "in_progress" })} loading={busy}>Empezar</Button>}
          {["open", "in_progress", "overdue"].includes(status) && (
            <Button variant="primary" onClick={() => move({ status: "pending_verification" })} loading={busy}>
              Enviar a verificación
            </Button>
          )}
          {status === "pending_verification" && (
            <>
              <Button variant="primary" onClick={() => setDialog("verify")}>Verificar</Button>
              <Button onClick={() => move({ status: "in_progress" })} loading={busy}>Devolver: falta completar</Button>
            </>
          )}
          {status !== "pending_verification" && <Button variant="danger-ghost" onClick={() => setDialog("cancel")}>Anular</Button>}
        </div>
      )}

      {dialog === "verify" && (
        <Modal title="Verificar la acción" icon="checklist" size="sm" z={60} onClose={() => setDialog(null)}
          footer={
            <div className="flex w-full flex-wrap justify-end gap-2">
              <Button variant="danger-ghost" loading={busy} onClick={() => move({ status: "verified", effectiveness: "not_effective", verification_notes: text || null })}>
                No fue eficaz
              </Button>
              <Button variant="primary" loading={busy} onClick={() => move({ status: "verified", effectiveness: "effective", verification_notes: text || null })}>
                Fue eficaz
              </Button>
            </div>
          }
        >
          <FormField label="Observaciones de la verificación" hint="Si no fue eficaz, se abre otra acción con el mismo origen.">
            <textarea className="input min-h-[4rem] py-2" value={text} onChange={(e) => setText(e.target.value)} />
          </FormField>
          {msg && <p className="mt-2 text-sm text-bad">{msg}</p>}
        </Modal>
      )}
      {dialog === "cancel" && (
        <Modal title="Anular la acción" icon="close" size="sm" z={60} onClose={() => setDialog(null)}
          footer={
            <div className="flex w-full justify-end gap-2">
              <Button onClick={() => setDialog(null)}>Volver</Button>
              <Button variant="danger" disabled={!text.trim()} loading={busy} onClick={() => move({ status: "cancelled", cancel_reason: text })}>
                Anular
              </Button>
            </div>
          }
        >
          <FormField label="Motivo">
            <textarea autoFocus className="input min-h-[4rem] py-2" value={text} onChange={(e) => setText(e.target.value)} />
          </FormField>
          {msg && <p className="mt-2 text-sm text-bad">{msg}</p>}
        </Modal>
      )}
    </section>
  );
}

// ─────────────── INS: ejecutar el checklist ───────────────

type Item = {
  id: string;
  section: string | null;
  item_code: string;
  question: string;
  answer_type: string;
  weight: number;
  is_critical: boolean;
  answer: string | null;
  comment: string | null;
};
type Checklist = {
  inspection: { status: string; score_pct: number | null; template_name: string | null };
  items: Item[];
  findings: { id: string; description: string; severity: string; status: string; corrective_action_id: string | null; action_code: string | null }[];
  editable: boolean;
};

const CHOICES: Record<string, [string, string][]> = {
  yes_no_na: [["yes", "Sí"], ["no", "No"], ["na", "N/A"]],
  compliant_noncompliant: [["compliant", "Cumple"], ["noncompliant", "No cumple"], ["na", "N/A"]],
  scale_1_5: [["1", "1"], ["2", "2"], ["3", "3"], ["4", "4"], ["5", "5"]],
};

function InspectionRun({ row, onChanged }: { row: RecordRow; onChanged: () => void }) {
  const [open, setOpen] = useState(false);
  const [data, setData] = useState<Checklist | null>(null);
  const [answers, setAnswers] = useState<Record<string, { answer: string; comment: string }>>({});
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  const load = () =>
    api<{ data: Checklist }>(`/ins/inspections/${row.id}/checklist`, { withCompany: true }).then((r) => {
      setData(r.data);
      setAnswers(Object.fromEntries(r.data.items.map((i) => [i.id, { answer: i.answer ?? "", comment: i.comment ?? "" }])));
    });
  useEffect(() => {
    load().catch(() => setData(null));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [row.id, row.updated_at]);

  if (!data || data.items.length === 0) return null;
  const answered = data.items.filter((i) => answers[i.id]?.answer).length;

  const save = async (finish: boolean) => {
    setBusy(true);
    setMsg(null);
    try {
      const list = Object.entries(answers).filter(([, a]) => a.answer).map(([item_id, a]) => ({ item_id, answer: a.answer, comment: a.comment || null }));
      if (list.length) await api(`/ins/inspections/${row.id}/answers`, { method: "POST", body: { answers: list }, withCompany: true });
      if (finish) {
        const r = await api<{ data: { score_pct: number | null; findings_created: number; critical: boolean } }>(`/ins/inspections/${row.id}/finish`, { method: "POST", body: {}, withCompany: true });
        setMsg(`Finalizada: ${r.data.score_pct ?? "—"} % · ${r.data.findings_created} hallazgo(s)${r.data.critical ? " · incluye un ítem crítico" : ""}.`);
        setOpen(false);
      }
      await load();
      onChanged();
    } catch (e) {
      setMsg((e as ApiError).detail ?? (e as ApiError).title);
    } finally {
      setBusy(false);
    }
  };

  return (
    <section aria-label="Checklist" className="space-y-2 rounded-xl border border-line p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm">
          <b>Checklist:</b> {answered} de {data.items.length} respondidos
          {data.inspection.score_pct !== null && <> · <b>Puntaje {data.inspection.score_pct} %</b></>}
          {data.findings.length > 0 && <> · {data.findings.length} hallazgo(s)</>}
        </p>
        <Button variant={data.editable ? "primary" : "secondary"} icon="checklist" onClick={() => setOpen(true)}>
          {data.editable ? "Ejecutar checklist" : "Ver respuestas"}
        </Button>
      </div>
      {msg && <p className="text-sm text-brand-strong">{msg}</p>}
      {open && (
        <Modal
          title="Checklist de la inspección"
          subtitle={`${data.inspection.template_name ?? ""} · Puntaje = ítems que cumplen / ítems aplicables (los N/A no cuentan). Cada «No» crea un hallazgo.`}
          icon="checklist"
          size="lg"
          z={60}
          onClose={() => setOpen(false)}
          footer={
            data.editable ? (
              <div className="flex w-full flex-wrap items-center justify-between gap-2">
                <span className="text-sm text-muted">{answered} de {data.items.length} respondidos</span>
                <div className="flex gap-2">
                  <Button loading={busy} onClick={() => save(false)}>Guardar avance</Button>
                  <Button variant="primary" loading={busy} disabled={answered < data.items.length} onClick={() => save(true)}>
                    Finalizar inspección
                  </Button>
                </div>
              </div>
            ) : undefined
          }
        >
          <div className="space-y-3">
            {msg && <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{msg}</p>}
            {data.items.map((it, n) => (
              <div key={it.id} className={`space-y-2 rounded-lg border p-3 ${it.is_critical ? "border-bad/40" : "border-line"}`}>
                <p className="text-sm font-medium">
                  {n + 1}. {it.question} {it.is_critical && <span className="text-xs font-semibold text-bad">(crítico)</span>}
                </p>
                {CHOICES[it.answer_type] ? (
                  <div className="flex flex-wrap gap-2" role="radiogroup" aria-label={it.question}>
                    {CHOICES[it.answer_type].map(([v, l]) => {
                      const on = answers[it.id]?.answer === v;
                      return (
                        <button
                          key={v}
                          role="radio"
                          aria-checked={on}
                          disabled={!data.editable}
                          onClick={() => setAnswers((x) => ({ ...x, [it.id]: { ...x[it.id], answer: v } }))}
                          className={`rounded-lg border px-3 py-1.5 text-sm font-semibold ${on ? (v === "no" || v === "noncompliant" ? "border-bad bg-bad-soft text-bad" : "border-brand bg-brand-soft text-brand-strong") : "border-line"}`}
                        >
                          {l}
                        </button>
                      );
                    })}
                  </div>
                ) : (
                  <input className="input" disabled={!data.editable} aria-label={it.question} value={answers[it.id]?.answer ?? ""}
                    onChange={(e) => setAnswers((x) => ({ ...x, [it.id]: { ...x[it.id], answer: e.target.value } }))} />
                )}
                <input className="input !h-9 text-sm" placeholder="Comentario (opcional)" aria-label={`Comentario de ${it.question}`} disabled={!data.editable}
                  value={answers[it.id]?.comment ?? ""} onChange={(e) => setAnswers((x) => ({ ...x, [it.id]: { ...x[it.id], comment: e.target.value } }))} />
              </div>
            ))}
          </div>
        </Modal>
      )}
    </section>
  );
}

// ─────────────── INS: del hallazgo a la acción correctiva ───────────────

/** Registros desde los que se crea una acción correctiva (su origen queda en la trazabilidad). */
const ACTION_SOURCES: Record<string, string> = {
  findings: "Este hallazgo",
  investigation_causes: "Esta causa",
  meeting_agreements: "Este acuerdo",
  baseline_gaps: "Esta brecha",
  substandard_reports: "Este reporte",
  requirement_evaluations: "Esta evaluación",
  audit_findings: "Este hallazgo de auditoría",
  management_review_outputs: "Esta salida de la revisión",
  measurements: "Esta medición",
  behavior_observations: "Esta observación",
  change_requests: "Este cambio",
};

function FindingAction({ table, row, onChanged }: { table: string; row: RecordRow; onChanged: () => void }) {
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ title: "", responsible_user_id: "", due_on: "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);

  if (row.corrective_action_id || ["dismissed", "cancelled", "rejected", "closed"].includes(String(row.status))) return null;
  if (table === "requirement_evaluations" && row.result === "compliant") return null;

  const create = async () => {
    setBusy(true);
    setErrors({});
    try {
      await api(`/acr/from/${table}/${row.id}`, { method: "POST", body: form, withCompany: true });
      setOpen(false);
      onChanged();
    } catch (e) {
      setErrors((e as ApiError).errors ?? { title: [(e as ApiError).detail ?? "Error"] });
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-ink/30 bg-amber-soft px-4 py-3 text-sm text-amber-ink">
      <span>{ACTION_SOURCES[table]} aún no tiene acción correctiva.</span>
      <Button variant="primary" icon="plus" onClick={() => setOpen(true)}>Crear acción correctiva</Button>
      {open && (
        <Modal title="Nueva acción correctiva" subtitle="Queda vinculada a este registro (trazabilidad)." icon="plus" size="sm" z={60} onClose={() => setOpen(false)}
          footer={
            <div className="flex w-full justify-end gap-2">
              <Button onClick={() => setOpen(false)}>Cancelar</Button>
              <Button variant="primary" loading={busy} onClick={create}>Crear</Button>
            </div>
          }
        >
          <div className="space-y-3">
            <FormField label="Qué se va a hacer *" error={errors.title?.[0]}>
              <input className="input" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
            </FormField>
            <FormField label="Responsable *" error={errors.responsible_user_id?.[0]}>
              <RefSelect code="ACR" table="corrective_actions" f={{ name: "responsible_user_id", label: "Responsable", type: "ref", ref: "users" }}
                value={form.responsible_user_id} disabled={false} invalid={!!errors.responsible_user_id} onChange={(v) => setForm({ ...form, responsible_user_id: (v as string) ?? "" })} />
            </FormField>
            <FormField label="Fecha meta *" error={errors.due_on?.[0]}>
              <input className="input" type="date" value={form.due_on} onChange={(e) => setForm({ ...form, due_on: e.target.value })} />
            </FormField>
          </div>
        </Modal>
      )}
    </section>
  );
}


// ─────────────── PAS / PCA: aprobación del programa ───────────────

const PROGRAM_STEPS: [string, string][] = [
  ["draft", "Borrador"],
  ["committee_review", "Revisión del comité"],
  ["approved", "Aprobado"],
  ["in_execution", "En ejecución"],
  ["closed", "Cerrado"],
];

function ProgramFlow({ code, table, row, canEdit, onChanged }: { code: string; table: string; row: RecordRow; canEdit: boolean; onChanged: () => void }) {
  const status = String(row.status);
  const idx = PROGRAM_STEPS.findIndex(([s]) => s === status);
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const move = async (to: string) => {
    setBusy(true);
    setMsg(null);
    try {
      await records.update(code, table, row.id, { status: to });
      onChanged();
    } catch (e) {
      const err = e as ApiError;
      setMsg(Object.values(err.errors ?? {})[0]?.[0] ?? err.detail ?? err.title);
    } finally {
      setBusy(false);
    }
  };
  const isPas = table === "annual_programs";
  return (
    <section aria-label="Proceso del programa" className="space-y-3 rounded-xl border border-line p-4">
      <ol className="flex flex-wrap items-center gap-2 text-xs font-semibold">
        {PROGRAM_STEPS.map(([s, l], i) => (
          <li key={s} className="flex items-center gap-2">
            <span className={`rounded-full px-3 py-1 ${i < idx ? "bg-ok-soft text-ok" : i === idx ? "bg-brand text-white" : "bg-bg text-muted"}`}>{l}</span>
            {i < PROGRAM_STEPS.length - 1 && <span aria-hidden="true" className="text-muted">→</span>}
          </li>
        ))}
      </ol>
      {isPas && row.progress_pct !== undefined && row.progress_pct !== null && (
        <div>
          <p className="text-sm">Avance del programa: <b>{String(row.progress_pct)} %</b></p>
          <div className="mt-1 h-2 rounded-full bg-bg"><div className="h-2 rounded-full bg-brand" style={{ width: `${Math.min(100, Number(row.progress_pct))}%` }} /></div>
        </div>
      )}
      <p className="text-xs text-muted">
        {status === "draft" || status === "committee_review"
          ? `Agrega ${isPas ? "las actividades" : "los temas"} y envíalo a aprobación. Una vez aprobado, ${isPas ? "sus actividades" : "sus temas"} ya no cambian.`
          : status === "closed"
            ? "Programa cerrado."
            : isPas
              ? "Aprobado: registra la ejecución desde cada actividad; el avance se calcula solo."
              : "Aprobado: cada tema se marca ejecutado cuando su capacitación tiene una sesión realizada."}
      </p>
      {msg && <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{msg}</p>}
      {canEdit && (
        <div className="flex flex-wrap gap-2">
          {status === "draft" && <Button loading={busy} onClick={() => move("committee_review")}>Enviar al comité</Button>}
          {(status === "draft" || status === "committee_review") && <Button variant="primary" loading={busy} onClick={() => move("approved")}>Aprobar</Button>}
          {status === "committee_review" && <Button loading={busy} onClick={() => move("draft")}>Devolver a borrador</Button>}
          {status === "approved" && <Button variant="primary" loading={busy} onClick={() => move("in_execution")}>Iniciar ejecución</Button>}
          {(status === "approved" || status === "in_execution") && <Button loading={busy} onClick={() => move("closed")}>Cerrar programa</Button>}
        </div>
      )}
    </section>
  );
}

// ─────────────── PAS: registrar la ejecución de una actividad ───────────────

function ActivityExecution({ row, onChanged }: { row: RecordRow; onChanged: () => void }) {
  const [form, setForm] = useState({ executed_on: new Date().toISOString().slice(0, 10), quantity: "1", description: "" });
  const [list, setList] = useState<RecordRow[]>([]);
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    records.list("PAS", "activity_executions", { filters: { activity_id: row.id } }).then((r) => setList(r.data)).catch(() => setList([]));
  }, [row.id, row.updated_at]);
  const add = async () => {
    setBusy(true);
    setMsg(null);
    try {
      await records.create("PAS", "activity_executions", { activity_id: row.id, executed_on: form.executed_on, quantity: Number(form.quantity), description: form.description });
      setForm({ ...form, description: "" });
      onChanged();
    } catch (e) {
      const err = e as ApiError;
      setMsg(Object.values(err.errors ?? {})[0]?.[0] ?? err.detail ?? err.title);
    } finally {
      setBusy(false);
    }
  };
  return (
    <section aria-label="Ejecución" className="space-y-3 rounded-xl border border-line p-4">
      <p className="text-sm">
        Avance: <b>{String(row.executed_quantity ?? 0)}</b> de <b>{String(row.goal_quantity ?? 1)}</b> ({String(row.progress_pct ?? 0)} %)
      </p>
      <div className="h-2 rounded-full bg-bg"><div className="h-2 rounded-full bg-brand" style={{ width: `${Math.min(100, Number(row.progress_pct ?? 0))}%` }} /></div>
      {list.map((e) => (
        <p key={e.id} className="rounded-lg bg-bg px-3 py-2 text-sm">
          {String(e.executed_on ?? "").slice(0, 10)} · {String(e.quantity ?? "")} · {String(e.description ?? "")}
        </p>
      ))}
      <div className="grid gap-2 sm:grid-cols-[9rem_6rem_1fr_auto]">
        <input className="input" type="date" aria-label="Fecha de ejecución" value={form.executed_on} onChange={(e) => setForm({ ...form, executed_on: e.target.value })} />
        <input className="input" type="number" min={1} aria-label="Cantidad" value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} />
        <input className="input" placeholder="Evidencia (acta, informe, registro)" aria-label="Evidencia" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        <Button variant="primary" loading={busy} onClick={add}>Registrar ejecución</Button>
      </div>
      {msg && <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{msg}</p>}
    </section>
  );
}

// ─────────────── CAP: sesión realizada y certificados ───────────────

function SessionHeld({ row, canEdit, onChanged }: { row: RecordRow; canEdit: boolean; onChanged: () => void }) {
  const [msg, setMsg] = useState<string | null>(null);
  if (!canEdit || row.status !== "scheduled") return null;
  return (
    <section className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-line px-4 py-3 text-sm">
      <span>Cuando termine la sesión y tengas las asistencias, márcala como realizada: el tema del programa queda ejecutado.</span>
      <Button
        variant="primary"
        onClick={() =>
          records.update("CAP", "training_sessions", row.id, { status: "held" }).then(onChanged).catch((e: ApiError) => setMsg(Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title))
        }
      >
        Marcar como realizada
      </Button>
      {msg && <p role="alert" className="w-full text-bad">{msg}</p>}
    </section>
  );
}

function IssueCertificates({ row, onChanged }: { row: RecordRow; onChanged: () => void }) {
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const issue = async () => {
    setBusy(true);
    setMsg(null);
    try {
      const r = await api<{ data: { issued: number; skipped: string[] } }>(`/cap/trainings/${row.id}/certificates`, { method: "POST", withCompany: true });
      setMsg(`${r.data.issued} certificado(s) emitido(s).${r.data.skipped.length ? ` Sin certificado: ${r.data.skipped.join(", ")}.` : ""}`);
      onChanged();
    } catch (e) {
      setMsg((e as ApiError).detail ?? (e as ApiError).title);
    } finally {
      setBusy(false);
    }
  };
  return (
    <section className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-line px-4 py-3 text-sm">
      <span>Emite un certificado a cada asistente de una sesión realizada (y con evaluación aprobada si la capacitación exige nota).</span>
      <Button variant="primary" loading={busy} onClick={issue}>Emitir certificados</Button>
      {msg && <p className="w-full text-brand-strong">{msg}</p>}
    </section>
  );
}


// ─────────────── ACC: plazo de notificación ───────────────

function EventDeadline({ row }: { row: RecordRow }) {
  const deadline = row.notification_deadline_at ? new Date(String(row.notification_deadline_at)) : null;
  if (!deadline || ["closed", "discarded"].includes(String(row.status))) return null;
  const hours = Math.round((deadline.getTime() - Date.now()) / 3600000);
  const late = hours < 0;
  return (
    <section className={`rounded-xl px-4 py-3 text-sm ${late ? "bg-bad-soft text-bad" : hours <= 24 ? "bg-amber-soft text-amber-ink" : "bg-bg"}`} role="status">
      <b>Plazo de notificación:</b> {deadline.toLocaleString("es-PE", { dateStyle: "medium", timeStyle: "short" })}
      {" · "}
      {late ? "vencido" : hours < 48 ? `quedan ${hours} h` : `quedan ${Math.floor(hours / 24)} días`}. Regístrala en «Notificaciones» (está en la trazabilidad).
    </section>
  );
}

function NotifyNow({ row, canEdit, onChanged }: { row: RecordRow; canEdit: boolean; onChanged: () => void }) {
  const [number, setNumber] = useState("");
  const [msg, setMsg] = useState<string | null>(null);
  if (row.status !== "pending" || !canEdit) {
    return row.notification_overdue ? <p className="rounded-xl bg-bad-soft px-4 py-3 text-sm text-bad">Notificada fuera de plazo.</p> : null;
  }
  return (
    <section className="flex flex-wrap items-center gap-2 rounded-xl border border-line px-4 py-3 text-sm">
      <span className="flex-1">¿Ya la notificaste? Regístrala con su número de constancia; si es después del plazo quedará «fuera de plazo».</span>
      <input className="input !w-48" placeholder="N.° de constancia" aria-label="Número de constancia" value={number} onChange={(e) => setNumber(e.target.value)} />
      <Button
        variant="primary"
        onClick={() =>
          records
            .update("ACC", "event_notifications", row.id, { status: "sent", notification_number: number || null, notified_at: new Date().toISOString() })
            .then(onChanged)
            .catch((e: ApiError) => setMsg(e.detail ?? e.title))
        }
      >
        Registrar notificación
      </Button>
      {msg && <p className="w-full text-bad">{msg}</p>}
    </section>
  );
}

// ─────────────── COM: sesión y acta ───────────────

function MeetingFlow({ row, canEdit, onChanged }: { row: RecordRow; canEdit: boolean; onChanged: () => void }) {
  const [msg, setMsg] = useState<string | null>(null);
  const st = String(row.status);
  const move = (body: Record<string, unknown>) =>
    records.update("COM", "committee_meetings", row.id, body).then(onChanged).catch((e: ApiError) => setMsg(Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title));
  if (!canEdit || ["minutes_signed", "cancelled"].includes(st)) {
    return st === "minutes_signed" ? <p className="rounded-xl bg-ok-soft px-4 py-3 text-sm text-ok">Acta firmada: la sesión queda cerrada.</p> : null;
  }
  return (
    <section className="space-y-2 rounded-xl border border-line px-4 py-3 text-sm">
      <p>
        Sesión N.° {String(row.number ?? "")} · {row.quorum_reached ? "con quórum: puede adoptar acuerdos" : "sin quórum marcado: no se registran acuerdos"}.
      </p>
      <div className="flex flex-wrap gap-2">
        {st === "scheduled" && (
          <>
            <Button onClick={() => move({ status: "held", quorum_reached: true })}>Realizada con quórum</Button>
            <Button onClick={() => move({ status: "held", quorum_reached: false })}>Realizada sin quórum</Button>
          </>
        )}
        {(st === "held" || st === "minutes_draft") && <Button variant="primary" onClick={() => move({ status: "minutes_signed" })}>Firmar acta</Button>}
      </div>
      {msg && <p className="text-bad">{msg}</p>}
    </section>
  );
}


/** Tablas cuyos registros los crea un flujo (no el botón «Nuevo»). */
export const NO_CREATE = new Set(["monthly_statistics", "training_certificates", "iperc_versions", "event_notifications", "investigations", "register_exports"]);

/** Acciones de la lista completa (arriba, junto a «Nuevo»). */
export function PanelActions({ table, onDone }: { table: string; onDone: () => void }) {
  const now = new Date();
  const [period, setPeriod] = useState(`${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`);
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  if (table === "register_exports") return <RegisterDownload onDone={onDone} />;
  if (table !== "monthly_statistics") return null;
  const run = async () => {
    setBusy(true);
    setMsg(null);
    try {
      const [y, m] = period.split("-").map(Number);
      await api(`/est/months`, { method: "POST", body: { year: y, month: m }, withCompany: true });
      setMsg("Mes calculado con las horas-hombre y los eventos registrados.");
      onDone();
    } catch (e) {
      setMsg((e as ApiError).detail ?? (e as ApiError).title);
    } finally {
      setBusy(false);
    }
  };
  return (
    <div className="flex flex-wrap items-center gap-2">
      <input className="input !w-40" type="month" aria-label="Mes a calcular" value={period} onChange={(e) => setPeriod(e.target.value)} />
      <Button variant="primary" loading={busy} onClick={run}>Calcular mes</Button>
      {msg && <span className="text-sm text-muted">{msg}</span>}
    </div>
  );
}

// ─────────────── LBA: diagnóstico ───────────────

function BaselineFlow({ row, canEdit, onChanged }: { row: RecordRow; canEdit: boolean; onChanged: () => void }) {
  const [msg, setMsg] = useState<string | null>(null);
  const st = String(row.status);
  const done = st === "approved" || st === "archived";
  const act = (fn: () => Promise<unknown>, ok?: string) =>
    fn()
      .then(() => {
        if (ok) setMsg(ok);
        onChanged();
      })
      .catch((e: ApiError) => setMsg(Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title));
  return (
    <section className="space-y-2 rounded-xl border border-line px-4 py-3 text-sm">
      <p>
        Puntaje: <b>{row.score_pct === null || row.score_pct === undefined ? "—" : `${String(row.score_pct)} %`}</b> · {String(row.compliant_items ?? 0)} de{" "}
        {String(row.total_items ?? 0)} ítems aplicables cumplen (parcial cuenta 0,5; «no aplica» no cuenta).
      </p>
      {!done && canEdit && (
        <>
          <p className="text-xs text-muted">Responde cada ítem en «Ítems». Al aprobar se crean las brechas de los ítems parciales o que no cumplen.</p>
          <div className="flex flex-wrap gap-2">
            <Button
              onClick={() =>
                act(async () => {
                  const r = await api<{ data: { added: number } }>(`/lba/assessments/${row.id}/load`, { method: "POST", withCompany: true });
                  setMsg(`${r.data.added} pregunta(s) base cargada(s). Complétalas con tu lista de verificación (Anexo 3).`);
                })
              }
            >
              Cargar preguntas base
            </Button>
            <Button variant="primary" onClick={() => act(() => records.update("LBA", "baseline_assessments", row.id, { status: "approved" }), "Diagnóstico aprobado: se generaron las brechas.")}>
              Aprobar diagnóstico
            </Button>
          </div>
        </>
      )}
      {msg && <p className="text-brand-strong">{msg}</p>}
    </section>
  );
}

// ─────────────── EST: cerrar el mes ───────────────

function CloseMonth({ row, onChanged }: { row: RecordRow; onChanged: () => void }) {
  const [msg, setMsg] = useState<string | null>(null);
  if (row.is_closed) return <p className="rounded-xl bg-ok-soft px-4 py-3 text-sm text-ok">Mes cerrado: sus cifras ya no cambian.</p>;
  return (
    <section className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-line px-4 py-3 text-sm">
      <span>Cierra el mes cuando todas las sedes tengan sus horas-hombre. Cerrado, no se recalcula.</span>
      <Button
        variant="primary"
        onClick={() =>
          api(`/est/months/${row.id}/close`, { method: "POST", withCompany: true })
            .then(onChanged)
            .catch((e: ApiError) => setMsg(e.detail ?? e.title))
        }
      >
        Cerrar mes
      </Button>
      {msg && <p role="alert" className="w-full text-bad">{msg}</p>}
    </section>
  );
}


// ─────────────── Pasos por estado (documentos con versiones y registros de campo) ───────────────

type Step = { from: string[]; label: string; body: Record<string, unknown>; primary?: boolean; ask?: string };

const VERSION_STEPS: Step[] = [
  { from: ["draft"], label: "Enviar a revisión", body: { status: "in_review" } },
  { from: ["draft", "in_review"], label: "Aprobar", body: { status: "approved" }, primary: true },
  { from: ["in_review", "approved"], label: "Devolver a borrador", body: { status: "draft" } },
  { from: ["approved"], label: "Poner vigente", body: { status: "current" }, primary: true },
];

/** Por tabla: el texto que explica el ciclo y los botones disponibles en cada estado. */
const STEPS: Record<string, { help: string; steps: Step[] }> = {
  sst_policies: { help: "Borrador → revisión → aprobada (por otra persona) → vigente. Al ponerla vigente, la anterior queda obsoleta.", steps: VERSION_STEPS },
  risst_versions: { help: "Con Comité, la aprobación pide la sesión. Al ponerla vigente se entrega a los trabajadores desde «Entregas».", steps: VERSION_STEPS },
  procedure_versions: { help: "La versión vigente queda como la actual del procedimiento.", steps: VERSION_STEPS },
  document_versions: { help: "La versión vigente queda como la actual del documento; los trabajadores toman conocimiento de ella.", steps: VERSION_STEPS },
  risk_maps: { help: "Una versión vigente por sede: al poner vigente la nueva, la anterior queda obsoleta.", steps: VERSION_STEPS },
  signature_requests: {
    help: "Solo la persona a quien se pidió la firma puede firmar o rechazar (con motivo).",
    steps: [
      { from: ["pending"], label: "Firmar", body: { status: "signed" }, primary: true },
      { from: ["pending"], label: "Rechazar", body: { status: "declined" }, ask: "declined_reason" },
    ],
  },
  substandard_reports: {
    help: "Recibido → revisado (con nivel de riesgo) → acción asignada o descartado (con motivo) → cerrado.",
    steps: [
      { from: ["received"], label: "Marcar revisado", body: { status: "triaged" }, primary: true },
      { from: ["received", "triaged"], label: "Descartar", body: { status: "rejected" }, ask: "rejection_reason" },
      { from: ["action_assigned", "triaged"], label: "Cerrar", body: { status: "closed" } },
    ],
  },
  safety_talks: {
    help: "Registra la asistencia en «Asistencias» y luego márcala como realizada.",
    steps: [{ from: ["scheduled"], label: "Marcar realizada", body: { status: "held" }, primary: true }],
  },
  ats_forms: {
    help: "Completa pasos (peligros y controles) y participantes; luego firma, ejecuta y cierra.",
    steps: [
      { from: ["draft"], label: "Firmar ATS", body: { status: "signed" }, primary: true },
      { from: ["signed"], label: "Iniciar trabajo", body: { status: "in_execution" }, primary: true },
      { from: ["in_execution"], label: "Cerrar ATS", body: { status: "closed" } },
    ],
  },
  drills: {
    help: "Planificado → ejecutado → evaluado (con resultado). Si está vinculado a una actividad del PASST, su ejecución se registra sola.",
    steps: [
      { from: ["planned"], label: "Marcar ejecutado", body: { status: "executed" }, primary: true },
      { from: ["executed"], label: "Evaluar: satisfactorio", body: { status: "evaluated", result: "satisfactory" }, primary: true },
      { from: ["executed"], label: "Evaluar: por mejorar", body: { status: "evaluated", result: "needs_improvement" } },
    ],
  },
  investigations: {
    help: "Registra las causas y sus acciones; apruébala y ciérrala cuando las acciones estén verificadas.",
    steps: [
      { from: ["open", "analysis", "committee_review"], label: "Aprobar investigación", body: { status: "approved" }, primary: true },
      { from: ["approved", "actions_in_progress"], label: "Cerrar investigación", body: { status: "closed" } },
    ],
  },
  sst_events: {
    help: "El evento se cierra cuando su investigación está cerrada, tiene afectados y no quedan notificaciones pendientes.",
    steps: [
      { from: ["reported", "registered", "notified", "under_investigation"], label: "Cerrar evento", body: { status: "closed" }, primary: true },
      { from: ["reported", "registered"], label: "Descartar", body: { status: "discarded" }, ask: "discarded_reason" },
    ],
  },
  annual_plans: {
    help: "Borrador → revisión → aprobado (con la sesión del comité si la empresa lo tiene) → vigente → cerrado. Aprobado, sus ítems no cambian.",
    steps: [
      { from: ["draft"], label: "Enviar a revisión", body: { status: "in_review" } },
      { from: ["draft", "in_review"], label: "Aprobar", body: { status: "approved" }, primary: true },
      { from: ["approved"], label: "Poner vigente", body: { status: "current" }, primary: true },
      { from: ["current"], label: "Cerrar plan", body: { status: "closed" } },
    ],
  },
  audits: {
    help: "Registra los hallazgos y sus acciones; complétala con conclusiones y ciérrala cuando los hallazgos estén atendidos.",
    steps: [
      { from: ["planned"], label: "Iniciar auditoría", body: { status: "in_progress" }, primary: true },
      { from: ["in_progress", "report_draft"], label: "Completar", body: { status: "completed" }, primary: true },
      { from: ["completed"], label: "Cerrar", body: { status: "closed" } },
    ],
  },
  psychosocial_surveys: {
    help: "Borrador → abierta (los trabajadores responden con el enlace) → cerrada (se calculan los resultados) → informada.",
    steps: [
      { from: ["draft"], label: "Abrir encuesta", body: { status: "open" }, primary: true },
      { from: ["open"], label: "Cerrar y calcular resultados", body: { status: "closed" }, primary: true },
      { from: ["closed"], label: "Marcar como informada", body: { status: "reported" }, primary: true },
      { from: ["closed"], label: "Reabrir", body: { status: "open" } },
    ],
  },
  management_reviews: {
    help: "Registra entradas, conclusiones y salidas (decisiones); cada salida puede generar su acción correctiva.",
    steps: [
      { from: ["planned"], label: "Iniciar", body: { status: "in_progress" }, primary: true },
      { from: ["in_progress"], label: "Completar", body: { status: "completed" }, primary: true },
      { from: ["completed"], label: "Cerrar", body: { status: "closed" } },
    ],
  },
  change_requests: {
    help: "Borrador → enviado → en revisión → aprobado (con una revisión que lo apruebe) → implementado (con su evaluación de riesgos) → cerrado.",
    steps: [
      { from: ["draft"], label: "Enviar", body: { status: "submitted" }, primary: true },
      { from: ["submitted"], label: "Pasar a revisión", body: { status: "under_review" } },
      { from: ["under_review"], label: "Aprobar", body: { status: "approved" }, primary: true },
      { from: ["under_review"], label: "Rechazar", body: { status: "rejected" } },
      { from: ["approved"], label: "Marcar implementado", body: { status: "implemented" }, primary: true },
      { from: ["implemented"], label: "Cerrar", body: { status: "closed" } },
    ],
  },
  work_permits: {
    help: "Borrador → solicitado → aprobado (sin aprobaciones pendientes ni verificaciones previas en «No») → activo (vigente y con ATS si el tipo lo exige) → cerrado con el área segura.",
    steps: [
      { from: ["draft"], label: "Solicitar aprobación", body: { status: "pending_approval" }, primary: true },
      { from: ["pending_approval"], label: "Aprobar", body: { status: "approved" }, primary: true },
      { from: ["pending_approval", "approved"], label: "Rechazar", body: { status: "rejected" } },
      { from: ["approved", "suspended"], label: "Activar trabajo", body: { status: "active" }, primary: true },
      { from: ["active"], label: "Suspender", body: { status: "suspended" }, ask: "suspended_reason" },
      { from: ["active", "suspended"], label: "Cerrar (área segura)", body: { status: "closed", area_left_safe: true } },
    ],
  },
  contractor_accreditations: {
    help: "Se acredita solo con todos los requisitos obligatorios aprobados y vigentes; vence con el primer documento que venza.",
    steps: [
      { from: ["draft", "observed"], label: "Enviar a revisión", body: { status: "submitted" } },
      { from: ["submitted", "under_review", "observed"], label: "Acreditar", body: { status: "accredited" }, primary: true },
      { from: ["submitted", "under_review"], label: "Observar", body: { status: "observed" }, ask: "observations" },
      { from: ["accredited", "expiring"], label: "Suspender", body: { status: "suspended" }, ask: "suspended_reason" },
    ],
  },
  contractor_documents: {
    help: "Revisa el documento: apruébalo (si no está vencido) u obsérvalo con comentarios.",
    steps: [
      { from: ["pending", "submitted", "observed"], label: "Aprobar", body: { status: "approved" }, primary: true },
      { from: ["pending", "submitted"], label: "Observar", body: { status: "observed" }, ask: "review_comments" },
    ],
  },
  budgets: {
    help: "Aprobado, se registran los gastos en «Ejecuciones»; los totales se calculan solos.",
    steps: [
      { from: ["draft", "in_review"], label: "Aprobar", body: { status: "approved" }, primary: true },
      { from: ["approved", "in_execution"], label: "Cerrar", body: { status: "closed" } },
    ],
  },
  credentials: {
    help: "Se evalúa contra los requisitos de su tipo (aptitud médica, capacitación, SCTR, acreditación): activo si cumple todo, bloqueado con los motivos si falta algo.",
    steps: [
      { from: ["draft", "issued", "active", "blocked", "expired"], label: "Reevaluar requisitos", body: {}, primary: true },
      { from: ["draft", "issued", "active", "blocked"], label: "Revocar", body: { status: "revoked" }, ask: "revoked_reason" },
    ],
  },
  medical_exam_orders: {
    help: "Programado → atendido → resultados recibidos → aptitud emitida (al registrar la aptitud del trabajador).",
    steps: [
      { from: ["scheduled"], label: "Marcar atendido", body: { status: "attended" }, primary: true },
      { from: ["attended"], label: "Resultados recibidos", body: { status: "results_received" } },
      { from: ["scheduled"], label: "Anular", body: { status: "cancelled" }, ask: "cancel_reason" },
    ],
  },
  committees: {
    help: "Para activarlo, los titulares deben ser pares y mitad por cada lado.",
    steps: [{ from: ["forming"], label: "Activar comité", body: { status: "active" }, primary: true }],
  },
};

function StepButtons({ code, table, row, canEdit, onChanged }: { code: string; table: string; row: RecordRow; canEdit: boolean; onChanged: () => void }) {
  const cfg = STEPS[table];
  const st = String(row.status ?? "");
  const [msg, setMsg] = useState<string | null>(null);
  const [asking, setAsking] = useState<Step | null>(null);
  const [text, setText] = useState("");
  const options = cfg.steps.filter((x) => x.from.includes(st));
  const run = (x: Step, extra: Record<string, unknown> = {}) =>
    records
      .update(code, table, row.id, { ...x.body, ...extra })
      .then(() => {
        setAsking(null);
        setText("");
        setMsg(null);
        onChanged();
      })
      .catch((e: ApiError) => setMsg(Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title));
  // La sección EventDeadline del evento se muestra junto a los pasos.
  return (
    <>
      {table === "sst_events" && <EventDeadline row={row} />}
      <section className="space-y-2 rounded-xl border border-line px-4 py-3 text-sm">
        <p className="text-muted">{cfg.help}</p>
        {table === "credentials" && (
          <a href={`/imprimir/fotocheck/${row.id}`} target="_blank" rel="noreferrer" className="inline-block rounded-lg border border-line px-3 py-1.5 font-semibold text-brand hover:border-brand">Imprimir fotocheck con QR</a>
        )}
        {table === "credentials" && Array.isArray(row.blocking_reasons) && row.blocking_reasons.length > 0 && (
          <p className="rounded-lg bg-bad-soft px-3 py-2 text-bad">Bloqueado por: {(row.blocking_reasons as string[]).join(", ")}.</p>
        )}
        {canEdit && options.length > 0 && (
          <div className="flex flex-wrap gap-2">
            {options.map((x) => (
              <Button key={x.label} variant={x.primary ? "primary" : "secondary"} onClick={() => (x.ask ? setAsking(x) : run(x))}>
                {x.label}
              </Button>
            ))}
          </div>
        )}
        {asking && (
          <div className="flex flex-wrap gap-2">
            <input autoFocus className="input flex-1" placeholder="Motivo" aria-label="Motivo" value={text} onChange={(e) => setText(e.target.value)} />
            <Button variant="danger" disabled={!text.trim()} onClick={() => run(asking, { [asking.ask!]: text })}>
              {asking.label}
            </Button>
          </div>
        )}
        {msg && <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-bad">{msg}</p>}
      </section>
    </>
  );
}


// ─────────────── REG: descargar un registro obligatorio ───────────────

const REGISTERS: [string, string][] = [
  ["accidents", "Accidentes de trabajo"],
  ["occupational_diseases", "Enfermedades ocupacionales"],
  ["incidents", "Incidentes peligrosos e incidentes"],
  ["emo", "Exámenes médicos (aptitud)"],
  ["monitoring", "Monitoreo de agentes"],
  ["inspections", "Inspecciones internas"],
  ["statistics", "Estadísticas de SST"],
  ["emergency_equipment", "Equipos de seguridad o emergencia"],
  ["training", "Inducción, capacitación y simulacros"],
  ["audits", "Auditorías"],
];

function RegisterDownload({ onDone }: { onDone: () => void }) {
  const y = new Date().getFullYear();
  const [type, setType] = useState("accidents");
  const [from, setFrom] = useState(`${y}-01-01`);
  const [to, setTo] = useState(new Date().toISOString().slice(0, 10));
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const run = async () => {
    setBusy(true);
    setMsg(null);
    try {
      await downloadFile(`/reg/${type}.xlsx?from=${from}&to=${to}`, `registro-${type}-${from}-${to}.xlsx`);
      onDone();
    } catch (e) {
      setMsg((e as ApiError).detail ?? (e as ApiError).title);
    } finally {
      setBusy(false);
    }
  };
  return (
    <div className="flex flex-wrap items-end gap-2">
      <label className="flex flex-col gap-1 text-xs font-semibold text-muted">
        Registro
        <select className="input !h-10" value={type} onChange={(e) => setType(e.target.value)}>
          {REGISTERS.map(([v, l]) => (
            <option key={v} value={v}>
              {l}
            </option>
          ))}
        </select>
      </label>
      <label className="flex flex-col gap-1 text-xs font-semibold text-muted">
        Desde
        <input className="input !h-10" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
      </label>
      <label className="flex flex-col gap-1 text-xs font-semibold text-muted">
        Hasta
        <input className="input !h-10" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
      </label>
      <Button variant="primary" icon="download" loading={busy} onClick={run}>Descargar Excel</Button>
      {msg && <span className="text-sm text-bad">{msg}</span>}
    </div>
  );
}


// ─────────────── SUN: armar el expediente ───────────────

type DossierItem = { code: string; title: string; module: string; table: string | null; id: string | null; status: string; gap: string | null };
const DOSSIER_STATUS: Record<string, [string, string]> = {
  included: ["Incluido", "bg-ok-soft text-ok"],
  missing: ["Falta", "bg-bad-soft text-bad"],
  expired: ["Vencido", "bg-amber-soft text-amber-ink"],
  not_applicable: ["No aplica", "bg-bg text-muted"],
};

function DossierBuild({ row, onChanged }: { row: RecordRow; onChanged: () => void }) {
  const [items, setItems] = useState<DossierItem[] | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const build = async () => {
    setBusy(true);
    setMsg(null);
    try {
      const r = await api<{ data: { items: DossierItem[]; gaps: number; total: number } }>(`/sun/dossiers/${row.id}/build`, { method: "POST", withCompany: true });
      setItems(r.data.items);
      setMsg(r.data.gaps === 0 ? "Expediente completo: todos los puntos están incluidos." : `${r.data.gaps} de ${r.data.total} puntos con brechas. Toca cada uno para resolverlo.`);
      onChanged();
    } catch (e) {
      setMsg((e as ApiError).detail ?? (e as ApiError).title);
    } finally {
      setBusy(false);
    }
  };
  return (
    <section className="space-y-3 rounded-xl border border-line p-4 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span>Revisa con los datos reales de la empresa si cada documento y registro clave existe y está vigente en el periodo.</span>
        <Button variant="primary" loading={busy} onClick={build}>{items ? "Volver a armar" : "Armar expediente"}</Button>
      </div>
      {msg && <p className="font-semibold text-brand-strong">{msg}</p>}
      {items && (
        <ul className="space-y-1">
          {items.map((it) => {
            const [label, cls] = DOSSIER_STATUS[it.status] ?? [it.status, "bg-bg"];
            const href = it.table ? recordsHref(it.module, it.table, it.id ? { open: it.id } : {}) : `/m/${it.module.toLowerCase()}`;
            return (
              <li key={it.code}>
                <Link href={href} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line px-3 py-2 hover:border-brand">
                  <span>
                    <b>{it.code}.</b> {it.title}
                    {it.gap && <span className="block text-xs text-muted">{it.gap}</span>}
                  </span>
                  <span className={`rounded-md px-2 py-0.5 text-xs font-semibold ${cls}`}>{label}</span>
                </Link>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}


// ─────────────── API: secreto del cliente (una sola vez) ───────────────

function ApiSecret({ row, canEdit, onChanged }: { row: RecordRow; canEdit: boolean; onChanged: () => void }) {
  const [secret, setSecret] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  if (row.status === "revoked") return <p className="rounded-xl bg-bg px-4 py-3 text-sm text-muted">Cliente revocado: ya no puede usar la API.</p>;
  return (
    <section className="space-y-2 rounded-xl border border-line px-4 py-3 text-sm">
      <p>
        Clave pública: <code className="rounded bg-bg px-1">{String(row.client_key ?? "")}</code>. El secreto se muestra una sola vez (10 minutos después de crear el cliente).
      </p>
      {secret ? (
        <p className="rounded-lg bg-amber-soft px-3 py-2 text-amber-ink">
          Cópialo ahora, no se volverá a mostrar: <code className="select-all break-all">{secret}</code>
        </p>
      ) : (
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => api<{ data: { secret: string } }>(`/api-clients/${row.id}/secret`, { method: "POST", withCompany: true }).then((r) => setSecret(r.data.secret)).catch((e: ApiError) => setMsg(e.detail ?? e.title))}>
            Ver secreto
          </Button>
          {canEdit && (
            <Button variant="danger-ghost" onClick={() => records.update("API", "api_clients", row.id, { status: "revoked" }).then(onChanged).catch((e: ApiError) => setMsg(e.detail ?? e.title))}>
              Revocar
            </Button>
          )}
        </div>
      )}
      {msg && <p className="text-bad">{msg}</p>}
    </section>
  );
}
