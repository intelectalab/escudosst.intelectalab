"use client";

// Descansos médicos y ausentismo (M-DES SCR-DES-01/03/04; prototipo R.DES). Indicadores del mes, tarjeta «Ausentismo»
// (tasa por área y mes, días perdidos y días por accidente de trabajo) y, solo para el personal de salud con su clave
// médica, el detalle médico del descanso: diagnóstico CIE-10, CITT y certificado (base médica). RR. HH. ve fechas y días.

import { useCallback, useEffect, useRef, useState } from "react";
import { api, ApiError, API_URL } from "@/lib/api";
import { session } from "@/lib/session";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { Card, Chip, Kpi, fmtNum } from "./Summary";
import { errMsg, fmtDate, MedNotice, MedReauth, useMedicalKey, type MedFetch, type MedKey } from "./MedicalGate";

type AreaRow = { area_id: string | null; area: string; workers: number; months: { month: string; days: number; rate: number | null }[] };
type Indicators = {
  month: string; months: string[];
  kpis: { lost_days: number; absenteeism_rate: number | null; active_leaves: number; accident_absences: number; accident_days: number; workers: number };
  trend: { month: string; days: number; accident_days: number; rate: number | null }[];
  by_area: AreaRow[]; by_type: { type: string; label: string; days: number }[]; is_medical: boolean;
};
type Absence = { id: string; worker: string; area: string | null; absence_type: string; type_label: string; starts_on: string; ends_on: string; days: number; status: string;
  has_medical: boolean; certificate_received: boolean; active: boolean };
type Detail = { id: string; diagnosis_code: string | null; diagnosis_description: string | null; citt_number: string | null; issuing_entity: string | null;
  issuing_doctor_name: string | null; issuing_doctor_cmp: string | null; is_work_related: boolean; validated_at: string | null; has_certificate: boolean };

const MONTHS = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
const monthLabel = (ym: string) => `${MONTHS[Number(ym.slice(5, 7)) - 1]} ${ym.slice(2, 4)}`;
const pct = (v: number | null) => (v == null ? "—" : `${fmtNum(v, 1)} %`);
const ENTITIES: Record<string, string> = { essalud: "EsSalud", eps: "EPS", minsa: "MINSA", private_clinic: "Clínica privada", other: "Otra" };

/** Color de la celda según la tasa (más alta, más intensa). */
function cellTone(rate: number | null) {
  if (rate == null || rate === 0) return "bg-surface text-muted";
  if (rate < 2) return "bg-ok-soft text-ok";
  if (rate < 5) return "bg-amber-soft text-amber-ink";
  return "bg-bad-soft text-bad";
}

