"use client";

// Resultado clínico de EMO (M-EMO SCR-EMO-03 y SCR-EMO-05): solo para el médico ocupacional. Antes de ver o registrar
// datos clínicos se confirma la contraseña (dura unos minutos y cada acceso queda registrado). Todo se guarda en la
// base médica; a la ficha del trabajador solo pasan la aptitud, su vigencia y las restricciones laborales.

import { useCallback, useEffect, useState } from "react";
import { api, ApiError, API_URL } from "@/lib/api";
import { session } from "@/lib/session";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";

type Fitness = "pending" | "fit" | "fit_with_restrictions" | "unfit";
type ExamFile = { id: string; file_kind: string; name: string; mime_type: string; size_bytes: number; created_at: string };
type Exam = {
  id: string; order_id: string | null; exam_type: string; exam_on: string; clinic_name: string | null; clinic_ruc: string | null;
  examining_doctor_name: string | null; examining_doctor_cmp: string | null; results: { exam: string; result: string | null }[];
  observations: string | null; diagnoses: { code: string; description: string | null }[]; recommendations: string | null;
  fitness_result: Fitness; work_restrictions: string | null; fitness_valid_until: string | null; status: string; files: ExamFile[];
};
type Order = { id: string; worker_id: string; worker: string; document_masked: string | null; position: string | null; exam_type: string; clinic_name: string | null; clinic_ruc: string | null; scheduled_at: string | null; status: string };
type Clinical = { order: Order; exam: Exam | null; can_edit: boolean; can_issue_fitness: boolean; can_upload: boolean };
type History = { worker: { id: string; full_name: string; document_masked: string | null; position: string | null }; fitness: { status: string; valid_until: string | null; work_restrictions: string | null } | null; exams: Exam[] };

const EXAM_TYPES: Record<string, string> = {
  pre_employment: "Preocupacional", periodic: "Periódico", exit: "De retiro", return_to_work: "Reintegro", position_change: "Cambio de puesto", other: "Otro",
};
const FITNESS: Record<string, [string, string]> = {
  pending: ["Pendiente", "bg-bg text-muted"], fit: ["Apto", "bg-ok-soft text-ok"], fit_with_restrictions: ["Apto con restricciones", "bg-amber-soft text-amber-ink"],
  unfit: ["No apto", "bg-bad-soft text-bad"], expired: ["Vencido", "bg-bad-soft text-bad"],
};
const FILE_KINDS: Record<string, string> = {
  full_report: "Informe médico", lab_result: "Laboratorio", imaging: "Imágenes (rayos X)", audiometry: "Audiometría", spirometry: "Espirometría",
  certificate: "Certificado de aptitud", consent: "Consentimiento", other: "Otro",
};
const EXAMS = ["Examen clínico", "Audiometría", "Espirometría", "Rayos X de tórax", "Laboratorio", "Oftalmología", "Electrocardiograma", "Evaluación psicológica", "Evaluación músculo-esquelética", "Otro"];
const d = (v: string | null | undefined) => (v ? v.slice(0, 10).split("-").reverse().join("/") : "—");
const msg = (e: unknown) => (e instanceof ApiError ? (e.detail ?? e.title) : "No pudimos completar la acción.");

type Med = <T>(path: string, opts?: { method?: "GET" | "POST" | "PUT"; body?: unknown }) => Promise<T>;

/**
 * Props:
 * - orderId: orden de examen (medical_exam_orders.id). Muestra y registra su resultado clínico y deja abrir la historia.
 * - workerId: si no hay orden, abre directo la historia ocupacional del trabajador.
 * - readOnly: módulo en solo lectura (no se registra ni se adjunta).
 * - onSaved: se llama tras guardar (por ejemplo, para recargar la lista de órdenes y la aptitud).
 */
