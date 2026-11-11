"use client";

// Asistente (complemento IAA, docs/modulos/M-IAA.md SCR-IAA-01, 02 y 05; prototipo m-diferenciadores.js R.IAA):
// IPERC sugerido por puesto, investigación guiada y el historial de solicitudes. Todo es propuesta: cada sugerencia
// queda pendiente hasta que una persona la acepta (entra al borrador del IPERC o al árbol de causas) o la descarta.

import { useEffect, useState } from "react";
import { ApiError } from "@/lib/api";
import { bandOf, HIERARCHY, levelClass, type Criteria } from "@/lib/ipc";
import { CAUSE_LEVEL, iaa, type CausePayload, type GuideResult, type HistoryRow, type IaaControl, type IpercPayload, type IpercSuggestResult, type Suggestion } from "@/lib/iaa";
import type { RecordField, RecordRow } from "@/lib/records";
import { RefSelect } from "@/components/records/ModuleRecords";
import { Button } from "@/components/ui/Button";
import { Chip, EmptyState } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { useApp } from "@/components/AppContext";
import { InvCauseTree } from "@/components/modules/InvCauseTree";

const refField = (name: string, label: string, ref: string): RecordField => ({ name, label, type: "ref", ref });
const errText = (e: unknown) => {
  const ae = e as ApiError;
  return Object.values(ae.errors ?? {})[0]?.[0] ?? ae.detail ?? ae.title ?? "No se pudo completar.";
};
const hierarchyLabel = (h: string) => HIERARCHY.find((x) => x[0] === h)?.[1] ?? h;

function probOf(c: Criteria | null, p: IpercPayload): number | null {
  if (!c) return null;
  if (c.method === "pxs_index") return p.probability_detail ? Object.values(p.probability_detail).reduce((a, b) => a + Number(b), 0) : null;
  return p.probability_value ?? null;
}

export function Rules() {
  return (
    <div className="rounded-xl border border-line bg-surface p-4 text-sm">
      <h3 className="font-display font-semibold">Reglas del asistente</h3>
      <ul className="mt-2 list-disc space-y-1 pl-5 text-muted">
        <li>Solo sugiere: una persona acepta, edita o descarta cada propuesta.</li>
        <li>Responde con la biblioteca que entrena el equipo de EscudoSST y el catálogo de peligros.</li>
        <li>No usa ni guarda datos médicos.</li>
        <li>Queda registro de quién aceptó cada sugerencia.</li>
      </ul>
    </div>
  );
}

// ─────────────── IPERC sugerido (SCR-IAA-01) ───────────────

