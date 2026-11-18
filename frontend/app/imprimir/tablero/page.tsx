"use client";

// Tablero de indicadores en PDF (M-DSH §11): los indicadores del año (y sede) con su valor, cálculo y semáforo, y la historia mensual.
// Se guarda como PDF desde el diálogo de impresión del navegador.

import { useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { BrandFooter, BrandLogo, useBrand } from "@/components/modules/FieldReports";

type Kpi = { code: string; name: string; unit: string | null; locked?: boolean; label?: string; basis?: string; color?: string | null; module_name?: string | null };
type Series = { code: string; name: string; points: { month: number; value: number | null }[] };
const COLOR: Record<string, [string, string]> = { green: ["En meta", "#17703e"], amber: ["Atención", "#7a5300"], red: ["Crítico", "#a3261f"] };
const MONTHS = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Set", "Oct", "Nov", "Dic"];
const cell = "border border-gray-300 px-2 py-1 align-top";

function Sheet() {
  const q = useSearchParams();
  const year = q.get("year") ?? String(new Date().getFullYear());
  const site = q.get("site_id") ?? "";
  const [kpis, setKpis] = useState<Kpi[] | null>(null);
  const [series, setSeries] = useState<Series[]>([]);
  const [siteName, setSiteName] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const brand = useBrand();
  useEffect(() => {
    const qs = `year=${year}${site ? `&site_id=${site}` : ""}`;
    api<{ data: { kpis: Kpi[]; sites: { id: string; name: string }[] } }>(`/dsh/overview?${qs}`, { withCompany: true })
      .then((r) => { setKpis(r.data.kpis.filter((k) => !k.locked)); setSiteName(r.data.sites.find((s) => s.id === site)?.name ?? null); })
      .catch((e: ApiError) => setError(e.detail ?? e.title));
    api<{ data: { series: Series[] } }>(`/dsh/history?${qs}`, { withCompany: true }).then((r) => setSeries(r.data.series)).catch(() => setSeries([]));
  }, [year, site]);
  if (!kpis) return <p className="p-8">{error ?? "Preparando el tablero…"}</p>;
  return (
    <div className="min-h-screen bg-gray-100 p-4 text-gray-900 print:bg-white print:p-0">
      <style>{"@page { size: A4 landscape; margin: 10mm } @media print { .no-print { display: none } } tr { break-inside: avoid }"}</style>
      <div className="no-print mx-auto mb-4 flex max-w-6xl justify-end rounded-xl bg-white p-3 shadow">
        <button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">Imprimir / Guardar PDF</button>
      </div>
      <div className="mx-auto max-w-6xl space-y-4 bg-white p-6 text-xs shadow print:max-w-none print:p-0 print:shadow-none">
        <header className="text-center">
          <BrandLogo brand={brand} className="mx-auto h-10 w-auto object-contain" />
          <h1 className="text-base font-bold uppercase">Tablero de indicadores SST {year}</h1>
          <p>{siteName ? `Sede: ${siteName}` : "Toda la empresa"} · Generado el {new Date().toLocaleString("es-PE")}</p>
        </header>
        <table className="w-full border-collapse">
          <thead><tr>{["Indicador", "Valor", "Semáforo", "Cálculo"].map((h) => <th key={h} className={`${cell} bg-gray-100 text-left`}>{h}</th>)}</tr></thead>
          <tbody>
            {kpis.map((k) => (
              <tr key={k.code}>
                <td className={cell}><b>{k.name}</b>{k.module_name ? <span className="block text-gray-500">{k.module_name}</span> : null}</td>
                <td className={cell}>{k.label ?? "—"}</td>
                <td className={cell} style={{ color: k.color ? COLOR[k.color]?.[1] : undefined }}>{k.color ? COLOR[k.color]?.[0] ?? k.color : "Sin semáforo"}</td>
                <td className={cell}>{k.basis ?? ""}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {series.length > 0 && (
          <>
            <h2 className="text-sm font-bold uppercase">Historia mensual (acumulado del año al cierre de cada mes)</h2>
            <table className="w-full border-collapse">
              <thead><tr><th className={`${cell} bg-gray-100 text-left`}>Indicador</th>{MONTHS.map((m) => <th key={m} className={`${cell} bg-gray-100`}>{m}</th>)}</tr></thead>
              <tbody>
                {series.map((s) => (
                  <tr key={s.code}><td className={cell}>{s.name}</td>
                    {MONTHS.map((_, i) => { const p = s.points.find((x) => x.month === i + 1); return <td key={i} className={`${cell} text-right`}>{p?.value != null ? +p.value.toFixed(1) : ""}</td>; })}</tr>
                ))}
              </tbody>
            </table>
          </>
        )}
        <BrandFooter brand={brand} />
      </div>
    </div>
  );
}

export default function Tablero() {
  return <Suspense fallback={<p className="p-8">Preparando el tablero…</p>}><Sheet /></Suspense>;
}
