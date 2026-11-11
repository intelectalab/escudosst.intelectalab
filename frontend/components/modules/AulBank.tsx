"use client";

// Banco de preguntas del aula (M-AUL SCR-AUL-09; prototipo m-aula.js «aul-banco»): bancos generales (reutilizables)
// y por curso con el número de preguntas por tipo y dificultad; al elegir un banco, sus preguntas con el editor de
// enunciado y de alternativas (el mismo editor de la ficha de la pregunta) e importación desde la plantilla de Excel.

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { records, type RecordRow } from "@/lib/records";
import { QuestionOptions } from "@/components/addons/AulLearner";
import { Button } from "@/components/ui/Button";
import { Chip } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { RefSelect } from "./RefSelect";
import { errorText } from "./Wizard";
import { AulCrumbs } from "./AulAdmin";
import { AulQuestionImport } from "./AulImport";

type Bank = {
  id: string; code: string; name: string; topic: string | null; description: string | null; status: string; course_id: string | null; course_code: string | null; course_title: string | null;
  questions: number; by_type: Record<string, number>; by_difficulty: Record<string, number>; used_in: number;
};
const QTYPE: Record<string, string> = { single_choice: "Opción única", multiple_choice: "Opción múltiple", true_false: "Verdadero o falso", short_answer: "Respuesta corta" };
const DIFF: Record<string, string> = { easy: "Baja", medium: "Media", hard: "Alta", none: "Sin dificultad" };

