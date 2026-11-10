"use client";

// Aula virtual (AUL): «Mis cursos» (arriba del módulo) y el reproductor del curso con lecciones y examen.
// También el editor de alternativas de una pregunta del banco.

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { Button } from "@/components/ui/Button";
import { api, ApiError } from "@/lib/api";
import type { RecordRow } from "@/lib/records";

type MyCourse = { id: string; title: string; status: string; progress_pct: number; final_score: number | null; summary: string | null; hours: number | null };
const ST: Record<string, string> = { enrolled: "Sin empezar", in_progress: "En curso", completed: "Completado", failed: "Desaprobado", expired: "Vencido" };
const errText = (e: unknown) => Object.values((e as ApiError).errors ?? {})[0]?.[0] ?? (e as ApiError).detail ?? (e as ApiError).title;

export function MyCourses() {
  const [items, setItems] = useState<MyCourse[] | null>(null);
  useEffect(() => {
    api<{ data: MyCourse[] }>("/aul/my", { withCompany: true }).then((r) => setItems(r.data)).catch(() => setItems([]));
  }, []);
  if (!items || items.length === 0) return null;
  return (
    <section className="space-y-2 rounded-2xl border border-line bg-surface p-4 shadow-sm">
      <h2 className="font-display text-lg font-semibold">Mis cursos</h2>
      <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        {items.map((c) => (
          <Link key={c.id} href={`/aula/${c.id}`} className="space-y-2 rounded-xl border border-line p-3 hover:border-brand">
            <b className="block">{c.title}</b>
            <div className="h-2 overflow-hidden rounded-full bg-bg"><div className="h-full rounded-full bg-brand" style={{ width: `${Number(c.progress_pct)}%` }} /></div>
            <span className="text-xs text-muted">{ST[c.status] ?? c.status} · {Number(c.progress_pct)} %{c.final_score !== null ? ` · nota ${c.final_score}` : ""}</span>
          </Link>
        ))}
      </div>
    </section>
  );
}

type Lesson = {
  id: string; section_id: string | null; title: string; lesson_type: string; content_html: string | null; video_external_id: string | null; external_url: string | null;
  is_required: boolean; done: boolean; best_score: number | null; max_attempts: number | null;
  questions?: { id: string; type: string; statement: string; options: { key: string; text: string }[] }[];
};
type Player = { enrollment: { id: string; status: string; progress_pct: number }; course: { title: string; summary: string | null }; lessons: Lesson[] };