export function DesModule() {
  const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7));
  const [data, setData] = useState<Indicators | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<{ data: Indicators }>(`/des/indicators?month=${month}`, { withCompany: true }).then((r) => { setData(r.data); setError(null); }).catch((e) => setError(errMsg(e)));
  }, [month]);

  const max = Math.max(1, ...(data?.trend.map((t) => t.days) ?? [1]));
  return (
    <div className="space-y-4">
      {data && <MedNotice medical={data.is_medical} text="Ves fechas, días y tipo de ausencia. El diagnóstico y el certificado son exclusivos del personal de salud." />}
      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-bad">{error}</p>}
      {data && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Kpi label="Días perdidos (mes)" value={fmtNum(data.kpis.lost_days)} color="text-brand" />
          <Kpi label="Tasa de ausentismo" value={pct(data.kpis.absenteeism_rate)} color="text-amber-ink" sub={`${fmtNum(data.kpis.workers)} trabajadores activos`} />
          <Kpi label="Descansos activos" value={fmtNum(data.kpis.active_leaves)} color="text-ok" sub="hoy" />
          <Kpi label="Por accidente de trabajo" value={fmtNum(data.kpis.accident_absences)} color={data.kpis.accident_absences ? "text-bad" : "text-brand"} sub={`${fmtNum(data.kpis.accident_days)} días perdidos en el mes`} />
        </div>
      )}

      <Card
        title="Ausentismo"
        action={
          <label className="flex items-center gap-2 text-sm text-muted">
            Mes
            <input type="month" className="input h-9 w-auto" value={month} max={new Date().toISOString().slice(0, 7)} onChange={(e) => e.target.value && setMonth(e.target.value)} aria-label="Mes" />
          </label>
        }
      >
        {!data ? <p className="text-sm text-muted">Cargando…</p> : (
          <div className="space-y-5">
            <div>
              <p className="mb-2 text-sm font-semibold">Días perdidos por mes</p>
              <div className="grid grid-cols-6 items-end gap-2" role="img" aria-label="Días perdidos por mes, total y por accidente de trabajo">
                {data.trend.map((t) => (
                  <div key={t.month} className="flex flex-col items-center gap-1">
                    <span className="text-xs font-semibold tabular-nums">{t.days}</span>
                    <div className="relative flex h-24 w-full max-w-[44px] items-end overflow-hidden rounded-md bg-bg">
                      <div className="w-full bg-brand" style={{ height: `${(t.days / max) * 100}%` }} />
                      {t.accident_days > 0 && <div className="absolute bottom-0 w-full bg-bad" style={{ height: `${(t.accident_days / max) * 100}%` }} />}
                    </div>
                    <span className="text-[11px] text-muted">{monthLabel(t.month)}</span>
                  </div>
                ))}
              </div>
              <p className="mt-2 flex flex-wrap gap-4 text-xs text-muted">
                <span className="flex items-center gap-1"><span className="inline-block h-2.5 w-2.5 rounded-sm bg-brand" aria-hidden="true" /> Todas las ausencias</span>
                <span className="flex items-center gap-1"><span className="inline-block h-2.5 w-2.5 rounded-sm bg-bad" aria-hidden="true" /> Por accidente de trabajo</span>
              </p>
            </div>

            <div>
              <p className="mb-2 text-sm font-semibold">Tasa de ausentismo por área y mes</p>
              {data.by_area.length === 0 ? <p className="text-sm text-muted">Aún no hay trabajadores ni ausencias registradas.</p> : (
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[560px] text-sm">
                    <thead>
                      <tr className="border-b border-line text-left text-xs text-muted">
                        <th className="py-2 pr-3 font-semibold">Área</th>
                        {data.months.map((m) => <th key={m} className="px-1 py-2 text-center font-semibold">{monthLabel(m)}</th>)}
                      </tr>
                    </thead>
                    <tbody>
                      {data.by_area.map((a) => (
                        <tr key={a.area_id ?? "none"} className="border-b border-line">
                          <td className="py-1.5 pr-3">
                            <p className="font-semibold">{a.area}</p>
                            <p className="text-xs text-muted">{a.workers} trabajador{a.workers === 1 ? "" : "es"}</p>
                          </td>
                          {a.months.map((m) => (
                            <td key={m.month} className="px-1 py-1.5 text-center">
                              <span className={`inline-block min-w-[58px] rounded-md px-1.5 py-1 text-xs font-semibold tabular-nums ${cellTone(m.rate)}`} title={`${m.days} días perdidos`}>
                                {pct(m.rate)}
                              </span>
                            </td>
                          ))}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
              <p className="mt-2 text-xs text-muted">Tasa = días perdidos ÷ (trabajadores activos × días del mes). Días calendario.</p>
            </div>

            {data.by_type.length > 0 && (
              <div>
                <p className="mb-2 text-sm font-semibold">Días perdidos del mes por tipo</p>
                <div className="flex flex-wrap gap-2">
                  {data.by_type.map((t) => <Chip key={t.type} tone={t.type === "occupational_accident" ? "bad" : "na"}>{t.label}: {t.days}</Chip>)}
                </div>
              </div>
            )}
          </div>
        )}
      </Card>

      {data?.is_medical && <MedicalLeaves />}
    </div>
  );
}

/** Descansos con su detalle médico (solo personal de salud). */
function MedicalLeaves() {
  const { key, setKey, med } = useMedicalKey();
  const [rows, setRows] = useState<Absence[] | null>(null);
  const [open, setOpen] = useState<Absence | null>(null);
  const [asking, setAsking] = useState<Absence | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ data: Absence[] }>("/des/absences", { withCompany: true }).then((r) => setRows(r.data)).catch(() => setRows([]));
  }, []);
  useEffect(load, [load]);

  const pick = (a: Absence) => (key ? setOpen(a) : setAsking(a));
  const onKey = (k: MedKey) => { setKey(k); if (asking) setOpen(asking); setAsking(null); };

  return (
    <Card title="Detalle médico de los descansos">
      {asking && !key && (
        <div className="mb-4">
          <MedReauth endpoint="/des/reauth" onOk={onKey} onCancel={() => setAsking(null)} />
        </div>
      )}
      {rows === null ? <p className="text-sm text-muted">Cargando…</p> : rows.length === 0 ? <p className="text-sm text-muted">No hay ausencias registradas.</p> : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[620px] text-sm">
            <thead>
              <tr className="border-b border-line text-left text-xs text-muted">
                <th className="py-2 pr-3 font-semibold">Trabajador</th>
                <th className="py-2 pr-3 font-semibold">Desde</th>
                <th className="py-2 pr-3 font-semibold">Hasta</th>
                <th className="py-2 pr-3 font-semibold">Días</th>
                <th className="py-2 pr-3 font-semibold">Origen</th>
                <th className="py-2 pr-3 font-semibold">Detalle médico</th>
                <th className="py-2 font-semibold"><span className="sr-only">Acción</span></th>
              </tr>
            </thead>
            <tbody>
              {rows.map((a) => (
                <tr key={a.id} className="border-b border-line">
                  <td className="py-2 pr-3"><p className="font-semibold">{a.worker}</p>{a.area && <p className="text-xs text-muted">{a.area}</p>}</td>
                  <td className="py-2 pr-3">{fmtDate(a.starts_on)}</td>
                  <td className="py-2 pr-3">{fmtDate(a.ends_on)}</td>
                  <td className="py-2 pr-3 tabular-nums">{a.days}</td>
                  <td className="py-2 pr-3">{a.type_label}</td>
                  <td className="py-2 pr-3">
                    <Chip tone={a.has_medical ? "ok" : "warn"}>{a.has_medical ? (a.certificate_received ? "Con certificado" : "Registrado") : "Pendiente"}</Chip>
                  </td>
                  <td className="py-2 text-right"><Button size="sm" variant="secondary" icon={key ? undefined : "lock"} onClick={() => pick(a)}>Ver detalle</Button></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {open && key && <DetailModal absence={open} med={med} reauthId={key.id} onClose={() => setOpen(null)} onSaved={(m) => { setToast(m); load(); }} />}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </Card>
  );
}

function DetailModal({ absence, med, reauthId, onClose, onSaved }: { absence: Absence; med: MedFetch; reauthId: string; onClose: () => void; onSaved: (m: string) => void }) {
  const [detail, setDetail] = useState<Detail | null | undefined>(undefined);
  const [canEdit, setCanEdit] = useState(false);
  const [f, setF] = useState({ diagnosis_code: "", diagnosis_description: "", citt_number: "", issuing_entity: "essalud", issuing_doctor_name: "", issuing_doctor_cmp: "",
    is_work_related: absence.absence_type === "occupational_accident" || absence.absence_type === "occupational_disease" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);

  const load = useCallback(() => {
    med<{ data: { detail: Detail | null; can_edit: boolean } }>(`/des/absences/${absence.id}/medical`).then((r) => {
      setDetail(r.data.detail);
      setCanEdit(r.data.can_edit);
      if (r.data.detail) {
        const d = r.data.detail;
        setF({ diagnosis_code: d.diagnosis_code ?? "", diagnosis_description: d.diagnosis_description ?? "", citt_number: d.citt_number ?? "", issuing_entity: d.issuing_entity ?? "essalud",
          issuing_doctor_name: d.issuing_doctor_name ?? "", issuing_doctor_cmp: d.issuing_doctor_cmp ?? "", is_work_related: d.is_work_related });
      }
    }).catch((e) => setError(errMsg(e)));
  }, [absence.id, med]);
  useEffect(load, [load]);

  async function save() {
    setBusy(true);
    setErrors({});
    setError(null);
    try {
      await med(`/des/absences/${absence.id}/medical`, { method: "PUT", body: { ...f, diagnosis_code: f.diagnosis_code.trim().toUpperCase() } });
      onSaved("Detalle médico guardado");
      load();
    } catch (e) {
      if (e instanceof ApiError && e.errors) setErrors(e.errors);
      setError(errMsg(e));
    } finally {
      setBusy(false);
    }
  }

  async function upload(file: File) {
    setBusy(true);
    setError(null);
    const body = new FormData();
    body.append("file", file);
    try {
      const res = await fetch(`${API_URL}/api/v1/des/absences/${absence.id}/certificate`, { method: "POST", body, headers: authHeaders(reauthId) });
      if (!res.ok) {
        const j = await res.json().catch(() => ({}));
        throw new ApiError(res.status, j.type ?? "error", j.title ?? "Error", j.detail);
      }
      onSaved("Certificado adjuntado");
      load();
    } catch (e) {
      setError(errMsg(e));
    } finally {
      setBusy(false);
    }
  }

  async function download() {
    setError(null);
    try {
      const res = await fetch(`${API_URL}/api/v1/des/absences/${absence.id}/certificate`, { headers: authHeaders(reauthId) });
      if (!res.ok) throw new ApiError(res.status, "error", "No pudimos abrir el certificado.");
      const url = URL.createObjectURL(await res.blob());
      window.open(url, "_blank", "noopener");
      setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch (e) {
      setError(errMsg(e));
    }
  }

  const set = (k: keyof typeof f, v: string | boolean) => setF((x) => ({ ...x, [k]: v }));
  return (
    <Modal title={absence.worker} subtitle={`${absence.type_label} · ${fmtDate(absence.starts_on)} – ${fmtDate(absence.ends_on)} · ${absence.days} días · acceso registrado`} onClose={onClose} size="lg"
      footer={<><Button variant="ghost" onClick={onClose}>Cerrar</Button>{canEdit && <Button variant="primary" loading={busy} onClick={save}>Guardar detalle</Button>}</>}>
      {detail === undefined ? <p className="text-sm text-muted">Cargando…</p> : (
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-[160px_1fr]">
            <FormField label="Diagnóstico CIE-10" error={errors.diagnosis_code?.[0]} hint="Ej.: S61.4">
              <input className="input uppercase" value={f.diagnosis_code} onChange={(e) => set("diagnosis_code", e.target.value)} disabled={!canEdit} aria-label="Código CIE-10" />
            </FormField>
            <FormField label="Descripción del diagnóstico">
              <input className="input" value={f.diagnosis_description} onChange={(e) => set("diagnosis_description", e.target.value)} disabled={!canEdit} aria-label="Descripción del diagnóstico" />
            </FormField>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="N.° de CITT o certificado">
              <input className="input" value={f.citt_number} onChange={(e) => set("citt_number", e.target.value)} disabled={!canEdit} aria-label="Número de CITT" />
            </FormField>
            <FormField label="Entidad emisora">
              <select className="input" value={f.issuing_entity} onChange={(e) => set("issuing_entity", e.target.value)} disabled={!canEdit} aria-label="Entidad emisora">
                {Object.entries(ENTITIES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </FormField>
            <FormField label="Médico que lo emitió">
              <input className="input" value={f.issuing_doctor_name} onChange={(e) => set("issuing_doctor_name", e.target.value)} disabled={!canEdit} aria-label="Médico emisor" />
            </FormField>
            <FormField label="CMP">
              <input className="input" value={f.issuing_doctor_cmp} onChange={(e) => set("issuing_doctor_cmp", e.target.value)} disabled={!canEdit} aria-label="CMP" />
            </FormField>
          </div>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={f.is_work_related} onChange={(e) => set("is_work_related", e.target.checked)} disabled={!canEdit} />
            Relacionado con el trabajo
          </label>
          {detail?.validated_at && <p className="text-xs text-muted">Validado por el médico ocupacional el {fmtDate(detail.validated_at)}.</p>}

          <div className="rounded-lg border border-line p-3">
            <p className="text-sm font-semibold">Certificado</p>
            {!detail ? (
              <p className="text-sm text-muted">Guarda primero el diagnóstico para adjuntar el certificado.</p>
            ) : (
              <div className="mt-2 flex flex-wrap gap-2">
                {detail.has_certificate && <Button size="sm" variant="secondary" onClick={download}>Ver certificado</Button>}
                {canEdit && (
                  <>
                    <input ref={fileRef} type="file" accept="application/pdf,image/jpeg,image/png" className="hidden" aria-label="Certificado"
                      onChange={(e) => { const file = e.target.files?.[0]; if (file) upload(file); e.target.value = ""; }} />
                    <Button size="sm" variant="secondary" loading={busy} onClick={() => fileRef.current?.click()}>{detail.has_certificate ? "Reemplazar certificado" : "Adjuntar certificado"}</Button>
                  </>
                )}
                <p className="w-full text-xs text-muted">PDF o imagen, hasta 15 MB. Se guarda cifrado en el almacenamiento médico.</p>
              </div>
            )}
          </div>
          {error && <p className="rounded-lg bg-bad-soft px-4 py-2 text-sm text-bad">{error}</p>}
        </div>
      )}
    </Modal>
  );
}

function authHeaders(reauthId: string): Record<string, string> {
  const h: Record<string, string> = { Accept: "application/json", "X-Medical-Reauth": reauthId };
  const token = session.getToken();
  const company = session.getCompanyId();
  if (token) h.Authorization = `Bearer ${token}`;
  if (company) h["X-Company-Id"] = company;
  return h;
}