export function EmoClinical({ orderId, workerId, readOnly = false, onSaved }: { orderId?: string; workerId?: string; readOnly?: boolean; onSaved?: () => void }) {
  const [reauth, setReauth] = useState<{ id: string; expires: number } | null>(null);
  const [data, setData] = useState<Clinical | null>(null);
  const [history, setHistory] = useState<History | null>(null);
  const [view, setView] = useState<"result" | "history">(orderId ? "result" : "history");
  const [editing, setEditing] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const med: Med = useCallback(
    async <T,>(path: string, opts: { method?: "GET" | "POST" | "PUT"; body?: unknown } = {}): Promise<T> => {
      if (!reauth || reauth.expires < Date.now()) {
        setReauth(null);
        throw new ApiError(403, "reauth_required", "Confirma tu contraseña");
      }
      try {
        return await api<T>(path, { ...opts, withCompany: true, headers: { "X-Medical-Reauth": reauth.id } });
      } catch (e) {
        if (e instanceof ApiError && e.type === "reauth_required") setReauth(null);
        throw e;
      }
    },
    [reauth],
  ) as Med;

  const load = useCallback(() => {
    if (!reauth) return;
    setError(null);
    if (view === "result" && orderId) {
      med<{ data: Clinical }>(`/emo/orders/${orderId}/clinical`).then((r) => { setData(r.data); setEditing(!r.data.exam && r.data.can_edit && !readOnly); }).catch((e) => setError(msg(e)));
    }
    const wid = workerId ?? data?.order.worker_id;
    if (view === "history" && wid) {
      med<{ data: History }>(`/emo/workers/${wid}/history`).then((r) => setHistory(r.data)).catch((e) => setError(msg(e)));
    }
    // data se lee solo para conocer el trabajador de la orden
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reauth, view, orderId, workerId, med, readOnly]);

  useEffect(load, [load]);

  if (!reauth) return <Reauth onOk={(id, minutes) => setReauth({ id, expires: Date.now() + minutes * 60_000 })} />;

  const canHistory = !!(workerId ?? data?.order.worker_id);

  return (
    <div className="space-y-4">
      <p className="rounded-lg bg-brand-soft px-4 py-2 text-sm">
        Datos médicos confidenciales: cada consulta queda registrada a tu nombre. La clave vence a las{" "}
        {new Date(reauth.expires).toLocaleTimeString("es-PE", { hour: "2-digit", minute: "2-digit" })}.
      </p>
      {orderId && canHistory && (
        <div className="flex gap-2" role="tablist">
          <Button size="sm" variant={view === "result" ? "primary" : "secondary"} onClick={() => setView("result")} aria-pressed={view === "result"}>Resultado del examen</Button>
          <Button size="sm" variant={view === "history" ? "primary" : "secondary"} onClick={() => setView("history")} aria-pressed={view === "history"}>Historia ocupacional</Button>
        </div>
      )}
      {error && <p className="rounded-lg bg-bad-soft px-4 py-3 text-bad">{error}</p>}

      {view === "result" && data && (
        <>
          <OrderHeader o={data.order} />
          {editing ? (
            <ResultForm med={med} order={data.order} exam={data.exam} canFitness={data.can_issue_fitness}
              onCancel={data.exam ? () => setEditing(false) : undefined}
              onSaved={() => { setEditing(false); load(); onSaved?.(); }} />
          ) : data.exam ? (
            <ExamView exam={data.exam} med={med} reauthId={reauth.id} canUpload={data.can_upload && !readOnly} onUploaded={load}
              onEdit={data.can_edit && !readOnly ? () => setEditing(true) : undefined} />
          ) : (
            <p className="text-sm text-muted">Esta orden aún no tiene resultado registrado.</p>
          )}
        </>
      )}

      {view === "history" && history && <HistoryView h={history} reauthId={reauth.id} />}
    </div>
  );
}

function OrderHeader({ o }: { o: Order }) {
  return (
    <div className="rounded-xl border border-line bg-surface p-4">
      <p className="font-semibold">{o.worker} <span className="text-xs font-normal text-muted">{o.document_masked}</span></p>
      <p className="text-sm text-muted">
        EMO {EXAM_TYPES[o.exam_type]?.toLowerCase() ?? o.exam_type}{o.position ? ` · ${o.position}` : ""}{o.clinic_name ? ` · ${o.clinic_name}` : ""} · programado {d(o.scheduled_at)}
      </p>
    </div>
  );
}

