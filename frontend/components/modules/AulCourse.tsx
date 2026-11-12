"use client";

// El curso por dentro (M-AUL SCR-AUL-04..08; prototipo m-aula.js cursoPage): cabecera con indicadores y pestañas
// Contenido (secciones y lecciones: texto, video, documento, enlace y evaluación; orden), Participantes (matrícula por
// persona, puesto o área), Calificaciones (libro de notas e intentos), Evaluaciones (bancos, preguntas, intentos, nota)
// y Configuración (escala, nota mínima, secuencial, compromiso, certificado, capacitadores del curso). Desde la cabecera:
// «Ver como participante» (/m/aul/<id>/vista) y «Tiempo de permanencia» (/m/aul/<id>/permanencia).

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { records, uploadFile } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Chip, type ChipTone } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Bar, Kpi, fmtNum } from "./Summary";
import { RefSelect } from "./RefSelect";
import { errorText } from "./Wizard";
import { AulCrumbs, CATEGORIES, COURSE_STATUS, ENROLL_STATUS, PermEditor, bandOf, hm, type AulCan, type TrainerPerm } from "./AulAdmin";
import { EnrollmentAction, QuestionStats, useExamStats } from "./AulTools";

type Course = {
  id: string; code: string; title: string; summary: string | null; category: string | null; hours: string | number | null; status: string; sequential: boolean;
  passing_score: string | number | null; max_attempts: number | null; randomize_questions: boolean; randomize_options: boolean; completion_commitment: string; commitment_text: string | null;
  min_active_time_pct: string | number | null; certificate_enabled: boolean; certificate_validity_months: number | null; public_verification_enabled: boolean; grading_scale_id: string | null;
  scale_name: string | null; published_at: string | null;
};
type Section = { id: string; section_number: number; title: string; sort_order: number };
type Lesson = {
  id: string; section_id: string | null; title: string; lesson_type: string; content_html: string | null; video_external_id: string | null; external_url: string | null; file_id: string | null;
  duration_minutes: number | null; is_required: boolean; question_bank_id: string | null; bank_name: string | null; bank_questions: number; passing_score: string | number | null;
  max_attempts: number | null; time_limit_minutes: number | null;
  exam_config: { num_questions?: number; shuffle_questions?: boolean; shuffle_options?: boolean; show_feedback?: string; weight?: number; bank_ids?: string[];
    difficulty_mix?: { easy?: number; medium?: number; hard?: number }; manual_grading?: boolean } | null;
};
type Bank = { id: string; code: string; name: string; course_id: string | null; questions: number };
type Detail = { course: Course; kpis: { enrolled: number; avg_progress: number; passed: number; avg_active_seconds: number }; sections: Section[]; lessons: Lesson[]; banks: Bank[]; trainers: TrainerPerm[]; can: AulCan };
type Participant = {
  id: string; status: string; progress_pct: string | number; final_score: string | number | null; final_score_label: string | null; active_seconds_total: number; last_activity_at: string | null;
  due_on: string | null; access_until: string | null; commitment_accepted_at: string | null; name: string; position: string | null; area: string | null; attempts: number;
};
type Grades = {
  passing_score: number | null; exams: { id: string; title: string; max_attempts: number | null }[];
  rows: { enrollment_id: string; name: string; position: string | null; status: string; final_score: number | null; exams: Record<string, { attempts: number; best_pct: number | null; passed: boolean }> }[];
  attempts: { id: string; attempt_number: number; score: string | null; max_score: string | null; passed: boolean | null; submitted_at: string | null; lesson: string | null; name: string; status: string }[];
};

const TYPE: Record<string, [string, string]> = { text: ["file", "Texto"], video: ["play", "Video"], document: ["file", "Documento"], link: ["link", "Enlace"], exam: ["checklist", "Evaluación"] };
const TABS = ["Contenido", "Participantes", "Calificaciones", "Evaluaciones", "Configuración"] as const;
const n = (v: string | number | null | undefined) => (v == null || v === "" ? null : Number(v));
const fmtDate = (v: string | null) => (v ? new Date(v.length === 10 ? `${v}T12:00:00` : v).toLocaleDateString("es-PE", { day: "2-digit", month: "2-digit", year: "numeric" }) : "—");

function Note({ children, tone = "info" }: { children: React.ReactNode; tone?: "info" | "warn" }) {
  return <p className={`rounded-xl px-4 py-3 text-sm ${tone === "warn" ? "bg-amber-soft text-amber-ink" : "border border-line bg-surface text-muted"}`}>{children}</p>;
}

