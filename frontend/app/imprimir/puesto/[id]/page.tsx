"use client";

// Ficha del puesto (M-TEN SCR-TEN-03): datos del puesto, su IPERC vigente con los peligros, EPP obligatorio y capacitaciones exigidas.

import { useParams } from "next/navigation";
import { Facts, Section, Sheet, ReportLoading, cell, fmtD, useReport } from "@/components/modules/FieldReports";

type SheetData = {
  company: { legal_name: string; ruc: string };
  position: { name: string; code: string | null; area: string | null; site: string | null; description: string | null; functions: string | null; is_critical: boolean; requires_emo: boolean; requires_sctr: boolean; workers: number };
  iperc: { id: string; code: string; title: string; status: string; next_review_on: string | null; version_number: number | null; approved_at: string | null }[];
  hazards: { task: string | null; hazard: string | null; risk: string | null; risk_level: string | null; residual_level: string | null; is_significant: boolean }[];
  ppe: { name: string; category: string | null; quantity: number | null; replacement_days: number | null; is_mandatory: boolean; conditions: string | null }[];
  trainings: { title: string | null; requirement_level: string | null; renewal_months: number | null; due_within_days: number | null }[];
};
const yes = (b: boolean) => (b ? "Sí" : "No");

export default function ImprimirPuesto() {
  const { id } = useParams<{ id: string }>();
  const { data: d, error } = useReport<SheetData>(`/ten/positions/${id}/sheet`);
  if (!d) return <ReportLoading error={error} />;
  const p = d.position;
  return (
    <Sheet company={{ name: d.company.legal_name, legal_name: d.company.legal_name, ruc: d.company.ruc }} title={`Ficha del puesto: ${p.name}`}
      sub={`${p.site ?? "—"} · ${p.area ?? "Sin área"} · Al ${new Date().toLocaleDateString("es-PE")}`}>
      <Section n={1} title="Puesto">
        <Facts rows={[["Código", p.code ?? "—"], ["Trabajadores activos", String(p.workers)], ["Puesto crítico", yes(p.is_critical)], ["Requiere EMO", yes(p.requires_emo)],
          ["Requiere SCTR", yes(p.requires_sctr)], ["Descripción", p.description ?? "—"], ["Funciones", p.functions ?? "—"]]} />
      </Section>
      <Section n={2} title="IPERC del puesto">
        {d.iperc.length === 0 ? <p className="text-[11px]">Sin matriz IPERC asignada al puesto.</p> : (
          <Facts rows={d.iperc.map((m) => [`${m.code} · ${m.title}`, `Versión ${m.version_number ?? "—"}${m.approved_at ? ` aprobada el ${fmtD(m.approved_at)}` : " sin aprobar"} · revisión ${fmtD(m.next_review_on)}`] as [string, string])} />
        )}
        {d.hazards.length > 0 && (
          <table className="mt-2 w-full border-collapse text-[11px]">
            <thead><tr className="bg-gray-100"><th className={cell}>Tarea</th><th className={cell}>Peligro</th><th className={cell}>Riesgo</th><th className={cell}>Nivel</th><th className={cell}>Residual</th></tr></thead>
            <tbody>{d.hazards.map((h, i) => (
              <tr key={i}><td className={cell}>{h.task ?? "—"}</td><td className={cell}>{h.hazard ?? "—"}</td><td className={cell}>{h.risk ?? "—"}</td>
                <td className={cell}>{h.risk_level ?? "—"}{h.is_significant ? " (significativo)" : ""}</td><td className={cell}>{h.residual_level ?? "—"}</td></tr>
            ))}</tbody>
          </table>
        )}
      </Section>
      <Section n={3} title="EPP del puesto">
        {d.ppe.length === 0 ? <p className="text-[11px]">Sin EPP configurado para el puesto.</p> : (
          <table className="w-full border-collapse text-[11px]">
            <thead><tr className="bg-gray-100"><th className={cell}>EPP</th><th className={cell}>Obligatorio</th><th className={cell}>Cantidad</th><th className={cell}>Reposición</th><th className={cell}>Condiciones</th></tr></thead>
            <tbody>{d.ppe.map((e, i) => (
              <tr key={i}><td className={cell}>{e.name}</td><td className={cell}>{yes(e.is_mandatory)}</td><td className={cell}>{e.quantity ?? 1}</td>
                <td className={cell}>{e.replacement_days ? `${e.replacement_days} días` : "—"}</td><td className={cell}>{e.conditions ?? "—"}</td></tr>
            ))}</tbody>
          </table>
        )}
      </Section>
      <Section n={4} title="Capacitaciones exigidas">
        {d.trainings.length === 0 ? <p className="text-[11px]">Sin capacitaciones exigidas para el puesto.</p> : (
          <Facts rows={d.trainings.map((t) => [t.title ?? "—", `${t.renewal_months ? `Renovar cada ${t.renewal_months} meses` : "Sin renovación"}${t.due_within_days ? ` · dentro de ${t.due_within_days} días del ingreso` : ""}`] as [string, string])} />
        )}
      </Section>
    </Sheet>
  );
}
