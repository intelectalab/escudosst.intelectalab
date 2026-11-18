"use client";

// Informe de simulacro (M-SIM, salidas): datos, tiempos frente a la meta de la sede, asistencia, quiénes no participaron
// y la evaluación por criterios con sus observaciones. Se guarda como PDF desde el diálogo de impresión.

import { cell, Facts, fmtDT, ReportLoading, Section, Sheet, useReport, type Company } from "./FieldReports";

type Crit = { name: string; met: boolean | null; note: string | null };
type Report = {
  company: Company;
  drill: { code: string | null; title: string; status: string; site: string | null; alarm_time: string | null; evacuation_complete_time: string | null; evacuation_time_seconds: number | null };
  target_seconds: number | null; met: boolean | null; not_participated: string[];
  details: { drill_type: string; scenario: string | null; objectives: string | null; is_announced: boolean | null; executed_at: string | null; result: string | null;
    external_entity: string | null; participants_count: number | null };
  present: { full_name: string; role: string | null }[];
  evaluation: { criteria?: Crit[]; observations?: string | null } | null;
};

const TYPE: Record<string, string> = { earthquake: "Sismo", fire: "Incendio", evacuation: "Evacuación", first_aid: "Primeros auxilios", chemical_spill: "Derrame químico",
  rescue: "Rescate", tsunami: "Tsunami", other: "Otro" };
const RESULT: Record<string, string> = { satisfactory: "Satisfactorio", needs_improvement: "Por mejorar", unsatisfactory: "No satisfactorio" };
const ROLE: Record<string, string> = { participant: "Participante", brigadist: "Brigadista", observer: "Observador", evaluator: "Evaluador" };
const mmss = (s: number | null) => (s == null ? "—" : `${Math.floor(s / 60)} min ${String(s % 60).padStart(2, "0")} s`);

export function DrillReport({ id }: { id: string }) {
  const { data, error } = useReport<Report>(`/sim/drills/${id}/report`);
  if (!data) return <ReportLoading error={error} />;
  const d = data.details;
  const crit = data.evaluation?.criteria ?? [];

  return (
    <Sheet company={data.company} title="Informe de simulacro" sub={[data.drill.code, data.drill.title].filter(Boolean).join(" · ")}
      draft={data.drill.status !== "evaluated" ? "Borrador: el simulacro aún no está evaluado" : null}>
      <Section n={1} title="Datos del simulacro">
        <Facts rows={[
          ["Tipo", TYPE[d.drill_type] ?? d.drill_type],
          ["Sede", data.drill.site],
          ["Fecha de ejecución", fmtDT(d.executed_at)],
          ["Aviso previo", d.is_announced == null ? "—" : d.is_announced ? "Avisado" : "Sin aviso"],
          ["Entidad externa", d.external_entity],
          ["Escenario", d.scenario],
          ["Objetivos", d.objectives],
        ]} />
      </Section>
      <Section n={2} title="Tiempos">
        <Facts rows={[
          ["Alarma", fmtDT(data.drill.alarm_time)],
          ["Evacuación completa", fmtDT(data.drill.evacuation_complete_time)],
          ["Tiempo de evacuación", mmss(data.drill.evacuation_time_seconds)],
          ["Meta de la sede", data.target_seconds == null ? "Sin meta" : mmss(data.target_seconds)],
          ["Frente a la meta", data.met == null ? "—" : data.met ? "Dentro de la meta" : "Fuera de la meta"],
        ]} />
      </Section>
      <Section n={3} title={`Asistencia (${data.present.length})`}>
        {data.present.length === 0 ? <p className="text-sm text-gray-600">Sin asistencia registrada.</p> : (
          <table className="w-full border-collapse text-sm">
            <thead><tr className="bg-gray-100"><th className={cell}>N.°</th><th className={cell}>Trabajador</th><th className={cell}>Rol</th></tr></thead>
            <tbody>{data.present.map((p, i) => <tr key={i}><td className={cell}>{i + 1}</td><td className={cell}>{p.full_name}</td><td className={cell}>{ROLE[p.role ?? ""] ?? p.role ?? "—"}</td></tr>)}</tbody>
          </table>
        )}
        {data.not_participated.length > 0 && <p className="text-sm"><b>No participaron ({data.not_participated.length}):</b> {data.not_participated.join(", ")}</p>}
      </Section>
      <Section n={4} title="Evaluación">
        <Facts rows={[["Resultado", d.result ? RESULT[d.result] ?? d.result : "Sin evaluar"]]} />
        {crit.length > 0 && (
          <table className="w-full border-collapse text-sm">
            <thead><tr className="bg-gray-100"><th className={cell}>Criterio</th><th className={cell}>Resultado</th><th className={cell}>Nota</th></tr></thead>
            <tbody>{crit.map((c) => <tr key={c.name}><td className={cell}>{c.name}</td><td className={cell}>{c.met == null ? "Sin evaluar" : c.met ? "Cumple" : "No cumple"}</td><td className={cell}>{c.note ?? ""}</td></tr>)}</tbody>
          </table>
        )}
        {data.evaluation?.observations && <p className="whitespace-pre-line text-sm"><b>Observaciones:</b> {data.evaluation.observations}</p>}
      </Section>
    </Sheet>
  );
}