export function AulBank({ readOnly }: { readOnly: boolean }) {
  const [banks, setBanks] = useState<Bank[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [seg, setSeg] = useState<"all" | "general" | "course">("all");
  const [sel, setSel] = useState<string | null>(null);
  const [creating, setCreating] = useState(false);
  const load = useCallback(() => {
    api<{ data: Bank[] }>("/aul/admin/banks", { withCompany: true }).then((r) => setBanks(r.data)).catch((e) => setError(errorText(e)));
  }, []);
  useEffect(load, [load]);
  useEffect(() => {
    const b = new URLSearchParams(window.location.search).get("bank");
    if (b) setSel(b);
  }, []);
  const list = (banks ?? []).filter((b) => seg === "all" || (seg === "general" ? !b.course_id : !!b.course_id));
  const bank = banks?.find((b) => b.id === sel) ?? null;

  return (
    <div className="space-y-4">
      <AulCrumbs items={[{ label: "Banco de preguntas" }]} />
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="font-display text-2xl font-bold">Banco de preguntas</h1>
          <p className="text-sm text-muted">Bancos generales para reutilizar en varios cursos y bancos propios de cada curso. Las evaluaciones sortean sus preguntas desde aquí.</p>
        </div>
        {!readOnly && <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>Nuevo banco</Button>}
      </div>
      {error && <p className="rounded-lg bg-bad-soft px-4 py-2 text-sm text-bad">{error}</p>}
      <div role="tablist" aria-label="Filtrar bancos" className="inline-flex rounded-lg border border-line bg-surface p-0.5 text-sm">
        {([["all", `Todos (${banks?.length ?? 0})`], ["general", "Generales"], ["course", "Del curso"]] as const).map(([v, l]) => (
          <button key={v} type="button" role="tab" aria-selected={seg === v} onClick={() => setSeg(v)}
            className={`rounded-md px-3 py-1.5 font-semibold ${seg === v ? "bg-brand text-white" : "text-muted hover:text-fg"}`}>{l}</button>
        ))}
      </div>
      {!banks ? <div className="h-32 animate-pulse rounded-xl bg-surface" /> : list.length === 0 ? (
        <div className="rounded-xl border border-line bg-surface p-8 text-center text-sm text-muted">No hay bancos en este filtro.</div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-line bg-surface">
          <table className="w-full min-w-[640px] text-sm">
            <thead className="bg-bg text-left text-xs text-muted">
              <tr>{["Banco", "Ámbito", "Preguntas", "Tipos", "Dificultad", "Estado"].map((h) => <th key={h} className="px-3 py-2 font-semibold">{h}</th>)}</tr>
            </thead>
            <tbody className="divide-y divide-line">
              {list.map((b) => (
                <tr key={b.id} className={`cursor-pointer hover:bg-bg ${sel === b.id ? "bg-brand-soft/40" : ""}`} onClick={() => setSel(b.id)}>
                  <td className="px-3 py-2"><button type="button" className="text-left" onClick={() => setSel(b.id)}><span className="font-mono text-[11px] text-muted">{b.code}</span><b className="block">{b.name}</b></button></td>
                  <td className="px-3 py-2">{b.course_id ? <span>Del curso <span className="text-xs text-muted">{b.course_code}</span></span> : "General"}</td>
                  <td className="px-3 py-2 font-semibold tabular-nums">{b.questions}</td>
                  <td className="px-3 py-2 text-xs">{Object.entries(b.by_type).map(([k, v]) => `${QTYPE[k] ?? k}: ${v}`).join(" · ") || "—"}</td>
                  <td className="px-3 py-2 text-xs">{Object.entries(b.by_difficulty).map(([k, v]) => `${DIFF[k] ?? k}: ${v}`).join(" · ") || "—"}</td>
                  <td className="px-3 py-2"><Chip tone={b.status === "active" ? "ok" : "na"}>{b.status === "active" ? "Activo" : "Archivado"}</Chip></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {bank && <BankQuestions key={bank.id} bank={bank} readOnly={readOnly} onChanged={load} />}
      {creating && <NewBank onClose={() => setCreating(false)} onSaved={(id) => { setCreating(false); setSel(id); load(); }} />}
    </div>
  );
}

function NewBank({ onClose, onSaved }: { onClose: () => void; onSaved: (id: string) => void }) {
  const [f, setF] = useState({ code: "", name: "", topic: "", course_id: "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const save = async () => {
    setBusy(true);
    try {
      const r = await records.create("AUL", "question_banks", { code: f.code, name: f.name, topic: f.topic || null, course_id: f.course_id || null, status: "active" });
      onSaved(r.data.id);
    } catch (e) {
      setErrors((e as { errors?: Record<string, string[]> }).errors ?? { name: [errorText(e)] });
      setBusy(false);
    }
  };
  return (
    <Modal title="Nuevo banco de preguntas" icon="plus" size="sm" onClose={onClose}
      footer={<div className="flex w-full justify-end gap-2"><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} onClick={save}>Crear banco</Button></div>}>
      <div className="space-y-3">
        <FormField label="Código *" hint="Por ejemplo BG-04 (general) o BC-014 (del curso)." error={errors.code?.[0]}><input className="input" value={f.code} onChange={(e) => setF({ ...f, code: e.target.value.toUpperCase() })} /></FormField>
        <FormField label="Nombre *" error={errors.name?.[0]}><input className="input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} /></FormField>
        <FormField label="Tema"><input className="input" value={f.topic} onChange={(e) => setF({ ...f, topic: e.target.value })} /></FormField>
        <FormField label="Curso" hint="Vacío: banco general, reutilizable en cualquier curso.">
          <RefSelect module="AUL" table="question_banks" field="course_id" label="Curso" value={f.course_id} onChange={(v) => setF({ ...f, course_id: v })} placeholder="Banco general" />
        </FormField>
      </div>
    </Modal>
  );
}

function BankQuestions({ bank, readOnly, onChanged }: { bank: Bank; readOnly: boolean; onChanged: () => void }) {
  const [rows, setRows] = useState<RecordRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [editing, setEditing] = useState<RecordRow | "new" | null>(null);
  const [importing, setImporting] = useState(false);
  const load = useCallback(() => {
    records.list("AUL", "questions", { page, filters: { question_bank_id: bank.id } }).then((r) => { setRows(r.data); setPages(r.meta.pages); }).catch((e) => setError(errorText(e)));
  }, [bank.id, page]);
  useEffect(load, [load]);
  const changed = () => { load(); onChanged(); };

  return (
    <section className="space-y-3 rounded-xl border border-line bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="font-display text-lg font-semibold">{bank.code} · {bank.name}</h2>
          <p className="text-xs text-muted">{bank.course_id ? `Del curso ${bank.course_code} ${bank.course_title ?? ""}` : "Banco general"} · usado en {bank.used_in} evaluación(es)</p>
        </div>
        {!readOnly && (
          <div className="flex flex-wrap gap-2">
            <Button icon="file" onClick={() => setImporting(true)}>Importar desde Excel</Button>
            <Button variant="primary" icon="plus" onClick={() => setEditing("new")}>Nueva pregunta</Button>
          </div>
        )}
      </div>
      {error && <p className="text-sm text-bad">{error}</p>}
      {!rows ? <div className="h-20 animate-pulse rounded-xl bg-bg" /> : rows.length === 0 ? <p className="text-sm text-muted">Este banco aún no tiene preguntas.</p> : (
        <ol className="divide-y divide-line rounded-xl border border-line">
          {rows.map((q, i) => {
            const opts = (typeof q.options === "string" ? JSON.parse(q.options || "[]") : q.options ?? []) as { text: string; correct: boolean }[];
            return (
              <li key={q.id} className="flex flex-wrap items-start gap-3 px-3 py-2.5">
                <span className="mt-0.5 text-xs font-semibold text-muted">{(page - 1) * 25 + i + 1}.</span>
                <span className="min-w-0 flex-1 text-sm">
                  <b className="block">{String(q.statement ?? "")}</b>
                  <span className="text-xs text-muted">
                    {QTYPE[String(q.question_type)] ?? String(q.question_type)} · {DIFF[String(q.difficulty ?? "none")]} · {Number(q.points ?? 1)} pt
                    {opts.length ? ` · correcta: ${opts.filter((o) => o.correct).map((o) => o.text).join(", ") || "sin marcar"}` : q.question_type === "short_answer" ? "" : " · sin alternativas"}
                  </span>
                </span>
                {q.status !== "active" && <Chip tone="na">{q.status === "draft" ? "Borrador" : "Archivada"}</Chip>}
                <Button size="sm" variant="ghost" onClick={() => setEditing(q)}>{readOnly ? "Ver" : "Editar"}</Button>
              </li>
            );
          })}
        </ol>
      )}
      {pages > 1 && (
        <div className="flex items-center justify-end gap-2 text-sm">
          <Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>Anterior</Button>
          <span>{page} de {pages}</span>
          <Button size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>Siguiente</Button>
        </div>
      )}
      {importing && <AulQuestionImport bank={bank} onClose={() => setImporting(false)} onDone={changed} />}
      {editing && <QuestionEditor bankId={bank.id} row={editing === "new" ? null : editing} readOnly={readOnly} onClose={() => setEditing(null)} onChanged={changed} />}
    </section>
  );
}

function QuestionEditor({ bankId, row, readOnly, onClose, onChanged }: { bankId: string; row: RecordRow | null; readOnly: boolean; onClose: () => void; onChanged: () => void }) {
  const [current, setCurrent] = useState<RecordRow | null>(row);
  const [f, setF] = useState({
    statement: String(row?.statement ?? ""), question_type: String(row?.question_type ?? "single_choice"), difficulty: String(row?.difficulty ?? "medium"),
    points: String(row?.points ?? "1"), feedback_correct: String(row?.feedback_correct ?? ""), feedback_incorrect: String(row?.feedback_incorrect ?? ""), status: String(row?.status ?? "active"),
  });
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (p: Partial<typeof f>) => setF((x) => ({ ...x, ...p }));
  const save = async () => {
    setBusy(true);
    setMsg(null);
    const body = { statement: f.statement, difficulty: f.difficulty, points: Number(f.points) || 1, feedback_correct: f.feedback_correct || null, feedback_incorrect: f.feedback_incorrect || null, status: f.status };
    try {
      if (current) {
        const r = await records.update("AUL", "questions", current.id, body);
        setCurrent(r.data);
      } else {
        const r = await records.create("AUL", "questions", { ...body, question_bank_id: bankId, question_type: f.question_type });
        setCurrent(r.data);
        if (f.question_type === "true_false") {
          await api(`/aul/questions/${r.data.id}/options`, { method: "PUT", withCompany: true, body: { options: [{ text: "Verdadero", correct: true }, { text: "Falso", correct: false }] } }).catch(() => undefined);
          setCurrent((await records.get("AUL", "questions", r.data.id)).data);
        }
      }
      setMsg({ ok: true, text: current ? "Pregunta guardada." : "Pregunta creada: ahora escribe sus alternativas." });
      onChanged();
    } catch (e) {
      setMsg({ ok: false, text: errorText(e) });
    } finally {
      setBusy(false);
    }
  };
  const remove = async () => {
    if (!current) return;
    try {
      await records.remove("AUL", "questions", current.id);
      onChanged();
      onClose();
    } catch (e) {
      setMsg({ ok: false, text: errorText(e) });
    }
  };
  return (
    <Modal title={current ? "Editar pregunta" : "Nueva pregunta"} icon="pen" size="lg" onClose={onClose}
      footer={
        <div className="flex w-full flex-wrap justify-between gap-2">
          {current && !readOnly ? <Button variant="danger-ghost" icon="trash" onClick={remove}>Eliminar</Button> : <span />}
          <div className="flex gap-2"><Button onClick={onClose}>Cerrar</Button>{!readOnly && <Button variant="primary" loading={busy} onClick={save}>{current ? "Guardar" : "Crear pregunta"}</Button>}</div>
        </div>
      }>
      <div className="space-y-3">
        <FormField label="Enunciado *"><textarea className="input min-h-20" disabled={readOnly} value={f.statement} onChange={(e) => set({ statement: e.target.value })} /></FormField>
        <div className="grid gap-3 sm:grid-cols-4">
          <FormField label="Tipo">
            <select className="input" disabled={!!current || readOnly} value={f.question_type} onChange={(e) => set({ question_type: e.target.value })}>
              {Object.entries(QTYPE).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
          </FormField>
          <FormField label="Dificultad">
            <select className="input" disabled={readOnly} value={f.difficulty} onChange={(e) => set({ difficulty: e.target.value })}>
              <option value="easy">Baja</option><option value="medium">Media</option><option value="hard">Alta</option>
            </select>
          </FormField>
          <FormField label="Puntos"><input className="input" disabled={readOnly} inputMode="decimal" value={f.points} onChange={(e) => set({ points: e.target.value })} /></FormField>
          <FormField label="Estado">
            <select className="input" disabled={readOnly} value={f.status} onChange={(e) => set({ status: e.target.value })}>
              <option value="active">Activa</option><option value="draft">Borrador</option><option value="archived">Archivada</option>
            </select>
          </FormField>
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <FormField label="Retroalimentación si acierta"><input className="input" disabled={readOnly} value={f.feedback_correct} onChange={(e) => set({ feedback_correct: e.target.value })} /></FormField>
          <FormField label="Retroalimentación si falla"><input className="input" disabled={readOnly} value={f.feedback_incorrect} onChange={(e) => set({ feedback_incorrect: e.target.value })} /></FormField>
        </div>
        {msg && <p className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</p>}
        {current && <QuestionOptions key={String(current.updated_at ?? current.id)} row={current} canEdit={!readOnly} onChanged={onChanged} />}
      </div>
    </Modal>
  );
}
