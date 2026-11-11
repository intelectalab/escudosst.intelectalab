"use client";

// Aula virtual (M-AUL SCR-AUL-01, 02, 03 y 11; prototipo m-aula.js R.AUL): panel con indicadores, filtro
// Todos / Publicados / Borradores y tarjetas de curso (banda de color, código, horas, matriculados, % de aprobados,
// chips Secuencial / Verificable). «Nuevo curso» abre el curso al guardar; «Banco de preguntas», «Recomendaciones» y
// «Capacitadores» (permisos y disponibilidad), «Escalas» (SCR-AUL-10) y «Por calificar». Todo lo demás se trabaja
// dentro del curso (/m/aul/<id>).

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { useApp } from "@/components/AppContext";
import { api } from "@/lib/api";
import { records } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Chip, type ChipTone } from "@/components/ui/Display";
import { FormField, SwitchRow } from "@/components/ui/Form";
import { Icon } from "@/components/ui/Icon";
import { Modal } from "@/components/ui/Modal";
import { Bar, Kpi, fmtNum } from "./Summary";
import { RefSelect } from "./RefSelect";
import { errorText } from "./Wizard";

export type AulCan = {
  create_course: boolean; update_course: boolean; publish: boolean; edit_content: boolean; sections: boolean; enroll: boolean; grade: boolean; banks: boolean;
  questions: boolean; trainers: boolean; availability: boolean; recommend: boolean; approve: boolean; rules: boolean;
  extend?: boolean; reset?: boolean; import?: boolean; scales?: boolean; time?: boolean; run_rules?: boolean;
};
export type CourseCard = {
  id: string; code: string; title: string; category: string | null; hours: number | null; status: string; sequential: boolean; public_verification_enabled: boolean;
  certificate_enabled: boolean; passing_score: number | null; max_attempts: number | null; completion_commitment: string; scale_name: string | null;
  enrolled: number; passed: number; active: number; trainers: string | null;
};
type Overview = { kpis: { published: number; drafts: number; active_enrollments: number; passed: number; hours_month: number }; courses: CourseCard[]; can: AulCan };

