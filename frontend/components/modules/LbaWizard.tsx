"use client";

// Nuevo diagnóstico de línea base (M-LBA, SCR-LBA-02): datos → un paso por lineamiento con sus ítems (4 opciones
// grandes y nota de evidencia) → resumen con el puntaje. Al pasar de «Datos» se crea el diagnóstico en borrador y se
// cargan las preguntas base (POST /lba/assessments/{id}/load); cada lineamiento se guarda al avanzar. Al final se
// puede aprobar, lo que genera las brechas de cada «parcial» o «no cumple» (RN-LBA-06, DiagnosticRules).

import Link from "next/link";
import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { records, recordsHref, type RecordRow } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { FormField } from "@/components/ui/Form";
import { RefSelect } from "./RefSelect";
import { Bar, Chip, toneOf } from "./Summary";
import { errorText, SummaryRow, Wizard, type WizardStep } from "./Wizard";

type Result = "compliant" | "partial" | "non_compliant" | "not_applicable";
type Item = { id: string; code: string; line: string; lineName: string; question: string; legal: string };
type Answer = { result?: Result; notes?: string };
type Data = {
  assessment_id: string;
  title: string;
  assessment_on: string;
  site_id: string;
  site_label: string;
  items: Item[];
  answers: Record<string, Answer>;
  /** Firma de lo ya guardado por ítem, para no repetir envíos. */
  saved: Record<string, string>;
};

const OPTIONS: { value: Result; title: string; cls: string }[] = [
  { value: "compliant", title: "Cumple", cls: "border-ok bg-ok-soft text-ok" },
  { value: "partial", title: "Parcial", cls: "border-[#F4B90F] bg-amber-soft text-amber-ink" },
  { value: "non_compliant", title: "No cumple", cls: "border-bad bg-bad-soft text-bad" },
  { value: "not_applicable", title: "No aplica", cls: "border-na bg-na-soft text-muted" },
];
const POINTS: Record<string, number> = { compliant: 1, partial: 0.5, non_compliant: 0 };

const today = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};

/** Puntaje como en DiagnosticRules: cumple 1, parcial 0,5, no cumple 0; «no aplica» fuera del denominador. */
export function lbaScore(items: Item[], answers: Record<string, Answer>) {
  let applicable = 0;
  let points = 0;
  for (const i of items) {
    const r = answers[i.id]?.result;
    if (!r || r === "not_applicable") continue;
    applicable++;
    points += POINTS[r] ?? 0;
  }
  return applicable ? Math.round((points * 1000) / applicable) / 10 : null;
}

async function allItems(assessment: string): Promise<RecordRow[]> {
  const out: RecordRow[] = [];
  for (let page = 1; page <= 20; page++) {
    const r = await records.list("LBA", "baseline_items", { page, filters: { assessment_id: assessment } });
    out.push(...r.data);
    if (page >= r.meta.pages) break;
  }
  return out;
}

/** Guarda las respuestas que cambiaron (de a cinco en paralelo). */
async function saveAnswers(d: Data, ids: string[]): Promise<Record<string, string>> {
  const saved = { ...d.saved };
  const todo = ids.filter((id) => {
    const a = d.answers[id];
    return a?.result && saved[id] !== `${a.result}|${a.notes ?? ""}`;
  });
  for (let i = 0; i < todo.length; i += 5) {
    await Promise.all(
      todo.slice(i, i + 5).map(async (id) => {
        const a = d.answers[id];
        await records.update("LBA", "baseline_items", id, { result: a.result, evidence_notes: a.notes?.trim() || null });
        saved[id] = `${a.result}|${a.notes ?? ""}`;
      }),
    );
  }
  return saved;
}

