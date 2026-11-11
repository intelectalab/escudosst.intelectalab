"use client";

// Recomendaciones del aula (M-AUL SCR-AUL-15..18): embudo sugerida → enviada → vista → clic → convertida, bandeja de
// recomendaciones por aprobar (aprobar o descartar), «Recomendar curso» a una persona, puesto o área (una por persona
// alcanzada; nunca a quien ya está matriculado o aprobó) y las reglas automáticas con su estado, cursos y parámetros;
// cada regla tiene «Vista previa» (a quiénes alcanzaría) y «Ejecutar ahora» (el job diario también la corre).

import { useCallback, useEffect, useState } from "react";
import { api } from "@/lib/api";
import { records } from "@/lib/records";
import { Button } from "@/components/ui/Button";
import { Chip, type ChipTone } from "@/components/ui/Display";
import { FormField } from "@/components/ui/Form";
import { Modal } from "@/components/ui/Modal";
import { Switch } from "@/components/ui/Switch";
import { Kpi } from "./Summary";
import { RefSelect } from "./RefSelect";
import { errorText } from "./Wizard";
import { AulCrumbs, type CourseCard } from "./AulAdmin";

type Funnel = { total: number; suggested: number; sent: number; viewed: number; clicked: number; converted: number; dismissed: number };
type Item = {
  id: string; status: string; source: string; target_scope: string; message: string | null; channel: string | null; sent_at: string | null; created_at: string; dismiss_reason: string | null;
  course_code: string; course_title: string; recipient: string | null; position: string | null; area: string | null; rule_name: string | null; recommended_by: string | null;
};
type Rule = {
  id: string; code: string; name: string; rule_type: string; audience: string; channels: string[]; is_active: boolean; max_sends_per_person: number; send_period_days: number;
  last_run_at: string | null; last_run_status: string | null; last_run_count: number | null; sent_total: number; course_ids: string[]; requires_consent: boolean;
  parameters: { lead_days?: number; requires_approval?: boolean; position_ids?: string[]; after_course_ids?: string[] };
};
type RunResult = { supported: boolean; reached: number; created: number; skipped: number; sent_external: number; portal_only: number; preview: { name: string; course: string; reason: string; channel: string }[] };
const SUPPORTED = ["certificate_expiring", "matrix_gap", "complementary", "learning_path_next"];
type Data = { funnel: Funnel; items: Item[]; rules: Rule[] };

const STATUS: Record<string, [string, ChipTone]> = {
  suggested: ["Por aprobar", "amber"], sent: ["Enviada", "brand"], viewed: ["Vista", "brand"], clicked: ["Clic", "brand"], converted: ["Convertida", "ok"], dismissed: ["Descartada", "na"], expired: ["Vencida", "na"],
};
const SOURCE: Record<string, string> = { rule: "Regla", manual: "Manual", ai: "Asistente" };
const RULE_TYPE: Record<string, string> = {
  matrix_gap: "Brecha de la matriz por puesto", certificate_expiring: "Certificado por vencer", learning_path_next: "Siguiente curso de la ruta", complementary: "Complementario del curso aprobado",
  inspection_finding: "Hallazgo de inspección del área", incident_related: "Accidente o incidente del área", similar_buyers: "Perfil similar de compra", manual: "Manual",
};
const AUDIENCE: Record<string, string> = { workers: "Trabajadores", external_learners: "Participantes externos", buyer_companies: "Empresas compradoras" };
const CHANNEL: Record<string, string> = { in_app: "Portal", email: "Correo", whatsapp: "WhatsApp" };
const fmtDate = (v: string | null) => (v ? new Date(v).toLocaleDateString("es-PE", { day: "2-digit", month: "2-digit", year: "numeric" }) : "—");

