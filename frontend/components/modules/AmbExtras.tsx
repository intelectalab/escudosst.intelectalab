"use client";

// Medio ambiente (M-AMB): criterios de significancia de la empresa (RN-AMB-01, recalcula los aspectos) y reportes en Excel.

import { useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";
import { ReportButton } from "./CtrExtras";

type Criteria = { severity_max: number; probability_max: number; threshold: number; recalculated?: number | null };

export function AmbPanel({ readOnly }: { readOnly: boolean }) {
  const [c, setC] = useState<Criteria | null>(null);
  const [f, setF] = useState({ severity_max: "", probability_max: "", threshold: "" });
  const [msg, setMsg] = useState<{ ok: boolean; text: string } | null>(null);
  useEffect(() => {
    api<{ data: Criteria }>("/amb/criteria", { withCompany: true }).then((r) => { setC(r.data); setF({ severity_max: String(r.data.severity_max), probability_max: String(r.data.probability_max), threshold: String(r.data.threshold) }); }).catch(() => setC(null));
  }, []);
  if (!c) return null;
  const save = () => api<{ data: Criteria }>("/amb/criteria", { method: "PUT", withCompany: true, body: { severity_max: Number(f.severity_max), probability_max: Number(f.probability_max), threshold: Number(f.threshold) } })
    .then((r) => { setC(r.data); setMsg({ ok: true, text: `Criterios guardados: se recalcularon ${r.data.recalculated ?? 0} aspecto(s).` }); })
    .catch((e) => setMsg({ ok: false, text: e instanceof ApiError ? Object.values(e.errors ?? {})[0]?.[0] ?? e.detail ?? e.title : "No se pudo guardar." }));
  const year = new Date().getFullYear();
  const input = (k: keyof typeof f, label: string) => (
    <label className="text-xs">{label}<input className="input mt-1 !w-24" inputMode="numeric" disabled={readOnly} value={f[k]} onChange={(e) => setF({ ...f, [k]: e.target.value })} /></label>
  );
  return (
    <section className="space-y-2 rounded-xl border border-line bg-surface p-4 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-semibold">Criterios de significancia</p>
        <span className="flex flex-wrap gap-2">
          <ReportButton path="/amb/reports.xlsx?kind=aspects" file="matriz-aspectos.xlsx" label="Matriz de aspectos en Excel" />
          <ReportButton path={`/amb/reports.xlsx?kind=waste&year=${year}`} file={`residuos-${year}.xlsx`} label={`Residuos por tipo y mes ${year}`} />
        </span>
      </div>
      <p className="text-muted">Puntaje = severidad × probabilidad. Es significativo desde el umbral. Hoy: severidad 1 a {c.severity_max}, probabilidad 1 a {c.probability_max}, umbral {c.threshold}.</p>
      {!readOnly && (
        <div className="flex flex-wrap items-end gap-2">
          {input("severity_max", "Severidad máxima")}{input("probability_max", "Probabilidad máxima")}{input("threshold", "Umbral")}
          <button type="button" className="rounded-lg bg-brand px-3 py-2 font-semibold text-white" onClick={save}>Guardar y recalcular</button>
        </div>
      )}
      {msg && <p className={msg.ok ? "text-ok" : "text-bad"}>{msg.text}</p>}
    </section>
  );
}
