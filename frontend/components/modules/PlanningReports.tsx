"use client";

// Informes imprimibles de planificación (§11 de las fichas), con el formato de los demás informes:
// política con sus objetivos y metas (M-POL), Plan Anual (M-PLA), Programa Anual con cronograma (M-PAS),
// línea base por lineamiento con brechas (M-LBA) y matriz de requisitos legales (M-MRL).

import { safeHtml } from "@/lib/workerApp";
import { Facts, Section, Sheet, ReportLoading, cell, fmtD, fmtDT, useReport, type Company } from "./FieldReports";

type Sig = { signer_name: string; signed_at: string; method: string; image: string | null };
const MONTHS = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Set", "Oct", "Nov", "Dic"];
const DOC_STATUS: Record<string, string> = { draft: "Borrador", in_review: "En revisión", approved: "Aprobado", current: "Vigente", obsolete: "Obsoleto", closed: "Cerrado",
  committee_review: "Revisión del comité", in_execution: "En ejecución", in_progress: "En curso", completed: "Completado", archived: "Archivado" };
const RESULT: Record<string, string> = { compliant: "Cumple", partial: "Parcial", non_compliant: "No cumple", not_applicable: "No aplica", not_evaluated: "Sin evaluar" };
const meetingText = (m: { number: number | null; held_at: string | null; scheduled_at: string | null } | null) =>
  m ? `Sesión N.° ${m.number ?? "—"} del ${fmtD(m.held_at ?? m.scheduled_at)}` : "—";

function SigBlock({ list }: { list: Sig[] }) {
  if (!list.length) return <p className="text-sm text-gray-600">Sin firmas registradas.</p>;
  return (
    <div className="grid grid-cols-2 gap-3">
      {list.map((s, i) => (
        <div key={i} className="rounded border border-gray-300 p-2 text-sm" style={{ breakInside: "avoid" }}>
          {s.image ? <img src={s.image} alt={`Firma de ${s.signer_name}`} className="h-14 object-contain" /> : <div className="h-14" />}
          <p className="border-t border-gray-300 pt-1 font-semibold">{s.signer_name}</p>
          <p className="text-xs text-gray-600">{fmtDT(s.signed_at)}</p>
        </div>
      ))}
    </div>
  );
}

// ─────────────── Política

type Pol = {
  company: Company;
  policy: { title: string; version_number: number; status: string; content_html: string | null; effective_on: string | null; review_due_on: string | null; approved_at: string | null; approved_by: string | null; prepared_by: string | null };
  objectives: { code: string; title: string; indicator: string | null; baseline_value: number | null; target_value: number | null; unit: string | null; status: string; responsible: string | null;
    targets: { period_label: string | null; target_on: string | null; target_value: number | null; actual_value: number | null; achievement_pct: number | null; status: string }[] }[];
  signatures: Sig[];
};
const TARGET: Record<string, string> = { pending: "Pendiente", on_track: "En camino", at_risk: "En riesgo", achieved: "Lograda", missed: "No lograda" };