export function LbaWizard({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const initial: Data = { assessment_id: "", title: `Línea base ${new Date().getFullYear()}`, assessment_on: today(), site_id: "", site_label: "", items: [], answers: {}, saved: {} };

  const steps = (d: Data): WizardStep<Data>[] => {
    const lines = [...new Map(d.items.map((i) => [i.line, i.lineName])).entries()].sort(([a], [b]) => a.localeCompare(b));
    return [
      {
        title: "Datos",
        hint: "Al continuar se crea el diagnóstico en borrador con la lista base de EscudoSST.",
        validate: (x) => (!x.title.trim() ? "Escribe un título para el diagnóstico." : !x.assessment_on ? "Indica la fecha del diagnóstico." : null),
        render: (x, set) => (
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField label="Título">
              <input className="input" value={x.title} maxLength={200} onChange={(e) => set({ title: e.target.value })} />
            </FormField>
            <FormField label="Fecha del diagnóstico">
              <input className="input" type="date" value={x.assessment_on} max={today()} onChange={(e) => set({ assessment_on: e.target.value })} />
            </FormField>
            <div className="sm:col-span-2">
              <FormField label="Sede (opcional)" hint="Déjalo vacío para evaluar toda la empresa.">
                <RefSelect module="LBA" table="baseline_assessments" field="site_id" label="Sede" placeholder="Toda la empresa" value={x.site_id} onChange={(id, label) => set({ site_id: id, site_label: id ? label : "" })} />
              </FormField>
            </div>
          </div>
        ),
        onNext: async (x) => {
          const body = { title: x.title.trim(), assessment_on: x.assessment_on, site_id: x.site_id || null, is_initial: true };
          let id = x.assessment_id;
          if (id) await records.update("LBA", "baseline_assessments", id, body);
          else id = (await records.create("LBA", "baseline_assessments", body)).data.id;
          await api(`/lba/assessments/${id}/load`, { method: "POST", withCompany: true });
          const rows = await allItems(id);
          const items: Item[] = rows
            .sort((a, b) => Number(a.sort_order ?? 0) - Number(b.sort_order ?? 0) || String(a.item_code).localeCompare(String(b.item_code)))
            .map((r) => ({ id: r.id, code: String(r.item_code), line: String(r.lineament_code), lineName: String(r.lineament_name ?? r.lineament_code), question: String(r.question), legal: String(r.legal_basis ?? "") }));
          if (!items.length) throw new Error("No hay preguntas base configuradas. Pide al equipo de EscudoSST que publique el cuestionario.");
          // Respuestas ya guardadas en el servidor (si se retoma un diagnóstico).
          const answers = { ...x.answers };
          const saved = { ...x.saved };
          for (const r of rows) {
            if (r.result && r.result !== "pending" && !answers[r.id]?.result) {
              answers[r.id] = { result: r.result as Result, notes: String(r.evidence_notes ?? "") };
              saved[r.id] = `${r.result}|${r.evidence_notes ?? ""}`;
            }
          }
          return { assessment_id: id, items, answers, saved };
        },
      },
      ...lines.map(([line, name], idx): WizardStep<Data> => {
        const its = d.items.filter((i) => i.line === line);
        return {
          title: `${idx + 1}. ${name}`,
          hint: `${its.length} ítem(s). Elige una respuesta por ítem; si no aplica, explica por qué.`,
          validate: (x) => {
            const bad = its.find((i) => x.answers[i.id]?.result === "not_applicable" && !x.answers[i.id]?.notes?.trim());
            return bad ? `Explica por qué no aplica: «${bad.question.slice(0, 80)}».` : null;
          },
          onNext: async (x) => ({ saved: await saveAnswers(x, its.map((i) => i.id)) }),
          render: (x, set) => (
            <ol className="space-y-3">
              {its.map((i) => {
                const a = x.answers[i.id] ?? {};
                const put = (p: Answer) => set({ answers: { ...x.answers, [i.id]: { ...a, ...p } } });
                return (
                  <li key={i.id} className="rounded-xl border border-line p-3">
                    <p className="text-sm font-semibold">{i.question}</p>
                    {i.legal && <p className="text-xs text-muted">{i.legal}</p>}
                    <div role="radiogroup" aria-label={i.question} className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                      {OPTIONS.map((o) => (
                        <button
                          key={o.value}
                          type="button"
                          role="radio"
                          aria-checked={a.result === o.value}
                          onClick={() => put({ result: o.value })}
                          className={`min-h-12 rounded-lg border-2 px-2 text-sm font-semibold transition ${a.result === o.value ? o.cls : "border-line hover:border-brand/50"}`}
                        >
                          {o.title}
                        </button>
                      ))}
                    </div>
                    <textarea
                      className="input mt-2 min-h-[60px]"
                      placeholder={a.result === "not_applicable" ? "¿Por qué no aplica? (obligatorio)" : "Evidencia o comentario (opcional)"}
                      value={a.notes ?? ""}
                      maxLength={2000}
                      onChange={(e) => put({ notes: e.target.value })}
                      aria-label="Nota de evidencia"
                    />
                  </li>
                );
              })}
            </ol>
          ),
        };
      }),
    ];
  };

  return (
    <Wizard<Data>
      id="lba-new"
      title="Nuevo diagnóstico"
      subtitle="Lista de verificación de la línea base del SG-SST"
      icon="checklist"
      initial={initial}
      steps={steps}
      confirmLabel="Guardar diagnóstico"
      onClose={onClose}
      summary={(d, goTo) => {
        const score = lbaScore(d.items, d.answers);
        const pending = d.items.filter((i) => !d.answers[i.id]?.result).length;
        const lines = [...new Map(d.items.map((i) => [i.line, i.lineName])).entries()].sort(([a], [b]) => a.localeCompare(b));
        return (
          <>
            <div className="grid gap-3 sm:grid-cols-3">
              <div className="rounded-xl border border-line p-4">
                <p className="text-sm text-muted">Puntaje</p>
                <p className="font-display text-3xl font-bold text-brand">{score === null ? "—" : `${score.toLocaleString("es-PE")}%`}</p>
              </div>
              <div className="rounded-xl border border-line p-4">
                <p className="text-sm text-muted">Ítems respondidos</p>
                <p className="font-display text-3xl font-bold">{d.items.length - pending} / {d.items.length}</p>
              </div>
              <div className="rounded-xl border border-line p-4">
                <p className="text-sm text-muted">Brechas que se generarán</p>
                <p className="font-display text-3xl font-bold text-bad">{d.items.filter((i) => ["partial", "non_compliant"].includes(d.answers[i.id]?.result ?? "")).length}</p>
              </div>
            </div>
            <div>
              <SummaryRow label="Diagnóstico" value={`${d.title} · ${d.assessment_on.split("-").reverse().join("/")}`} onEdit={() => goTo(0)} />
              <SummaryRow label="Alcance" value={d.site_label || "Toda la empresa"} onEdit={() => goTo(0)} />
            </div>
            <ul className="divide-y divide-line rounded-xl border border-line">
              {lines.map(([line, name], idx) => {
                const its = d.items.filter((i) => i.line === line);
                const pct = lbaScore(its, d.answers);
                const left = its.filter((i) => !d.answers[i.id]?.result).length;
                return (
                  <li key={line} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
                    <button type="button" className="min-w-0 flex-1 text-left text-sm font-medium hover:text-brand" onClick={() => goTo(idx + 1)}>
                      {name}
                      {left > 0 && <span className="ml-2 text-xs text-amber-ink">Faltan {left}</span>}
                    </button>
                    <span className="w-24">{pct !== null && <Bar pct={pct} />}</span>
                    <span className="w-12 text-right text-sm font-semibold tabular-nums">{pct === null ? "—" : `${Math.round(pct)}%`}</span>
                  </li>
                );
              })}
            </ul>
            {pending > 0 && (
              <p className="rounded-lg bg-amber-soft px-3 py-2 text-sm text-amber-ink">
                Quedan {pending} ítem(s) sin responder. Puedes guardar ahora y completarlos después; para aprobar el diagnóstico deben estar todos respondidos.
              </p>
            )}
          </>
        );
      }}
      onConfirm={async (d) => {
        const saved = await saveAnswers(d, d.items.map((i) => i.id));
        await records.update("LBA", "baseline_assessments", d.assessment_id, { title: d.title.trim(), assessment_on: d.assessment_on, site_id: d.site_id || null });
        onDone();
        const pending = d.items.filter((i) => !d.answers[i.id]?.result).length;
        return <LbaResult id={d.assessment_id} pending={pending} answered={Object.keys(saved).length} />;
      }}
    />
  );
}

function LbaResult({ id, pending, answered }: { id: string; pending: number; answered: number }) {
  const [row, setRow] = useState<RecordRow | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const [approved, setApproved] = useState(false);
  const [gaps, setGaps] = useState<number | null>(null);

  useEffect(() => {
    records.get("LBA", "baseline_assessments", id).then((r) => setRow(r.data)).catch(() => setRow({ id, own: true }));
  }, [id]);
  const score = row?.score_pct != null ? Number(row.score_pct) : null;

  const move = async (status: "completed" | "approved") => {
    setBusy(status);
    setMsg(null);
    try {
      const r = await records.update("LBA", "baseline_assessments", id, { status });
      setRow(r.data);
      if (status === "approved") {
        setApproved(true);
        const g = await records.list("LBA", "baseline_gaps", { filters: { assessment_id: id } });
        setGaps(g.meta.total);
      }
    } catch (e) {
      setMsg(errorText(e));
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-3 rounded-xl border border-line p-4">
        <div className="flex-1">
          <p className="text-sm text-muted">Diagnóstico guardado · {answered} respuesta(s)</p>
          <p className="font-display text-3xl font-bold text-brand">{score === null ? "—" : `${score.toLocaleString("es-PE")}%`}</p>
        </div>
        {score !== null && <Chip tone={toneOf(score)}>{score >= 90 ? "Cumple" : score >= 70 ? "En proceso" : "No cumple"}</Chip>}
      </div>
      {approved ? (
        <p className="rounded-lg bg-ok-soft px-3 py-2 text-sm text-ok">
          Diagnóstico aprobado. Se generaron {gaps ?? "las"} brecha(s) para cerrar con acciones correctivas.
        </p>
      ) : pending > 0 ? (
        <p className="text-sm text-muted">Quedan {pending} ítem(s) pendientes. Ábrelo desde la lista para terminarlo; luego podrás aprobarlo y generar las brechas.</p>
      ) : (
        <div className="flex flex-wrap gap-2">
          <Button variant="primary" icon="check" loading={busy === "approved"} disabled={!!busy} onClick={() => move("approved")}>
            Aprobar y generar brechas
          </Button>
          <Button loading={busy === "completed"} disabled={!!busy || row?.status === "completed"} onClick={() => move("completed")}>
            {row?.status === "completed" ? "Marcado como completado" : "Marcar como completado"}
          </Button>
        </div>
      )}
      {msg && <p role="alert" className="text-sm text-bad">{msg}</p>}
      <div className="flex flex-wrap gap-3 text-sm">
        <Link className="font-semibold text-brand hover:underline" href={recordsHref("LBA", "baseline_assessments", { open: id })}>Abrir el diagnóstico</Link>
        {approved && <Link className="font-semibold text-brand hover:underline" href={recordsHref("LBA", "baseline_gaps", { filters: { assessment_id: id } })}>Ver brechas</Link>}
      </div>
    </div>
  );
}
