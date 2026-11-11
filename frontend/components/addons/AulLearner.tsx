"use client";

// Aula virtual (AUL): «Mis cursos» (arriba del módulo) y el reproductor del curso con lecciones, evaluación con
// preguntas sorteadas por intento (temporizador y autoguardado), latidos de permanencia y vista como participante.
// También el editor de alternativas de una pregunta del banco.

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
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

type Question = { id: string; type: string; statement: string; points: number; options: { key: string; text: string }[] };
type Answers = Record<string, string | string[]>;
type Attempt = { id: string; attempt_number: number; started_at: string; expires_at: string | null; questions: Question[]; answers: Answers };
type Lesson = {
  id: string; section_id: string | null; title: string; lesson_type: string; content_html: string | null; video_external_id: string | null; external_url: string | null;
  is_required: boolean; done: boolean; best_score: number | null; max_attempts: number | null; time_limit_minutes: number | null;
  num_questions?: number | null; attempts_used?: number; pending_review?: boolean; open_attempt?: Attempt | null;
  last_result?: { score_pct: number | null; label: string | null; passed: boolean; feedback: string | null } | null;
};
type Player = {
  enrollment: { id: string; status: string; progress_pct: number; certificate_id: string | null; active_seconds_total: number; final_score_label: string | null } | null;
  course: { id: string; title: string; summary: string | null; min_active_time_pct: number | null; hours: number | null }; lessons: Lesson[]; heartbeat_seconds: number; preview?: boolean;
};
type Result = { score_pct: number | null; passing: number; passed: boolean | null; pending: boolean; label: string | null; detail: { id: string; ok: boolean | null; feedback: string | null }[] };

