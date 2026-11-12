"use client";

// Programa Anual SST (M-PAS SCR-PAS-01; prototipo m-modulos.js R.PAS): cronograma actividad × 12 meses con celdas de
// estado (programado, ejecutado, atrasado, ejecutado fuera de lo programado), siempre con texto además del color, y el %
// de avance global y por objetivo (RN-PAS-01). Tocar una celda registra la ejecución de ese mes (reglas de
// activity_executions del motor común: programa aprobado, evidencia obligatoria). Un mes programado sin ejecución se puede
// «Reprogramar» a otro mes del mismo año con motivo (RN-PAS-02): el mes original queda «Reprog.» y el historial se conserva.

import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { records, uploadFile } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { EmptyState } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Toast } from "@/components/ui/Toast";
import { Bar, Card, Kpi, TONE_TEXT, toneOf } from "./Summary";

type CellState = "executed" | "extra" | "overdue" | "planned" | "cancelled" | "rescheduled" | null;
type Cell = { month: number; planned: boolean; quantity: number; state: CellState; rescheduled_to?: number; reschedule_reason?: string };
type Reschedule = { id: string; from_month: number; to_month: number; reason: string; created_at: string; created_by_name: string | null };
type Exec = { id: string; executed_on: string; quantity: number; description: string | null; evidence_file_id: string | null; evidence_name: string | null; source_module: string | null };
type Activity = {
  id: string; code: string | null; title: string; status: string; goal_quantity: number; executed_quantity: number; progress_pct: number;
  objective_title: string | null; responsible_name: string | null; cells: Cell[]; executions: Exec[]; reschedules: Reschedule[];
};
type Program = { id: string; year: number; title: string; status: string; progress_pct: number };
type Objective = { id: string | null; title: string; code: string | null; due: number; done: number; pct: number | null; activities: number };
type Schedule = {
  programs: Program[]; program: Program | null; year: number; cutoff_month: number | null; activities: Activity[]; objectives: Objective[];
  progress: { due: number; done: number; pct: number | null; overdue: number; planned: number; executed: number; rescheduled?: number } | null;
  can_register: boolean; can_reschedule: boolean;
};

const MONTHS = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];
const MONTHS_FULL = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
const STATE: Record<Exclude<CellState, null>, { short: string; long: string; cls: string }> = {
  executed: { short: "Ejec.", long: "Ejecutado", cls: "bg-ok-soft text-ok border-ok/30" },
  extra: { short: "Ejec.*", long: "Ejecutado fuera de lo programado", cls: "bg-brand-soft text-brand-strong border-brand/30" },
  overdue: { short: "Atras.", long: "Atrasado", cls: "bg-bad-soft text-bad border-bad/30" },
  planned: { short: "Prog.", long: "Programado", cls: "bg-amber-soft text-amber-ink border-[#F4B90F]/40" },
  cancelled: { short: "Canc.", long: "Actividad cancelada", cls: "bg-na-soft text-muted border-line" },
  rescheduled: { short: "Reprog.", long: "Reprogramado", cls: "bg-na-soft text-fg border-line border-dashed" },
};
const P_STATUS: Record<string, string> = { draft: "Borrador", committee_review: "En revisión del comité", approved: "Aprobado", in_execution: "En ejecución", closed: "Cerrado" };
const pad = (n: number) => String(n).padStart(2, "0");
const errText = (e: unknown) => {
  const x = e as ApiError;
  return Object.values(x.errors ?? {})[0]?.[0] ?? x.detail ?? x.title;
};

