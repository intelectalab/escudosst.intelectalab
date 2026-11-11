"use client";

// Herramientas del aula virtual (M-AUL): escalas de calificación (SCR-AUL-10), tiempo de permanencia por curso
// (SCR-AUL-14, con CSV), «Por calificar» del capacitador (SCR-AUL-06/13; prototipo m-aula.js «Panel del capacitador»),
// estadística por pregunta de una evaluación (SCR-AUL-07) y las acciones de una matrícula: extender acceso y
// reiniciar intentos con motivo (SCR-AUL-05, quedan en la bitácora).

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { downloadFile } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Chip, type ChipTone } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Bar, Kpi, fmtNum } from "./Summary";
import { errorText } from "./Wizard";
import { AulCrumbs, ENROLL_STATUS, hm } from "./AulAdmin";

const fmtDate = (v: string | null) => (v ? new Date(v.length === 10 ? `${v}T12:00:00` : v).toLocaleDateString("es-PE", { day: "2-digit", month: "2-digit", year: "numeric" }) : "—");
const DIFF: Record<string, string> = { easy: "Baja", medium: "Media", hard: "Alta" };

// ─────────────── Escalas de calificación (SCR-AUL-10) ───────────────

type Band = { label: string; from: number; to?: number; description?: string | null };
type Scale = {
  id: string; code: string; name: string; scale_type: "numeric" | "letter"; min_value: number | null; max_value: number | null; passing_value: number | null;
  passing_label: string | null; bands: Band[]; decimals: number; is_default: boolean; status: string; courses: number; system: boolean;
};

export function AulScales({ readOnly }: { readOnly: boolean }) {
  const [d, setD] = useState<{ items: Scale[]; can_edit: boolean } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [editing, setEditing] = useState<Scale | "new" | null>(null);
  const [base, setBase] = useState<Scale | null>(null);
  const load = useCallback(() => {
    api<{ data: { items: Scale[]; can_edit: boolean } }>("/aul/admin/scales", { withCompany: true }).then((r) => setD(r.data)).catch((e) => setError(errorText(e)));
  }, []);
  useEffect(load, [load]);
  const can = !!d?.can_edit && !readOnly;
  const archive = (s: Scale) => api(`/aul/admin/scales/${s.id}`, { method: "DELETE", withCompany: true }).then(load).catch((e) => setError(errorText(e)));
  const list = (d?.items ?? []).filter((s) => s.status === "active");

  return (
    <div className="space-y-4">
      <AulCrumbs items={[{ label: "Escalas de calificación" }]} />
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="font-display text-2xl font-bold">Escalas de calificación</h1>
          <p className="text-sm text-muted">La nota se calcula en porcentaje y se muestra en la escala del curso: 0 a 20, 0 a 10, 0 a 100 o por letras. Cada curso usa una sola escala.</p>
        </div>
        {can && <Button variant="primary" icon="plus" onClick={() => { setBase(null); setEditing("new"); }}>Nueva escala</Button>}
      </div>
      {error && <p className="rounded-lg bg-bad-soft px-4 py-2 text-sm text-bad">{error}</p>}
      {!d ? <div className="h-32 animate-pulse rounded-xl bg-surface" /> : (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {list.map((s) => (
            <section key={s.id} className="flex flex-col gap-2 rounded-xl border border-line bg-surface p-4">
              <div className="flex items-start justify-between gap-2">
                <span><span className="font-mono text-[11px] text-muted">{s.code}</span><b className="block font-display">{s.name}</b></span>
                <span className="flex flex-wrap justify-end gap-1">{s.is_default && <Chip tone="brand">Por defecto</Chip>}<Chip tone="na">{s.system ? "Del sistema" : "De la empresa"}</Chip></span>
              </div>
              {s.scale_type === "numeric" ? (
                <p className="text-sm">De {fmtNum(s.min_value ?? 0, 0)} a {fmtNum(s.max_value ?? 0, 0)} · aprueba con <b>{fmtNum(s.passing_value ?? 0, s.decimals)}</b>{s.decimals ? ` · ${s.decimals} decimal(es)` : ""}</p>
              ) : (
                <ul className="space-y-0.5 text-sm">
                  {s.bands.map((b) => (
                    <li key={b.label} className="flex justify-between gap-2">
                      <span><b className={b.label === s.passing_label ? "text-ok" : ""}>{b.label}</b>{b.description ? <span className="text-muted"> · {b.description}</span> : null}</span>
                      <span className="tabular-nums text-muted">{fmtNum(b.from, 0)}–{fmtNum(b.to ?? 100, 0)} %</span>
                    </li>
                  ))}
                  <li className="pt-1 text-xs text-muted">Aprueba desde <b>{s.passing_label}</b></li>
                </ul>
              )}
              <p className="mt-auto text-xs text-muted">{s.courses} curso(s) la usan</p>
              {can && (
                <div className="flex flex-wrap gap-2">
                  {s.system ? <Button size="sm" onClick={() => { setBase(s); setEditing("new"); }}>Crear una propia a partir de esta</Button> : (
                    <>
                      <Button size="sm" icon="pen" onClick={() => setEditing(s)}>Editar</Button>
                      {s.courses === 0 && <Button size="sm" variant="danger-ghost" onClick={() => archive(s)}>Archivar</Button>}
                    </>
                  )}
                </div>
              )}
            </section>
          ))}
        </div>
      )}
      <p className="rounded-xl border border-line bg-surface px-4 py-3 text-sm text-muted">Un curso con calificaciones registradas no cambia su escala: para cambiarla se crea una nueva versión del curso.</p>
      {editing && <ScaleEditor scale={editing === "new" ? null : editing} base={base} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); load(); }} />}
    </div>
  );
}

