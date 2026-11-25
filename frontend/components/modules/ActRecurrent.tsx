"use client";

// Ranking de condiciones recurrentes (M-ACT §11): condiciones subestándar del periodo agrupadas por tipo de condición
// (clasificación del peligro), sede, área y lugar, con cuántas veces se reportaron y si reaparecieron tras cerrarse.
// En pantalla y en Excel.

import { useEffect, useState } from "react";
import { api, type ApiError } from "@/lib/api";
import { Chip } from "@/components/ui/Display";
import { Card } from "./Summary";
import { ReportButton } from "./CtrExtras";

type Group = { condition: string | null; site: string | null; area: string | null; location: string | null; count: number; months: number; open: number; after_close: number;
  first_on: string; last_on: string; recurrent: boolean };
type Rank = { name: string | null; count: number; places: number; open: number; recurrent: boolean };
type Data = { from: string; to: string; min: number; groups: Group[]; by_condition: Rank[]; by_area: Rank[] };

const fmt = (d: string) => d.slice(0, 10).split("-").reverse().join("/");

export function ActRecurrent() {
  const year = new Date().getFullYear();
  const [from, setFrom] = useState(`${year}-01-01`);
  const [to, setTo] = useState(new Date().toISOString().slice(0, 10));
  const [d, setD] = useState<Data | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    const q = new URLSearchParams({ from, to }).toString();
    api<{ data: Data }>(`/act/recurrent-conditions?${q}`, { withCompany: true }).then((r) => { setD(r.data); setError(null); })
      .catch((e: ApiError) => { setD(null); setError(e.status === 403 ? null : (e.detail ?? e.title)); });
  }, [from, to]);
  if (!d && !error) return null;
  const top = d?.groups.slice(0, 10) ?? [];
  return (
    <Card title="Ranking de condiciones recurrentes"
      action={<ReportButton path={`/act/recurrent-conditions.xlsx?${new URLSearchParams({ from, to })}`} file={`condiciones-recurrentes-${from}-${to}.xlsx`} label="Ranking en Excel" />}>
      <div className="mb-3 flex flex-wrap items-end gap-2 text-sm">
        <label><span className="mb-1 block text-xs font-semibold text-muted">Desde</span><input type="date" className="input" value={from} onChange={(e) => setFrom(e.target.value)} /></label>
        <label><span className="mb-1 block text-xs font-semibold text-muted">Hasta</span><input type="date" className="input" value={to} onChange={(e) => setTo(e.target.value)} /></label>
        {d && <p className="text-xs text-muted">Recurrente: {d.min} o más reportes de la misma condición en el periodo. Se agrupa por la clasificación del peligro, la sede, el área y el lugar.</p>}
      </div>
      {error && <p role="alert" className="text-sm text-bad">{error}</p>}
      {d && (top.length === 0 ? <p className="text-sm text-muted">No hay condiciones subestándar reportadas en el periodo.</p> : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="text-left text-xs text-muted">
              <tr><th className="py-1 pr-2">#</th><th className="pr-2">Tipo de condición</th><th className="pr-2">Sede · área · lugar</th><th className="pr-2 text-right">Reportes</th>
                <th className="pr-2 text-right">Meses</th><th className="pr-2 text-right">Abiertos</th><th className="pr-2 text-right">Tras un cierre</th><th className="pr-2">Último</th><th /></tr>
            </thead>
            <tbody>
              {top.map((g, k) => (
                <tr key={k} className="border-t border-line">
                  <td className="py-1.5 pr-2">{k + 1}</td>
                  <td className="pr-2">{g.condition ?? <span className="text-muted">Sin clasificar</span>}</td>
                  <td className="pr-2">{[g.site, g.area, g.location].filter(Boolean).join(" · ") || "—"}</td>
                  <td className="pr-2 text-right font-semibold">{g.count}</td>
                  <td className="pr-2 text-right">{g.months}</td>
                  <td className="pr-2 text-right">{g.open}</td>
                  <td className={`pr-2 text-right ${g.after_close > 0 ? "font-semibold text-bad" : ""}`}>{g.after_close}</td>
                  <td className="pr-2 whitespace-nowrap">{fmt(g.last_on)}</td>
                  <td>{g.recurrent ? <Chip tone="bad">Recurrente</Chip> : null}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {d.groups.length > top.length && <p className="mt-2 text-xs text-muted">Se muestran las 10 primeras de {d.groups.length}; el Excel trae todas.</p>}
          <div className="mt-3 grid gap-3 sm:grid-cols-2">
            {([["Por tipo de condición", d.by_condition, "Sin clasificar"], ["Por área", d.by_area, "Sin área"]] as [string, Rank[], string][]).map(([title, list, none]) => (
              <div key={title}>
                <p className="mb-1 text-xs font-semibold text-muted">{title}</p>
                <ul className="space-y-0.5">
                  {list.slice(0, 5).map((r, k) => (
                    <li key={k} className="flex justify-between gap-2 border-b border-line pb-0.5 last:border-0">
                      <span>{r.name ?? none}</span><span className={r.recurrent ? "font-semibold text-bad" : ""}>{r.count}</span>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </div>
      ))}
    </Card>
  );
}
