"use client";

// Historia de indicadores (M-DSH): valor acumulado del año al cierre de cada mes, por sede o de toda la empresa, guardado cada día
// (kpi_snapshots). Gráfico de barras por mes, Excel con todas las series y el tablero en PDF.

import { useEffect, useState } from "react";
import { api } from "@/lib/api";
import { downloadFile } from "@/lib/records";
import { Icon } from "@/components/ui/Icon";

type Point = { month: number; value: number | null; numerator: number | null; denominator: number | null; color: string | null; as_of: string };
type Series = { code: string; name: string; unit: string | null; points: Point[] };
const MONTHS = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Set", "Oct", "Nov", "Dic"];
const COLOR: Record<string, string> = { green: "bg-ok", amber: "bg-[#F4B90F]", red: "bg-bad" };

export function KpiHistory({ year, site }: { year: number; site: string }) {
  const [series, setSeries] = useState<Series[] | null>(null);
  const [code, setCode] = useState("");
  useEffect(() => {
    api<{ data: { series: Series[] } }>(`/dsh/history?year=${year}${site ? `&site_id=${site}` : ""}`, { withCompany: true })
      .then((r) => { setSeries(r.data.series); setCode((c) => c || r.data.series[0]?.code || ""); })
      .catch(() => setSeries([]));
  }, [year, site]);
  if (!series) return null;
  const s = series.find((x) => x.code === code);
  const max = Math.max(1, ...(s?.points.map((p) => p.value ?? 0) ?? [1]));

  return (
    <section aria-label="Historia de indicadores" className="rounded-xl border border-line bg-surface p-4">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="flex items-center gap-2 font-display text-base font-semibold">
          <span className="grid h-7 w-7 place-items-center rounded-lg bg-brand-soft text-brand-strong"><Icon name="history" size={15} /></span>Historia {year}
        </h2>
        <div className="flex flex-wrap gap-2">
          <button type="button" className="inline-flex h-8 items-center gap-1 rounded-lg border border-line px-3 text-xs font-semibold hover:border-brand"
            onClick={() => downloadFile(`/dsh/history.xlsx?year=${year}${site ? `&site_id=${site}` : ""}`, `indicadores-${year}.xlsx`)}><Icon name="download" size={14} /> Series en Excel</button>
          <a href={`/imprimir/tablero?year=${year}${site ? `&site_id=${site}` : ""}`} target="_blank" rel="noreferrer"
            className="inline-flex h-8 items-center gap-1 rounded-lg border border-line px-3 text-xs font-semibold hover:border-brand"><Icon name="file" size={14} /> Tablero en PDF</a>
        </div>
      </div>
      {series.length === 0 ? <p className="text-sm text-muted">Aún no hay historia guardada: los indicadores se guardan cada noche.</p> : (
        <>
          <select className="input mb-3 max-w-md" aria-label="Indicador" value={code} onChange={(e) => setCode(e.target.value)}>
            {series.map((x) => <option key={x.code} value={x.code}>{x.name}</option>)}
          </select>
          {s && (
            <div className="flex h-40 items-end gap-1.5" role="img" aria-label={`Historia mensual de ${s.name}`}>
              {MONTHS.map((m, i) => {
                const p = s.points.find((x) => x.month === i + 1);
                const h = p?.value != null ? Math.max(4, (p.value / max) * 100) : 0;
                return (
                  <div key={m} className="flex h-full flex-1 flex-col items-center justify-end gap-1" title={p ? `${m}: ${p.value ?? "No aplica"}${s.unit ? ` ${s.unit}` : ""}` : `${m}: sin dato`}>
                    <span className="text-[10px] font-semibold">{p?.value != null ? +p.value.toFixed(1) : ""}</span>
                    <div className={`w-full rounded-t ${p?.color ? COLOR[p.color] ?? "bg-brand" : "bg-brand"}`} style={{ height: `${h}%` }} />
                    <span className="text-[10px] text-muted">{m}</span>
                  </div>
                );
              })}
            </div>
          )}
          <p className="mt-2 text-xs text-muted">Cada barra es el valor acumulado del año al cierre del mes{s?.unit ? ` (${s.unit})` : ""}.</p>
        </>
      )}
    </section>
  );
}