export const COURSE_STATUS: Record<string, [string, ChipTone]> = { draft: ["Borrador", "amber"], published: ["Publicado", "ok"], archived: ["Archivado", "na"] };
export const ENROLL_STATUS: Record<string, [string, ChipTone]> = {
  enrolled: ["Sin iniciar", "na"], in_progress: ["En curso", "amber"], completed: ["Aprobado", "ok"], failed: ["Desaprobado", "bad"], expired: ["Vencido", "bad"], cancelled: ["De baja", "na"],
};
export const CATEGORIES = ["Inducción", "General", "Operación", "Alto riesgo", "Emergencias", "Gestión SST"];
const BANDS = ["#E2453C", "#1F7FD1", "#22A35A", "#F08A24", "#1FB5A3", "#C99A06", "#0D3B6E"];
export const bandOf = (code: string) => BANDS[[...code].reduce((a, ch) => a + ch.charCodeAt(0), 0) % BANDS.length];
export const hm = (seconds: number | null | undefined) => {
  const m = Math.round((seconds ?? 0) / 60);
  return m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, "0")} min`;
};
export const WEEKDAYS = ["", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado", "Domingo"];
export const MODALITY: Record<string, string> = { in_person: "Presencial", virtual: "Virtual", both: "Presencial y virtual" };

/** Acceso al aula según el plan y el rol (como la página del módulo). */
export function useAulAccess() {
  const { modules } = useApp();
  const m = modules.find((x) => x.code === "AUL");
  const ok = !!m && m.role_can_use && (m.mode === "enabled" || m.mode === "read_only");
  return { ok, readOnly: m?.mode === "read_only" };
}

export function AulCrumbs({ items }: { items: { label: string; href?: string }[] }) {
  return (
    <nav aria-label="Migas de pan" className="text-xs text-muted">
      EscudoSST › Personas y salud › <Link href="/m/aul" className="hover:text-brand">Aula virtual</Link>
      {items.map((i) => (
        <span key={i.label}> › {i.href ? <Link href={i.href} className="hover:text-brand">{i.label}</Link> : <span className="text-fg">{i.label}</span>}</span>
      ))}
    </nav>
  );
}

export function NoAccess({ text }: { text: string }) {
  return (
    <div className="space-y-3 rounded-xl border border-line bg-surface p-8">
      <h1 className="text-xl font-semibold">Página no disponible</h1>
      <p className="text-muted">{text}</p>
      <Link href="/m/aul" className="inline-block rounded-lg border border-line px-4 py-2 hover:bg-bg">Volver al aula virtual</Link>
    </div>
  );
}

function CourseTile({ c }: { c: CourseCard }) {
  const pct = c.enrolled ? Math.round((c.passed * 100) / c.enrolled) : 0;
  const [st, tone] = COURSE_STATUS[c.status] ?? [c.status, "na" as ChipTone];
  return (
    <Link href={`/m/aul/${c.id}`} className="flex flex-col overflow-hidden rounded-xl border border-line bg-surface hover:border-brand/50 focus-visible:outline-2 focus-visible:outline-brand">
      <span className="flex items-center gap-2 px-4 py-3 text-white" style={{ background: bandOf(c.code) }}>
        <Icon name="book" size={20} />
        <span className="font-mono text-xs font-semibold">{c.code}</span>
        <span className="ml-auto text-sm font-semibold">{c.hours != null ? `${fmtNum(c.hours, c.hours % 1 ? 1 : 0)} h` : "— h"}</span>
      </span>
      <span className="flex flex-1 flex-col gap-1.5 p-4">
        <b className="font-display leading-snug">{c.title}</b>
        <span className="text-sm text-muted">{c.trainers || "Sin capacitador asignado"}</span>
        <span className="text-xs text-muted">{c.scale_name ?? "Porcentaje (0 a 100)"} · mínimo {c.passing_score ?? 70}{c.scale_name ? "" : " %"}</span>
        <span className="mt-auto flex items-center justify-between pt-2 text-sm">
          <span>{c.enrolled} matriculado{c.enrolled === 1 ? "" : "s"}</span>
          <b className="tabular-nums">{pct}% aprobados</b>
        </span>
        <Bar pct={pct} />
        <span className="flex flex-wrap gap-1.5 pt-1">
          <Chip tone={tone}>{st}</Chip>
          {c.sequential && <Chip tone="brand">Secuencial</Chip>}
          {c.public_verification_enabled && <Chip tone="brand">Verificable</Chip>}
        </span>
      </span>
    </Link>
  );
}

/** Panel del aula: indicadores, filtro y tarjetas de curso. No se muestra a quien solo aprende. */
export function AulLanding({ readOnly }: { readOnly: boolean }) {
  const [data, setData] = useState<Overview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [seg, setSeg] = useState<"all" | "published" | "draft">("all");
  const [creating, setCreating] = useState(false);
  const [trainers, setTrainers] = useState(false);

  useEffect(() => {
    api<{ data: Overview }>("/aul/admin/overview", { withCompany: true }).then((r) => setData(r.data)).catch((e) => setError(errorText(e)));
  }, []);

  if (error) return <p className="rounded-xl border border-line bg-surface px-4 py-3 text-sm text-muted">{error}</p>;
  if (!data) return <div className="h-48 animate-pulse rounded-xl bg-surface" />;
  const list = data.courses.filter((c) => seg === "all" || c.status === seg);
  const k = data.kpis;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-end gap-2">
        <Link href="/m/aul/banco" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold hover:border-brand hover:text-brand-strong">
          <Icon name="file" size={17} /> Banco de preguntas
        </Link>
        <Link href="/m/aul/recomendaciones" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold hover:border-brand hover:text-brand-strong">
          <Icon name="star" size={17} /> Recomendaciones
        </Link>
        <Link href="/m/aul/escalas" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold hover:border-brand hover:text-brand-strong">
          <Icon name="scale" size={17} /> Escalas
        </Link>
        {data.can.grade && (
          <Link href="/m/aul/calificar" className="inline-flex h-10 items-center gap-2 rounded-lg border border-line bg-surface px-4 text-sm font-semibold hover:border-brand hover:text-brand-strong">
            <Icon name="checklist" size={17} /> Por calificar
          </Link>
        )}
        <Button icon="people" onClick={() => setTrainers(true)}>Capacitadores</Button>
        {data.can.create_course && !readOnly && <Button variant="primary" icon="plus" onClick={() => setCreating(true)}>Nuevo curso</Button>}
      </div>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Kpi label="Cursos publicados" value={String(k.published)} sub={`${k.drafts} en borrador`} />
        <Kpi label="Matrículas activas" value={String(k.active_enrollments)} color="text-[#1FB5A3]" sub="En curso o sin iniciar" />
        <Kpi label="Aprobados" value={String(k.passed)} color="text-ok" sub="Generan constancia en Capacitaciones" />
        <Kpi label="Horas acreditadas del mes" value={fmtNum(k.hours_month, k.hours_month % 1 ? 1 : 0)} color="text-[#F08A24]" sub="Por cursos aprobados este mes" />
      </div>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div role="tablist" aria-label="Filtrar cursos" className="inline-flex rounded-lg border border-line bg-surface p-0.5 text-sm">
          {([["all", `Todos (${data.courses.length})`], ["published", "Publicados"], ["draft", "Borradores"]] as const).map(([v, l]) => (
            <button key={v} type="button" role="tab" aria-selected={seg === v} onClick={() => setSeg(v)}
              className={`rounded-md px-3 py-1.5 font-semibold ${seg === v ? "bg-brand text-white" : "text-muted hover:text-fg"}`}>{l}</button>
          ))}
        </div>
        <span className="text-xs text-muted">Toca un curso para abrirlo</span>
      </div>
      {list.length ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{list.map((c) => <CourseTile key={c.id} c={c} />)}</div>
      ) : (
        <div className="rounded-xl border border-line bg-surface p-8 text-center text-sm text-muted">
          <p className="font-semibold text-fg">Sin cursos en este filtro</p>
          <p>Cambia el filtro{data.can.create_course ? " o crea un curso nuevo" : ""}.</p>
        </div>
      )}
      <p className="flex gap-2 rounded-xl border border-line bg-surface px-4 py-3 text-sm text-muted">
        <Icon name="clock" size={18} className="mt-0.5 shrink-0" />
        <span><b className="text-fg">Tiempo de permanencia real.</b> Se mide por actividad dentro del curso (lectura, video, respuestas); una ventana abierta sin interacción no suma. Así se acreditan las horas de capacitación.</span>
      </p>
      {creating && <NewCourse onClose={() => setCreating(false)} />}
      {trainers && <TrainersModal canEdit={data.can.trainers && !readOnly} onClose={() => setTrainers(false)} />}
    </div>
  );
}

// ─────────────── Nuevo curso (SCR-AUL-03) ───────────────

type CourseForm = {
  title: string; category: string; hours: string; summary: string; grading_scale_id: string; passing_score: string; max_attempts: string; sequential: boolean;
  completion_commitment: "none" | "optional" | "mandatory"; commitment_text: string; min_active_time_pct: string; certificate_enabled: boolean;
  certificate_validity_months: string; public_verification_enabled: boolean;
};
const num = (v: string) => (v.trim() === "" ? null : Number(v.replace(",", ".")));

function NewCourse({ onClose }: { onClose: () => void }) {
  const router = useRouter();
  const [f, setF] = useState<CourseForm>({
    title: "", category: "General", hours: "2", summary: "", grading_scale_id: "", passing_score: "70", max_attempts: "3", sequential: true, completion_commitment: "none",
    commitment_text: "Me comprometo a completar este curso dentro del plazo indicado.", min_active_time_pct: "", certificate_enabled: true, certificate_validity_months: "12", public_verification_enabled: true,
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const set = (p: Partial<CourseForm>) => setF((x) => ({ ...x, ...p }));
  const save = async () => {
    setBusy(true);
    setErrors({});
    try {
      const r = await api<{ data: { id: string } }>("/aul/admin/courses", {
        method: "POST", withCompany: true,
        body: {
          title: f.title, category: f.category || null, hours: num(f.hours), summary: f.summary || null, grading_scale_id: f.grading_scale_id || null, passing_score: num(f.passing_score),
          max_attempts: num(f.max_attempts), sequential: f.sequential, completion_commitment: f.completion_commitment, commitment_text: f.completion_commitment === "none" ? null : f.commitment_text,
          min_active_time_pct: num(f.min_active_time_pct), certificate_enabled: f.certificate_enabled, certificate_validity_months: f.certificate_enabled ? num(f.certificate_validity_months) : null,
          public_verification_enabled: f.public_verification_enabled,
        },
      });
      router.push(`/m/aul/${r.data.id}`);
    } catch (e) {
      const ae = e as { errors?: Record<string, string[]> };
      setErrors(ae.errors ?? { title: [errorText(e)] });
      setBusy(false);
    }
  };
  const err = (k: string) => errors[k]?.[0];
  return (
    <Modal title="Nuevo curso" subtitle="Primero los datos y la configuración; al guardar se abre el curso para subir contenidos y matricular." icon="plus" size="lg" onClose={onClose}
      footer={<div className="flex w-full justify-end gap-2"><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} onClick={save}>Guardar y abrir el curso</Button></div>}>
      <div className="space-y-3">
        <div className="grid gap-3 sm:grid-cols-2">
          <FormField label="Nombre del curso *" error={err("title")}><input className="input" value={f.title} onChange={(e) => set({ title: e.target.value })} placeholder="Espacios confinados para vigías" /></FormField>
          <FormField label="Categoría"><select className="input" value={f.category} onChange={(e) => set({ category: e.target.value })}>{CATEGORIES.map((c) => <option key={c}>{c}</option>)}</select></FormField>
          <FormField label="Horas que acredita *" error={err("hours")}><input className="input" inputMode="decimal" value={f.hours} onChange={(e) => set({ hours: e.target.value })} /></FormField>
          <FormField label="Escala de calificación" hint="Sin escala, la nota se muestra en porcentaje.">
            <RefSelect module="AUL" table="courses" field="grading_scale_id" label="Escala de calificación" value={f.grading_scale_id} onChange={(v) => set({ grading_scale_id: v })} placeholder="Porcentaje (0 a 100)" />
          </FormField>
          <FormField label="Nota mínima para aprobar (%)" error={err("passing_score")}><input className="input" inputMode="decimal" value={f.passing_score} onChange={(e) => set({ passing_score: e.target.value })} /></FormField>
          <FormField label="Intentos por evaluación" error={err("max_attempts")}><input className="input" inputMode="numeric" value={f.max_attempts} onChange={(e) => set({ max_attempts: e.target.value })} /></FormField>
          <FormField label="Permanencia mínima (% de las horas)" hint="Opcional. Tiempo activo real que debe acumular." error={err("min_active_time_pct")}>
            <input className="input" inputMode="decimal" value={f.min_active_time_pct} onChange={(e) => set({ min_active_time_pct: e.target.value })} placeholder="Ej.: 80" />
          </FormField>
          <FormField label="Compromiso de culminación">
            <select className="input" value={f.completion_commitment} onChange={(e) => set({ completion_commitment: e.target.value as CourseForm["completion_commitment"] })}>
              <option value="none">Desactivado</option><option value="optional">Opcional</option><option value="mandatory">Obligatorio antes de empezar</option>
            </select>
          </FormField>
        </div>
        {f.completion_commitment !== "none" && (
          <FormField label="Texto del compromiso *" error={err("commitment_text")}><textarea className="input min-h-20" value={f.commitment_text} onChange={(e) => set({ commitment_text: e.target.value })} /></FormField>
        )}
        <FormField label="Resumen (opcional)"><textarea className="input min-h-16" value={f.summary} onChange={(e) => set({ summary: e.target.value })} /></FormField>
        <SwitchRow label="Recorrido secuencial" hint="Cada actividad obligatoria se desbloquea al completar la anterior." checked={f.sequential} onChange={(v) => set({ sequential: v })} />
        <SwitchRow label="Emite certificado" hint="Al aprobar se crea el certificado en Capacitaciones." checked={f.certificate_enabled} onChange={(v) => set({ certificate_enabled: v })} />
        {f.certificate_enabled && (
          <div className="grid gap-3 sm:grid-cols-2">
            <FormField label="Vigencia del certificado (meses)"><input className="input" inputMode="numeric" value={f.certificate_validity_months} onChange={(e) => set({ certificate_validity_months: e.target.value })} /></FormField>
            <SwitchRow label="Verificación pública" hint="El certificado se valida con su código QR." checked={f.public_verification_enabled} onChange={(v) => set({ public_verification_enabled: v })} />
          </div>
        )}
      </div>
    </Modal>
  );
}

// ─────────────── Capacitadores (SCR-AUL-11, 12) ───────────────

export type TrainerPerm = {
  id: string; trainer_user_id: string; course_id: string | null; course_code?: string | null; course_title?: string | null; name?: string;
  can_upload_materials: boolean; can_edit_content: boolean; can_edit_assessments: boolean; can_manage_question_banks: boolean; can_grade: boolean; can_enroll: boolean; can_view_reports: boolean;
};
type Slot = { id: string; weekday: number | null; start_time: string | null; end_time: string | null; modality: string | null; site: string | null };
type Trainer = { id: string; name: string; email: string | null; permissions: TrainerPerm[]; availability: Slot[] };

export const PERMS: [keyof TrainerPerm, string, string][] = [
  ["can_upload_materials", "Subir materiales", "Documentos, videos y enlaces del curso"],
  ["can_edit_content", "Editar contenidos", "Secciones, textos y orden"],
  ["can_edit_assessments", "Configurar evaluaciones", "Bancos, intentos y nota mínima"],
  ["can_manage_question_banks", "Gestionar preguntas", "Bancos de sus cursos"],
  ["can_grade", "Calificar", "Notas de evaluaciones prácticas"],
  ["can_enroll", "Matricular", "Por defecto lo hace el administrador"],
  ["can_view_reports", "Ver reportes", "Avance, permanencia y aprobados"],
];
/** Por defecto (RN-AUL-02): sube y edita contenidos, gestiona preguntas y califica; no matricula. */
export const DEFAULT_PERMS = { can_upload_materials: true, can_edit_content: true, can_edit_assessments: false, can_manage_question_banks: true, can_grade: true, can_enroll: false, can_view_reports: true };

/** Editor de los permisos de un capacitador (por defecto o para un curso). */
export function PermEditor({ perm, trainerId, courseId, canEdit, onSaved }: { perm: TrainerPerm | null; trainerId: string; courseId: string | null; canEdit: boolean; onSaved: () => void }) {
  const [v, setV] = useState<Record<string, boolean>>(() => Object.fromEntries(PERMS.map(([k]) => [k, perm ? !!perm[k] : (DEFAULT_PERMS as Record<string, boolean>)[k]])));
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  const save = async () => {
    setBusy(true);
    setMsg(null);
    try {
      if (perm) await records.update("AUL", "trainer_permissions", perm.id, v);
      else await records.create("AUL", "trainer_permissions", { ...v, trainer_user_id: trainerId, course_id: courseId });
      setMsg({ ok: true, text: "Permisos guardados." });
      onSaved();
    } catch (e) {
      setMsg({ ok: false, text: errorText(e) });
    } finally {
      setBusy(false);
    }
  };
  return (
    <div className="space-y-2">
      <ul className="grid gap-1.5 sm:grid-cols-2">
        {PERMS.map(([k, l, d]) => (
          <li key={k}>
            <label className="flex cursor-pointer items-start gap-2 rounded-lg border border-line px-3 py-2 text-sm">
              <input type="checkbox" className="mt-1" checked={!!v[k]} disabled={!canEdit} onChange={(e) => setV((x) => ({ ...x, [k]: e.target.checked }))} />
              <span><b className="block">{l}</b><span className="text-xs text-muted">{d}</span></span>
            </label>
          </li>
        ))}
      </ul>
      {canEdit && (
        <div className="flex items-center gap-3">
          <Button size="sm" variant="primary" loading={busy} onClick={save}>Guardar permisos</Button>
          {msg && <span className={`text-sm ${msg.ok ? "text-ok" : "text-bad"}`}>{msg.text}</span>}
        </div>
      )}
    </div>
  );
}

function TrainersModal({ canEdit, onClose }: { canEdit: boolean; onClose: () => void }) {
  const [list, setList] = useState<Trainer[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState<string | null>(null);
  const [adding, setAdding] = useState("");
  const load = useCallback(() => {
    api<{ data: Trainer[] }>("/aul/admin/trainers", { withCompany: true }).then((r) => setList(r.data)).catch((e) => setError(errorText(e)));
  }, []);
  useEffect(load, [load]);
  const extra = adding && !list?.some((t) => t.id === adding) ? [{ id: adding, name: "Nuevo capacitador", email: null, permissions: [], availability: [] } as Trainer] : [];
  return (
    <Modal title="Capacitadores" subtitle="Cursos asignados, permisos por defecto y disponibilidad semanal. Los permisos por curso se ajustan en la configuración del curso." icon="people" size="lg" onClose={onClose}>
      {error && <p className="text-sm text-bad">{error}</p>}
      {!list ? <div className="h-24 animate-pulse rounded-xl bg-bg" /> : (
        <div className="space-y-3">
          {list.length + extra.length === 0 && <p className="text-sm text-muted">Aún no hay capacitadores. Invita a una persona con el rol «Capacitador» desde Usuarios, o elige un usuario aquí para darle permisos.</p>}
          {[...list, ...extra].map((t) => {
            const def = t.permissions.find((p) => p.course_id === null) ?? null;
            const courses = t.permissions.filter((p) => p.course_id);
            return (
              <div key={t.id} className="rounded-xl border border-line">
                <button type="button" className="flex w-full flex-wrap items-center justify-between gap-2 px-4 py-3 text-left" onClick={() => setOpen(open === t.id ? null : t.id)} aria-expanded={open === t.id}>
                  <span><b className="block">{t.name}</b><span className="text-xs text-muted">{t.email ?? ""}</span></span>
                  <span className="flex flex-wrap gap-1.5 text-xs">
                    <Chip tone="brand">{courses.length} curso{courses.length === 1 ? "" : "s"}</Chip>
                    <Chip tone={t.availability.length ? "ok" : "na"}>{t.availability.length ? `${t.availability.length} franja(s)` : "Sin disponibilidad"}</Chip>
                    <Chip tone={(def ?? DEFAULT_PERMS).can_enroll ? "ok" : "na"}>{(def ?? DEFAULT_PERMS).can_enroll ? "Matricula" : "No matricula"}</Chip>
                  </span>
                </button>
                {open === t.id && (
                  <div className="space-y-4 border-t border-line px-4 py-3">
                    {courses.length > 0 && <p className="text-sm"><b>Cursos:</b> {courses.map((c) => `${c.course_code} ${c.course_title}`).join(" · ")}</p>}
                    <div>
                      <p className="mb-2 text-sm font-semibold">Permisos por defecto</p>
                      <PermEditor perm={def} trainerId={t.id} courseId={null} canEdit={canEdit} onSaved={load} />
                    </div>
                    <Availability trainer={t} canEdit={canEdit} onChanged={load} />
                  </div>
                )}
              </div>
            );
          })}
          {canEdit && (
            <FormField label="Dar permisos a otro usuario" hint="Elige a la persona; luego guarda sus permisos.">
              <RefSelect module="AUL" table="trainer_permissions" field="trainer_user_id" label="Usuario" value={adding} onChange={(v) => { setAdding(v); setOpen(v); }} />
            </FormField>
          )}
        </div>
      )}
    </Modal>
  );
}

function Availability({ trainer, canEdit, onChanged }: { trainer: Trainer; canEdit: boolean; onChanged: () => void }) {
  const [f, setF] = useState({ weekday: "1", start_time: "08:00", end_time: "12:00", modality: "virtual" });
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const add = async () => {
    setBusy(true);
    setMsg(null);
    try {
      await api("/aul/admin/trainers/availability", { method: "POST", withCompany: true, body: { ...f, weekday: Number(f.weekday), trainer_user_id: trainer.id } });
      onChanged();
    } catch (e) {
      setMsg(errorText(e));
    } finally {
      setBusy(false);
    }
  };
  const remove = (id: string) => records.remove("AUL", "trainer_availability", id).then(onChanged).catch((e) => setMsg(errorText(e)));
  return (
    <div className="space-y-2">
      <p className="text-sm font-semibold">Disponibilidad semanal</p>
      {trainer.availability.length === 0 ? <p className="text-sm text-muted">Sin franjas registradas.</p> : (
        <ul className="space-y-1 text-sm">
          {trainer.availability.map((s) => (
            <li key={s.id} className="flex items-center justify-between gap-2 rounded-lg border border-line px-3 py-1.5">
              <span>{WEEKDAYS[s.weekday ?? 0] ?? "—"} {s.start_time}–{s.end_time} · {MODALITY[s.modality ?? ""] ?? "—"}{s.site ? ` · ${s.site}` : ""}</span>
              {canEdit && <button type="button" className="text-xs font-semibold text-bad hover:underline" onClick={() => remove(s.id)}>Quitar</button>}
            </li>
          ))}
        </ul>
      )}
      {canEdit && (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
          <select className="input" aria-label="Día" value={f.weekday} onChange={(e) => setF({ ...f, weekday: e.target.value })}>{WEEKDAYS.slice(1).map((d, i) => <option key={d} value={i + 1}>{d}</option>)}</select>
          <input className="input" type="time" aria-label="Desde" value={f.start_time} onChange={(e) => setF({ ...f, start_time: e.target.value })} />
          <input className="input" type="time" aria-label="Hasta" value={f.end_time} onChange={(e) => setF({ ...f, end_time: e.target.value })} />
          <select className="input" aria-label="Modalidad" value={f.modality} onChange={(e) => setF({ ...f, modality: e.target.value })}>{Object.entries(MODALITY).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
          <Button variant="primary" loading={busy} onClick={add}>Agregar</Button>
        </div>
      )}
      {msg && <p className="text-sm text-bad">{msg}</p>}
    </div>
  );
}
