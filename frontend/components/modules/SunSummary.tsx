"use client";

// Modo Inspección SUNAFIL (M-SUN, SCR-SUN-01/02; prototipo m-diferenciadores.js R.SUN): autodiagnóstico calculado con
// los datos reales de los módulos (puntaje en anillo y una fila por área con semáforo, barra, % y enlace), la tarjeta
// «¿Llegó un inspector?» que crea el expediente y lo abre para armarlo, y los expedientes recientes.

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { records, recordsHref, type RecordRow } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Icon } from "@/components/ui/Icon";
import { Bar, Card, Chip, Dot, Ring, type Tone } from "./Summary";
import { SunWizard } from "./SunWizard";

type Evidence = { id: string; code: string | null; title: string; state: "current" | "review_overdue" | "not_valid" };
type Area = { workers?: string[]; workers_total?: number; code: string; name: string; module: string; table: string | null; pct: number | null; tone: Tone; detail: string; enabled: boolean; severity: string; severity_label: string;
  no_module?: boolean; evidence?: Evidence[]; sector?: boolean; evidence_hint?: string | null };
const EVIDENCE_STATE: Record<Evidence["state"], string> = { current: "Vigente", review_overdue: "Revisión vencida", not_valid: "Sin versión vigente" };

/**
 * RN-SUN-03: obligación de un módulo no contratado. Se evalúa con documentos de Gestión documental vinculados como evidencia;
 * aquí se vinculan o se quitan (el documento sigue en DOC).
 */
function EvidenceLinker({ area, readOnly, onChanged }: { area: Area; readOnly: boolean; onChanged: () => void }) {
  const [q, setQ] = useState("");
  const [opts, setOpts] = useState<{ id: string; label: string }[] | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const search = () => api<{ data: { id: string; label: string }[] }>(`/sun/diagnosis/evidence-options?q=${encodeURIComponent(q.trim())}`, { withCompany: true })
    .then((r) => setOpts(r.data)).catch((e: ApiError) => setErr(e.detail ?? e.title));
  const act = (p: Promise<unknown>) => p.then(() => { setOpts(null); setQ(""); setErr(null); onChanged(); }).catch((e: ApiError) => setErr(Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title));
  return (
    <div className="ml-6 mb-2 space-y-1.5 rounded-lg bg-bg px-3 py-2 text-xs">
      <p className="font-semibold">Evidencias manuales (Gestión documental)</p>
      {(area.evidence ?? []).length === 0 ? <p className="text-muted">Sin documentos vinculados: la obligación no se da por cumplida.</p> : (
        <ul className="space-y-1">
          {area.evidence?.map((e) => (
            <li key={e.id} className="flex flex-wrap items-center gap-2">
              <Link className="font-medium text-brand hover:underline" href={recordsHref("DOC", "documents", { open: e.id })}>{e.code ? `${e.code} · ` : ""}{e.title}</Link>
              <Chip tone={e.state === "current" ? "ok" : e.state === "review_overdue" ? "warn" : "bad"}>{EVIDENCE_STATE[e.state]}</Chip>
              {!readOnly && <button type="button" className="font-semibold text-muted underline hover:text-bad" onClick={() => void act(api(`/sun/diagnosis/${area.code}/evidence/${e.id}`, { method: "DELETE", withCompany: true }))}>Quitar</button>}
            </li>
          ))}
        </ul>
      )}
      {!readOnly && (
        <div className="flex flex-wrap items-center gap-2">
          <input className="input h-8 max-w-xs text-xs" placeholder="Buscar documento por código o título" aria-label="Buscar documento" value={q}
            onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") void search(); }} />
          <Button size="sm" onClick={() => void search()}>Buscar</Button>
        </div>
      )}
      {opts && (opts.length === 0 ? <p className="text-muted">No hay documentos con ese texto. Súbelo primero en Gestión documental.</p> : (
        <ul className="space-y-1">
          {opts.map((o) => (
            <li key={o.id}><button type="button" className="text-left font-medium text-brand hover:underline" onClick={() => void act(api(`/sun/diagnosis/${area.code}/evidence`, { method: "POST", withCompany: true, body: { document_id: o.id } }))}>Vincular: {o.label}</button></li>
          ))}
        </ul>
      ))}
      {err && <p role="alert" className="text-bad">{err}</p>}
    </div>
  );
}
type Diagnosis = { score: number | null; risk_weighted_score: number | null; very_serious_gaps: number; evaluated_at: string; areas: Area[] };
type Month = { month: string; id: string; score: number | null; weighted: number | null; very_serious_gaps: number };
const MONTHS = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "set", "oct", "nov", "dic"];