export function IpercSuggest({ positionId, positionLabel, matrixId, onAccepted }: { positionId?: string | null; positionLabel?: string | null; matrixId?: string; onAccepted?: () => void }) {
  const [position, setPosition] = useState(positionId ?? "");
  const [context, setContext] = useState("");
  const [res, setRes] = useState<IpercSuggestResult | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<Record<string, string>>({});
  const [editing, setEditing] = useState<Suggestion<IpercPayload> | null>(null);

  async function generate() {
    setBusy(true);
    setError(null);
    try {
      const r = await iaa.ipercSuggest({ position_id: position, ...(matrixId ? { matrix_id: matrixId } : {}), ...(context.trim() ? { context: context.trim() } : {}) });
      setRes(r.data);
      setDone({});
    } catch (e) {
      setError(errText(e));
    } finally {
      setBusy(false);
    }
  }

  async function accept(s: Suggestion<IpercPayload>, body: Record<string, unknown> = {}) {
    try {
      const r = await iaa.accept(s.id, body);
      setDone((d) => ({ ...d, [s.id]: `Aceptada · agregada al borrador v${res?.draft?.version_number ?? ""} como fila ${r.data.row_number ?? ""}${r.data.controls ? ` con ${r.data.controls} control(es)` : ""}` }));
      setEditing(null);
      onAccepted?.();
    } catch (e) {
      setError(errText(e));
    }
  }

  async function reject(s: Suggestion<IpercPayload>) {
    try {
      await iaa.reject(s.id);
      setDone((d) => ({ ...d, [s.id]: "Descartada" }));
    } catch (e) {
      setError(errText(e));
    }
  }

  const c = res?.criteria ?? null;
  const canAccept = !!res?.draft;

  return (
    <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
      <div className="space-y-3">
        <p className="rounded-xl border border-amber-ink/30 bg-amber-soft px-4 py-3 text-sm text-amber-ink">
          <b>El asistente solo sugiere.</b> Cada fila queda pendiente hasta que la aceptes. Las aceptadas entran al borrador del IPERC, que luego aprueba el Comité SST.
        </p>
        {error && <p role="alert" className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">{error}</p>}
        {!res ? (
          <EmptyState icon="sparkle" text="Elige el puesto y pide sugerencias: peligros, riesgos y controles por jerarquía." />
        ) : (
          <section className="space-y-3 rounded-xl border border-line bg-surface p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h3 className="font-display font-semibold">Sugerencias para el puesto «{res.position.name}»</h3>
              <span className="text-xs text-muted">{res.matrix ? `Matriz ${res.matrix.code}${res.draft ? ` · borrador v${res.draft.version_number}` : ""}` : "Sin matriz"}</span>
            </div>
            {res.note && <p className="rounded-lg bg-bg px-3 py-2 text-sm text-amber-ink">{res.note}</p>}
            {res.suggestions.length === 0 && <p className="text-sm text-muted">No encontré peligros para ese puesto. Agrega más contexto (tareas, equipos, lugar) y vuelve a intentar.</p>}
            {res.suggestions.map((s) => {
              const p = probOf(c, s.payload);
              const score = p && s.payload.severity_value ? p * s.payload.severity_value : null;
              const band = c && score ? bandOf(c, score) : null;
              const state = done[s.id];
              return (
                <article key={s.id} className={`space-y-1.5 rounded-xl border p-3 text-sm ${state?.startsWith("Acept") ? "border-ok/40 bg-ok-soft/30" : state ? "border-line opacity-60" : "border-line"}`}>
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <b>{s.payload.hazard}</b>
                    {band && c && <span className={`rounded-md px-2 py-0.5 text-xs font-semibold ${levelClass(c, band.code)}`}>{band.label} · {score}</span>}
                  </div>
                  <p>{s.payload.risk}{s.payload.consequence ? ` · ${s.payload.consequence}` : ""}</p>
                  {s.payload.controls.length > 0 && (
                    <p className="text-muted">Controles: {s.payload.controls.map((x) => `${hierarchyLabel(x.hierarchy)}: ${x.description}`).join(" · ")}</p>
                  )}
                  {s.rationale && <p className="text-xs text-muted">{s.rationale} Evaluación propuesta: revísala antes de aceptar.</p>}
                  {state ? (
                    <Chip tone={state.startsWith("Acept") ? "ok" : "na"}>{state}</Chip>
                  ) : (
                    <div className="flex flex-wrap gap-2 pt-1">
                      <Button size="sm" variant="primary" disabled={!canAccept} onClick={() => accept(s)}>Aceptar</Button>
                      <Button size="sm" disabled={!canAccept} onClick={() => setEditing(s)}>Editar</Button>
                      <Button size="sm" variant="ghost" onClick={() => reject(s)}>Descartar</Button>
                    </div>
                  )}
                </article>
              );
            })}
          </section>
        )}
      </div>
      <div className="space-y-4">
        <section className="space-y-3 rounded-xl border border-line bg-surface p-4">
          <h3 className="font-display font-semibold">Pedir sugerencias</h3>
          <FormField label="Puesto *">
            <RefSelect code="IPC" table="iperc_rows" f={refField("position_id", "Puesto", "positions")} value={position} initialLabel={positionLabel ?? undefined} disabled={!!positionId} invalid={false} onChange={(v) => setPosition((v as string) ?? "")} />
          </FormField>
          <FormField label="Contexto adicional" hint="Tareas, equipos o lugar. Sin datos de salud ni nombres.">
            <textarea className="input min-h-[80px] py-2" maxLength={1000} value={context} onChange={(e) => setContext(e.target.value)} placeholder="Ej.: soldadura MIG en nave con ventilación natural" />
          </FormField>
          <Button variant="primary" icon="sparkle" loading={busy} disabled={!position} onClick={generate}>Generar sugerencias</Button>
        </section>
        <Rules />
      </div>
      {editing && c && <EditSuggestion s={editing} c={c} onClose={() => setEditing(null)} onSave={(body) => accept(editing, body)} />}
    </div>
  );
}

function EditSuggestion({ s, c, onClose, onSave }: { s: Suggestion<IpercPayload>; c: Criteria; onClose: () => void; onSave: (body: Record<string, unknown>) => void }) {
  const p = s.payload;
  const [f, setF] = useState({ activity: p.activity, task: p.task, hazard: p.hazard, risk: p.risk, consequence: p.consequence ?? "", severity: String(p.severity_value ?? ""), probability: String(p.probability_value ?? "") });
  const [detail, setDetail] = useState<Record<string, string>>(Object.fromEntries(Object.entries(p.probability_detail ?? {}).map(([k, v]) => [k, String(v)])));
  const [controls, setControls] = useState<IaaControl[]>(p.controls);
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }));

  const save = () => {
    const row: Record<string, unknown> = { activity: f.activity, task: f.task, hazard: f.hazard, risk: f.risk, consequence: f.consequence || null, severity_value: Number(f.severity) };
    if (c.method === "pxs_index") row.probability_detail = Object.fromEntries(Object.entries(detail).map(([k, v]) => [k, Number(v)]));
    else row.probability_value = Number(f.probability);
    row.controls = controls.filter((x) => x.description.trim());
    onSave({ row });
  };
  const sel = (value: string, onChange: (v: string) => void, options: { value: number; label: string }[], label: string) => (
    <select className="input" aria-label={label} value={value} onChange={(e) => onChange(e.target.value)}>
      {options.map((o) => <option key={o.value} value={o.value}>{o.value} · {o.label}</option>)}
    </select>
  );

  return (
    <Modal title="Editar sugerencia" subtitle="Ajusta la fila y sus controles antes de agregarla al borrador." icon="pen" size="lg" onClose={onClose}
      footer={<div className="flex w-full justify-end gap-2"><Button onClick={onClose}>Cancelar</Button><Button variant="primary" onClick={save}>Guardar y aceptar</Button></div>}>
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-2">
          <FormField label="Actividad"><input className="input" value={f.activity} onChange={(e) => set("activity", e.target.value)} /></FormField>
          <FormField label="Tarea"><input className="input" value={f.task} onChange={(e) => set("task", e.target.value)} /></FormField>
          <FormField label="Peligro"><input className="input" value={f.hazard} onChange={(e) => set("hazard", e.target.value)} /></FormField>
          <FormField label="Riesgo"><input className="input" value={f.risk} onChange={(e) => set("risk", e.target.value)} /></FormField>
          <div className="sm:col-span-2"><FormField label="Consecuencia"><input className="input" value={f.consequence} onChange={(e) => set("consequence", e.target.value)} /></FormField></div>
          {c.method === "pxs_index"
            ? c.factors.map((x) => <FormField key={x.code} label={x.label}>{sel(detail[x.code] ?? "", (v) => setDetail((d) => ({ ...d, [x.code]: v })), x.levels, x.label)}</FormField>)
            : <FormField label="Probabilidad">{sel(f.probability, (v) => set("probability", v), c.probability_scale, "Probabilidad")}</FormField>}
          <FormField label="Severidad">{sel(f.severity, (v) => set("severity", v), c.severity_scale, "Severidad")}</FormField>
        </div>
        <div className="space-y-2">
          <p className="text-sm font-semibold">Controles</p>
          {controls.map((x, i) => (
            <div key={i} className="grid gap-2 sm:grid-cols-[12rem_1fr_auto]">
              <select className="input" aria-label="Jerarquía" value={x.hierarchy} onChange={(e) => setControls(controls.map((y, k) => (k === i ? { ...y, hierarchy: e.target.value } : y)))}>
                {HIERARCHY.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
              <input className="input" aria-label="Control" value={x.description} onChange={(e) => setControls(controls.map((y, k) => (k === i ? { ...y, description: e.target.value } : y)))} />
              <Button variant="ghost" icon="trash" aria-label="Quitar control" onClick={() => setControls(controls.filter((_, k) => k !== i))} />
            </div>
          ))}
          <Button size="sm" variant="soft" icon="plus" onClick={() => setControls([...controls, { hierarchy: "engineering", description: "" }])}>Agregar control</Button>
        </div>
      </div>
    </Modal>
  );
}