export function AulCourse({ id, readOnly }: { id: string; readOnly: boolean }) {
  const [d, setD] = useState<Detail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<(typeof TABS)[number]>("Contenido");
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const load = useCallback(() => {
    api<{ data: Detail }>(`/aul/admin/courses/${id}`, { withCompany: true }).then((r) => setD(r.data)).catch((e) => setError(errorText(e)));
  }, [id]);
  useEffect(load, [load]);

  if (error) return (
    <div className="space-y-3 rounded-xl border border-line bg-surface p-8">
      <h1 className="text-xl font-semibold">Curso no disponible</h1>
      <p className="text-muted">{error}</p>
      <Link href="/m/aul" className="inline-block rounded-lg border border-line px-4 py-2 hover:bg-bg">Volver a los cursos</Link>
    </div>
  );
  if (!d) return <div className="h-48 animate-pulse rounded-xl bg-surface" />;
  const c = d.course;
  const [st, tone] = COURSE_STATUS[c.status] ?? [c.status, "na" as ChipTone];
  const can = readOnly ? (Object.fromEntries(Object.keys(d.can).map((k) => [k, false])) as AulCan) : d.can;
  const publish = async (on: boolean) => {
    setBusy(true);
    setMsg(null);
    try {
      await api(`/aul/admin/courses/${id}/publish`, { method: "POST", withCompany: true, body: { publish: on } });
      setMsg({ ok: true, text: on ? "Curso publicado: ya se puede matricular." : "El curso volvió a borrador." });
      load();
    } catch (e) {
      setMsg({ ok: false, text: errorText(e) });
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-4">
      <AulCrumbs items={[{ label: c.title }]} />
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 space-y-2">
          <h1 className="font-display text-2xl font-bold leading-tight">{c.title}</h1>
          <div className="flex flex-wrap gap-1.5">
            <span className="rounded-full px-2.5 py-0.5 font-mono text-xs font-bold text-white" style={{ background: bandOf(c.code) }}>{c.code}</span>
            {c.category && <Chip tone="na">{c.category}</Chip>}
            <Chip tone="na">{c.hours != null ? `${fmtNum(n(c.hours), 1).replace(",0", "")} h` : "Sin horas"}</Chip>
            <Chip tone="na">{c.scale_name ?? "Porcentaje"} · mínimo {n(c.passing_score) ?? 70}{c.scale_name ? "" : " %"}</Chip>
            {d.trainers.length > 0 && <Chip tone="na">{d.trainers.map((t) => t.name).join(", ")}</Chip>}
            <Chip tone={tone}>{st}</Chip>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link href="/m/aul" className="inline-flex h-10 items-center rounded-lg border border-line bg-surface px-4 text-sm font-semibold hover:border-brand">‹ Cursos</Link>
          <Link href={`/m/aul/${id}/vista`} className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold hover:border-brand"><Icon name="eye" size={16} /> Ver como participante</Link>
          {d.can.time && <Link href={`/m/aul/${id}/permanencia`} className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold hover:border-brand"><Icon name="clock" size={16} /> Tiempo de permanencia</Link>}
          {can.publish && c.status === "draft" && <Button variant="primary" icon="check" loading={busy} onClick={() => publish(true)}>Publicar</Button>}
          {can.publish && c.status === "published" && d.kpis.enrolled === 0 && <Button loading={busy} onClick={() => publish(false)}>Volver a borrador</Button>}
        </div>
      </div>
      {msg && <p role="status" className={`rounded-lg px-4 py-2 text-sm ${msg.ok ? "bg-ok-soft text-ok" : "bg-bad-soft text-bad"}`}>{msg.text}</p>}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Kpi label="Matriculados" value={String(d.kpis.enrolled)} />
        <Kpi label="Avance promedio" value={`${d.kpis.avg_progress}%`} color="text-[#0f766e]" />
        <Kpi label="Aprobados" value={String(d.kpis.passed)} color="text-ok" sub={d.kpis.enrolled ? `${Math.round((d.kpis.passed * 100) / d.kpis.enrolled)}% de los matriculados` : "Sin matrículas"} />
        <Kpi label="Permanencia promedio" value={hm(d.kpis.avg_active_seconds)} color="text-[#b45309]" sub="Tiempo activo real" />
      </div>
      <div role="tablist" aria-label="Secciones del curso" className="flex gap-1 overflow-x-auto border-b border-line">
        {TABS.map((t) => (
          <button key={t} type="button" role="tab" aria-selected={tab === t} onClick={() => setTab(t)}
            className={`shrink-0 border-b-2 px-3 py-2 text-sm font-semibold ${tab === t ? "border-brand text-brand-strong" : "border-transparent text-muted hover:text-fg"}`}>
            {t === "Participantes" ? `Participantes (${d.kpis.enrolled})` : t}
          </button>
        ))}
      </div>
      {tab === "Contenido" && <ContentTab d={d} can={can} onChanged={load} goEval={() => setTab("Evaluaciones")} />}
      {tab === "Participantes" && <ParticipantsTab course={c} can={can} exams={d.lessons.filter((l) => l.lesson_type === "exam")} onChanged={load} />}
      {tab === "Calificaciones" && <GradesTab course={c} />}
      {tab === "Evaluaciones" && <ExamsTab d={d} can={can} onChanged={load} />}
      {tab === "Configuración" && <ConfigTab d={d} can={can} onChanged={load} />}
    </div>
  );
}

// ─────────────── Contenido (SCR-AUL-04) ───────────────

function ContentTab({ d, can, onChanged, goEval }: { d: Detail; can: AulCan; onChanged: () => void; goEval: () => void }) {
  const [edit, setEdit] = useState(false);
  const [adding, setAdding] = useState<string | null | undefined>(undefined); // sección donde se agrega (null = sin sección)
  const [renaming, setRenaming] = useState<Section | null>(null);
  const [removing, setRemoving] = useState<{ table: "course_lessons" | "course_sections"; id: string; title: string } | null>(null);
  const [newSection, setNewSection] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const c = d.course;
  const groups: { section: Section | null; lessons: Lesson[] }[] = [
    ...d.sections.map((s) => ({ section: s, lessons: d.lessons.filter((l) => l.section_id === s.id) })),
    ...(d.lessons.some((l) => !l.section_id || !d.sections.some((s) => s.id === l.section_id)) ? [{ section: null, lessons: d.lessons.filter((l) => !l.section_id || !d.sections.some((s) => s.id === l.section_id)) }] : []),
  ];

  const saveOrder = async (sections: Section[], lessons: { id: string; section_id: string | null }[]) => {
    setError(null);
    try {
      await api(`/aul/admin/courses/${c.id}/order`, { method: "PUT", withCompany: true, body: { sections: sections.map((s) => s.id), lessons } });
      onChanged();
    } catch (e) {
      setError(errorText(e));
    }
  };
  const flat = () => groups.flatMap((g) => g.lessons.map((l) => ({ id: l.id, section_id: g.section?.id ?? null })));
  const moveLesson = (gi: number, li: number, dir: -1 | 1) => {
    const gs = groups.map((g) => ({ ...g, lessons: [...g.lessons] }));
    const g = gs[gi];
    const l = g.lessons[li];
    if (li + dir >= 0 && li + dir < g.lessons.length) {
      [g.lessons[li], g.lessons[li + dir]] = [g.lessons[li + dir], g.lessons[li]];
    } else if (gs[gi + dir]) {
      g.lessons.splice(li, 1);   // Pasa a la sección vecina (al final si sube, al inicio si baja).
      if (dir === -1) gs[gi - 1].lessons.push(l); else gs[gi + 1].lessons.unshift(l);
    } else return;
    saveOrder(d.sections, gs.flatMap((x) => x.lessons.map((y) => ({ id: y.id, section_id: x.section?.id ?? null }))));
  };
  const moveSection = (si: number, dir: -1 | 1) => {
    const s = [...d.sections];
    if (!s[si + dir]) return;
    [s[si], s[si + dir]] = [s[si + dir], s[si]];
    saveOrder(s, flat());
  };
  const remove = async () => {
    if (!removing) return;
    try {
      await records.remove("AUL", removing.table, removing.id);
      setRemoving(null);
      onChanged();
    } catch (e) {
      setError(errorText(e));
      setRemoving(null);
    }
  };
  let step = 0;

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <p className="flex-1 basis-80 rounded-xl border border-line bg-surface px-4 py-3 text-sm text-muted">
          {c.sequential ? <><b className="text-fg">Recorrido secuencial:</b> el participante desbloquea los elementos en orden; la evaluación se abre al completar los obligatorios.</>
            : <><b className="text-fg">Recorrido libre:</b> el participante elige el orden.</>}
        </p>
        {can.edit_content && <Button variant={edit ? "primary" : "secondary"} icon="pen" aria-pressed={edit} onClick={() => setEdit(!edit)}>{edit ? "Terminar edición" : "Modo edición"}</Button>}
      </div>
      {error && <p className="rounded-lg bg-bad-soft px-4 py-2 text-sm text-bad">{error}</p>}
      {groups.length === 0 && <div className="rounded-xl border border-line bg-surface p-8 text-center text-sm text-muted"><b className="block text-fg">Este curso aún no tiene contenido</b>{can.edit_content ? "Activa el modo edición para agregar la primera sección." : ""}</div>}
      {groups.map((g, gi) => (
        <section key={g.section?.id ?? "none"} className="rounded-xl border border-line bg-surface">
          <div className="flex flex-wrap items-center gap-2 border-b border-line px-4 py-3">
            <span className="text-[11px] font-bold uppercase tracking-wide text-muted">{g.section ? `Sección ${gi + 1}` : "Sin sección"}</span>
            <h3 className="font-display font-semibold">{g.section?.title ?? "Lecciones sueltas"}</h3>
            <span className="ml-auto flex flex-wrap items-center gap-1">
              {edit && g.section ? (
                <>
                  <IconBtn icon="chevron" label="Subir sección" rotate disabled={gi === 0} onClick={() => moveSection(gi, -1)} />
                  <IconBtn icon="chevron" label="Bajar sección" disabled={gi >= d.sections.length - 1} onClick={() => moveSection(gi, 1)} />
                  <IconBtn icon="pen" label="Renombrar sección" onClick={() => setRenaming(g.section)} />
                  <IconBtn icon="trash" label="Eliminar sección" onClick={() => setRemoving({ table: "course_sections", id: g.section!.id, title: g.section!.title })} />
                  <Button size="sm" icon="plus" onClick={() => setAdding(g.section!.id)}>Agregar elemento</Button>
                </>
              ) : <span className="text-xs text-muted">{g.lessons.length} elemento(s)</span>}
            </span>
          </div>
          {g.lessons.length === 0 ? <p className="px-4 py-3 text-sm text-muted">Sección vacía.</p> : (
            <ul className="divide-y divide-line">
              {g.lessons.map((l, li) => {
                step++;
                const [ic, tl] = TYPE[l.lesson_type] ?? ["file", l.lesson_type];
                const meta = l.lesson_type === "video" ? `Vimeo ${l.video_external_id ?? ""}` : l.lesson_type === "link" ? (l.external_url ?? "") : l.lesson_type === "exam"
                  ? `${l.exam_config?.num_questions ?? 10} preguntas · ${l.max_attempts ?? c.max_attempts ?? "sin límite de"} intentos · ${l.bank_name ?? "sin banco"}` : l.lesson_type === "document" ? "Documento adjunto" : "Lectura";
                return (
                  <li key={l.id} className="flex flex-wrap items-center gap-3 px-4 py-2.5">
                    <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand-soft text-brand-strong"><Icon name={ic} size={17} /></span>
                    <span className="min-w-0 flex-1">
                      <b className="block truncate text-sm">{l.title}</b>
                      <span className="block truncate text-xs text-muted">{tl} · {meta}{l.duration_minutes ? ` · ${l.duration_minutes} min` : ""}</span>
                    </span>
                    {c.sequential && <span className="text-xs text-muted">Paso {step}</span>}
                    <Chip tone={l.is_required ? "brand" : "na"}>{l.is_required ? "Obligatorio" : "Opcional"}</Chip>
                    {l.lesson_type === "exam" && !edit && <Button size="sm" variant="ghost" onClick={goEval}>Configurar</Button>}
                    {l.lesson_type === "video" && l.video_external_id && !edit && (
                      <a className="text-sm font-semibold text-brand hover:underline" href={`https://vimeo.com/${l.video_external_id}`} target="_blank" rel="noreferrer">Ver</a>
                    )}
                    {edit && (
                      <span className="flex gap-1">
                        <IconBtn icon="chevron" label="Subir" rotate disabled={gi === 0 && li === 0} onClick={() => moveLesson(gi, li, -1)} />
                        <IconBtn icon="chevron" label="Bajar" disabled={gi === groups.length - 1 && li === g.lessons.length - 1} onClick={() => moveLesson(gi, li, 1)} />
                        <IconBtn icon="trash" label="Eliminar" onClick={() => setRemoving({ table: "course_lessons", id: l.id, title: l.title })} />
                      </span>
                    )}
                  </li>
                );
              })}
            </ul>
          )}
        </section>
      ))}
      {edit && can.sections && <Button variant="primary" icon="plus" onClick={() => setNewSection(true)}>Agregar sección</Button>}
      {adding !== undefined && <AddLesson course={d} sectionId={adding} onClose={() => setAdding(undefined)} onSaved={() => { setAdding(undefined); onChanged(); }} />}
      {(renaming || newSection) && (
        <SectionName section={renaming} courseId={c.id} onClose={() => { setRenaming(null); setNewSection(false); }} onSaved={() => { setRenaming(null); setNewSection(false); onChanged(); }} />
      )}
      {removing && (
        <Modal title="Eliminar" icon="trash" size="sm" onClose={() => setRemoving(null)}
          footer={<div className="flex w-full justify-end gap-2"><Button onClick={() => setRemoving(null)}>Cancelar</Button><Button variant="danger" onClick={remove}>Eliminar</Button></div>}>
          <p className="text-sm">¿Eliminar <b>{removing.title}</b>? El avance ya registrado de los participantes se conserva.</p>
        </Modal>
      )}
    </div>
  );
}

function IconBtn({ icon, label, onClick, disabled, rotate }: { icon: string; label: string; onClick: () => void; disabled?: boolean; rotate?: boolean }) {
  return (
    <button type="button" aria-label={label} title={label} disabled={disabled} onClick={onClick}
      className="grid h-8 w-8 place-items-center rounded-lg border border-line text-muted hover:border-brand hover:text-brand disabled:opacity-40">
      <Icon name={icon} size={15} className={icon === "chevron" ? (rotate ? "-rotate-90" : "rotate-90") : ""} />
    </button>
  );
}

function SectionName({ section, courseId, onClose, onSaved }: { section: Section | null; courseId: string; onClose: () => void; onSaved: () => void }) {
  const [title, setTitle] = useState(section?.title ?? "");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const save = async () => {
    setBusy(true);
    try {
      if (section) await records.update("AUL", "course_sections", section.id, { title });
      else await api(`/aul/admin/courses/${courseId}/sections`, { method: "POST", withCompany: true, body: { title } });
      onSaved();
    } catch (e) {
      setError(errorText(e));
      setBusy(false);
    }
  };
  return (
    <Modal title={section ? "Renombrar sección" : "Nueva sección"} icon="pen" size="sm" onClose={onClose}
      footer={<div className="flex w-full justify-end gap-2"><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} onClick={save}>Guardar</Button></div>}>
      <FormField label="Nombre de la sección *" error={error ?? undefined}><input className="input" autoFocus value={title} onChange={(e) => setTitle(e.target.value)} /></FormField>
    </Modal>
  );
}