function FitnessBadge({ s }: { s: string }) {
  const [label, tone] = FITNESS[s] ?? [s, "bg-bg text-muted"];
  return <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${tone}`}>{label}</span>;
}

function ExamView({ exam, med, reauthId, canUpload, onUploaded, onEdit }: {
  exam: Exam; med: Med; reauthId: string; canUpload: boolean; onUploaded: () => void; onEdit?: () => void;
}) {
  return (
    <section className="space-y-3 rounded-2xl border border-line bg-surface p-5 shadow-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <h3 className="font-semibold">Resultado del {d(exam.exam_on)}</h3>
          <FitnessBadge s={exam.fitness_result} />
        </div>
        {onEdit && <Button size="sm" onClick={onEdit}>Corregir resultado</Button>}
      </div>
      <ExamBody exam={exam} />
      <FileList files={exam.files} reauthId={reauthId} />
      {canUpload && <FileUpload examId={exam.id} med={med} reauthId={reauthId} onUploaded={onUploaded} />}
    </section>
  );
}

function ExamBody({ exam }: { exam: Exam }) {
  return (
    <div className="space-y-2 text-sm">
      {(exam.examining_doctor_name || exam.clinic_name) && (
        <p className="text-muted">
          {exam.clinic_name}{exam.examining_doctor_name ? ` · ${exam.examining_doctor_name}` : ""}{exam.examining_doctor_cmp ? ` (CMP ${exam.examining_doctor_cmp})` : ""}
        </p>
      )}
      {exam.results.length > 0 && (
        <table className="min-w-full text-sm">
          <tbody>
            {exam.results.map((r, i) => (
              <tr key={i} className="border-b border-line last:border-0">
                <td className="py-1.5 pr-3 font-semibold">{r.exam}</td><td className="py-1.5">{r.result || "—"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
      {exam.diagnoses.length > 0 && (
        <p><b>Diagnósticos (CIE-10):</b> {exam.diagnoses.map((x) => `${x.code}${x.description ? ` ${x.description}` : ""}`).join(" · ")}</p>
      )}
      {exam.observations && <p><b>Observaciones:</b> {exam.observations}</p>}
      {exam.recommendations && <p><b>Recomendaciones:</b> {exam.recommendations}</p>}
      {exam.fitness_result !== "pending" && (
        <p><b>Aptitud:</b> {FITNESS[exam.fitness_result]?.[0]}{exam.fitness_valid_until ? ` · vigente hasta ${d(exam.fitness_valid_until)}` : ""}{exam.work_restrictions ? ` · ${exam.work_restrictions}` : ""}</p>
      )}
    </div>
  );
}

/** Descarga con la clave médica en la cabecera (no se puede abrir como enlace simple). */
async function openFile(id: string, reauthId: string) {
  const headers: Record<string, string> = { "X-Medical-Reauth": reauthId };
  const t = session.getToken();
  const c = session.getCompanyId();
  if (t) headers.Authorization = `Bearer ${t}`;
  if (c) headers["X-Company-Id"] = c;
  const res = await fetch(`${API_URL}/api/v1/emo/files/${id}`, { headers });
  if (!res.ok) {
    const j = await res.json().catch(() => ({}));
    throw new ApiError(res.status, j.type ?? "error", j.title ?? "Error", j.detail);
  }
  const url = URL.createObjectURL(await res.blob());
  window.open(url, "_blank", "noopener");
  setTimeout(() => URL.revokeObjectURL(url), 60_000);
}

function FileList({ files, reauthId }: { files: ExamFile[]; reauthId: string }) {
  const [err, setErr] = useState<string | null>(null);
  if (files.length === 0) return <p className="text-xs text-muted">Sin archivos adjuntos.</p>;
  return (
    <div className="space-y-1">
      <p className="text-xs font-semibold text-muted">Archivos</p>
      <ul className="space-y-1 text-sm">
        {files.map((f) => (
          <li key={f.id}>
            <button className="text-brand hover:underline" onClick={() => openFile(f.id, reauthId).catch((e) => setErr(msg(e)))}>
              {FILE_KINDS[f.file_kind] ?? f.file_kind}: {f.name}
            </button>{" "}
            <span className="text-xs text-muted">{Math.max(1, Math.round(f.size_bytes / 1024))} KB · {d(f.created_at)}</span>
          </li>
        ))}
      </ul>
      {err && <p className="text-xs text-bad">{err}</p>}
    </div>
  );
}

function FileUpload({ examId, reauthId, onUploaded }: { examId: string; med: Med; reauthId: string; onUploaded: () => void }) {
  const [kind, setKind] = useState("full_report");
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  async function send() {
    if (!file) return;
    setBusy(true);
    setErr(null);
    try {
      const fd = new FormData();
      fd.append("file", file);
      fd.append("file_kind", kind);
      const headers: Record<string, string> = { Accept: "application/json", "X-Medical-Reauth": reauthId };
      const t = session.getToken();
      const c = session.getCompanyId();
      if (t) headers.Authorization = `Bearer ${t}`;
      if (c) headers["X-Company-Id"] = c;
      const res = await fetch(`${API_URL}/api/v1/emo/exams/${examId}/files`, { method: "POST", headers, body: fd });
      if (!res.ok) {
        const j = await res.json().catch(() => ({}));
        throw new ApiError(res.status, j.type ?? "error", j.title ?? "Error", j.detail, j.errors);
      }
      setFile(null);
      onUploaded();
    } catch (e) {
      setErr(msg(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="flex flex-wrap items-end gap-2 rounded-xl border border-dashed border-line p-3">
      <FormField label="Tipo de archivo">
        <select className="filter-control" value={kind} onChange={(e) => setKind(e.target.value)}>
          {Object.entries(FILE_KINDS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
      </FormField>
      <FormField label="Archivo (PDF o imagen, hasta 15 MB)" error={err ?? undefined}>
        <input type="file" accept="application/pdf,image/jpeg,image/png" className="text-sm" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
      </FormField>
      <Button size="sm" variant="primary" loading={busy} disabled={!file} onClick={send}>Adjuntar</Button>
    </div>
  );
}

function ResultForm({ med, order, exam, canFitness, onCancel, onSaved }: {
  med: Med; order: Order; exam: Exam | null; canFitness: boolean; onCancel?: () => void; onSaved: () => void;
}) {
  const [f, setF] = useState({
    exam_on: exam?.exam_on?.slice(0, 10) ?? new Date().toISOString().slice(0, 10),
    clinic_name: exam?.clinic_name ?? order.clinic_name ?? "", clinic_ruc: exam?.clinic_ruc ?? order.clinic_ruc ?? "",
    examining_doctor_name: exam?.examining_doctor_name ?? "", examining_doctor_cmp: exam?.examining_doctor_cmp ?? "",
    observations: exam?.observations ?? "", recommendations: exam?.recommendations ?? "",
    fitness_result: (exam?.fitness_result ?? "pending") as Fitness, work_restrictions: exam?.work_restrictions ?? "",
    fitness_valid_until: exam?.fitness_valid_until?.slice(0, 10) ?? "",
  });
  const [results, setResults] = useState(exam?.results.length ? exam.results.map((r) => ({ exam: r.exam, result: r.result ?? "" })) : [{ exam: "Examen clínico", result: "" }]);
  const [dx, setDx] = useState(exam?.diagnoses.map((x) => ({ code: x.code, description: x.description ?? "" })) ?? []);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof f, v: string) => setF((p) => ({ ...p, [k]: v }));
  const err = (k: string) => errors[k]?.[0];

  async function save() {
    setBusy(true);
    setErrors({});
    try {
      await med(`/emo/orders/${order.id}/clinical`, {
        method: "PUT",
        body: {
          ...f, clinic_ruc: f.clinic_ruc || null, fitness_valid_until: f.fitness_valid_until || null, work_restrictions: f.work_restrictions || null,
          results: results.filter((r) => r.exam.trim()), diagnoses: dx.filter((x) => x.code.trim()).map((x) => ({ code: x.code.trim().toUpperCase(), description: x.description || null })),
        },
      });
      onSaved();
    } catch (e) {
      setErrors(e instanceof ApiError ? (e.errors ?? { exam_on: [e.detail ?? e.title] }) : { exam_on: ["No pudimos guardar."] });
    } finally {
      setBusy(false);
    }
  }

  const dxError = Object.entries(errors).find(([k]) => k.startsWith("diagnoses"))?.[1]?.[0];

  return (
    <section className="space-y-4 rounded-2xl border border-line bg-surface p-5 shadow-sm">
      <div>
        <h3 className="font-semibold">{exam ? "Corregir resultado" : "Registrar resultado"}</h3>
        <p className="text-xs text-muted">Los hallazgos y diagnósticos quedan solo en la base médica. A la ficha del trabajador pasan la aptitud, su vigencia y las restricciones laborales.</p>
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <FormField label="Fecha del examen" error={err("exam_on")}>
          <input type="date" className="filter-control w-full" value={f.exam_on} onChange={(e) => set("exam_on", e.target.value)} />
        </FormField>
        <FormField label="Clínica" error={err("clinic_name")}>
          <input className="filter-control w-full" value={f.clinic_name} onChange={(e) => set("clinic_name", e.target.value)} />
        </FormField>
        <FormField label="Médico examinador" error={err("examining_doctor_name")}>
          <input className="filter-control w-full" value={f.examining_doctor_name} onChange={(e) => set("examining_doctor_name", e.target.value)} />
        </FormField>
        <FormField label="CMP" error={err("examining_doctor_cmp")}>
          <input className="filter-control w-full" value={f.examining_doctor_cmp} onChange={(e) => set("examining_doctor_cmp", e.target.value)} />
        </FormField>
      </div>

      <div className="space-y-2">
        <p className="text-sm font-semibold">Resultados por examen</p>
        {results.map((r, i) => (
          <div key={i} className="grid gap-2 sm:grid-cols-[14rem_1fr_auto]">
            <select aria-label="Examen" className="filter-control" value={EXAMS.includes(r.exam) ? r.exam : "Otro"}
              onChange={(e) => setResults((p) => p.map((x, j) => (j === i ? { ...x, exam: e.target.value } : x)))}>
              {EXAMS.map((x) => <option key={x}>{x}</option>)}
            </select>
            <input aria-label="Resultado" className="filter-control" placeholder="Resultado o hallazgo" value={r.result}
              onChange={(e) => setResults((p) => p.map((x, j) => (j === i ? { ...x, result: e.target.value } : x)))} />
            <Button size="sm" variant="ghost" onClick={() => setResults((p) => p.filter((_, j) => j !== i))} aria-label="Quitar examen">Quitar</Button>
          </div>
        ))}
        <Button size="sm" onClick={() => setResults((p) => [...p, { exam: "Audiometría", result: "" }])}>+ Agregar examen</Button>
      </div>

      <FormField label="Observaciones" error={err("observations")}>
        <textarea className="filter-control w-full" rows={2} value={f.observations} onChange={(e) => set("observations", e.target.value)} />
      </FormField>

      <div className="space-y-2">
        <p className="text-sm font-semibold">Diagnósticos (CIE-10)</p>
        {dx.map((x, i) => (
          <div key={i} className="grid gap-2 sm:grid-cols-[8rem_1fr_auto]">
            <input aria-label="Código CIE-10" className="filter-control uppercase" placeholder="H83.3" value={x.code}
              onChange={(e) => setDx((p) => p.map((y, j) => (j === i ? { ...y, code: e.target.value } : y)))} />
            <input aria-label="Descripción" className="filter-control" placeholder="Descripción" value={x.description}
              onChange={(e) => setDx((p) => p.map((y, j) => (j === i ? { ...y, description: e.target.value } : y)))} />
            <Button size="sm" variant="ghost" onClick={() => setDx((p) => p.filter((_, j) => j !== i))} aria-label="Quitar diagnóstico">Quitar</Button>
          </div>
        ))}
        {dxError && <p className="text-xs font-medium text-bad">{dxError}</p>}
        <Button size="sm" onClick={() => setDx((p) => [...p, { code: "", description: "" }])}>+ Agregar diagnóstico</Button>
      </div>

      <FormField label="Recomendaciones" error={err("recommendations")}>
        <textarea className="filter-control w-full" rows={2} value={f.recommendations} onChange={(e) => set("recommendations", e.target.value)} />
      </FormField>

      <div className="grid gap-3 sm:grid-cols-2">
        <FormField label="Aptitud" hint={canFitness ? undefined : "Solo el médico ocupacional registra la aptitud."} error={err("fitness_result")}>
          <select className="filter-control w-full" value={f.fitness_result} disabled={!canFitness} onChange={(e) => set("fitness_result", e.target.value)}>
            {(["pending", "fit", "fit_with_restrictions", "unfit"] as const).map((k) => <option key={k} value={k}>{FITNESS[k][0]}</option>)}
          </select>
        </FormField>
        {(f.fitness_result === "fit" || f.fitness_result === "fit_with_restrictions") && (
          <FormField label="Vigente hasta" error={err("fitness_valid_until")}>
            <input type="date" className="filter-control w-full" value={f.fitness_valid_until} onChange={(e) => set("fitness_valid_until", e.target.value)} />
          </FormField>
        )}
      </div>
      {f.fitness_result !== "pending" && (
        <FormField label="Restricciones de trabajo" hint="En términos laborales y sin diagnóstico (p. ej. «No levantar más de 15 kg»): es lo que ven RR.HH. y los jefes." error={err("work_restrictions")}>
          <textarea className="filter-control w-full" rows={2} value={f.work_restrictions} onChange={(e) => set("work_restrictions", e.target.value)} />
        </FormField>
      )}

      <div className="flex justify-end gap-2">
        {onCancel && <Button onClick={onCancel}>Cancelar</Button>}
        <Button variant="primary" loading={busy} onClick={save}>Guardar resultado</Button>
      </div>
    </section>
  );
}

function HistoryView({ h, reauthId }: { h: History; reauthId: string }) {
  return (
    <section className="space-y-3">
      <div className="rounded-xl border border-line bg-surface p-4">
        <p className="font-semibold">{h.worker.full_name} <span className="text-xs font-normal text-muted">{h.worker.document_masked}</span></p>
        <p className="text-sm text-muted">
          {h.worker.position ?? "Sin puesto"} · Aptitud actual: {h.fitness ? <FitnessBadge s={h.fitness.status} /> : "sin registro"}
          {h.fitness?.valid_until ? ` · vigente hasta ${d(h.fitness.valid_until)}` : ""}
        </p>
      </div>
      {h.exams.length === 0 ? (
        <p className="text-sm text-muted">Aún no hay exámenes registrados para este trabajador.</p>
      ) : (
        <ol className="space-y-3 border-l-2 border-line pl-4">
          {h.exams.map((e) => (
            <li key={e.id} className="space-y-2 rounded-xl border border-line bg-surface p-4">
              <div className="flex flex-wrap items-center gap-2">
                <p className="font-semibold">{d(e.exam_on)} · EMO {EXAM_TYPES[e.exam_type]?.toLowerCase() ?? e.exam_type}</p>
                <FitnessBadge s={e.fitness_result} />
              </div>
              <ExamBody exam={e} />
              <FileList files={e.files} reauthId={reauthId} />
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}

function Reauth({ onOk }: { onOk: (id: string, minutes: number) => void }) {
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ data: { reauth_id: string; expires_at: string } }>("/emo/reauth", { method: "POST", body: { password }, withCompany: true });
      onOk(r.data.reauth_id, Math.max(1, Math.round((new Date(r.data.expires_at).getTime() - Date.now()) / 60_000)));
    } catch (err) {
      setError(err instanceof ApiError && err.status === 403 ? "Solo el médico ocupacional ve los resultados clínicos. Tu rol ve la aptitud y su vigencia." : msg(err));
    } finally {
      setBusy(false);
    }
  }
  return (
    <form onSubmit={submit} className="max-w-md space-y-3 rounded-2xl border border-line bg-surface p-6 shadow-sm">
      <h2 className="text-lg font-semibold">Confirma tu contraseña</h2>
      <p className="text-sm text-muted">Vas a ver datos médicos confidenciales. Por seguridad, confirma tu contraseña: el acceso dura unos minutos y cada consulta queda registrada.</p>
      <FormField label="Contraseña" error={error ?? undefined}>
        <input type="password" aria-label="Contraseña" autoComplete="current-password" className="filter-control w-full" value={password} onChange={(e) => setPassword(e.target.value)} />
      </FormField>
      <Button type="submit" variant="primary" loading={busy} disabled={!password}>Ver datos médicos</Button>
    </form>
  );
}
