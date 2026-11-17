"use client";

// Informe de autodiagnóstico SUNAFIL (M-SUN §11): brechas por obligación, gravedad de la infracción y plan sugerido.

import { useParams } from "next/navigation";
import { Facts, Section, Sheet, ReportLoading, cell, fmtD, useReport } from "@/components/modules/FieldReports";

type Area = { workers?: string[]; workers_total?: number; code: string; name: string; module: string; pct: number | null; detail: string; result: string; severity: string; severity_label: string };
type Diag = {
  title: string; date: string; checklist_version: string; performed_by: string | null; company: { name: string; ruc: string };
  score: number | null; risk_weighted_score: number | null; very_serious_gaps: number; areas: Area[];
};
const RESULT: Record<string, string> = { complies: "Cumple", partial: "Parcial", fails: "No cumple", na: "No aplica" };
const RANK: Record<string, number> = { very_serious: 0, serious: 1, minor: 2 };

export default function ImprimirAutodiagnostico() {
  const { id } = useParams<{ id: string }>();
  const { data: d, error } = useReport<Diag>(`/sun/self-diagnostics/${id}`);
  if (!d) return <ReportLoading error={error} />;
  const gaps = d.areas.filter((a) => a.result === "fails" || a.result === "partial")
    .sort((a, b) => (RANK[a.severity] ?? 3) - (RANK[b.severity] ?? 3) || (a.pct ?? 0) - (b.pct ?? 0));
  return (
    <Sheet company={{ name: d.company.name, legal_name: d.company.name, ruc: d.company.ruc }} title="Informe de autodiagnóstico SUNAFIL"
      sub={`${fmtD(d.date)} · Catálogo ${d.checklist_version}${d.performed_by ? ` · Ejecutado por ${d.performed_by}` : ""}`}>
      <Section n={1} title="Resultado">
        <Facts rows={[
          ["Puntaje", d.score === null ? "—" : `${d.score} de 100`],
          ["Puntaje ponderado por gravedad", d.risk_weighted_score === null ? "—" : `${d.risk_weighted_score} de 100`],
          ["Brechas de infracción muy grave", String(d.very_serious_gaps)],
        ]} />
      </Section>
      <Section n={2} title="Obligaciones evaluadas">
        <table className="w-full border-collapse text-[11px]">
          <thead><tr className="bg-gray-100"><th className={cell}>Obligación</th><th className={cell}>Resultado</th><th className={cell}>%</th><th className={cell}>Gravedad</th><th className={cell}>Detalle</th></tr></thead>
          <tbody>{d.areas.map((a) => (
            <tr key={a.code}><td className={cell}>{a.name}</td><td className={cell}>{RESULT[a.result] ?? a.result}</td><td className={cell}>{a.pct ?? "—"}</td>
              <td className={cell}>{a.severity_label}</td><td className={cell}>{a.detail}
                {a.workers && a.workers.length > 0 && <><br /><span className="text-[10px]">Faltan: {a.workers.join(", ")}{(a.workers_total ?? 0) > a.workers.length ? ` y ${(a.workers_total ?? 0) - a.workers.length} más` : ""}</span></>}</td></tr>
          ))}</tbody>
        </table>
      </Section>
      <Section n={3} title="Plan sugerido">
        {gaps.length === 0 ? <p className="text-[11px]">Sin brechas: mantener los registros al día.</p> : (
          <ol className="list-decimal pl-5 text-[11px]">
            {gaps.map((a) => <li key={a.code} className="mb-1"><b>{a.name}</b> ({a.severity_label}, {RESULT[a.result]}): {a.detail}</li>)}
          </ol>
        )}
        <p className="mt-2 text-[10px] text-gray-500">La gravedad de cada infracción es un parámetro que debe confirmar un especialista.</p>
      </Section>
    </Sheet>
  );
}
