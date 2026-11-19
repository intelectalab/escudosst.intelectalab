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
  // M-DSH: historia por área (además de por sede) y su gráfico en barras o en línea.
  const [area, setArea] = useState("");
  const [areas, setAreas] = useState<{ id: string; name: string; site: string | null }[]>([]);
  const [kind, setKind] = useState<"bar" | "line">("bar");
  const qs = `year=${year}${area ? `&area_id=${area}` : site ? `&site_id=${site}` : ""}`;
  useEffect(() => {
    api<{ data: { series: Series[]; areas: { id: string; name: string; site: string | null }[] } }>(`/dsh/history?${qs}`, { withCompany: true })
      .then((r) => { setSeries(r.data.series); setAreas(r.data.areas ?? []); setCode((c) => (c && r.data.series.some((x) => x.code === c) ? c : r.data.series[0]?.code || "")); })
      .catch(() => setSeries([]));
  }, [qs]);
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
            onClick={() => downloadFile(`/dsh/history.xlsx?${qs}`, `indicadores-${year}.xlsx`)}><Icon name="download" size={14} /> Series en Excel</button>
          <a href={`/imprimir/tablero?year=${year}${site ? `&site_id=${site}` : ""}`} target="_blank" rel="noreferrer"
            className="inline-flex h-8 items-center gap-1 rounded-lg border border-line px-3 text-xs font-semibold hover:border-brand"><Icon name="file" size={14} /> Tablero en PDF</a>
        </div>
      </div>
      <div className="mb-3 flex flex-wrap gap-2">
        {series.length > 0 && (
          <select className="input max-w-md" aria-label="Indicador" value={code} onChange={(e) => setCode(e.target.value)}>
            {series.map((x) => <option key={x.code} value={x.code}>{x.name}</option>)}
          </select>
        )}
        {areas.length > 0 && (
          <select className="input max-w-xs" aria-label="Área" value={area} onChange={(e) => setArea(e.target.value)}>
            <option value="">{site ? "Toda la sede" : "Toda la empresa"}</option>
            {areas.map((a) => <option key={a.id} value={a.id}>{a.site ? `${a.site} · ` : ""}{a.name}</option>)}
          </select>
        )}
        <div className="inline-flex overflow-hidden rounded-lg border border-line text-xs font-semibold" role="group" aria-label="Tipo de gráfico">
          {([["bar", "Barras"], ["line", "Línea"]] as const).map(([v, l]) => (
            <button key={v} type="button" aria-pressed={kind === v} onClick={() => setKind(v)} className={`px-3 ${kind === v ? "bg-brand text-white" : "bg-surface hover:bg-bg"}`}>{l}</button>
          ))}
        </div>
      </div>
      {series.length === 0 ? <p className="text-sm text-muted">{area ? "Esta área aún no tiene historia guardada: se guarda cada noche." : "Aún no hay historia guardada: los indicadores se guardan cada noche."}</p> : (
        <>
          {s && kind === "line" && <LineChart s={s} />}
          {s && kind === "bar" && (
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
          <p className="mt-2 text-xs text-muted">Cada {kind === "bar" ? "barra" : "punto"} es el valor acumulado del año al cierre del mes{s?.unit ? ` (${s.unit})` : ""}{area ? `, solo con los registros del área${areas.find((a) => a.id === area) ? ` ${areas.find((a) => a.id === area)!.name}` : ""}` : ""}.</p>
        </>
      )}
    </section>
  );
}

function LineChart({ s }: { s: Series }) {
  const W = 600, H = 160, L = 34, B = 20, T = 12;
  const pts = s.points.filter((p) => p.value !== null).sort((a, b) => a.month - b.month);
  const max = Math.max(1, ...pts.map((p) => p.value ?? 0)) * 1.1;
  const x = (m: number) => L + ((m - 0.5) / 12) * (W - L);
  const y = (v: number) => T + (1 - v / max) * (H - T - B);
  const FILL: Record<string, string> = { green: "var(--ok)", amber: "#F4B90F", red: "var(--bad)" };
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-40 w-full" role="img" aria-label={`Historia mensual de ${s.name}`}>
      {[0, 0.5, 1].map((f) => (
        <g key={f}>
          <line x1={L} x2={W} y1={y((max * f) / 1.1)} y2={y((max * f) / 1.1)} stroke="var(--line)" />
          <text x={L - 4} y={y((max * f) / 1.1) + 3} textAnchor="end" fontSize="10" fill="var(--muted)">{+((max * f) / 1.1).toFixed(1)}</text>
        </g>
      ))}
      {MONTHS.map((m, i) => <text key={m} x={x(i + 1)} y={H - 5} textAnchor="middle" fontSize="10" fill="var(--muted)">{m}</text>)}
      <polyline fill="none" stroke="var(--brand)" strokeWidth={2} points={pts.map((p) => `${x(p.month)},${y(p.value!)}`).join(" ")} />
      {pts.map((p) => (
        <g key={p.month}>
          <circle cx={x(p.month)} cy={y(p.value!)} r={4} fill={p.color ? FILL[p.color] ?? "var(--brand)" : "var(--brand)"} />
          <text x={x(p.month)} y={y(p.value!) - 7} textAnchor="middle" fontSize="10" fontWeight="600" fill="var(--fg)">{+p.value!.toFixed(1)}</text>
        </g>
      ))}
    </svg>
  );
}
