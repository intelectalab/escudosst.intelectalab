"use client";

// Widgets del tablero elegido (M-DSH §5 widget_type, SCR-DSH-03): cada indicador marcado como línea o barras muestra su historia
// mensual del año (kpi_snapshots); el semáforo muestra el color del mes en curso y de los anteriores. Las tarjetas van en la grilla.

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

export type Widget = { code: string; type: "card" | "line" | "bar" | "traffic_light" };
type Point = { month: number; value: number | null; color: string | null };
type Series = { code: string; name: string; unit: string | null; points: Point[] };
const MONTHS = ["E", "F", "M", "A", "M", "J", "J", "A", "S", "O", "N", "D"];
const FILL: Record<string, string> = { green: "var(--ok)", amber: "#F4B90F", red: "var(--bad)" };
const DOT: Record<string, string> = { green: "bg-ok", amber: "bg-[#F4B90F]", red: "bg-bad" };
const WORD: Record<string, string> = { green: "En meta", amber: "Atención", red: "Crítico" };

export function BoardCharts({ widgets, year, site }: { widgets: Widget[]; year: number; site: string }) {
  const charts = widgets.filter((w) => w.type !== "card");
  const [series, setSeries] = useState<Series[] | null>(null);
  useEffect(() => {
    if (!charts.length) return;
    api<{ data: { series: Series[] } }>(`/dsh/history?year=${year}${site ? `&site_id=${site}` : ""}`, { withCompany: true })
      .then((r) => setSeries(r.data.series)).catch(() => setSeries([]));
  }, [charts.length, year, site]);
  if (!charts.length) return null;
  if (!series) return <div className="h-40 animate-pulse rounded-xl bg-surface" />;

  return (
    <div className="grid gap-3 md:grid-cols-2">
      {charts.map((w) => {
        const s = series.find((x) => x.code === w.code);
        return (
          <section key={w.code} className="rounded-xl border border-line bg-surface p-4" aria-label={s?.name ?? w.code}>
            <p className="mb-2 text-sm font-semibold">{s?.name ?? w.code}{s?.unit ? <span className="font-normal text-muted"> ({s.unit})</span> : null}</p>
            {!s || s.points.every((p) => p.value === null) ? <p className="text-sm text-muted">Aún no hay historia guardada para este indicador.</p>
              : w.type === "traffic_light" ? <Lights s={s} /> : <Chart s={s} kind={w.type === "bar" ? "bar" : "line"} />}
          </section>
        );
      })}
    </div>
  );
}

function Chart({ s, kind }: { s: Series; kind: "line" | "bar" }) {
  const W = 360, H = 140, L = 30, B = 18, T = 10;
  const vals = s.points.map((p) => p.value ?? 0);
  const max = Math.max(1, ...vals) * 1.1;
  const x = (m: number) => L + ((m - 0.5) / 12) * (W - L);
  const y = (v: number) => T + (1 - v / max) * (H - T - B);
  const pts = s.points.filter((p) => p.value !== null).sort((a, b) => a.month - b.month);
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-40 w-full" role="img" aria-label={`${kind === "line" ? "Línea" : "Barras"}: ${s.name} por mes`}>
      {[0, 0.5, 1].map((f) => (
        <g key={f}>
          <line x1={L} x2={W} y1={y(max * f / 1.1)} y2={y(max * f / 1.1)} stroke="var(--line)" />
          <text x={L - 4} y={y(max * f / 1.1) + 3} textAnchor="end" fontSize="9" fill="var(--muted)">{+((max * f) / 1.1).toFixed(1)}</text>
        </g>
      ))}
      {MONTHS.map((m, i) => <text key={i} x={x(i + 1)} y={H - 4} textAnchor="middle" fontSize="9" fill="var(--muted)">{m}</text>)}
      {kind === "bar"
        ? pts.map((p) => <rect key={p.month} x={x(p.month) - 9} y={y(p.value!)} width={18} height={Math.max(1, H - B - y(p.value!))} rx={2} fill={p.color ? FILL[p.color] ?? "var(--brand)" : "var(--brand)"}><title>{`${p.value} ${s.unit ?? ""}`}</title></rect>)
        : (
          <>
            <polyline fill="none" stroke="var(--brand)" strokeWidth={2} points={pts.map((p) => `${x(p.month)},${y(p.value!)}`).join(" ")} />
            {pts.map((p) => <circle key={p.month} cx={x(p.month)} cy={y(p.value!)} r={3.5} fill={p.color ? FILL[p.color] ?? "var(--brand)" : "var(--brand)"}><title>{`${p.value} ${s.unit ?? ""}`}</title></circle>)}
          </>
        )}
    </svg>
  );
}

function Lights({ s }: { s: Series }) {
  const pts = [...s.points].sort((a, b) => a.month - b.month);
  const last = [...pts].reverse().find((p) => p.value !== null);
  return (
    <div className="space-y-3">
      <div className="flex items-center gap-3">
        <span className={`h-10 w-10 rounded-full ${last?.color ? DOT[last.color] : "bg-line"}`} aria-hidden="true" />
        <div>
          <p className="font-display text-2xl font-bold">{last?.value != null ? `${+last.value.toFixed(1)}${s.unit === "%" ? " %" : ""}` : "—"}</p>
          <p className="text-xs text-muted">{last?.color ? WORD[last.color] : "Sin meta definida"} · mes en curso</p>
        </div>
      </div>
      <ol className="flex gap-1" aria-label="Color por mes">
        {MONTHS.map((m, i) => {
          const p = pts.find((x) => x.month === i + 1);
          return (
            <li key={i} className="flex flex-1 flex-col items-center gap-1" title={p?.color ? WORD[p.color] : "Sin dato"}>
              <span className={`h-3 w-3 rounded-full ${p?.color ? DOT[p.color] : "bg-line"}`} />
              <span className="text-[10px] text-muted">{m}</span>
            </li>
          );
        })}
      </ol>
    </div>
  );
}