const hm = (s: number) => { const m = Math.floor(s / 60); return m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, "0")} min`; };
const clock = (s: number) => `${Math.floor(s / 60)}:${String(Math.max(0, s) % 60).padStart(2, "0")}`;

/** Latidos de permanencia (RN-AUL-07): cada `seconds` mientras la pestaña está visible; cuenta la interacción real. */
function useHeartbeat(enrollmentId: string | null, lessonId: string | null, seconds: number, onTotal: (t: number) => void) {
  const interactions = useRef(0);
  const seq = useRef(0);
  const session = useRef(`s-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`);
  const lesson = useRef(lessonId);
  lesson.current = lessonId;
  const total = useRef(onTotal);
  total.current = onTotal;
  useEffect(() => {
    if (!enrollmentId) return;
    const bump = () => { interactions.current += 1; };
    const events = ["click", "keydown", "scroll", "touchstart", "pointermove"] as const;
    events.forEach((ev) => window.addEventListener(ev, bump, { passive: true }));
    const beat = () => {
      if (document.visibilityState !== "visible") return;
      seq.current += 1;
      const n = interactions.current;
      interactions.current = 0;
      const device = window.matchMedia("(max-width: 640px)").matches ? "mobile" : window.matchMedia("(max-width: 1024px)").matches ? "tablet" : "desktop";
      api<{ data: { active_seconds_total: number } }>(`/aul/enrollments/${enrollmentId}/heartbeat`, {
        method: "POST", withCompany: true, body: { client_session_id: session.current, seq: seq.current, lesson_id: lesson.current, interactions: n, visible: true, device_type: device },
      }).then((r) => total.current(r.data.active_seconds_total)).catch(() => undefined);
    };
    beat();
    const t = window.setInterval(beat, Math.max(10, seconds) * 1000);
    return () => { window.clearInterval(t); events.forEach((ev) => window.removeEventListener(ev, bump)); };
  }, [enrollmentId, seconds]);
}

/**
 * Reproductor del curso (SCR-AUL-22/23). Con `preview` es «Ver como participante» del administrador: el curso tal
 * como lo ve quien aprende, sin matrícula, sin avance y sin guardar intentos.
 */
export function CoursePlayer({ id, preview = false }: { id: string; preview?: boolean }) {
  const [data, setData] = useState<Player | null>(null);
  const [active, setActive] = useState<string | null>(null);
  const [msg, setMsg] = useState<{ text: string; ok: boolean } | null>(null);
  const [activeSeconds, setActiveSeconds] = useState(0);
  const load = useCallback(() => {
    api<{ data: Player }>(preview ? `/aul/admin/courses/${id}/preview` : `/aul/enrollments/${id}`, { withCompany: true }).then((r) => {
      setData(r.data);
      setActiveSeconds(r.data.enrollment?.active_seconds_total ?? 0);
      setActive((a) => a ?? r.data.lessons.find((l) => !l.done)?.id ?? r.data.lessons[0]?.id ?? null);
    }).catch((e) => setMsg({ text: errText(e), ok: false }));
  }, [id, preview]);
  useEffect(load, [load]);
  useHeartbeat(!preview && data?.enrollment ? data.enrollment.id : null, active, data?.heartbeat_seconds ?? 30, setActiveSeconds);
  if (!data) return <p className="p-6 text-sm text-muted">{msg?.text ?? "Cargando el curso…"}</p>;
  const l = data.lessons.find((x) => x.id === active);
  const e = data.enrollment;
  const need = data.course.min_active_time_pct != null && data.course.hours != null ? Math.ceil(Number(data.course.hours) * 3600 * Number(data.course.min_active_time_pct) / 100) : 0;
  const done = () => api(`/aul/enrollments/${id}/lessons/${l!.id}/done`, { method: "POST", withCompany: true }).then(() => { setMsg(null); load(); }).catch((err) => setMsg({ text: errText(err), ok: false }));

  return (
    <div className="space-y-3">
      {preview && (
        <p className="rounded-xl border border-line bg-surface px-4 py-3 text-sm text-muted">
          <b className="text-fg">Vista como participante.</b> Así ve el curso quien aprende. No se crea matrícula ni se guarda avance; las evaluaciones muestran un sorteo de ejemplo.
        </p>
      )}
      <div className="grid gap-4 lg:grid-cols-[18rem_1fr]">
        <aside className="space-y-2 rounded-2xl border border-line bg-surface p-4">
          <h1 className="font-display text-lg font-semibold">{data.course.title}</h1>
          {e && (
            <>
              <div className="h-2 overflow-hidden rounded-full bg-bg"><div className="h-full bg-brand" style={{ width: `${Number(e.progress_pct)}%` }} /></div>
              <p className="text-xs text-muted">{ST[e.status] ?? e.status} · {Number(e.progress_pct)} %{e.final_score_label ? ` · nota ${e.final_score_label}` : ""}</p>
              <p className="text-xs text-muted">Tiempo activo: <b className="text-fg">{hm(activeSeconds)}</b>{need > 0 ? ` de ${hm(need)} requeridos` : ""}</p>
              {need > 0 && activeSeconds < need && Number(e.progress_pct) >= 100 && <p className="text-xs text-amber-ink">Te falta {hm(need - activeSeconds)} de permanencia para completar el curso.</p>}
            </>
          )}
          {e?.certificate_id && (
            <a href={`/imprimir/certificado/${e.certificate_id}`} target="_blank" rel="noreferrer" className="block rounded-lg bg-ok-soft px-3 py-2 text-sm font-semibold text-ok">
              Curso completado: ver mi certificado
            </a>
          )}
          <ol className="space-y-1 text-sm">
            {data.lessons.map((x, i) => (
              <li key={x.id}>
                <button type="button" onClick={() => { setActive(x.id); setMsg(null); }}
                  className={`flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left ${active === x.id ? "bg-brand-soft font-semibold" : "hover:bg-bg"}`}>
                  <span className={`grid h-5 w-5 shrink-0 place-items-center rounded-full text-[10px] ${x.done ? "bg-ok text-white" : "border border-line"}`}>{x.done ? "✓" : i + 1}</span>
                  {x.title}{x.lesson_type === "exam" ? " (evaluación)" : ""}
                </button>
              </li>
            ))}
          </ol>
        </aside>
        {l && (
          <section className="min-w-0 space-y-4 rounded-2xl border border-line bg-surface p-5">
            <h2 className="font-display text-xl font-semibold">{l.title}</h2>
            {l.lesson_type === "text" && <div className="prose max-w-none text-sm" dangerouslySetInnerHTML={{ __html: l.content_html ?? "" }} />}
            {l.lesson_type === "video" && l.video_external_id && (
              <div className="aspect-video overflow-hidden rounded-xl bg-black"><iframe className="h-full w-full" src={`https://player.vimeo.com/video/${l.video_external_id}?dnt=1`} title={l.title} allow="fullscreen" /></div>
            )}
            {l.lesson_type === "link" && l.external_url && <a href={l.external_url} target="_blank" rel="noreferrer" className="font-semibold text-brand underline">Abrir el material ↗</a>}
            {l.lesson_type === "document" && <p className="text-sm text-muted">Revisa el documento adjunto de esta lección.</p>}
            {l.lesson_type === "exam" ? (
              preview ? <PreviewExam key={l.id} courseId={id} lesson={l} /> : <Exam key={l.id} enrollmentId={id} lesson={l} onDone={load} />
            ) : preview ? <p className="text-sm text-muted">Quien aprende marca aquí la lección como vista.</p>
              : l.done ? <p className="text-sm text-ok">Lección vista ✓</p> : <Button variant="primary" onClick={done}>Marcar como vista</Button>}
            {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
          </section>
        )}
      </div>
    </div>
  );
}