export function PasSchedule({ readOnly }: { readOnly: boolean }) {
  const current = new Date().getFullYear();
  const [year, setYear] = useState(current);
  const [programId, setProgramId] = useState("");
  const [data, setData] = useState<Schedule | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState<{ a: Activity; month: number } | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const load = useCallback(() => {
    setError(null);
    const q = new URLSearchParams({ year: String(year) });
    if (programId) q.set("program_id", programId);
    api<{ data: Schedule }>(`/pas/schedule?${q}`, { withCompany: true }).then((r) => setData(r.data)).catch((e) => setError(errText(e)));
  }, [year, programId]);
  useEffect(load, [load]);

  if (error) return <p className="rounded-xl border border-line bg-surface p-4 text-sm text-muted">{error}</p>;
  if (!data) return <div className="h-64 animate-pulse rounded-xl bg-surface" />;
  const years = Array.from(new Set([current, ...data.programs.map((p) => p.year)])).sort((a, b) => b - a);
  const sameYear = data.programs.filter((p) => p.year === data.year);
  const p = data.progress;
  const canClick = data.can_register && !readOnly;

  return (
    <div className="space-y-4">
      <Card
        title={data.program ? data.program.title : `Programa Anual SST ${year}`}
        action={
          <div className="flex flex-wrap items-center gap-2">
            {sameYear.length > 1 && (
              <select className="filter-control !w-auto" aria-label="Programa" value={data.program?.id ?? ""} onChange={(e) => setProgramId(e.target.value)}>
                {sameYear.map((x) => <option key={x.id} value={x.id}>{x.title} · {P_STATUS[x.status] ?? x.status}</option>)}
              </select>
            )}
            <select className="filter-control !w-auto" aria-label="Año" value={data.year} onChange={(e) => { setProgramId(""); setYear(Number(e.target.value)); }}>
              {years.map((y) => <option key={y} value={y}>{y}</option>)}
            </select>
          </div>
        }
      >
        {!data.program ? (
          <EmptyState icon="calendar" text={`No hay Programa Anual SST para ${year}. Créalo en la lista de abajo («Programas anuales»).`} />
        ) : (
          <div className="space-y-4">
            <p className="text-sm text-muted">
              Estado: <b className="text-fg">{P_STATUS[data.program.status] ?? data.program.status}</b>
              {data.cutoff_month ? ` · Avance calculado hasta ${MONTHS_FULL[data.cutoff_month - 1]} ${data.year}` : " · El año aún no empieza"}
              {!data.can_register && ["draft", "committee_review"].includes(data.program.status) && " · Las ejecuciones se registran cuando el programa está aprobado."}
            </p>
            {p && (
              <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Kpi label="Avance del programa" value={p.pct === null ? "—" : `${p.pct} %`} color={TONE_TEXT[toneOf(p.pct)]} sub={`${p.done} de ${p.due} actividades-mes a la fecha`} />
                <Kpi label="Actividades" value={String(data.activities.length)} color="text-fg" />
                <Kpi label="Ejecutadas (mes)" value={String(p.executed)} color="text-ok" />
                <Kpi label="Atrasadas (mes)" value={String(p.overdue)} color={p.overdue ? "text-bad" : "text-muted"} />
              </div>
            )}
            <Legend />
            {data.activities.length === 0 ? (
              <EmptyState icon="calendar" text="El programa aún no tiene actividades. Agrégalas en la pestaña «Actividades» de abajo." />
            ) : (
              <div className="overflow-x-auto rounded-xl border border-line">
                <table className="w-full min-w-[56rem] text-left text-sm">
                  <thead className="border-b border-line bg-bg text-xs font-semibold text-muted">
                    <tr>
                      <th className="sticky left-0 z-10 bg-bg px-3 py-2">Actividad</th>
                      {MONTHS.map((m, i) => (
                        <th key={m} className={`px-1 py-2 text-center ${data.cutoff_month === i + 1 && data.year === current ? "text-brand-strong" : ""}`}>{m}</th>
                      ))}
                      <th className="px-3 py-2 text-right">Avance</th>
                    </tr>
                  </thead>
                  <tbody>
                    {data.activities.map((a) => (
                      <tr key={a.id} className="border-b border-line last:border-0">
                        <td className="sticky left-0 z-10 max-w-[16rem] bg-surface px-3 py-2">
                          <p className="font-medium leading-tight">{a.code ? `${a.code} · ` : ""}{a.title}</p>
                          <p className="text-xs text-muted">{[a.objective_title, a.responsible_name].filter(Boolean).join(" · ") || "Sin objetivo ni responsable"}</p>
                        </td>
                        {a.cells.map((c) => {
                          const s = c.state ? STATE[c.state] : null;
                          const label = `${a.title}, ${MONTHS_FULL[c.month - 1]}: ${s ? s.long : "sin programar"}${c.rescheduled_to ? ` a ${MONTHS_FULL[c.rescheduled_to - 1]}` : ""}${c.quantity ? ` (${c.quantity})` : ""}`;
                          const clickable = canClick && a.status !== "cancelled";
                          return (
                            <td key={c.month} className="px-0.5 py-1.5 text-center">
                              <button
                                type="button"
                                disabled={!clickable && !s}
                                onClick={() => (clickable || s) && setOpen({ a, month: c.month })}
                                aria-label={label}
                                title={label}
                                className={`h-9 w-full min-w-[2.75rem] rounded-md border text-[11px] font-semibold ${s ? s.cls : "border-transparent text-line hover:border-line"} ${clickable ? "hover:ring-2 hover:ring-brand/40" : ""} disabled:cursor-default`}
                              >
                                {s ? s.short : clickable ? "+" : ""}
                              </button>
                            </td>
                          );
                        })}
                        <td className="px-3 py-2 text-right tabular-nums">
                          <span className={TONE_TEXT[toneOf(Number(a.progress_pct))]}>{Number(a.progress_pct).toLocaleString("es-PE", { maximumFractionDigits: 0 })} %</span>
                          <span className="block text-[11px] text-muted">{a.executed_quantity}/{a.goal_quantity}</span>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>
        )}
      </Card>
      {data.program && data.objectives.length > 0 && (
        <Card title="Avance por objetivo">
          <ul className="space-y-3">
            {data.objectives.map((o) => (
              <li key={o.id ?? "none"} className="space-y-1">
                <div className="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                  <span className="font-medium">{o.code ? `${o.code} · ` : ""}{o.title} <span className="text-xs font-normal text-muted">· {o.activities} actividad(es)</span></span>
                  <span className={`font-semibold tabular-nums ${TONE_TEXT[toneOf(o.pct)]}`}>{o.pct === null ? "Sin meses a la fecha" : `${o.pct} % · ${o.done} de ${o.due}`}</span>
                </div>
                <Bar pct={o.pct ?? 0} tone={o.pct === null ? "na" : undefined} />
              </li>
            ))}
          </ul>
        </Card>
      )}
      {open && data.program && (
        <ExecutionModal
          activity={open.a}
          month={open.month}
          year={data.year}
          canRegister={canClick && open.a.status !== "cancelled"}
          canReschedule={data.can_reschedule && !readOnly && open.a.status !== "cancelled"}
          currentMonth={data.year === current ? new Date().getMonth() + 1 : data.year > current ? 1 : 13}
          onClose={() => setOpen(null)}
          onDone={(text) => {
            setOpen(null);
            setToast(text);
            load();
          }}
        />
      )}
      {toast && <Toast message={toast} onClose={() => setToast(null)} />}
    </div>
  );
}

function Legend() {
  return (
    <ul className="flex flex-wrap gap-2 text-xs" aria-label="Leyenda del cronograma">
      {(["planned", "executed", "overdue", "extra", "rescheduled"] as const).map((k) => (
        <li key={k} className={`rounded-md border px-2 py-1 font-semibold ${STATE[k].cls}`}>
          {STATE[k].short} = {STATE[k].long}
        </li>
      ))}
    </ul>
  );
}

function ExecutionModal({ activity, month, year, canRegister, canReschedule, currentMonth, onClose, onDone }: {
  activity: Activity; month: number; year: number; canRegister: boolean; canReschedule: boolean; currentMonth: number; onClose: () => void; onDone: (text: string) => void;
}) {
  const today = new Date();
  const todayStr = `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`;
  const first = `${year}-${pad(month)}-01`;
  const last = `${year}-${pad(month)}-${pad(new Date(year, month, 0).getDate())}`;
  const max = last < todayStr ? last : todayStr;
  const future = first > todayStr;
  const [form, setForm] = useState({ executed_on: future ? first : max, quantity: "1", description: "" });
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);
  const list = activity.executions.filter((e) => e.executed_on.slice(0, 7) === `${year}-${pad(month)}`);
  const cell = activity.cells.find((c) => c.month === month);
  const [resched, setResched] = useState<{ to: string; reason: string } | null>(null);
  const targets = Array.from({ length: 12 }, (_, i) => i + 1).filter((m) => m >= currentMonth && m !== month && !activity.cells.find((c) => c.month === m)?.planned);
  const canMove = canReschedule && !!cell?.planned && cell.quantity === 0 && (cell.state === "overdue" || cell.state === "planned") && targets.length > 0;
  const history = activity.reschedules.filter((r) => r.from_month === month || r.to_month === month);

  const reschedule = async () => {
    if (!resched) return;
    setBusy(true);
    setMsg(null);
    try {
      await api(`/pas/activities/${activity.id}/reschedule`, {
        method: "POST", withCompany: true, body: { from_month: month, to_month: Number(resched.to), reason: resched.reason.trim() },
      });
      onDone(`Actividad reprogramada a ${MONTHS_FULL[Number(resched.to) - 1]}.`);
    } catch (e) {
      setMsg(errText(e));
    } finally {
      setBusy(false);
    }
  };

  const save = async () => {
    setBusy(true);
    setMsg(null);
    try {
      const evidence = file ? await uploadFile(file, "PAS") : null;
      await records.create("PAS", "activity_executions", {
        activity_id: activity.id, executed_on: form.executed_on, quantity: Number(form.quantity), description: form.description.trim() || `Evidencia adjunta: ${file?.name ?? ""}`, evidence_file_id: evidence?.id ?? null,
      });
      onDone("Ejecución registrada con su evidencia.");
    } catch (e) {
      setMsg(errText(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      title="Registrar ejecución"
      subtitle={`${activity.code ? `${activity.code} · ` : ""}${activity.title} · ${MONTHS_FULL[month - 1]} ${year}`}
      icon="calendar"
      size="md"
      onClose={onClose}
      footer={
        resched ? (
          <div className="flex w-full justify-end gap-2">
            <Button variant="ghost" onClick={() => setResched(null)}>Volver</Button>
            <Button variant="primary" loading={busy} disabled={!resched.to || resched.reason.trim().length < 5} onClick={reschedule}>Reprogramar</Button>
          </div>
        ) : (canRegister && !future) || canMove ? (
          <div className="flex w-full flex-wrap justify-end gap-2">
            {canMove && <Button variant="secondary" onClick={() => { setMsg(null); setResched({ to: String(targets[0]), reason: "" }); }}>Reprogramar</Button>}
            <Button variant="ghost" onClick={onClose}>Cancelar</Button>
            {canRegister && !future && (
              <Button variant="primary" loading={busy} disabled={!file} onClick={save}>Registrar ejecución</Button>
            )}
          </div>
        ) : undefined
      }
    >
      <div className="space-y-4">
        <p className="text-sm">
          Estado del mes: <b>{cell?.state ? STATE[cell.state].long : "Sin programar"}</b>
          {cell?.planned ? "" : " (este mes no estaba programado)"} · Avance de la actividad: {activity.executed_quantity} de {activity.goal_quantity}
        </p>
        {list.length > 0 && (
          <ul className="space-y-2 text-sm">
            {list.map((e) => (
              <li key={e.id} className="rounded-lg bg-bg px-3 py-2">
                <b>{e.executed_on.slice(8, 10)}/{e.executed_on.slice(5, 7)}</b> · {e.quantity} · {e.description ?? (e.source_module ? `Desde ${e.source_module}` : "")}
                {e.evidence_name && <span className="block text-xs text-muted">Evidencia: {e.evidence_name}</span>}
              </li>
            ))}
          </ul>
        )}
        {history.length > 0 && (
          <ul className="space-y-2 text-sm" aria-label="Historial de reprogramaciones">
            {history.map((r) => (
              <li key={r.id} className="rounded-lg border border-dashed border-line px-3 py-2">
                <b>Reprogramado de {MONTHS_FULL[r.from_month - 1]} a {MONTHS_FULL[r.to_month - 1]}</b>
                <span className="block text-xs text-muted">Motivo: {r.reason} · {r.created_by_name ?? "—"} · {new Date(r.created_at).toLocaleDateString("es-PE")}</span>
              </li>
            ))}
          </ul>
        )}
        {resched ? (
          <div className="grid gap-3 sm:grid-cols-[12rem_1fr]">
            <FormField label="Nuevo mes">
              <select className="input" value={resched.to} onChange={(e) => setResched({ ...resched, to: e.target.value })}>
                {targets.map((m) => <option key={m} value={m}>{MONTHS_FULL[m - 1]} {year}</option>)}
              </select>
            </FormField>
            <FormField label="Motivo" hint="Obligatorio. Queda en el historial de la actividad (RN-PAS-02).">
              <textarea className="input min-h-[5rem]" maxLength={500} value={resched.reason} onChange={(e) => setResched({ ...resched, reason: e.target.value })} />
            </FormField>
          </div>
        ) : cell?.state === "rescheduled" ? (
          <p className="text-sm text-muted">Este mes se reprogramó{cell.rescheduled_to ? ` a ${MONTHS_FULL[cell.rescheduled_to - 1]}` : ""}.</p>
        ) : !canRegister ? (
          <p className="text-sm text-muted">No puedes registrar ejecuciones en este programa (debe estar aprobado y tu rol debe permitirlo).</p>
        ) : future ? (
          <p className="text-sm text-muted">El mes aún no empieza: la ejecución se registra cuando se realiza.</p>
        ) : (
          <div className="grid gap-3 sm:grid-cols-[10rem_7rem_1fr]">
            <FormField label="Fecha">
              <input className="input" type="date" min={first} max={max} value={form.executed_on} onChange={(e) => setForm({ ...form, executed_on: e.target.value })} />
            </FormField>
            <FormField label="Cantidad">
              <input className="input" type="number" min={1} value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} />
            </FormField>
            <FormField label="Evidencia" hint="Acta, informe o registro que lo prueba (RN-PAS-04).">
              <input className="input" maxLength={500} placeholder="p. ej. Acta de inspección INS-2026-031" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </FormField>
            <div className="sm:col-span-3">
              <FormField label="Archivo de evidencia (opcional)">
                <input className="input" type="file" accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
              </FormField>
            </div>
          </div>
        )}
        {msg && <p role="alert" className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{msg}</p>}
      </div>
    </Modal>
  );
}