export function PolicyReport({ id }: { id: string }) {
  const { data: d, error } = useReport<Pol>(`/reports/sst_policies/${id}`);
  if (!d) return <ReportLoading error={error} />;
  const p = d.policy;
  return (
    <Sheet company={d.company} title={p.title || "Política de Seguridad y Salud en el Trabajo"} sub={`Versión ${p.version_number} · ${DOC_STATUS[p.status] ?? p.status}`}
      draft={["current", "approved", "obsolete"].includes(p.status) ? null : "BORRADOR · la política aún no está aprobada"}>
      <Section n={1} title="Texto de la política">
        <div className="prose max-w-none text-sm" dangerouslySetInnerHTML={{ __html: safeHtml(p.content_html ?? "") }} />
      </Section>
      <Section n={2} title="Aprobación">
        <Facts rows={[["Elaborada por", p.prepared_by], ["Aprobada por", p.approved_by], ["Fecha de aprobación", fmtD(p.approved_at)], ["Vigente desde", fmtD(p.effective_on)], ["Próxima revisión", fmtD(p.review_due_on)]]} />
        <SigBlock list={d.signatures} />
      </Section>
      <Section n={3} title="Objetivos y metas">
        {d.objectives.length === 0 ? <p className="text-sm text-gray-600">Sin objetivos registrados.</p> : (
          <table className="w-full border-collapse text-sm">
            <thead><tr className="bg-gray-100"><th className={cell}>Objetivo</th><th className={cell}>Indicador y meta</th><th className={cell}>Responsable</th><th className={cell}>Avance de metas</th></tr></thead>
            <tbody>
              {d.objectives.map((o) => (
                <tr key={o.code} style={{ breakInside: "avoid" }}>
                  <td className={cell}><b>{o.code}</b> · {o.title}</td>
                  <td className={cell}>{o.indicator ?? "—"}{o.target_value != null ? <span className="block text-xs text-gray-600">Meta: {o.target_value} {o.unit ?? ""}{o.baseline_value != null ? ` (base ${o.baseline_value})` : ""}</span> : null}</td>
                  <td className={cell}>{o.responsible ?? "—"}</td>
                  <td className={cell}>{o.targets.length === 0 ? "—" : o.targets.map((t, i) => (
                    <span key={i} className="block">{t.period_label ?? fmtD(t.target_on)}: {t.actual_value ?? "—"} / {t.target_value ?? "—"} · {TARGET[t.status] ?? t.status}</span>
                  ))}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Section>
    </Sheet>
  );
}

// ─────────────── Plan Anual

type Plan = {
  company: Company;
  plan: { year: number; title: string; status: string; objectives_summary: string | null; resources_text: string | null; approved_at: string | null; approved_by: string | null;
    budget: { title: string; total_amount: number | null; currency: string | null } | null; meeting: { number: number | null; held_at: string | null; scheduled_at: string | null } | null };
  items: { code: string | null; description: string; goal: string | null; indicator: string | null; resources: string | null; starts_on: string | null; ends_on: string | null; responsible: string | null; objective_code: string | null; objective: string | null }[];
  signatures: Sig[];
};

export function PlanReport({ id }: { id: string }) {
  const { data: d, error } = useReport<Plan>(`/reports/annual_plans/${id}`);
  if (!d) return <ReportLoading error={error} />;
  const p = d.plan;
  return (
    <Sheet company={d.company} title={`Plan Anual de Seguridad y Salud en el Trabajo ${p.year}`} sub={`${p.title} · ${DOC_STATUS[p.status] ?? p.status}`}
      draft={["approved", "current", "closed"].includes(p.status) ? null : "BORRADOR · el plan aún no está aprobado"}>
      <Section n={1} title="Datos del plan">
        <Facts rows={[["Año", String(p.year)], ["Objetivos generales", p.objectives_summary], ["Recursos", p.resources_text],
          ["Presupuesto", p.budget ? `${p.budget.title}${p.budget.total_amount != null ? ` · ${p.budget.currency ?? "PEN"} ${Number(p.budget.total_amount).toLocaleString("es-PE")}` : ""}` : "—"],
          ["Aprobación", `${p.approved_by ?? "—"} · ${fmtD(p.approved_at)}`], ["Acta de aprobación", meetingText(p.meeting)]]} />
      </Section>
      <Section n={2} title="Objetivos y actividades">
        {d.items.length === 0 ? <p className="text-sm text-gray-600">Sin actividades registradas.</p> : (
          <table className="w-full border-collapse text-sm">
            <thead><tr className="bg-gray-100"><th className={cell}>Objetivo</th><th className={cell}>Actividad</th><th className={cell}>Meta e indicador</th><th className={cell}>Responsable</th><th className={cell}>Plazo</th></tr></thead>
            <tbody>{d.items.map((i, k) => (
              <tr key={k} style={{ breakInside: "avoid" }}>
                <td className={cell}>{i.objective_code ? `${i.objective_code} · ${i.objective}` : "—"}</td>
                <td className={cell}>{i.code ? <b>{i.code} </b> : null}{i.description}{i.resources ? <span className="block text-xs text-gray-600">Recursos: {i.resources}</span> : null}</td>
                <td className={cell}>{i.goal ?? "—"}{i.indicator ? <span className="block text-xs text-gray-600">{i.indicator}</span> : null}</td>
                <td className={cell}>{i.responsible ?? "—"}</td>
                <td className={cell}>{fmtD(i.starts_on)} a {fmtD(i.ends_on)}</td>
              </tr>
            ))}</tbody>
          </table>
        )}
      </Section>
      <Section n={3} title="Firmas"><SigBlock list={d.signatures} /></Section>
    </Sheet>
  );
}

// ─────────────── Programa Anual (PASST)

type Prog = {
  company: Company;
  program: { year: number; title: string; status: string; progress_pct: number | null; approved_at: string | null; approved_by: string | null; meeting: { number: number | null; held_at: string | null; scheduled_at: string | null } | null };
  activities: { code: string | null; title: string; activity_type: string | null; planned_months: number[]; executed_months: number[]; goal_quantity: number | null; executed_quantity: number | null; status: string; progress_pct: number | null; responsible: string | null }[];
  totals: { planned: number; executed: number; pct: number | null };
  signatures: Sig[];
};

export function ProgramReport({ id }: { id: string }) {
  const { data: d, error } = useReport<Prog>(`/reports/annual_programs/${id}`);
  if (!d) return <ReportLoading error={error} />;
  const p = d.program;
  return (
    <Sheet company={d.company} title={`Programa Anual de Seguridad y Salud en el Trabajo ${p.year}`} sub={`${p.title} · ${DOC_STATUS[p.status] ?? p.status}`}
      draft={["approved", "in_execution", "closed"].includes(p.status) ? null : "BORRADOR · el programa aún no está aprobado"}>
      <Section n={1} title="Datos y avance">
        <Facts rows={[["Año", String(p.year)], ["Aprobación", `${p.approved_by ?? "—"} · ${fmtD(p.approved_at)}`], ["Acta de aprobación", meetingText(p.meeting)],
          ["Avance (ejecutado / programado)", d.totals.planned ? `${d.totals.executed} de ${d.totals.planned} meses programados (${d.totals.pct} %)` : "—"]]} />
      </Section>
      <Section n={2} title="Cronograma">
        <p className="text-xs text-gray-600">P: programado · E: ejecutado · X: programado y sin ejecutar en un mes ya pasado.</p>
        <table className="w-full border-collapse text-[11px]">
          <thead><tr className="bg-gray-100"><th className={cell}>Actividad</th><th className={cell}>Responsable</th>{MONTHS.map((m) => <th key={m} className={`${cell} text-center`}>{m}</th>)}<th className={cell}>Avance</th></tr></thead>
          <tbody>{d.activities.map((a, k) => {
            const now = new Date();
            return (
              <tr key={k} style={{ breakInside: "avoid" }}>
                <td className={cell}>{a.code ? <b>{a.code} </b> : null}{a.title}</td>
                <td className={cell}>{a.responsible ?? "—"}</td>
                {MONTHS.map((_, i) => {
                  const m = i + 1;
                  const planned = a.planned_months.includes(m);
                  const done = a.executed_months.includes(m);
                  const late = planned && !done && (p.year < now.getFullYear() || (p.year === now.getFullYear() && m < now.getMonth() + 1));
                  return <td key={m} className={`${cell} text-center ${done ? "bg-green-100 font-bold" : late ? "bg-red-100 font-bold text-red-700" : planned ? "bg-blue-50" : ""}`}>{done ? "E" : late ? "X" : planned ? "P" : ""}</td>;
                })}
                <td className={cell}>{a.progress_pct != null ? `${Math.round(a.progress_pct)} %` : "—"}</td>
              </tr>
            );
          })}</tbody>
        </table>
      </Section>
      <Section n={3} title="Firmas"><SigBlock list={d.signatures} /></Section>
    </Sheet>
  );
}

// ─────────────── Línea base

type Base = {
  company: Company;
  assessment: { title: string; reference_norm: string | null; assessment_on: string | null; status: string; is_initial: boolean; score_pct: number | null; total_items: number; compliant_items: number;
    evaluator: string | null; site: string | null; approved_at: string | null; approved_by: string | null };
  lineaments: { lineament_code: string; lineament_name: string; total: number; compliant: number; partial: number; non_compliant: number }[];
  gaps: { description: string; priority: string | null; due_on: string | null; status: string; responsible: string | null; action_code: string | null }[];
  signatures: Sig[];
};
const GAP: Record<string, string> = { open: "Abierta", in_progress: "En curso", closed: "Cerrada", accepted_risk: "Riesgo aceptado" };

export function BaselineReport({ id }: { id: string }) {
  const { data: d, error } = useReport<Base>(`/reports/baseline_assessments/${id}`);
  if (!d) return <ReportLoading error={error} />;
  const a = d.assessment;
  return (
    <Sheet company={d.company} title={`Informe de línea base${a.is_initial ? " (evaluación inicial)" : ""}`} sub={`${a.title} · ${DOC_STATUS[a.status] ?? a.status}`}
      draft={a.status === "approved" ? null : "BORRADOR · el diagnóstico aún no está aprobado"}>
      <Section n={1} title="Datos de la evaluación">
        <Facts rows={[["Norma de referencia", a.reference_norm], ["Fecha", fmtD(a.assessment_on)], ["Sede", a.site ?? "Toda la empresa"], ["Evaluador", a.evaluator],
          ["Resultado", a.score_pct != null ? `${a.score_pct} % (${a.compliant_items} de ${a.total_items} ítems cumplen)` : "—"], ["Aprobación", `${a.approved_by ?? "—"} · ${fmtD(a.approved_at)}`]]} />
      </Section>
      <Section n={2} title="Resultados por lineamiento">
        <table className="w-full border-collapse text-sm">
          <thead><tr className="bg-gray-100"><th className={cell}>Lineamiento</th><th className={cell}>Ítems</th><th className={cell}>Cumple</th><th className={cell}>Parcial</th><th className={cell}>No cumple</th><th className={cell}>%</th></tr></thead>
          <tbody>{d.lineaments.map((l) => (
            <tr key={l.lineament_code}><td className={cell}>{l.lineament_code} · {l.lineament_name}</td><td className={cell}>{l.total}</td><td className={cell}>{l.compliant}</td><td className={cell}>{l.partial}</td><td className={cell}>{l.non_compliant}</td>
              <td className={cell}>{l.total ? Math.round((l.compliant * 100) / l.total) : 0} %</td></tr>
          ))}</tbody>
        </table>
      </Section>
      <Section n={3} title="Brechas y plan de cierre">
        {d.gaps.length === 0 ? <p className="text-sm text-gray-600">Sin brechas registradas.</p> : (
          <table className="w-full border-collapse text-sm">
            <thead><tr className="bg-gray-100"><th className={cell}>Brecha</th><th className={cell}>Prioridad</th><th className={cell}>Responsable</th><th className={cell}>Fecha meta</th><th className={cell}>Estado</th></tr></thead>
            <tbody>{d.gaps.map((g, k) => (
              <tr key={k} style={{ breakInside: "avoid" }}><td className={cell}>{g.description}{g.action_code ? <span className="block text-xs text-gray-600">Acción {g.action_code}</span> : null}</td>
                <td className={cell}>{g.priority ?? "—"}</td><td className={cell}>{g.responsible ?? "—"}</td><td className={cell}>{fmtD(g.due_on)}</td><td className={cell}>{GAP[g.status] ?? g.status}</td></tr>
            ))}</tbody>
          </table>
        )}
      </Section>
      <Section n={4} title="Firmas"><SigBlock list={d.signatures} /></Section>
    </Sheet>
  );
}

// ─────────────── Matriz legal

type Legal = {
  company: Company;
  rows: { norm_type: string; number: string | null; norm: string; article: string | null; requirement: string; applies: boolean | null; frequency: string | null; next_evaluation_on: string | null;
    status: string; responsible: string | null; site: string | null; last_result: string | null; last_evaluated_on: string | null }[];
  counts: Record<string, number>;
  generated_at: string;
};

export function LegalMatrixReport() {
  const { data: d, error } = useReport<Legal>("/reports/legal_matrix");
  if (!d) return <ReportLoading error={error} />;
  return (
    <Sheet company={d.company} title="Matriz de requisitos legales" sub={`Generada el ${fmtDT(d.generated_at)}`}>
      <Section n={1} title="Resumen del cumplimiento">
        <Facts rows={(["compliant", "partial", "non_compliant", "not_applicable", "not_evaluated"] as const).map((k) => [RESULT[k], String(d.counts[k] ?? 0)] as [string, string])} />
      </Section>
      <Section n={2} title="Requisitos y último resultado">
        <table className="w-full border-collapse text-[11px]">
          <thead><tr className="bg-gray-100"><th className={cell}>Norma</th><th className={cell}>Artículo</th><th className={cell}>Requisito</th><th className={cell}>Responsable</th><th className={cell}>Último resultado</th><th className={cell}>Próxima evaluación</th></tr></thead>
          <tbody>{d.rows.map((r, k) => (
            <tr key={k} style={{ breakInside: "avoid" }}>
              <td className={cell}>{[r.norm_type, r.number].filter(Boolean).join(" ")}<span className="block text-gray-600">{r.norm}</span></td>
              <td className={cell}>{r.article ?? "—"}</td>
              <td className={cell}>{r.requirement}{r.applies === false ? <span className="block text-gray-600">No aplica</span> : null}</td>
              <td className={cell}>{r.responsible ?? "—"}{r.site ? <span className="block text-gray-600">{r.site}</span> : null}</td>
              <td className={cell}>{RESULT[r.last_result ?? "not_evaluated"] ?? r.last_result}{r.last_evaluated_on ? <span className="block text-gray-600">{fmtD(r.last_evaluated_on)}</span> : null}</td>
              <td className={cell}>{fmtD(r.next_evaluation_on)}</td>
            </tr>
          ))}</tbody>
        </table>
      </Section>
    </Sheet>
  );
}