function ScaleEditor({ scale, base, onClose, onSaved }: { scale: Scale | null; base: Scale | null; onClose: () => void; onSaved: () => void }) {
  const src = scale ?? base;
  const [f, setF] = useState({
    code: scale?.code ?? (base ? `${base.code}-E` : ""), name: scale?.name ?? (base ? `${base.name} (empresa)` : ""), scale_type: src?.scale_type ?? "numeric",
    min_value: String(src?.min_value ?? 0), max_value: String(src?.max_value ?? 20), passing_value: String(src?.passing_value ?? 14), decimals: String(src?.decimals ?? 0),
    passing_label: src?.passing_label ?? "A", is_default: scale?.is_default ?? false,
    bands: (src?.bands?.length ? src.bands : [{ label: "AD", from: 90 }, { label: "A", from: 70 }, { label: "B", from: 50 }, { label: "C", from: 0 }]).map((b) => ({ label: b.label, from: String(b.from), description: b.description ?? "" })),
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const set = (p: Partial<typeof f>) => setF((x) => ({ ...x, ...p }));
  const num = (v: string) => (v.trim() === "" ? null : Number(v.replace(",", ".")));
  const save = async () => {
    setBusy(true);
    setErrors({});
    const body = {
      code: f.code, name: f.name, scale_type: f.scale_type, is_default: f.is_default, decimals: num(f.decimals) ?? 0,
      ...(f.scale_type === "numeric" ? { min_value: num(f.min_value), max_value: num(f.max_value), passing_value: num(f.passing_value) }
        : { passing_label: f.passing_label, bands: f.bands.filter((b) => b.label.trim()).map((b) => ({ label: b.label.trim(), from: num(b.from) ?? 0, description: b.description || null })) }),
    };
    try {
      await api(scale ? `/aul/admin/scales/${scale.id}` : "/aul/admin/scales", { method: scale ? "PUT" : "POST", withCompany: true, body });
      onSaved();
    } catch (e) {
      setErrors((e as { errors?: Record<string, string[]> }).errors ?? { name: [errorText(e)] });
      setBusy(false);
    }
  };
  const err = (k: string) => errors[k]?.[0] ?? Object.entries(errors).find(([x]) => x.startsWith(`${k}.`))?.[1]?.[0];
  return (
    <Modal title={scale ? "Editar escala" : "Nueva escala"} icon="pen" size="md" onClose={onClose}
      footer={<div className="flex w-full justify-end gap-2"><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} onClick={save}>Guardar escala</Button></div>}>
      <div className="space-y-3">
        <div className="grid gap-3 sm:grid-cols-2">
          <FormField label="Código *" error={err("code")}><input className="input" value={f.code} onChange={(e) => set({ code: e.target.value.toUpperCase() })} /></FormField>
          <FormField label="Nombre *" error={err("name")}><input className="input" value={f.name} onChange={(e) => set({ name: e.target.value })} /></FormField>
        </div>
        <div role="radiogroup" aria-label="Tipo de escala" className="inline-flex rounded-lg border border-line p-0.5 text-sm">
          {([["numeric", "Numérica"], ["letter", "Por letras"]] as const).map(([v, l]) => (
            <button key={v} type="button" role="radio" aria-checked={f.scale_type === v} onClick={() => set({ scale_type: v })}
              className={`rounded-md px-3 py-1.5 font-semibold ${f.scale_type === v ? "bg-brand text-white" : "text-muted hover:text-fg"}`}>{l}</button>
          ))}
        </div>
        {f.scale_type === "numeric" ? (
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <FormField label="Mínimo" error={err("min_value")}><input className="input" inputMode="decimal" value={f.min_value} onChange={(e) => set({ min_value: e.target.value })} /></FormField>
            <FormField label="Máximo" error={err("max_value")}><input className="input" inputMode="decimal" value={f.max_value} onChange={(e) => set({ max_value: e.target.value })} /></FormField>
            <FormField label="Aprueba con" error={err("passing_value")}><input className="input" inputMode="decimal" value={f.passing_value} onChange={(e) => set({ passing_value: e.target.value })} /></FormField>
            <FormField label="Decimales"><select className="input" value={f.decimals} onChange={(e) => set({ decimals: e.target.value })}><option>0</option><option>1</option><option>2</option></select></FormField>
          </div>
        ) : (
          <div className="space-y-2">
            <p className="text-sm text-muted">Cada letra empieza en un porcentaje; la más baja empieza en 0 %.</p>
            {f.bands.map((b, i) => (
              <div key={i} className="grid grid-cols-[4rem_5rem_1fr_auto] items-center gap-2">
                <input className="input" aria-label="Letra" value={b.label} onChange={(e) => set({ bands: f.bands.map((x, k) => (k === i ? { ...x, label: e.target.value.toUpperCase() } : x)) })} />
                <input className="input" aria-label="Desde (%)" inputMode="decimal" value={b.from} onChange={(e) => set({ bands: f.bands.map((x, k) => (k === i ? { ...x, from: e.target.value } : x)) })} />
                <input className="input" aria-label="Descripción" placeholder="Descripción" value={b.description} onChange={(e) => set({ bands: f.bands.map((x, k) => (k === i ? { ...x, description: e.target.value } : x)) })} />
                {f.bands.length > 2 ? <button type="button" className="text-xs font-semibold text-bad" onClick={() => set({ bands: f.bands.filter((_, k) => k !== i) })}>Quitar</button> : <span />}
              </div>
            ))}
            {err("bands") && <p className="text-sm text-bad">{err("bands")}</p>}
            {f.bands.length < 10 && <Button size="sm" variant="ghost" icon="plus" onClick={() => set({ bands: [...f.bands, { label: "", from: "", description: "" }] })}>Agregar letra</Button>}
            <FormField label="Aprueba desde la letra" error={err("passing_label")}>
              <select className="input" value={f.passing_label} onChange={(e) => set({ passing_label: e.target.value })}>{f.bands.filter((b) => b.label).map((b) => <option key={b.label}>{b.label}</option>)}</select>
            </FormField>
          </div>
        )}
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.is_default} onChange={(e) => set({ is_default: e.target.checked })} /> Escala por defecto para los cursos nuevos</label>
      </div>
    </Modal>
  );
}

