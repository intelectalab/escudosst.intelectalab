"use client";

// Matriz IPERC imprimible (A4 horizontal): cabecera con la versión y su aprobación, la matriz con los grupos
// Identificación | Evaluación inicial | Controles | Riesgo residual, la leyenda de niveles y las firmas.
// «Imprimir» permite guardarla en PDF. ?v=<versión> elige la versión (por defecto la de trabajo o la vigente).

import { useParams, useSearchParams } from "next/navigation";
import { Suspense, useEffect, useState } from "react";
import { ApiError } from "@/lib/api";
import { HIERARCHY, ipc, levelLabel, VERSION_STATUS, type Workspace } from "@/lib/ipc";

const fmt = (d: string | null | undefined) => (d ? `${d.slice(8, 10)}/${d.slice(5, 7)}/${d.slice(0, 4)}` : "—");
const BAND_BG: Record<string, string> = { green: "#DDF3E4", amber: "#FDF0C9", red: "#FADBD8" };

function Sheet() {
  const { id } = useParams<{ id: string }>();
  const v = useSearchParams().get("v") ?? undefined;
  const [ws, setWs] = useState<Workspace | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    ipc.workspace(id, v).then((r) => setWs(r.data)).catch((e: ApiError) => setError(e.detail ?? e.title));
  }, [id, v]);
  if (error) return <p className="p-8">{error}</p>;
  if (!ws) return <p className="p-8">Preparando la matriz…</p>;

  const ver = ws.versions.find((x) => x.id === ws.version_id)!;
  const c = ws.criteria;
  const bg = (level: string | null) => (level ? BAND_BG[c.bands.find((b) => b.code === level)?.color ?? ""] : undefined);
  const th = "border border-[#9aa4b1] px-1.5 py-1 text-left font-semibold";
  const td = "border border-[#9aa4b1] px-1.5 py-1 align-top";

  return (
    <div className="min-h-screen bg-gray-100 p-4 text-[#0b2440] print:bg-white print:p-0">
      <style>{`@page { size: A4 landscape; margin: 8mm } @media print { .no-print { display: none } }`}</style>
      <div className="no-print mx-auto mb-4 flex max-w-6xl items-center justify-between rounded-xl bg-white p-3 shadow">
        <span className="text-sm text-gray-600">Imprime en A4 horizontal o elige «Guardar como PDF».</span>
        <button onClick={() => window.print()} className="rounded-lg bg-[#0d3b6e] px-4 py-2 text-sm font-semibold text-white">Imprimir / PDF</button>
      </div>
      <div className="mx-auto max-w-6xl space-y-3 bg-white p-6 text-[11px] print:max-w-none print:p-0">
        <header className="flex flex-wrap items-start justify-between gap-3 border-b-2 border-[#0d3b6e] pb-2">
          <div>
            <p className="text-[10px] font-bold uppercase tracking-widest text-[#1b6fb7]">Matriz IPERC · Ley 29783 y R.M. 050-2013-TR</p>
            <h1 className="text-lg font-extrabold">{ws.matrix.code} · {ws.matrix.title}</h1>
            <p>{[ws.matrix.position_name, ws.matrix.area_name, ws.matrix.site_name, ws.matrix.process].filter(Boolean).join(" · ") || "Toda la empresa"}</p>
          </div>
          <table className="text-[10px]">
            <tbody>
              <tr><td className="pr-2 text-gray-500">Versión</td><td className="font-bold">v{ver.version_number} · {VERSION_STATUS[ver.status]}</td></tr>
              <tr><td className="pr-2 text-gray-500">Vigente desde</td><td>{fmt(ver.effective_on)}</td></tr>
              <tr><td className="pr-2 text-gray-500">Criterios</td><td>{c.name}</td></tr>
              <tr><td className="pr-2 text-gray-500">Trabajadores expuestos</td><td>{ws.exposed_workers ?? "—"}</td></tr>
              <tr><td className="pr-2 text-gray-500">Próxima revisión</td><td>{fmt(ws.matrix.next_review_on)}</td></tr>
            </tbody>
          </table>
        </header>
        {ver.status !== "current" && <p className="rounded bg-[#FDF0C9] px-2 py-1 font-semibold">Copia no vigente: esta versión está «{VERSION_STATUS[ver.status]}».</p>}

        <table className="w-full border-collapse">
          <thead>
            <tr className="bg-[#0d3b6e] text-white">
              <th className={th} rowSpan={2}>N.°</th>
              <th className={`${th} text-center`} colSpan={6}>Identificación</th>
              <th className={`${th} text-center`} colSpan={3}>Evaluación inicial</th>
              <th className={`${th} text-center`}>Controles por jerarquía</th>
              <th className={`${th} text-center`} colSpan={3}>Riesgo residual</th>
            </tr>
            <tr className="bg-[#e8eef6]">
              {["Proceso", "Actividad", "Tarea", "Puesto", "Peligro", "Riesgo", "P", "S", "Nivel", "Medidas", "P", "S", "Nivel"].map((h, i) => <th key={i} className={th}>{h}</th>)}
            </tr>
          </thead>
          <tbody>
            {ws.rows.map((r) => (
              <tr key={r.id} style={{ breakInside: "avoid" }}>
                <td className={td}>{r.row_number}</td>
                <td className={td}>{r.process ?? "—"}</td>
                <td className={td}>{r.activity}</td>
                <td className={td}>{r.task}<br /><span className="text-gray-500">{r.is_routine ? "Rutinaria" : "No rutinaria"}</span></td>
                <td className={td}>{r.position_name ?? "—"}</td>
                <td className={td}>{r.hazard}</td>
                <td className={td}>{r.risk}{r.consequence ? <><br /><span className="text-gray-500">{r.consequence}</span></> : null}</td>
                <td className={`${td} text-center`}>{r.probability_value}</td>
                <td className={`${td} text-center`}>{r.severity_value}</td>
                <td className={td} style={{ background: bg(r.risk_level) }}><b>{r.risk_score}</b> {levelLabel(c, r.risk_level)}</td>
                <td className={td}>
                  {r.controls.length === 0 ? "—" : r.controls.map((x) => <div key={x.id}><b>{HIERARCHY.find((h) => h[0] === x.hierarchy)?.[1]}:</b> {x.description}</div>)}
                </td>
                <td className={`${td} text-center`}>{r.residual_probability ?? "—"}</td>
                <td className={`${td} text-center`}>{r.residual_severity ?? "—"}</td>
                <td className={td} style={{ background: bg(r.residual_level) }}>{r.residual_level ? <><b>{r.residual_score}</b> {levelLabel(c, r.residual_level)}</> : "—"}</td>
              </tr>
            ))}
            {ws.rows.length === 0 && <tr><td className={td} colSpan={14}>Esta versión no tiene filas.</td></tr>}
          </tbody>
        </table>

        <p className="flex flex-wrap items-center gap-1.5">
          <span className="text-gray-500">Niveles:</span>
          {c.bands.map((b) => <span key={b.code} className="rounded px-1.5 py-0.5 font-semibold" style={{ background: BAND_BG[b.color] ?? "#eee" }}>{b.label} {b.min}–{b.max}</span>)}
        </p>
        {ver.participants.length > 0 && <p><span className="text-gray-500">Participaron:</span> {ver.participants.map((p) => (p.role ? `${p.name} (${p.role})` : p.name)).join(", ")}</p>}
        {ver.change_reason && <p><span className="text-gray-500">Motivo de la versión:</span> {ver.change_reason}</p>}

        <div className="grid grid-cols-2 gap-10 pt-8" style={{ breakInside: "avoid" }}>
          <div className="border-t border-[#0b2440] pt-1 text-center">Elaboró: {ver.prepared_by_name ?? "________________"}<br /><span className="text-gray-500">{fmt(ver.prepared_at)}</span></div>
          <div className="border-t border-[#0b2440] pt-1 text-center">Aprobó: {ver.approved_by_name ?? "________________"}<br /><span className="text-gray-500">{fmt(ver.approved_at)}</span></div>
        </div>
      </div>
    </div>
  );
}

export default function ImprimirIperc() {
  return (
    <Suspense fallback={<p className="p-8">Preparando la matriz…</p>}>
      <Sheet />
    </Suspense>
  );
}