// ─────────────── Investigación guiada (SCR-IAA-02) ───────────────

export function InvestigationGuide({ investigationId, investigationLabel, onAccepted }: { investigationId?: string; investigationLabel?: string; onAccepted?: () => void }) {
  const [inv, setInv] = useState(investigationId ?? "");
  const [res, setRes] = useState<GuideResult | null>(null);
  const [answers, setAnswers] = useState<string[]>([]);
  const [items, setItems] = useState<(Suggestion<CausePayload> & { on: boolean; state?: string })[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);

  async function start(withAnswers = false) {
    setBusy(true);
    setError(null);
    setMsg(null);
    try {
      const r = await iaa.guide({ investigation_id: inv, ...(withAnswers ? { answers } : {}) });
      setRes(r.data);
      if (!withAnswers) setAnswers(r.data.questions.map(() => ""));
      setItems(r.data.suggestions.map((s) => ({ ...s, on: true })));
    } catch (e) {
      setError(errText(e));
    } finally {
      setBusy(false);
    }
  }

  async function acceptAll() {
    setBusy(true);
    setError(null);
    let ok = 0;
    const next = [...items];
    for (const [i, s] of next.entries()) {
      if (!s.on || s.state) continue;
      try {
        await iaa.accept(s.id, { description: s.payload.description, cause_level: s.payload.cause_level });
        next[i] = { ...s, state: "En el árbol" };
        ok++;
      } catch (e) {
        setError(errText(e));
        break;
      }
    }
    setItems(next);
    setBusy(false);
    if (ok) {
      setMsg(`${ok} causa(s) agregada(s) al árbol de la investigación.`);
      onAccepted?.();
    }
  }

  async function discard(i: number) {
    try {
      await iaa.reject(items[i].id);
      setItems(items.map((x, k) => (k === i ? { ...x, state: "Descartada", on: false } : x)));
    } catch (e) {
      setError(errText(e));
    }
  }

  const edit = (i: number, patch: Partial<CausePayload>) => setItems(items.map((x, k) => (k === i ? { ...x, payload: { ...x.payload, ...patch } } : x)));
  const pending = items.filter((x) => x.on && !x.state).length;

  return (
    <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_20rem]">
      <div className="space-y-3">
        {error && <p role="alert" className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">{error}</p>}
        {msg && <p role="status" className="rounded-lg bg-ok-soft px-4 py-3 text-sm text-ok">{msg}</p>}
        {!res ? (
          <EmptyState icon="sparkle" text="Elige la investigación: el asistente hace las preguntas del método y propone causas. Quien investiga decide." />
        ) : (
          <section className="space-y-3 rounded-xl border border-line bg-surface p-4">
            <h3 className="font-display font-semibold">Investigación guiada · {res.investigation.code ?? res.investigation.event_code}</h3>
            <p className="text-sm text-muted">Responde las preguntas con lo que encontraste; luego puedes pedir que vuelva a proponer causas con tus respuestas.</p>
            {res.questions.map((q, i) => (
              <div key={i} className="rounded-lg bg-bg p-3">
                <label className="text-sm font-semibold" htmlFor={`iaa-q${i}`}>{q}</label>
                <textarea id={`iaa-q${i}`} className="input mt-2 min-h-[3rem] py-2" maxLength={1000} value={answers[i] ?? ""} onChange={(e) => setAnswers(answers.map((a, k) => (k === i ? e.target.value : a)))} />
              </div>
            ))}
            <Button size="sm" icon="refresh" loading={busy} disabled={!answers.some((a) => a.trim())} onClick={() => start(true)}>Proponer causas con mis respuestas</Button>

            <div className="space-y-2 rounded-xl border border-line p-3">
              <p className="font-semibold">Causas propuestas</p>
              <p className={`text-xs ${res.topic ? "text-ok" : "text-amber-ink"}`}>{res.note}</p>
              {items.map((s, i) => (
                <div key={s.id} className={`flex flex-wrap items-start gap-2 rounded-lg border border-line p-2 text-sm ${s.state ? "opacity-70" : ""}`}>
                  <input type="checkbox" className="mt-2" aria-label="Incluir esta causa" checked={s.on} disabled={!!s.state} onChange={(e) => setItems(items.map((x, k) => (k === i ? { ...x, on: e.target.checked } : x)))} />
                  <select className="input !h-9 !w-auto" aria-label="Nivel de la causa" disabled={!!s.state} value={s.payload.cause_level} onChange={(e) => edit(i, { cause_level: e.target.value })}>
                    {Object.entries(CAUSE_LEVEL).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                  </select>
                  <input className="input !h-9 min-w-[12rem] flex-1" aria-label="Causa" disabled={!!s.state} value={s.payload.description} onChange={(e) => edit(i, { description: e.target.value })} />
                  {s.state ? <Chip tone={s.state === "En el árbol" ? "ok" : "na"}>{s.state}</Chip> : <Button size="sm" variant="ghost" onClick={() => discard(i)}>Descartar</Button>}
                </div>
              ))}
              <Button variant="primary" loading={busy} disabled={pending === 0} onClick={acceptAll}>Aceptar en el árbol ({pending})</Button>
            </div>
          </section>
        )}
      </div>
      <div className="space-y-4">
        <section className="space-y-3 rounded-xl border border-line bg-surface p-4">
          <h3 className="font-display font-semibold">Investigación</h3>
          <FormField label="Investigación *">
            <RefSelect code="INV" table="investigation_causes" f={refField("investigation_id", "Investigación", "investigations")} value={inv} initialLabel={investigationLabel} disabled={!!investigationId} invalid={false} onChange={(v) => setInv((v as string) ?? "")} />
          </FormField>
          <Button variant="primary" icon="sparkle" loading={busy && !res} disabled={!inv} onClick={() => start(false)}>{res ? "Reiniciar guía" : "Iniciar guía"}</Button>
        </section>
        <Rules />
      </div>
    </div>
  );
}

/** Árbol de causas de la investigación con la entrada «Investigación guiada» si la empresa tiene el complemento IAA. */
export function InvTreeWithGuide({ row, readOnly }: { row: RecordRow; readOnly: boolean }) {
  const { modules } = useApp();
  const [open, setOpen] = useState(false);
  const [tick, setTick] = useState(0);
  const on = !readOnly && modules.some((m) => m.code === "IAA" && m.mode === "enabled" && m.role_can_use);
  return (
    <div className="space-y-2">
      {on && (
        <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-line px-4 py-2 text-sm">
          <span className="text-muted">El asistente hace las preguntas del método y propone causas para el árbol.</span>
          <Button size="sm" icon="sparkle" onClick={() => setOpen(true)}>Investigación guiada</Button>
        </div>
      )}
      <InvCauseTree key={tick} row={row} />
      {open && (
        <Modal title="Investigación guiada" subtitle="Las causas que aceptes entran al árbol de esta investigación." icon="sparkle" size="lg" onClose={() => setOpen(false)}>
          <InvestigationGuide investigationId={row.id} investigationLabel={String(row.code ?? "")} onAccepted={() => setTick((t) => t + 1)} />
        </Modal>
      )}
    </div>
  );
}

// ─────────────── Historial (SCR-IAA-05) ───────────────

const FEATURE: Record<string, string> = { iperc_suggestion: "IPERC sugerido", investigation_guide: "Investigación guiada", other: "Consulta rápida" };

export function IaaHistory() {
  const [rows, setRows] = useState<HistoryRow[] | null>(null);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    iaa.history(page).then((r) => { setRows(r.data); setPages(r.meta.pages); }).catch((e) => setError(errText(e)));
  }, [page]);
  if (error) return <p className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">{error}</p>;
  if (!rows) return <p className="text-muted">Cargando…</p>;
  if (rows.length === 0) return <EmptyState icon="history" text="Aún no hay solicitudes al asistente." />;
  return (
    <div className="space-y-3">
      <div className="overflow-x-auto rounded-xl border border-line bg-surface">
        <table className="w-full min-w-[720px] text-left text-sm">
          <thead className="border-b border-line bg-bg text-xs text-muted">
            <tr><th className="px-3 py-2">Fecha</th><th className="px-3 py-2">Usuario</th><th className="px-3 py-2">Caso de uso</th><th className="px-3 py-2">Detalle</th><th className="px-3 py-2 text-center">Sugerencias</th><th className="px-3 py-2 text-center">Aceptadas</th><th className="px-3 py-2 text-center">Descartadas</th><th className="px-3 py-2 text-center">Pendientes</th></tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-b border-line last:border-0">
                <td className="whitespace-nowrap px-3 py-2">{new Intl.DateTimeFormat("es-PE", { dateStyle: "short", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(r.created_at))}</td>
                <td className="px-3 py-2">{r.user_name ?? "—"}</td>
                <td className="px-3 py-2">{FEATURE[r.feature] ?? r.feature}</td>
                <td className="px-3 py-2 text-muted">{r.input_summary ?? "—"}</td>
                <td className="px-3 py-2 text-center font-semibold">{r.suggestions}</td>
                <td className="px-3 py-2 text-center text-ok">{r.accepted}</td>
                <td className="px-3 py-2 text-center text-muted">{r.rejected}</td>
                <td className="px-3 py-2 text-center text-amber-ink">{r.pending}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {pages > 1 && (
        <div className="flex items-center justify-end gap-2 text-sm">
          <Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Anterior</Button>
          <span>Página {page} de {pages}</span>
          <Button size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>Siguiente</Button>
        </div>
      )}
    </div>
  );
}

