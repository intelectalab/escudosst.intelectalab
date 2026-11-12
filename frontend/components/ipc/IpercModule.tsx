"use client";

// Matriz IPERC (M-IPC): lista de matrices (SCR-IPC-01), editor tipo hoja con cálculo al instante (SCR-IPC-03/04),
// envío y aprobación (SCR-IPC-05), historial de versiones (SCR-IPC-06) y mapa de calor (SCR-IPC-07).
// El catálogo de peligros y los criterios usan las pantallas comunes de registros.

import { useCallback, useEffect, useMemo, useState } from "react";
import { ApiError } from "@/lib/api";
import { records, type ModuleSchema, type RecordField, type RecordRow } from "@/lib/records";
import {
  bandOf,
  CONTROL_STATUS,
  downloadIperc,
  HIERARCHY,
  ipc,
  isSignificant,
  levelClass,
  levelLabel,
  probabilityValues,
  VERSION_STATUS,
  type Criteria,
  type IpercControl,
  type IpercRow,
  type IpercVersion,
  type Workspace,
} from "@/lib/ipc";
import { filtersFromUrl, RecordForm, RefSelect, ResourcePanel } from "@/components/records/ModuleRecords";
import { describeFilter } from "@/components/records/Process";
import { Button } from "@/components/ui/Button";
import { Chip, EmptyState } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Switch } from "@/components/ui/Switch";
import { Toast } from "@/components/ui/Toast";
import { useApp } from "@/components/AppContext";
import { IpercSuggest } from "@/components/addons/IaaGuided";

