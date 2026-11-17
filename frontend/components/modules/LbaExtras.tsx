"use client";

// Línea base (M-LBA): radar por lineamiento (SCR-LBA-03) y comparativo entre dos diagnósticos (SCR-LBA-05).

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

type Lin = { lineament_code: string; lineament_name: string; total: number; compliant: number; partial: number; non_compliant: number };

/** Radar SVG del % de cumplimiento por lineamiento (cumple = 1, parcial = 0,5). */
export function BaselineRadar({ id }: { id: string }) {
  const [lin, setLin] = useState<Lin[] | null>(null);
  useEffect(() => { api<{ data: { lineaments: Lin[] } }>(`/reports/baseline_assessments/${id}`, { withCompany: true }).then((r) => setLin(r.data.lineaments)).catch(() => setLin(null)); }, [id]);
  if (!lin || lin.length < 3) return null;
  const pct = (l: Lin) => { const n = Number(l.total); return n ? Math.round(((Number(l.compliant) + Number(l.partial) * 0.5) / n) * 100) : 0; };
  const size = 260, c = size / 2, r = 95;
  const pt = (i: number, v: number) => { const a = (Math.PI * 2 * i) / lin.length - Math.PI / 2; return [c + Math.cos(a) * r * (v / 100), c + Math.sin(a) * r * (v / 100)]; };
  const poly = lin.map((l, i) => pt(i, pct(l)).join(",")).join(" ");
  return (
    <figure className="space-y-1 rounded-xl border border-line px-4 py-3 text-sm">
      <figcaption className="font-semibold">Cumplimiento por lineamiento</figcaption>
      <svg viewBox={`0 0 ${size} ${size}`} className="mx-auto h-64 w-64" role="img" aria-label="Radar de cumplimiento por lineamiento">
        {[25, 50, 75, 100].map((g) => <polygon key={g} points={lin.map((_, i) => pt(i, g).join(",")).join(" ")} fill="none" stroke="var(--line, #ddd)" strokeWidth="1" />)}
        {lin.map((_, i) => { const [x, y] = pt(i, 100); return <line key={i} x1={c} y1={c} x2={x} y2={y} stroke="var(--line, #ddd)" strokeWidth="1" />; })}
        <polygon points={poly} fill="var(--brand, #1d4ed8)" fillOpacity="0.25" stroke="var(--brand, #1d4ed8)" strokeWidth="2" />
        {lin.map((l, i) => { const [x, y] = pt(i, 116); return <text key={l.lineament_code} x={x} y={y} fontSize="9" textAnchor="middle" dominantBaseline="middle" fill="currentColor">{l.lineament_code}</text>; })}
      </svg>
      <ul className="grid gap-x-4 text-xs text-muted sm:grid-cols-2">{lin.map((l) => <li key={l.lineament_code}><b>{l.lineament_code}</b> {l.lineament_name}: {pct(l)} %</li>)}</ul>
    </figure>
  );
}

type Cmp = { a: { title: string; assessment_on: string; score_pct: number | null }; b: { title: string; assessment_on: string; score_pct: number | null }; diff: number | null;
  lineaments: { code: string; name: string; a: number | null; b: number | null; diff: number | null }[] };

/** Elegir dos diagnósticos y ver la diferencia por lineamiento. */
export function BaselineCompare() {
  const [list, setList] = useState<{ id: string; title: string; assessment_on: string }[]>([]);
  const [a, setA] = useState("");
  const [b, setB] = useState("");
  const [d, setD] = useState<Cmp | null>(null);
  useEffect(() => { api<{ data: { id: string; title: string; assessment_on: string }[] }>("/records/LBA/baseline_assessments?per_page=50", { withCompany: true }).then((r) => setList(r.data)).catch(() => setList([])); }, []);
  useEffect(() => {
    if (!a || !b || a === b) { setD(null); return; }
    api<{ data: Cmp }>(`/lba/compare?a=${a}&b=${b}`, { withCompany: true }).then((r) => setD(r.data)).catch(() => setD(null));
  }, [a, b]);
  if (list.length < 2) return null;
  const sel = (v: string, set: (x: string) => void, label: string) => (
    <label className="text-xs">{label}<select className="input mt-1 !w-64" value={v} onChange={(e) => set(e.target.value)}><option value="">Elige…</option>{list.map((x) => <option key={x.id} value={x.id}>{x.title} · {x.assessment_on}</option>)}</select></label>
  );
  return (
    <details className="rounded-xl border border-line bg-surface">
      <summary className="cursor-pointer px-4 py-3 text-sm font-semibold">Comparar dos diagnósticos</summary>
      <div className="space-y-2 border-t border-line p-4 text-sm">
        <div className="flex flex-wrap gap-2">{sel(a, setA, "Anterior")}{sel(b, setB, "Actual")}</div>
        {d && (
          <table className="w-full text-left">
            <thead className="text-xs text-muted"><tr><th className="py-1">Lineamiento</th><th>Anterior</th><th>Actual</th><th>Diferencia</th></tr></thead>
            <tbody>
              {d.lineaments.map((l) => (
                <tr key={l.code} className="border-t border-line"><td className="py-1">{l.code} {l.name}</td><td>{l.a ?? "—"} %</td><td>{l.b ?? "—"} %</td>
                  <td className={l.diff == null ? "" : l.diff >= 0 ? "text-ok" : "text-bad"}>{l.diff == null ? "—" : `${l.diff > 0 ? "+" : ""}${l.diff}`}</td></tr>
              ))}
              <tr className="border-t border-line font-semibold"><td className="py-1">Total</td><td>{d.a.score_pct ?? "—"} %</td><td>{d.b.score_pct ?? "—"} %</td><td>{d.diff == null ? "—" : `${d.diff > 0 ? "+" : ""}${d.diff}`}</td></tr>
            </tbody>
          </table>
        )}
      </div>
    </details>
  );
}
