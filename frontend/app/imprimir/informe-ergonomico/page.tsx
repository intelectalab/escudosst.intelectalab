"use client";

// Informe ergonómico por puesto (M-PSI §11): última evaluación de cada puesto con método, puntaje, nivel y acción, e indicadores.

import { Facts, Section, Sheet, ReportLoading, cell, fmtD, useReport } from "@/components/modules/FieldReports";
import { METHOD, type ErgoReport } from "@/components/modules/PsiTools";

const LEVEL: Record<string, string> = { low: "Bajo", medium: "Medio", high: "Alto", very_high: "Muy alto" };

export default function Imprimir() {
  const { data: d, error } = useReport<ErgoReport>("/psi/ergonomics/report");
  if (!d) return <ReportLoading error={error} />;
  const i = d.indicators;
  return (
    <Sheet company={d.company} title="Informe ergonómico por puesto" sub={`Generado el ${new Date().toLocaleDateString("es-PE")}`}>
      <Section n={1} title="Indicadores">
        <Facts rows={[["Puestos evaluados", `${i.evaluated} de ${i.positions}${i.evaluated_pct != null ? ` (${i.evaluated_pct} %)` : ""}`],
          ["Por nivel", Object.entries(i.by_level).map(([k, n]) => `${LEVEL[k] ?? k}: ${n}`).join(" · ") || "—"],
          ["Nivel alto o muy alto", String(i.high)], ["Acciones de nivel alto cerradas", `${i.high_actions_closed} de ${i.high}`]]} />
      </Section>
      <Section n={2} title="Puestos">
        <table className="w-full border-collapse text-[11px]">
          <thead><tr className="bg-gray-100"><th className={cell}>Puesto</th><th className={cell}>Trabajadores</th><th className={cell}>Método</th><th className={cell}>Fecha</th><th className={cell}>Puntaje</th>
            <th className={cell}>Nivel</th><th className={cell}>Recomendaciones</th><th className={cell}>Acción</th></tr></thead>
          <tbody>{d.positions.map((p) => (
            <tr key={p.position_id} style={{ breakInside: "avoid" }}>
              <td className={cell}>{p.position}</td><td className={cell}>{p.workers}</td>
              {p.id ? <>
                <td className={cell}>{p.method ? METHOD[p.method] ?? p.method : "—"}</td><td className={cell}>{fmtD(p.assessed_on)}</td><td className={cell}>{p.score ?? "—"}</td>
                <td className={`${cell} ${p.risk_level === "high" || p.risk_level === "very_high" ? "font-bold text-red-700" : ""}`}>{p.risk_level ? LEVEL[p.risk_level] ?? p.risk_level : "—"}</td>
                <td className={cell}>{p.recommendations ?? "—"}</td><td className={cell}>{p.action_code ?? "—"}</td>
              </> : <td className={cell} colSpan={6}>Sin evaluación</td>}
            </tr>
          ))}</tbody>
        </table>
      </Section>
    </Sheet>
  );
}