function QuestionList({ questions, answers, onChange, readOnly, results }: { questions: Question[]; answers: Answers; onChange: (a: Answers) => void; readOnly?: boolean; results?: Result["detail"] }) {
  return (
    <div className="space-y-3">
      {questions.map((q, qi) => {
        const r = results?.find((x) => x.id === q.id);
        return (
          <fieldset key={q.id} className="space-y-1.5 rounded-xl border border-line p-3 text-sm">
            <legend className="px-1 font-semibold">{qi + 1}. {q.statement} <span className="text-xs font-normal text-muted">({q.points} pt)</span></legend>
            {q.type === "short_answer" ? (
              <textarea className="input min-h-16" disabled={readOnly} aria-label={`Respuesta ${qi + 1}`} value={String(answers[q.id] ?? "")} onChange={(e) => onChange({ ...answers, [q.id]: e.target.value })} />
            ) : (
              q.options.map((o) => {
                const multi = q.type === "multiple_choice";
                const cur = answers[q.id];
                const checked = multi ? ((cur as string[]) ?? []).includes(o.key) : cur === o.key;
                return (
                  <label key={o.key} className="flex min-h-9 items-center gap-2 rounded-lg px-2 hover:bg-bg">
                    <input type={multi ? "checkbox" : "radio"} name={q.id} disabled={readOnly} checked={checked}
                      onChange={(e) => onChange({ ...answers, [q.id]: multi ? (e.target.checked ? [...((cur as string[]) ?? []), o.key] : ((cur as string[]) ?? []).filter((k) => k !== o.key)) : o.key })} />
                    {o.text}
                  </label>
                );
              })
            )}
            {r && r.ok !== null && <p className={`text-xs ${r.ok ? "text-ok" : "text-bad"}`}>{r.ok ? "Correcta" : "Incorrecta"}{r.feedback ? ` · ${r.feedback}` : ""}</p>}
          </fieldset>
        );
      })}
    </div>
  );
}