function AddLesson({ course, sectionId, onClose, onSaved }: { course: Detail; sectionId: string | null; onClose: () => void; onSaved: () => void }) {
  const [type, setType] = useState("video");
  const [f, setF] = useState({ title: "", content_html: "", video_url: "", external_url: "https://", file_id: "", file_name: "", duration_minutes: "", is_required: true, question_bank_id: "", num_questions: "10", passing_score: "", max_attempts: "", time_limit_minutes: "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const set = (p: Partial<typeof f>) => setF((x) => ({ ...x, ...p }));
  const sec = course.sections.find((s) => s.id === sectionId);
  const upload = async (file: File | undefined) => {
    if (!file) return;
    setBusy(true);
    try {
      const r = await uploadFile(file, "AUL");
      set({ file_id: r.id, file_name: r.original_name });
    } catch (e) {
      setErrors({ file_id: [errorText(e)] });
    } finally {
      setBusy(false);
    }
  };
  const save = async () => {
    setBusy(true);
    setErrors({});
    const nn = (v: string) => (v.trim() ? Number(v) : null);
    try {
      await api(`/aul/admin/courses/${course.course.id}/lessons`, {
        method: "POST", withCompany: true,
        body: {
          section_id: sectionId, lesson_type: type, title: f.title, is_required: f.is_required, duration_minutes: nn(f.duration_minutes),
          content_html: type === "text" ? f.content_html.split(/\n{2,}/).map((p) => `<p>${p.replace(/[<>&]/g, (ch) => ({ "<": "&lt;", ">": "&gt;", "&": "&amp;" })[ch]!).replace(/\n/g, "<br>")}</p>`).join("") : null,
          video_url: type === "video" ? f.video_url : null, external_url: type === "link" ? f.external_url : null, file_id: type === "document" ? f.file_id || null : null,
          question_bank_id: type === "exam" ? f.question_bank_id || null : null, num_questions: type === "exam" ? nn(f.num_questions) : null,
          passing_score: type === "exam" ? nn(f.passing_score) : null, max_attempts: type === "exam" ? nn(f.max_attempts) : null, time_limit_minutes: type === "exam" ? nn(f.time_limit_minutes) : null,
        },
      });
      onSaved();
    } catch (e) {
      setErrors((e as { errors?: Record<string, string[]> }).errors ?? { title: [errorText(e)] });
      setBusy(false);
    }
  };
  const err = (k: string) => errors[k]?.[0];
  return (
    <Modal title={`Agregar elemento${sec ? ` a «${sec.title}»` : ""}`} icon="plus" size="lg" onClose={onClose}
      footer={<div className="flex w-full justify-end gap-2"><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} onClick={save}>Agregar</Button></div>}>
      <div className="space-y-3">
        <div role="radiogroup" aria-label="Tipo de elemento" className="flex flex-wrap gap-1.5">
          {Object.entries(TYPE).map(([k, [ic, l]]) => (
            <button key={k} type="button" role="radio" aria-checked={type === k} onClick={() => setType(k)}
              className={`inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-semibold ${type === k ? "border-brand bg-brand text-white" : "border-line hover:border-brand"}`}>
              <Icon name={ic} size={15} /> {l}
            </button>
          ))}
        </div>
        <FormField label="Título *" error={err("title")}><input className="input" value={f.title} onChange={(e) => set({ title: e.target.value })} /></FormField>
        {type === "text" && <FormField label="Contenido *" hint="Deja una línea en blanco entre párrafos." error={err("content_html")}><textarea className="input min-h-40" value={f.content_html} onChange={(e) => set({ content_html: e.target.value })} /></FormField>}
        {type === "video" && <FormField label="Enlace del video en Vimeo *" hint="El video queda en la cuenta de Vimeo de la empresa; aquí se guarda solo su número." error={err("video_url")}><input className="input" value={f.video_url} onChange={(e) => set({ video_url: e.target.value })} placeholder="https://vimeo.com/123456789" /></FormField>}
        {type === "link" && <FormField label="Dirección (URL) *" error={err("external_url")}><input className="input" value={f.external_url} onChange={(e) => set({ external_url: e.target.value })} /></FormField>}
        {type === "document" && (
          <FormField label="Documento *" hint="PDF, Word o PowerPoint." error={err("file_id")}>
            <input className="input" type="file" accept=".pdf,.doc,.docx,.ppt,.pptx" onChange={(e) => upload(e.target.files?.[0])} />
            {f.file_name && <span className="mt-1 block text-xs text-ok">Adjuntado: {f.file_name}</span>}
          </FormField>
        )}
        {type === "exam" && (
          <div className="grid gap-3 sm:grid-cols-2">
            <FormField label="Banco de preguntas" hint="Sin elegir, se usa el banco del curso (se crea solo)." error={err("question_bank_id")}>
              <select className="input" value={f.question_bank_id} onChange={(e) => set({ question_bank_id: e.target.value })}>
                <option value="">Banco del curso</option>
                {course.banks.map((b) => <option key={b.id} value={b.id}>{b.code} · {b.name} ({b.questions})</option>)}
              </select>
            </FormField>
            <FormField label="Preguntas al azar"><input className="input" inputMode="numeric" value={f.num_questions} onChange={(e) => set({ num_questions: e.target.value })} /></FormField>
            <FormField label="Nota mínima (%)" hint="Vacío: la del curso."><input className="input" inputMode="decimal" value={f.passing_score} onChange={(e) => set({ passing_score: e.target.value })} /></FormField>
            <FormField label="Intentos" hint="Vacío: los del curso."><input className="input" inputMode="numeric" value={f.max_attempts} onChange={(e) => set({ max_attempts: e.target.value })} /></FormField>
            <FormField label="Tiempo límite (min)" hint="Vacío: sin límite."><input className="input" inputMode="numeric" value={f.time_limit_minutes} onChange={(e) => set({ time_limit_minutes: e.target.value })} /></FormField>
          </div>
        )}
        {type !== "exam" && <FormField label="Duración estimada (min)"><input className="input" inputMode="numeric" value={f.duration_minutes} onChange={(e) => set({ duration_minutes: e.target.value })} /></FormField>}
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.is_required} onChange={(e) => set({ is_required: e.target.checked })} /> Obligatorio para avanzar</label>
      </div>
    </Modal>
  );
}

