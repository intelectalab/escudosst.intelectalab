"use client";

// Reporte consolidado de clientes de la consultora (M-CON §11): cumplimiento, brechas (alertas) y vencimientos de cada
// empresa, con la marca de la consultora (logo, nombre, color y pie de página de su marca blanca).

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { cell, fmtD } from "@/components/modules/FieldReports";

type Panel = {
  consultancy: { name: string };
  kpis: { companies: number; workers: number; compliance: number | null; alerts: number };
  companies: { company_id: string; name: string; ruc: string; workers: number; compliance: number | null; alerts: number; critical: number; plan: { name: string } | null }[];
  critical: { company: string; count: number; text: string }[];
  agenda: { company: string; kind: string; text: string; date: string }[];
};
type Brand = { brand_name: string | null; logo: string | null; primary_color: string | null; pdf_footer_text: string | null; show_powered_by: boolean } | null;

export default function ImprimirConsolidado() {
  const [d, setD] = useState<Panel | null>(null);
  const [brand, setBrand] = useState<Brand>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ data: Panel }>("/con/panel", { withCompany: true }).then((r) => setD(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
    api<{ data: Brand }>("/brand", { withCompany: true }).then((r) => setBrand(r.data)).catch(() => setBrand(null));
  }, []);
  if (!d) return <p className="p-8">{error ?? "Preparando el reporte…"}</p>;
  const color = brand?.primary_color || "#0d3b6e";
  const name = brand?.brand_name || d.consultancy.name;
  return (
    <div className="min-h-screen bg-gray-100 p-4 text-gray-900 print:bg-white print:p-0">
      <style>{`@page { size: A4; margin: 14mm } @media print { .no-print { display: none } }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-3xl flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3 shadow">
        <span className="text-sm text-gray-600">Para el PDF elige «Guardar como PDF» en el diálogo de impresión.</span>
        <button onClick={() => window.print()} className="rounded-lg px-4 py-2 text-sm font-semibold text-white" style={{ background: color }}>Imprimir / Guardar PDF</button>
      </div>
      <div className="mx-auto max-w-3xl space-y-5 bg-white p-8 shadow print:max-w-none print:p-0 print:shadow-none">
        <header className="flex items-center gap-4 border-b-4 pb-3" style={{ borderColor: color }}>
          {brand?.logo && <img src={brand.logo} alt={name} className="h-14 w-auto object-contain" />}
          <div>
            <p className="text-xs text-gray-600">{name}</p>
            <h1 className="text-lg font-bold uppercase">Reporte consolidado de clientes</h1>
            <p className="text-sm">Al {new Date().toLocaleDateString("es-PE")} · {d.kpis.companies} empresa(s) · {d.kpis.workers} trabajador(es)</p>
          </div>
        </header>
        <section className="space-y-2">
          <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">1. Resumen</h2>
          <p className="text-sm">Cumplimiento promedio frente a una inspección: <b>{d.kpis.compliance ?? "—"}{d.kpis.compliance !== null ? " de 100" : ""}</b> · Alertas abiertas: <b>{d.kpis.alerts}</b>.</p>
        </section>
        <section className="space-y-2">
          <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">2. Cumplimiento por empresa</h2>
          <table className="w-full border-collapse text-[11px]">
            <thead><tr className="bg-gray-100"><th className={cell}>Empresa</th><th className={cell}>RUC</th><th className={cell}>Trabajadores</th><th className={cell}>Cumplimiento</th><th className={cell}>Alertas</th><th className={cell}>Críticas</th></tr></thead>
            <tbody>{d.companies.map((c) => (
              <tr key={c.company_id}><td className={cell}>{c.name}</td><td className={cell}>{c.ruc}</td><td className={cell}>{c.workers}</td>
                <td className={cell}>{c.compliance ?? "—"}</td><td className={cell}>{c.alerts}</td><td className={cell}>{c.critical}</td></tr>
            ))}</tbody>
          </table>
        </section>
        <section className="space-y-2">
          <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">3. Brechas críticas</h2>
          {d.critical.length === 0 ? <p className="text-[11px]">Sin alertas críticas abiertas.</p> : (
            <ul className="list-disc pl-5 text-[11px]">{d.critical.map((c, i) => <li key={i}><b>{c.company}:</b> {c.count} {c.text}</li>)}</ul>
          )}
        </section>
        <section className="space-y-2">
          <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">4. Próximos vencimientos y visitas</h2>
          {d.agenda.length === 0 ? <p className="text-[11px]">Sin actividades programadas en los próximos días.</p> : (
            <table className="w-full border-collapse text-[11px]">
              <thead><tr className="bg-gray-100"><th className={cell}>Fecha</th><th className={cell}>Empresa</th><th className={cell}>Actividad</th></tr></thead>
              <tbody>{d.agenda.map((a, i) => <tr key={i}><td className={cell}>{fmtD(a.date)}</td><td className={cell}>{a.company}</td><td className={cell}>{a.kind}: {a.text}</td></tr>)}</tbody>
            </table>
          )}
        </section>
        <footer className="border-t border-gray-300 pt-2 text-center text-[10px] text-gray-500">
          {brand?.pdf_footer_text || name}{brand?.show_powered_by !== false ? " · Generado con EscudoSST" : ""}
        </footer>
      </div>
    </div>
  );
}