/** Rendir evaluación (SCR-AUL-23): preguntas sorteadas, temporizador, autoguardado, envío y resultado. */
function Exam({ enrollmentId, lesson, onDone }: { enrollmentId: string; lesson: Lesson; onDone: () => void }) {
  const [attempt, setAttempt] = useState<Attempt | null>(lesson.open_attempt ?? null);
  const [answers, setAnswers] = useState<Answers>(lesson.open_attempt?.answers ?? {});
  const [result, setResult] = useState<Result | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [now, setNow] = useState(() => Date.now());
  const saveTimer = useRef<number | null>(null);
  const sent = useRef(false);
  const left = (lesson.max_attempts ?? Infinity) - (lesson.attempts_used ?? 0);
  const remaining = attempt?.expires_at ? Math.floor((new Date(attempt.expires_at).getTime() - now) / 1000) : null;

  const submit = useCallback(async (withAnswers: boolean) => {
    if (!attempt || sent.current) return;
    sent.current = true;
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ data: Result }>(`/aul/enrollments/${enrollmentId}/lessons/${lesson.id}/exam`, { method: "POST", withCompany: true, body: withAnswers ? { attempt_id: attempt.id, answers } : { attempt_id: attempt.id } });
      setResult(r.data);
      onDone();
    } catch (e) {
      sent.current = false;
      setError(errText(e));
    } finally {
      setBusy(false);
    }
  }, [attempt, answers, enrollmentId, lesson.id, onDone]);

  useEffect(() => {
    if (!attempt?.expires_at || result) return;
    const t = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(t);
  }, [attempt, result]);
  useEffect(() => { if (remaining !== null && remaining <= 0 && !result) submit(false); }, [remaining, result, submit]);

  const change = (a: Answers) => {
    setAnswers(a);
    if (saveTimer.current) window.clearTimeout(saveTimer.current);
    saveTimer.current = window.setTimeout(() => {
      if (attempt) api(`/aul/enrollments/${enrollmentId}/attempts/${attempt.id}`, { method: "PUT", withCompany: true, body: { answers: a } }).catch(() => undefined);
    }, 1200);
  };
  const start = async () => {
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ data: Attempt }>(`/aul/enrollments/${enrollmentId}/lessons/${lesson.id}/attempts`, { method: "POST", withCompany: true });
      setAttempt(r.data);
      setAnswers(r.data.answers ?? {});
      setNow(Date.now());
      sent.current = false;
    } catch (e) {
      setError(errText(e));
    } finally {
      setBusy(false);
    }
  };

  if (result) {
    return (
      <div className="space-y-3">
        {result.pending ? (
          <p className="rounded-xl border border-line px-4 py-3 text-sm"><b>Enviado.</b> Tu evaluación tiene preguntas que califica el capacitador; verás la nota cuando la revise.</p>
        ) : (
          <div className={`rounded-xl border px-4 py-3 ${result.passed ? "border-ok/40 bg-ok-soft" : "border-bad/40 bg-bad-soft"}`}>
            <p className={`font-display text-lg font-semibold ${result.passed ? "text-ok" : "text-bad"}`}>{result.passed ? "¡Aprobaste!" : "No alcanzaste la nota mínima"}</p>
            <p className="text-sm">Nota: <b>{result.label ?? `${result.score_pct} %`}</b> ({result.score_pct} %, mínimo {result.passing} %)</p>
          </div>
        )}
        {attempt && result.detail.length > 0 && <QuestionList questions={attempt.questions} answers={answers} onChange={() => undefined} readOnly results={result.detail} />}
      </div>
    );
  }
  if (lesson.done) return <p className="text-sm text-ok">Evaluación aprobada{lesson.best_score !== null ? ` con ${lesson.best_score} %` : ""}.</p>;
  if (lesson.pending_review) return <p className="rounded-xl border border-line px-4 py-3 text-sm">Tu último intento está <b>por calificar</b>: el capacitador lo revisará pronto.</p>;
  if (!attempt) {
    return (
      <div className="space-y-3">
        <dl className="grid gap-2 rounded-xl border border-line p-4 text-sm sm:grid-cols-3">
          <div><dt className="text-xs text-muted">Preguntas</dt><dd className="font-semibold">{lesson.num_questions ?? "—"} al azar</dd></div>
          <div><dt className="text-xs text-muted">Intentos restantes</dt><dd className="font-semibold">{lesson.max_attempts ? `${Math.max(0, left)} de ${lesson.max_attempts}` : "Sin límite"}</dd></div>
          <div><dt className="text-xs text-muted">Tiempo límite</dt><dd className="font-semibold">{lesson.time_limit_minutes ? `${lesson.time_limit_minutes} min` : "Sin límite"}</dd></div>
        </dl>
        {lesson.last_result && !lesson.last_result.passed && (
          <p className="rounded-xl bg-amber-soft px-4 py-3 text-sm text-amber-ink">Último intento: {lesson.last_result.label ?? `${lesson.last_result.score_pct} %`}. No alcanzó la nota mínima.{lesson.last_result.feedback ? ` Comentario: ${lesson.last_result.feedback}` : ""}</p>
        )}
        {left > 0 ? <Button variant="primary" loading={busy} onClick={start}>Empezar evaluación</Button>
          : <p className="rounded-xl bg-bad-soft px-4 py-3 text-sm text-bad">Ya no te quedan intentos. Comunícate con tu capacitador.</p>}
        {error && <p className="text-sm text-bad">{error}</p>}
      </div>
    );
  }
  return (
    <div className="space-y-3">
      <div className="sticky top-0 z-10 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-line bg-surface px-4 py-2 text-sm">
        <span>Intento {attempt.attempt_number}{lesson.max_attempts ? ` de ${lesson.max_attempts}` : ""} · {attempt.questions.length} preguntas</span>
        {remaining !== null && <b className={`tabular-nums ${remaining < 60 ? "text-bad" : ""}`} aria-live="polite">Tiempo: {clock(remaining)}</b>}
      </div>
      <QuestionList questions={attempt.questions} answers={answers} onChange={change} />
      <p className="text-xs text-muted">Tus respuestas se guardan solas. Al agotarse el tiempo, el intento se envía con lo guardado.</p>
      <Button variant="primary" loading={busy} onClick={() => submit(true)}>Enviar respuestas</Button>
      {error && <p className="text-sm text-bad">{error}</p>}
    </div>
  );
}