// ─────────────── Tiempo de permanencia (SCR-AUL-14) ───────────────

type TimeRow = {
  enrollment_id: string; name: string; position: string | null; area: string | null; status: string; progress_pct: number; active_seconds: number; video_seconds: number; sessions: number;
  idle_closed: number; flagged: number; days: number; last_at: string | null; required_seconds: number | null; compliance_pct: number | null; complies: boolean | null; missing_seconds: number | null;
};
type TimeData = {
  course: { id: string; code: string; title: string; hours: number | null; min_active_time_pct: number | null }; required_seconds: number | null;
  summary: { participants: number; avg_active_seconds: number; complying: number; not_complying: number }; params: { heartbeat_seconds: number; idle_minutes: number };
  rows: TimeRow[]; days: { day: string; active_seconds: number; participants: number }[];
};

export function AulTimeReport({ courseId }: { courseId: string }) {
  const [d, setD] = useState<TimeData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [seg, setSeg] = useState<"all" | "ok" | "no">("all");
  useEffect(() => {
    api<{ data: TimeData }>(`/aul/admin/courses/${courseId}/time`, { withCompany: true }).then((r) => setD(r.data)).catch((e) => setError(errorText(e)));
  }, [courseId]);
  if (error) return <p className="rounded-lg bg-bad-soft px-4 py-2 text-sm text-bad">{error}</p>;
  if (!d) return <div className="h-48 animate-pulse rounded-xl bg-surface" />;
  const rows = d.rows.filter((r) => seg === "all" || (seg === "ok" ? r.complies : r.complies === false));
  const maxDay = Math.max(1, ...d.days.map((x) => x.active_seconds));
  return (
    <div className="space-y-4">
      <AulCrumbs items={[{ label: d.course.title, href: `/m/aul/${courseId}` }, { label: "Tiempo de permanencia" }]} />
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="font-display text-2xl font-bold">Tiempo de permanencia</h1>
          <p className="text-sm text-muted">{d.course.code} · {d.course.title} · {d.required_seconds ? `mínimo ${hm(d.required_seconds)} de tiempo activo (${fmtNum(d.course.min_active_time_pct ?? 0, 0)} % de ${fmtNum(d.course.hours ?? 0, 1)} h)` : "el curso no exige permanencia mínima"}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link href={`/m/aul/${courseId}`} className="inline-flex h-10 items-center rounded-lg border border-line bg-surface px-4 text-sm font-semibold hover:border-brand">‹ Curso</Link>
          <Button icon="download" onClick={() => downloadFile(`/aul/admin/courses/${courseId}/time?format=csv`, `permanencia-${d.course.code}.csv`).catch((e) => setError(errorText(e)))}>Exportar CSV</Button>
        </div>
      </div>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Kpi label="Participantes" value={String(d.summary.participants)} />
        <Kpi label="Permanencia promedio" value={hm(d.summary.avg_active_seconds)} color="text-[#F08A24]" sub="Tiempo activo real" />
        <Kpi label="Cumplen el mínimo" value={d.required_seconds ? String(d.summary.complying) : "—"} color="text-ok" />
        <Kpi label="No cumplen" value={d.required_seconds ? String(d.summary.not_complying) : "—"} color="text-bad" />
      </div>
      {d.days.length > 0 && (
        <section className="rounded-xl border border-line bg-surface p-4">
          <h2 className="mb-3 font-display font-semibold">Tiempo activo por día (últimos 30 días)</h2>
          <div className="flex h-28 items-end gap-1" role="img" aria-label="Tiempo activo por día">
            {d.days.map((x) => (
              <span key={x.day} className="flex-1 rounded-t bg-brand/70" style={{ height: `${Math.max(4, (x.active_seconds * 100) / maxDay)}%` }} title={`${fmtDate(x.day)}: ${hm(x.active_seconds)} · ${x.participants} participante(s)`} />
            ))}
          </div>
        </section>
      )}
      <div role="tablist" aria-label="Filtrar participantes" className="inline-flex rounded-lg border border-line bg-surface p-0.5 text-sm">
        {([["all", "Todos"], ["ok", "Cumplen"], ["no", "No cumplen"]] as const).map(([v, l]) => (
          <button key={v} type="button" role="tab" aria-selected={seg === v} onClick={() => setSeg(v)} className={`rounded-md px-3 py-1.5 font-semibold ${seg === v ? "bg-brand text-white" : "text-muted hover:text-fg"}`}>{l}</button>
        ))}
      </div>
      {rows.length === 0 ? <div className="rounded-xl border border-line bg-surface p-8 text-center text-sm text-muted">Sin participantes en este filtro.</div> : (
        <div className="overflow-x-auto rounded-xl border border-line bg-surface">
          <table className="w-full min-w-[760px] text-sm">
            <thead className="bg-bg text-left text-xs text-muted">
              <tr>{["Participante", "Tiempo activo", "Cumplimiento", "Video visto", "Sesiones", "Días", "Última actividad", "Estado"].map((h) => <th key={h} className="px-3 py-2 font-semibold">{h}</th>)}</tr>
            </thead>
            <tbody className="divide-y divide-line">
              {rows.map((r) => {
                const [st, tone] = ENROLL_STATUS[r.status] ?? [r.status, "na" as ChipTone];
                return (
                  <tr key={r.enrollment_id}>
                    <td className="px-3 py-2"><b className="block">{r.name}</b><span className="text-xs text-muted">{[r.position, r.area].filter(Boolean).join(" · ") || "—"}</span></td>
                    <td className="px-3 py-2 tabular-nums">{hm(r.active_seconds)}</td>
                    <td className="px-3 py-2">
                      {r.compliance_pct === null ? <span className="text-muted">Sin mínimo</span> : (
                        <div className="w-32 space-y-1"><Bar pct={r.compliance_pct} tone={r.complies ? "ok" : "warn"} /><span className="text-xs">{fmtNum(r.compliance_pct, 0)} %{r.missing_seconds ? ` · faltan ${hm(r.missing_seconds)}` : ""}</span></div>
                      )}
                    </td>
                    <td className="px-3 py-2 tabular-nums">{hm(r.video_seconds)}</td>
                    <td className="px-3 py-2 text-xs">{r.sessions}{r.idle_closed ? ` · ${r.idle_closed} por inactividad` : ""}{r.flagged ? <span className="block text-bad">{r.flagged} con latidos fuera de secuencia</span> : null}</td>
                    <td className="px-3 py-2 tabular-nums">{r.days}</td>
                    <td className="px-3 py-2 text-xs">{fmtDate(r.last_at)}</td>
                    <td className="px-3 py-2"><Chip tone={tone}>{st}</Chip></td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
      <p className="rounded-xl border border-line bg-surface px-4 py-3 text-sm text-muted">
        <b className="text-fg">Cómo se mide.</b> Un latido cada {d.params.heartbeat_seconds} s mientras hay interacción y la pestaña está visible; tras {d.params.idle_minutes} min sin actividad la sesión se cierra y ese tiempo no suma. Abrir el curso en otra pestaña o equipo cierra la sesión anterior. Son parámetros de la empresa.
      </p>
    </div>
  );
}

// ─────────────── Por calificar (SCR-AUL-06 / 13) ───────────────

type Pending = { id: string; attempt_number: number; submitted_at: string; lesson: string; course_id: string; course_code: string; course_title: string; name: string };
type AttemptDetail = {
  id: string; attempt_number: number; submitted_at: string; lesson: string; practical: boolean; score: number; max_score: number; passing: number;
  questions: { id: string; type: string; statement: string; max: number; manual: boolean; auto_points: number; ok: boolean | null; answer: string; correct: string }[];
};

export function AulGrading({ readOnly }: { readOnly: boolean }) {
  const [items, setItems] = useState<Pending[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [open, setOpen] = useState<Pending | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ data: { items: Pending[] } }>("/aul/admin/grading", { withCompany: true }).then((r) => setItems(r.data.items)).catch((e) => setError(errorText(e)));
  }, []);
  useEffect(load, [load]);
  return (
    <div className="space-y-4">
      <AulCrumbs items={[{ label: "Por calificar" }]} />
      <div>
        <h1 className="font-display text-2xl font-bold">Por calificar</h1>
        <p className="text-sm text-muted">Evaluaciones prácticas y preguntas abiertas que enviaron los participantes de tus cursos. La nota usa la escala del curso.</p>
      </div>
      {error && <p className="rounded-lg bg-bad-soft px-4 py-2 text-sm text-bad">{error}</p>}
      {msg && <p role="status" className="rounded-lg bg-ok-soft px-4 py-2 text-sm text-ok">{msg}</p>}
      {!items ? <div className="h-32 animate-pulse rounded-xl bg-surface" /> : items.length === 0 ? (
        <div className="rounded-xl border border-line bg-surface p-8 text-center text-sm text-muted"><b className="block text-fg">Nada por calificar</b>Te avisaremos cuando un participante envíe una actividad.</div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-line bg-surface">
          <table className="w-full min-w-[620px] text-sm">
            <thead className="bg-bg text-left text-xs text-muted"><tr>{["Participante", "Curso", "Actividad", "Enviado", ""].map((h) => <th key={h} className="px-3 py-2 font-semibold">{h}</th>)}</tr></thead>
            <tbody className="divide-y divide-line">
              {items.map((p) => (
                <tr key={p.id}>
                  <td className="px-3 py-2 font-semibold">{p.name}</td>
                  <td className="px-3 py-2"><Link href={`/m/aul/${p.course_id}`} className="hover:text-brand"><span className="font-mono text-[11px] text-muted">{p.course_code}</span> {p.course_title}</Link></td>
                  <td className="px-3 py-2">{p.lesson} <span className="text-xs text-muted">· intento {p.attempt_number}</span></td>
                  <td className="px-3 py-2 text-xs">{fmtDate(p.submitted_at)}</td>
                  <td className="px-3 py-2 text-right">{!readOnly && <Button size="sm" variant="primary" onClick={() => setOpen(p)}>Calificar</Button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {open && <GradeModal pending={open} onClose={() => setOpen(null)} onDone={(t) => { setOpen(null); setMsg(t); load(); }} />}
    </div>
  );
}

function GradeModal({ pending, onClose, onDone }: { pending: Pending; onClose: () => void; onDone: (msg: string) => void }) {
  const [d, setD] = useState<AttemptDetail | null>(null);
  const [points, setPoints] = useState<Record<string, string>>({});
  const [pct, setPct] = useState("");
  const [feedback, setFeedback] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    api<{ data: AttemptDetail }>(`/aul/admin/attempts/${pending.id}`, { withCompany: true }).then((r) => setD(r.data)).catch((e) => setError(errorText(e)));
  }, [pending.id]);
  const manual = d?.questions.filter((q) => q.manual) ?? [];
  const total = d ? d.questions.reduce((a, q) => a + (q.manual ? Number((points[q.id] ?? "").replace(",", ".")) || 0 : q.auto_points), 0) : 0;
  const practical = !!d && (d.practical || manual.length === 0);
  const shown = practical ? Number(pct.replace(",", ".")) || 0 : d && d.max_score ? Math.round((total * 1000) / d.max_score) / 10 : 0;
  const save = async () => {
    setBusy(true);
    setError(null);
    try {
      const body = practical ? { score_pct: Number(pct.replace(",", ".")), feedback: feedback || null }
        : { question_points: Object.fromEntries(manual.map((q) => [q.id, Number((points[q.id] ?? "").replace(",", "."))])), feedback: feedback || null };
      const r = await api<{ data: { label: string; passed: boolean } }>(`/aul/admin/attempts/${pending.id}/grade`, { method: "POST", withCompany: true, body });
      onDone(`Nota ${r.data.label} registrada para ${pending.name}: ${r.data.passed ? "aprobado" : "desaprobado"}.`);
    } catch (e) {
      setError(errorText(e));
      setBusy(false);
    }
  };
  return (
    <Modal title={`Calificar · ${pending.name}`} subtitle={`${pending.lesson} · ${pending.course_title}`} icon="pen" size="lg" onClose={onClose}
      footer={<div className="flex w-full flex-wrap items-center justify-between gap-2"><span className="text-sm">Nota: <b>{fmtNum(shown, 1)} %</b>{d ? ` (mínimo ${d.passing} %)` : ""}</span><div className="flex gap-2"><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} disabled={!d} onClick={save}>Guardar nota</Button></div></div>}>
      {!d ? <div className="h-24 animate-pulse rounded-xl bg-bg" /> : (
        <div className="space-y-3">
          <ol className="space-y-2">
            {d.questions.map((q, i) => (
              <li key={q.id} className="space-y-1 rounded-xl border border-line p-3 text-sm">
                <b className="block">{i + 1}. {q.statement}</b>
                <p><span className="text-muted">Respuesta:</span> {q.answer || <i className="text-muted">sin responder</i>}</p>
                {q.manual ? (
                  !practical && (
                    <FormField label={`Puntos (de ${q.max})`}>
                      <input className="input max-w-28" inputMode="decimal" value={points[q.id] ?? ""} onChange={(e) => setPoints({ ...points, [q.id]: e.target.value })} />
                    </FormField>
                  )
                ) : <p className={`text-xs ${q.ok ? "text-ok" : "text-bad"}`}>{q.ok ? "Correcta" : "Incorrecta"} · {q.auto_points} de {q.max} pt (calificada sola){q.correct ? ` · correcta: ${q.correct}` : ""}</p>}
              </li>
            ))}
          </ol>
          {practical && <FormField label="Nota del intento (%) *" hint="Evaluación práctica: la nota la pone quien califica."><input className="input max-w-32" inputMode="decimal" value={pct} onChange={(e) => setPct(e.target.value)} /></FormField>}
          <FormField label="Comentario para el participante"><textarea className="input min-h-20" value={feedback} onChange={(e) => setFeedback(e.target.value)} /></FormField>
        </div>
      )}
      {error && <p className="mt-2 text-sm text-bad">{error}</p>}
    </Modal>
  );
}

// ─────────────── Estadística por pregunta (SCR-AUL-07) ───────────────

type StatRow = { question_id: string; code: string | null; statement: string; type: string; difficulty: string | null; attempts: number; correct_pct: number; blank: number; top_wrong: { text: string; count: number; pct: number } | null };
type ExamStats = { lesson_id: string; title: string; attempts: number; questions: StatRow[] };

export function useExamStats(courseId: string) {
  const [stats, setStats] = useState<ExamStats[] | null>(null);
  useEffect(() => {
    api<{ data: ExamStats[] }>(`/aul/admin/courses/${courseId}/exam-stats`, { withCompany: true }).then((r) => setStats(r.data)).catch(() => setStats([]));
  }, [courseId]);
  return stats;
}

export function QuestionStats({ stats }: { stats: ExamStats | undefined }) {
  const [all, setAll] = useState(false);
  if (!stats || stats.attempts === 0) return <p className="text-sm text-muted">Aún no hay intentos enviados: la estadística por pregunta aparece cuando los participantes rinden.</p>;
  const rows = all ? stats.questions : stats.questions.slice(0, 8);
  return (
    <div className="space-y-2">
      <p className="text-sm text-muted">{stats.attempts} intento(s) enviados. Primero las preguntas con menos aciertos.</p>
      <div className="overflow-x-auto rounded-xl border border-line">
        <table className="w-full min-w-[600px] text-sm">
          <thead className="bg-bg text-left text-xs text-muted"><tr>{["Pregunta", "Intentos", "Aciertos", "Incorrecta más elegida"].map((h) => <th key={h} className="px-3 py-2 font-semibold">{h}</th>)}</tr></thead>
          <tbody className="divide-y divide-line">
            {rows.map((q) => (
              <tr key={q.question_id}>
                <td className="px-3 py-2"><span className="font-mono text-[11px] text-muted">{q.code ?? ""}</span> {q.statement}<span className="block text-xs text-muted">{q.difficulty ? `Dificultad ${DIFF[q.difficulty] ?? q.difficulty}` : ""}{q.blank ? ` · ${q.blank} sin responder` : ""}</span></td>
                <td className="px-3 py-2 tabular-nums">{q.attempts}</td>
                <td className="px-3 py-2"><div className="w-28 space-y-1"><Bar pct={q.correct_pct} tone={q.correct_pct >= 70 ? "ok" : q.correct_pct >= 40 ? "warn" : "bad"} /><span className="text-xs tabular-nums">{fmtNum(q.correct_pct, 0)} %</span></div></td>
                <td className="px-3 py-2 text-xs">{q.top_wrong ? <>«{q.top_wrong.text}» <span className="text-muted">· {q.top_wrong.count} vez/veces ({fmtNum(q.top_wrong.pct, 0)} %)</span></> : "—"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {stats.questions.length > 8 && <Button size="sm" variant="ghost" onClick={() => setAll(!all)}>{all ? "Ver menos" : `Ver las ${stats.questions.length} preguntas`}</Button>}
    </div>
  );
}

// ─────────────── Acciones de una matrícula (SCR-AUL-05) ───────────────

export function EnrollmentAction({ kind, enrollment, exams, onClose, onDone }: {
  kind: "extend" | "reset"; enrollment: { id: string; name: string; access_until: string | null }; exams: { id: string; title: string }[]; onClose: () => void; onDone: () => void;
}) {
  const [until, setUntil] = useState(() => new Date(Date.now() + 15 * 86400000).toISOString().slice(0, 10));
  const [lesson, setLesson] = useState("");
  const [reason, setReason] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const save = async () => {
    setBusy(true);
    setErrors({});
    try {
      await api(`/aul/admin/enrollments/${enrollment.id}/${kind === "extend" ? "extend" : "reset-attempts"}`, {
        method: "POST", withCompany: true, body: kind === "extend" ? { access_until: until, reason } : { lesson_id: lesson || null, reason },
      });
      onDone();
    } catch (e) {
      setErrors((e as { errors?: Record<string, string[]> }).errors ?? { reason: [errorText(e)] });
      setBusy(false);
    }
  };
  return (
    <Modal title={kind === "extend" ? `Extender acceso · ${enrollment.name}` : `Reiniciar intentos · ${enrollment.name}`} icon={kind === "extend" ? "clock" : "refresh"} size="sm" onClose={onClose}
      subtitle="Queda registrado en la bitácora con tu nombre y el motivo."
      footer={<div className="flex w-full justify-end gap-2"><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} onClick={save}>{kind === "extend" ? "Extender acceso" : "Reiniciar intentos"}</Button></div>}>
      <div className="space-y-3">
        {kind === "extend" ? (
          <>
            <p className="text-sm text-muted">Acceso actual: {enrollment.access_until ? fmtDate(enrollment.access_until) : "sin fecha de fin"}.</p>
            <FormField label="Nuevo acceso hasta *" error={errors.access_until?.[0]}><input className="input" type="date" value={until} onChange={(e) => setUntil(e.target.value)} /></FormField>
          </>
        ) : (
          <FormField label="Evaluación" hint="Los intentos reiniciados no se borran: quedan en el historial.">
            <select className="input" value={lesson} onChange={(e) => setLesson(e.target.value)}>
              <option value="">Todas las evaluaciones del curso</option>
              {exams.map((x) => <option key={x.id} value={x.id}>{x.title}</option>)}
            </select>
          </FormField>
        )}
        <FormField label="Motivo *" error={errors.reason?.[0]}><textarea className="input min-h-20" value={reason} onChange={(e) => setReason(e.target.value)} placeholder={kind === "extend" ? "Ej.: estuvo de vacaciones" : "Ej.: se cortó la conexión durante el examen"} /></FormField>
      </div>
    </Modal>
  );
}
