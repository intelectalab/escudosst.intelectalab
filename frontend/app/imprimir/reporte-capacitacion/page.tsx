"use client";

// Reporte anual de capacitación para imprimir o guardar en PDF (M-CAP SCR-CAP-14): avance por tema, aprobados y desaprobados,
// horas-hombre por modalidad, permanencia en el aula, cumplimiento de la matriz por área y por cargo, y certificados del año.

import { Suspense, useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { api, type ApiError } from "@/lib/api";
import { BrandFooter, BrandLogo, useBrand } from "@/components/modules/FieldReports";

type Comp = { name: string; workers: number; required: number; valid: number; expiring: number; expired: number; missing: number; pct: number | null };
type Rep = {
  year: number; company: { name: string; legal_name: string; ruc: string };
  topics: { title: string; modality: string; sessions: number; participants: number; approved: number; failed: number; hours_in_person: number; hours_aula: number }[];
  permanence: { course: string; enrolled: number; completed: number; passed: number; avg_minutes: number; total_hours: number }[];
  compliance: { by_area: Comp[]; by_position: Comp[] };
  certificates: { issued: number; validated: number; pending: number; voided: number };
};
const MOD: Record<string, string> = { in_person: "Presencial", virtual: "Virtual", blended: "Mixta" };

export default function ReporteCapacitacionPage() {
  return <Suspense fallback={<p className="p-8">Preparando el reporte…</p>}><ReporteCapacitacion /></Suspense>;
}

function ReporteCapacitacion() {
  const year = useSearchParams().get("year") ?? String(new Date().getFullYear());
  const [d, setD] = useState<Rep | null>(null);
  const [error, setError] = useState<string | null>(null);
  const brand = useBrand();
  useEffect(() => { api<{ data: Rep }>(`/cap/report?year=${encodeURIComponent(year)}`, { withCompany: true }).then((r) => setD(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title)); }, [year]);
  if (error) return <p className="p-8">{error}</p>;
  if (!d) return <p className="p-8">Preparando el reporte…</p>;
  const cell = "border border-gray-300 px-2 py-1";
  const head = (cols: string[]) => <thead><tr className="bg-gray-100">{cols.map((c) => <th key={c} className={cell}>{c}</th>)}</tr></thead>;
  const empty = (n: number, t: string) => <tr><td className={cell} colSpan={n}>{t}</td></tr>;
  const comp = (t: string, label: string, rows: Comp[]) => (
    <section className="space-y-2">
      <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">{t}</h2>
      <table className="w-full border-collapse text-xs">
        {head([label, "Trabajadores", "Temas exigidos", "Vigentes", "Por vencer", "Vencidos", "Faltantes", "Cumplimiento"])}
        <tbody>{rows.length === 0 ? empty(8, "La matriz por cargo no exige temas a trabajadores activos.") : rows.map((r) => (
          <tr key={r.name}><td className={cell}>{r.name}</td><td className={cell}>{r.workers}</td><td className={cell}>{r.required}</td><td className={cell}>{r.valid}</td>
            <td className={cell}>{r.expiring}</td><td className={cell}>{r.expired}</td><td className={cell}>{r.missing}</td><td className={cell}>{r.pct === null ? "—" : `${r.pct} %`}</td></tr>
        ))}</tbody>
      </table>
    </section>
  );
  return (
    <div className="min-h-screen bg-gray-100 p-4 text-gray-900 print:bg-white print:p-0">
      <style>{`@page { size: A4; margin: 14mm } @media print { .no-print { display: none } }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-4xl justify-end"><button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">Imprimir / Guardar PDF</button></div>
      <div className="mx-auto max-w-4xl space-y-5 bg-white p-8 shadow print:max-w-none print:p-0 print:shadow-none">
        <header className="text-center">
          <BrandLogo brand={brand} className="mx-auto h-10 w-auto object-contain" />
          <h1 className="text-lg font-bold uppercase">Reporte de capacitaciones {d.year}</h1>
          <p className="text-sm">{d.company.legal_name} · RUC {d.company.ruc} · generado el {new Date().toLocaleDateString("es-PE")}</p>
        </header>
        <section className="space-y-2">
          <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">Avance por tema</h2>
          <table className="w-full border-collapse text-xs">
            {head(["Tema", "Modalidad", "Sesiones", "Participantes", "Aprobados", "Desaprobados o sin certificado", "Horas-hombre presenciales", "Horas-hombre en el aula"])}
            <tbody>{d.topics.length === 0 ? empty(8, "Sin sesiones realizadas ni certificados en el año.") : d.topics.map((r) => (
              <tr key={r.title}><td className={cell}>{r.title}</td><td className={cell}>{MOD[r.modality] ?? r.modality}</td><td className={cell}>{r.sessions}</td><td className={cell}>{r.participants}</td>
                <td className={cell}>{r.approved}</td><td className={cell}>{r.failed}</td><td className={cell}>{r.hours_in_person}</td><td className={cell}>{r.hours_aula}</td></tr>
            ))}</tbody>
          </table>
        </section>
        <section className="space-y-2">
          <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">Permanencia en el aula virtual</h2>
          <table className="w-full border-collapse text-xs">
            {head(["Curso", "Matriculados", "Completaron", "Aprobaron", "Permanencia promedio (min)", "Permanencia total (horas)"])}
            <tbody>{d.permanence.length === 0 ? empty(6, "Sin matrículas en el aula en el año.") : d.permanence.map((r) => (
              <tr key={r.course}><td className={cell}>{r.course}</td><td className={cell}>{r.enrolled}</td><td className={cell}>{r.completed}</td><td className={cell}>{r.passed}</td>
                <td className={cell}>{r.avg_minutes}</td><td className={cell}>{r.total_hours}</td></tr>
            ))}</tbody>
          </table>
          <p className="text-xs text-gray-600">La permanencia es el tiempo activo en el curso (con interacción o video en reproducción), no el tiempo con la ventana abierta.</p>
        </section>
        {comp("Cumplimiento de la matriz por área", "Área", d.compliance.by_area)}
        {comp("Cumplimiento de la matriz por cargo", "Cargo", d.compliance.by_position)}
        <section className="space-y-2">
          <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">Certificados del año</h2>
          <p className="text-sm">Emitidos: <b>{d.certificates.issued}</b> · Validados: <b>{d.certificates.validated}</b> · Por validar: <b>{d.certificates.pending}</b> · Anulados: <b>{d.certificates.voided}</b></p>
        </section>
        <BrandFooter brand={brand} />
      </div>
    </div>
  );
}
