"use client";

// Grupos vulnerables (M-VUL SCR-VUL-01/02/03; prototipo R.VUL). Lista de adecuaciones con la columna «Condición»:
// «Reservado» para todos salvo el médico ocupacional con su clave médica, que ve la condición real (base médica).
// Cada adecuación se cruza con los peligros del IPERC vigente del puesto (alerta de compatibilidad, RN-VUL-02).
// El médico registra la condición (tipo, fechas, consentimiento) y, con medidas, propone la adecuación.

import { useCallback, useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { RefSelect } from "./RefSelect";
import { Card, Chip, Kpi, type Tone } from "./Summary";
import { errMsg, fmtDate, MedNotice, MedReauth, useMedicalKey, type MedFetch } from "./MedicalGate";

type Conflict = { row_id: string; matrix: string; activity: string; task: string; hazard: string; risk: string; risk_level: string; family: string; family_label: string };
type Compat = { position: { id: string; name: string } | null; conflicts: Conflict[]; compatible: boolean; relocated: boolean; minor: boolean; alerts: string[] };
type Adjustment = {
  id: string; worker_id: string; worker: string; document_masked: string | null; position: string | null; new_position_id: string | null; new_position: string | null;
  adjustment_category: string; description: string; starts_on: string; ends_on: string | null; status: string; expired: boolean;
  restricted_hazard_families: string[]; compatibility_justification: string | null; conflicts: Conflict[]; compatible: boolean;
  condition: { type: string; label: string } | null; condition_reserved: boolean;
};
type Meta = { condition_visible: boolean; is_medical: boolean; can_create: boolean; can_update: boolean; can_record: boolean; families: Record<string, string>;
  kpis: { active: number; proposed: number; incompatible: number; expiring: number } };
type VulRecord = { id: string; worker_id: string; worker: string; document_masked: string | null; vulnerability_type: string; type_label: string; details: string | null;
  consent_on: string | null; conadis_registration: string | null; risk_assessment: string | null; recommended_measures: string | null; starts_on: string;
  expected_end_on: string | null; ends_on: string | null; status: string; work_adjustment_id: string | null };

const CATEGORIES: Record<string, string> = {
  workload: "Carga de trabajo", schedule: "Horario", position_change: "Cambio de puesto", ergonomic: "Ergonómico", environment: "Ambiente", equipment: "Equipo", other: "Otro",
};
const STATUS: Record<string, [string, Tone]> = { proposed: ["Propuesta", "warn"], active: ["Vigente", "ok"], ended: ["Finalizada", "na"], cancelled: ["Anulada", "na"] };
const TYPES: Record<string, string> = {
  pregnancy: "Gestante", lactation: "Lactante", minor: "Menor de edad", disability: "Persona con discapacidad", chronic_condition: "Condición crónica", elderly: "Adulto mayor", other: "Otra condición",
};

export function VulModule({ readOnly = false }: { readOnly?: boolean }) {
  const { key, setKey, med } = useMedicalKey();
  const [rows, setRows] = useState<Adjustment[] | null>(null);
  const [meta, setMeta] = useState<Meta | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [asking, setAsking] = useState(false);
  const [editing, setEditing] = useState<Adjustment | "new" | null>(null);
  const [recording, setRecording] = useState(false);
  const [open, setOpen] = useState<string | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [version, setVersion] = useState(0);

  const load = useCallback(() => {
    const req = key ? med<{ data: Adjustment[]; meta: Meta }>("/vul/adjustments") : api<{ data: Adjustment[]; meta: Meta }>("/vul/adjustments", { withCompany: true });
    req.then((r) => { setRows(r.data); setMeta(r.meta); setError(null); }).catch((e) => setError(errMsg(e)));
  }, [key, med]);
  useEffect(load, [load, version]);

  const refresh = () => setVersion((v) => v + 1);
  const families = meta?.families ?? {};

  return (
    <div className="space-y-4">
      {meta && <MedNotice medical={meta.is_medical} text="Ves la adecuación del puesto. La condición de salud es exclusiva del médico ocupacional." />}
      {meta && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Kpi label="Adecuaciones vigentes" value={String(meta.kpis.active)} color="text-ok" />
          <Kpi label="Propuestas por revisar" value={String(meta.kpis.proposed)} color="text-amber-ink" />
          <Kpi label="Incompatibles con el IPERC" value={String(meta.kpis.incompatible)} color={meta.kpis.incompatible ? "text-bad" : "text-brand"} sub="peligros del puesto sin reubicar ni justificar" />
          <Kpi label="Vencen en 15 días" value={String(meta.kpis.expiring)} />
        </div>
      )}
      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-bad">{error}</p>}

      <Card
        title="Adecuaciones de puesto"
        action={
          <div className="flex flex-wrap gap-2">
            {meta?.is_medical && !key && <Button size="sm" variant="secondary" icon="lock" onClick={() => setAsking(true)}>Ver condición</Button>}
            {meta?.can_record && !readOnly && <Button size="sm" variant="secondary" onClick={() => (key ? setRecording(true) : setAsking(true))}>Registrar condición</Button>}
            {meta?.can_create && !readOnly && <Button size="sm" variant="primary" onClick={() => setEditing("new")}>Nueva adecuación</Button>}
          </div>
        }
      >
        {asking && !key && (
          <div className="mb-4">
            <MedReauth endpoint="/vul/reauth" who="el médico ocupacional" onOk={(k) => { setKey(k); setAsking(false); }} onCancel={() => setAsking(false)} />
          </div>
        )}
        {rows === null ? (
          <p className="text-sm text-muted">Cargando…</p>
        ) : rows.length === 0 ? (
          <p className="text-sm text-muted">Aún no hay adecuaciones de puesto registradas.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="border-b border-line text-left text-xs text-muted">
                  <th className="py-2 pr-3 font-semibold">Trabajador</th>
                  <th className="py-2 pr-3 font-semibold">Condición</th>
                  <th className="py-2 pr-3 font-semibold">Ajuste del puesto</th>
                  <th className="py-2 pr-3 font-semibold">Compatibilidad con el puesto</th>
                  <th className="py-2 pr-3 font-semibold">Vigencia</th>
                  <th className="py-2 font-semibold">Estado</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((a) => {
                  const [label, tone] = a.expired ? ["Vencida", "bad" as Tone] : STATUS[a.status] ?? [a.status, "na" as Tone];
                  return (
                    <Row key={a.id} a={a} open={open === a.id} onToggle={() => setOpen(open === a.id ? null : a.id)} families={families}
                      onEdit={meta?.can_update && !readOnly ? () => setEditing(a) : undefined} statusLabel={label} statusTone={tone} />
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {key && meta?.is_medical && <RecordsList med={med} version={version} canEdit={!!meta.can_record && !readOnly} onChanged={refresh} />}

      {editing && (
        <AdjustmentForm initial={editing === "new" ? null : editing} families={families} onClose={() => setEditing(null)}
          onSaved={(m) => { setEditing(null); setToast(m); refresh(); }} />
      )}
      {recording && key && (
        <RecordForm med={med} onClose={() => setRecording(false)} onSaved={(m) => { setRecording(false); setToast(m); refresh(); }} />
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function Row({ a, open, onToggle, onEdit, families, statusLabel, statusTone }: {
  a: Adjustment; open: boolean; onToggle: () => void; onEdit?: () => void; families: Record<string, string>; statusLabel: string; statusTone: Tone;
}) {
  const compat = a.conflicts.length === 0
    ? <Chip tone={a.restricted_hazard_families.length ? "ok" : "na"}>{a.restricted_hazard_families.length ? "Compatible" : "Sin restricciones"}</Chip>
    : a.compatibility_justification ? <Chip tone="warn">Justificada · {a.conflicts.length} peligro(s)</Chip> : <Chip tone="bad">Incompatible · {a.conflicts.length} peligro(s)</Chip>;
  return (
    <>
      <tr className="border-b border-line align-top">
        <td className="py-2 pr-3">
          <button type="button" className="text-left font-semibold hover:text-brand" onClick={onToggle} aria-expanded={open}>{a.worker}</button>
          <p className="text-xs text-muted">{a.document_masked ?? ""}{a.position ? ` · ${a.position}` : ""}</p>
        </td>
        <td className="py-2 pr-3">{a.condition_reserved ? <span className="text-muted">Reservado</span> : a.condition?.label ?? <span className="text-muted">Sin registro</span>}</td>
        <td className="py-2 pr-3">
          <p>{a.description}</p>
          <p className="text-xs text-muted">{CATEGORIES[a.adjustment_category] ?? a.adjustment_category}{a.new_position ? ` · reubicada a ${a.new_position}` : ""}</p>
        </td>
        <td className="py-2 pr-3">{compat}</td>
        <td className="py-2 pr-3 whitespace-nowrap">{fmtDate(a.starts_on)} – {a.ends_on ? fmtDate(a.ends_on) : "sin fin"}</td>
        <td className="py-2"><Chip tone={statusTone}>{statusLabel}</Chip></td>
      </tr>
      {open && (
        <tr className="border-b border-line bg-bg/50">
          <td colSpan={6} className="space-y-2 px-3 py-3">
            <p className="text-sm">
              <span className="font-semibold">Restringe:</span>{" "}
              {a.restricted_hazard_families.length ? a.restricted_hazard_families.map((f) => families[f] ?? f).join(", ") : "ninguna familia de peligro"}
            </p>
            {a.conflicts.length > 0 && (
              <div>
                <p className="text-sm font-semibold text-bad">Peligros del IPERC del puesto que la adecuación restringe</p>
                <ul className="mt-1 space-y-1 text-sm">
                  {a.conflicts.map((c) => (
                    <li key={c.row_id} className="rounded-lg border border-line bg-surface px-3 py-2">
                      <span className="font-semibold">{c.hazard}</span> · {c.risk} <span className="text-xs text-muted">({c.family_label} · {c.matrix} · {c.task})</span>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {a.compatibility_justification && <p className="text-sm"><span className="font-semibold">Justificación:</span> {a.compatibility_justification}</p>}
            {onEdit && <Button size="sm" variant="secondary" onClick={onEdit}>Editar adecuación</Button>}
          </td>
        </tr>
      )}
    </>
  );
}

function AdjustmentForm({ initial, families, onClose, onSaved }: { initial: Adjustment | null; families: Record<string, string>; onClose: () => void; onSaved: (m: string) => void }) {
  const today = new Date().toISOString().slice(0, 10);
  const [f, setF] = useState({
    worker_id: initial?.worker_id ?? "", adjustment_category: initial?.adjustment_category ?? "environment", description: initial?.description ?? "",
    restricted_hazard_families: initial?.restricted_hazard_families ?? ([] as string[]), new_position_id: initial?.new_position_id ?? "",
    starts_on: initial?.starts_on?.slice(0, 10) ?? today, ends_on: initial?.ends_on?.slice(0, 10) ?? "", status: initial?.status ?? "proposed",
    compatibility_justification: initial?.compatibility_justification ?? "",
  });
  const [compat, setCompat] = useState<Compat | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof f, v: unknown) => setF((x) => ({ ...x, [k]: v }));

  // SCR-VUL-03: cruce en vivo con el IPERC del puesto (o del nuevo puesto si se reubica).
  useEffect(() => {
    if (!f.worker_id) { setCompat(null); return; }
    const t = setTimeout(() => {
      api<{ data: Compat }>("/vul/compatibility", { method: "POST", withCompany: true,
        body: { worker_id: f.worker_id, families: f.restricted_hazard_families, new_position_id: f.new_position_id || null } })
        .then((r) => setCompat(r.data)).catch(() => setCompat(null));
    }, 250);
    return () => clearTimeout(t);
  }, [f.worker_id, f.restricted_hazard_families, f.new_position_id]);

  async function save() {
    setBusy(true);
    setError(null);
    setErrors({});
    const body = { ...f, new_position_id: f.new_position_id || null, ends_on: f.ends_on || null, compatibility_justification: f.compatibility_justification || null };
    try {
      await api(initial ? `/vul/adjustments/${initial.id}` : "/vul/adjustments", { method: initial ? "PUT" : "POST", body, withCompany: true });
      onSaved(initial ? "Adecuación actualizada" : "Adecuación registrada");
    } catch (e) {
      if (e instanceof ApiError && e.errors) setErrors(e.errors);
      setError(errMsg(e));
    } finally {
      setBusy(false);
    }
  }

  const needsJustification = !!compat && compat.conflicts.length > 0 && !compat.relocated;
  return (
    <Modal title={initial ? "Editar adecuación" : "Nueva adecuación de puesto"} subtitle="Describe el ajuste en términos laborales, sin mencionar la condición de salud." onClose={onClose} size="lg"
      footer={<><Button variant="ghost" onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} onClick={save}>Guardar</Button></>}>
      <div className="space-y-4">
        {!initial && (
          <FormField label="Trabajador" error={errors.worker_id?.[0]}>
            <RefSelect module="VUL" table="work_adjustments" field="worker_id" label="Trabajador" value={f.worker_id} onChange={(id) => set("worker_id", id)} placeholder="Elige al trabajador" />
          </FormField>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Tipo de adecuación">
            <select className="input" value={f.adjustment_category} onChange={(e) => set("adjustment_category", e.target.value)} aria-label="Tipo de adecuación">
              {Object.entries(CATEGORIES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </FormField>
          <FormField label="Estado">
            <select className="input" value={f.status} onChange={(e) => set("status", e.target.value)} aria-label="Estado">
              {Object.entries(STATUS).map(([k, [v]]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </FormField>
        </div>
        <FormField label="Ajuste del puesto" error={errors.description?.[0]}>
          <textarea className="input min-h-[80px]" value={f.description} onChange={(e) => set("description", e.target.value)} aria-label="Ajuste del puesto"
            placeholder="Ej.: no participa en barnizado ni manipula solventes; pausas cada 2 horas" />
        </FormField>
        <fieldset>
          <legend className="mb-1.5 text-sm font-semibold">Familias de peligro que restringe</legend>
          <div className="flex flex-wrap gap-2">
            {Object.entries(families).map(([k, v]) => {
              const on = f.restricted_hazard_families.includes(k);
              return (
                <button key={k} type="button" aria-pressed={on}
                  className={`rounded-full border px-3 py-1 text-sm ${on ? "border-brand bg-brand-soft font-semibold text-brand-strong" : "border-line bg-surface text-fg hover:border-brand"}`}
                  onClick={() => set("restricted_hazard_families", on ? f.restricted_hazard_families.filter((x) => x !== k) : [...f.restricted_hazard_families, k])}>
                  {v}
                </button>
              );
            })}
          </div>
        </fieldset>
        <FormField label="Reubicar en otro puesto (opcional)" hint="Si lo reubicas, el cruce se hace con el IPERC del nuevo puesto.">
          <RefSelect module="VUL" table="work_adjustments" field="new_position_id" label="Nuevo puesto" value={f.new_position_id} onChange={(id) => set("new_position_id", id)} placeholder="Se mantiene en su puesto" />
        </FormField>
        {compat && (
          <div className={`rounded-lg border px-4 py-3 text-sm ${compat.conflicts.length ? "border-bad/40 bg-bad-soft" : "border-line bg-bg"}`} role="status">
            <p className="font-semibold">{compat.conflicts.length ? "Alerta de compatibilidad" : "Compatibilidad con el puesto"}{compat.position ? ` · ${compat.position.name}` : ""}</p>
            {compat.alerts.length === 0 && <p className="text-muted">El IPERC vigente del puesto no tiene peligros de las familias elegidas.</p>}
            {compat.alerts.map((t) => <p key={t}>{t}</p>)}
            {compat.conflicts.length > 0 && (
              <ul className="mt-2 list-disc pl-5">
                {compat.conflicts.map((c) => <li key={c.row_id}>{c.hazard} · {c.risk} <span className="text-muted">({c.family_label} · {c.task})</span></li>)}
              </ul>
            )}
          </div>
        )}
        {(needsJustification || f.compatibility_justification) && (
          <FormField label="Justificación para mantenerlo en el puesto" error={errors.compatibility_justification?.[0]} hint="Obligatoria si el puesto tiene peligros restringidos y no se reubica.">
            <textarea className="input min-h-[70px]" value={f.compatibility_justification} onChange={(e) => set("compatibility_justification", e.target.value)} aria-label="Justificación" />
          </FormField>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Desde" error={errors.starts_on?.[0]}>
            <input type="date" className="input" value={f.starts_on} onChange={(e) => set("starts_on", e.target.value)} aria-label="Desde" />
          </FormField>
          <FormField label="Hasta (opcional)" error={errors.ends_on?.[0]} hint="Al vencer se revisa la adecuación.">
            <input type="date" className="input" value={f.ends_on} onChange={(e) => set("ends_on", e.target.value)} aria-label="Hasta" />
          </FormField>
        </div>
        {error && <p className="rounded-lg bg-bad-soft px-4 py-2 text-sm text-bad">{error}</p>}
      </div>
    </Modal>
  );
}

function RecordForm({ med, initial, onClose, onSaved }: { med: MedFetch; initial?: VulRecord; onClose: () => void; onSaved: (m: string) => void }) {
  const today = new Date().toISOString().slice(0, 10);
  const [f, setF] = useState({
    worker_id: initial?.worker_id ?? "", vulnerability_type: initial?.vulnerability_type ?? "pregnancy", starts_on: initial?.starts_on?.slice(0, 10) ?? today,
    expected_end_on: initial?.expected_end_on?.slice(0, 10) ?? "", ends_on: initial?.ends_on?.slice(0, 10) ?? "", details: initial?.details ?? "",
    conadis_registration: initial?.conadis_registration ?? "", risk_assessment: initial?.risk_assessment ?? "", recommended_measures: initial?.recommended_measures ?? "",
    consent_on: initial?.consent_on?.slice(0, 10) ?? "",
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));

  async function save() {
    setBusy(true);
    setErrors({});
    setError(null);
    const body = Object.fromEntries(Object.entries(f).map(([k, v]) => [k, v === "" ? null : v]));
    try {
      const r = await med<{ data: { work_adjustment_id?: string | null } }>(initial ? `/vul/records/${initial.id}` : "/vul/records", { method: initial ? "PUT" : "POST", body });
      onSaved(initial ? "Condición actualizada" : r.data.work_adjustment_id ? "Condición registrada y adecuación propuesta al Jefe SST" : "Condición registrada");
    } catch (e) {
      if (e instanceof ApiError && e.errors) setErrors(e.errors);
      setError(errMsg(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal title={initial ? "Editar condición" : "Registrar condición"} subtitle="Confidencial: se guarda cifrada en la base médica." onClose={onClose} size="lg"
      footer={<><Button variant="ghost" onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} onClick={save}>Guardar</Button></>}>
      <div className="space-y-4">
        {!initial && (
          <FormField label="Trabajador" error={errors.worker_id?.[0]}>
            <RefSelect module="VUL" table="work_adjustments" field="worker_id" label="Trabajador" value={f.worker_id} onChange={(id) => set("worker_id", id)} placeholder="Elige al trabajador" />
          </FormField>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField label="Condición" error={errors.vulnerability_type?.[0]}>
            <select className="input" value={f.vulnerability_type} onChange={(e) => set("vulnerability_type", e.target.value)} aria-label="Condición">
              {Object.entries(TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </FormField>
          {f.vulnerability_type === "disability" && (
            <FormField label="Registro CONADIS (opcional)">
              <input className="input" value={f.conadis_registration} onChange={(e) => set("conadis_registration", e.target.value)} aria-label="Registro CONADIS" />
            </FormField>
          )}
        </div>
        <div className="grid gap-4 sm:grid-cols-3">
          <FormField label="Desde" error={errors.starts_on?.[0]}>
            <input type="date" className="input" value={f.starts_on} onChange={(e) => set("starts_on", e.target.value)} aria-label="Desde" />
          </FormField>
          <FormField label="Fin previsto" error={errors.expected_end_on?.[0]}>
            <input type="date" className="input" value={f.expected_end_on} onChange={(e) => set("expected_end_on", e.target.value)} aria-label="Fin previsto" />
          </FormField>
          {initial && (
            <FormField label="Terminó el" error={errors.ends_on?.[0]}>
              <input type="date" className="input" value={f.ends_on} onChange={(e) => set("ends_on", e.target.value)} aria-label="Terminó el" />
            </FormField>
          )}
        </div>
        <FormField label="Detalle clínico (opcional)">
          <textarea className="input min-h-[70px]" value={f.details} onChange={(e) => set("details", e.target.value)} aria-label="Detalle clínico" />
        </FormField>
        <FormField label="Evaluación del riesgo (opcional)">
          <textarea className="input min-h-[60px]" value={f.risk_assessment} onChange={(e) => set("risk_assessment", e.target.value)} aria-label="Evaluación del riesgo" />
        </FormField>
        <FormField label="Medidas recomendadas en términos laborales" hint={initial ? undefined : "Si las indicas, se propone una adecuación al Jefe SST sin revelar la condición."}>
          <textarea className="input min-h-[60px]" value={f.recommended_measures} onChange={(e) => set("recommended_measures", e.target.value)} aria-label="Medidas recomendadas" />
        </FormField>
        <FormField label="Consentimiento del trabajador (fecha)" error={errors.consent_on?.[0]} hint="Fecha en que el trabajador autorizó el tratamiento de este dato.">
          <input type="date" className="input" value={f.consent_on} onChange={(e) => set("consent_on", e.target.value)} aria-label="Fecha del consentimiento" />
        </FormField>
        {error && <p className="rounded-lg bg-bad-soft px-4 py-2 text-sm text-bad">{error}</p>}
      </div>
    </Modal>
  );
}

function RecordsList({ med, version, canEdit, onChanged }: { med: MedFetch; version: number; canEdit: boolean; onChanged: () => void }) {
  const [rows, setRows] = useState<VulRecord[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<VulRecord | null>(null);
  useEffect(() => {
    med<{ data: VulRecord[] }>("/vul/records").then((r) => setRows(r.data)).catch((e) => setError(errMsg(e)));
  }, [med, version]);
  return (
    <Card title="Registros de condición (confidencial)">
      {error && <p className="text-sm text-bad">{error}</p>}
      {rows === null ? <p className="text-sm text-muted">Cargando…</p> : rows.length === 0 ? <p className="text-sm text-muted">No hay condiciones registradas.</p> : (
        <ul className="divide-y divide-line">
          {rows.map((v) => (
            <li key={v.id} className="flex flex-wrap items-start justify-between gap-2 py-2 text-sm">
              <div>
                <p className="font-semibold">{v.worker} <span className="text-xs font-normal text-muted">{v.document_masked}</span></p>
                <p>{v.type_label} · desde {fmtDate(v.starts_on)}{v.expected_end_on ? ` · fin previsto ${fmtDate(v.expected_end_on)}` : ""}</p>
                {v.details && <p className="text-muted">{v.details}</p>}
                <p className="text-xs text-muted">{v.consent_on ? `Consentimiento del ${fmtDate(v.consent_on)}` : "Sin consentimiento registrado"}</p>
              </div>
              <div className="flex items-center gap-2">
                <Chip tone={v.status === "active" ? "ok" : "na"}>{v.status === "active" ? "Vigente" : "Terminada"}</Chip>
                {canEdit && <Button size="sm" variant="ghost" onClick={() => setEditing(v)}>Editar</Button>}
              </div>
            </li>
          ))}
        </ul>
      )}
      {editing && <RecordForm med={med} initial={editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); onChanged(); }} />}
    </Card>
  );
}
