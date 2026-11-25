"use client";

// Informe mensual de una empresa cliente de la consultora (M-CON §11 «exportación por cliente para informes mensuales»):
// cumplimiento por obligación SUNAFIL, actividad del mes, indicadores del año, alertas y vencimientos, con la marca de la
// consultora. Solo conteos e indicadores (sin datos personales). El mismo informe sale en Excel.

import { useParams, useSearchParams } from "next/navigation";
import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { downloadFile } from "@/lib/records";
import { cell, fmtD } from "@/components/modules/FieldReports";

type Report = {
  consultancy: string;
  company: { id: string; name: string; ruc: string; sector: string | null; sst_model: string | null; workers: number };
  month: string; month_label: string; from: string; to: string;
  compliance: { score: number | null; risk_weighted_score: number | null; areas: { name: string; pct: number | null; result_label: string; severity: string | null; detail: string | null }[] };
  indicators: { code: string; name: string; unit: string | null; value: number | null }[];
  activity: { code: string; label: string; value: number }[];
  alerts: { text: string; count: number; tone: string }[];
  agenda: { kind: string; text: string; date: string }[];
};
type Brand = { brand_name: string | null; logo: string | null; primary_color: string | null; pdf_footer_text: string | null; show_powered_by: boolean } | null;

const thisMonth = () => new Date().toLocaleDateString("en-CA", { timeZone: "America/Lima" }).slice(0, 7);

export default function ImprimirInformeCliente() {
  const { id } = useParams<{ id: string }>();
  const params = useSearchParams();
  const [month, setMonth] = useState(params.get("mes") ?? thisMonth());
  const [d, setD] = useState<Report | null>(null);
  const [brand, setBrand] = useState<Brand>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    setD(null); setError(null);
    api<{ data: Report }>(`/con/clients/${id}/report?month=${month}`, { withCompany: true }).then((r) => setD(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [id, month]);
  useEffect(() => {
    api<{ data: Brand }>("/brand", { withCompany: true }).then((r) => setBrand(r.data)).catch(() => setBrand(null));
  }, []);
  const color = brand?.primary_color || "#0d3b6e";
  const name = brand?.brand_name || d?.consultancy || "";
  const excel = () => d && downloadFile(`/con/clients/${id}/report.xlsx?month=${month}`, `informe-${d.company.ruc}-${month}.xlsx`).catch((e: ApiError) => setError(e.detail ?? e.title));
  return (
    <div className="min-h-screen bg-gray-100 p-4 text-gray-900 print:bg-white print:p-0">
      <style>{`@page { size: A4; margin: 14mm } @media print { .no-print { display: none } }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-3xl flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3 shadow">
        <label className="flex items-center gap-2 text-sm text-gray-600">Mes
          <input type="month" className="rounded border border-gray-300 px-2 py-1" value={month} max={thisMonth()} onChange={(e) => e.target.value && setMonth(e.target.value)} />
        </label>
        <div className="flex gap-2">
          <button onClick={excel} disabled={!d} className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold">Descargar Excel</button>
          <button onClick={() => window.print()} disabled={!d} className="rounded-lg px-4 py-2 text-sm font-semibold text-white" style={{ background: color }}>Imprimir / Guardar PDF</button>
        </div>
      </div>
      {!d ? <p className="mx-auto max-w-3xl p-8">{error ?? "Preparando el informe…"}</p> : (
        <div className="mx-auto max-w-3xl space-y-5 bg-white p-8 shadow print:max-w-none print:p-0 print:shadow-none">
          <header className="flex items-center gap-4 border-b-4 pb-3" style={{ borderColor: color }}>
            {brand?.logo && <img src={brand.logo} alt={name} className="h-14 w-auto object-contain" />}
            <div>
              <p className="text-xs text-gray-600">{name}</p>
              <h1 className="text-lg font-bold uppercase">Informe mensual SST · {d.month_label}</h1>
              <p className="text-sm">{d.company.name} · RUC {d.company.ruc}{d.company.sector ? ` · ${d.company.sector}` : ""} · {d.company.workers} trabajador(es)</p>
            </div>
          </header>
          <section className="space-y-2">
            <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">1. Cumplimiento frente a una inspección SUNAFIL</h2>
            <p className="text-sm">Puntaje: <b>{d.compliance.score ?? "—"}{d.compliance.score !== null ? " de 100" : ""}</b>{d.compliance.risk_weighted_score !== null && <> · ponderado por gravedad: <b>{d.compliance.risk_weighted_score}</b></>}</p>
            <table className="w-full border-collapse text-[11px]">
              <thead><tr className="bg-gray-100"><th className={cell}>Obligación</th><th className={cell}>Avance</th><th className={cell}>Resultado</th><th className={cell}>Gravedad</th></tr></thead>
              <tbody>{d.compliance.areas.map((a) => (
                <tr key={a.name}><td className={cell}>{a.name}{a.detail && <span className="block text-gray-500">{a.detail}</span>}</td><td className={cell}>{a.pct === null ? "—" : `${a.pct} %`}</td><td className={cell}>{a.result_label}</td><td className={cell}>{a.severity ?? ""}</td></tr>
              ))}</tbody>
            </table>
          </section>
          <section className="space-y-2">
            <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">2. Actividad del mes ({fmtD(d.from)} al {fmtD(d.to)})</h2>
            <table className="w-full border-collapse text-[11px]">
              <tbody>{d.activity.map((a) => <tr key={a.code}><td className={cell}>{a.label}</td><td className={`${cell} text-right font-semibold`}>{a.value}</td></tr>)}</tbody>
            </table>
          </section>
          <section className="space-y-2">
            <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">3. Indicadores del año al cierre del mes</h2>
            {d.indicators.length === 0 ? <p className="text-[11px]">Sin indicadores disponibles en el plan de la empresa.</p> : (
              <table className="w-full border-collapse text-[11px]">
                <tbody>{d.indicators.map((k) => <tr key={k.code}><td className={cell}>{k.name}</td><td className={`${cell} text-right`}>{k.value ?? "—"}{k.value !== null && k.unit === "percent" ? " %" : ""}</td></tr>)}</tbody>
              </table>
            )}
          </section>
          <section className="space-y-2">
            <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">4. Alertas abiertas</h2>
            {d.alerts.length === 0 ? <p className="text-[11px]">Sin alertas abiertas.</p> : (
              <ul className="list-disc pl-5 text-[11px]">{d.alerts.map((a, i) => <li key={i}><b>{a.count}</b> {a.text}{a.tone === "bad" ? " (crítica)" : ""}</li>)}</ul>
            )}
          </section>
          <section className="space-y-2">
            <h2 className="border-b border-gray-400 pb-1 text-sm font-bold uppercase">5. Próximos vencimientos y visitas (30 días)</h2>
            {d.agenda.length === 0 ? <p className="text-[11px]">Sin actividades programadas.</p> : (
              <table className="w-full border-collapse text-[11px]">
                <thead><tr className="bg-gray-100"><th className={cell}>Fecha</th><th className={cell}>Actividad</th></tr></thead>
                <tbody>{d.agenda.map((a, i) => <tr key={i}><td className={cell}>{fmtD(a.date)}</td><td className={cell}>{a.kind}: {a.text}</td></tr>)}</tbody>
              </table>
            )}
          </section>
          <footer className="border-t border-gray-300 pt-2 text-center text-[10px] text-gray-500">
            {brand?.pdf_footer_text || name}{brand?.show_powered_by !== false ? " · Generado con EscudoSST" : ""}
          </footer>
        </div>
      )}
    </div>
  );
}