const STATUS: Record<string, [string, Tone]> = {
  queued: ["En cola", "na"],
  building: ["Armando", "warn"],
  ready: ["Completo", "ok"],
  ready_with_gaps: ["Con brechas", "warn"],
  failed: ["Falló", "bad"],
  archived: ["Archivado", "na"],
};

const dt = (v: unknown) => (v ? new Intl.DateTimeFormat("es-PE", { dateStyle: "short", timeStyle: "short", timeZone: "America/Lima" }).format(new Date(String(v))) : "");

export function SunSummary({ readOnly }: { readOnly: boolean }) {
  const [diag, setDiag] = useState<Diagnosis | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [recent, setRecent] = useState<RecordRow[] | null>(null);

  const load = useCallback(() => {
    setError(null);
    api<{ data: Diagnosis }>("/sun/diagnosis", { withCompany: true })
      .then((r) => setDiag(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, []);
  // M-SUN §11: posición por mes (último autodiagnóstico guardado de cada mes).
  const [months, setMonths] = useState<Month[] | null>(null);
  const loadMonths = useCallback(() => {
    api<{ data: Month[] }>("/sun/self-diagnostics/monthly", { withCompany: true }).then((r) => setMonths(r.data)).catch(() => setMonths([]));
  }, []);
  useEffect(() => {
    load();
    loadMonths();
    records.list("SUN", "inspection_dossiers", { page: 1 }).then((r) => setRecent(r.data.slice(0, 3))).catch(() => setRecent([]));
  }, [load, loadMonths]);
  const [saving, setSaving] = useState(false);
  const save = async () => {
    setSaving(true);
    try {
      const r = await api<{ data: Diagnosis & { id: string } }>("/sun/self-diagnostics", { method: "POST", withCompany: true });
      setDiag(r.data);
      loadMonths();
      window.open(`/imprimir/autodiagnostico/${r.data.id}`, "_blank");
    } catch (e) {
      setError((e as ApiError).detail ?? (e as ApiError).title);
    } finally {
      setSaving(false);
    }
  };

  // «Generar expediente» abre el asistente (SCR-SUN-03): crea el expediente y lo arma.
  const [wizard, setWizard] = useState(false);
  const reloadRecent = () => records.list("SUN", "inspection_dossiers", { page: 1 }).then((r) => setRecent(r.data.slice(0, 3))).catch(() => undefined);

  const score = diag?.score ?? null;
  return (
    <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <Card title="Autodiagnóstico frente a una inspección" action={
        <span className="flex gap-2">
          <Button size="sm" icon="refresh" onClick={load}>Volver a evaluar</Button>
          {!readOnly && <Button size="sm" variant="primary" icon="file" onClick={() => void save()} disabled={saving}>{saving ? "Guardando…" : "Guardar e informe PDF"}</Button>}
        </span>}>
        {error ? (
          <p className="text-sm text-muted">{error}</p>
        ) : !diag ? (
          <div className="h-48 animate-pulse rounded-lg bg-bg" />
        ) : (
          <>
            <div className="mb-3 flex flex-wrap items-center gap-5">
              <Ring pct={score} label="Puntaje" />
              <div className="min-w-[200px] flex-1 text-sm">
                <p>
                  <b>Puntaje {score ?? "—"} de 100.</b> Si SUNAFIL llegara hoy, estas son las brechas a cerrar primero.
                </p>
                {diag.risk_weighted_score !== null && (
                  <p className="mt-1">Puntaje ponderado por gravedad: <b>{diag.risk_weighted_score}</b>.{" "}
                    {diag.very_serious_gaps > 0 && <span className="font-semibold text-bad">{diag.very_serious_gaps} brecha(s) de infracción muy grave.</span>}</p>
                )}
                <p className="mt-1 text-xs text-muted">Evaluado el {dt(diag.evaluated_at)} con los datos del sistema. Toca un área para ir a resolverla.</p>
              </div>
            </div>
            <ul className="divide-y divide-line">
              {[...diag.areas]
                .sort((a, b) => (a.pct ?? 101) - (b.pct ?? 101))
                .map((a) => (
                  <li key={a.name}>
                    <Link
                      href={a.no_module || a.sector ? recordsHref("DOC", "documents") : a.enabled && a.table ? recordsHref(a.module, a.table) : `/m/${a.module.toLowerCase()}`}
                      className="flex items-center gap-3 rounded-lg px-1 py-2.5 hover:bg-brand-soft/40"
                    >
                      <Dot tone={a.tone} />
                      <span className="min-w-0 flex-1">
                        <b className="block text-sm">{a.name}{a.no_module && <span className="ml-2 align-middle"><Chip tone="na">Sin módulo</Chip></span>}{a.sector && <span className="ml-2 align-middle"><Chip tone="na">Del paquete sectorial</Chip></span>}</b>
                        <span className="block text-xs text-muted">{a.detail}</span>
                        {a.workers && a.workers.length > 0 && (
                          <span className="block text-xs text-muted" title={a.workers.join(", ")}>
                            Faltan: {a.workers.slice(0, 4).join(", ")}{(a.workers_total ?? 0) > 4 ? ` y ${(a.workers_total ?? 0) - 4} más` : ""}
                          </span>
                        )}
                      </span>
                      <span className="hidden text-xs text-muted md:block">{a.severity_label}</span>
                      <span className="hidden w-28 sm:block">{a.pct !== null && <Bar pct={a.pct} tone={a.tone} />}</span>
                      <b className="w-10 text-right font-display tabular-nums">{a.pct ?? "—"}</b>
                    </Link>
                    {a.sector && a.evidence_hint && <p className="text-xs text-muted">Evidencia esperada: {a.evidence_hint}</p>}
                    {(a.no_module || a.sector) && <EvidenceLinker area={a} readOnly={readOnly} onChanged={load} />}
                  </li>
                ))}
            </ul>
          </>
        )}
      </Card>
      <div className="space-y-4">
        <Card title="¿Llegó un inspector?">
          <p className="mb-3 text-sm text-muted">Arma el expediente que te pidan en minutos, por trabajador, periodo u obligación, y compártelo con un enlace que vence solo.</p>
          {!readOnly && (
            <Button variant="primary" size="lg" full onClick={() => setWizard(true)} icon="shield">
              Generar expediente
            </Button>
          )}
          {wizard && <SunWizard onClose={() => setWizard(false)} onDone={reloadRecent} />}
        </Card>
        <Card title="Posición por mes">
          {months === null ? (
            <p className="text-sm text-muted">Cargando…</p>
          ) : months.length === 0 ? (
            <p className="text-sm text-muted">Guarda un autodiagnóstico para ver su evolución mes a mes.</p>
          ) : (
            <ul className="space-y-1.5">
              {months.map((m) => (
                <li key={m.month}>
                  <a href={`/imprimir/autodiagnostico/${m.id}`} target="_blank" rel="noreferrer" className="flex items-center gap-2 text-sm hover:text-brand">
                    <span className="w-16 text-xs text-muted">{MONTHS[Number(m.month.slice(5, 7)) - 1]} {m.month.slice(0, 4)}</span>
                    <span className="flex-1">{m.score !== null && <Bar pct={m.score} tone={m.score >= 90 ? "ok" : m.score >= 70 ? "warn" : "bad"} />}</span>
                    <b className="w-8 text-right tabular-nums">{m.score ?? "—"}</b>
                  </a>
                </li>
              ))}
            </ul>
          )}
        </Card>
        <Card title="Expedientes recientes">
          {recent === null ? (
            <p className="text-sm text-muted">Cargando…</p>
          ) : recent.length === 0 ? (
            <p className="text-sm text-muted">Aún no hay expedientes.</p>
          ) : (
            <ul className="divide-y divide-line">
              {recent.map((x) => {
                const [label, tone] = STATUS[String(x.status ?? "")] ?? [String(x.status ?? "—"), "na" as Tone];
                return (
                  <li key={x.id}>
                    <Link href={recordsHref("SUN", "inspection_dossiers", { open: x.id })} className="flex items-center gap-3 py-2.5 hover:text-brand">
                      <Icon name="file" size={18} />
                      <span className="min-w-0 flex-1">
                        <b className="block truncate text-sm">{String(x.title ?? "Expediente")}</b>
                        <span className="block text-xs text-muted">{x.appearance_at ? `Comparecencia ${dt(x.appearance_at)}` : `Actualizado ${dt(x.updated_at)}`}</span>
                      </span>
                      <Chip tone={tone}>{label}</Chip>
                    </Link>
                  </li>
                );
              })}
            </ul>
          )}
        </Card>
      </div>
    </div>
  );
}