function PreviewExam({ courseId, lesson }: { courseId: string; lesson: Lesson }) {
  const [qs, setQs] = useState<Question[] | null>(null);
  const [answers, setAnswers] = useState<Answers>({});
  const [error, setError] = useState<string | null>(null);
  const draw = () => api<{ data: { questions: Question[] } }>(`/aul/admin/courses/${courseId}/preview/lessons/${lesson.id}`, { withCompany: true })
    .then((r) => { setQs(r.data.questions); setAnswers({}); }).catch((e) => setError(errText(e)));
  return (
    <div className="space-y-3">
      <dl className="grid gap-2 rounded-xl border border-line p-4 text-sm sm:grid-cols-3">
        <div><dt className="text-xs text-muted">Preguntas</dt><dd className="font-semibold">{lesson.num_questions ?? "—"} al azar</dd></div>
        <div><dt className="text-xs text-muted">Intentos</dt><dd className="font-semibold">{lesson.max_attempts ?? "Sin límite"}</dd></div>
        <div><dt className="text-xs text-muted">Tiempo límite</dt><dd className="font-semibold">{lesson.time_limit_minutes ? `${lesson.time_limit_minutes} min` : "Sin límite"}</dd></div>
      </dl>
      <Button onClick={draw}>{qs ? "Sortear otra vez" : "Ver un sorteo de ejemplo"}</Button>
      {qs && <QuestionList questions={qs} answers={answers} onChange={setAnswers} />}
      {error && <p className="text-sm text-bad">{error}</p>}
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