const fmt = (d: string | null) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}` : "—");
const refField = (name: string, label: string, ref: string): RecordField => ({ name, label, type: "ref", ref });

export function IpercModule({ readOnly }: { readOnly: boolean }) {
  const [schema, setSchema] = useState<ModuleSchema | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState("matrices");
  const [open, setOpen] = useState<string | null>(null);

  useEffect(() => {
    records.schema("IPC").then(setSchema).catch((e: ApiError) => setError(e.detail ?? e.title));
    const q = new URLSearchParams(window.location.search);
    if (q.get("matriz")) setOpen(q.get("matriz"));
    if (q.get("t")) setTab(q.get("t") === "iperc_matrices" ? "matrices" : q.get("t")!);
  }, []);

  const go = (t: string, matrix: string | null = null) => {
    setTab(t);
    setOpen(matrix);
    const url = new URL(window.location.href);
    url.searchParams.set("t", t);
    if (matrix) url.searchParams.set("matriz", matrix);
    else url.searchParams.delete("matriz");
    window.history.replaceState(null, "", url);
  };

  if (error) return <p className="rounded-xl border border-line bg-surface p-6 text-muted">{error}</p>;
  if (!schema) return <p className="text-muted">Cargando…</p>;

  const res = (t: string) => schema.resources.find((r) => r.table === t);
  const tabs: [string, string][] = [["matrices", "Todas las matrices"]];
  if (res("hazard_catalog")) tabs.push(["hazard_catalog", "Catálogo de peligros"]);
  if (res("risk_criteria")) tabs.push(["risk_criteria", "Criterios de evaluación"]);

  return (
    <div className="space-y-4">
      <div role="tablist" aria-label="Matriz IPERC" className="flex gap-1 overflow-x-auto rounded-xl border border-line bg-surface p-1">
        {tabs.map(([t, l]) => (
          <button
            key={t}
            role="tab"
            aria-selected={tab === t}
            onClick={() => go(t)}
            className={`whitespace-nowrap rounded-lg px-3 py-2 text-sm font-semibold ${tab === t ? "bg-brand text-white shadow-sm" : "text-muted hover:bg-bg hover:text-fg"}`}
          >
            {l}
          </button>
        ))}
      </div>
      {tab === "matrices" &&
        (open ? (
          <MatrixEditor id={open} schema={schema} readOnly={readOnly} onBack={() => go("matrices")} />
        ) : (
          <MatrixList schema={schema} readOnly={readOnly} onOpen={(id) => go("matrices", id)} />
        ))}
      {tab !== "matrices" && res(tab) && <ResourcePanel key={tab} code="IPC" res={res(tab)!} readOnly={readOnly} />}
    </div>
  );
}

// ─────────────── SCR-IPC-01 · Matrices ───────────────

function MatrixList({ schema, readOnly, onOpen }: { schema: ModuleSchema; readOnly: boolean; onOpen: (id: string) => void }) {
  const res = schema.resources.find((r) => r.table === "iperc_matrices");
  const [rows, setRows] = useState<RecordRow[] | null>(null);
  const [creating, setCreating] = useState(false);
  // Filtros que llegan por enlace (p. ej. desde «Para atender» del Inicio).
  const [linkFilters, setLinkFilters] = useState<Record<string, string>>(() =>
    typeof window === "undefined" ? {} : filtersFromUrl(new URLSearchParams(window.location.search)),
  );

  useEffect(() => {
    records.list("IPC", "iperc_matrices", { filters: { status: "active", ...linkFilters } }).then((r) => setRows(r.data));
  }, [linkFilters]);

  if (!res) return <p className="text-muted">Tu rol no puede ver las matrices.</p>;
  const today = new Date().toISOString().slice(0, 10);
  const soon = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold">Todas las matrices</h2>
          <p className="text-sm text-muted">Una matriz por sede, área, proceso o puesto. Cada una guarda sus versiones aprobadas.</p>
        </div>
        {res.can.create && !readOnly && (
          <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>
            Nueva matriz
          </Button>
        )}
      </div>
      {Object.keys(linkFilters).filter((k) => k !== "status").length > 0 && (
        <div className="flex flex-wrap items-center gap-2 rounded-xl bg-brand-soft px-4 py-2 text-sm text-brand-strong" role="status">
          <span className="font-semibold">Mostrando solo:</span>
          {Object.entries(linkFilters)
            .filter(([k]) => k !== "status")
            .map(([k, v]) => (
              <button key={k} onClick={() => setLinkFilters((x) => Object.fromEntries(Object.entries(x).filter(([y]) => y !== k)))}
                className="rounded-full bg-surface px-3 py-1 text-xs font-semibold shadow-sm hover:bg-bg" title="Quitar este filtro">
                {describeFilter(res, k, v)} ×
              </button>
            ))}
        </div>
      )}
      {rows && rows.length === 0 && <EmptyState icon="clipboard" text={Object.keys(linkFilters).length ? "No hay matrices con ese filtro." : "Aún no hay matrices. Crea la primera con «Nueva matriz»."} />}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {rows?.map((m) => {
          const next = m.next_review_on as string | null;
          const tone = !m.current_version_id ? "na" : next && next < today ? "bad" : next && next <= soon ? "amber" : "ok";
          return (
            <button
              key={m.id}
              onClick={() => onOpen(m.id)}
              className="space-y-3 rounded-2xl border border-line bg-surface p-5 text-left shadow-sm transition hover:border-brand hover:shadow-md"
            >
              <div className="flex items-start justify-between gap-2">
                <span className="text-xs font-semibold text-muted">{String(m.code)}</span>
                <Chip tone={tone} dot>
                  {!m.current_version_id ? "Sin versión aprobada" : tone === "bad" ? "Revisión vencida" : tone === "amber" ? "Revisión próxima" : "Vigente"}
                </Chip>
              </div>
              <p className="text-base font-semibold">{String(m.title)}</p>
              <p className="text-sm text-muted">
                {[m.site_id__label, m.area_id__label, m.process].filter(Boolean).join(" · ") || "Toda la empresa"}
              </p>
              <p className="text-xs text-muted">Próxima revisión: {fmt(next)}</p>
            </button>
          );
        })}
      </div>
      {creating && (
        <RecordForm
          code="IPC"
          res={res}
          row={null}
          readOnly={readOnly}
          hide={["sector_pack_item_id", "status"]}
          onClose={() => setCreating(false)}
          onSaved={(_, saved) => {
            setCreating(false);
            if (saved) onOpen(saved.id);
          }}
        />
      )}
    </section>
  );
}

// ─────────────── SCR-IPC-03 · Editor ───────────────

function MatrixEditor({ id, schema, readOnly, onBack }: { id: string; schema: ModuleSchema; readOnly: boolean; onBack: () => void }) {
  const [ws, setWs] = useState<Workspace | null>(null);
  const [version, setVersion] = useState<string | undefined>();
  const [error, setError] = useState<string | null>(null);
  const [row, setRow] = useState<IpercRow | "new" | null>(null);
  const [dialog, setDialog] = useState<"submit" | "return" | "new" | "edit" | null>(null);
  const [toast, setToast] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [view, setView] = useState<"matrix" | "history" | "heat">("matrix");
  const [suggest, setSuggest] = useState(false);
  const { modules } = useApp();
  const iaaOn = modules.some((m) => m.code === "IAA" && m.mode === "enabled" && m.role_can_use);

  const load = useCallback(() => {
    ipc
      .workspace(id, version)
      .then((r) => setWs(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [id, version]);
  useEffect(load, [load]);

  if (error) return <p className="rounded-xl border border-line bg-surface p-6 text-muted">{error}</p>;
  if (!ws) return <p className="text-muted">Cargando matriz…</p>;

  const v = ws.versions.find((x) => x.id === ws.version_id)!;
  const draft = v.status === "draft";
  const editable = draft && ws.can.edit && !readOnly;
  const working = ws.versions.some((x) => x.status === "draft" || x.status === "in_review");
  const c = ws.criteria;
  const significant = ws.rows.filter((r) => r.is_significant).length;

  const run = async (fn: () => Promise<unknown>, msg: string) => {
    setBusy(true);
    try {
      await fn();
      setToast(msg);
      load();
    } catch (e) {
      const err = e as ApiError & { issues?: unknown };
      setError(null);
      setToast(null);
      alertError(err);
    } finally {
      setBusy(false);
    }
  };
  const alertError = (err: ApiError) => setToast(`⚠ ${err.detail ?? err.title}`);

  const current = ws.versions.find((x) => x.status === "current");
  const inWork = ws.versions.find((x) => x.status === "draft" || x.status === "in_review");
  const steps = ["Borrador", "En revisión", "Aprobado"];
  const stepIdx = v.status === "draft" ? 0 : v.status === "in_review" ? 1 : 3;
  const exposed = ws.exposed_workers ?? null;
  const pdfHref = `/imprimir/iperc/${id}?v=${v.id}`;

  return (
    <section className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="space-y-1">
          <button onClick={onBack} className="text-sm font-semibold text-brand hover:underline">
            ← Todas las matrices
          </button>
          <h2 className="text-xl font-semibold">
            {ws.matrix.code} · {ws.matrix.title}
          </h2>
          <p className="text-sm text-muted">Criterios: {c.name} · Próxima revisión: {fmt(ws.matrix.next_review_on)}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          {ws.can.export && (
            <>
              <a href={pdfHref} target="_blank" rel="noreferrer" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold hover:border-brand">
                Descargar PDF
              </a>
              <Button icon="download" onClick={() => downloadIperc(v.id, "xlsx", `IPERC-${ws.matrix.code}-v${v.version_number}.xlsx`).catch(alertError)}>
                Excel
              </Button>
            </>
          )}
          {draft && ws.can.edit && !readOnly && (
            <Button icon="pen" onClick={() => setDialog("edit")}>
              Datos de la matriz
            </Button>
          )}
        </div>
      </div>

      {/* Cabecera del puesto (prototipo m-campo.js) */}
      <dl className="grid gap-x-6 gap-y-3 rounded-xl border border-line bg-surface p-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <dt className="text-xs font-semibold text-muted">Puesto / área / sede</dt>
          <dd className="font-semibold">{[ws.matrix.position_name, ws.matrix.area_name, ws.matrix.site_name].filter(Boolean).join(" · ") || ws.matrix.process || "Toda la empresa"}</dd>
        </div>
        <div>
          <dt className="text-xs font-semibold text-muted">Trabajadores expuestos</dt>
          <dd className="font-display text-2xl font-bold text-brand">{exposed ?? "—"}</dd>
        </div>
        <div>
          <dt className="text-xs font-semibold text-muted">Versión en edición</dt>
          <dd>{inWork ? <>v{inWork.version_number} <Chip tone={inWork.status === "draft" ? "brand" : "amber"}>{VERSION_STATUS[inWork.status]}</Chip></> : <span className="text-muted">Ninguna</span>}</dd>
        </div>
        <div>
          <dt className="text-xs font-semibold text-muted">Versión vigente</dt>
          <dd>{current ? <>v{current.version_number} · aprobada{current.approved_by_name ? ` por ${current.approved_by_name}` : ""} el {fmt(current.effective_on)}</> : <span className="text-amber-ink">Sin versión aprobada</span>}</dd>
        </div>
      </dl>

      <div role="tablist" aria-label="Matriz" className="flex gap-1 overflow-x-auto border-b border-line">
        {([["matrix", "Matriz del puesto"], ["history", "Historial de versiones"], ["heat", "Mapa de calor"]] as const).map(([k, l]) => (
          <button key={k} role="tab" aria-selected={view === k} onClick={() => setView(k)}
            className={`-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-semibold ${view === k ? "border-brand text-brand-strong" : "border-transparent text-muted hover:text-fg"}`}>
            {l}
          </button>
        ))}
      </div>

      {view === "history" && <VersionHistory ws={ws} matrixId={id} onOpen={(vid) => { setVersion(vid); setView("matrix"); }} />}
      {view === "heat" && <Heatmap c={c} ws={ws} />}

      {view === "matrix" && (
        <>
          {/* Flujo (SCR-IPC-05): paso a paso y acciones */}
          <div className="flex flex-wrap items-center justify-between gap-3">
            <ol className="flex flex-wrap items-center gap-2 text-sm" aria-label="Estado de la versión">
              {steps.map((st, i) => {
                const doneStep = i < stepIdx;
                const cur = i === stepIdx;
                return (
                  <li key={st} className="flex items-center gap-2">
                    {i > 0 && <span className="h-px w-6 bg-line" aria-hidden="true" />}
                    <span className={`grid h-6 w-6 place-items-center rounded-full text-xs font-bold ${doneStep ? "bg-ok text-white" : cur ? "bg-brand text-white" : "bg-bg text-muted"}`}>{doneStep ? "✓" : i + 1}</span>
                    <span className={cur ? "font-semibold" : doneStep ? "" : "text-muted"} aria-current={cur ? "step" : undefined}>{st}</span>
                  </li>
                );
              })}
              <li className="ml-1 text-muted">· v{v.version_number} {VERSION_STATUS[v.status]}{v.change_reason ? ` · ${v.change_reason}` : ""}</li>
            </ol>
            <div className="flex flex-wrap gap-2">
              {editable && iaaOn && (
                <Button icon="sparkle" onClick={() => setSuggest(true)}>
                  Sugerir controles
                </Button>
              )}
              {editable && (
                <Button icon="plus" onClick={() => setRow("new")}>
                  Agregar fila
                </Button>
              )}
              {draft && ws.can.submit && !readOnly && (
                <Button variant="primary" onClick={() => setDialog("submit")} disabled={ws.rows.length === 0}>
                  Enviar a aprobación
                </Button>
              )}
              {v.status === "in_review" && ws.can.approve && !readOnly && (
                <>
                  <Button variant="danger-ghost" onClick={() => setDialog("return")}>
                    Observar
                  </Button>
                  <Button variant="primary" loading={busy} onClick={() => run(() => ipc.approve(v.id), "Versión aprobada: ahora es la vigente.")}>
                    Aprobar versión {v.version_number}
                  </Button>
                </>
              )}
              {!working && ws.can.new_version && !readOnly && (
                <Button icon="plus" onClick={() => setDialog("new")}>
                  Crear versión {Math.max(...ws.versions.map((x) => x.version_number)) + 1}
                </Button>
              )}
            </div>
          </div>

          <p className="text-sm text-muted">
            {ws.rows.length} fila(s) · {significant} riesgo(s) significativo(s)
            {v.prepared_by_name && ` · Elaboró ${v.prepared_by_name}`}
            {v.approved_by_name && ` · Aprobó ${v.approved_by_name} el ${fmt(v.effective_on)}`}
          </p>
          {editable && <p className="rounded-lg bg-bg px-4 py-2 text-sm text-muted">Borrador: edita en la misma tabla (se guarda al salir de cada celda). Toca el N.° de una fila para ver todo su detalle y sus controles.</p>}
          {v.status === "in_review" && <p className="rounded-lg bg-amber-soft px-4 py-2 text-sm text-amber-ink">Versión {v.version_number} en revisión: las filas no se pueden editar. La aprueba otra persona (comité o supervisor SST), no quien la elaboró.</p>}
          {v.status === "current" && <p className="rounded-lg bg-ok-soft px-4 py-2 text-sm text-ok">Versión {v.version_number} vigente y congelada. Para cambiarla crea una nueva versión; esta sigue válida hasta aprobar la nueva.</p>}

          {draft && ws.issues.length > 0 && (
            <div className="rounded-xl border border-amber-ink/30 bg-amber-soft px-4 py-3 text-sm text-amber-ink">
              <p className="font-semibold">Antes de enviar a aprobación:</p>
              <ul className="mt-1 list-disc space-y-0.5 pl-5">
                {ws.issues.map((i, k) => (
                  <li key={k}>{i.text}</li>
                ))}
              </ul>
            </div>
          )}

          {ws.rows.length === 0 ? (
            <EmptyState icon="clipboard" text={editable ? "La matriz no tiene filas. Usa «Agregar fila»: proceso → actividad → tarea → peligro." : "Esta versión no tiene filas."} />
          ) : (
            <GridEditor
              ws={ws}
              editable={editable}
              onOpen={(r) => setRow(r)}
              onSaved={(r) => {
                setWs((w) => (w ? { ...w, rows: w.rows.map((x) => (x.id === r.id ? { ...x, ...r, controls: x.controls, position_name: x.position_name } : x)) } : w));
                load();
              }}
              onError={alertError}
            />
          )}
          <Legend c={c} />
        </>
      )}

      {row && (
        <RowModal
          ws={ws}
          row={row === "new" ? null : row}
          editable={editable}
          canControls={ws.can.controls && !readOnly}
          onClose={() => {
            setRow(null);
            load();
          }}
          onSaved={(msg) => {
            setToast(msg);
            load();
          }}
          onDone={() => {
            setRow(null);
            load();
          }}
        />
      )}
      {dialog === "submit" && (
        <SubmitDialog
          initial={v.participants}
          onClose={() => setDialog(null)}
          onSubmit={(p) => run(() => ipc.submit(v.id, p), "Enviada a aprobación.").then(() => setDialog(null))}
        />
      )}
      {dialog === "return" && (
        <TextDialog
          title="Observar la versión"
          label="¿Qué se debe corregir?"
          action="Devolver a borrador"
          onClose={() => setDialog(null)}
          onSubmit={(t) => run(() => ipc.sendBack(v.id, t), "Devuelta a borrador con observaciones.").then(() => setDialog(null))}
        />
      )}
      {dialog === "new" && (
        <TextDialog
          title="Nueva versión"
          label="Motivo (revisión anual, accidente, cambio en el proceso…)"
          action="Crear borrador"
          onClose={() => setDialog(null)}
          onSubmit={(t) =>
            run(async () => {
              const r = await ipc.newVersion(id, t);
              setVersion(r.data.id);
            }, "Nueva versión en borrador: copia de la vigente.").then(() => setDialog(null))
          }
        />
      )}
      {dialog === "edit" && (
        <RecordForm
          code="IPC"
          res={schema.resources.find((r) => r.table === "iperc_matrices")!}
          row={{ id, own: true } as RecordRow}
          readOnly={readOnly}
          hide={["sector_pack_item_id"]}
          onClose={() => setDialog(null)}
          onSaved={(msg) => {
            setDialog(null);
            setToast(msg);
            load();
          }}
        />
      )}
      {suggest && (
        <Modal title="Sugerir controles" subtitle="Peligros, riesgos y controles por jerarquía para este puesto. Lo que aceptes entra a este borrador." icon="sparkle" size="lg" onClose={() => setSuggest(false)}>
          <IpercSuggest positionId={ws.matrix.position_id} positionLabel={ws.matrix.position_name} matrixId={id} onAccepted={load} />
        </Modal>
      )}
      {toast && <Toast message={toast.replace(/^⚠ /, "")} tone={toast.startsWith("⚠") ? "bad" : "ok"} onClose={() => setToast(null)} />}
    </section>
  );
}

// ─────────────── SCR-IPC-03 · Editor tipo hoja ───────────────

const TEXT_COLS: [keyof IpercRow, string, boolean][] = [
  ["process", "Proceso", false],
  ["activity", "Actividad", true],
  ["task", "Tarea", true],
  ["hazard", "Peligro", true],
  ["risk", "Riesgo", true],
];

function GridEditor({ ws, editable, onOpen, onSaved, onError }: { ws: Workspace; editable: boolean; onOpen: (r: IpercRow) => void; onSaved: (r: IpercRow) => void; onError: (e: ApiError) => void }) {
  const c = ws.criteria;
  const [saving, setSaving] = useState<string | null>(null);

  const save = async (r: IpercRow, body: Record<string, unknown>, revert?: () => void) => {
    setSaving(r.id);
    try {
      const res = await records.update("IPC", "iperc_rows", r.id, body);
      onSaved(res.data as unknown as IpercRow);
    } catch (e) {
      const err = e as ApiError;
      revert?.();
      onError({ ...err, detail: Object.values(err.errors ?? {})[0]?.[0] ?? err.detail } as ApiError);
    } finally {
      setSaving(null);
    }
  };

  const probOptions = c.method === "pxs_index" ? [] : c.probability_scale;
  const resProb = probabilityValues(c);
  const cellSel = (value: number | null, options: number[], label: string, onChange: (v: number | null) => void, allowEmpty = false) => (
    <select className="h-8 w-14 rounded-md border border-line bg-surface px-1 text-center text-sm" aria-label={label} value={value ?? ""} onChange={(e) => onChange(e.target.value === "" ? null : Number(e.target.value))}>
      {allowEmpty && <option value="">—</option>}
      {options.map((o) => <option key={o} value={o}>{o}</option>)}
    </select>
  );
  const chip = (level: string | null, score: number | null) =>
    level ? <span className={`inline-block whitespace-nowrap rounded-md px-2 py-0.5 text-xs font-semibold ${levelClass(c, level)}`}>{score ? `${score} · ` : ""}{levelLabel(c, level)}</span> : <span className="text-muted">—</span>;

  return (
    <div className="overflow-x-auto rounded-xl border border-line bg-surface">
      <table className="w-full min-w-[1180px] border-collapse text-left text-sm">
        <thead className="text-xs text-muted">
          <tr className="border-b border-line bg-bg">
            <th className="px-2 py-2" rowSpan={2}>N.°</th>
            <th className="border-l border-line px-2 py-2 text-center font-bold uppercase tracking-wide text-fg" colSpan={6}>Identificación</th>
            <th className="border-l border-line px-2 py-2 text-center font-bold uppercase tracking-wide text-fg" colSpan={3}>Evaluación inicial</th>
            <th className="border-l border-line px-2 py-2 text-center font-bold uppercase tracking-wide text-fg">Controles por jerarquía</th>
            <th className="border-l border-line px-2 py-2 text-center font-bold uppercase tracking-wide text-fg" colSpan={3}>Riesgo residual</th>
          </tr>
          <tr className="border-b border-line bg-bg">
            {TEXT_COLS.map(([, l], i) => <th key={l} className={`px-2 py-2 font-semibold ${i === 0 ? "border-l border-line" : ""}`}>{l}</th>)}
            <th className="px-2 py-2 font-semibold">Puesto</th>
            <th className="border-l border-line px-2 py-2 text-center font-semibold">P</th>
            <th className="px-2 py-2 text-center font-semibold">S</th>
            <th className="px-2 py-2 font-semibold">Nivel</th>
            <th className="border-l border-line px-2 py-2 font-semibold">Medidas</th>
            <th className="border-l border-line px-2 py-2 text-center font-semibold">P</th>
            <th className="px-2 py-2 text-center font-semibold">S</th>
            <th className="px-2 py-2 font-semibold">Nivel</th>
          </tr>
        </thead>
        <tbody>
          {ws.rows.map((r) => {
            const flagged = ws.issues.some((i) => i.row === r.id);
            return (
              <tr key={r.id} className={`border-b border-line align-top last:border-0 ${flagged ? "bg-amber-soft/40" : ""} ${saving === r.id ? "opacity-60" : ""}`}>
                <td className="px-2 py-2">
                  <button type="button" onClick={() => onOpen(r)} className="rounded-md px-1.5 py-0.5 font-semibold text-brand hover:bg-brand-soft" title="Ver el detalle de la fila" aria-label={`Abrir la fila ${r.row_number}`}>
                    {r.row_number}
                  </button>
                  {r.ai_assisted && <span className="mt-1 block whitespace-nowrap rounded bg-brand-soft px-1.5 py-0.5 text-[10px] font-semibold text-brand-strong" title="Fila propuesta por el Asistente IA: revísala antes de aprobar la versión">Sugerencia IA</span>}
                </td>
                {TEXT_COLS.map(([k, l, req], i) => (
                  <td key={k} className={`px-1 py-1.5 ${i === 0 ? "border-l border-line" : ""}`}>
                    {editable ? (
                      <textarea
                        rows={2}
                        defaultValue={(r[k] as string | null) ?? ""}
                        aria-label={`${l} de la fila ${r.row_number}`}
                        className="min-h-[2.25rem] w-full min-w-[7.5rem] resize-y rounded-md border border-transparent bg-transparent px-1.5 py-1 text-sm hover:border-line focus:border-brand focus:bg-surface focus:outline-none"
                        onBlur={(e) => {
                          const before = (r[k] as string | null) ?? "";
                          const now = e.target.value.trim();
                          if (now === before) return;
                          if (req && !now) {
                            e.target.value = before;
                            onError({ title: "Revisa los datos", detail: `${l} es obligatorio.` } as ApiError);
                            return;
                          }
                          const el = e.target;
                          save(r, { [k]: now || null }, () => (el.value = before));
                        }}
                      />
                    ) : (
                      <span className={`block px-1.5 py-1 ${k === "hazard" ? "font-medium" : ""}`}>{(r[k] as string | null) ?? "—"}</span>
                    )}
                    {k === "task" && <span className="block px-1.5 text-[11px] text-muted">{r.is_routine ? "Rutinaria" : "No rutinaria"}</span>}
                  </td>
                ))}
                <td className="px-2 py-2">{r.position_name ?? <button type="button" onClick={() => onOpen(r)} className="font-semibold text-amber-ink hover:underline">Falta</button>}</td>
                <td className="border-l border-line px-2 py-2 text-center">
                  {editable && c.method !== "pxs_index"
                    ? cellSel(r.probability_value, probOptions.map((o) => o.value), `Probabilidad de la fila ${r.row_number}`, (v) => v !== null && save(r, { probability_value: v }))
                    : editable
                      ? <button type="button" onClick={() => onOpen(r)} className="rounded-md border border-line px-2 py-1 font-semibold hover:border-brand" title="La probabilidad sale de los índices: edítalos en el detalle">{r.probability_value}</button>
                      : r.probability_value}
                </td>
                <td className="px-2 py-2 text-center">
                  {editable ? cellSel(r.severity_value, c.severity_scale.map((o) => o.value), `Severidad de la fila ${r.row_number}`, (v) => v !== null && save(r, { severity_value: v })) : r.severity_value}
                </td>
                <td className="px-2 py-2">
                  {chip(r.risk_level, r.risk_score)}
                  {r.is_significant && <span className="mt-1 block text-[11px] font-semibold text-bad">Significativo</span>}
                </td>
                <td className="min-w-[13rem] border-l border-line px-2 py-2">
                  <div className="flex flex-wrap gap-1">
                    {r.controls.map((x) => (
                      <span key={x.id} className="rounded-md bg-bg px-1.5 py-0.5 text-xs" title={CONTROL_STATUS.find((s) => s[0] === x.status)?.[1]}>
                        <b>{HIERARCHY.find((h) => h[0] === x.hierarchy)?.[1]}:</b> {x.description}
                      </span>
                    ))}
                    {r.controls.length === 0 && !editable && <span className="text-muted">—</span>}
                    {editable && (
                      <button type="button" onClick={() => onOpen(r)} className="rounded-md px-1.5 py-0.5 text-xs font-semibold text-brand hover:bg-brand-soft">+ control</button>
                    )}
                  </div>
                </td>
                <td className="border-l border-line px-2 py-2 text-center">
                  {editable
                    ? cellSel(r.residual_probability, resProb, `Probabilidad residual de la fila ${r.row_number}`, (v) => save(r, v === null ? { residual_probability: null, residual_severity: null } : { residual_probability: v, residual_severity: r.residual_severity ?? r.severity_value }), true)
                    : (r.residual_probability ?? "—")}
                </td>
                <td className="px-2 py-2 text-center">
                  {editable
                    ? cellSel(r.residual_severity, c.severity_scale.map((o) => o.value), `Severidad residual de la fila ${r.row_number}`, (v) => save(r, v === null ? { residual_probability: null, residual_severity: null } : { residual_severity: v, residual_probability: r.residual_probability ?? r.probability_value }), true)
                    : (r.residual_severity ?? "—")}
                </td>
                <td className="px-2 py-2">{chip(r.residual_level, r.residual_score)}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function Legend({ c }: { c: Criteria }) {
  return (
    <div className="flex flex-wrap items-center gap-2 text-xs">
      <span className="text-muted">Criterio {c.name}. Niveles:</span>
      {c.bands.map((b) => (
        <span key={b.code} className={`rounded-md px-2 py-0.5 font-semibold ${levelClass(c, b.code)}`} title={`Puntaje ${b.min}–${b.max}`}>
          {b.label} <span className="font-normal opacity-80">{b.min}–{b.max}</span>
        </span>
      ))}
      <span className="text-muted">· Criterio configurable por empresa.</span>
    </div>
  );
}

// ─────────────── SCR-IPC-06 · Historial y comparación ───────────────

type Change = { kind: "added" | "removed" | "changed"; row: IpercRow; fields: [string, string, string][] };

const DIFF_FIELDS: [keyof IpercRow, string][] = [
  ["process", "Proceso"], ["activity", "Actividad"], ["task", "Tarea"], ["position_name", "Puesto"], ["hazard", "Peligro"], ["risk", "Riesgo"],
  ["consequence", "Consecuencia"], ["probability_value", "Probabilidad"], ["severity_value", "Severidad"], ["risk_level", "Nivel"],
  ["residual_probability", "Probabilidad residual"], ["residual_severity", "Severidad residual"], ["residual_level", "Nivel residual"],
];

/** Diferencias por fila entre dos versiones (las filas se emparejan por su N.°: la nueva versión copia la numeración). */
function diffRows(from: IpercRow[], to: IpercRow[]): Change[] {
  const key = (r: IpercRow) => r.row_number;
  const a = new Map(from.map((r) => [key(r), r]));
  const b = new Map(to.map((r) => [key(r), r]));
  const ctl = (r: IpercRow) => r.controls.map((x) => `${HIERARCHY.find((h) => h[0] === x.hierarchy)?.[1]}: ${x.description}`).sort().join(" · ");
  const out: Change[] = [];
  for (const r of to) {
    const old = a.get(key(r));
    if (!old) {
      out.push({ kind: "added", row: r, fields: [] });
      continue;
    }
    const fields: [string, string, string][] = [];
    for (const [f, l] of DIFF_FIELDS) {
      const x = old[f] ?? "";
      const y = r[f] ?? "";
      if (String(x) !== String(y)) fields.push([l, String(x) || "—", String(y) || "—"]);
    }
    if (ctl(old) !== ctl(r)) fields.push(["Controles", ctl(old) || "—", ctl(r) || "—"]);
    if (fields.length) out.push({ kind: "changed", row: r, fields });
  }
  for (const r of from) if (!b.has(key(r))) out.push({ kind: "removed", row: r, fields: [] });
  return out.sort((x, y) => x.row.row_number - y.row.row_number);
}

function VersionHistory({ ws, matrixId, onOpen }: { ws: Workspace; matrixId: string; onOpen: (id: string) => void }) {
  const [cmp, setCmp] = useState<{ from: IpercVersion; to: IpercVersion; changes: Change[] | null; error?: string } | null>(null);
  const current = ws.versions.find((x) => x.status === "current");
  const inWork = ws.versions.find((x) => x.status === "draft" || x.status === "in_review");

  // «La actual» es la vigente; si se compara la vigente, se compara contra la versión en edición.
  const other = (x: IpercVersion) => (x.status === "current" ? inWork : current) ?? null;

  const compare = async (x: IpercVersion) => {
    const o = other(x);
    if (!o) return;
    const [from, to] = x.version_number < o.version_number ? [x, o] : [o, x];
    setCmp({ from, to, changes: null });
    try {
      const [a, b] = await Promise.all([ipc.workspace(matrixId, from.id), ipc.workspace(matrixId, to.id)]);
      setCmp({ from, to, changes: diffRows(a.data.rows, b.data.rows) });
    } catch (e) {
      setCmp({ from, to, changes: [], error: (e as ApiError).detail ?? (e as ApiError).title });
    }
  };

  const tone = (st: string): "ok" | "amber" | "brand" | "na" => (st === "current" ? "ok" : st === "in_review" ? "amber" : st === "draft" ? "brand" : "na");

  return (
    <div className="space-y-4">
      <div className="overflow-x-auto rounded-xl border border-line bg-surface">
        <table className="w-full min-w-[760px] text-left text-sm">
          <thead className="border-b border-line bg-bg text-xs text-muted">
            <tr><th className="px-3 py-2">Versión</th><th className="px-3 py-2">Estado</th><th className="px-3 py-2">Aprobado / editado por</th><th className="px-3 py-2">Fecha</th><th className="px-3 py-2">Motivo</th><th className="px-3 py-2" /></tr>
          </thead>
          <tbody>
            {ws.versions.map((x) => (
              <tr key={x.id} className={`border-b border-line last:border-0 ${x.id === ws.version_id ? "bg-brand-soft/30" : ""}`}>
                <td className="px-3 py-2 font-semibold">v{x.version_number}</td>
                <td className="px-3 py-2"><Chip tone={tone(x.status)} dot>{VERSION_STATUS[x.status] ?? x.status}</Chip></td>
                <td className="px-3 py-2">{x.approved_by_name ?? x.prepared_by_name ?? "—"}</td>
                <td className="px-3 py-2">{fmt((x.approved_at ?? x.prepared_at ?? x.effective_on)?.slice(0, 10) ?? null)}</td>
                <td className="px-3 py-2 text-muted">{x.change_reason ?? (x.version_number === 1 ? "Elaboración inicial" : "—")}</td>
                <td className="px-3 py-2">
                  <div className="flex flex-wrap justify-end gap-1.5">
                    <Button size="sm" variant="ghost" onClick={() => onOpen(x.id)}>Ver</Button>
                    {other(x) && <Button size="sm" variant="ghost" onClick={() => compare(x)}>{x.status === "current" ? "Comparar con la de edición" : "Comparar con la actual"}</Button>}
                    {ws.can.export && <a href={`/imprimir/iperc/${matrixId}?v=${x.id}`} target="_blank" rel="noreferrer" className="inline-flex h-8 items-center rounded-lg px-3 text-sm font-semibold text-brand hover:bg-brand-soft">Descargar PDF</a>}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-muted">Las versiones aprobadas no se pueden modificar. Para cambiar algo se crea una versión nueva, que copia la vigente.</p>
      {cmp && (
        <Modal title={`Comparación v${cmp.from.version_number} → v${cmp.to.version_number}`} subtitle={`${VERSION_STATUS[cmp.from.status]} frente a ${VERSION_STATUS[cmp.to.status]}`} icon="swap" size="lg" onClose={() => setCmp(null)}>
          {!cmp.changes ? (
            <p className="text-muted">Comparando…</p>
          ) : cmp.error ? (
            <p className="text-bad">{cmp.error}</p>
          ) : cmp.changes.length === 0 ? (
            <p className="text-muted">Las dos versiones tienen las mismas filas.</p>
          ) : (
            <div className="space-y-3 text-sm">
              <p className="flex flex-wrap gap-2">
                <Chip tone="ok">{cmp.changes.filter((x) => x.kind === "added").length} nuevas</Chip>
                <Chip tone="amber">{cmp.changes.filter((x) => x.kind === "changed").length} modificadas</Chip>
                <Chip tone="bad">{cmp.changes.filter((x) => x.kind === "removed").length} retiradas</Chip>
              </p>
              <ul className="space-y-2">
                {cmp.changes.map((ch) => (
                  <li key={`${ch.kind}${ch.row.id}`} className={`rounded-lg border-l-4 bg-bg px-3 py-2 ${ch.kind === "added" ? "border-l-ok" : ch.kind === "removed" ? "border-l-bad" : "border-l-amber-ink"}`}>
                    <p>
                      <b>Fila {ch.row.row_number}</b> · {ch.kind === "added" ? "Nueva" : ch.kind === "removed" ? "Retirada" : "Modificada"} · {ch.row.hazard} — {ch.row.risk}
                    </p>
                    {ch.fields.length > 0 && (
                      <ul className="mt-1 space-y-0.5 text-xs">
                        {ch.fields.map(([l, x, y]) => (
                          <li key={l}><span className="text-muted">{l}:</span> <s className="text-muted">{x}</s> → <b>{y}</b></li>
                        ))}
                      </ul>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          )}
        </Modal>
      )}
    </div>
  );
}

// ─────────────── SCR-IPC-07 · Mapa de calor ───────────────

function Heatmap({ c, ws }: { c: Criteria; ws: Workspace }) {
  const probs = probabilityValues(c).slice().reverse();
  const sevs = c.severity_scale.map((s) => s.value);
  const grid = (kind: "initial" | "residual", title: string) => (
    <div className="space-y-2">
      <p className="font-semibold">{title}</p>
      <div className="overflow-x-auto">
        <table className="text-center text-xs">
          <thead>
            <tr>
              <th className="px-2 py-1 text-muted">P \ S</th>
              {sevs.map((s) => (
                <th key={s} className="px-2 py-1 text-muted">
                  {s}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {probs.map((p) => (
              <tr key={p}>
                <th className="px-2 py-1 text-muted">{p}</th>
                {sevs.map((s) => {
                  const n = ws.heatmap[kind][`${p}x${s}`] ?? 0;
                  const band = bandOf(c, p * s);
                  return (
                    <td key={s} className={`h-9 w-14 border border-surface ${band ? levelClass(c, band.code) : ""} ${n ? "font-bold" : "opacity-60"}`} title={`${p} × ${s} = ${p * s} · ${band?.label ?? ""}`}>
                      {n || ""}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
  return (
    <div className="grid gap-6 rounded-2xl border border-line bg-surface p-5 lg:grid-cols-2">
      {grid("initial", "Antes de controles")}
      {grid("residual", "Después de controles (residual)")}
      <p className="text-xs text-muted lg:col-span-2">Cada número es la cantidad de riesgos en esa combinación de probabilidad (P) y severidad (S). Bandas: {c.bands.map((b) => `${b.label} ${b.min}–${b.max}`).join(" · ")}.</p>
    </div>
  );
}

// ─────────────── SCR-IPC-04 · Fila ───────────────

type RowForm = {
  process: string;
  activity: string;
  task: string;
  is_routine: boolean;
  position_id: string;
  hazard_catalog_id: string;
  hazard: string;
  risk: string;
  consequence: string;
  exposed_workers: string;
  probability_value: string;
  detail: Record<string, string>;
  severity_value: string;
  residual_probability: string;
  residual_severity: string;
  notes: string;
};

function RowModal({
  ws,
  row,
  editable,
  canControls,
  onClose,
  onSaved,
  onDone,
}: {
  ws: Workspace;
  row: IpercRow | null;
  editable: boolean;
  canControls: boolean;
  onClose: () => void;
  onSaved: (msg: string) => void;
  onDone: () => void;
}) {
  const c = ws.criteria;
  const s = (v: unknown) => (v === null || v === undefined ? "" : String(v));
  const [f, setF] = useState<RowForm>(() => ({
    process: s(row?.process ?? ws.matrix.process),
    activity: s(row?.activity),
    task: s(row?.task),
    is_routine: row?.is_routine ?? true,
    position_id: s(row?.position_id),
    hazard_catalog_id: s(row?.hazard_catalog_id),
    hazard: s(row?.hazard),
    risk: s(row?.risk),
    consequence: s(row?.consequence),
    exposed_workers: s(row?.exposed_workers),
    probability_value: s(row?.probability_value),
    detail: Object.fromEntries(Object.entries(row?.probability_detail ?? {}).map(([k, v]) => [k, String(v)])),
    severity_value: s(row?.severity_value),
    residual_probability: s(row?.residual_probability),
    residual_severity: s(row?.residual_severity),
    notes: s(row?.notes),
  }));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [detail, setDetail] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState<IpercRow | null>(row);
  const [confirm, setConfirm] = useState(false);
  const set = (k: keyof RowForm, v: unknown) => setF((x) => ({ ...x, [k]: v }));

  // Cálculo al instante (el backend vuelve a calcular y valida al guardar).
  const calc = useMemo(() => {
    const prob =
      c.method === "pxs_index"
        ? c.factors.every((x) => f.detail[x.code])
          ? c.factors.reduce((a, x) => a + Number(f.detail[x.code]), 0)
          : null
        : f.probability_value
          ? Number(f.probability_value)
          : null;
    const sev = f.severity_value ? Number(f.severity_value) : null;
    const score = prob && sev ? prob * sev : null;
    const band = score ? bandOf(c, score) : null;
    const rScore = f.residual_probability && f.residual_severity ? Number(f.residual_probability) * Number(f.residual_severity) : null;
    return { prob, score, band, rScore, rBand: rScore ? bandOf(c, rScore) : null };
  }, [c, f]);

  const save = async () => {
    setBusy(true);
    setErrors({});
    setDetail(null);
    const num = (v: string) => (v === "" ? null : Number(v));
    const body: Record<string, unknown> = {
      process: f.process || null,
      activity: f.activity,
      task: f.task,
      is_routine: f.is_routine,
      position_id: f.position_id || null,
      hazard_catalog_id: f.hazard_catalog_id || null,
      hazard: f.hazard || null,
      risk: f.risk || null,
      consequence: f.consequence || null,
      exposed_workers: num(f.exposed_workers),
      severity_value: num(f.severity_value),
      residual_probability: num(f.residual_probability),
      residual_severity: num(f.residual_severity),
      notes: f.notes || null,
    };
    if (c.method === "pxs_index") body.probability_detail = Object.fromEntries(Object.entries(f.detail).filter(([, v]) => v).map(([k, v]) => [k, Number(v)]));
    else body.probability_value = num(f.probability_value);
    try {
      const r = saved
        ? await records.update("IPC", "iperc_rows", saved.id, body)
        : await records.create("IPC", "iperc_rows", { ...body, iperc_version_id: ws.version_id });
      setSaved({ ...(r.data as unknown as IpercRow), controls: saved?.controls ?? [] });
      onSaved(saved ? "Fila guardada." : "Fila creada. Ahora agrega sus controles.");
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setDetail(err.detail ?? err.title);
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    setBusy(true);
    try {
      await records.remove("IPC", "iperc_rows", saved!.id);
      onDone();
    } catch (e) {
      setDetail((e as ApiError).detail ?? (e as ApiError).title);
    } finally {
      setBusy(false);
    }
  };

  const err = (k: string) => errors[k]?.[0];
  const dis = !editable;
  const sel = (value: string, onChange: (v: string) => void, options: { value: number; label: string }[], invalid?: string) => (
    <select className="input" disabled={dis} value={value} aria-invalid={invalid ? true : undefined} onChange={(e) => onChange(e.target.value)}>
      <option value="">Elige…</option>
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.value} · {o.label}
        </option>
      ))}
    </select>
  );
  const resProbOptions = probabilityValues(c).map((p) => ({ value: p, label: c.method === "pxs_index" ? "índice" : (c.probability_scale.find((x) => x.value === p)?.label ?? "") }));

  return (
    <Modal
      title={saved ? `Fila ${saved.row_number}` : "Nueva fila"}
      subtitle={editable ? "Proceso → actividad → tarea → peligro → evaluación → controles." : "Solo lectura: la versión no está en borrador."}
      icon="clipboard"
      size="lg"
      onClose={onClose}
      footer={
        <div className="flex w-full flex-wrap items-center justify-between gap-2">
          <div>
            {saved && editable && (confirm ? (
              <span className="flex items-center gap-2 text-sm">
                ¿Eliminar la fila?
                <Button size="sm" variant="danger" loading={busy} onClick={remove}>Sí</Button>
                <Button size="sm" variant="ghost" onClick={() => setConfirm(false)}>No</Button>
              </span>
            ) : (
              <Button variant="danger-ghost" icon="trash" onClick={() => setConfirm(true)}>Eliminar fila</Button>
            ))}
          </div>
          <div className="flex gap-2">
            <Button onClick={saved ? onDone : onClose}>Cerrar</Button>
            {editable && (
              <Button variant="primary" loading={busy} onClick={save}>
                {saved ? "Guardar" : "Crear fila"}
              </Button>
            )}
          </div>
        </div>
      }
    >
      <div className="space-y-5">
        {detail && <p role="alert" className="rounded-lg bg-bad-soft px-4 py-3 text-sm text-bad">{detail}</p>}

        <fieldset className="grid gap-4 sm:grid-cols-2">
          <legend className="mb-2 text-sm font-semibold text-brand">1. Tarea</legend>
          <FormField label="Proceso"><input className="input" disabled={dis} value={f.process} onChange={(e) => set("process", e.target.value)} /></FormField>
          <FormField label="Actividad *" error={err("activity")}><input className="input" disabled={dis} value={f.activity} onChange={(e) => set("activity", e.target.value)} /></FormField>
          <FormField label="Tarea *" error={err("task")}><input className="input" disabled={dis} value={f.task} onChange={(e) => set("task", e.target.value)} /></FormField>
          <FormField label="Puesto expuesto" error={err("position_id")} hint="Obligatorio para enviar a aprobación.">
            <RefSelect code="IPC" table="iperc_rows" f={refField("position_id", "Puesto", "positions")} value={f.position_id} initialLabel={row?.position_name ?? undefined} disabled={dis} invalid={!!err("position_id")} onChange={(v) => set("position_id", (v as string) ?? "")} />
          </FormField>
          <div className="flex items-center justify-between gap-3 rounded-lg border border-line px-4 py-3">
            <span className="text-sm font-semibold">Tarea rutinaria</span>
            <Switch checked={f.is_routine} onChange={(v) => set("is_routine", v)} disabled={dis} label="Tarea rutinaria" />
          </div>
          <FormField label="Personas expuestas"><input className="input" type="number" min={0} disabled={dis} value={f.exposed_workers} onChange={(e) => set("exposed_workers", e.target.value)} /></FormField>
        </fieldset>

        <fieldset className="grid gap-4 sm:grid-cols-2">
          <legend className="mb-2 text-sm font-semibold text-brand">2. Peligro y riesgo</legend>
          <div className="sm:col-span-2">
            <FormField label="Buscar en el catálogo de peligros" hint="Al elegir uno se completan peligro, riesgo y consecuencia (puedes ajustarlos).">
              <RefSelect code="IPC" table="iperc_rows" f={refField("hazard_catalog_id", "Peligro del catálogo", "hazard_catalog")} value={f.hazard_catalog_id} initialLabel={row?.hazard_catalog_id ? row.hazard : undefined} disabled={dis} invalid={false} onChange={(v) => setF((x) => ({ ...x, hazard_catalog_id: (v as string) ?? "", ...(v && !row ? { hazard: "", risk: "", consequence: "" } : {}) }))} />
            </FormField>
          </div>
          <FormField label="Peligro *" error={err("hazard")} hint={f.hazard_catalog_id && !f.hazard ? "Se completa del catálogo al guardar." : undefined}><input className="input" disabled={dis} value={f.hazard} onChange={(e) => set("hazard", e.target.value)} /></FormField>
          <FormField label="Riesgo *" error={err("risk")}><input className="input" disabled={dis} value={f.risk} onChange={(e) => set("risk", e.target.value)} /></FormField>
          <div className="sm:col-span-2"><FormField label="Consecuencia"><input className="input" disabled={dis} value={f.consequence} onChange={(e) => set("consequence", e.target.value)} /></FormField></div>
        </fieldset>

        <fieldset className="space-y-4">
          <legend className="mb-2 text-sm font-semibold text-brand">3. Evaluación ({c.name})</legend>
          <div className="grid gap-4 sm:grid-cols-2">
            {c.method === "pxs_index"
              ? c.factors.map((x) => (
                  <FormField key={x.code} label={`${x.label} *`} error={err("probability_detail")}>
                    {sel(f.detail[x.code] ?? "", (v) => setF((y) => ({ ...y, detail: { ...y.detail, [x.code]: v } })), x.levels)}
                  </FormField>
                ))
              : (
                  <FormField label="Probabilidad *" error={err("probability_value")}>{sel(f.probability_value, (v) => set("probability_value", v), c.probability_scale)}</FormField>
                )}
            <FormField label="Severidad *" error={err("severity_value")}>{sel(f.severity_value, (v) => set("severity_value", v), c.severity_scale)}</FormField>
          </div>
          <div className="flex flex-wrap items-center gap-3 rounded-xl bg-bg px-4 py-3 text-sm" aria-live="polite">
            <span>Probabilidad <b>{calc.prob ?? "—"}</b></span>
            <span>× Severidad <b>{f.severity_value || "—"}</b></span>
            <span>= <b>{calc.score ?? "—"}</b></span>
            {calc.band && <span className={`rounded-md px-2 py-0.5 font-semibold ${levelClass(c, calc.band.code)}`}>{calc.band.label}</span>}
            {calc.band && isSignificant(c, calc.band.code) && <span className="font-semibold text-bad">Riesgo significativo: requiere controles de mayor jerarquía que el EPP.</span>}
          </div>
        </fieldset>

        <fieldset className="space-y-4">
          <legend className="mb-2 text-sm font-semibold text-brand">4. Riesgo residual (después de controles)</legend>
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Probabilidad residual" error={err("residual_probability")}>{sel(f.residual_probability, (v) => set("residual_probability", v), resProbOptions)}</FormField>
            <FormField label="Severidad residual" error={err("residual_severity")}>{sel(f.residual_severity, (v) => set("residual_severity", v), c.severity_scale)}</FormField>
          </div>
          {calc.rScore && (
            <p className="text-sm">
              Residual: <b>{calc.rScore}</b>{" "}
              {calc.rBand && <span className={`rounded-md px-2 py-0.5 font-semibold ${levelClass(c, calc.rBand.code)}`}>{calc.rBand.label}</span>}
              {calc.score && calc.rScore > calc.score && <span className="ml-2 font-semibold text-bad">No puede ser mayor que el inicial.</span>}
            </p>
          )}
          <FormField label="Notas / justificación" hint="Si un riesgo significativo solo tiene EPP, explica aquí por qué no es factible un control de mayor jerarquía.">
            <textarea className="input min-h-[4rem] py-2" disabled={dis} value={f.notes} onChange={(e) => set("notes", e.target.value)} />
          </FormField>
        </fieldset>

        <fieldset className="space-y-3">
          <legend className="mb-2 text-sm font-semibold text-brand">5. Controles (de mayor a menor jerarquía)</legend>
          {saved ? (
            <Controls row={saved} draft={editable} canEdit={canControls} onChanged={(list) => setSaved((x) => (x ? { ...x, controls: list } : x))} />
          ) : (
            <p className="text-sm text-muted">Guarda la fila para agregar sus controles.</p>
          )}
        </fieldset>
      </div>
    </Modal>
  );
}

function Controls({ row, draft, canEdit, onChanged }: { row: IpercRow; draft: boolean; canEdit: boolean; onChanged: (list: IpercControl[]) => void }) {
  const [list, setList] = useState<IpercControl[]>(row.controls);
  const [form, setForm] = useState({ hierarchy: "engineering", description: "", is_existing: false, responsible_user_id: "", due_on: "" });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const add = async () => {
    setBusy(true);
    setError(null);
    try {
      const r = await records.create("IPC", "iperc_controls", {
        iperc_row_id: row.id,
        hierarchy: form.hierarchy,
        description: form.description,
        is_existing: form.is_existing,
        responsible_user_id: form.responsible_user_id || null,
        due_on: form.due_on || null,
      });
      const next = [...list, { ...(r.data as unknown as IpercControl), responsible_name: null }];
      setList(next);
      onChanged(next);
      setForm({ hierarchy: "engineering", description: "", is_existing: false, responsible_user_id: "", due_on: "" });
    } catch (e) {
      setError((e as ApiError).detail ?? (e as ApiError).title);
    } finally {
      setBusy(false);
    }
  };

  const update = async (ctl: IpercControl, body: Record<string, unknown>) => {
    try {
      const r = await records.update("IPC", "iperc_controls", ctl.id, body);
      const next = list.map((x) => (x.id === ctl.id ? { ...x, ...(r.data as unknown as IpercControl), responsible_name: x.responsible_name } : x));
      setList(next);
      onChanged(next);
    } catch (e) {
      setError((e as ApiError).detail ?? (e as ApiError).title);
    }
  };

  const remove = async (ctl: IpercControl) => {
    try {
      await records.remove("IPC", "iperc_controls", ctl.id);
      const next = list.filter((x) => x.id !== ctl.id);
      setList(next);
      onChanged(next);
    } catch (e) {
      setError((e as ApiError).detail ?? (e as ApiError).title);
    }
  };

  return (
    <div className="space-y-3">
      {error && <p className="rounded-lg bg-bad-soft px-3 py-2 text-sm text-bad">{error}</p>}
      {list.length === 0 && <p className="text-sm text-muted">Sin controles todavía.</p>}
      {list.map((x) => (
        <div key={x.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line px-3 py-2 text-sm">
          <div className="min-w-0">
            <p>
              <b>{HIERARCHY.find((h) => h[0] === x.hierarchy)?.[1]}:</b> {x.description}
            </p>
            <p className="text-xs text-muted">
              {x.responsible_name ?? (x.responsible_user_id ? "Con responsable" : "Sin responsable")} · {x.due_on ? fmt(x.due_on) : "sin fecha"}
              {x.corrective_action_id && " · acción correctiva creada"}
            </p>
          </div>
          <div className="flex items-center gap-2">
            <select className="input !h-8 !py-0 text-xs" disabled={!canEdit} value={x.status} onChange={(e) => update(x, { status: e.target.value })} aria-label="Estado del control">
              {CONTROL_STATUS.map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
            {draft && canEdit && (
              <Button size="sm" variant="danger-ghost" icon="trash" aria-label="Quitar control" onClick={() => remove(x)} />
            )}
          </div>
        </div>
      ))}
      {draft && canEdit && (
        <div className="grid gap-3 rounded-xl border border-dashed border-line p-3 sm:grid-cols-2">
          <FormField label="Jerarquía">
            <select className="input" value={form.hierarchy} onChange={(e) => setForm({ ...form, hierarchy: e.target.value })}>
              {HIERARCHY.map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </FormField>
          <div className="flex items-center justify-between gap-3 rounded-lg border border-line px-4 py-3">
            <span className="text-sm font-semibold">Ya existe</span>
            <Switch checked={form.is_existing} onChange={(v) => setForm({ ...form, is_existing: v })} label="Control existente" />
          </div>
          <div className="sm:col-span-2">
            <FormField label="Descripción del control">
              <input className="input" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
            </FormField>
          </div>
          {!form.is_existing && (
            <>
              <FormField label="Responsable" hint="Con responsable y fecha, al aprobar se crea su acción correctiva.">
                <RefSelect code="IPC" table="iperc_controls" f={refField("responsible_user_id", "Responsable", "users")} value={form.responsible_user_id} disabled={false} invalid={false} onChange={(v) => setForm({ ...form, responsible_user_id: (v as string) ?? "" })} />
              </FormField>
              <FormField label="Fecha límite">
                <input className="input" type="date" value={form.due_on} onChange={(e) => setForm({ ...form, due_on: e.target.value })} />
              </FormField>
            </>
          )}
          <div className="sm:col-span-2 flex justify-end">
            <Button variant="soft" icon="plus" loading={busy} disabled={!form.description.trim()} onClick={add}>
              Agregar control
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

// ─────────────── Diálogos ───────────────

function SubmitDialog({ initial, onClose, onSubmit }: { initial: { name: string; role?: string }[]; onClose: () => void; onSubmit: (p: { name: string; role: string }[]) => void }) {
  const [list, setList] = useState(initial.length ? initial.map((p) => ({ name: p.name, role: p.role ?? "" })) : [{ name: "", role: "" }]);
  const clean = list.filter((p) => p.name.trim());
  return (
    <Modal
      title="Enviar a aprobación"
      subtitle="El reglamento exige la participación de los trabajadores en la elaboración del IPERC."
      icon="checklist"
      onClose={onClose}
      footer={
        <div className="flex w-full justify-end gap-2">
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={clean.length === 0} onClick={() => onSubmit(clean)}>
            Enviar
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <p className="text-sm font-semibold">Trabajadores que participaron</p>
        {list.map((p, i) => (
          <div key={i} className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
            <input className="input" placeholder="Nombre" aria-label={`Nombre del participante ${i + 1}`} value={p.name} onChange={(e) => setList(list.map((x, k) => (k === i ? { ...x, name: e.target.value } : x)))} />
            <input className="input" placeholder="Puesto o rol" aria-label={`Puesto del participante ${i + 1}`} value={p.role} onChange={(e) => setList(list.map((x, k) => (k === i ? { ...x, role: e.target.value } : x)))} />
            <Button variant="ghost" icon="trash" aria-label="Quitar" onClick={() => setList(list.filter((_, k) => k !== i))} />
          </div>
        ))}
        <Button size="sm" variant="soft" icon="plus" onClick={() => setList([...list, { name: "", role: "" }])}>
          Agregar participante
        </Button>
        <p className="text-xs text-muted">Al enviar, la versión queda congelada. La aprueba otra persona (comité o supervisor SST); si la observan, vuelve a borrador.</p>
      </div>
    </Modal>
  );
}

function TextDialog({ title, label, action, onClose, onSubmit }: { title: string; label: string; action: string; onClose: () => void; onSubmit: (t: string) => void }) {
  const [text, setText] = useState("");
  return (
    <Modal
      title={title}
      icon="pen"
      size="sm"
      onClose={onClose}
      footer={
        <div className="flex w-full justify-end gap-2">
          <Button onClick={onClose}>Cancelar</Button>
          <Button variant="primary" disabled={!text.trim()} onClick={() => onSubmit(text)}>
            {action}
          </Button>
        </div>
      }
    >
      <FormField label={label}>
        <textarea autoFocus className="input min-h-[5rem] py-2" value={text} onChange={(e) => setText(e.target.value)} />
      </FormField>
    </Modal>
  );
}