export function CoursePlayer({ id }: { id: string }) {
  const [data, setData] = useState<Player | null>(null);
  const [active, setActive] = useState<string | null>(null);
  const [answers, setAnswers] = useState<Record<string, string | string[]>>({});
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const load = useCallback(() => {
    api<{ data: Player }>(`/aul/enrollments/${id}`, { withCompany: true }).then((r) => {
      setData(r.data);
      setActive((a) => a ?? r.data.lessons.find((l) => !l.done)?.id ?? r.data.lessons[0]?.id ?? null);
    }).catch((e) => setMsg({ text: errText(e), ok: false }));
  }, [id]);
  useEffect(load, [load]);
  if (!data) return <p className="p-6 text-sm text-muted">{msg?.text ?? "Cargando el curso…"}</p>;
  const l = data.lessons.find((x) => x.id === active);
  const done = () => api(`/aul/enrollments/${id}/lessons/${l!.id}/done`, { method: "POST", withCompany: true }).then(() => { setMsg(null); load(); }).catch((e) => setMsg({ text: errText(e), ok: false }));
  const exam = () =>
    api<{ data: { score_pct: number; passing: number; passed: boolean } }>(`/aul/enrollments/${id}/lessons/${l!.id}/exam`, { method: "POST", withCompany: true, body: { answers } })
      .then((r) => { setMsg({ text: `Nota ${r.data.score_pct} % (mínimo ${r.data.passing} %): ${r.data.passed ? "¡aprobaste!" : "no aprobaste, repasa e inténtalo de nuevo."}`, ok: r.data.passed }); load(); })
      .catch((e) => setMsg({ text: errText(e), ok: false }));

  return (
    <div className="grid gap-4 lg:grid-cols-[18rem_1fr]">
      <aside className="space-y-2 rounded-2xl border border-line bg-surface p-4">
        <h1 className="font-display text-lg font-semibold">{data.course.title}</h1>
        <div className="h-2 overflow-hidden rounded-full bg-bg"><div className="h-full bg-brand" style={{ width: `${Number(data.enrollment.progress_pct)}%` }} /></div>
        <p className="text-xs text-muted">{ST[data.enrollment.status] ?? data.enrollment.status} · {Number(data.enrollment.progress_pct)} %</p>
        <ol className="space-y-1 text-sm">
          {data.lessons.map((x, i) => (
            <li key={x.id}>
              <button type="button" onClick={() => { setActive(x.id); setMsg(null); setAnswers({}); }}
                className={`flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left ${active === x.id ? "bg-brand-soft font-semibold" : "hover:bg-bg"}`}>
                <span className={`grid h-5 w-5 shrink-0 place-items-center rounded-full text-[10px] ${x.done ? "bg-ok text-white" : "border border-line"}`}>{x.done ? "✓" : i + 1}</span>
                {x.title}{x.lesson_type === "exam" ? " (examen)" : ""}
              </button>
            </li>
          ))}
        </ol>
      </aside>
      {l && (
        <section className="space-y-4 rounded-2xl border border-line bg-surface p-5">
          <h2 className="font-display text-xl font-semibold">{l.title}</h2>
          {l.lesson_type === "text" && <div className="prose max-w-none text-sm" dangerouslySetInnerHTML={{ __html: l.content_html ?? "" }} />}
          {l.lesson_type === "video" && l.video_external_id && (
            <div className="aspect-video overflow-hidden rounded-xl bg-black"><iframe className="h-full w-full" src={`https://player.vimeo.com/video/${l.video_external_id}?dnt=1`} title={l.title} allow="fullscreen" /></div>
          )}
          {l.lesson_type === "link" && l.external_url && <a href={l.external_url} target="_blank" rel="noreferrer" className="font-semibold text-brand underline">Abrir el material ↗</a>}
          {l.lesson_type === "document" && <p className="text-sm text-muted">Revisa el documento adjunto de esta lección.</p>}
          {l.lesson_type === "exam" ? (
            <div className="space-y-3">
              {(l.questions ?? []).length === 0 && <p className="text-sm text-muted">El examen aún no tiene preguntas.</p>}
              {(l.questions ?? []).map((q, qi) => (
                <fieldset key={q.id} className="space-y-1 rounded-xl bg-bg p-3 text-sm">
                  <legend className="font-semibold">{qi + 1}. {q.statement}</legend>
                  {q.type === "short_answer" ? (
                    <input className="input" value={String(answers[q.id] ?? "")} onChange={(e) => setAnswers({ ...answers, [q.id]: e.target.value })} />
                  ) : (
                    q.options.map((o) => (
                      <label key={o.key} className="flex items-center gap-2">
                        <input type={q.type === "multiple_choice" ? "checkbox" : "radio"} name={q.id}
                          checked={q.type === "multiple_choice" ? ((answers[q.id] as string[]) ?? []).includes(o.key) : answers[q.id] === o.key}
                          onChange={(e) => setAnswers({ ...answers, [q.id]: q.type === "multiple_choice"
                            ? (e.target.checked ? [...((answers[q.id] as string[]) ?? []), o.key] : ((answers[q.id] as string[]) ?? []).filter((k) => k !== o.key)) : o.key })} />
                        {o.text}
                      </label>
                    ))
                  )}
                </fieldset>
              ))}
              {(l.questions ?? []).length > 0 && !l.done && <Button variant="primary" onClick={exam}>Enviar respuestas</Button>}
              {l.done && <p className="text-sm text-ok">Examen aprobado{l.best_score !== null ? ` con ${l.best_score} %` : ""}.</p>}
            </div>
          ) : l.done ? <p className="text-sm text-ok">Lección vista ✓</p> : <Button variant="primary" onClick={done}>Marcar como vista</Button>}
          {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
        </section>
      )}
    </div>
  );
}

export function QuestionOptions({ row, canEdit, onChanged }: { row: RecordRow; canEdit: boolean; onChanged: () => void }) {
  const short = row.question_type === "short_answer";
  const initial = (typeof row.options === "string" ? JSON.parse(row.options || "[]") : row.options ?? []) as { text: string; correct: boolean }[];
  const [opts, setOpts] = useState(initial.length ? initial : [{ text: "", correct: false }, { text: "", correct: false }]);
  const [accepted, setAccepted] = useState(((typeof row.accepted_answers === "string" ? JSON.parse(row.accepted_answers || "[]") : row.accepted_answers) ?? []).join("\n"));
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const save = () =>
    api(`/aul/questions/${row.id}/options`, { method: "PUT", withCompany: true, body: short ? { accepted: accepted.split("\n").map((s: string) => s.trim()).filter(Boolean) } : { options: opts } })
      .then(() => { setMsg({ text: "Guardado.", ok: true }); onChanged(); })
      .catch((e) => setMsg({ text: errText(e), ok: false }));
  return (
    <section className="space-y-2 rounded-xl border border-line px-4 py-3 text-sm">
      <p className="font-semibold">{short ? "Respuestas aceptadas (una por línea)" : "Alternativas (marca la correcta)"}</p>
      {short ? (
        <textarea className="input min-h-[70px]" disabled={!canEdit} value={accepted} onChange={(e) => setAccepted(e.target.value)} />
      ) : (
        opts.map((o, i) => (
          <div key={i} className="flex items-center gap-2">
            <input type={row.question_type === "multiple_choice" ? "checkbox" : "radio"} name={`q-${row.id}`} disabled={!canEdit} checked={o.correct}
              onChange={(e) => setOpts(opts.map((x, k) => (k === i ? { ...x, correct: e.target.checked } : row.question_type === "multiple_choice" ? x : { ...x, correct: false })))} aria-label="Correcta" />
            <input className="input flex-1" disabled={!canEdit} value={o.text} onChange={(e) => setOpts(opts.map((x, k) => (k === i ? { ...x, text: e.target.value } : x)))} aria-label="Alternativa" />
            {canEdit && opts.length > 2 && <button type="button" className="text-xs text-bad" onClick={() => setOpts(opts.filter((_, k) => k !== i))}>Quitar</button>}
          </div>
        ))
      )}
      {canEdit && (
        <div className="flex gap-2">
          {!short && opts.length < 8 && <Button size="sm" variant="ghost" icon="plus" onClick={() => setOpts([...opts, { text: "", correct: false }])}>Agregar</Button>}
          <Button size="sm" variant="primary" onClick={save}>Guardar</Button>
        </div>
      )}
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </section>
  );
}