// ─────────────── Participantes (SCR-AUL-05) ───────────────

function ParticipantsTab({ course, can, exams, onChanged }: { course: Course; can: AulCan; exams: Lesson[]; onChanged: () => void }) {
  const [list, setList] = useState<Participant[] | null>(null);
  const [action, setAction] = useState<{ kind: "extend" | "reset"; p: Participant } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [mode, setMode] = useState<"people" | "group" | null>(null);
  const [q, setQ] = useState("");
  const load = useCallback(() => {
    api<{ data: Participant[] }>(`/aul/admin/courses/${course.id}/participants`, { withCompany: true }).then((r) => setList(r.data)).catch((e) => setError(errorText(e)));
  }, [course.id]);
  useEffect(load, [load]);
  const done = () => { setMode(null); load(); onChanged(); };
  const drop = (p: Participant) => records.update("AUL", "course_enrollments", p.id, { status: "cancelled" }).then(done).catch((e) => setError(errorText(e)));
  const shown = (list ?? []).filter((p) => !q.trim() || `${p.name} ${p.position ?? ""} ${p.area ?? ""}`.toLowerCase().includes(q.trim().toLowerCase()));

  return (
    <div className="space-y-3">
      {!can.enroll && <Note>La matrícula la hace el administrador del aula.</Note>}
      {can.enroll && course.status !== "published" && <Note tone="warn">Publica el curso para matricular participantes.</Note>}
      <div className="flex flex-wrap items-center justify-between gap-2">
        <input className="input max-w-xs" type="search" placeholder="Buscar participante" aria-label="Buscar participante" value={q} onChange={(e) => setQ(e.target.value)} />
        {can.enroll && course.status === "published" && (
          <div className="flex flex-wrap gap-2">
            <Button variant="primary" icon="plus" onClick={() => setMode("people")}>Matricular participantes</Button>
            <Button icon="people" onClick={() => setMode("group")}>Matricular por puesto o área</Button>
          </div>
        )}
      </div>
      {error && <p className="rounded-lg bg-bad-soft px-4 py-2 text-sm text-bad">{error}</p>}
      {!list ? <div className="h-24 animate-pulse rounded-xl bg-surface" /> : shown.length === 0 ? (
        <div className="rounded-xl border border-line bg-surface p-8 text-center text-sm text-muted">{list.length ? "Nadie coincide con la búsqueda." : "Aún no hay matriculados."}</div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-line bg-surface">
          <table className="w-full min-w-[860px] text-sm">
            <thead className="bg-bg text-left text-xs text-muted">
              <tr>{["Participante", "Avance", "Nota", "Permanencia", "Compromiso", "Estado", "Último acceso", "Acceso hasta", ""].map((h) => <th key={h} className="px-3 py-2 font-semibold">{h}</th>)}</tr>
            </thead>
            <tbody className="divide-y divide-line">
              {shown.map((p) => {
                const [st, tone] = ENROLL_STATUS[p.status] ?? [p.status, "na" as ChipTone];
                return (
                  <tr key={p.id}>
                    <td className="px-3 py-2"><b className="block">{p.name}</b><span className="text-xs text-muted">{[p.position, p.area].filter(Boolean).join(" · ") || "—"}</span></td>
                    <td className="px-3 py-2"><div className="w-28 space-y-1"><Bar pct={Number(p.progress_pct)} /><span className="text-xs tabular-nums">{Math.round(Number(p.progress_pct))}%</span></div></td>
                    <td className="px-3 py-2 tabular-nums">{p.final_score_label ?? (p.final_score != null ? fmtNum(Number(p.final_score), 1) : "—")}</td>
                    <td className="px-3 py-2 tabular-nums">{hm(p.active_seconds_total)}</td>
                    <td className="px-3 py-2">{course.completion_commitment === "none" ? "—" : p.commitment_accepted_at ? <Chip tone="ok">Aceptado</Chip> : <Chip tone="amber">Pendiente</Chip>}</td>
                    <td className="px-3 py-2"><Chip tone={tone}>{st}</Chip></td>
                    <td className="px-3 py-2 text-xs">{fmtDate(p.last_activity_at)}</td>
                    <td className="px-3 py-2 text-xs">{fmtDate(p.access_until)}</td>
                    <td className="px-3 py-2 text-right">
                      <span className="flex flex-col items-end gap-1">
                        {can.extend && !["completed", "cancelled"].includes(p.status) && <button type="button" className="text-xs font-semibold text-brand hover:underline" onClick={() => setAction({ kind: "extend", p })}>Extender acceso</button>}
                        {can.reset && p.attempts > 0 && p.status !== "completed" && exams.length > 0 && <button type="button" className="text-xs font-semibold text-brand hover:underline" onClick={() => setAction({ kind: "reset", p })}>Reiniciar intentos</button>}
                        {can.enroll && !["completed", "cancelled"].includes(p.status) && <button type="button" className="text-xs font-semibold text-bad hover:underline" onClick={() => drop(p)}>Dar de baja</button>}
                      </span>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
      {mode && <EnrollModal course={course} mode={mode} onClose={() => setMode(null)} onDone={done} />}
      {action && <EnrollmentAction kind={action.kind} enrollment={action.p} exams={exams} onClose={() => setAction(null)} onDone={() => { setAction(null); load(); onChanged(); }} />}
    </div>
  );
}

function EnrollModal({ course, mode, onClose, onDone }: { course: Course; mode: "people" | "group"; onClose: () => void; onDone: () => void }) {
  const [q, setQ] = useState("");
  const [opts, setOpts] = useState<{ id: string; label: string }[] | null>(null);
  const [picked, setPicked] = useState<Record<string, string>>({});
  const [group, setGroup] = useState<{ position_id: string; area_id: string }>({ position_id: "", area_id: "" });
  const [preview, setPreview] = useState<{ matched: number; already: number; to_enroll: number } | null>(null);
  const [due, setDue] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (mode !== "people") return;
    const t = setTimeout(() => records.options("AUL", "course_enrollments", "worker_id", { q: q.trim() || undefined }).then((r) => setOpts(r.data)).catch(() => setOpts([])), q ? 250 : 0);
    return () => clearTimeout(t);
  }, [q, mode]);
  const body = (dry: boolean) => ({
    ...(mode === "people" ? { worker_ids: Object.keys(picked) } : { position_id: group.position_id || null, area_id: group.area_id || null }), due_on: due || null, dry_run: dry,
  });
  useEffect(() => {
    if (mode !== "group" || (!group.position_id && !group.area_id)) { setPreview(null); return; }
    api<{ data: { matched: number; already: number; to_enroll: number } }>(`/aul/admin/courses/${course.id}/enroll`, { method: "POST", withCompany: true, body: { ...body(true) } })
      .then((r) => setPreview(r.data)).catch((e) => setError(errorText(e)));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [group, mode, course.id]);
  const go = async () => {
    setBusy(true);
    setError(null);
    try {
      await api(`/aul/admin/courses/${course.id}/enroll`, { method: "POST", withCompany: true, body: body(false) });
      onDone();
    } catch (e) {
      setError(errorText(e));
      setBusy(false);
    }
  };
  const count = mode === "people" ? Object.keys(picked).length : preview?.to_enroll ?? 0;
  return (
    <Modal title={mode === "people" ? `Matricular en «${course.title}»` : "Matricular por puesto o área"} icon="people" size="md" onClose={onClose}
      subtitle={mode === "group" ? "Matricula a todos los trabajadores activos del puesto o área. Útil para cumplir la matriz de capacitación." : undefined}
      footer={<div className="flex w-full justify-end gap-2"><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} disabled={count === 0} onClick={go}>Matricular{count ? ` (${count})` : ""}</Button></div>}>
      <div className="space-y-3">
        {mode === "people" ? (
          <>
            <input className="input" type="search" placeholder="Buscar por nombre o documento" aria-label="Buscar trabajador" value={q} onChange={(e) => setQ(e.target.value)} />
            <ul className="max-h-72 divide-y divide-line overflow-y-auto rounded-xl border border-line">
              {opts === null && <li className="px-3 py-2 text-sm text-muted">Cargando…</li>}
              {opts?.length === 0 && <li className="px-3 py-2 text-sm text-muted">No hay trabajadores con ese nombre.</li>}
              {opts?.map((o) => (
                <li key={o.id}>
                  <label className="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm">
                    <input type="checkbox" checked={!!picked[o.id]} onChange={(e) => setPicked((x) => { const y = { ...x }; if (e.target.checked) y[o.id] = o.label; else delete y[o.id]; return y; })} />
                    {o.label}
                  </label>
                </li>
              ))}
            </ul>
            <p className="text-xs text-muted">Quien ya está matriculado no se duplica.</p>
          </>
        ) : (
          <>
            <FormField label="Puesto"><RefSelect module="AUL" table="course_recommendations" field="target_position_id" label="Puesto" value={group.position_id} onChange={(v) => setGroup((g) => ({ ...g, position_id: v }))} placeholder="Cualquier puesto" /></FormField>
            <FormField label="Área"><RefSelect module="AUL" table="course_recommendations" field="target_area_id" label="Área" value={group.area_id} onChange={(v) => setGroup((g) => ({ ...g, area_id: v }))} placeholder="Cualquier área" /></FormField>
            {preview && (
              <div className="grid grid-cols-3 gap-2 text-center text-sm">
                <div className="rounded-lg border border-line p-2"><b className="block text-xl">{preview.matched}</b>Con el filtro</div>
                <div className="rounded-lg border border-line p-2"><b className="block text-xl">{preview.already}</b>Ya matriculados</div>
                <div className="rounded-lg border border-line p-2"><b className="block text-xl text-brand">{preview.to_enroll}</b>Se matricularán</div>
              </div>
            )}
          </>
        )}
        <FormField label="Fecha límite (opcional)"><input className="input" type="date" value={due} onChange={(e) => setDue(e.target.value)} /></FormField>
        {error && <p className="text-sm text-bad">{error}</p>}
      </div>
    </Modal>
  );
}

// ─────────────── Calificaciones (SCR-AUL-06) ───────────────

function GradesTab({ course }: { course: Course }) {
  const [g, setG] = useState<Grades | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: Grades }>(`/aul/admin/courses/${course.id}/grades`, { withCompany: true }).then((r) => setG(r.data)).catch((e) => setError(errorText(e)));
  }, [course.id]);
  if (error) return <p className="rounded-lg bg-bad-soft px-4 py-2 text-sm text-bad">{error}</p>;
  if (!g) return <div className="h-24 animate-pulse rounded-xl bg-surface" />;
  const min = g.passing_score ?? 70;
  return (
    <div className="space-y-3">
      <Note>Escala del curso: <b className="text-fg">{course.scale_name ?? "Porcentaje (0 a 100)"}</b> · nota mínima para aprobar: <b className="text-fg">{min}%</b>. Se cambia en «Configuración».</Note>
      {g.rows.length === 0 ? <div className="rounded-xl border border-line bg-surface p-8 text-center text-sm text-muted">Aún no hay matriculados.</div> : (
        <div className="overflow-x-auto rounded-xl border border-line bg-surface">
          <table className="w-full min-w-[640px] text-sm">
            <thead className="bg-bg text-left text-xs text-muted">
              <tr>
                <th className="px-3 py-2 font-semibold">Participante</th>
                {g.exams.map((e) => <th key={e.id} className="px-3 py-2 font-semibold">{e.title}</th>)}
                <th className="px-3 py-2 font-semibold">Nota final</th><th className="px-3 py-2 font-semibold">Estado</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-line">
              {g.rows.map((r) => {
                const [st, tone] = ENROLL_STATUS[r.status] ?? [r.status, "na" as ChipTone];
                return (
                  <tr key={r.enrollment_id}>
                    <td className="px-3 py-2"><b className="block">{r.name}</b><span className="text-xs text-muted">{r.position ?? ""}</span></td>
                    {g.exams.map((e) => {
                      const x = r.exams[e.id];
                      return (
                        <td key={e.id} className="px-3 py-2 tabular-nums">
                          {x ? <><b className={x.passed ? "text-ok" : x.best_pct != null && x.best_pct < min ? "text-bad" : ""}>{x.best_pct != null ? `${fmtNum(x.best_pct, 0)}%` : "—"}</b><span className="block text-xs text-muted">{x.attempts} de {e.max_attempts ?? "∞"} intentos</span></> : <span className="text-muted">—</span>}
                        </td>
                      );
                    })}
                    <td className="px-3 py-2 font-semibold tabular-nums">{r.final_score != null ? `${fmtNum(r.final_score, 1)}%` : "—"}</td>
                    <td className="px-3 py-2"><Chip tone={tone}>{st}</Chip></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
      {g.attempts.length > 0 && (
        <section className="rounded-xl border border-line bg-surface p-4">
          <h3 className="mb-2 font-display font-semibold">Últimos intentos</h3>
          <ul className="divide-y divide-line text-sm">
            {g.attempts.slice(0, 20).map((a) => (
              <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-1.5">
                <span><b>{a.name}</b> · {a.lesson ?? "Evaluación"} · intento {a.attempt_number}</span>
                <span className="flex items-center gap-2">
                  <span className="tabular-nums">{a.score != null && a.max_score ? `${Math.round((Number(a.score) * 100) / Number(a.max_score))}%` : "—"}</span>
                  {a.status === "submitted" ? <Link href="/m/aul/calificar" className="hover:underline"><Chip tone="amber">Por calificar</Chip></Link> : <Chip tone={a.passed ? "ok" : "bad"}>{a.passed ? "Aprobado" : "Desaprobado"}</Chip>}
                  <span className="text-xs text-muted">{fmtDate(a.submitted_at)}</span>
                </span>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}

// ─────────────── Evaluaciones (SCR-AUL-07) ───────────────

function ExamsTab({ d, can, onChanged }: { d: Detail; can: AulCan; onChanged: () => void }) {
  const exams = d.lessons.filter((l) => l.lesson_type === "exam");
  const stats = useExamStats(d.course.id);
  if (exams.length === 0) return <div className="rounded-xl border border-line bg-surface p-8 text-center text-sm text-muted"><b className="block text-fg">Este curso no tiene evaluaciones</b>Agrégalas en «Contenido» con el tipo «Evaluación».</div>;
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><Link href="/m/aul/banco" className="text-sm font-semibold text-brand hover:underline">Abrir el banco de preguntas ›</Link></div>
      {exams.map((l) => (
        <div key={l.id} className="space-y-2">
          <ExamCard lesson={l} banks={d.banks} canEdit={can.edit_content} onSaved={onChanged} />
          <details className="rounded-xl border border-line bg-surface">
            <summary className="cursor-pointer px-4 py-3 text-sm font-semibold">Estadística por pregunta · {l.title}</summary>
            <div className="border-t border-line p-4">{stats === null ? <div className="h-16 animate-pulse rounded-xl bg-bg" /> : <QuestionStats stats={stats.find((x) => x.lesson_id === l.id)} />}</div>
          </details>
        </div>
      ))}
    </div>
  );
}

function ExamCard({ lesson, banks, canEdit, onSaved }: { lesson: Lesson; banks: Bank[]; canEdit: boolean; onSaved: () => void }) {
  const cfg = lesson.exam_config ?? {};
  const [f, setF] = useState({
    question_bank_id: lesson.question_bank_id ?? "", num_questions: String(cfg.num_questions ?? 10), shuffle_questions: cfg.shuffle_questions ?? true, shuffle_options: cfg.shuffle_options ?? true,
    show_feedback: cfg.show_feedback ?? "on_finish", weight: String(cfg.weight ?? 1), passing_score: lesson.passing_score != null ? String(lesson.passing_score) : "",
    max_attempts: lesson.max_attempts != null ? String(lesson.max_attempts) : "", time_limit_minutes: lesson.time_limit_minutes != null ? String(lesson.time_limit_minutes) : "",
    mix_easy: String(cfg.difficulty_mix?.easy ?? ""), mix_medium: String(cfg.difficulty_mix?.medium ?? ""), mix_hard: String(cfg.difficulty_mix?.hard ?? ""),
    manual_grading: cfg.manual_grading ?? false, bank_ids: cfg.bank_ids ?? [],
  });
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (p: Partial<typeof f>) => setF((x) => ({ ...x, ...p }));
  const bank = banks.find((b) => b.id === f.question_bank_id);
  const need = Number(f.num_questions) || 0;
  const pool = banks.filter((b) => b.id === f.question_bank_id || f.bank_ids.includes(b.id)).reduce((a, b) => a + b.questions, 0);
  const save = async () => {
    setBusy(true);
    setMsg(null);
    const nn = (v: string) => (v.trim() ? Number(v) : null);
    try {
      await api(`/aul/admin/lessons/${lesson.id}/exam`, {
        method: "PUT", withCompany: true,
        body: {
          question_bank_id: f.question_bank_id || null, bank_ids: f.bank_ids.filter((b) => b !== f.question_bank_id), passing_score: nn(f.passing_score), max_attempts: nn(f.max_attempts),
          time_limit_minutes: nn(f.time_limit_minutes), num_questions: nn(f.num_questions) ?? 10, shuffle_questions: f.shuffle_questions, shuffle_options: f.shuffle_options,
          show_feedback: f.show_feedback, weight: nn(f.weight) ?? 1, manual_grading: f.manual_grading,
          difficulty_mix: { easy: nn(f.mix_easy) ?? 0, medium: nn(f.mix_medium) ?? 0, hard: nn(f.mix_hard) ?? 0 },
        },
      });
      setMsg({ ok: true, text: "Evaluación guardada." });
      onSaved();
    } catch (e) {
      setMsg({ ok: false, text: errorText(e) });
    } finally {
      setBusy(false);
    }
  };
  return (
    <section className="space-y-3 rounded-xl border border-line bg-surface p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="font-display font-semibold">{lesson.title}</h3>
        <Chip tone={bank && pool >= need ? "ok" : "amber"}>{bank ? `${pool} preguntas en ${f.bank_ids.length ? "los bancos" : "el banco"}` : "Sin banco"}</Chip>
      </div>
      {bank && pool < need && <Note tone="warn">Los bancos tienen {pool} pregunta(s) y la evaluación pide {need}: agrega preguntas antes de publicar.</Note>}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <FormField label="Banco de origen">
          <select className="input" disabled={!canEdit} value={f.question_bank_id} onChange={(e) => set({ question_bank_id: e.target.value })}>
            <option value="">Elige un banco</option>
            {banks.map((b) => <option key={b.id} value={b.id}>{b.code} · {b.name}{b.course_id ? "" : " (general)"}</option>)}
          </select>
        </FormField>
        <FormField label="Preguntas al azar"><input className="input" disabled={!canEdit} inputMode="numeric" value={f.num_questions} onChange={(e) => set({ num_questions: e.target.value })} /></FormField>
        <FormField label="Intentos" hint="Vacío: los del curso."><input className="input" disabled={!canEdit} inputMode="numeric" value={f.max_attempts} onChange={(e) => set({ max_attempts: e.target.value })} /></FormField>
        <FormField label="Tiempo límite (min)" hint="Vacío: sin límite."><input className="input" disabled={!canEdit} inputMode="numeric" value={f.time_limit_minutes} onChange={(e) => set({ time_limit_minutes: e.target.value })} /></FormField>
        <FormField label="Nota mínima (%)" hint="Vacío: la del curso."><input className="input" disabled={!canEdit} inputMode="decimal" value={f.passing_score} onChange={(e) => set({ passing_score: e.target.value })} /></FormField>
        <FormField label="Peso en la nota final"><input className="input" disabled={!canEdit} inputMode="decimal" value={f.weight} onChange={(e) => set({ weight: e.target.value })} /></FormField>
        <FormField label="Retroalimentación">
          <select className="input" disabled={!canEdit} value={f.show_feedback} onChange={(e) => set({ show_feedback: e.target.value })}>
            <option value="on_finish">Al terminar</option><option value="per_question">En cada pregunta</option><option value="never">No mostrar</option>
          </select>
        </FormField>
        <div className="space-y-1.5 pt-6 text-sm">
          <label className="flex items-center gap-2"><input type="checkbox" disabled={!canEdit} checked={f.shuffle_questions} onChange={(e) => set({ shuffle_questions: e.target.checked })} /> Preguntas al azar</label>
          <label className="flex items-center gap-2"><input type="checkbox" disabled={!canEdit} checked={f.shuffle_options} onChange={(e) => set({ shuffle_options: e.target.checked })} /> Mezclar opciones</label>
          <label className="flex items-center gap-2"><input type="checkbox" disabled={!canEdit} checked={f.manual_grading} onChange={(e) => set({ manual_grading: e.target.checked })} /> Evaluación práctica (la califica el capacitador)</label>
        </div>
      </div>
      <div className="grid gap-3 sm:grid-cols-2">
        <fieldset className="space-y-1.5">
          <legend className="text-sm font-semibold">Mezcla por dificultad (opcional)</legend>
          <div className="grid grid-cols-3 gap-2">
            {([["mix_easy", "Baja"], ["mix_medium", "Media"], ["mix_hard", "Alta"]] as const).map(([k, l]) => (
              <label key={k} className="text-xs text-muted">{l}<input className="input mt-1" disabled={!canEdit} inputMode="numeric" value={f[k]} onChange={(e) => set({ [k]: e.target.value } as Partial<typeof f>)} /></label>
            ))}
          </div>
          <p className="text-xs text-muted">Cuántas preguntas de cada dificultad entran en el sorteo; el resto se completa al azar.</p>
        </fieldset>
        <fieldset className="space-y-1.5">
          <legend className="text-sm font-semibold">Otros bancos de origen</legend>
          <div className="max-h-28 space-y-1 overflow-y-auto rounded-lg border border-line p-2 text-sm">
            {banks.filter((b) => b.id !== f.question_bank_id).map((b) => (
              <label key={b.id} className="flex items-center gap-2">
                <input type="checkbox" disabled={!canEdit} checked={f.bank_ids.includes(b.id)} onChange={(e) => set({ bank_ids: e.target.checked ? [...f.bank_ids, b.id] : f.bank_ids.filter((x) => x !== b.id) })} />
                {b.code} · {b.name} ({b.questions})
              </label>
            ))}
            {banks.length <= 1 && <span className="text-xs text-muted">No hay otros bancos.</span>}
          </div>
        </fieldset>
      </div>
      {canEdit && (
        <div className="flex items-center gap-3">
          <Button variant="primary" loading={busy} onClick={save}>Guardar evaluación</Button>
          {msg && <span className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</span>}
        </div>
      )}
    </section>
  );
}

// ─────────────── Configuración (SCR-AUL-08) ───────────────

function ConfigTab({ d, can, onChanged }: { d: Detail; can: AulCan; onChanged: () => void }) {
  const c = d.course;
  const locked = c.status === "published" && d.kpis.enrolled > 0;   // RN-AUL-17: escala y nota mínima no cambian con matriculados.
  const [f, setF] = useState({
    title: c.title, summary: c.summary ?? "", category: c.category ?? "", hours: c.hours != null ? String(Number(c.hours)) : "", grading_scale_id: c.grading_scale_id ?? "",
    passing_score: c.passing_score != null ? String(Number(c.passing_score)) : "", max_attempts: c.max_attempts != null ? String(c.max_attempts) : "", sequential: c.sequential,
    randomize_questions: c.randomize_questions, randomize_options: c.randomize_options, completion_commitment: c.completion_commitment, commitment_text: c.commitment_text ?? "",
    min_active_time_pct: c.min_active_time_pct != null ? String(Number(c.min_active_time_pct)) : "", certificate_enabled: c.certificate_enabled,
    certificate_validity_months: c.certificate_validity_months != null ? String(c.certificate_validity_months) : "", public_verification_enabled: c.public_verification_enabled,
  });
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [trainer, setTrainer] = useState("");
  const set = (p: Partial<typeof f>) => setF((x) => ({ ...x, ...p }));
  const dis = !can.update_course;
  const save = async () => {
    setBusy(true);
    setMsg(null);
    const nn = (v: string) => (v.trim() ? Number(v.replace(",", ".")) : null);
    try {
      await records.update("AUL", "courses", c.id, {
        title: f.title, summary: f.summary || null, category: f.category || null, hours: nn(f.hours), max_attempts: nn(f.max_attempts), sequential: f.sequential,
        randomize_questions: f.randomize_questions, randomize_options: f.randomize_options, completion_commitment: f.completion_commitment,
        commitment_text: f.completion_commitment === "none" ? (f.commitment_text || null) : f.commitment_text, min_active_time_pct: nn(f.min_active_time_pct),
        certificate_enabled: f.certificate_enabled, certificate_validity_months: nn(f.certificate_validity_months), public_verification_enabled: f.public_verification_enabled,
        ...(locked ? {} : { grading_scale_id: f.grading_scale_id || null, passing_score: nn(f.passing_score) }),
      });
      setMsg({ ok: true, text: "Configuración guardada." });
      onChanged();
    } catch (e) {
      setMsg({ ok: false, text: errorText(e) });
    } finally {
      setBusy(false);
    }
  };
  const removeTrainer = (p: TrainerPerm) => records.remove("AUL", "trainer_permissions", p.id).then(onChanged).catch((e) => setMsg({ ok: false, text: errorText(e) }));
  return (
    <div className="space-y-4">
      <section className="space-y-3 rounded-xl border border-line bg-surface p-4">
        <h3 className="font-display font-semibold">Datos y reglas del curso</h3>
        {locked && <Note tone="warn">El curso está publicado y tiene matriculados: la escala y la nota mínima no cambian. Para cambiarlas, crea una nueva versión del curso.</Note>}
        <div className="grid gap-3 sm:grid-cols-2">
          <FormField label="Nombre del curso"><input className="input" disabled={dis} value={f.title} onChange={(e) => set({ title: e.target.value })} /></FormField>
          <FormField label="Categoría">
            <select className="input" disabled={dis} value={f.category} onChange={(e) => set({ category: e.target.value })}>
              <option value="">Sin categoría</option>{[...new Set([...CATEGORIES, ...(f.category ? [f.category] : [])])].map((x) => <option key={x}>{x}</option>)}
            </select>
          </FormField>
          <FormField label="Horas que acredita"><input className="input" disabled={dis} inputMode="decimal" value={f.hours} onChange={(e) => set({ hours: e.target.value })} /></FormField>
          <FormField label="Escala de calificación">
            {locked || dis ? <input className="input" disabled value={c.scale_name ?? "Porcentaje (0 a 100)"} /> : (
              <RefSelect module="AUL" table="courses" field="grading_scale_id" label="Escala de calificación" value={f.grading_scale_id} onChange={(v) => set({ grading_scale_id: v })} placeholder="Porcentaje (0 a 100)" />
            )}
          </FormField>
          <FormField label="Nota mínima para aprobar (%)"><input className="input" disabled={dis || locked} inputMode="decimal" value={f.passing_score} onChange={(e) => set({ passing_score: e.target.value })} /></FormField>
          <FormField label="Intentos por evaluación"><input className="input" disabled={dis} inputMode="numeric" value={f.max_attempts} onChange={(e) => set({ max_attempts: e.target.value })} /></FormField>
          <FormField label="Permanencia mínima (% de las horas)"><input className="input" disabled={dis} inputMode="decimal" value={f.min_active_time_pct} onChange={(e) => set({ min_active_time_pct: e.target.value })} /></FormField>
          <FormField label="Compromiso de culminación">
            <select className="input" disabled={dis} value={f.completion_commitment} onChange={(e) => set({ completion_commitment: e.target.value })}>
              <option value="none">Desactivado</option><option value="optional">Opcional</option><option value="mandatory">Obligatorio antes de empezar</option>
            </select>
          </FormField>
        </div>
        {f.completion_commitment !== "none" && <FormField label="Texto del compromiso"><textarea className="input min-h-20" disabled={dis} value={f.commitment_text} onChange={(e) => set({ commitment_text: e.target.value })} /></FormField>}
        <FormField label="Resumen"><textarea className="input min-h-16" disabled={dis} value={f.summary} onChange={(e) => set({ summary: e.target.value })} /></FormField>
        <div className="grid gap-2 sm:grid-cols-2">
          <SwitchRow label="Recorrido secuencial" hint="Cada actividad obligatoria se desbloquea al completar la anterior." checked={f.sequential} onChange={(v) => !dis && set({ sequential: v })} />
          <SwitchRow label="Mezclar opciones" hint="Cambia el orden de las alternativas en cada intento." checked={f.randomize_options} onChange={(v) => !dis && set({ randomize_options: v })} />
          <SwitchRow label="Emite certificado" hint="Al aprobar se crea el certificado en Capacitaciones." checked={f.certificate_enabled} onChange={(v) => !dis && set({ certificate_enabled: v })} />
          <SwitchRow label="Verificación pública" hint="El certificado se valida con su código QR." checked={f.public_verification_enabled} onChange={(v) => !dis && set({ public_verification_enabled: v })} />
        </div>
        {f.certificate_enabled && <FormField label="Vigencia del certificado (meses)"><input className="input max-w-40" disabled={dis} inputMode="numeric" value={f.certificate_validity_months} onChange={(e) => set({ certificate_validity_months: e.target.value })} /></FormField>}
        {can.update_course && (
          <div className="flex items-center gap-3">
            <Button variant="primary" loading={busy} onClick={save}>Guardar configuración</Button>
            {msg && <span className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</span>}
          </div>
        )}
      </section>
      <section className="space-y-3 rounded-xl border border-line bg-surface p-4">
        <h3 className="font-display font-semibold">Capacitadores de este curso</h3>
        <p className="text-sm text-muted">El capacitador ve solo los cursos que tiene asignados. Los permisos de este curso prevalecen sobre sus permisos por defecto.</p>
        {d.trainers.length === 0 && <p className="text-sm text-muted">Sin capacitador asignado.</p>}
        {d.trainers.map((t) => (
          <div key={t.id} className="space-y-2 rounded-xl border border-line p-3">
            <div className="flex items-center justify-between gap-2">
              <b>{t.name}</b>
              {can.trainers && <button type="button" className="text-xs font-semibold text-bad hover:underline" onClick={() => removeTrainer(t)}>Quitar del curso</button>}
            </div>
            <PermEditor perm={t} trainerId={t.trainer_user_id} courseId={c.id} canEdit={can.trainers} onSaved={onChanged} />
          </div>
        ))}
        {can.trainers && (
          <div className="space-y-2">
            <FormField label="Asignar capacitador"><RefSelect module="AUL" table="trainer_permissions" field="trainer_user_id" label="Capacitador" value={trainer} onChange={(v) => setTrainer(v)} exclude={d.trainers.map((t) => t.trainer_user_id)} /></FormField>
            {trainer && <PermEditor key={trainer} perm={null} trainerId={trainer} courseId={c.id} canEdit onSaved={() => { setTrainer(""); onChanged(); }} />}
          </div>
        )}
      </section>
    </div>
  );
}
