"use client";

// Estadísticas de SST (M-EST, SCR-EST-02; prototipo m-modulos.js R.EST): aviso con las fórmulas y factores vigentes,
// gráfico de línea del índice de frecuencia por mes y la tabla mensual (trabajadores, horas-hombre, accidentes con
// baja, días perdidos, IF, IG, IA). Los meses aún no calculados se muestran con el cálculo al vuelo.

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Card, fmtNum } from "./Summary";

type Month = { month: number; workers: number | null; hours: number; accidents: number; lost_days: number; fi: number | null; si: number | null; ai: number | null; source: string };
type Summary = {
  year: number;
  factors: { frequency: number; severity: number; accident_rate_divisor: number };
  months: Month[];
  total: { hours: number; accidents: number; lost_days: number; fi: number | null; si: number | null; ai: number | null };
};

const MONTHS = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];

function LineChart({ months }: { months: Month[] }) {
  const W = 640;
  const H = 200;
  const pad = { l: 36, r: 12, t: 12, b: 26 };
  const vals = months.map((m) => m.fi);
  const max = Math.max(1, ...vals.map((v) => v ?? 0)) * 1.15;
  const x = (i: number) => pad.l + (months.length <= 1 ? (W - pad.l - pad.r) / 2 : (i * (W - pad.l - pad.r)) / (months.length - 1));
  const y = (v: number) => pad.t + (H - pad.t - pad.b) * (1 - v / max);
  const pts = months.map((m, i) => (m.fi == null ? null : ([x(i), y(m.fi)] as const)));
  // Segmentos continuos (un mes sin horas-hombre corta la línea).
  const segs: (readonly [number, number])[][] = [[]];
  pts.forEach((p) => (p ? segs[segs.length - 1].push(p) : segs.push([])));
  const ticks = [0, 0.25, 0.5, 0.75, 1].map((t) => max * t);
  return (
    <svg viewBox={`0 0 ${W} ${H}`} className="h-auto w-full" role="img" aria-label="Índice de frecuencia mensual">
      {ticks.map((t) => (
        <g key={t}>
          <line x1={pad.l} x2={W - pad.r} y1={y(t)} y2={y(t)} stroke="var(--line)" strokeWidth="1" />
          <text x={pad.l - 6} y={y(t) + 3} textAnchor="end" fontSize="10" fill="var(--muted)">{fmtNum(t, t < 10 ? 1 : 0)}</text>
        </g>
      ))}
      {segs.filter((s) => s.length > 0).map((s, i) => (
        <g key={i}>
          {s.length > 1 && (
            <path d={`M${s[0][0]},${y(0)} ${s.map(([a, b]) => `L${a},${b}`).join(" ")} L${s[s.length - 1][0]},${y(0)} Z`} fill="var(--brand)" opacity="0.08" />
          )}
          <polyline points={s.map(([a, b]) => `${a},${b}`).join(" ")} fill="none" stroke="var(--brand)" strokeWidth="2.5" strokeLinejoin="round" strokeLinecap="round" />
        </g>
      ))}
      {pts.map((p, i) =>
        p ? (
          <circle key={i} cx={p[0]} cy={p[1]} r="3.5" fill="var(--surface)" stroke="var(--brand)" strokeWidth="2">
            <title>{`${MONTHS[months[i].month - 1]}: IF ${fmtNum(months[i].fi, 2)}`}</title>
          </circle>
        ) : null,
      )}
      {months.map((m, i) => (
        <text key={m.month} x={x(i)} y={H - 8} textAnchor="middle" fontSize="10" fill="var(--muted)">{MONTHS[m.month - 1][0]}</text>
      ))}
    </svg>
  );
}

export function EstSummary() {
  const current = new Date().getFullYear();
  const [year, setYear] = useState(current);
  const [data, setData] = useState<Summary | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    setError(null);
    api<{ data: Summary }>(`/est/summary?year=${year}`, { withCompany: true })
      .then((r) => setData(r.data))
      .catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [year]);

  const f = data?.factors;
  const n = (v: number) => v.toLocaleString("es-PE");
  const rows = [...(data?.months ?? [])].reverse();
  return (
    <div className="space-y-4">
      <p role="note" className="rounded-lg bg-amber-soft px-4 py-3 text-sm text-amber-ink">
        Factores de cálculo como parámetro: <b>IF = N.° accidentes con baja × {f ? n(f.frequency) : "1 000 000"} / HHT</b> ·{" "}
        <b>IG = días perdidos × {f ? n(f.severity) : "1 000 000"} / HHT</b> · <b>IA = IF × IG / {f ? n(f.accident_rate_divisor) : "1 000"}</b>.
      </p>
      {error ? (
        <p className="rounded-xl border border-line bg-surface p-4 text-sm text-muted">{error}</p>
      ) : !data ? (
        <div className="h-64 animate-pulse rounded-xl bg-surface" />
      ) : (
        <>
          <Card
            title="Índice de frecuencia"
            action={
              <select className="filter-control !w-auto" aria-label="Año" value={year} onChange={(e) => setYear(Number(e.target.value))}>
                {[0, 1, 2, 3].map((k) => <option key={k} value={current - k}>{current - k}</option>)}
              </select>
            }
          >
            {data.months.some((m) => m.fi != null) ? (
              <LineChart months={data.months} />
            ) : (
              <p className="text-sm text-muted">Registra las horas-hombre de cada mes (pestaña «Horas-hombre») para calcular los índices.</p>
            )}
            <p className="mt-1 text-xs text-muted">
              Acumulado {data.year}: IF {fmtNum(data.total.fi, 2)} · IG {fmtNum(data.total.si, 2)} · IA {fmtNum(data.total.ai, 2)} · {fmtNum(data.total.hours)} horas-hombre
            </p>
          </Card>
          <div className="overflow-x-auto rounded-xl border border-line bg-surface">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-line bg-bg text-xs font-semibold text-muted">
                <tr>
                  {["Mes", "Trabajadores", "Horas-hombre", "Accidentes con baja", "Días perdidos", "IF", "IG", "IA"].map((h) => (
                    <th key={h} className="whitespace-nowrap px-4 py-3">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map((m) => (
                  <tr key={m.month} className="border-b border-line last:border-0">
                    <td className="px-4 py-2.5 font-medium">
                      {MONTHS[m.month - 1]}
                      {m.source === "live" && <span className="ml-1 text-[11px] font-normal text-muted" title="Mes aún no calculado: cálculo al vuelo">· sin calcular</span>}
                      {m.source === "closed" && <span className="ml-1 text-[11px] font-normal text-ok">· cerrado</span>}
                    </td>
                    <td className="px-4 py-2.5 tabular-nums">{fmtNum(m.workers)}</td>
                    <td className="px-4 py-2.5 tabular-nums">{fmtNum(m.hours)}</td>
                    <td className="px-4 py-2.5 tabular-nums">{m.accidents}</td>
                    <td className="px-4 py-2.5 tabular-nums">{m.lost_days}</td>
                    <td className="px-4 py-2.5 font-semibold tabular-nums">{fmtNum(m.fi, 1)}</td>
                    <td className="px-4 py-2.5 tabular-nums">{fmtNum(m.si, 1)}</td>
                    <td className="px-4 py-2.5 tabular-nums">{fmtNum(m.ai, 2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  );
}