export function AulRecs({ readOnly }: { readOnly: boolean }) {
  const [d, setD] = useState<Data | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<"items" | "rules">("items");
  const [recommend, setRecommend] = useState(false);
  const [newRule, setNewRule] = useState<Rule | "new" | null>(null);
  const [run, setRun] = useState<{ rule: Rule; dry: boolean; res: RunResult } | null>(null);
  const [msg, setMsg] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ data: Data }>("/aul/admin/recommendations", { withCompany: true }).then((r) => setD(r.data)).catch((e) => setError(errorText(e)));
  }, []);
  useEffect(load, [load]);
  const act = (id: string, what: "approve" | "dismiss") =>
    api(`/aul/admin/recommendations/${id}/${what}`, { method: "POST", withCompany: true, body: {} }).then(load).catch((e) => setError(errorText(e)));
  const runRule = (r: Rule, dry: boolean) =>
    api<{ data: RunResult }>(`/aul/admin/rules/${r.id}/run`, { method: "POST", withCompany: true, body: { dry_run: dry } })
      .then((x) => { setRun({ rule: r, dry, res: x.data }); if (!dry) load(); }).catch((e) => setError(errorText(e)));
  const toggleRule = (r: Rule, on: boolean) => records.update("AUL", "recommendation_rules", r.id, { is_active: on }).then(load).catch((e) => setError(errorText(e)));
  const f = d?.funnel;
  const pending = (d?.items ?? []).filter((i) => i.status === "suggested");

  return (
    <div className="space-y-4">
      <AulCrumbs items={[{ label: "Recomendaciones" }]} />
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="font-display text-2xl font-bold">Recomendaciones de cursos</h1>
          <p className="text-sm text-muted">Cada recomendación muestra su motivo a la persona. Nunca se recomienda un curso que ya aprobó o en el que ya está matriculada, y no se usan datos médicos.</p>
        </div>
        {!readOnly && (
          <div className="flex flex-wrap gap-2">
            <Button icon="plus" onClick={() => setNewRule("new")}>Nueva regla</Button>
            <Button variant="primary" icon="star" onClick={() => setRecommend(true)}>Recomendar curso</Button>
          </div>
        )}
      </div>
      {error && <p className="rounded-lg bg-bad-soft px-4 py-2 text-sm text-bad">{error}</p>}
      {msg && <p role="status" className="rounded-lg bg-ok-soft px-4 py-2 text-sm text-ok">{msg}</p>}
      {!d || !f ? <div className="h-32 animate-pulse rounded-xl bg-surface" /> : (
        <>
          <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <Kpi label="Por aprobar" value={String(f.suggested)} color="text-amber-ink" />
            <Kpi label="Enviadas" value={String(f.sent)} />
            <Kpi label="Vistas" value={String(f.viewed)} />
            <Kpi label="Con clic" value={String(f.clicked)} />
            <Kpi label="Convertidas" value={String(f.converted)} color="text-ok" sub={f.sent ? `${Math.round((f.converted * 100) / f.sent)}% de las enviadas` : undefined} />
            <Kpi label="Descartadas" value={String(f.dismissed)} color="text-muted" />
          </div>
          <div role="tablist" aria-label="Recomendaciones" className="flex gap-1 border-b border-line">
            {([["items", `Recomendaciones${pending.length ? ` (${pending.length} por aprobar)` : ""}`], ["rules", `Reglas (${d.rules.length})`]] as const).map(([v, l]) => (
              <button key={v} type="button" role="tab" aria-selected={tab === v} onClick={() => setTab(v)}
                className={`border-b-2 px-3 py-2 text-sm font-semibold ${tab === v ? "border-brand text-brand-strong" : "border-transparent text-muted hover:text-fg"}`}>{l}</button>
            ))}
          </div>
          {tab === "items" ? (
            d.items.length === 0 ? <div className="rounded-xl border border-line bg-surface p-8 text-center text-sm text-muted">Aún no hay recomendaciones. Usa «Recomendar curso» o activa una regla.</div> : (
              <div className="overflow-x-auto rounded-xl border border-line bg-surface">
                <table className="w-full min-w-[720px] text-sm">
                  <thead className="bg-bg text-left text-xs text-muted">
                    <tr>{["Curso", "Para", "Origen", "Mensaje", "Estado", "Fecha", ""].map((h) => <th key={h} className="px-3 py-2 font-semibold">{h}</th>)}</tr>
                  </thead>
                  <tbody className="divide-y divide-line">
                    {d.items.map((i) => {
                      const [st, tone] = STATUS[i.status] ?? [i.status, "na" as ChipTone];
                      return (
                        <tr key={i.id}>
                          <td className="px-3 py-2"><span className="font-mono text-[11px] text-muted">{i.course_code}</span><b className="block">{i.course_title}</b></td>
                          <td className="px-3 py-2">{i.recipient ?? "—"}<span className="block text-xs text-muted">{i.target_scope === "position" ? `Por puesto: ${i.position}` : i.target_scope === "area" ? `Por área: ${i.area}` : ""}</span></td>
                          <td className="px-3 py-2 text-xs">{SOURCE[i.source] ?? i.source}{i.rule_name ? ` · ${i.rule_name}` : ""}{i.recommended_by ? ` · ${i.recommended_by}` : ""}</td>
                          <td className="max-w-56 px-3 py-2 text-xs">{i.message ?? "—"}{i.dismiss_reason ? <span className="block text-muted">Motivo: {i.dismiss_reason}</span> : null}</td>
                          <td className="px-3 py-2"><Chip tone={tone}>{st}</Chip>{i.channel && <span className="block pt-0.5 text-[11px] text-muted">{CHANNEL[i.channel] ?? i.channel}</span>}</td>
                          <td className="px-3 py-2 text-xs">{fmtDate(i.sent_at ?? i.created_at)}</td>
                          <td className="px-3 py-2 text-right">
                            {!readOnly && i.status === "suggested" && <Button size="sm" variant="primary" onClick={() => act(i.id, "approve")}>Aprobar</Button>}
                            {!readOnly && !["converted", "dismissed", "expired"].includes(i.status) && <Button size="sm" variant="ghost" onClick={() => act(i.id, "dismiss")}>Descartar</Button>}
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            )
          ) : d.rules.length === 0 ? <div className="rounded-xl border border-line bg-surface p-8 text-center text-sm text-muted">Aún no hay reglas de recomendación.</div> : (
            <ul className="grid gap-3 md:grid-cols-2">
              {d.rules.map((r) => (
                <li key={r.id} className="space-y-2 rounded-xl border border-line bg-surface p-4">
                  <div className="flex items-start justify-between gap-2">
                    <span><span className="font-mono text-[11px] text-muted">{r.code}</span><b className="block">{r.name}</b></span>
                    <Switch checked={r.is_active} disabled={readOnly} onChange={(v) => toggleRule(r, v)} label={r.is_active ? "Pausar regla" : "Activar regla"} />
                  </div>
                  <p className="text-sm">{RULE_TYPE[r.rule_type] ?? r.rule_type} · {AUDIENCE[r.audience] ?? r.audience}</p>
                  <p className="text-xs text-muted">
                    Canales: {r.channels.map((c) => CHANNEL[c] ?? c).join(", ")} · hasta {r.max_sends_per_person} envío(s) por persona cada {r.send_period_days} días · {r.sent_total} recomendación(es)
                    {r.last_run_at ? ` · última ejecución ${fmtDate(r.last_run_at)}${r.last_run_count != null ? ` (${r.last_run_count} nueva(s))` : ""}` : ""}
                    {r.parameters?.requires_approval ? " · requiere aprobación" : ""}
                  </p>
                  {!SUPPORTED.includes(r.rule_type) && <p className="text-xs text-amber-ink">Este tipo de regla aún no se evalúa automáticamente.</p>}
                  {!readOnly && (
                    <div className="flex flex-wrap gap-2 pt-1">
                      <Button size="sm" icon="pen" onClick={() => setNewRule(r)}>Editar</Button>
                      <Button size="sm" icon="eye" onClick={() => runRule(r, true)}>Vista previa</Button>
                      <Button size="sm" variant="primary" icon="refresh" onClick={() => runRule(r, false)}>Ejecutar ahora</Button>
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}
        </>
      )}
      {recommend && <RecommendModal onClose={() => setRecommend(false)} onDone={(t) => { setRecommend(false); setMsg(t); load(); }} />}
      {newRule && <RuleModal rule={newRule === "new" ? null : newRule} onClose={() => setNewRule(null)} onDone={() => { setNewRule(null); setTab("rules"); load(); }} />}
      {run && (
        <Modal title={`${run.dry ? "Vista previa" : "Ejecución"} · ${run.rule.name}`} icon={run.dry ? "eye" : "refresh"} size="md" onClose={() => setRun(null)}
          footer={<div className="flex w-full justify-end"><Button onClick={() => setRun(null)}>Cerrar</Button></div>}>
          {!run.res.supported ? <p className="text-sm text-muted">Este tipo de regla aún no se evalúa automáticamente.</p> : (
            <div className="space-y-3 text-sm">
              <p>{run.dry ? "Se crearían" : "Se crearon"} <b>{run.res.created}</b> recomendación(es) de {run.res.reached} persona(s) alcanzada(s); {run.res.skipped} ya matriculada(s), aprobada(s) o con la recomendación vigente.</p>
              <p className="text-muted">{run.res.sent_external} por correo o WhatsApp · {run.res.portal_only} solo en el portal (sin consentimiento de publicidad o con el límite de envíos alcanzado).</p>
              {run.dry && run.res.preview.length > 0 && (
                <ul className="max-h-64 divide-y divide-line overflow-y-auto rounded-xl border border-line">
                  {run.res.preview.map((x, i) => <li key={i} className="px-3 py-2"><b>{x.name}</b> · {x.course}<span className="block text-xs text-muted">{x.reason} · {CHANNEL[x.channel] ?? x.channel}</span></li>)}
                </ul>
              )}
            </div>
          )}
        </Modal>
      )}
    </div>
  );
}

function RecommendModal({ onClose, onDone }: { onClose: () => void; onDone: (msg: string) => void }) {
  const [courses, setCourses] = useState<CourseCard[] | null>(null);
  const [f, setF] = useState({ course_id: "", target_scope: "person", worker_id: "", position_id: "", area_id: "", message: "", channel: "in_app" });
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    api<{ data: { courses: CourseCard[] } }>("/aul/admin/overview", { withCompany: true }).then((r) => setCourses(r.data.courses.filter((c) => c.status === "published"))).catch(() => setCourses([]));
  }, []);
  const set = (p: Partial<typeof f>) => setF((x) => ({ ...x, ...p }));
  const go = async () => {
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ data: { created: number; enrolled: number; approved: number; duplicated: number; reached: number } }>("/aul/admin/recommendations", {
        method: "POST", withCompany: true,
        body: { course_id: f.course_id, target_scope: f.target_scope, worker_id: f.worker_id || null, position_id: f.position_id || null, area_id: f.area_id || null, message: f.message || null, channel: f.channel },
      });
      const x = r.data;
      const skipped = [x.enrolled && `${x.enrolled} ya matriculado(s)`, x.approved && `${x.approved} ya aprobado(s)`, x.duplicated && `${x.duplicated} ya la tenía(n)`].filter(Boolean).join(", ");
      onDone(`Recomendación enviada a ${x.created} persona(s)${skipped ? `; no se envió a ${skipped}` : ""}.`);
    } catch (e) {
      setError(errorText(e));
      setBusy(false);
    }
  };
  return (
    <Modal title="Recomendar curso" subtitle="Se crea una recomendación por persona alcanzada, con su propio seguimiento." icon="star" size="md" onClose={onClose}
      footer={<div className="flex w-full justify-end gap-2"><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} disabled={!f.course_id} onClick={go}>Recomendar</Button></div>}>
      <div className="space-y-3">
        <FormField label="Curso *">
          <select className="input" value={f.course_id} onChange={(e) => set({ course_id: e.target.value })}>
            <option value="">{courses === null ? "Cargando…" : courses.length ? "Elige un curso publicado" : "No hay cursos publicados"}</option>
            {(courses ?? []).map((c) => <option key={c.id} value={c.id}>{c.code} · {c.title}</option>)}
          </select>
        </FormField>
        <FormField label="Para">
          <div role="radiogroup" className="flex flex-wrap gap-1.5">
            {([["person", "Una persona"], ["position", "Un puesto"], ["area", "Un área"]] as const).map(([v, l]) => (
              <button key={v} type="button" role="radio" aria-checked={f.target_scope === v} onClick={() => set({ target_scope: v })}
                className={`rounded-lg border px-3 py-1.5 text-sm font-semibold ${f.target_scope === v ? "border-brand bg-brand text-white" : "border-line hover:border-brand"}`}>{l}</button>
            ))}
          </div>
        </FormField>
        {f.target_scope === "person" && <FormField label="Trabajador *"><RefSelect module="AUL" table="course_recommendations" field="worker_id" label="Trabajador" value={f.worker_id} onChange={(v) => set({ worker_id: v })} /></FormField>}
        {f.target_scope === "position" && <FormField label="Puesto *"><RefSelect module="AUL" table="course_recommendations" field="target_position_id" label="Puesto" value={f.position_id} onChange={(v) => set({ position_id: v })} /></FormField>}
        {f.target_scope === "area" && <FormField label="Área *"><RefSelect module="AUL" table="course_recommendations" field="target_area_id" label="Área" value={f.area_id} onChange={(v) => set({ area_id: v })} /></FormField>}
        <FormField label="Mensaje (opcional)" hint="Se muestra junto al motivo de la recomendación."><textarea className="input min-h-16" maxLength={500} value={f.message} onChange={(e) => set({ message: e.target.value })} /></FormField>
        <FormField label="Canal">
          <select className="input" value={f.channel} onChange={(e) => set({ channel: e.target.value })}>{Object.entries(CHANNEL).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
        </FormField>
        {error && <p className="text-sm text-bad">{error}</p>}
      </div>
    </Modal>
  );
}

function RuleModal({ rule, onClose, onDone }: { rule: Rule | null; onClose: () => void; onDone: () => void }) {
  const [courses, setCourses] = useState<CourseCard[] | null>(null);
  const [f, setF] = useState({
    code: rule?.code ?? "", name: rule?.name ?? "", rule_type: rule?.rule_type ?? "certificate_expiring", audience: rule?.audience ?? "workers", channels: rule?.channels ?? ["in_app"],
    max_sends_per_person: String(rule?.max_sends_per_person ?? 1), send_period_days: String(rule?.send_period_days ?? 30), lead_days: String(rule?.parameters?.lead_days ?? 30),
    requires_approval: !!rule?.parameters?.requires_approval, requires_consent: rule?.requires_consent ?? true, course_ids: rule?.course_ids ?? [], after_course_ids: rule?.parameters?.after_course_ids ?? [],
  });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    api<{ data: { courses: CourseCard[] } }>("/aul/admin/overview", { withCompany: true }).then((r) => setCourses(r.data.courses)).catch(() => setCourses([]));
  }, []);
  const set = (p: Partial<typeof f>) => setF((x) => ({ ...x, ...p }));
  const chained = f.rule_type === "complementary" || f.rule_type === "learning_path_next";
  const save = async () => {
    setBusy(true);
    setErrors({});
    try {
      await api(rule ? `/aul/admin/rules/${rule.id}` : "/aul/admin/rules", {
        method: rule ? "PUT" : "POST", withCompany: true,
        body: {
          code: f.code, name: f.name, rule_type: f.rule_type, audience: f.audience, channels: f.channels, max_sends_per_person: Number(f.max_sends_per_person) || 1,
          send_period_days: Number(f.send_period_days) || 30, requires_consent: f.requires_consent, is_active: rule?.is_active ?? false, course_ids: f.course_ids,
          parameters: { requires_approval: f.requires_approval, ...(f.rule_type === "certificate_expiring" ? { lead_days: Number(f.lead_days) || 30 } : {}), ...(chained ? { after_course_ids: f.after_course_ids } : {}) },
        },
      });
      onDone();
    } catch (e) {
      setErrors((e as { errors?: Record<string, string[]> }).errors ?? { name: [errorText(e)] });
      setBusy(false);
    }
  };
  return (
    <Modal title={rule ? "Editar regla de recomendación" : "Nueva regla de recomendación"} subtitle={rule ? undefined : "Se crea en pausa; actívala cuando la revises."} icon={rule ? "pen" : "plus"} size="md" onClose={onClose}
      footer={<div className="flex w-full justify-end gap-2"><Button onClick={onClose}>Cancelar</Button><Button variant="primary" loading={busy} onClick={save}>{rule ? "Guardar regla" : "Crear regla"}</Button></div>}>
      <div className="space-y-3">
        <div className="grid gap-3 sm:grid-cols-2">
          <FormField label="Código *" error={errors.code?.[0]}><input className="input" value={f.code} onChange={(e) => set({ code: e.target.value.toUpperCase() })} placeholder="REC-01" /></FormField>
          <FormField label="Nombre *" error={errors.name?.[0]}><input className="input" value={f.name} onChange={(e) => set({ name: e.target.value })} /></FormField>
          <FormField label="Tipo de regla" error={errors.rule_type?.[0]}>
            <select className="input" value={f.rule_type} onChange={(e) => set({ rule_type: e.target.value })}>{Object.entries(RULE_TYPE).filter(([k]) => k !== "manual").map(([k, l]) => <option key={k} value={k}>{l}{SUPPORTED.includes(k) ? "" : " (próximamente)"}</option>)}</select>
          </FormField>
          <FormField label="Audiencia" error={errors.audience?.[0]}>
            <select className="input" value={f.audience} onChange={(e) => set({ audience: e.target.value })}>{Object.entries(AUDIENCE).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
          </FormField>
          {f.rule_type === "certificate_expiring" && <FormField label="Anticipación (días)"><input className="input" inputMode="numeric" value={f.lead_days} onChange={(e) => set({ lead_days: e.target.value })} /></FormField>}
          <FormField label="Envíos por persona"><input className="input" inputMode="numeric" value={f.max_sends_per_person} onChange={(e) => set({ max_sends_per_person: e.target.value })} /></FormField>
          <FormField label="Cada (días)"><input className="input" inputMode="numeric" value={f.send_period_days} onChange={(e) => set({ send_period_days: e.target.value })} /></FormField>
        </div>
        {chained && <FormField label="Para quienes aprobaron *" hint="Curso o cursos ya aprobados."><CoursePick courses={courses} value={f.after_course_ids} onChange={(v) => set({ after_course_ids: v })} /></FormField>}
        <FormField label={chained ? "Cursos que se recomiendan *" : "Cursos de la regla (opcional)"} hint={chained ? undefined : f.rule_type === "matrix_gap" ? "Vacío: todos los cursos que exige cada puesto." : "Vacío: todos los cursos con certificado."} error={errors.course_ids?.[0]}>
          <CoursePick courses={courses} value={f.course_ids} onChange={(v) => set({ course_ids: v })} />
        </FormField>
        <FormField label="Canales" error={errors.channels?.[0]}>
          <div className="flex flex-wrap gap-3 text-sm">
            {Object.entries(CHANNEL).map(([k, l]) => (
              <label key={k} className="flex items-center gap-1.5">
                <input type="checkbox" checked={f.channels.includes(k)} onChange={(e) => set({ channels: e.target.checked ? [...f.channels, k] : f.channels.filter((x) => x !== k) })} /> {l}
              </label>
            ))}
          </div>
        </FormField>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.requires_approval} onChange={(e) => set({ requires_approval: e.target.checked })} /> Requiere aprobación antes de publicarse</label>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.requires_consent} onChange={(e) => set({ requires_consent: e.target.checked })} /> Correo o WhatsApp a externos solo con consentimiento de publicidad</label>
        <p className="text-xs text-muted">Los filtros admitidos son puesto, área, sede, temas, certificados, matrículas y hallazgos; nunca datos médicos.</p>
      </div>
    </Modal>
  );
}

function CoursePick({ courses, value, onChange }: { courses: CourseCard[] | null; value: string[]; onChange: (v: string[]) => void }) {
  return (
    <div className="max-h-36 space-y-1 overflow-y-auto rounded-lg border border-line p-2 text-sm">
      {courses === null ? <span className="text-xs text-muted">Cargando…</span> : courses.length === 0 ? <span className="text-xs text-muted">No hay cursos.</span> : courses.map((c) => (
        <label key={c.id} className="flex items-center gap-2">
          <input type="checkbox" checked={value.includes(c.id)} onChange={(e) => onChange(e.target.checked ? [...value, c.id] : value.filter((x) => x !== c.id))} /> {c.code} · {c.title}
        </label>
      ))}
    </div>
  );
}
